<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Los correos que salen alrededor de una cita. Port de
 * `citas_email_service.py`.
 *
 * **Ninguna función de acá puede lanzar.** Un fallo mandando el correo no
 * puede tumbar la reserva que ya quedó guardada: el cupo está tomado y el
 * cliente tiene su hora, aunque el aviso no salga. Se loggea y se sigue —
 * mismo criterio que `email_service.enviar`, que nunca lanza.
 */
class CitasEmail
{
    public const DIRECCION_CLINICA = 'Av. Principal 1234, local 5, Puente Alto';

    /**
     * Qué esperar el día de la atención. **Una sola lista** para las dos
     * versiones del correo (texto plano y HTML): estaban escritas dos veces y
     * ya habían quedado distintas entre sí.
     */
    public const QUE_ESPERAR = [
        'Asiste puntual.',
        'Recuerda traer carnet sanitario e historial médico y recetas anteriores.',
        'Contamos con estacionamiento público a pasos de la clínica.',
    ];

    private const DIAS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    private const MESES = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    public function __construct(private Correo $correo)
    {
    }

    /**
     * «Sábado 25 de julio, 11:00 AM», en español y **sin depender del locale
     * del sistema**.
     *
     * No se usa el formateo localizado de Carbon por lo mismo que Python no usa
     * `strftime("%A")`: el servidor no tiene locale español instalado y la
     * fecha salía medio en inglés — «Monday 24 de august» (§9.7). Las dos
     * listas son la única fuente.
     */
    public function fechaLegible(string $fechaHora): string
    {
        $dt = CarbonImmutable::parse($fechaHora);
        // `dayOfWeekIso` va 1..7 desde el lunes, igual que `weekday()` de
        // Python va 0..6 desde el lunes.
        $dia = self::DIAS[$dt->dayOfWeekIso - 1];
        $mes = self::MESES[$dt->month - 1];
        // `ltrim` del cero inicial: Python hace `.lstrip("0")` sobre "%I:%M %p",
        // así que las 09:00 salen «9:00 AM» y no «09:00 AM».
        $hora12 = ltrim($dt->format('h:i A'), '0');

        return mb_convert_case(mb_substr($dia, 0, 1), MB_CASE_UPPER) . mb_substr($dia, 1)
            . " {$dt->day} de {$mes}, {$hora12}";
    }

    private function clp(int $monto): string
    {
        return '$' . number_format($monto, 0, ',', '.');
    }

    private function esTelemedicina(?string $servicio): bool
    {
        return str_contains(mb_strtolower($servicio ?? ''), 'telemedicina');
    }

    private function esDomicilio(?string $servicio): bool
    {
        return str_contains(mb_strtolower($servicio ?? ''), 'domicilio');
    }

    /**
     * Dónde ocurre la atención.
     *
     * Si el servicio NO es a domicilio, la dirección que traiga `respuestas`
     * **no aplica**: mostrarla igual filtraría el domicilio del tutor en el
     * correo de una consulta que ocurre en la clínica. Ya pasó — una cita de
     * Exámenes de sangre quedó con la dirección particular de una reserva
     * anterior guardada en `respuestas`, y sin esta guarda el correo se la
     * mostraba como el lugar de esa consulta.
     */
    public function lugar(object $cita): string
    {
        if ($this->esTelemedicina($cita->servicio)) {
            return 'Videollamada por Google Meet (el enlace llega antes de la hora agendada).';
        }
        if ($this->esDomicilio($cita->servicio)) {
            $respuestas = $cita->respuestas
                ? (json_decode($cita->respuestas, true) ?: []) : [];
            if (! empty($respuestas['Dirección'])) {
                return $respuestas['Dirección'];
            }
        }

        return (string) (config('emr.clinica_direccion') ?: self::DIRECCION_CLINICA);
    }

    /** Dónde el cliente mueve su hora: «Mis citas» del portal. */
    private function enlaceReagendar(): string
    {
        return rtrim((string) config('emr.portal_reservas_url'), '/') . '/citas';
    }

    private function enlacePago(string $citaId): string
    {
        return config('emr.portal_reservas_url') . "/pago/{$citaId}";
    }

    /**
     * ¿La reserva todavía espera el abono (o el pago total) en línea?
     *
     * Mismo criterio que `bloqueAbonoTexto()` y que el barrido de 15 minutos:
     * hay monto congelado y el pago no está aprobado.
     */
    private function abonoPendiente(object $cita): bool
    {
        return ! empty($cita->monto_abono_clp) && $cita->payment_status !== 'approved';
    }

