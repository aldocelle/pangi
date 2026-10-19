<?php

namespace App\Console\Commands;

use App\Services\CorridasTareas;
use Illuminate\Console\Command;

/**
 * Base de las tres tareas de fondo, con el candado que las hace seguras.
 *
 * **Por qué son cron y no el scheduler de Laravel.** `exec`, `shell_exec` y
 * `proc_open` están deshabilitadas en el Hostinger compartido —también en la
 * CLI, comprobado por sonda en la Fase 1—, así que `schedule:run` no puede
 * lanzar sus procesos hijos. Un cron por tarea no es una preferencia: es la
 * única arquitectura posible.
 *
 * **Por qué el lock.** En hosting compartido una ejecución lenta se solapa con
 * la siguiente, y dos procesos mandando los mismos recordatorios los mandan dos
 * veces. El lock es por ARCHIVO (`flock`) y no por caché ni por base: tiene que
 * funcionar aunque la base sea justamente lo que está lento, y el sistema
 * operativo lo suelta solo si el proceso muere — un lock en base o en caché se
 * queda tomado para siempre tras un `kill -9` y la tarea no vuelve a correr
 * nunca, en silencio.
 *
 * **Ningún horario de negocio en la expresión del cron.** El cron dispara cada
 * uno o cinco minutos y es el CÓDIGO el que decide si toca actuar. El cron de
 * hPanel corre en UTC, y este proyecto ya se equivocó tres veces con eso (§9.6);
 * una expresión con hora local se corre sola dos veces al año con el cambio de
 * horario.
 */
abstract class TareaDeFondo extends Command
{
    /** Qué hace la tarea. Devuelve el resumen para el log. */
    abstract protected function ejecutar(): string;

    public function handle(): int
    {
        $inicio = microtime(true);
        $ruta = storage_path('app/locks');
        if (! is_dir($ruta)) {
            mkdir($ruta, 0775, true);
        }
        $archivo = $ruta . '/' . str_replace([':', '/'], '-', $this->getName()) . '.lock';

        $fh = fopen($archivo, 'c');
        if ($fh === false) {
            $this->error("No se pudo abrir el lock {$archivo}");
            return self::FAILURE;
        }

        // `LOCK_NB`: si otra corrida sigue viva, esta se va sin hacer nada. No
        // es un error —es el caso normal cuando una tanda tarda más que el
        // intervalo— así que sale con 0 para no llenar el correo del cron de
        // avisos de fallo.
        if (! flock($fh, LOCK_EX | LOCK_NB)) {
            $this->info('Ya hay otra corrida en curso: esta se salta.');
            fclose($fh);
            CorridasTareas::anotar($this->getName(), $inicio, 'saltada', 'Otra corrida tenía el lock.');
            return self::SUCCESS;
        }

        try {
            $resumen = $this->ejecutar();
            if ($resumen !== '') {
                $this->info($resumen);
            }
            // Cada corrida queda anotada (Sysop → Tareas programadas): es lo
            // que deja ver desde el panel una tarea que no corre (§13.17).
            CorridasTareas::anotar($this->getName(), $inicio, 'ok', $resumen);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Una tarea de fondo NUNCA tumba el proceso ni deja de correr la
            // próxima vez: se anota y se sale. Mismo criterio que los
            // `try/except` de los loops de Python.
            report($e);
            $this->error('Falló: ' . $e->getMessage());
            CorridasTareas::anotar($this->getName(), $inicio, 'fallo', $e->getMessage());
            return self::FAILURE;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
