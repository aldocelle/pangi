<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El enlace de pago de una reserva que YA existe (sep-2026).
 *
 * Una sola pieza para los dos que lo piden: la secretaria desde el panel y el
 * asistente de WhatsApp. Si cada uno congelara el monto por su cuenta, el
 * panel mostraría una cifra y el bot cobraría otra.
 *
 * El enlace es `/pago/{id}` del portal, no la `processUrl` de Getnet: esa
 * vence, y la página del portal abre una sesión nueva cada vez que se usa.
 *
 * LA VENTANA DE PAGO: 15 MINUTOS PARA TODAS (regla de producto, 12-oct-2026)
 *
 * Toda reserva con abono pendiente retiene la hora MINUTOS_VENTANA_PAGO
 * minutos; pasado el plazo sin pagar, la hora vuelve a ofrecerse, el barrido la
 * marca «Expirada» y el portal deja de ofrecer el pago. Lo que cambia entre las
 * dos clases de reserva es DESDE CUÁNDO se cuenta:
 *
 * · Autoservicio (portal, chat): congela el abono AL RESERVAR, así que el
 *   plazo corre desde `created_at`.
 * · Coordinada por la clínica (mostrador, WhatsApp atendido por una persona):
 *   nace SIN monto y se congela acá, cuando alguien genera el enlace. El plazo
 *   corre desde ese momento (`pago_enlace_at`): contarlo desde `created_at`
 *   soltaría en el acto una hora agendada hace días.
 *
 * Hasta el 12-oct la coordinada no vencía nunca, y la página de pago le
 * seguía cobrando al tutor una hora de hacía días. Una coordinada anterior a
 * `pago_enlace_at` no tiene la marca y se cuenta desde `created_at`.
 *
 * `pago_modalidad_requerida` NO decide la ventana: solo dice si se cobra abono
 * o total (lo escriben `/citas/reservar` y `/citas/crear`).
 *
 * Mientras la columna `pago_enlace_at` no exista (migración
 * `mariadb_pago_enlace.sql` sin correr) rige la regla anterior: la coordinada
 * no vence. Así el código puede subir antes que la migración sin soltar horas.
 */
class LinkDePago
{
    public function __construct(private CobroService $cobro)
    {
    }

    /** ¿Ya existe `citas.pago_enlace_at`? Es lo que enciende la regla nueva. */
    private static function reglaNueva(): bool
    {
        return Esquema::columna('citas', 'pago_enlace_at');
    }

    /**
     * Desde cuándo corre el plazo (UTC), o null si no corre ninguno.
     *
     * Con `?? null` en cada lectura: hay tablas de prueba sin estas columnas,
     * y leer una propiedad que no está es un warning.
     */
    private static function inicioVentana(object $cita): ?string
    {
        if (! self::reglaNueva() && ($cita->pago_modalidad_requerida ?? null) === null) {
            return null;
        }
        $inicio = ($cita->pago_enlace_at ?? null) ?: ($cita->created_at ?? null);

        return $inicio ?: null;
    }

    /**
     * ¿Esta cita queda fuera de la ventana? Con la regla nueva, solo la que no
     * cobra nada en línea. Lo lee el resumen de la página de pago.
     */
    public static function sinVentana(object $cita): bool
    {
        return ($cita->monto_abono_clp ?? null) === null || self::inicioVentana($cita) === null;
    }

    /**
     * Hasta cuándo se puede pagar (UTC, ISO con «Z»), o null si no corre
     * ningún plazo: ya pagada, sin cobro en línea, o en otro estado que
     * «Agendada».
     */
    public static function pagarHasta(object $cita): ?string
    {
        if (($cita->estado ?? null) !== 'Agendada' || ($cita->monto_abono_clp ?? null) === null
            || ($cita->payment_status ?? null) === 'approved') {
            return null;
        }
        $inicio = self::inicioVentana($cita);
        if ($inicio === null) {
            return null;
        }

        return CarbonImmutable::parse($inicio, 'UTC')
            ->addMinutes(SlotsService::MINUTOS_VENTANA_PAGO)
            ->format('Y-m-d\\TH:i:s\\Z');
    }

    /**
     * ¿Esta reserva ya perdió la hora por no pagar? Sí si el barrido la marcó
     * «Expirada», y TAMBIÉN si sigue «Agendada» pero pasó su plazo: es la misma
     * regla con la que SlotsService vuelve a ofrecer el horario antes de que el
     * barrido pase. Sin esto, la página de pago le seguía diciendo «tu hora
     * está reservada» a alguien cuya hora ya se le estaba ofreciendo a otro.
     */
    public static function horaLiberada(object $cita): bool
    {
        if (($cita->payment_status ?? null) === 'approved') {
            return false;
        }
        if (($cita->estado ?? null) === 'Expirada') {
            return true;
        }
        $hasta = self::pagarHasta($cita);

        return $hasta !== null && CarbonImmutable::parse($hasta)->isPast();
    }

    /**
     * La misma regla en SQL, para SlotsService, el barrido y el portal: la
     * ventana de la cita sigue abierta (o no tiene). `$limite` es «ahora menos
     * la ventana» en UTC; `$p` el prefijo de la tabla («c.» o vacío).
     * Un inicio NULL (cita sin `created_at`) no tiene plazo: no se suelta.
     */
    public static function whereVentanaAbierta($q, string $limite, string $p = ''): void
    {
        if (self::reglaNueva()) {
            $q->whereRaw("(COALESCE({$p}pago_enlace_at, {$p}created_at) IS NULL"
                . " OR COALESCE({$p}pago_enlace_at, {$p}created_at) >= ?)", [$limite]);

            return;
        }
        $q->where(fn ($w) => $w->whereNull("{$p}pago_modalidad_requerida")
            ->orWhere("{$p}created_at", '>=', $limite));
    }