    /** True si la consulta exige el 100% por adelantado (Telemedicina). */
    private function esPagoTotal(object $cita): bool
    {
        return $cita->pago_modalidad_requerida === 'total';
    }

    /**
     * Aviso de abono pendiente. **No aparece si el pago ya está aprobado**:
     * quien acaba de pagar en el checkout no tiene por qué recibir un cobro en
     * el mismo correo.
     *
     * El plazo sale de `SlotsService::MINUTOS_VENTANA_PAGO`, no escrito a mano.
     * Decía «48 horas, la reserva se cancela automáticamente»: ni el cupo dura
     * eso —lo suelta la ventana de 15 minutos— ni el enlace deja de servir
     * después, porque revive la cita si nadie tomó la hora. El portal decía 15
     * en la pantalla del pago, y la clínica vio los dos plazos juntos
     * (sep-2026).
     */
    private function bloqueAbonoTexto(object $cita): string
    {
        if (empty($cita->monto_abono_clp) || $cita->payment_status === 'approved') {
            return '';
        }

        $monto = $this->clp((int) $cita->monto_abono_clp);
        $lineaMonto = $this->esPagoTotal($cita)
            ? "🩺 Para confirmar y hacer efectiva esta reserva se requiere el pago completo de {$monto}."
            : "🩺 Para confirmar y hacer efectiva esta reserva se requiere un abono de {$monto} (50% del valor de la consulta).";
        $queFalta = $this->esPagoTotal($cita) ? 'el pago' : 'el abono';
        $enlace = $this->enlacePago((string) $cita->id);

        return "\n{$lineaMonto}\n\n"
            . "Paga con tarjeta de crédito o débito aquí:\n  {$enlace}\n\n"
            . "🛑 Guardamos la hora " . SlotsService::MINUTOS_VENTANA_PAGO . " minutos para que "
            . "realices {$queFalta}. Pasado ese plazo este enlace sigue sirviendo, pero la hora "
            . "puede tomarla otra persona.\n\n"
            . "⏳ Si ya pagaste y por fuerza mayor no puedes asistir, avisa con 24 horas "
            . "de anticipación: la devolución corresponde al 60% del valor pagado. Con "
            . "menos de 24 horas de aviso no procede el reembolso.\n";
    }

    /** El correo de confirmación al tutor. */
    /**
     * EL asunto de la reserva, y por qué lo comparten dos correos.
     *
     * Al tutor le llegan dos mensajes nuestros por una reserva con abono: el de
     * la reserva creada y, al pagar, el comprobante. La clínica pidió
     * fusionarlos y que los dos se llamen «Reserva confirmada» (05-sep-2026).
     *
     * Fusionarlos de verdad no se puede —salen en momentos distintos, y el
     * primero es el que lleva el enlace de pago—, pero el asunto IDÉNTICO logra
     * lo que se buscaba: los clientes de correo agrupan por asunto, así que el
     * tutor ve UNA conversación «Reserva confirmada» en vez de dos correos
     * sueltos que parecen de cosas distintas. Encima está la invitación de
     * Google, que también le llega por ser invitado del evento; el asunto de esa
     * lo compone Google y no lo controlamos.
     *
     * Vive en un método porque el día que los dos asuntos se separen por un
     * carácter, la agrupación se rompe EN SILENCIO: no falla nada, solo vuelven
     * a verse como dos correos.
     */
    private function asuntoReserva(object $mascota, string $fecha): string
    {
        return "Reserva confirmada — " . Marca::nombre() . " | {$mascota->nombre} — {$fecha}";
    }

