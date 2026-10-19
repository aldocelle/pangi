<?php

namespace App\Services;

/**
 * Modo C del arnés, lado Laravel: anota lo que el backend ENVÍA hacia afuera.
 *
 * El modo A compara respuestas HTTP, y hay una clase de bug de portado que no
 * mueve la respuesta ni un byte y aun así rompe producción: el que sale hacia
 * afuera. Un correo con el asunto mal armado, un evento de Google corrido
 * cuatro horas de zona (§9.6), una invitación al especialista equivocado — la
 * respuesta es idéntica en los tres casos y el arnés daría verde.
 *
 * Se activa con `ARNES_EFECTOS=1` en el entorno, y **solo** ahí: en producción
 * la clase existe pero nadie la enciende. Cuando está apagada no anota nada y
 * el envío sigue su curso normal.
 */
class RegistroEfectos
{
    /** @var array<int,array<string,mixed>> */
    private static array $efectos = [];

    public static function activo(): bool
    {
        return (string) env('ARNES_EFECTOS', '') === '1';
    }

    /**
     * Anota un efecto. **El orden es contrato**, igual que en los arrays del
     * comparador: dos correos emitidos al revés son un bug real —el tutor
     * recibiría el del especialista.
     */
    public static function anotar(string $tipo, array $datos): void
    {
        if (! self::activo()) {
            return;
        }
        self::$efectos[] = ['tipo' => $tipo] + $datos;
        // Se escribe en cada anotación y no al final: el proceso de
        // `artisan serve` atiende cada request por separado y no hay un
        // «final» donde volcar.
        @file_put_contents(
            (string) env('ARNES_EFECTOS_ARCHIVO', '/tmp/efectos_lv.json'),
            json_encode(self::$efectos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    public static function limpiar(): void
    {
        self::$efectos = [];
        @file_put_contents((string) env('ARNES_EFECTOS_ARCHIVO', '/tmp/efectos_lv.json'), '[]');
    }
}
