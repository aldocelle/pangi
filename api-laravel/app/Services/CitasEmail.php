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
        'Llega puntual a tu hora.',
        'Trae el carnet sanitario, el historial médico y las recetas anteriores, si los tienes.',
        'Hay estacionamiento público a pasos de la clínica.',
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
     * «Sábado 25 de julio, 17:30», en español y **sin depender del locale
     * del sistema**.
     *
     * No se usa el formateo localizado de Carbon por lo mismo que Python no usa
     * `strftime("%A")`: el servidor no tiene locale español instalado y la
     * fecha salía medio en inglés — «Monday 24 de august» (§9.7). Las dos
     * listas son la única fuente.
     *
     * La hora va en 24 h, como la escribe la clínica y como sale en WhatsApp
     * (`HorariosOfrecidos::legible`). Era «5:30 PM», herencia del port de
     * Python, y una tutora la leyó en el correo de su reserva (oct-2026).
     *
     * `$mayuscula` en false es para usarla dentro de una frase o de un asunto:
     * «Luna, lunes 26 de octubre». En una fila del detalle va al comienzo y
     * lleva mayúscula.
     */
    public function fechaLegible(string $fechaHora, bool $mayuscula = true): string
    {
        $dt = CarbonImmutable::parse($fechaHora);
        // `dayOfWeekIso` va 1..7 desde el lunes, igual que `weekday()` de
        // Python va 0..6 desde el lunes.
        $dia = self::DIAS[$dt->dayOfWeekIso - 1];
        $mes = self::MESES[$dt->month - 1];
        if ($mayuscula) {
            $dia = mb_convert_case(mb_substr($dia, 0, 1), MB_CASE_UPPER) . mb_substr($dia, 1);
        }

        return "{$dia} {$dt->day} de {$mes}, " . $dt->format('H:i');
    }

    /**
     * Cómo escribirnos, para cerrar un correo con algo que se pueda hacer:
     * «escríbenos por WhatsApp al +56 9 …». Sin número cargado queda en
     * «contáctanos», que es lo que todos los correos decían antes.
     */
    private function comoContactar(bool $html = false): string
    {
        $tel = trim((string) config('emr.clinica_telefono'));
        if ($tel === '') {
            return 'contáctanos';
        }

        return 'escríbenos por WhatsApp al ' . ($html ? '<strong>' . Plantilla::e($tel) . '</strong>' : $tel);
    }

    /**
     * Lo que cierra los correos de una hora que sigue en pie. Con `$html`,
     * lo que se busca de un vistazo va en negrita: dónde y a qué número.
     */
    private function pieCambios(bool $html = false): string
    {
        $misCitas = $html ? '<strong>Mis citas</strong>' : '«Mis citas»';

        return "Si no puedes venir, cambia o cancela la hora desde {$misCitas} en el portal, o "
            . $this->comoContactar($html) . '.';
    }

    /**
     * Las filas de la tarjeta de una cita, iguales en todos los correos al
     * tutor: mascota, servicio, quién atiende, fecha y lugar.
     *
     * El especialista va en su propia fila, debajo del servicio, y no como
     * cabecera de la tarjeta («Atiende Dra. …»): la cabecera es el servicio
     * (pedido de la clínica, oct-2026). La mascota y la fecha van en negrita:
     * son lo que se busca al abrir el correo.
     */
    private function filasCita(object $mascota, object $cita, ?object $especialista,
                               bool $conLugar = true): string
    {
        $e = fn ($v) => Plantilla::e($v);
        $filas = Plantilla::fila('🐾', '<strong>' . $e($mascota->nombre) . '</strong>')
            . Plantilla::fila('🩺', $e($cita->servicio ?: '-'));
        if ($especialista && trim((string) ($especialista->nombre ?? '')) !== '') {
            $filas .= Plantilla::fila('👤', $e($especialista->nombre));
        }
        $filas .= Plantilla::fila(Plantilla::ICONO_FECHA,
            '<strong>' . $e($this->fechaLegible($cita->fecha_hora)) . '</strong>');
        if ($conLugar) {
            $filas .= Plantilla::fila('📍', $e($this->lugar($cita)));
        }

        return $filas;
    }

    /** La cabecera de la tarjeta: el nombre del servicio. */
    private function cabeceraCita(object $cita, string $sinServicio = 'Detalle de tu reserva'): string
    {
        return Plantilla::e($cita->servicio ?: $sinServicio);
    }

    /** La línea del especialista en el texto plano, o nada. */
    private function lineaEspecialista(?object $especialista): string
    {
        $nombre = trim((string) ($especialista->nombre ?? ''));

        return $nombre !== '' ? "  Especialista: {$nombre}\n" : '';
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

        $enlace = $this->enlacePago((string) $cita->id);

        return "\n" . $this->lineaMontoPendiente($cita) . "\n\n"
            . "Paga con tarjeta de crédito o débito en este enlace:\n  {$enlace}\n\n"
            . $this->avisoPlazo() . "\n\n"
            . $this->avisoDevolucion() . "\n";
    }

    /**
     * Lo que falta pagar, en una frase. La comparten el texto plano y el HTML
     * para que no vuelvan a decir cosas distintas (ver `html()`).
     */
    private function lineaMontoPendiente(object $cita, bool $html = false): string
    {
        $monto = $this->clp((int) $cita->monto_abono_clp);
        $monto = $html ? '<strong>' . Plantilla::e($monto) . '</strong>' : $monto;

        return $this->esPagoTotal($cita)
            ? "Para confirmarla, paga el valor completo de la consulta: {$monto}."
            : "Para confirmarla, paga un abono de {$monto} (el 50% de la consulta). El resto se paga en la clínica.";
    }

    private function avisoPlazo(bool $html = false): string
    {
        $min = SlotsService::MINUTOS_VENTANA_PAGO . ' minutos';

        return 'Te guardamos la hora por ' . ($html ? "<strong>{$min}</strong>" : $min) . '. '
            . 'Después el enlace sigue funcionando, pero la hora queda libre y otra persona puede tomarla.';
    }

    private function avisoDevolucion(bool $html = false): string
    {
        $b = fn ($t) => $html ? "<strong>{$t}</strong>" : $t;

        return 'Si pagas y por fuerza mayor no puedes venir, avísanos con al menos ' . $b('24 horas')
            . ' de anticipación y te devolvemos el ' . $b('60% de lo pagado') . '. Con menos de 24 horas '
            . 'de aviso no hay devolución.';
    }

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
     *
     * Recibe la cita y no la fecha ya armada: la fecha va en minúscula dentro
     * del asunto, y así ninguno de los dos correos puede pasarla distinta.
     */
    private function asuntoReserva(object $mascota, object $cita): string
    {
        return 'Reserva confirmada en ' . Marca::nombre() . ": {$mascota->nombre}, "
            . $this->fechaLegible($cita->fecha_hora, false);
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
            $asunto = $this->asuntoReserva($mascota, $cita);

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
                . $this->lineaEspecialista($especialista)
                . "  Fecha: {$fecha}\n"
                . '  Lugar: ' . $this->lugar($cita) . "\n"
                . $lineaRut . $lineaSaldo . $autorizacion
                . "\nGuarda este correo: es tu comprobante de pago.\n\n"
                . $this->pieCambios() . "\n\n"
                . Marca::nombre();

            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $filas = $this->filasCita($mascota, $cita, $especialista);
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
                    . '<strong>Tu hora quedó confirmada.</strong>',
                Plantilla::tarjeta($this->cabeceraCita($cita), $filas),
                '<strong>Guarda este correo: es tu comprobante de pago.</strong> ' . $this->pieCambios(true)
            );

            // El resultado lo usa el aviso de WhatsApp: solo dice «te enviamos
            // el comprobante» si de verdad salió.
            return $this->correo->enviar($usuario->email, $asunto, $texto, $html);
        } catch (\Throwable $ex) {
            Log::error('Error notificando pago recibido (cita ' . $cita->id . '): ' . $ex->getMessage());

            return false;
        }
    }

    /** El correo de confirmación al tutor. */
    public function notificarReservaCreada(
        object $usuario, object $mascota, object $cita, ?object $especialista): void
    {
        try {
            $fecha = $this->fechaLegible($cita->fecha_hora);
            $lugar = $this->lugar($cita);

            $asunto = $this->asuntoReserva($mascota, $cita);

            $queEsperar = '';
            foreach (self::QUE_ESPERAR as $linea) {
                $queEsperar .= "  - {$linea}\n";
            }

            // «Confirmada» solo si no queda nada que pagar en línea. Con abono
            // pendiente decía «Tu visita está confirmada» y 15 minutos después
            // la hora se liberaba: el tutor tenía por escrito una promesa que
            // el sistema no cumplía (visto en pruebas el 14-sep-2026).
            $apertura = $this->abonoPendiente($cita)
                ? 'Reservamos tu hora, pero todavía no está confirmada: falta '
                    . ($this->esPagoTotal($cita) ? 'el pago' : 'el abono') . '.'
                : "Tu hora está confirmada. Te esperamos con {$mascota->nombre}.";

            $texto = "Hola {$usuario->nombre},\n\n"
                . "{$apertura}\n\n"
                . "  Mascota: {$mascota->nombre}\n"
                . '  Servicio: ' . ($cita->servicio ?: '-') . "\n"
                . $this->lineaEspecialista($especialista)
                . "  Fecha: {$fecha}\n"
                . "  Lugar: {$lugar}\n"
                . $this->bloqueAbonoTexto($cita) . "\n"
                . "Para tu visita:\n"
                . $queEsperar
                . "\n" . $this->pieCambios()
                . ($this->abonoPendiente($cita) ? "\n\n" : "\n" . $this->enlaceReagendar() . "\n\n")
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
        $que = $this->esPagoTotal($cita) ? 'Pago' : 'Abono';

        return $cita->payment_status === 'approved'
            ? "{$que} de {$monto} pagado."
            : "{$que} de {$monto} pendiente: si no se paga, la hora se libera.";
    }

    /**
     * Las filas del aviso —(ícono, etiqueta, valor)— en el orden en que se
     * leen. La etiqueta sigue existiendo aunque el HTML muestre el ícono: la
     * versión en texto plano la necesita, y ahí «Paciente: Diana» sí se lee.
     */
    private function datosParaEspecialista(object $usuario, object $mascota, object $cita,
                                           string $etiquetaFecha = 'Fecha'): array
    {
        $contacto = implode(' · ', array_filter(
            [$usuario->nombre ?? null, $usuario->telefono ?? null, $usuario->email ?? null]));

        $filas = [
            ['🐾', 'Paciente', $mascota->especie
                ? "{$mascota->nombre} ({$mascota->especie})" : $mascota->nombre],
            ['🩺', 'Consulta', $cita->servicio ?: '-'],
            [Plantilla::ICONO_FECHA, $etiquetaFecha, $this->fechaLegible($cita->fecha_hora)],
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
        object $mascota, object $cita, string $asunto, string $encabezado, string $frase,
        string $contexto, string $etiquetaFecha = 'Fecha'): void
    {
        $destino = $this->correoEspecialista($especialista);
        if (! $destino) {
            return;
        }

        try {
            $filas = $this->datosParaEspecialista($usuario, $mascota, $cita, $etiquetaFecha);

            $lineas = '';
            foreach ($filas as [, $etiqueta, $valor]) {
                $lineas .= "  {$etiqueta}: {$valor}\n";
            }
            $texto = "Hola {$especialista->nombre},\n\n{$frase}\n\n{$lineas}\n" . Marca::nombre();

            // Escapado por lo mismo que el correo del tutor, y con MÁS
            // superficie: acá viajan el nombre del tutor, el de la mascota, el
            // motivo de consulta y la dirección — todos campos de un formulario
            // público que se llena sin sesión.
            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            // Paciente y fecha en negrita: es lo que se lee primero entre consultas.
            $filasHtml = '';
            foreach ($filas as [$icono, $etiqueta, $valor]) {
                $fuerte = in_array($etiqueta, ['Paciente', 'Fecha', 'Nueva fecha'], true);
                $filasHtml .= Plantilla::fila($icono, $fuerte ? '<strong>' . $e($valor) . '</strong>' : $e($valor));
            }
            $html = Plantilla::pagina(
                $e($encabezado),
                // `lcfirst` y no `mb_lcfirst` (PHP 8.4): el hosting corre 8.3, y
                // las frases de quien llama empiezan con letra sin tilde.
                'Hola ' . $e($especialista->nombre) . ', ' . $e(lcfirst($frase)),
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
        $fecha = $this->fechaLegible($cita->fecha_hora, false);
        $this->avisarEspecialista($especialista, $usuario, $mascota, $cita,
            "Nueva hora en tu agenda: {$mascota->nombre}, {$fecha}",
            'Nueva hora en tu agenda', 'Te agendaron esta hora.', 'reserva creada');
    }

    public function notificarEspecialistaReagendada(?object $especialista,
        object $usuario, object $mascota, object $cita): void
    {
        $fecha = $this->fechaLegible($cita->fecha_hora, false);
        $this->avisarEspecialista($especialista, $usuario, $mascota, $cita,
            "Hora movida: {$mascota->nombre}, ahora el {$fecha}",
            'Una de tus horas cambió', 'Esta hora pasó a una nueva fecha.', 'reagendada', 'Nueva fecha');
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
            $noFuiste = fn (bool $html = false) => 'Si tú no pediste este cambio, '
                . $this->comoContactar($html) . ' y lo revisamos.';

            $texto = "Hola {$usuario->nombre},\n\n"
                . "La cita de {$mascota->nombre} quedó en una nueva hora:\n"
                . '  Servicio: ' . ($cita->servicio ?: '-') . "\n"
                . $this->lineaEspecialista($especialista)
                . "  Nueva fecha: {$fecha}\n"
                . '  Lugar: ' . $this->lugar($cita) . "\n\n"
                . "¿Necesitas cambiarla otra vez? Hazlo desde «Mis citas»:\n" . $this->enlaceReagendar() . "\n\n"
                . $noFuiste() . "\n\n"
                . Marca::nombre();

            $e = fn ($v) => Plantilla::e($v);
            $filas = $this->filasCita($mascota, $cita, $especialista);

            $html = Plantilla::pagina(
                'Tu cita cambió de hora',
                'Hola ' . $e($usuario->nombre) . ', la cita de <strong style="color:' . Plantilla::INK . '">'
                    . $e($mascota->nombre) . '</strong> quedó en una nueva hora.',
                Plantilla::tarjeta($this->cabeceraCita($cita, 'Tu nueva hora'), $filas)
                . Plantilla::bloqueReagendar($this->enlaceReagendar()),
                $noFuiste(true)
            );

            $this->correo->enviar($usuario->email,
                "Nueva hora para {$mascota->nombre}: " . $this->fechaLegible($cita->fecha_hora, false),
                $texto, $html);
        } catch (\Throwable $e) {
            Log::error("Error notificando reagendamiento (cita {$cita->id}): " . $e->getMessage());
        }
    }

    /**
     * Cancelada, Confirmada o Completada — cualquier cambio de estado que no
     * sea la creación inicial ni el reagendamiento.
     *
     * El título cambia con el estado y el ✓ solo va donde algo se cerró bien;
     * una cancelación no lleva ✓. El lugar se omite al cancelar y al
     * completar: a quien ya no va, o ya fue, no le sirve saber adónde.
     *
     * Cada estado dice lo suyo y deja UN paso siguiente: la confirmada,
     * cambiar la hora; la cancelada, reservar otra (una hora cancelada pierde
     * al paciente si el correo no le ofrece volver).
     */
    public function notificarCambioEstado(object $usuario, object $mascota, object $cita): void
    {
        try {
            $fecha = $this->fechaLegible($cita->fecha_hora);
            $enFrase = $this->fechaLegible($cita->fecha_hora, false);
            $nombre = $mascota->nombre;
            $contacto = $this->comoContactar();
            $contactoHtml = $this->comoContactar(true);
            $portal = rtrim((string) config('emr.portal_reservas_url'), '/');

            // [asunto, título, frase del saludo, cierre, botón [subtítulo, texto, enlace] o null].
            // La frase lleva `{mascota}` y no el nombre: en el HTML va resaltado.
            $copia = match ($cita->estado) {
                'Cancelada' => ["Cita cancelada: {$nombre}, {$enFrase}", 'Tu cita fue cancelada',
                    'la cita de {mascota} quedó cancelada.',
                    "Si tú no pediste la cancelación, {$contacto}.",
                    ['¿Quieres otra hora?', 'Reservar otra hora', $portal]],
                'Confirmada' => ["Cita confirmada: {$nombre}, {$enFrase}", 'Tu cita fue confirmada ✓',
                    'confirmamos la cita de {mascota}. Te esperamos.',
                    $this->pieCambios(),
                    ['¿Necesitas cambiar la hora?', 'Reagendar mi cita', $this->enlaceReagendar()]],
                'Completada' => ["Gracias por venir con {$nombre}", 'Atención realizada ✓',
                    'gracias por confiarnos a {mascota}.',
                    "Si te queda alguna duda sobre las indicaciones, {$contacto}.", null],
                'Agendada' => ["Cita activa de nuevo: {$nombre}, {$enFrase}", 'Tu cita está activa de nuevo',
                    'la cita de {mascota} vuelve a estar agendada.',
                    $this->pieCambios(),
                    ['¿Necesitas cambiar la hora?', 'Reagendar mi cita', $this->enlaceReagendar()]],
                default => ["Tu cita cambió de estado: {$nombre}, {$enFrase}", 'Tu cita cambió de estado',
                    'la cita de {mascota} quedó ' . mb_strtolower((string) $cita->estado) . '.',
                    "Si tienes dudas, {$contacto}.", null],
            };
            [$asunto, $titulo, $frase, $cierre, $accion] = $copia;
            $conLugar = ! in_array($cita->estado, ['Cancelada', 'Completada'], true);

            $texto = "Hola {$usuario->nombre},\n\n" . ucfirst(str_replace('{mascota}', $nombre, $frase)) . "\n\n"
                . '  Servicio: ' . ($cita->servicio ?: '-') . "\n"
                . "  Fecha: {$fecha}\n"
                . ($conLugar ? '  Lugar: ' . $this->lugar($cita) . "\n" : '') . "\n"
                . ($accion ? "{$accion[0]}\n{$accion[2]}\n\n" : '')
                . "{$cierre}\n\n"
                . Marca::nombre();

            $e = fn ($v) => Plantilla::e($v);
            $filas = $this->filasCita($mascota, $cita, null, $conLugar);
            $boton = $accion
                ? Plantilla::subtitulo($e($accion[0])) . Plantilla::boton($e($accion[2]), $e($accion[1]))
                : '';

            // El nombre de la mascota va resaltado, como en los demás correos.
            $fraseHtml = str_replace('{mascota}',
                '<strong style="color:' . Plantilla::INK . '">' . $e($nombre) . '</strong>', $e($frase));

            $html = Plantilla::pagina(
                $titulo,
                'Hola ' . $e($usuario->nombre) . ', ' . $fraseHtml,
                Plantilla::tarjeta($this->cabeceraCita($cita, 'Detalle de la cita'), $filas) . $boton,
                // El cierre en HTML: el número de WhatsApp y «Mis citas» en negrita.
                str_replace([$e($contacto), '«Mis citas»'], [$contactoHtml, '<strong>Mis citas</strong>'], $e($cierre))
            );

            $this->correo->enviar($usuario->email, $asunto, $texto, $html);
        } catch (\Throwable $e) {
            Log::error("Error notificando cambio de estado (cita {$cita->id}): " . $e->getMessage());
        }
    }

    public function notificarEspecialistaCancelada(?object $especialista,
        object $usuario, object $mascota, object $cita): void
    {
        $fecha = $this->fechaLegible($cita->fecha_hora, false);
        $this->avisarEspecialista($especialista, $usuario, $mascota, $cita,
            "Hora cancelada: {$mascota->nombre}, {$fecha}",
            'Se canceló una hora', 'Esta hora se canceló y quedó libre en tu agenda.', 'cancelada');
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
        $cabecera = $this->cabeceraCita($cita);

        // El detalle va como filas de ícono + texto y NO como tabla
        // etiqueta/valor: en esa tabla el nombre de la mascota salía pegado a
        // su etiqueta —«MascotaDiana»— en los clientes que colapsan celdas.
        $filas = $this->filasCita($mascota, $cita, $especialista);

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

            $pago = Plantilla::parrafo($this->lineaMontoPendiente($cita, true))
                . Plantilla::boton($e($this->enlacePago((string) $cita->id)),
                    ($pagoTotal ? 'Pagar ' : 'Pagar abono de ') . $e($monto));

            $aviso = $this->avisoPlazo(true) . '<br><br>' . $this->avisoDevolucion(true);

            return Plantilla::pagina(
                'Tu hora está reservada',
                'Hola ' . $e($usuario->nombre) . ', falta <strong style="color:' . Plantilla::INK . '">'
                    . $queFalta . '</strong> para confirmarla.',
                $pago . Plantilla::tarjeta($cabecera, $filas, $aviso) . Plantilla::bloqueQueEsperar($items),
                $this->pieCambios(true)
            );
        }

        return Plantilla::pagina(
            'Tu hora está confirmada ✓',
            'Hola ' . $e($usuario->nombre) . ', te esperamos con <strong style="color:' . Plantilla::INK . '">'
                . $e($mascota->nombre) . '</strong>.',
            Plantilla::tarjeta($cabecera, $filas) . Plantilla::bloqueQueEsperar($items)
                . Plantilla::bloqueReagendar($this->enlaceReagendar(), true),
            $this->pieCambios(true)
        );
    }
}