    /**
     * El comprobante del pago. Era el último `TODO(efectos)` del port.
     *
     * Sin esto, el día del corte quien pagara su abono dejaba de recibir el
     * único correo que la clínica le dice que guarde como comprobante — y no
     * habría fallado nada: el cobro se cerraba igual, en silencio.
     *
     * Quien llama debe garantizar que sale UNA sola vez. Getnet avisa dos veces
     * (webhook + vuelta del navegador) y `guardarEstado()` corta la segunda, así
     * que va en la TRANSICIÓN a `approved`, nunca en cada consulta.
     */
    public function notificarPagoRecibido(
        object $usuario, object $mascota, object $cita, ?object $especialista, ?int $pagado = null): bool
    {
        try {
            $fecha = $this->fechaLegible($cita->fecha_hora);
            $conEsp = $especialista ? " con {$especialista->nombre}" : '';

            // Quién pagó qué. El cliente puede haber dejado la consulta pagada
            // entera: hablarle siempre del «abono» le manda un comprobante por
            // la mitad del monto y un saldo que ya pagó, y llega al mostrador a
            // pagar dos veces lo mismo.
            //
            // `$pagado` es lo que se COBRÓ (PagosController::montoDe con la
            // modalidad que eligió). Antes se deducía solo de la modalidad
            // REQUERIDA, así que quien elegía pagar el total de una consulta
            // de abono recibía «abono de $X» y un saldo que ya había pagado.
            $total = $cita->monto_total_clp !== null ? (int) $cita->monto_total_clp : null;
            $abono = $cita->monto_abono_clp !== null ? (int) $cita->monto_abono_clp : null;
            $pagoTotal = $this->esPagoTotal($cita) || ($pagado !== null && $total !== null && $pagado >= $total);
            $pagado ??= $pagoTotal ? $total : $abono;
            $monto = $pagado !== null ? $this->clp($pagado) : '';

            // null y no 0 cuando el total no se conoce: en una radiografía SÍ
            // queda saldo, solo que se calcula en la clínica. Un 0 le diría al
            // cliente que no debe nada.
            $saldo = (! $pagoTotal && $total !== null && $abono !== null)
                ? $total - $abono : null;

            $titulo = $pagoTotal ? 'Pago recibido' : 'Abono recibido';
            $concepto = $pagoTotal ? 'pago' : 'abono';
            $asunto = $this->asuntoReserva($mascota, $fecha);

            $lineaSaldo = ($saldo !== null && $saldo > 0)
                ? '  Saldo a pagar en la clínica: ' . $this->clp($saldo) . "\n" : '';
            $autorizacion = ! empty($cita->pago_autorizacion)
                ? "  Código de autorización: {$cita->pago_autorizacion}\n" : '';
            // El RUT de quien pagó (decisión de producto, 06-oct): el que escribió en
            // la pantalla de pago, o el que ya tenía la cuenta. Válido o nada.
            $resp = $cita->respuestas ? (json_decode($cita->respuestas, true) ?: []) : [];
            $rutN = Rut::normalizar($resp['RUT del pago'] ?? null) ?? Rut::normalizar($usuario->rut ?? null);
            $rut = $rutN ? Rut::formatear($rutN) : null;
            $lineaRut = $rut ? "  RUT: {$rut}\n" : '';

            $texto = "Hola {$usuario->nombre},\n\n"
                . "Recibimos tu {$concepto} de {$monto}. Tu hora quedó confirmada.\n\n"
                . "  Mascota: {$mascota->nombre}\n"
                . '  Servicio: ' . ($cita->servicio ?: '-') . "\n"
                . "  Fecha: {$fecha}{$conEsp}\n"
                . '  Lugar: ' . $this->lugar($cita) . "\n"
                . $lineaRut . $lineaSaldo . $autorizacion
                . "\nGuarda este correo como comprobante.\n\n"
                . "Si necesitas reagendar o cancelar, contáctanos o hazlo desde tu portal de reservas.\n\n"
                . Marca::nombre();

            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $filas = Plantilla::fila('🐾', '<strong>' . $e($mascota->nombre) . '</strong>')
                . Plantilla::fila('🩺', $e($cita->servicio ?: '-'))
                . Plantilla::fila('📅', $e($fecha) . $e($conEsp))
                . Plantilla::fila('📍', $e($this->lugar($cita)));
            if ($saldo !== null && $saldo > 0) {
                $filas .= Plantilla::fila('💲',
                    'Saldo a pagar en la clínica: <strong>' . $e($this->clp($saldo)) . '</strong>');
            }
            if ($rut) {
                $filas .= Plantilla::fila('🪪', 'RUT: ' . $e($rut), true);
            }
            if (! empty($cita->pago_autorizacion)) {
                $filas .= Plantilla::fila('🔖',
                    'Autorización: ' . $e($cita->pago_autorizacion), true);
            }

            $html = Plantilla::pagina(
                $e($titulo) . ' ✓',
                'Hola ' . $e($usuario->nombre) . ', recibimos tu ' . $concepto . ' de '
                    . '<strong style="color:' . Plantilla::INK . '">' . $e($monto) . '</strong>. '
                    . 'Tu hora quedó confirmada.',
                Plantilla::tarjeta(
                    $especialista ? 'Atiende ' . $e($especialista->nombre) : 'Detalle de tu reserva',
                    $filas
                ),
                'Guarda este correo como comprobante.'
            );

            // El resultado lo usa el aviso de WhatsApp: solo dice «te enviamos
            // el comprobante» si de verdad salió.
            return $this->correo->enviar($usuario->email, $asunto, $texto, $html);
        } catch (\Throwable $ex) {
            Log::error('Error notificando pago recibido (cita ' . $cita->id . '): ' . $ex->getMessage());

            return false;
        }
    }

