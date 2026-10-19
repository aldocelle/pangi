<?php

namespace App\Console\Commands;

use App\Services\CitasEmail;
use App\Services\Correo;
use App\Services\Plantilla;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Recordatorios de cita, y de paso el barrido de reservas sin pagar.
 *
 * Las dos cosas en el mismo comando porque comparten la unidad de tiempo —el
 * minuto— y así no hay un cron más que recordar al operar. Es lo mismo que hace
 * `loop_recordatorios()` en Python. Van en `try` separados a propósito: si el
 * correo falla, el cupo se libera igual, y al revés.
 *
 * Cron sugerido (cada minuto; la etapa de 15 min necesita esa resolución):
 *
 *     * * * * * /usr/bin/php /home/.../api-laravel/artisan recordatorios:citas
 */
class RecordatoriosCitas extends TareaDeFondo
{
    protected $signature = 'recordatorios:citas';

    protected $description = 'Envía los recordatorios de cita y expira las reservas sin pagar';

    /**
     * Las tres etapas: columna del flag, horas de anticipación y cómo se lee.
     *
     * El flag va en UTC aunque `fecha_hora` esté en hora de la clínica: es una
     * marca técnica («ya se mandó»), como `created_at`, y solo se consulta con
     * `IS NULL` — nunca se compara contra `fecha_hora` (§9.6).
     */
    private const ETAPAS = [
        ['recordatorio_24h_enviado_at', 24.0, 'en 24 horas'],
        ['recordatorio_2h_enviado_at', 2.0, 'en 2 horas'],
        ['recordatorio_15min_enviado_at', 0.25, 'en 15 minutos'],
    ];

    // Una sola cifra para todo el sistema: la que usan SlotsService, el
    // asistente y LinkDePago. Una copia acá podía quedar distinta sin aviso.
    private const MINUTOS_VENTANA_PAGO = \App\Services\SlotsService::MINUTOS_VENTANA_PAGO;

    private const ESTADO_EXPIRADA = 'Expirada';

    public function __construct(
        private CitasEmail $emails,
        private Correo $correo,
    ) {
        parent::__construct();
    }

    protected function ejecutar(): string
    {
        $enviados = 0;
        $expiradas = 0;

        // El interruptor envuelve SOLO el envío, nunca el comando entero: el
        // barrido de abajo no manda correo y es lo único que libera el cupo de
        // una reserva que nadie pagó. Apagarlo dejaría esas horas tomadas para
        // siempre. Y se salta `enviarPendientes()` completa, sin entrar a
        // filtrar destinatario por destinatario: adentro es donde se marcan los
        // flags de etapa, y marcarlos sin enviar pierde el recordatorio en
        // silencio para cuando se reencienda.
        if (config('emr.correos_automaticos')) {
            try {
                $enviados = $this->enviarPendientes();
            } catch (\Throwable $e) {
                report($e);
                $this->error('Error en el ciclo de recordatorios: ' . $e->getMessage());
            }
        }

        try {
            $expiradas = $this->expirarSinPago();
        } catch (\Throwable $e) {
            report($e);
            $this->error('Error expirando reservas sin pago: ' . $e->getMessage());
        }

        $limpiados = 0;
        try {
            $limpiados = $this->limpiarCalendario();
        } catch (\Throwable $e) {
            report($e);
            $this->error('Error limpiando el calendario de Google: ' . $e->getMessage());
        }

        return ($enviados || $expiradas || $limpiados)
            ? "Recordatorios enviados: {$enviados} · reservas expiradas: {$expiradas}"
                . " · eventos de Google borrados: {$limpiados}"
            : '';
    }

