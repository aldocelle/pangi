<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El tope de gasto de la IA del chat. Port de `uso_asistente.py`, con su
 * mismo contrato:
 *
 * - **Degrada, no falla.** Sin presupuesto el chat sigue: lo sin IA contesta
 *   entero y lo demás deriva a WhatsApp.
 * - **Del día y no del mes.** Uno mensual se agota el 9 y deja tres semanas
 *   sin asistente; uno diario acota un mal día y se repone solo.
 * - **Se comprueba antes y se suma después.** Lo que cuesta una llamada no se
 *   sabe hasta que vuelve, así que el tope se puede pasar por una llamada,
 *   nunca por más.
 *
 * El día es el de la clínica (`America/Santiago`, config/app.php), no UTC: en
 * UTC el tope se repondría a las 21:00 de Chile, justo cuando nadie mira.
 *
 * **El tope lo fija la clínica desde el panel** (Ajustes → Asistente IA,
 * `ajustes.data.asistente.tokens_dia`), y `ASISTENTE_TOKENS_DIA` queda solo
 * como valor para cuando nadie lo ha guardado. Antes vivía únicamente en el
 * `.env`, que la clínica no puede tocar: el 29-sep-2026 el lab llegó al tope a
 * las 18:36 en plena prueba y subirlo exigía entrar a hPanel.
 */
class UsoAsistente
{
    /** ¿Interruptor, clave y presupuesto del día? */
    public function puedeLlamar(): bool
    {
        if (! config('emr.asistente_ia_activo') || config('emr.anthropic_api_key') === '') {
            return false;
        }

        // Falla CERRADO: sin poder leer el tope o el gasto, no se gasta a ciegas.
        try {
            $tope = self::tope();
            if ($tope <= 0) {
                return true;
            }
            $gastado = self::gastoDeHoy()['tokens'];
        } catch (\Throwable $e) {
            Log::warning('asistente: no se pudo leer el tope o uso_asistente, se deriva: ' . $e->getMessage());

            return false;
        }
        if ($gastado >= $tope) {
            Log::warning("asistente: tope diario alcanzado ({$gastado} de {$tope} tokens)");

            return false;
        }

        return true;
    }

    /**
     * ¿La IA está encendida y se quedó sin cupo HOY? Distinto de apagada: la
     * persona venía hablando con ella y lo que cambió es nuestro. Sin poder
     * leer la base, false (se trata como cualquier otra derivación).
     */
    public function agotado(): bool
    {
        if (! config('emr.asistente_ia_activo') || config('emr.anthropic_api_key') === '') {
            return false;
        }
        try {
            $tope = self::tope();

            return $tope > 0 && self::gastoDeHoy()['tokens'] >= $tope;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Suma una llamada. Nunca lanza: la contabilidad no puede tumbar el chat. */
    public function registrar(int $entrada, int $salida): void
    {
        try {
            $hoy = self::hoy();
            // Incremento en el servidor (no leer-sumar-escribir): dos
            // mensajes a la vez no se pisan. Sin fila, se inserta; si otro la
            // insertó en medio, el duplicado cae al catch interno y se reintenta
            // el incremento.
            $sube = fn () => DB::table('uso_asistente')->where('fecha', $hoy)->update([
                'llamadas' => DB::raw('llamadas + 1'),
                'tokens_entrada' => DB::raw('tokens_entrada + ' . max(0, $entrada)),
                'tokens_salida' => DB::raw('tokens_salida + ' . max(0, $salida)),
            ]);
            if ($sube() === 0) {
                try {
                    DB::table('uso_asistente')->insert([
                        'fecha' => $hoy, 'llamadas' => 1,
                        'tokens_entrada' => max(0, $entrada), 'tokens_salida' => max(0, $salida),
                    ]);
                } catch (\Illuminate\Database\QueryException) {
                    $sube();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('asistente: no se pudo registrar el uso: ' . $e->getMessage());
        }
    }

    /**
     * El tope vigente: lo guardado en el panel, o la variable si no hay nada.
     * 0 (solo posible por la variable) = sin tope.
     */
    public static function tope(): int
    {
        $data = json_decode((string) DB::table('ajustes')->where('id', 1)->value('data'), true);

        return self::topeDe(is_array($data) ? ($data['asistente'] ?? []) : []);
    }

    /** La misma regla sobre la config del asistente ya leída (sin otra consulta). */
    public static function topeDe(array $asistente): int
    {
        $guardado = $asistente['tokens_dia'] ?? null;

        return is_int($guardado) ? $guardado : (int) config('emr.asistente_tokens_dia');
    }

    /** Lo gastado hoy (día de la clínica), para el tope y para mostrarlo en el panel. */
    public static function gastoDeHoy(): array
    {
        $fila = DB::table('uso_asistente')->where('fecha', self::hoy())->first();

        return [
            'tokens' => $fila ? (int) $fila->tokens_entrada + (int) $fila->tokens_salida : 0,
            'llamadas' => $fila ? (int) $fila->llamadas : 0,
        ];
    }

    private static function hoy(): string
    {
        return CarbonImmutable::now('America/Santiago')->format('Y-m-d');
    }
}