    public function notificarReservaCreada(
        object $usuario, object $mascota, object $cita, ?object $especialista): void
    {
        try {
            $fecha = $this->fechaLegible($cita->fecha_hora);
            $lugar = $this->lugar($cita);
            $conEsp = $especialista ? " con {$especialista->nombre}" : '';

            $asunto = $this->asuntoReserva($mascota, $fecha);

            $queEsperar = '';
            foreach (self::QUE_ESPERAR as $linea) {
                $queEsperar .= "  - {$linea}\n";
            }

            // «Confirmada» solo si no queda nada que pagar en línea. Con abono
            // pendiente decía «Tu visita está confirmada» y 15 minutos después
            // la hora se liberaba: el tutor tenía por escrito una promesa que
            // el sistema no cumplía (visto en pruebas el 14-sep-2026).
            $apertura = $this->abonoPendiente($cita)
                ? 'Reservamos tu hora. Para dejarla confirmada falta '
                    . ($this->esPagoTotal($cita) ? 'el pago' : 'el abono') . '. Aquí el detalle:'
                : 'Tu visita está confirmada. Aquí el detalle:';

            $texto = "Hola {$usuario->nombre},\n\n"
                . "{$apertura}\n\n"
                . "  Mascota: {$mascota->nombre}\n"
                . '  Servicio: ' . ($cita->servicio ?: '-') . "\n"
                . "  Fecha: {$fecha}{$conEsp}\n"
                . "  Lugar: {$lugar}\n"
                . $this->bloqueAbonoTexto($cita) . "\n"
                . "Qué esperar:\n"
                . $queEsperar
                . "\nSi necesitas reagendar o cancelar, contáctanos o hazlo desde tu portal de reservas"
                . ($this->abonoPendiente($cita) ? ".\n\n" : ":\n" . $this->enlaceReagendar() . "\n\n")
                . Marca::nombre();

            $this->correo->enviar($usuario->email, $asunto, $texto,
                $this->html($usuario, $mascota, $cita, $especialista, $fecha, $lugar));
        } catch (\Throwable $e) {
            Log::error('Error notificando reserva creada (cita ' . $cita->id . '): ' . $e->getMessage());
        }
    }

    /**
     * A quién avisarle, o null si a nadie. **Única regla de decisión.**
     *
     * Existe como método público y no como un `if` repetido porque la usan dos
     * sitios: estos correos y la invitación de Google Calendar. Si cada uno
     * decidiera por su cuenta, un especialista podría recibir la invitación del
     * calendario sin el correo, o al revés.
     *
     * Tener el correo cargado NO alcanza: hace falta `recibe_avisos`. Son cosas
     * distintas — la clínica puede querer el dato de contacto a mano sin haber
     * acordado con esa persona que el sistema le escriba.
     */
    public function correoEspecialista(?object $especialista): ?string
    {
        if (! $especialista || ! ($especialista->recibe_avisos ?? false)) {
            return null;
        }
        $email = trim((string) ($especialista->email ?? ''));

        return $email !== '' ? $email : null;
    }

    /**
     * Una línea informativa, no un cobro.
     *
     * Le importa al especialista porque una hora con el abono pendiente puede
     * caerse —sin pagar, el cupo se suelta al vencer la ventana de pago—. Sin este
     * dato vería como firmes horas que no lo son.
     */
    private function estadoAbono(object $cita): string
    {
        if (empty($cita->monto_abono_clp)) {
            return 'Se paga en la clínica.';
        }
        $monto = $this->clp((int) $cita->monto_abono_clp);

        return $cita->payment_status === 'approved'
            ? "Abono de {$monto} pagado."
            : "Abono de {$monto} pendiente (la hora se cae si no se paga).";
    }

