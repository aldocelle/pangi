<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Getnet;
use App\Services\Rut;
use App\Services\LinkDePago;
use App\Services\SlotsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use App\Services\CitasEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\LimitePorIP;

/**
 * Cobro del abono con Getnet Web Checkout.
 *
 * **Todo este router es PÚBLICO**, y tiene que serlo: quien reserva sin cuenta
 * no tiene token, y el `cita_id` es un UUID v4 que solo conoce quien acaba de
 * reservar. Aun así, lo que se expone va recortado a propósito —el correo sale
 * enmascarado, no hay teléfono ni dirección— porque un id filtrado no debe
 * convertirse en la ficha de un cliente.
 *
 * El webhook no depende de eso: valida firma.
 */
class PagosController extends Controller
{
    /** La reserva que venció su ventana de pago, sin bloquear el cupo. */
    private const ESTADO_EXPIRADA = 'Expirada';

    public function __construct(private Getnet $getnet)
    {
    }

    /** GET /pagos/config — para que el frontend sepa si puede ofrecer pago en línea. */
    public function config()
    {
        return [
            'habilitado' => $this->getnet->habilitado(),
            'simulado' => $this->getnet->simulado(),
            'proveedor' => 'getnet',
        ];
    }

    /**
     * Tope de la consulta de estado. Holgado —`PagoView` reintenta sola al
     * volver del checkout— pero no puede no existir: cada llamada con un pago
     * sin resolver dispara una consulta SALIENTE a Getnet
     * (`refrescarDesdeGetnet`), así que sin tope este endpoint público es un
     * amplificador contra la pasarela, con el `cita_id` de cualquier correo
     * reenviado como única entrada.
     */
    private const ESTADO_MAX_POR_IP = 60;
    private const ESTADO_VENTANA_SEG = 300;

    /** GET /pagos/cita/{id} — el estado del cobro de esa reserva. */
    public function estado(Request $request, string $citaId)
    {
        if (LimitePorIP::demasiados('pago-estado:' . $request->ip(),
                self::ESTADO_MAX_POR_IP, self::ESTADO_VENTANA_SEG)) {
            return response()->json([
                'detail' => 'Demasiadas consultas seguidas. Espera un momento.',
            ], 429);
        }

        $cita = DB::table('citas')->where('id', $citaId)->first();
        if (! $cita) {
            return response()->json(['detail' => 'No encontramos esa reserva.'], 404);
        }

        $this->refrescarDesdeGetnet($cita);

        return $this->resumen(DB::table('citas')->where('id', $citaId)->first());
    }