    /**
     * Saca de Google las horas que ya no existen: expiradas o canceladas que
     * todavía tienen evento.
     *
     * Visto el 06-oct en el calendario de pruebas: amara y Tuffy habían
     * expirado sin pago y el viernes seguían en Google, una encima de una hora
     * real. El especialista ve su agenda allá, así que la hora se soltaba en
     * nuestra base y seguía tomada para él.
     *
     * Va como barrido de cada pasada y no solo en el momento de expirar o
     * cancelar, por la misma razón que Google falla abierto: si Google no
     * contesta, la cita cambia de estado igual y el evento queda. Acá se
     * reintenta solo al minuto siguiente, en vez de esperar a que alguien lo
     * vea en el calendario. Solo horas futuras (las pasadas son historia y no
     * estorban a nadie) y con tope por pasada, para no gastar la cuota de
     * Google de golpe si algún día se juntan muchas.
     */
    private function limpiarCalendario(): int
    {
        $huerfanas = DB::table('citas')
            ->whereIn('estado', [self::ESTADO_EXPIRADA, 'Cancelada'])
            ->whereNotNull('google_event_id')
            ->where('fecha_hora', '>=', CarbonImmutable::now()->format('Y-m-d H:i:s.u'))
            ->orderBy('fecha_hora')->orderBy('id')
            ->limit(20)
            ->get();

        $google = app(\App\Services\GoogleCalendar::class);
        $borrados = 0;
        foreach ($huerfanas as $cita) {
            if ($google->eliminarEventoDeCita($cita)) {
                DB::table('citas')->where('id', $cita->id)->update(['google_event_id' => null]);
                $borrados++;
            }
        }

        return $borrados;
    }

    /**
     * Manda lo que corresponda de cada etapa cuya ventana ya se alcanzó.
     *
     * **Hora de la clínica, NO UTC.** `citas.fecha_hora` se guarda en local, y
     * compararla contra un «ahora» en UTC la corre cuatro horas: el aviso de
     * «en 15 minutos» saldría 4 h 15 antes. Y algo peor que el desfase — con el
     * filtro `fecha_hora > ahora` usando la hora adelantada, toda cita a menos
     * de cuatro horas queda «en el pasado» y no recibe NINGÚN recordatorio.
     */
    private function enviarPendientes(): int
    {
        $ahora = CarbonImmutable::now();
        $enviados = 0;

        foreach (self::ETAPAS as [$flag, $horas, $cuando]) {
            $limite = $ahora->addMinutes((int) round($horas * 60));

            $citas = DB::table('citas')
                ->whereIn('estado', ['Agendada', 'Confirmada'])
                ->whereNull($flag)
                ->where('fecha_hora', '>', $ahora->format('Y-m-d H:i:s.u'))
                ->where('fecha_hora', '<=', $limite->format('Y-m-d H:i:s.u'))
                ->orderBy('fecha_hora')->orderBy('id')
                ->get();

            foreach ($citas as $cita) {
                $usuario = DB::table('usuarios')->where('id', $cita->usuario_dueno_id)->first();
                $mascota = DB::table('mascotas')->where('id', $cita->mascota_id)->first();
                if (! $usuario || ! $mascota) {
                    continue;
                }

                [$asunto, $texto, $html] = $this->cuerpo($usuario, $mascota, $cita, $cuando);
                if ($this->correo->enviar($usuario->email, $asunto, $texto, $html)) {
                    DB::table('citas')->where('id', $cita->id)->update([
                        $flag => now('UTC')->format('Y-m-d H:i:s.u'),
                    ]);
                    $enviados++;
                }
                // Si el envío falla NO se marca: se reintenta en el próximo
                // ciclo. Marcarlo igual perdería el recordatorio en silencio.
            }
        }

        return $enviados;
    }

    /**
     * Suelta el cupo de las reservas que agotaron su ventana de pago.
     *
     * Solo toca citas **futuras**: una hora que ya pasó no tiene cupo que
     * liberar, y reescribirle el estado solo ensucia la agenda de días
     * cerrados.
     *
     * `monto_abono_clp IS NOT NULL` es lo que acota esto a las reservas que de
     * verdad cobran en línea. Una consulta que se paga en el mostrador nunca
     * entra acá: no hay pago que esperar, y expirarla le quitaría la hora a
     * alguien que no debía nada.
     *
     * El límite va en UTC porque se compara contra `created_at`, que es marca
     * técnica — mezclarlo con la hora local lo corre cuatro horas y expira
     * reservas recién creadas, justo mientras su dueño las está pagando.
     */
    private function expirarSinPago(): int
    {
        $limite = CarbonImmutable::now('UTC')
            ->subMinutes(self::MINUTOS_VENTANA_PAGO)
            ->format('Y-m-d H:i:s.u');

        $vencidas = DB::table('citas')
            ->where('estado', 'Agendada')
            ->where('fecha_hora', '>=', CarbonImmutable::now()->format('Y-m-d H:i:s.u'))
            ->whereNotNull('monto_abono_clp')
            ->where(fn ($q) => $q->whereNull('payment_status')
                ->orWhere('payment_status', '<>', 'approved'))
            // Toda hora con abono pendiente tiene ventana desde el 12-oct: la
            // del autoservicio desde que se reservó, la coordinada por la
            // clínica desde que se le mandó el enlace (LinkDePago).
            ->where(fn ($q) => \App\Services\LinkDePago::whereVentanaVencida($q, $limite))
            ->orderBy('id')
            ->get();

        if ($vencidas->isEmpty()) {
            return 0;
        }

        DB::table('citas')->whereIn('id', $vencidas->pluck('id'))
            ->update(['estado' => self::ESTADO_EXPIRADA]);

        // El evento espejo de Google lo saca `limpiarCalendario()`, que corre
        // justo después en cada pasada.

        // Quien reservó por WhatsApp se entera en el mismo chat de que la hora
        // se soltó (05-oct). Sin lanzar: el cupo ya quedó libre.
        $aviso = app(\App\Services\AvisoPagoWhatsapp::class);
        foreach ($vencidas as $cita) {
            $aviso->avisarHoraLiberada($cita, DB::table('mascotas')->where('id', $cita->mascota_id)->first());
        }

        return $vencidas->count();
    }