    /** Lo contrario de `whereVentanaAbierta`: el plazo venció. */
    public static function whereVentanaVencida($q, string $limite, string $p = ''): void
    {
        if (self::reglaNueva()) {
            $q->whereRaw("COALESCE({$p}pago_enlace_at, {$p}created_at) < ?", [$limite]);

            return;
        }
        $q->whereNotNull("{$p}pago_modalidad_requerida")->where("{$p}created_at", '<', $limite);
    }

    /**
     * `['url', 'monto_abono_clp', 'monto_total_clp', 'congelado']` o
     * `['error' => texto para una persona]`.
     */
    public function generar(string $citaId): array
    {
        $cita = DB::table('citas')->where('id', $citaId)->first();
        if (! $cita) {
            return ['error' => 'No encontramos esa reserva.'];
        }
        if ($cita->estado !== 'Agendada') {
            return ['error' => match ($cita->estado) {
                'Confirmada' => 'Esta hora ya está confirmada.',
                'Cancelada' => 'Esta hora está cancelada.',
                'Expirada' => 'Esta reserva venció sin pagarse. Hay que reservar de nuevo.',
                default => 'Esta hora ya no admite pago en línea.',
            }];
        }
        if (CarbonImmutable::parse($cita->fecha_hora)->lte(CarbonImmutable::now())) {
            return ['error' => 'Esa hora ya pasó.'];
        }
        if (($cita->payment_status ?? null) === 'approved') {
            return ['error' => 'Esta hora ya está pagada.'];
        }
        // Reemplaza a una cita vieja que quedó pagada y sin cancelar (el
        // intermediario con un especialista no cancela sola una hora con
        // abono: "qué pasa con esa plata lo decide una persona", ver
        // IntermediarioEspecialista::soltarReemplazada). Cobrar esta también
        // sería cobrar dos veces la misma atención — incidente del 6-oct en
        // el lab, $45.000 + $22.500 por una sola ecografía.
        $respuestas = $cita->respuestas ? (json_decode($cita->respuestas, true) ?: []) : [];
        if (! empty($respuestas['Reemplaza cita pagada sin resolver'])) {
            return ['error' => 'Esta hora reemplaza otra que ya está pagada y no se canceló sola. '
                . 'Alguien de la clínica tiene que resolver ese pago antes de cobrar esta.'];
        }

        $congelado = false;
        if ($cita->monto_abono_clp === null) {
            $svc = $cita->servicio_id ? DB::table('servicios')->where('id', $cita->servicio_id)->first() : null;
            $esp = $cita->especialista_id ? DB::table('especialistas')->where('id', $cita->especialista_id)->first() : null;
            if (! $svc || ! $esp) {
                return ['error' => 'La reserva no tiene consulta o especialista asociado.'];
            }
            if (($svc->modalidad_pago ?: 'abono') === 'nada') {
                return ['error' => "{$svc->nombre} se paga en la clínica, no en línea."];
            }
            $cuando = CarbonImmutable::parse($cita->fecha_hora);
            $abono = $this->cobro->montoAbono($esp, $svc, $cuando);
            if ($abono === null) {
                return ['error' => "Falta cargar el valor de {$svc->nombre} con {$esp->nombre}."];
            }
            DB::table('citas')->where('id', $citaId)->update([
                'monto_abono_clp' => $abono,
                'monto_total_clp' => $this->cobro->montoTotal($esp, $svc, $cuando),
                'payment_status' => 'pending',
            ]);
            $cita = DB::table('citas')->where('id', $citaId)->first();
            $congelado = true;
        } elseif (self::horaLiberada($cita)) {
            return ['error' => 'Esta reserva venció su plazo de pago de '
                . SlotsService::MINUTOS_VENTANA_PAGO . ' minutos. Hay que reservar de nuevo.'];
        }

        $modalidad = $cita->pago_modalidad_requerida
            ?: (DB::table('servicios')->where('id', $cita->servicio_id)->value('modalidad_pago') ?: 'abono');
        $exigeTotal = $modalidad === 'total' && $cita->monto_total_clp !== null;

        // Una hora coordinada empieza su plazo AHORA, cuando el enlace sale
        // hacia el tutor. Solo la primera vez: volver a generarlo no le
        // regala otros 15 minutos. La del autoservicio ya corre desde que se
        // reservó y no se toca.
        if (self::reglaNueva() && $cita->pago_modalidad_requerida === null && empty($cita->pago_enlace_at)) {
            DB::table('citas')->where('id', $citaId)
                ->update(['pago_enlace_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')]);
            $cita = DB::table('citas')->where('id', $citaId)->first();
        }

        return [
            'url' => rtrim(config('emr.portal_reservas_url'), '/') . '/pago/' . $citaId,
            'monto_abono_clp' => (int) $cita->monto_abono_clp,
            'monto_total_clp' => $cita->monto_total_clp === null ? null : (int) $cita->monto_total_clp,
            // Lo que de verdad se cobra: Telemedicina se paga entera.
            'a_pagar_clp' => $exigeTotal ? (int) $cita->monto_total_clp : (int) $cita->monto_abono_clp,
            'exige_total' => $exigeTotal,
            'congelado' => $congelado,
            // Hasta cuándo puede pagar el tutor (UTC con «Z»): el panel y el
            // asistente se lo dicen al mandar el enlace.
            'pagar_hasta' => self::pagarHasta($cita),
        ];
    }
}