    /**
     * POST /pagos/cita/{id}/sesion — abre el checkout.
     *
     * Falla CERRADO: si no se pudo crear la sesión, el error llega al cliente.
     * Un fallo silencioso dejaría una hora tomada sin cobrar.
     */
    public function crearSesion(Request $request, string $citaId)
    {
        $cita = DB::table('citas')->where('id', $citaId)->first();
        if (! $cita) {
            return response()->json(['detail' => 'No encontramos esa reserva.'], 404);
        }
        // El ORDEN de las guardas es contrato, y los códigos también:
        // «cancelada» y «no requiere pago» son **400** (la petición no tiene
        // sentido), mientras que «ya está pagada» es **409** — un conflicto con
        // el estado actual— y va DESPUÉS de calcular el monto.
        if ($cita->estado === 'Cancelada') {
            return response()->json(['detail' => 'Esta reserva está cancelada.'], 400);
        }
        if (! $cita->monto_abono_clp) {
            return response()->json(['detail' => 'Esta reserva no requiere pago en línea.'], 400);
        }

        $modalidad = (string) $request->input('modalidad', 'abono');
        $monto = $this->montoDe($cita, $modalidad);
        if (! $monto) {
            return response()->json(['detail' => 'No pudimos calcular el monto a cobrar.'], 400);
        }

        // RUT del que paga (decisión de producto, 06-oct): opcional, pero si se
        // escribe tiene que ser válido — un RUT con el dígito equivocado en el
        // comprobante es peor que ninguno.
        $rutEscrito = trim((string) $request->input('rut', ''));
        $rut = $rutEscrito !== '' ? Rut::normalizar($rutEscrito) : null;
        if ($rutEscrito !== '' && ! $rut) {
            return response()->json(['detail' => 'El RUT no es válido. Revisa el número y el dígito '
                . 'verificador, o déjalo en blanco.'], 422);
        }

        // Se pregunta a Getnet ANTES de decidir, no se cree la columna local.
        //
        // Quien ya pagó pero cuyo webhook todavía no llegó tiene
        // `payment_status = 'pending'` guardado, y sin este refresco se le
        // abriría una SEGUNDA sesión de cobro: pagaría dos veces la misma
        // hora. `refrescarDesdeGetnet()` actualiza `$cita` en el sitio, así
        // que la guarda de abajo ya mira el estado real.
        //
        // Falla abierto a propósito (lo dice su propio cuerpo): si Getnet no
        // contesta, se sigue con el último estado conocido en vez de dejar al
        // cliente sin poder pagar.
        $this->refrescarDesdeGetnet($cita);

        if ($cita->payment_status === 'approved') {
            return response()->json(['detail' => 'Esta reserva ya está pagada.'], 409);
        }

        // Pasado el plazo, la hora ya se le está ofreciendo a otras personas:
        // abrir un cobro ahora sería cobrar una hora que quizás ya no es suya.
        // Va DESPUÉS de refrescar contra Getnet a propósito: quien abrió el
        // pago a tiempo y lo terminó un poco después aparece como pagado
        // arriba (y `guardarEstado` revive la hora si sigue libre). 410 y no
        // 400: la reserva existió, pero ya no está disponible.
        if (LinkDePago::horaLiberada($cita)) {
            return response()->json(['detail' => 'Pasaron los ' . SlotsService::MINUTOS_VENTANA_PAGO
                . ' minutos para pagar y la hora quedó libre. Puedes reservar otra.'], 410);
        }

        // Dónde queda el RUT escrito. Esta ruta es PÚBLICA (basta el id de la
        // cita), así que no puede reescribir el RUT de una cuenta que ya lo
        // tiene: solo lo llena si estaba vacío o roto. Siempre queda en la
        // cita, que es donde lo mira la clínica para el comprobante.
        $dueno = DB::table('usuarios')->where('id', $cita->usuario_dueno_id)->first();
        $rutGuardado = Rut::normalizar($dueno->rut ?? null);
        if ($rut) {
            $resp = $cita->respuestas ? (json_decode($cita->respuestas, true) ?: []) : [];
            $resp['RUT del pago'] = Rut::formatear($rut);
            DB::table('citas')->where('id', $citaId)->update(['respuestas' => json_encode($resp, JSON_UNESCAPED_UNICODE)]);
            $cita->respuestas = json_encode($resp, JSON_UNESCAPED_UNICODE);
            if ($dueno && ! $rutGuardado) {
                DB::table('usuarios')->where('id', $dueno->id)->update(['rut' => Rut::formatear($rut)]);
            }
        }
        $documento = $rut ?? $rutGuardado;

        if (! $this->getnet->habilitado()) {
            // Sin credenciales: en desarrollo se SIMULA para poder recorrer el
            // flujo; en producción se responde 503 con una salida —no se deja
            // el cobro apagado en silencio.
            if ($this->getnet->simulado()) {
                DB::table('citas')->where('id', $citaId)->update([
                    'pago_modalidad' => $modalidad,
                    'payment_status' => 'pending',
                    'pago_actualizado_at' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
                ]);

                return [
                    'process_url' => rtrim(config('emr.portal_reservas_url'), '/')
                        . "/pago/{$citaId}?simulado=1",
                    'request_id' => 0,
                    'monto_clp' => $monto,
                    'modalidad' => $modalidad,
                ];
            }

            return response()->json([
                'detail' => 'El pago en línea no está disponible en este momento. '
                    . 'Escríbenos por WhatsApp y coordinamos el abono.',
            ], 503);
        }

        $retorno = rtrim(config('emr.portal_reservas_url'), '/') . "/pago/{$citaId}";

        // El COMPRADOR va con los datos que el tutor ya escribió en el paso 4
        // del formulario: si no, llega al checkout de Getnet con el formulario
        // en blanco y tiene que escribirlos otra vez. Se mandan solo los que
        // existen —y si no hay ninguno, `buyer` no viaja— porque un campo
        // vacío no es un dato, y Getnet valida el formato de los que recibe.
        $mascota = DB::table('mascotas')->where('id', $cita->mascota_id)->first();
        $comprador = array_filter([
            'name' => $dueno->nombre ?? null,
            'email' => $dueno->email ?? null,
            'mobile' => $dueno->telefono ?? null,
            // `CLRUT`, sin puntos: así lo guarda Getnet (probado contra el
            // sandbox el 06-oct; el sandbox no valida el documento).
            'documentType' => $documento ? 'CLRUT' : null,
            'document' => $documento,
        ], fn ($v) => (string) $v !== '');

        // La DESCRIPCIÓN es lo que la clínica lee en el panel de Getnet para
        // cuadrar un cobro con una cita, así que lleva la modalidad, la
        // consulta y el paciente. «Abono consulta» a secas no distingue dos
        // cobros del mismo día.
        //
        // Pasa por el saneador porque se arma con texto escrito por una
        // persona (el nombre de la consulta) y con el nombre de la mascota:
        // una raya larga ahí devuelve un 400 que no dice cuál fue el carácter,
        // y tumbaría todos los cobros de esa consulta.
        $descripcion = ($modalidad === 'abono' ? 'Abono 50%' : 'Pago total')
            . ' - ' . ($cita->servicio ?: 'Consulta')
            . ($mascota ? ' - ' . $mascota->nombre : '');

        /*
         * LA REFERENCIA NO ES EL ID DE LA CITA, aunque salga de él.
         *
         * Python manda `RES-` + los 12 primeros hex del UUID en mayúsculas
         * —18 caracteres—; acá se mandaba el UUID entero, 36 con guiones, y
         * **Getnet lo rechaza**:
         *
         *   400 {"status":"FAILED","reason":"request_not_valid",
         *        "message":"La información del pago es incorrecta (reference)"}
         *
         * O sea que el cobro entero estaba muerto en el port: cada intento
         * moría en 502 y el cliente leía «No pudimos abrir el pago». Se
         * descubrió el 04-sep-2026 apretando el botón en el navegador.
         *
         * Y como también se GUARDA en `pago_referencia`, la diferencia rompía
         * de paso el rescate por referencia del webhook: un aviso de una sesión
         * anterior se busca por ese valor, y los dos lados guardaban distinto.
         *
         * Se reusa entre reintentos a propósito —igual que Python, con el
         * `?:`—: un rechazo y su reintento son el MISMO cobro, no dos.
         */
        $referencia = $cita->pago_referencia
            ?: 'RES-' . strtoupper(substr(str_replace('-', '', (string) $citaId), 0, 12));

        $cuerpo = [
            'locale' => 'es_CL',
            'payment' => [
                'reference' => $referencia,
                'description' => $this->getnet->limpiarDescripcion($descripcion),
                'amount' => ['currency' => 'CLP', 'total' => $monto],
            ],
            'expiration' => CarbonImmutable::now('UTC')->addHours(48)
                ->format('Y-m-d\TH:i:sP'),
            'returnUrl' => $retorno,
            'ipAddress' => $request->ip(),
            'userAgent' => (string) $request->userAgent(),
            ...($comprador ? ['buyer' => $comprador] : []),
        ];
        try {
            try {
                $r = $this->getnet->post('api/session', $cuerpo);
            } catch (\RuntimeException $e) {
                // Un RUT nunca puede bloquear un pago: si Getnet rechaza la
                // sesión y llevaba documento, se reintenta sin él. El sandbox
                // acepta cualquier formato; producción puede validar.
                if (empty($cuerpo['buyer']['document'])) {
                    throw $e;
                }
                Log::warning("Getnet rechazó la sesión de la cita {$citaId} con RUT; se reintenta sin RUT");
                unset($cuerpo['buyer']['document'], $cuerpo['buyer']['documentType']);
                $r = $this->getnet->post('api/session', $cuerpo);
            }
        } catch (\Throwable $e) {
            Log::error("Getnet: no se pudo crear la sesión de la cita {$citaId}: " . $e->getMessage());

            return response()->json([
                'detail' => 'No pudimos abrir el pago. Intenta de nuevo en unos minutos.',
            ], 502);
        }

        DB::table('citas')->where('id', $citaId)->update([
            'pago_request_id' => $r['requestId'] ?? null,
            'pago_process_url' => $r['processUrl'] ?? null,
            // La referencia se REUSA entre reintentos a propósito: es lo que
            // permite reconciliar el aviso de una sesión anterior.
            'pago_referencia' => $referencia,
            'pago_modalidad' => $modalidad,
            'payment_status' => 'pending',
            'pago_actualizado_at' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
        ]);

        return [
            'process_url' => $r['processUrl'] ?? null,
            'request_id' => $r['requestId'] ?? null,
            'monto_clp' => $monto,
            'modalidad' => $modalidad,
        ];
    }

