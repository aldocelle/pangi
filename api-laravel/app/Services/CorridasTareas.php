<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Registro de corridas de las tareas de fondo, y su lectura para Sysop.
 *
 * **Por qué existe** (§13.17): los crons de hPanel corrieron dos semanas contra
 * `lab-pruebas/` en vez de producción y nada lo mostró. Un cron que corre contra
 * la carpeta equivocada tiene éxito, así que la falla solo se ve desde el lado
 * que NO recibe las corridas. Por eso el estado se calcula por ausencia: una
 * tarea sin corridas recientes se pinta como atrasada, no como «sin datos».
 *
 * **Nunca lanza.** Anotar es un efecto secundario de la tarea; si la tabla no
 * existe (migración sin correr) o la base falla al escribir, la tarea ya hizo
 * su trabajo y no puede terminar en fallo por esto.
 */
class CorridasTareas
{
    public const TABLA = 'tareas_corridas';

    private const DIAS_RETENCION = 7;

    /**
     * Las tareas que se vigilan, con la frecuencia de su cron en hPanel
     * (comprobada el 07-oct-2026) y desde cuándo cuentan como atrasadas.
     * El margen es holgado a propósito: un minuto tarde no es un incidente,
     * una tarea que no corre en horas sí.
     *
     * `panel`: si se puede lanzar con «Ejecutar ahora». Solo las que terminan
     * en segundos y no le escriben a ningún cliente: la petición web corre
     * dentro del límite de tiempo de PHP (no hay `exec` en el Hostinger
     * compartido) y un respaldo de 30 MB o un barrido de recordatorios no
     * tienen por qué dispararse con un clic.
     */
    public const TAREAS = [
        'recordatorios:citas' => [
            'nombre' => 'Recordatorios de cita y barrido de reservas sin pagar',
            'cada_min' => 1, 'atrasada_min' => 15, 'panel' => false,
        ],
        'google:sincronizar' => [
            'nombre' => 'Sincronización con Google Calendar',
            'cada_min' => 5, 'atrasada_min' => 30, 'panel' => true,
        ],
        'estado:revisar' => [
            'nombre' => 'Revisión de WhatsApp, Google y la IA',
            'cada_min' => 15, 'atrasada_min' => 60, 'panel' => true,
        ],
        'avisos:preventivos' => [
            'nombre' => 'Avisos de medicina preventiva',
            'cada_min' => 60, 'atrasada_min' => 180, 'panel' => false,
        ],
        'respaldo:crear' => [
            'nombre' => 'Respaldo diario de la base y los archivos',
            'cada_min' => 1440, 'atrasada_min' => 26 * 60, 'panel' => false,
        ],
    ];

    /** Quién lanzó la corrida en curso. Lo fija `ejecutarDesdePanel()`. */
    private static string $origen = 'cron';

    private static ?string $usuarioId = null;

    public static function anotar(string $tarea, float $inicio, string $resultado, string $resumen = ''): void
    {
        try {
            if (! Esquema::tabla(self::TABLA)) {
                return;
            }
            $fin = microtime(true);
            DB::table(self::TABLA)->insert([
                'tarea' => mb_substr($tarea, 0, 60),
                'inicio' => self::utc($inicio),
                'fin' => self::utc($fin),
                'duracion_ms' => (int) max(0, round(($fin - $inicio) * 1000)),
                'resultado' => $resultado,
                'resumen' => $resumen === '' ? null : mb_substr($resumen, 0, 500),
                'origen' => self::$origen,
                'usuario_id' => self::$usuarioId,
            ]);
            // La purga va con la escritura: no necesita un cron más, y con el
            // índice por `inicio` borrar lo de hace 7 días es instantáneo.
            DB::table(self::TABLA)
                ->where('inicio', '<', CarbonImmutable::now('UTC')->subDays(self::DIAS_RETENCION)->format('Y-m-d H:i:s'))
                ->delete();
        } catch (\Throwable $e) {
            Log::warning("No se pudo anotar la corrida de {$tarea}: " . $e->getMessage());
        }
    }

