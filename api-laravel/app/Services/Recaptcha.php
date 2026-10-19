<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * reCAPTCHA v3 para los dos formularios públicos sin sesión: contacto y
 * reserva. Puerto de `recaptcha_service.py`.
 *
 * **Las dos asimetrías de este servicio son deliberadas y hay que conservarlas.**
 *
 * 1. **Contacto BLOQUEA, la reserva solo OBSERVA.** En contacto, un falso
 *    positivo es un formulario molesto. En la reserva es una hora que no se
 *    agenda y un abono que nunca se cobra, sin que el cliente ni la clínica
 *    sepan por qué. reCAPTCHA v3 no da un sí/no sino un puntaje, y cuál es el
 *    umbral sano depende del tráfico real de ESTE sitio — no se sabe hasta
 *    medirlo. El modo observación es esa medición.
 *
 * 2. **Sin clave configurada, en desarrollo se omite y en producción se
 *    rechaza.** No es lo mismo «todavía no la configuré en mi máquina» que
 *    «falta una variable en el servidor»: lo segundo dejaría el formulario
 *    público sin protección anti-bot sin que nadie lo note. Mismo criterio que
 *    `SECRET_KEY` y `TOKEN_ENCRYPTION_KEY`, que directamente no arrancan.
 */
class Recaptcha
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * v3 devuelve 0.0 (bot) a 1.0 (humano). 0.5 es el punto de partida que
     * sugiere la propia documentación de Google.
     */
    private const UMBRAL = 0.5;

    /**
     * ¿Se acepta esta petición?
     *
     * Con `$bloquear = false` se consulta a Google y se anota el puntaje, pero
     * se devuelve `true` pase lo que pase.
     */
    public function verificar(?string $token, string $ip, bool $bloquear = true): bool
    {
        // Interruptor de emergencia. Existe porque el par de claves
        // sitio/secreto puede quedar descuadrado —pasó el 18-ago-2026: el
        // frontend firmaba con un sitio y el backend validaba contra otro— y en
        // ese estado NINGUNA reserva entra: el formulario queda muerto sin que
        // el error diga por qué.
        //
        // Prendiéndolo se acepta cualquier token. Es un agujero real, así que
        // va con dos candados: solo se activa poniendo la variable, y deja
        // registro en CADA llamada para que no se olvide encendido.
        if (in_array(mb_strtolower((string) env('RECAPTCHA_DESACTIVADO', '')),
                     ['1', 'true', 'si', 'sí'], true)) {
            Log::warning("RECAPTCHA_DESACTIVADO está encendido: se acepta la petición de "
                . "{$ip} sin verificar. Esto debe ser temporal — apagar la variable en "
                . 'cuanto se arregle el par de claves.');
            return true;
        }

        [$aprobado, $detalle] = $this->evaluar($token, $ip);

        if (! $bloquear) {
            Log::info("reCAPTCHA en observación ({$ip}): {$detalle} — se acepta igual.");
            return true;
        }

        if (! $aprobado) {
            Log::info("reCAPTCHA rechazado ({$ip}): {$detalle}");
        }
        return $aprobado;
    }

    /**
     * `[aprobado, detalle legible]`. El detalle va al log en los dos modos:
     * en observación es literalmente el dato con el que después se elige el
     * umbral.
     *
     * @return array{0: bool, 1: string}
     */
    private function evaluar(?string $token, string $ip): array
    {
        $secreto = (string) env('RECAPTCHA_SECRET_KEY', '');

        if (! $secreto) {
            if (app()->environment('local', 'development', 'testing')) {
                Log::warning('RECAPTCHA_SECRET_KEY no configurada: se omite la verificación.');
                return [true, 'sin secret key (development)'];
            }
            Log::error('RECAPTCHA_SECRET_KEY no configurada fuera de development: se '
                . 'rechaza el mensaje por seguridad en vez de omitir la verificación.');
            return [false, 'sin secret key en producción'];
        }
        if (! $token) {
            return [false, 'la petición no traía token'];
        }

        try {
            $r = Http::asForm()->timeout(10)->post(self::VERIFY_URL, [
                'secret' => $secreto, 'response' => $token, 'remoteip' => $ip,
            ]);
            $data = $r->json();
        } catch (\Throwable $e) {
            Log::error('Fallo al verificar reCAPTCHA: ' . $e->getMessage());
            return [false, 'no se pudo consultar a Google'];
        }

        if (! ($data['success'] ?? false)) {
            $codigos = json_encode($data['error-codes'] ?? null);
            return [false, "Google lo rechazó: {$codigos}"];
        }

        $score = (float) ($data['score'] ?? 0);
        $accion = $data['action'] ?? null;

        return [$score >= self::UMBRAL,
                "score {$score} (umbral " . self::UMBRAL . "), action={$accion}"];
    }
}