    /**
     * POST /pagos/notificacion — el webhook de Getnet.
     *
     * Resuelve el caso de quien paga y **nunca vuelve al sitio**, así que hay
     * que registrar esta URL en el panel de comercio: no se manda por request.
     *
     * Se valida la firma `SHA-1(requestId + status + date + secretKey)`. SHA-1
     * lo impone el protocolo, no es elección nuestra.
     */
    public function notificacion(Request $request)
    {
        $datos = $request->all();
        $requestId = (string) ($datos['requestId'] ?? '');

        // **200 incluso cuando se ignora el aviso.** Getnet reintenta ante
        // cualquier otro código, y reintentar no arregla una firma inválida ni
        // un requestId que no es nuestro: solo genera ruido para siempre.
        if (! $this->getnet->firmaWebhookValida($datos)) {
            Log::warning("Getnet: notificación con firma inválida (requestId {$requestId})");

            return ['recibido' => false, 'motivo' => 'firma inválida'];
        }

        $cita = DB::table('citas')->where('pago_request_id', $requestId)->first();

        if (! $cita && ! empty($datos['reference'])) {
            // **Rescate por referencia**: es el aviso de una sesión ANTERIOR de
            // esta misma cita. Cada intento de pago abre una sesión nueva
            // (misma `reference`, `requestId` distinto) y la cita solo guarda
            // el último, pero la sesión vieja sigue siendo pagable — basta que
            // el cliente vuelva atrás en el navegador.
            //
            // Sin esto el aviso se descartaba como «sesión desconocida»: el
            // cobro se hacía, la hora nunca se confirmaba y la plata quedaba
            // sin conciliar.
            //
            // Confiar en la referencia es seguro porque la FIRMA ya se validó
            // arriba con el secretKey: nadie de afuera puede inventar un aviso
            // para una referencia ajena.
            $cita = DB::table('citas')->where('pago_referencia', $datos['reference'])->first();
            if ($cita) {
                Log::info("Getnet: aviso de una sesión anterior ({$requestId}) reconciliado "
                    . "por referencia {$datos['reference']} (cita {$cita->id})");
            }
        }

        if (! $cita) {
            Log::warning("Getnet: notificación de un requestId desconocido ({$requestId})");

            return ['recibido' => false, 'motivo' => 'sesión desconocida'];
        }

        $estado = $this->traducirEstado($datos['status']['status'] ?? '');
        $this->guardarEstado($cita, $estado, $datos['status']['reason'] ?? null, null);

        // Solo `recibido`, sin el estado: es lo que devuelve Python, y quien
        // lee esta respuesta es GETNET, no una pantalla. Devolverle de más el
        // estado interno de la cita no le sirve para nada y convierte un
        // detalle nuestro en parte del contrato con un tercero.
        return ['recibido' => true];
    }