    /**
     * Corre una tarea dentro de la petición, anotada como lanzada desde el
     * panel por `$usuarioId`. Devuelve [código de salida, salida de consola].
     */
    public static function ejecutarDesdePanel(string $tarea, ?string $usuarioId): array
    {
        self::$origen = 'panel';
        self::$usuarioId = $usuarioId;
        try {
            $codigo = \Illuminate\Support\Facades\Artisan::call($tarea);

            return [$codigo, trim(\Illuminate\Support\Facades\Artisan::output())];
        } finally {
            self::$origen = 'cron';
            self::$usuarioId = null;
        }
    }

    /**
     * El estado de cada tarea vigilada, para la pantalla de Sysop.
     *
     * `estado`: `ok`, `fallando` (la última corrida que hizo algo falló),
     * `atrasada` (no corre hace más de `atrasada_min`), `sin_corridas` (nunca
     * anotó nada en 7 días: en producción, casi seguro que no tiene cron) o
     * `sin_tabla` (falta la migración).
     */
    public static function resumen(): array
    {
        $hayTabla = Esquema::tabla(self::TABLA);
        $ahora = CarbonImmutable::now('UTC');
        $hace24 = $ahora->subDay()->format('Y-m-d H:i:s');
        $tareas = [];

        foreach (self::TAREAS as $id => $def) {
            $fila = ['id' => $id] + $def + [
                'estado' => $hayTabla ? 'sin_corridas' : 'sin_tabla',
                'ultima' => null, 'ultimo_ok' => null, 'ultimo_fallo' => null,
                'corridas_24h' => 0, 'fallos_24h' => 0, 'minutos_desde_ultima' => null,
            ];

            if ($hayTabla) {
                $base = fn () => DB::table(self::TABLA)->where('tarea', $id);
                $ultima = $base()->orderByDesc('inicio')->orderByDesc('id')->first();
                $fila['ultima'] = self::salida($ultima);
                $fila['ultimo_ok'] = self::salida($base()->where('resultado', 'ok')
                    ->orderByDesc('inicio')->orderByDesc('id')->first());
                $fila['ultimo_fallo'] = self::salida($base()->where('resultado', 'fallo')
                    ->orderByDesc('inicio')->orderByDesc('id')->first());
                $fila['corridas_24h'] = $base()->where('inicio', '>=', $hace24)->count();
                $fila['fallos_24h'] = $base()->where('inicio', '>=', $hace24)->where('resultado', 'fallo')->count();

                if ($ultima) {
                    $min = (int) floor(CarbonImmutable::parse($ultima->inicio, 'UTC')->diffInMinutes($ahora, true));
                    $fila['minutos_desde_ultima'] = $min;
                    // «Fallando» mira la última corrida que llegó a ejecutar: una
                    // `saltada` (otra tenía el lock) no dice nada del resultado.
                    $efectiva = $base()->where('resultado', '!=', 'saltada')
                        ->orderByDesc('inicio')->orderByDesc('id')->value('resultado');
                    $fila['estado'] = match (true) {
                        $min > $def['atrasada_min'] => 'atrasada',
                        $efectiva === 'fallo' => 'fallando',
                        default => 'ok',
                    };
                }
            }
            $tareas[] = $fila;
        }

        return ['ahora' => $ahora->toIso8601ZuluString(), 'tareas' => $tareas];
    }

    /** Las últimas corridas de una tarea, más nuevas primero. */
    public static function historial(string $tarea, int $limite = 30): array
    {
        if (! Esquema::tabla(self::TABLA)) {
            return [];
        }

        return DB::table(self::TABLA)->where('tarea', $tarea)
            ->orderByDesc('inicio')->orderByDesc('id')->limit($limite)->get()
            ->map(fn ($f) => self::salida($f))->all();
    }

    private static function salida(?object $f): ?array
    {
        if (! $f) {
            return null;
        }

        return [
            'inicio' => CarbonImmutable::parse($f->inicio, 'UTC')->toIso8601ZuluString('millisecond'),
            'duracion_ms' => (int) $f->duracion_ms,
            'resultado' => $f->resultado,
            'resumen' => $f->resumen,
            'origen' => $f->origen,
        ];
    }

    private static function utc(float $t): string
    {
        return CarbonImmutable::createFromTimestamp($t, 'UTC')->format('Y-m-d H:i:s.v');
    }
}