    /**
     * Las filas del aviso —(ícono, etiqueta, valor)— en el orden en que se
     * leen. La etiqueta sigue existiendo aunque el HTML muestre el ícono: la
     * versión en texto plano la necesita, y ahí «Paciente: Diana» sí se lee.
     */
    private function datosParaEspecialista(object $usuario, object $mascota, object $cita): array
    {
        $contacto = implode(' · ', array_filter(
            [$usuario->nombre ?? null, $usuario->telefono ?? null, $usuario->email ?? null]));

        $filas = [
            ['🐾', 'Paciente', $mascota->especie
                ? "{$mascota->nombre} ({$mascota->especie})" : $mascota->nombre],
            ['🩺', 'Consulta', $cita->servicio ?: '-'],
            ['📅', 'Fecha', $this->fechaLegible($cita->fecha_hora)],
            ['⏱️', 'Duración', $cita->duracion_min ? "{$cita->duracion_min} min" : '-'],
            ['📍', 'Lugar', $this->lugar($cita)],
            ['👤', 'Tutor', $contacto],
        ];
        if ($cita->notas_pre_cita) {
            $filas[] = ['📝', 'Motivo', $cita->notas_pre_cita];
        }
        $filas[] = ['💲', 'Pago', $this->estadoAbono($cita)];

        return $filas;
    }

    /**
     * El aviso al especialista. **Sin enlace de pago**: el cobro es entre la
     * clínica y el tutor.
     */
    private function avisarEspecialista(?object $especialista, object $usuario,
        object $mascota, object $cita, string $asunto, string $encabezado, string $contexto): void
    {
        $destino = $this->correoEspecialista($especialista);
        if (! $destino) {
            return;
        }

        try {
            $filas = $this->datosParaEspecialista($usuario, $mascota, $cita);

            $lineas = '';
            foreach ($filas as [, $etiqueta, $valor]) {
                $lineas .= "  {$etiqueta}: {$valor}\n";
            }
            $texto = "Hola {$especialista->nombre},\n\n{$encabezado}\n\n{$lineas}\n" . Marca::nombre();

            // Escapado por lo mismo que el correo del tutor, y con MÁS
            // superficie: acá viajan el nombre del tutor, el de la mascota, el
            // motivo de consulta y la dirección — todos campos de un formulario
            // público que se llena sin sesión.
            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $filasHtml = '';
            foreach ($filas as [$icono, , $valor]) {
                $filasHtml .= Plantilla::fila($icono, $e($valor));
            }
            $html = Plantilla::pagina(
                $e($encabezado),
                'Hola ' . $e($especialista->nombre),
                Plantilla::tarjeta($e($cita->servicio ?: 'Detalle de la hora'), $filasHtml)
            );

            $this->correo->enviar($destino, $asunto, $texto, $html);
        } catch (\Throwable $ex) {
            Log::error("Error avisando al especialista ({$contexto}, cita {$cita->id}): "
                . $ex->getMessage());
        }
    }

    public function notificarEspecialistaReservaCreada(?object $especialista,
        object $usuario, object $mascota, object $cita): void
    {
        $fecha = $this->fechaLegible($cita->fecha_hora);
        $this->avisarEspecialista($especialista, $usuario, $mascota, $cita,
            "Nueva hora agendada — {$mascota->nombre}, {$fecha}",
            'Te agendaron una hora', 'reserva creada');
    }

    public function notificarEspecialistaReagendada(?object $especialista,
        object $usuario, object $mascota, object $cita): void
    {
        $fecha = $this->fechaLegible($cita->fecha_hora);
        $this->avisarEspecialista($especialista, $usuario, $mascota, $cita,
            "Cambio de hora — {$mascota->nombre}, {$fecha}",
            'Una hora tuya cambió de fecha', 'reagendada');
    }