    // ── internos ──────────────────────────────────────────────────────────

    /**
     * **Un pago aprobado NUNCA se degrada.**
     *
     * Getnet puede notificar dos veces, y la vuelta del navegador puede llegar
     * después del webhook: sin este candado, una consulta rezagada que todavía
     * dice PENDING borraría un `approved` ya escrito.
     */
    private function guardarEstado(object $cita, string $estado, ?string $autorizacion,
                                   ?int $montoPagado): void
    {
        if ($cita->payment_status === 'approved') {
            return;
        }

        $set = [
            'payment_status' => $estado,
            'pago_actualizado_at' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
        ];
        if ($autorizacion) {
            $set['pago_autorizacion'] = mb_substr($autorizacion, 0, 60);
        }

        if ($estado === 'approved'
            && in_array($cita->estado, ['Agendada', self::ESTADO_EXPIRADA], true)) {
            // El abono es lo que hace firme la hora: eso es exactamente lo que
            // significa «Confirmada» en el panel.
            //
            // «Expirada» entra acá a propósito: la ventana suelta el cupo a los
            // 15 minutos, pero el pago puede llegar después —el webhook se
            // demora, o la persona pagó justo pasado el plazo— y en ese caso ya
            // cobramos. Dejarla expirada sería quedarse con la plata Y con la
            // hora liberada.
            //
            // Salvo que otro se haya llevado el cupo en el intervalo, que es el
            // único caso en que revivirla crearía una doble reserva: ahí queda
            // expirada y el pago visible en el panel, para que una PERSONA
            // decida si reubica o devuelve.
            if ($cita->estado === self::ESTADO_EXPIRADA && $this->cupoTomadoPorOtro($cita)) {
                Log::warning("Pago aprobado sobre la cita expirada {$cita->id} cuyo cupo "
                    . 'ya fue tomado; queda para revisión manual');
            } else {
                $set['estado'] = 'Confirmada';
            }
        }

        $revive = $cita->estado === self::ESTADO_EXPIRADA && ($set['estado'] ?? null) === 'Confirmada';

        DB::table('citas')->where('id', $cita->id)->update($set);

        // Y en el objeto que tiene el llamador, no solo en la base.
        //
        // Sin esto, `crearSesion()` refrescaba el estado contra Getnet, escribía
        // «approved» en la fila… y a continuación leía `$cita->payment_status`
        // de la copia en memoria, que seguía diciendo «pending»: le abría una
        // SEGUNDA sesión de cobro a alguien que ya había pagado. Es el bug que
        // el modo C destapó — Python respondía 409 y Laravel 200.
        //
        // Se hace acá, en el único sitio que cambia el estado, y no en cada
        // llamador: uno que se olvide vuelve a abrir el mismo agujero.
        foreach ($set as $columna => $valor) {
            $cita->$columna = $valor;
        }

        // Al expirar, el barrido le borró el evento de Google; si el pago tardío
        // la revive, la hora vuelve a la agenda del especialista. Nunca lanza.
        if ($revive && ! $cita->google_event_id) {
            $dueno = DB::table('usuarios')->where('id', $cita->usuario_dueno_id)->first();
            $mascota = DB::table('mascotas')->where('id', $cita->mascota_id)->first();
            if ($dueno && $mascota) {
                app(\App\Services\GoogleCalendar::class)->crearEventoDeCita($cita, $dueno, $mascota,
                    $cita->especialista_id
                        ? DB::table('especialistas')->where('id', $cita->especialista_id)->first()
                        : null);
            }
        }

        // El comprobante al cliente. Sale acá y no en cada llamador porque el
        // `return` de más arriba garantiza que esto solo se alcanza en la
        // TRANSICIÓN a approved: Getnet avisa dos veces —webhook y vuelta del
        // navegador— y la segunda ya no entra.
        if ($estado === 'approved') {
            $dueno = DB::table('usuarios')->where('id', $cita->usuario_dueno_id)->first();
            $mascota = DB::table('mascotas')->where('id', $cita->mascota_id)->first();
            $especialista = $cita->especialista_id
                ? DB::table('especialistas')->where('id', $cita->especialista_id)->first()
                : null;
            // Lo cobrado sale de la misma regla que armó el cobro, y lo dicen
            // igual el correo y el WhatsApp.
            $pagado = $this->montoDe($cita, $cita->pago_modalidad ?: 'abono');
            $correoEnviado = $dueno && $mascota
                && app(CitasEmail::class)->notificarPagoRecibido($dueno, $mascota, $cita, $especialista, $pagado);
            // Y en el chat, si la hora se reservó por WhatsApp.
            app(\App\Services\AvisoPagoWhatsapp::class)->avisar($cita, $mascota, $pagado,
                $correoEnviado ? $dueno->email : null);
        }
    }