    /** @return array{0:string,1:string,2:string} asunto, texto y html. */
    public function cuerpo(object $usuario, object $mascota, object $cita,
                            string $cuando): array
    {
        // La misma función que arma la fecha en el correo de confirmación.
        // `strftime('%A')` no sirve: el servidor no tiene locale español y al
        // cliente le llegaba «Monday 24 de august» (§9.7).
        $fecha = $this->emails->fechaLegible($cita->fecha_hora);
        $enlace = rtrim(config('emr.portal_reservas_url'), '/') . '/citas';
        $servicio = $cita->servicio ?: '-';
        $telefono = config('emr.clinica_telefono');
        // El lugar también: el aviso de 2 horas o de 15 minutos es el que se
        // abre camino a la clínica, y antes no decía adónde ir.
        $lugar = $this->emails->lugar($cita);
        $asunto = "Recordatorio: cita de {$mascota->nombre} {$cuando} ("
            . $this->emails->fechaLegible($cita->fecha_hora, false) . ')';
        $sinPortal = "Si no puedes entrar al portal, escríbenos por WhatsApp al {$telefono} y la cambiamos por ti.";

        $texto = "Hola {$usuario->nombre},\n\n"
            . "Te recordamos que la cita de {$mascota->nombre} en " . \App\Services\Marca::nombre() . " es {$cuando}:\n"
            . "  Servicio: {$servicio}\n"
            . "  Fecha: {$fecha}\n"
            . "  Lugar: {$lugar}\n\n"
            . "¿Necesitas cambiar la hora? Hazlo desde «Mis citas»:\n{$enlace}\n\n"
            . "{$sinPortal}\n\n"
            . \App\Services\Marca::nombre();

        // Escapado: `usuario.nombre`, `mascota.nombre` y `cita.servicio` vienen
        // de un formulario público SIN sesión, y este correo sale con el
        // remitente real de la clínica.
        //
        // El HTML existe por una sola razón: que «reagendar» sea un enlace en
        // el que se pueda hacer clic. En texto plano la única salida era
        // «contáctanos», así que quien no podía asistir cancelaba en vez de
        // mover la hora — y una hora movida conserva al paciente, una cancelada
        // lo pierde. Por eso reagendar va primero y como botón, y cancelar ni
        // se nombra.
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ink = Plantilla::INK;

        $html = Plantilla::pagina(
            'Recordatorio de tu cita',
            "Hola {$e($usuario->nombre)}, la cita de "
                . "<strong style='color:{$ink}'>{$e($mascota->nombre)}</strong> "
                . "es <strong style='color:{$ink}'>{$e($cuando)}</strong>.",
            Plantilla::tarjeta(
                'Detalle de tu reserva',
                Plantilla::fila('🐾', "<strong>{$e($mascota->nombre)}</strong>")
                . Plantilla::fila('🩺', $e($servicio))
                . Plantilla::fila(Plantilla::ICONO_FECHA, "<strong>{$e($fecha)}</strong>")
                . Plantilla::fila('📍', $e($lugar)),
            )
            . Plantilla::bloqueReagendar($enlace)
            . Plantilla::parrafo(str_replace($e($telefono), "<strong>{$e($telefono)}</strong>", $e($sinPortal)), true)
            . Plantilla::enlaceAlterno($enlace),
        );

        return [$asunto, $texto, $html];
    }
}
