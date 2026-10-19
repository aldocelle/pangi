<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * «Recibimos tu pago» por WhatsApp, para la hora que se reservó por WhatsApp.
 *
 * Decisión de producto (05-oct-2026): quien paga el abono desde el enlace que le
 * mandó el asistente recibía el comprobante solo por correo, y en el chat
 * quedaba el «paga dentro de 15 minutos» como último mensaje. Ahora el mismo
 * chat le confirma la hora.
 *
 * Solo para citas que guardan el número en `respuestas["WhatsApp"]` (las que
 * hizo el asistente). Una reserva del portal no tiene chat al que avisar.
 * Texto libre: llega porque quien acaba de pagar escribió hace minutos (la
 * ventana de 24 h está abierta). Nunca lanza: el pago ya quedó registrado.
 */
class AvisoPagoWhatsapp
{
    public function __construct(private WhatsappEnvio $envio)
    {
    }

    /**
     * La hora se soltó porque el abono no llegó en la ventana. Antes la
     * persona no se enteraba: el chat se quedaba en «paga dentro de 15
     * minutos» y la hora desaparecía en silencio (decisión de producto, 05-oct:
     * «queda a veces en el aire»).
     */
    public function avisarHoraLiberada(object $cita, ?object $mascota): bool
    {
        try {
            $tel = self::telefonoDelChat($cita);
            if ($tel === null || ! $this->envio->habilitado()) {
                return false;
            }
            $cuando = HorariosOfrecidos::legible(CarbonImmutable::parse((string) $cita->fecha_hora, 'America/Santiago'));
            $quien = $mascota->nombre ?? 'tu mascota';

            $ok = $this->envio->texto($tel, "No alcanzamos a recibir el abono de la hora de {$quien} del {$cuando}, "
                . 'así que se liberó. Si todavía la necesitas, escríbeme y te busco otra.');
            // Si la acordó un especialista por WhatsApp, él también la tenía
            // anotada: se le avisa que el horario volvió a quedar libre.
            $resp = json_decode((string) ($cita->respuestas ?? ''), true) ?: [];
            if (! empty($resp['Solicitud']) && ($cita->especialista_id ?? null)) {
                $nac = AgenteIA::nacional(DB::table('especialistas')->where('id', $cita->especialista_id)->value('telefono'));
                if ($nac !== null) {
                    $this->envio->texto('56' . $nac,
                        "La hora de {$quien} del {$cuando} (solicitud {$resp['Solicitud']}) se liberó: el tutor no pagó el abono a tiempo.");
                }
            }

            return $ok;
        } catch (\Throwable $e) {
            Log::warning("citas: no se pudo avisar la hora liberada de {$cita->id}: " . $e->getMessage());

            return false;
        }
    }

    /** El número del chat donde se reservó, o null si la cita no vino de WhatsApp. */
    private static function telefonoDelChat(object $cita): ?string
    {
        $resp = json_decode((string) ($cita->respuestas ?? ''), true) ?: [];
        $tel = preg_replace('/\D/', '', (string) ($resp['WhatsApp'] ?? ''));

        return strlen($tel) >= 11 ? $tel : null;
    }

    /**
     * true si el mensaje salió. `$cita` ya trae el estado nuevo.
     *
     * `$pagado`: lo que se cobró (PagosController::montoDe), el mismo número
     * del comprobante. `$correo`: a dónde salió el comprobante, o null si no
     * salió — en ese caso no se dice que se envió (decisión de producto, 05-oct:
     * «que indique la cantidad pagada y que se envió el comprobante»).
     */
    public function avisar(object $cita, ?object $mascota, ?int $pagado = null, ?string $correo = null): bool
    {
        try {
            $resp = json_decode((string) ($cita->respuestas ?? ''), true) ?: [];
            $tel = preg_replace('/\D/', '', (string) ($resp['WhatsApp'] ?? ''));
            if (strlen($tel) < 11 || ! $this->envio->habilitado()) {
                return false;
            }
            $cuando = HorariosOfrecidos::legible(CarbonImmutable::parse((string) $cita->fecha_hora, 'America/Santiago'));
            $quien = $mascota->nombre ?? 'tu mascota';
            $deMonto = $pagado ? ' de ' . self::clp($pagado) : '';

            // Confirmada salvo el caso raro de una cita expirada cuyo cupo
            // tomó otro mientras tanto: ahí decide una persona (PagosController).
            $texto = ($cita->estado ?? '') === 'Confirmada'
                ? "¡Recibimos tu pago{$deMonto}! La hora de {$quien} del {$cuando} quedó confirmada."
                    . self::saldo($cita, $pagado) . ' ' . self::comprobante($correo) . ' ' . self::dondeSeAtiende($cita)
                : "Recibimos tu pago{$deMonto} para la hora de {$quien} del {$cuando}. "
                    . self::comprobante($correo) . ' La clínica te va a contactar para coordinarla.';

            return $this->envio->texto($tel, $texto);
        } catch (\Throwable $e) {
            Log::warning("pagos: no se pudo avisar el pago por WhatsApp de la cita {$cita->id}: " . $e->getMessage());

            return false;
        }
    }

    /**
     * Lo que queda por pagar, con la misma regla que el comprobante
     * (CitasEmail): el total pagado no deja saldo; un abono sobre un total
     * conocido, la diferencia; un abono fijo (total desconocido), que el resto
     * se calcula allá — nunca un «$0» que le diga que no debe nada.
     */
    private static function saldo(object $cita, ?int $pagado): string
    {
        if (! $pagado) {
            return '';
        }
        $total = $cita->monto_total_clp !== null ? (int) $cita->monto_total_clp : null;
        if ($total === null) {
            return ' El resto se paga en la clínica.';
        }

        return $pagado < $total ? ' Saldo a pagar en la clínica: ' . self::clp($total - $pagado) . '.' : '';
    }

    private static function comprobante(?string $correo): string
    {
        return $correo !== null && $correo !== ''
            ? "Te enviamos el comprobante a {$correo}."
            : 'Si necesitas el comprobante, pídelo en la clínica.';
    }

    private static function clp(int $n): string
    {
        return '$' . number_format($n, 0, ',', '.');
    }

    /**
     * Con la dirección de la clínica (decisión de producto, 05-oct), salvo domicilio
     * y telemedicina, que no son allá: misma regla que la confirmación del
     * chat (AgenteIA::lugarDe). Si la dirección no se puede leer, el aviso del
     * pago sale igual, sin ella.
     */
    private static function dondeSeAtiende(object $cita): string
    {
        $svc = (object) ['nombre' => (string) ($cita->servicio ?? '')];
        $dir = trim((string) ((json_decode((string) ($cita->respuestas ?? ''), true) ?: [])['Dirección'] ?? ''));
        if (AgenteIA::esDomicilio($svc) && $dir !== '') {
            return "La atención es a domicilio, en {$dir}.";
        }
        try {
            $lugar = AgenteIA::lugarDe($svc);
        } catch (\Throwable) {
            $lugar = null;
        }

        return match (true) {
            $lugar === null => 'Te esperamos en ' . Marca::nombre() . '.',
            str_starts_with($lugar, 'en ') => "Te esperamos {$lugar}.",
            default => "La atención es {$lugar}.",
        };
    }
}