    /** ¿Otra cita ocupó ese mismo hueco mientras esta estaba expirada? */
    private function cupoTomadoPorOtro(object $cita): bool
    {
        return DB::table('citas')
            ->where('id', '!=', $cita->id)
            ->where('especialista_id', $cita->especialista_id)
            ->where('fecha_hora', $cita->fecha_hora)
            ->whereIn('estado', ['Agendada', 'Confirmada'])
            ->exists();
    }

    /**
     * Consulta el estado real contra Getnet. Falla en silencio: es una lectura.
     *
     * Pública porque la usa también el asistente (AgenteIA) cuando el tutor
     * escribe «pagado»: sin esto contestaba desde la base, que sin webhook ni
     * vuelta del navegador puede seguir diciendo «pending» con el pago hecho.
     */
    public function refrescarDesdeGetnet(object $cita): void
    {
        if (! $this->getnet->habilitado() || ! $cita->pago_request_id
            || $cita->payment_status === 'approved') {
            return;
        }

        try {
            $r = $this->getnet->post("api/session/{$cita->pago_request_id}");
            // Una TRANSACCIÓN aprobada manda sobre el estado de la sesión:
            // Getnet cierra la sesión con algo de retraso, así que mirar solo
            // `status.status` dejaría el pago como pendiente un rato largo.
            $aprobada = null;
            foreach ($r['payment'] ?? [] as $p) {
                if (($p['status']['status'] ?? '') === 'APPROVED') {
                    $aprobada = $p;
                    break;
                }
            }
            if ($aprobada) {
                $this->guardarEstado($cita, 'approved',
                    $aprobada['authorization'] ?? null,
                    isset($aprobada['amount']['total']) ? (int) $aprobada['amount']['total'] : null);

                return;
            }
            $this->guardarEstado($cita,
                $this->traducirEstado($r['status']['status'] ?? ''), null, null);
        } catch (\Throwable $e) {
            Log::info("Getnet: no se pudo refrescar el estado de {$cita->id}: " . $e->getMessage());
        }
    }