    /**
     * Aviso de reagendamiento al tutor. Antes era solo texto; ahora lleva la
     * misma tarjeta de detalle que la confirmación, así que se reconoce como
     * «su hora» de un vistazo y trae el lugar, que el aviso viejo omitía.
     */
    public function notificarCitaReagendada(object $usuario, object $mascota,
                                            object $cita, ?object $especialista): void
    {
        try {
            $fecha = $this->fechaLegible($cita->fecha_hora);
            $conEsp = $especialista ? " con {$especialista->nombre}" : '';

            $texto = "Hola {$usuario->nombre},\n\n"
                . "La cita de {$mascota->nombre} en " . Marca::nombre() . " fue reagendada:\n"
                . '  Servicio: ' . ($cita->servicio ?: '-') . "\n"
                . "  Nueva fecha: {$fecha}{$conEsp}\n"
                . '  Lugar: ' . $this->lugar($cita) . "\n\n"
                . "¿Necesitas cambiarla otra vez? Reagenda aquí:\n" . $this->enlaceReagendar() . "\n\n"
                . "Si esto no fue solicitado por ti, contáctanos a la brevedad.\n\n"
                . Marca::nombre();

            $e = fn ($v) => Plantilla::e($v);
            $filas = Plantilla::fila('🐾', '<strong>' . $e($mascota->nombre) . '</strong>')
                . Plantilla::fila('🩺', $e($cita->servicio ?: '-'))
                . Plantilla::fila('📅', '<strong>' . $e($fecha) . '</strong>' . $e($conEsp))
                . Plantilla::fila('📍', $e($this->lugar($cita)));

            $html = Plantilla::pagina(
                'Tu cita cambió de hora',
                'Hola ' . $e($usuario->nombre) . ', la cita de <strong style="color:' . Plantilla::INK . '">'
                    . $e($mascota->nombre) . '</strong> fue reagendada.',
                Plantilla::tarjeta($especialista ? 'Atiende ' . $e($especialista->nombre) : 'Tu nueva hora', $filas)
                . Plantilla::bloqueReagendar($this->enlaceReagendar()),
                'Si esto no fue solicitado por ti, contáctanos a la brevedad.'
            );

            $this->correo->enviar($usuario->email,
                "Tu cita cambió de hora: {$mascota->nombre} — {$fecha}", $texto, $html);
        } catch (\Throwable $e) {
            Log::error("Error notificando reagendamiento (cita {$cita->id}): " . $e->getMessage());
        }
    }

    /**
     * Cancelada, Confirmada o Completada — cualquier cambio de estado que no
     * sea la creación inicial ni el reagendamiento.
     *
     * El título cambia con el estado y el ✓ solo va donde algo se cerró bien;
     * una cancelación no lleva ✓. El lugar se omite al cancelar: a quien ya no
     * va no le sirve saber adónde.
     */
    public function notificarCambioEstado(object $usuario, object $mascota, object $cita): void
    {
        try {
            $fecha = $this->fechaLegible($cita->fecha_hora);
            $etiquetas = [
                'Cancelada' => 'cancelada',
                'Confirmada' => 'confirmada',
                'Completada' => 'marcada como completada',
                'Agendada' => 'reactivada',
            ];
            $etiqueta = $etiquetas[$cita->estado] ?? mb_strtolower((string) $cita->estado);
            $cancelada = $cita->estado === 'Cancelada';

            $texto = "Hola {$usuario->nombre},\n\n"
                . "La cita de {$mascota->nombre} en " . Marca::nombre() . " fue {$etiqueta}:\n"
                . '  Servicio: ' . ($cita->servicio ?: '-') . "\n"
                . "  Fecha: {$fecha}\n\n"
                . "Si tienes dudas, contáctanos.\n\n"
                . Marca::nombre();

            $e = fn ($v) => Plantilla::e($v);
            $filas = Plantilla::fila('🐾', '<strong>' . $e($mascota->nombre) . '</strong>')
                . Plantilla::fila('🩺', $e($cita->servicio ?: '-'))
                . Plantilla::fila('📅', $e($fecha));
            if (! $cancelada) {
                $filas .= Plantilla::fila('📍', $e($this->lugar($cita)));
            }

            $html = Plantilla::pagina(
                'Tu cita fue ' . $e($etiqueta) . (in_array($cita->estado, ['Confirmada', 'Completada'], true) ? ' ✓' : ''),
                'Hola ' . $e($usuario->nombre) . ', la cita de <strong style="color:' . Plantilla::INK . '">'
                    . $e($mascota->nombre) . '</strong> fue ' . $e($etiqueta) . '.',
                Plantilla::tarjeta('Detalle de la cita', $filas),
                'Si tienes dudas, contáctanos.'
            );

            $this->correo->enviar($usuario->email,
                "Tu cita fue {$etiqueta}: {$mascota->nombre} — {$fecha}", $texto, $html);
        } catch (\Throwable $e) {
            Log::error("Error notificando cambio de estado (cita {$cita->id}): " . $e->getMessage());
        }
    }