    private function traducirEstado(string $getnet): string
    {
        return match ($getnet) {
            'APPROVED' => 'approved',
            'REJECTED', 'FAILED' => 'rejected',
            default => 'pending',
        };
    }

    /**
     * Valor completo de la cita. **`null` = NO SE CONOCE**, no cero.
     *
     * Acá vivía un fallback que reconstruía el total como `monto_abono * 2`.
     * Se sacó porque con los abonos fijos (Radiología, Peluquería) un total en
     * NULL ya no significa «cita vieja» sino «este valor no se conoce todavía»:
     * duplicar el abono inventaba un total de $54.000 para una radiografía — y
     * esta ruta es PÚBLICA y acepta `modalidad: "total"`, así que ese número
     * inventado era cobrable de verdad.
     */
    private function montoTotal(object $cita): ?int
    {
        return $cita->monto_total_clp === null ? null : (int) $cita->monto_total_clp;
    }

    /**
     * La columna solo la escribe el autoservicio. Una hora del mostrador con
     * enlace generado después (LinkDePago) la tiene en NULL, y leerla como
     * 'abono' dejaría pagar la mitad de una Telemedicina que se paga entera:
     * ahí manda la regla del servicio.
     */
    private function modalidadRequerida(object $cita): string
    {
        if ($cita->pago_modalidad_requerida) {
            return $cita->pago_modalidad_requerida;
        }
        $svc = $cita->servicio_id
            ? DB::table('servicios')->where('id', $cita->servicio_id)->first() : null;

        return ($svc->modalidad_pago ?? null) === 'total' ? 'total' : 'abono';
    }

    /**
     * Cuánto se cobra.
     *
     * La elección del cliente solo manda cuando el servicio se lo permite: en
     * un servicio «total» se cobra el total aunque el request pida abono. El
     * portal ni siquiera muestra la opción, pero **esta ruta es pública y no
     * puede apoyarse en eso**.
     */
    private function montoDe(object $cita, string $modalidad): ?int
    {
        if ($this->montoTotal($cita) === null) {
            // Sin total conocido solo se puede cobrar el abono, venga lo que
            // venga en el request.
            return $cita->monto_abono_clp === null ? null : (int) $cita->monto_abono_clp;
        }
        if ($this->modalidadRequerida($cita) === 'total') {
            return $this->montoTotal($cita);
        }

        return $modalidad === 'total'
            ? $this->montoTotal($cita)
            : (int) $cita->monto_abono_clp;
    }

    /** El correo con casi todo tapado: `a•••••@dominio.cl`. */
    private function enmascararCorreo(?string $correo): string
    {
        if (! $correo || ! str_contains($correo, '@')) {
            return '';
        }
        [$usuario, $dominio] = explode('@', $correo, 2);
        $visible = $usuario === '' ? '' : mb_substr($usuario, 0, 1);
        // Mínimo tres puntos: con un usuario de dos letras, un solo punto
        // revelaría el largo real.
        $puntos = str_repeat('•', max(mb_strlen($usuario) - 1, 3));

        return "{$visible}{$puntos}@{$dominio}";
    }

    private function resumen(object $cita): array
    {
        $mascota = DB::table('mascotas')->where('id', $cita->mascota_id)->first();
        $dueno = DB::table('usuarios')->where('id', $cita->usuario_dueno_id)->first();
        $esp = $cita->especialista_id
            ? DB::table('especialistas')->where('id', $cita->especialista_id)->first() : null;
        $svc = $cita->servicio_id
            ? DB::table('servicios')->where('id', $cita->servicio_id)->first() : null;

        $total = $this->montoTotal($cita);
        $pagado = $cita->payment_status === 'approved';
        $abono = $cita->monto_abono_clp === null ? null : (int) $cita->monto_abono_clp;

        // Lo que queda por cobrar el día de la atención. **`null` y no 0**
        // cuando el total no se conoce: en una radiografía SÍ queda saldo, solo
        // que se calcula en la clínica según cuántas placas salgan. Un 0 le
        // diría al mostrador que no hay nada que cobrar, que es el error caro.
        if ($total === null) {
            $saldo = null;
        } elseif ($pagado) {
            $saldo = $cita->pago_modalidad === 'total' ? 0 : $total - ($abono ?? 0);
        } else {
            $saldo = $total;
        }

        $respuestas = $cita->respuestas ? (json_decode($cita->respuestas, true) ?: []) : [];

        return [
            'cita_id' => $cita->id,
            'estado' => $cita->estado,
            'fecha_hora' => $this->iso($cita->fecha_hora),
            'servicio' => $cita->servicio,
            'especialista' => $esp->nombre ?? null,
            'mascota' => $mascota->nombre ?? null,
            'correo' => $this->enmascararCorreo($dueno->email ?? null),
            // Enmascarado: la ruta es pública. Sirve para que la persona sepa
            // que ya lo tenemos y pueda dejar el campo en blanco.
            'rut_registrado' => ($rutN = Rut::normalizar($dueno->rut ?? null)) ? Rut::enmascarar($rutN) : null,
            // `monto_clp` se mantiene igual al abono por COMPATIBILIDAD: es lo
            // que leen las versiones ya publicadas del portal.
            'monto_clp' => $abono,
            'monto_abono_clp' => $abono,
            'monto_total_clp' => $total,
            'modalidad' => $cita->pago_modalidad,
            // Qué puede elegir quien todavía no paga. Va en el resumen porque
            // PagoView se abre días después desde el correo y no conoce el
            // servicio, solo la cita.
            'modalidad_requerida' => $this->modalidadRequerida($cita),
            'monto_pagado_clp' => $pagado
                ? $this->montoDe($cita, $cita->pago_modalidad ?: 'abono') : null,
            'saldo_clp' => $saldo,
            'estado_pago' => $cita->payment_status,
            'autorizacion' => $cita->pago_autorizacion,
            'requiere_pago' => (bool) $abono && ! $pagado,
            // Quien paga primero sale del sitio antes de ver la pantalla que
            // ofrece adjuntar la orden, y vuelve acá: sin estos dos campos,
            // PagoView no tiene cómo saber que hace falta. No filtran nada.
            'requiere_orden_examen' => (bool) ($svc->requiere_orden_examen ?? false),
            'orden_adjunta' => ! empty($respuestas['orden_examen_documento_id']),
            'cancelada' => $cita->estado === 'Cancelada',
            // Si corre el plazo de 15 minutos. Una hora coordinada por la
            // clínica no lo tiene, y PagoView no debe anunciarlo.
            'ventana_pago' => ! LinkDePago::sinVentana($cita),
            // La hora ya no es suya (plazo vencido o «Expirada»): la página
            // deja de ofrecer el pago. Y hasta cuándo puede pagar, en UTC con
            // «Z», para la cuenta regresiva; null si no corre plazo.
            'hora_liberada' => LinkDePago::horaLiberada($cita),
            'pagar_hasta' => LinkDePago::pagarHasta($cita),
        ];
    }

    private function iso(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = str_replace(' ', 'T', substr($v, 0, 26));

        return str_ends_with($t, '.000000') ? substr($t, 0, 19) : $t;
    }
}