    public function notificarEspecialistaCancelada(?object $especialista,
        object $usuario, object $mascota, object $cita): void
    {
        $fecha = $this->fechaLegible($cita->fecha_hora);
        $this->avisarEspecialista($especialista, $usuario, $mascota, $cita,
            "Hora cancelada — {$mascota->nombre}, {$fecha}",
            'Se canceló una hora tuya', 'cancelada');
    }

    /**
     * La versión HTML.
     *
     * **Todo lo que viene del formulario público va escapado**: el nombre del
     * tutor, el de la mascota y `lugar` (que puede salir de
     * `respuestas["Dirección"]`) son campos libres de un formulario SIN sesión.
     * Sin escapar, cualquiera de los tres inyecta contenido en un correo que
     * sale con el remitente real de la clínica — phishing con firma legítima.
     */
    private function html(object $usuario, object $mascota, object $cita,
                          ?object $especialista, string $fecha, string $lugar): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $cabecera = $especialista
            ? 'Atiende ' . $e($especialista->nombre) : 'Detalle de tu reserva';

        // El detalle va como filas de ícono + texto y NO como tabla
        // etiqueta/valor: en esa tabla el nombre de la mascota salía pegado a
        // su etiqueta —«MascotaDiana»— en los clientes que colapsan celdas.
        $filas = Plantilla::fila('🐾', '<strong>' . $e($mascota->nombre) . '</strong>')
            . Plantilla::fila('🩺', $e($cita->servicio ?: '-'))
            . Plantilla::fila('📅', $e($fecha))
            . Plantilla::fila('📍', $e($lugar));

        $items = '';
        foreach (self::QUE_ESPERAR as $linea) {
            $items .= Plantilla::fila('•', $e($linea));
        }

        // Con el pago pendiente, el HTML no llevaba NADA del abono: ni monto, ni
        // enlace, ni el plazo. Todo eso vivía solo en la versión de texto
        // plano, que Gmail no muestra. El tutor leía «Tu visita está confirmada
        // ✓» sin ninguna forma de pagar desde el correo, y la hora se caía a
        // los 15 minutos (pruebas, 14-sep-2026).
        if ($this->abonoPendiente($cita)) {
            $pagoTotal = $this->esPagoTotal($cita);
            $monto = $this->clp((int) $cita->monto_abono_clp);
            $queFalta = $pagoTotal ? 'el pago' : 'el abono';
            $min = SlotsService::MINUTOS_VENTANA_PAGO;

            $pago = "<p style='margin:0 0 10px;font-size:15px;line-height:1.5;color:" . Plantilla::INK . "'>"
                . 'Para confirmar esta hora se requiere '
                . ($pagoTotal
                    ? 'el pago completo de <strong>' . $e($monto) . '</strong>.'
                    : 'un abono de <strong>' . $e($monto) . '</strong> (50% del valor de la consulta).')
                . '</p>'
                . Plantilla::boton($e($this->enlacePago((string) $cita->id)),
                    ($pagoTotal ? 'Pagar ' : 'Pagar abono de ') . $e($monto));

            $aviso = "🛑 Guardamos la hora <strong>{$min} minutos</strong> para que realices {$queFalta}. "
                . 'Pasado ese plazo el enlace sigue sirviendo, pero la hora puede tomarla otra persona.'
                . '<br><br>⏳ Si ya pagaste y por fuerza mayor no puedes asistir, avisa con 24 horas de '
                . 'anticipación: la devolución corresponde al 60% del valor pagado. Con menos de 24 horas '
                . 'de aviso no procede el reembolso.';

            return Plantilla::pagina(
                'Tu hora está reservada',
                'Hola ' . $e($usuario->nombre) . ', falta ' . $queFalta . ' para confirmarla.',
                $pago . Plantilla::tarjeta($cabecera, $filas, $aviso) . Plantilla::bloqueQueEsperar($items),
                'Si necesitas reagendar o cancelar, contáctanos o hazlo desde tu portal de reservas.'
            );
        }

        return Plantilla::pagina(
            'Tu visita está confirmada ✓',
            'Hola ' . $e($usuario->nombre),
            Plantilla::tarjeta($cabecera, $filas) . Plantilla::bloqueQueEsperar($items)
                . Plantilla::bloqueReagendar($this->enlaceReagendar(), true),
            'Si necesitas reagendar o cancelar, contáctanos o hazlo desde tu portal de reservas.'
        );
    }
}
