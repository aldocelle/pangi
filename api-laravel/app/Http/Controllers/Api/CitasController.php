<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LimitePorIP;
use App\Services\CobroService;
use App\Services\LinkDePago;
use App\Services\OrdenExamen;
use App\Services\SlotsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use App\Services\Auditoria;
use App\Services\CitasEmail;
use App\Services\GoogleCalendar;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Permisos;
use App\Services\Fechas;

class CitasController extends Controller
{
    /** ~3 meses. Tope de anticipación con que se puede reservar. */
    private const MAX_DIAS_ANTICIPACION = 92;

    /** Tope por IP de la subida de órdenes de examen: es pública. */
    private const ORDEN_MAX_POR_IP = 12;

    private const ORDEN_VENTANA_SEG = 900; // 15 min

    /** Tope del rango de la agenda: la vista de mes pide ~31 días. */
    private const AGENDA_MAX_DIAS = 62;

    /**
     * La reserva que venció su ventana de pago. NO bloquea el cupo, pero sigue
     * visible en el panel: una hora que se perdió por no pagar es información,
     * y borrarla dejaría al tutor sin rastro de lo que intentó.
     */
    private const ESTADO_EXPIRADA = 'Expirada';

    public function __construct(
        private Auditoria $auditoria,
        private CitasEmail $emails,
        private GoogleCalendar $google,
    ) {
    }

    /**
     * GET /citas/slots — público, sin token.
     *
     * Es la ruta que decide qué horas ve el cliente. Un error acá se traduce en
     * horas ofrecidas que la reserva después rechaza (409), o peor, en horas
     * ocultas que sí estaban libres.
     */
    public function slots(Request $request, SlotsService $slots)
    {
        // `now()` en la zona de la app (America/Santiago), NO en UTC:
        // `citas.fecha_hora` está en hora de la clínica (§9.6) y compararla
        // contra un «ahora» en UTC la corre 4 horas — el error que este
        // proyecto ya cometió tres veces.
        $inicio = CarbonImmutable::now();
        $hoy = $inicio->startOfDay();

        if ($desde = $request->query('desde')) {
            try {
                $d = CarbonImmutable::createFromFormat('Y-m-d', $desde)->startOfDay();
            } catch (\Throwable $e) {
                return response()->json(
                    ['detail' => "Parámetro 'desde' inválido (YYYY-MM-DD)"], 400
                );
            }
            if ($d->gt($hoy->addDays(self::MAX_DIAS_ANTICIPACION))) {
                // `(object)` y no `[]`: este endpoint devuelve un MAPA
                // fecha->horas, y un array PHP vacío se serializa como `[]`,
                // que en JSON es una lista. Python devuelve `{}`. Para Vue son
                // cosas distintas y el arnés también las distingue.
                return response()->json((object) []);
            }
            $inicio = $d->gt($inicio) ? $d : $inicio;
        }

        // FastAPI declara `dias: int = Query(14, ge=1, le=30)`, así que un
        // valor fuera de rango es 422, NO un recorte silencioso. Recortarlo
        // haría que `dias=99` devolviera 30 días con un 200 alegre, mientras
        // Python contesta 422 — el arnés lo cazó.
        $dias = (int) $request->query('dias', 14);
        if ($dias < 1 || $dias > 30) {
            // El cuerpo replica el de Pydantic campo por campo —`ctx` e
            // `input` incluidos—: es lo que ve cualquier cliente que valide
            // la respuesta, y el arnés compara el JSON entero, no solo el
            // código.
            $bajo = $dias < 1;
            return response()->json([
                'detail' => [[
                    'type' => $bajo ? 'greater_than_equal' : 'less_than_equal',
                    'loc' => ['query', 'dias'],
                    'msg' => $bajo
                        ? 'Input should be greater than or equal to 1'
                        : 'Input should be less than or equal to 30',
                    'input' => (string) $request->query('dias'),
                    'ctx' => $bajo ? ['ge' => '1'] : ['le' => '30'],
                ]],
            ], 422);
        }
        // No ofrecer fechas más allá del máximo de anticipación.
        $dias = min($dias, self::MAX_DIAS_ANTICIPACION
            - max(0, Fechas::diasEntre($hoy, $inicio)) + 1);
        if ($dias <= 0) {
            return response()->json((object) []);   // mapa vacío, no lista
        }

        $servicioId = $request->query('servicio_id');
        $especialistaId = $request->query('especialista_id');
        if (! $servicioId || ! $especialistaId) {
            // Modo legado: el horario general de la clínica, de antes de que
            // existieran los especialistas. **No se migra a propósito.**
            //
            // Se verificaron los tres frontends y el agente de WhatsApp: TODOS
            // llaman siempre con `servicio_id` y `especialista_id`
            // (CitasView.vue, ReservarView.vue, internal.py). Nadie puede
            // llegar acá desde la aplicación; solo a mano contra la API.
            //
            // Portarlo sería reproducir `CitasService.get_slots_disponibles`,
            // que trae su propia tabla de horarios fijos y su propia duración
            // — código muerto que habría que mantener sincronizado con el
            // algoritmo de verdad. Se devuelve un mapa vacío, que es lo que un
            // cliente sin parámetros puede esperar.
            return response()->json((object) []);
        }

        $servicio = DB::table('servicios')
            ->where('id', $servicioId)->where('activo', true)->first();
        $especialista = DB::table('especialistas')
            ->where('id', $especialistaId)->where('activo', true)->first();
        if (! $servicio || ! $especialista) {
            return response()->json(['detail' => 'Servicio o especialista no encontrado'], 404);
        }

        // Corta el autoservicio con quien no se reserva por la web. No es lo
        // mismo que `activo`: esta gente sigue apareciendo en el equipo del
        // sitio, lo que no existe es la vía «elige tu hora con él».
        if (! $especialista->reservable || self::coordinaPorWhatsapp($servicio)) {
            return response()->json([
                'detail' => self::coordinaPorWhatsapp($servicio)
                    ? "{$servicio->nombre} se coordina directamente con la clínica."
                    : "La hora con {$especialista->nombre} se coordina directamente con la clínica.",
            ], 409);
        }

        return $slots->para($servicio, $especialista, $inicio, $dias);
    }

    /**
     * POST /citas/reservar — PÚBLICO, sin token.
     *
     * Es la única ruta de escritura verdaderamente pública junto a
     * `/contacto/mensajes` y `/pagos`. Crea usuario, mascota y cita, congela
     * los montos y dispara dos correos.
     *
     * **El pago va DESPUÉS de crear la cita, nunca antes.** Así el cupo queda
     * tomado mientras el cliente pasa por el checkout, y si abandona el pago la
     * hora igual existe. Al revés —cobrar y después agendar— se puede cobrar
     * por una hora que otro tomó mientras tanto.
     */
    /** La versión del documento que se sella al informar. Espejo de
     *  PRIVACY_VERSION en app/routes/auth.py: si divergen, dos personas que
     *  vieron lo mismo quedan registradas con versiones distintas. */
    private const PRIVACY_VERSION = '2026-10-02';

    public function reservar(Request $request, SlotsService $slots, CobroService $cobro)
    {
        $datos = $request->validate([
            // Los límites siguen a las columnas donde termina cada dato: sin
            // esto, un valor más largo que la columna no falla con un 422
            // limpio sino con un error de la base convertido en 500.
            'correo' => ['required', 'email', 'max:255'],
            'tutor' => ['required', 'string', 'min:1', 'max:255'],
            'telefono' => ['required', 'string', 'min:1', 'max:60'],
            'mascota_nombre' => ['required', 'string', 'min:1', 'max:255'],
            'servicio_id' => ['required', 'uuid'],
            'especialista_id' => ['required', 'uuid'],
            'fecha_hora' => ['required', 'date'],
            'direccion' => ['nullable', 'string', 'max:500'],
            'respuestas' => ['nullable', 'array'],
            // Aviso de privacidad (ley 21.719). OPCIONAL a propósito: el portal
            // ya publicado no lo manda, y hacerlo requerido devolvería 422 en
            // cada reserva hasta desplegar el frontend nuevo. Y de fondo,
            // porque para dar la hora que la persona vino a pedir la base de
            // licitud es la prestación del servicio, no su consentimiento: lo
            // que la ley exige acá es INFORMAR.
            'acepta_privacidad' => ['nullable', 'boolean'],
            'notas_pre_cita' => ['nullable', 'string', 'max:2000'],
            'forma_pago' => ['nullable', 'string', 'max:60'],
            // Se sigue ACEPTANDO y se ignora: el portal publicado lo manda, y
            // quitarlo de la validación no rompe nada, pero rechazarlo sí.
            'recaptcha_token' => ['nullable', 'string'],
        ]);

        // Sin reCAPTCHA (sacado el 14-sep-2026, decisión de producto: «si no hace
        // nada, fuera»). Estaba en modo OBSERVACIÓN: consultaba a Google en cada
        // reserva, anotaba el puntaje y dejaba pasar igual, y el comentario
        // decía «contra los robots queda el tope por IP» — un tope que esta ruta
        // NO tiene. O sea: latencia y una llamada externa por reserva, a cambio
        // de nada que bloqueara. Si vuelve, que vuelva bloqueando y con el
        // umbral decidido. El chat (AgenteIA) tiene su propio tope por IP.

        $servicio = DB::table('servicios')
            ->where('id', $datos['servicio_id'])->where('activo', true)->first();
        $especialista = DB::table('especialistas')
            ->where('id', $datos['especialista_id'])->first();
        if (! $servicio || ! $especialista) {
            return response()->json(['detail' => 'Servicio o especialista no válido'], 400);
        }

        // Se toma la fecha TAL CUAL viene, sin convertir de zona:
        // `citas.fecha_hora` está en hora de la clínica (§9.6), y Python hace
        // exactamente esto (`.replace(tzinfo=None)`). Convertirla correría las
        // horas 4 h — el error que este proyecto ya cometió tres veces.
        $fechaHora = CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s',
            CarbonImmutable::parse($datos['fecha_hora'])->format('Y-m-d H:i:s')
        );
        $ahora = CarbonImmutable::now();

        if ($fechaHora->lessThanOrEqualTo($ahora)) {
            return response()->json(['detail' => 'No se puede agendar en el pasado'], 400);
        }
        if ($fechaHora->startOfDay()->gt($ahora->addDays(self::MAX_DIAS_ANTICIPACION)->startOfDay())) {
            return response()->json(
                ['detail' => 'Solo se puede agendar hasta con 3 meses de anticipación'], 400
            );
        }

        // Última verificación anti-doble-reserva: se recalcula la
        // disponibilidad de ESE día justo antes de insertar. No hay
        // `SELECT ... FOR UPDATE` —con el volumen de una sola clínica no se
        // justificó— y este es el punto exacto a reforzar si el tráfico
        // creciera.
        $inicioValidacion = $fechaHora->startOfDay()->gt($ahora)
            ? $fechaHora->startOfDay() : $ahora;
        $disponibles = $slots->para($servicio, $especialista, $inicioValidacion, 1);
        $dia = $fechaHora->format('Y-m-d');
        $hora = $fechaHora->format('H:i');
        if (! in_array($hora, $disponibles[$dia] ?? [], true)) {
            return response()->json(['detail' => 'Ese horario ya no está disponible. Elige otro.'], 409);
        }

        if ($error = $this->exigirReservable($especialista, $servicio)) {
            return $error;
        }

        // Consulta que exige pago en línea pero de la que no sale ningún monto:
        // se corta la reserva en vez de dejar la hora tomada GRATIS y en
        // silencio, justo en el servicio donde rige la política de las 48 h.
        // Falla CERRADO a propósito.
        $montoAbono = $cobro->montoAbono($especialista, $servicio, $fechaHora);
        if ($error = $this->exigirMontoCobrable($especialista, $servicio, $montoAbono)) {
            return $error;
        }
        $montoTotal = $cobro->montoTotal($especialista, $servicio, $fechaHora);

        $ahoraUtc = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

        $respuesta = DB::transaction(function () use (
            $datos, $servicio, $especialista, $fechaHora, $montoAbono, $montoTotal,
            $cobro, $ahoraUtc
        ) {
            // Busca o crea el usuario «invitado» por correo (sin login).
            //
            // En minúsculas, igual que el resto del sistema: con la comparación
            // exacta, quien escribía `Cliente@X.com` no encontraba su propia
            // cuenta y se le creaba una segunda, con sus mascotas y su
            // historial partidos en dos.
            $correo = mb_strtolower(trim($datos['correo']));
            $usuario = DB::table('usuarios')->whereRaw('LOWER(email) = ?', [$correo])->first();
            if (! $usuario) {
                $uid = (string) Str::uuid();
                DB::table('usuarios')->insert([
                    'id' => $uid,
                    'email' => $correo,
                    'nombre' => trim($datos['tutor']),
                    'telefono' => trim($datos['telefono']),
                    'rol' => 'dueño',
                    // Contraseña aleatoria que nunca se usa: la cuenta existe
                    // para colgar la cita, no para entrar.
                    'password_hash' => password_hash((string) Str::uuid(), PASSWORD_BCRYPT),
                    'email_verificado' => 0,
                    // `terms_accepted` NO se marca. Acá decía `=> 1`, y eso
                    // fabricaba un registro de consentimiento de alguien a
                    // quien nunca se le mostraron los términos: este flujo no
                    // los pide, ni tiene por qué. Un consentimiento inventado
                    // es peor que ninguno — el que no existe se puede pedir
                    // después; el falso hace creer que ya está resuelto.
                    // Python deja la columna en su default (false).
                    'created_at' => $ahoraUtc,
                ]);
                $usuario = DB::table('usuarios')->where('id', $uid)->first();
            }

            // Constancia de que el aviso se mostró, con la versión vigente del
            // documento. Solo se escribe cuando viene en true y la persona no
            // lo había prestado ya: un false no debe borrar un consentimiento
            // que esta persona sí dio antes por el portal.
            if (! empty($datos['acepta_privacidad']) && ! $usuario->privacy_accepted) {
                DB::table('usuarios')->where('id', $usuario->id)->update([
                    'privacy_accepted' => 1,
                    'privacy_accepted_at' => $ahoraUtc,
                    'privacy_version' => self::PRIVACY_VERSION,
                ]);
            }

            // Busca o crea la mascota por nombre bajo ese dueño. Reservar de
            // nuevo con el mismo nombre NO duplica.
            $mascota = DB::table('mascotas')
                ->where('usuario_id', $usuario->id)
                ->whereRaw('LOWER(nombre) = LOWER(?)', [trim($datos['mascota_nombre'])])
                ->first();
            if (! $mascota) {
                $mid = (string) Str::uuid();
                DB::table('mascotas')->insert([
                    'id' => $mid,
                    'usuario_id' => $usuario->id,
                    'nombre' => trim($datos['mascota_nombre']),
                    'especie' => 'perro',
                    'created_at' => $ahoraUtc,
                ]);
                $mascota = DB::table('mascotas')->where('id', $mid)->first();
            }

            $respuestas = $datos['respuestas'] ?? [];
            if (! empty($datos['direccion']) && ! isset($respuestas['Dirección'])) {
                $respuestas['Dirección'] = $datos['direccion'];
            }
            // El nombre de una cuenta que YA existe no se toca, pero el que se
            // escribió tampoco se pierde: si no coincide, queda anotado acá.
            // Es el caso real de quien agenda por otro —una pareja, un hijo por
            // su madre— y así el mostrador lo ve, en vez de que el sistema
            // decida en silencio cuál de los dos nombres es el bueno.
            $tutorDeclarado = trim($datos['tutor']);
            if ($tutorDeclarado !== '' && $tutorDeclarado !== trim((string) $usuario->nombre)
                && ! isset($respuestas['Reservado por'])) {
                $respuestas['Reservado por'] = $tutorDeclarado;
            }

            $citaId = (string) Str::uuid();
            DB::table('citas')->insert([
                'id' => $citaId,
                'mascota_id' => $mascota->id,
                'usuario_dueno_id' => $usuario->id,
                'fecha_hora' => $fechaHora->format('Y-m-d H:i:s'),
                'servicio' => $servicio->nombre,
                'servicio_id' => $servicio->id,
                'especialista_id' => $especialista->id,
                'duracion_min' => $servicio->duracion_min,
                // `sort_keys` implícito: se guarda ordenado para que la
                // verificación campo a campo no marque diferencias que no lo
                // son (MariaDB no normaliza las claves de un JSON).
                'respuestas' => $this->jsonOrdenado($respuestas),
                'notas_pre_cita' => $datos['notas_pre_cita'] ?? null,
                'forma_pago' => $datos['forma_pago'] ?? null,
                'payment_status' => $montoAbono ? 'pending' : null,
                'monto_abono_clp' => $montoAbono,
                'monto_total_clp' => $montoTotal,
                'pago_modalidad_requerida' => $servicio->modalidad_pago ?: 'abono',
                'estado' => $cobro->estadoInicial($montoAbono),
                'created_at' => $ahoraUtc,
            ]);

            $c = DB::table('citas')->where('id', $citaId)->first();
            return response()->json($this->citaOut($c));
        });

        // ── Efectos laterales, FUERA de la transacción ────────────────────
        // Van después del commit y no adentro, por dos razones:
        //
        //  · un correo enviado NO se revierte con un rollback, así que
        //    mandarlo dentro arriesga avisar de una cita que no quedó; y
        //  · Google falla ABIERTO (§4): si la llamada revienta, la reserva ya
        //    está guardada y no puede caerse por eso.
        //
        // Si la reserva no salió bien, no hay nada que avisar.
        // El id sale de la respuesta y no de una variable del closure: `$citaId`
        // se declara DENTRO de la transacción y acá ya no existe.
        if ($respuesta->getStatusCode() < 300) {
            $creada = json_decode($respuesta->getContent(), true);
            if (! empty($creada['id'])) {
                $this->efectosDeReserva((string) $creada['id']);
            }
        }

        return $respuesta;
    }

    /**
     * El evento de Google y los dos correos de una reserva recién creada.
     *
     * Orden: **primero Google, después el correo al tutor**. No es casual —
     * es el orden en que Python los emite, y el modo C del arnés compara la
     * secuencia, no solo el contenido.
     *
     * Nada de acá puede lanzar: la cita ya existe.
     */
    /** Público: también lo usa la cita acordada por WhatsApp con un especialista (IntermediarioEspecialista). */
    public function efectosDeReserva(string $citaId): void
    {
        try {
            $cita = DB::table('citas')->where('id', $citaId)->first();
            if (! $cita) {
                return;
            }
            $usuario = DB::table('usuarios')->where('id', $cita->usuario_dueno_id)->first();
            $mascota = DB::table('mascotas')->where('id', $cita->mascota_id)->first();
            $especialista = $cita->especialista_id
                ? DB::table('especialistas')->where('id', $cita->especialista_id)->first()
                : null;
            if (! $usuario || ! $mascota) {
                return;
            }

            $this->google->crearEventoDeCita($cita, $usuario, $mascota, $especialista);
            $this->emails->notificarReservaCreada($usuario, $mascota, $cita, $especialista);
            $this->emails->notificarEspecialistaReservaCreada(
                $especialista, $usuario, $mascota, $cita);
        } catch (\Throwable $e) {
            Log::error("Efectos de la reserva {$citaId}: " . $e->getMessage());
        }
    }

    /**
     * Los efectos de un cambio de estado.
     *
     * Al TUTOR se le avisa siempre. Al ESPECIALISTA **solo la cancelación**:
     * es lo único que le libera la hora. Confirmada y Completada son
     * movimientos internos sobre una cita que él ya tiene anotada, y avisarle
     * de cada uno convierte el aviso en ruido que se deja de leer — incluida
     * la cancelación, que es la que sí importa.
     */
    private function efectosDeCambioEstado(string $citaId, string $estado): void
    {
        try {
            [$cita, $usuario, $mascota, $especialista] = $this->piezasDe($citaId);
            if (! $cita || ! $usuario || ! $mascota) {
                return;
            }

            // Si se cancela y hay evento espejo en Google, se borra también allá.
            if ($estado === 'Cancelada' && $cita->google_event_id) {
                if ($this->google->eliminarEventoDeCita($cita)) {
                    DB::table('citas')->where('id', $citaId)
                        ->update(['google_event_id' => null]);
                }
            }

            $this->emails->notificarCambioEstado($usuario, $mascota, $cita);

            if ($estado === 'Cancelada') {
                $this->emails->notificarEspecialistaCancelada(
                    $especialista, $usuario, $mascota, $cita);
            }
        } catch (\Throwable $e) {
            Log::error("Efectos del cambio de estado ({$citaId}): " . $e->getMessage());
        }
    }

    /**
     * Los efectos de un reagendamiento.
     *
     * El evento de Google se **ACTUALIZA**, no se crea de nuevo: crear otro
     * dejaría la hora vieja y la nueva juntas en el calendario de la clínica.
     */
    private function efectosDeReagendar(string $citaId): void
    {
        try {
            [$cita, $usuario, $mascota, $especialista] = $this->piezasDe($citaId);
            if (! $cita || ! $usuario || ! $mascota) {
                return;
            }

            if ($cita->google_event_id) {
                $this->google->actualizarEventoDeCita($cita, $usuario, $mascota, $especialista);
            }

            $this->emails->notificarCitaReagendada($usuario, $mascota, $cita, $especialista);
            $this->emails->notificarEspecialistaReagendada(
                $especialista, $usuario, $mascota, $cita);
        } catch (\Throwable $e) {
            Log::error("Efectos del reagendamiento ({$citaId}): " . $e->getMessage());
        }
    }

    /** La cita con su tutor, su paciente y su especialista. */
    private function piezasDe(string $citaId): array
    {
        $cita = DB::table('citas')->where('id', $citaId)->first();
        if (! $cita) {
            return [null, null, null, null];
        }

        return [
            $cita,
            DB::table('usuarios')->where('id', $cita->usuario_dueno_id)->first(),
            DB::table('mascotas')->where('id', $cita->mascota_id)->first(),
            $cita->especialista_id
                ? DB::table('especialistas')->where('id', $cita->especialista_id)->first()
                : null,
        ];
    }

    /**
     * GET /citas/reservas — staff, permiso «reservas».
     *
     * El listado con filtros del panel. Sin `desde`/`hasta` cubre los próximos
     * 30 días desde hoy.
     */
    public function reservas(Request $request)
    {
        try {
            $d0 = $request->query('desde')
                ? CarbonImmutable::createFromFormat('Y-m-d', $request->query('desde'))->startOfDay()
                : CarbonImmutable::now()->startOfDay();
            $d1 = $request->query('hasta')
                ? Fechas::sumarDias($request->query('hasta'), 1)
                : Fechas::sumarDias($d0, 30);
        } catch (\Throwable $e) {
            return response()->json(['detail' => 'Fechas inválidas (YYYY-MM-DD)'], 400);
        }

        $q = $this->consultaReservas($d0, $d1);

        if ($v = $request->query('servicio_id')) {
            $q->where('c.servicio_id', $v);
        }
        if ($v = $request->query('especialista_id')) {
            $q->where('c.especialista_id', $v);
        }
        if ($v = $request->query('estado')) {
            $q->where('c.estado', $v);
        }
        if ($v = $request->query('q')) {
            // `ilike` de Postgres = insensible a mayúsculas. En MariaDB la
            // collation de la columna ya lo es (`uca1400_as_ci`), así que un
            // LIKE normal se comporta igual — pero NO es insensible a tildes,
            // y eso también coincide: `as_ci` es accent-SENSITIVE, como
            // Postgres. Buscar «Munoz» no encuentra «Muñoz» en ninguno de los
            // dos, que es el comportamiento que la clínica ya conoce.
            $like = '%' . $v . '%';
            $q->where(function ($w) use ($like) {
                $w->where('u.nombre', 'like', $like)
                  ->orWhere('u.email', 'like', $like)
                  ->orWhere('m.nombre', 'like', $like);
            });
        }

        return $this->salidaReservas($q);
    }

    /**
     * GET /citas/agenda — staff, permiso «agenda».
     *
     * Una sola llamada sirve las tres vistas (día, semana, mes): el cliente
     * recorta en memoria, así que cerrar el zoom no dispara otra petición.
     */
    public function agenda(Request $request)
    {
        try {
            $dia = $request->query('fecha')
                ? CarbonImmutable::createFromFormat('Y-m-d', $request->query('fecha'))->startOfDay()
                : CarbonImmutable::now()->startOfDay();
            $ultimo = $request->query('hasta')
                ? CarbonImmutable::createFromFormat('Y-m-d', $request->query('hasta'))->startOfDay()
                : $dia;
        } catch (\Throwable $e) {
            return response()->json(['detail' => 'Fecha inválida (YYYY-MM-DD)'], 400);
        }

        $fin = Fechas::sumarDias($ultimo, 1);
        if ($fin->lessThanOrEqualTo($dia)) {
            return response()->json(['detail' => 'El rango termina antes de empezar.'], 400);
        }
        // El tope existe para que una petición no arrastre un año de citas: la
        // vista de mes pide ~31 días y la de semana 7.
        if (Fechas::diasEntre($dia, $fin) > self::AGENDA_MAX_DIAS) {
            return response()->json([
                'detail' => 'El rango de la agenda no puede superar los '
                    . self::AGENDA_MAX_DIAS . ' días.',
            ], 400);
        }

        return $this->salidaReservas($this->consultaReservas($dia, $fin));
    }

    /** La consulta base que comparten `/reservas` y `/agenda`. */
    /**
     * PATCH /citas/{id}/estado
     *
     * `auth` a secas: el TUTOR entra acá para cancelar su propia hora, así que
     * el permiso se decide adentro. Un dueño solo puede cancelar, y solo lo
     * suyo; el staff puede cualquier estado.
     */
    public function cambiarEstado(Request $request, string $citaId)
    {
        $datos = $request->validate(['estado' => 'required|string']);
        $usuario = $request->attributes->get('usuario');

        $cita = DB::table('citas')->where('id', $citaId)->first();
        if (! $cita) {
            return response()->json(['detail' => 'Cita no encontrada'], 404);
        }

        // `reservas` o `agenda`: son las dos pantallas que cambian estados, y
        // hay gente del equipo que tiene una sola.
        $esStaff = Permisos::staffDeCitas($usuario);
        if (! $esStaff
            && ($cita->usuario_dueno_id !== $usuario->id || $datos['estado'] !== 'Cancelada')) {
            return response()->json(['detail' => 'Sin permiso'], 403);
        }

        if (! in_array($datos['estado'],
                       ['Agendada', 'Confirmada', 'Completada', 'Cancelada'], true)) {
            return response()->json(['detail' => 'Estado inválido'], 400);
        }

        // «Confirmada» significa «la hora quedó firme», y con un cobro en línea
        // de por medio eso solo es cierto si el pago está aprobado. Sin esta
        // guarda, este endpoint genérico dejaba confirmar a mano CUALQUIER cita
        // sin pago real: el camino para un abono pagado por fuera de Getnet
        // sigue siendo `PATCH /{id}/abono-confirmado`, que sí valida.
        //
        // Se mira el MONTO CONGELADO y no `pago_modalidad_requerida`, porque esa
        // columna la escriben solo la reserva pública y la del cliente logueado:
        // en una cita de `/crear-staff` o del agente de WhatsApp viene en NULL,
        // se leía como 'abono' y la cita quedaba SIN NINGUNA vía para
        // confirmarse — ni por acá (400) ni con «Registrar abono», que exige
        // justamente un abono congelado.
        $faltaPagar = $cita->monto_abono_clp !== null && $cita->payment_status !== 'approved';
        if ($datos['estado'] === 'Confirmada' && $faltaPagar) {
            return response()->json([
                'detail' => "Esta cita exige pago antes de poder confirmarse. Usa 'Registrar abono' "
                    . 'si el pago llegó por fuera de la pasarela, o espera a que Getnet lo apruebe.',
            ], 400);
        }

        $cambios = ['estado' => $datos['estado']];
        // Una hora PAGADA que se cancela deja una devolución por decidir, y la
        // política depende de cuánto aviso hubo (60% con 24 h). Sin anotar
        // cuándo se canceló, ese dato no existe en ninguna otra parte.
        if ($datos['estado'] === 'Cancelada' && $cita->estado !== 'Cancelada'
            && $cita->payment_status === 'approved') {
            $resp = $cita->respuestas ? (json_decode($cita->respuestas, true) ?: []) : [];
            $resp['Cancelada el'] = CarbonImmutable::now()->format('Y-m-d H:i');
            $resp['Cancelada por'] = $esStaff ? ($usuario->nombre ?: 'Equipo') : 'El tutor';
            $cambios['respuestas'] = $this->jsonOrdenado($resp);
        }
        DB::table('citas')->where('id', $citaId)->update($cambios);

        $this->efectosDeCambioEstado($citaId, $datos['estado']);

        return $this->citaOut(DB::table('citas')->where('id', $citaId)->first());
    }

    /**
     * Con qué estado queda una cita después de moverla de hora. Espejo de
     * `_estado_al_reagendar()` en Python.
     *
     * Mover la hora no agrega nada que esperar. Antes volvía SIEMPRE a
     * «Agendada», y eso rompía la definición de Confirmada (§5): una Consulta
     * General sin cobro en línea nacía Confirmada y al moverla quedaba Agendada
     * sin ningún trámite pendiente, y una cita con el abono ya aprobado quedaba
     * Agendada — el estado que `migrate_confirmar_sin_cobro.py` tuvo que reparar.
     * Se vio el 13-sep-2026 al ensayar el calendario desde pruebas-panel.
     *
     * Sigue en «Agendada» lo que de verdad espera algo: el abono sin pagar, o
     * la cita creada por staff/WhatsApp que la clínica todavía no confirmó.
     */
    public static function estadoAlReagendar(object $cita): string
    {
        return ($cita->estado === 'Confirmada' || ($cita->payment_status ?? null) === 'approved')
            ? 'Confirmada' : 'Agendada';
    }

    /**
     * PATCH /citas/{id}/reagendar — mover la hora conservando el historial.
     *
     * Reagendar y no «cancelar + crear otra»: así no se pierden las respuestas
     * del formulario original ni el historial de la cita.
     *
     * La hora nueva se valida contra la disponibilidad REAL, con el mismo
     * `SlotsService` que alimenta el paso 3 del portal — no basta con que la
     * fecha sea futura.
     */
    public function reagendar(Request $request, string $citaId, SlotsService $slots)
    {
        $datos = $request->validate(['fecha_hora' => 'required|date']);
        $usuario = $request->attributes->get('usuario');

        $cita = DB::table('citas')->where('id', $citaId)->first();
        if (! $cita) {
            return response()->json(['detail' => 'Cita no encontrada'], 404);
        }

        $esStaff = Permisos::staffDeCitas($usuario);
        if (! $esStaff && $cita->usuario_dueno_id !== $usuario->id) {
            return response()->json(['detail' => 'Sin permiso'], 403);
        }
        if ($cita->estado === 'Cancelada') {
            return response()->json(
                ['detail' => 'No se puede reagendar una cita cancelada.'], 400);
        }

        // Hora de la CLÍNICA, no UTC (§9.6).
        $fechaHora = CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s', CarbonImmutable::parse($datos['fecha_hora'])->format('Y-m-d H:i:s'));
        $ahora = CarbonImmutable::now();

        if ($fechaHora->lte($ahora)) {
            return response()->json(['detail' => 'No se puede agendar en el pasado'], 400);
        }
        if ($fechaHora->startOfDay()->gt($ahora->addDays(self::MAX_DIAS_ANTICIPACION)->startOfDay())) {
            return response()->json(
                ['detail' => 'Solo se puede agendar hasta con 3 meses de anticipación'], 400);
        }

        $servicio = $cita->servicio_id
            ? DB::table('servicios')->where('id', $cita->servicio_id)->first() : null;
        $especialista = $cita->especialista_id
            ? DB::table('especialistas')->where('id', $cita->especialista_id)->first() : null;
        if (! $servicio || ! $especialista) {
            return response()->json([
                'detail' => 'Esta cita no tiene servicio/especialista asociado; no se puede reagendar.',
            ], 400);
        }

        // El inicio de la validación es el MAYOR entre «ahora» y el comienzo
        // del día pedido: pedir desde medianoche para un día futuro está bien,
        // pero para HOY ofrecería horas que ya pasaron.
        $inicio = $ahora->gt($fechaHora->startOfDay()) ? $ahora : $fechaHora->startOfDay();
        $disponibles = $slots->para($servicio, $especialista, $inicio, 1);

        $dia = $fechaHora->format('Y-m-d');
        $hora = $fechaHora->format('H:i');
        if (! in_array($hora, $disponibles[$dia] ?? [], true)) {
            return response()->json(
                ['detail' => 'Ese horario ya no está disponible. Elige otro.'], 409);
        }

        DB::table('citas')->where('id', $citaId)->update([
            'fecha_hora' => $fechaHora->format('Y-m-d H:i:s'),
            'estado' => self::estadoAlReagendar($cita),
        ]);

        $this->efectosDeReagendar($citaId);

        return $this->citaOut(DB::table('citas')->where('id', $citaId)->first());
    }

    /**
     * PATCH /citas/{id}/abono-confirmado — el abono que llegó POR FUERA de la
     * pasarela (transferencia, efectivo en el mostrador).
     *
     * El cobro por Getnet no pasa por acá: lo marca el webhook con la respuesta
     * de la pasarela.
     *
     * **El efectivo entra a la caja del turno.** Antes no lo hacía y el dinero
     * quedaba en dos sitios que no se hablaban: la cita marcada como pagada y
     * el cajón sin enterarse. Se descubrió con cuatro abonos de un mismo minuto
     * —$75.000— que no aparecían por ninguna parte en Caja. La TRANSFERENCIA no
     * entra: ese dinero está en la cuenta, no en el cajón, y meterlo ahí
     * rompería el cuadre en la dirección contraria.
     */
    public function confirmarAbono(Request $request, string $citaId)
    {
        $datos = $request->validate(['medio' => 'nullable|string']);
        $actor = $request->attributes->get('usuario');

        $cita = DB::table('citas')->where('id', $citaId)->first();
        if (! $cita) {
            return response()->json(['detail' => 'Cita no encontrada'], 404);
        }
        if ($cita->monto_abono_clp === null) {
            return response()->json(['detail' => 'Esta cita no requiere abono.'], 400);
        }

        $medio = $datos['medio'] ?? 'transferencia';

        // Se comprueba que HAYA dónde registrar el efectivo antes de tocar la
        // cita: si falla, todo queda como estaba en vez de depender de que el
        // rollback llegue a tiempo.
        $sesion = null;
        if ($medio === 'efectivo') {
            $sesion = DB::table('sesiones_caja')->whereNull('cerrada_at')
                ->orderBy('abierta_at', 'desc')->orderBy('id', 'desc')->first();
            if (! $sesion) {
                return response()->json([
                    'detail' => 'No hay una caja abierta para registrar este efectivo. Abre la '
                        . 'caja y vuelve a intentarlo: sin el movimiento, el cierre del '
                        . 'turno mostraría un sobrante por ese monto.',
                ], 409);
            }
        }

        DB::transaction(function () use ($cita, $citaId, $medio, $sesion, $actor) {
            $cambios = [
                'payment_status' => 'approved',
                'forma_pago' => $medio,
                'pago_actualizado_at' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
            ];
            // 'Expirada' también REVIVE: el abono llegó por fuera y ya está
            // cobrado, así que la hora vuelve a ser firme. Es una acción del
            // staff sobre una cita que tiene delante, de modo que la
            // comprobación de cupo la hace la persona, no el código.
            if (in_array($cita->estado, ['Agendada', self::ESTADO_EXPIRADA], true)) {
                $cambios['estado'] = 'Confirmada';
            }
            DB::table('citas')->where('id', $citaId)->update($cambios);

            if ($sesion !== null) {
                $cuando = CarbonImmutable::parse($cita->fecha_hora)->format('d-m-Y H:i');
                DB::table('movimientos_caja')->insert([
                    'id' => (string) Str::uuid(),
                    'sesion_id' => $sesion->id,
                    'usuario_id' => $actor->id,
                    'tipo' => 'abono',
                    'monto' => $cita->monto_abono_clp,
                    'comentario' => 'Abono de ' . ($cita->servicio ?: 'consulta') . " — {$cuando}",
                    'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                ]);
            }

            $this->auditoria->registrar($actor, 'abono_confirmado', 'reservas', 'cita', $citaId, [
                'medio' => $medio,
                'monto' => (int) $cita->monto_abono_clp,
                'a_caja' => $sesion !== null,
            ]);
        });

        return $this->citaOut(DB::table('citas')->where('id', $citaId)->first());
    }

    /** GET /citas/mias — las citas del dueño logueado, la más reciente arriba. */
    public function mias(Request $request)
    {
        $u = $request->attributes->get('usuario');

        return DB::table('citas')
            ->where('usuario_dueno_id', $u->id)
            ->orderBy('fecha_hora', 'desc')->orderBy('id', 'desc')
            ->get()
            ->map(fn ($c) => $this->citaOut($c))
            ->values();
    }

    /**
     * GET /citas/mi_mascota/{id}
     *
     * El staff ve las de cualquier paciente; un dueño, solo las de SU mascota.
     * La comprobación va adentro porque la ruta la comparten las dos
     * audiencias — y sin ella, un tutor vería la agenda de otro.
     */
    public function deMiMascota(Request $request, string $mascotaId)
    {
        $u = $request->attributes->get('usuario');

        // Las citas de un paciente son parte de su historial: mismo permiso
        // que la ficha.
        if (! Permisos::tiene($u, 'personas')) {
            $m = DB::table('mascotas')->where('id', $mascotaId)->first();
            if (! $m || $m->usuario_id !== $u->id) {
                return response()->json(['detail' => 'Sin permiso'], 403);
            }
        }

        return DB::table('citas')
            ->where('mascota_id', $mascotaId)
            ->orderBy('fecha_hora', 'desc')->orderBy('id', 'desc')
            ->get()
            ->map(fn ($c) => $this->citaOut($c))
            ->values();
    }

    /**
     * `servicios.coordinar_whatsapp`. Con `?? false`: sin el ALTER
     * (migracion/mariadb_servicio_coordinar_whatsapp.sql) todo sigue
     * reservable como antes, en vez de romper los endpoints públicos.
     */
    public static function coordinaPorWhatsapp(object $servicio): bool
    {
        return (bool) ($servicio->coordinar_whatsapp ?? false);
    }

    /**
     * Corta el autoservicio con quien no se reserva por la web.
     *
     * No es lo mismo que `activo`: esa gente sigue apareciendo en el equipo del
     * sitio. Lo que no existe es la vía «elige tu hora con él» — su agenda la
     * coordina la clínica, y antes de este flag quien lo elegía llegaba a un
     * calendario vacío sin explicación.
     *
     * Va acá ADEMÁS del filtro del listado, porque el listado es solo la UI: el
     * id del especialista viaja en el cuerpo, así que sin esta guarda basta con
     * mandarlo a mano. **`crear-staff` NO la llama, a propósito**: el mostrador
     * tiene que poder agendarle, que es justo como se coordina esa hora.
     */
    private function exigirReservable(object $especialista, ?object $servicio = null)
    {
        $tel = config('emr.clinica_telefono');
        // La consulta entera se coordina por WhatsApp (ecografía, radiología:
        // pedido de la clínica, 30-sep-2026), la atienda quien la atienda.
        if ($servicio && self::coordinaPorWhatsapp($servicio)) {
            return response()->json([
                'detail' => "{$servicio->nombre} no se agenda por la web: primero hay que confirmar "
                    . "disponibilidad. Escríbenos por WhatsApp al {$tel} y te la coordinamos.",
            ], 409);
        }
        if ($especialista->reservable) {
            return null;
        }

        return response()->json([
            'detail' => "Las horas con {$especialista->nombre} no se piden por la web: "
                . "las coordina la clínica. Escríbenos por WhatsApp al {$tel} y te la agendamos.",
        ], 409);
    }

    /**
     * Corta la reserva si la consulta exige pago en línea pero no hay monto.
     *
     * El caso real: «Interconsultas especialistas» cobra abono del 50% y no
     * tiene precio propio —cada especialidad cobra distinto—, así que el monto
     * sale de la tarifa del especialista. Los que no la tienen cargada
     * producían monto NULL y la reserva se guardaba con `payment_status` en
     * NULL: la hora quedaba tomada, gratis y en silencio, justo en el servicio
     * donde rige la política de las 48 horas.
     *
     * Antes fallaba ABIERTO. Ahora falla CERRADO: es preferible que una hora no
     * se pueda pedir por la web —y se derive a WhatsApp, donde una persona
     * cobra— a que se agende sin cobrar sin que nadie se entere.
     */
    private function exigirMontoCobrable(object $especialista, object $servicio, ?int $montoAbono)
    {
        if (($servicio->modalidad_pago ?: 'abono') === 'nada' || $montoAbono !== null) {
            return null;
        }
        $tel = config('emr.clinica_telefono');

        return response()->json([
            'detail' => "Esta hora con {$especialista->nombre} todavía no se puede reservar "
                . "por la web porque falta cargar su valor. Escríbenos por WhatsApp al {$tel} "
                . 'y te la agendamos.',
        ], 409);
    }

    /**
     * POST /citas/crear — el CLIENTE LOGUEADO reserva para su mascota.
     *
     * Misma mecánica que `/reservar` salvo en dos cosas: la mascota tiene que
     * ser SUYA (se filtra por `usuario_id`, no basta el id) y el tutor sale de
     * la sesión en vez del formulario.
     */
    public function crear(Request $request, SlotsService $slots, CobroService $cobro)
    {
        $datos = $request->validate([
            'mascota_id' => 'required|uuid',
            'servicio_id' => 'required|uuid',
            'especialista_id' => 'required|uuid',
            'fecha_hora' => 'required|date',
            'respuestas' => 'nullable|array',
            'notas_pre_cita' => 'nullable|string',
            'forma_pago' => 'nullable|string',
        ]);

        $u = $request->attributes->get('usuario');

        // La mascota se busca CON el dueño: si solo se filtrara por id, un
        // cliente podría agendarle una hora a la mascota de otro.
        $mascota = DB::table('mascotas')->where('id', $datos['mascota_id'])
            ->where('usuario_id', $u->id)->first();
        if (! $mascota) {
            return response()->json(['detail' => 'Mascota no encontrada'], 400);
        }

        $servicio = DB::table('servicios')->where('id', $datos['servicio_id'])
            ->where('activo', true)->first();
        $especialista = DB::table('especialistas')->where('id', $datos['especialista_id'])->first();
        if (! $servicio || ! $especialista) {
            return response()->json(['detail' => 'Servicio o especialista no válido'], 400);
        }

        $fechaHora = CarbonImmutable::createFromFormat('Y-m-d H:i:s',
            CarbonImmutable::parse($datos['fecha_hora'])->format('Y-m-d H:i:s'));
        if ($error = $this->validarVentana($fechaHora)) {
            return $error;
        }
        if ($error = $this->validarDisponible($slots, $servicio, $especialista, $fechaHora)) {
            return $error;
        }
        if ($error = $this->exigirReservable($especialista, $servicio)) {
            return $error;
        }

        $montoAbono = $cobro->montoAbono($especialista, $servicio, $fechaHora);
        if ($error = $this->exigirMontoCobrable($especialista, $servicio, $montoAbono)) {
            return $error;
        }
        $montoTotal = $cobro->montoTotal($especialista, $servicio, $fechaHora);

        $id = (string) Str::uuid();
        DB::table('citas')->insert([
            'id' => $id,
            'mascota_id' => $mascota->id,
            'usuario_dueno_id' => $u->id,
            'fecha_hora' => $fechaHora->format('Y-m-d H:i:s'),
            'servicio' => $servicio->nombre,
            'servicio_id' => $servicio->id,
            'especialista_id' => $especialista->id,
            'duracion_min' => $servicio->duracion_min,
            'respuestas' => $this->jsonOrdenado($datos['respuestas'] ?? []),
            'notas_pre_cita' => $datos['notas_pre_cita'] ?? null,
            'forma_pago' => $datos['forma_pago'] ?? null,
            'payment_status' => $montoAbono ? 'pending' : null,
            'monto_abono_clp' => $montoAbono,
            'monto_total_clp' => $montoTotal,
            'pago_modalidad_requerida' => $servicio->modalidad_pago ?: 'abono',
            'estado' => $cobro->estadoInicial($montoAbono),
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);

        $this->efectosDeReserva($id);

        return $this->citaOut(DB::table('citas')->where('id', $id)->first());
    }

    /**
     * POST /citas/crear-staff — el mostrador agenda a nombre del dueño REAL.
     *
     * Tres diferencias con `/crear`, todas deliberadas:
     *
     *  · el tutor sale de la MASCOTA, no de la sesión (quien agenda es el staff);
     *  · `forzar` permite saltarse la comprobación de disponibilidad, para el
     *    caso excepcional que la clínica decide encajar; y
     *  · **no congela ningún monto**: la cita nace «Agendada» y el cobro se
     *    coordina aparte. Por eso tampoco se exige `reservable` — esta es
     *    justamente la vía por la que se agenda al anestesista.
     */
    public function crearStaff(Request $request, SlotsService $slots)
    {
        $datos = $request->validate([
            'mascota_id' => 'required|uuid',
            'servicio_id' => 'required|uuid',
            'especialista_id' => 'required|uuid',
            'fecha_hora' => 'required|date',
            'respuestas' => 'nullable|array',
            'notas_pre_cita' => 'nullable|string',
            'forzar' => 'nullable|boolean',
        ]);

        $mascota = DB::table('mascotas')->where('id', $datos['mascota_id'])->first();
        if (! $mascota) {
            return response()->json(['detail' => 'Mascota no encontrada'], 400);
        }
        $dueno = DB::table('usuarios')->where('id', $mascota->usuario_id)->first();
        if (! $dueno) {
            return response()->json(['detail' => 'La mascota no tiene un dueño válido.'], 400);
        }

        $servicio = DB::table('servicios')->where('id', $datos['servicio_id'])
            ->where('activo', true)->first();
        $especialista = DB::table('especialistas')->where('id', $datos['especialista_id'])->first();
        if (! $servicio || ! $especialista) {
            return response()->json(['detail' => 'Servicio o especialista no válido'], 400);
        }

        $fechaHora = CarbonImmutable::createFromFormat('Y-m-d H:i:s',
            CarbonImmutable::parse($datos['fecha_hora'])->format('Y-m-d H:i:s'));
        // Solo se valida el pasado: el staff SÍ puede agendar más allá de los
        // tres meses, a diferencia del autoservicio.
        if ($fechaHora->lte(CarbonImmutable::now())) {
            return response()->json(['detail' => 'No se puede agendar en el pasado'], 400);
        }

        if (empty($datos['forzar'])) {
            $error = $this->validarDisponible($slots, $servicio, $especialista, $fechaHora,
                "Ese horario ya no está disponible. Elige otro, o marca 'forzar' si es un caso excepcional.");
            if ($error) {
                return $error;
            }
        }

        $id = (string) Str::uuid();
        DB::table('citas')->insert([
            'id' => $id,
            'mascota_id' => $mascota->id,
            'usuario_dueno_id' => $dueno->id,
            'fecha_hora' => $fechaHora->format('Y-m-d H:i:s'),
            'servicio' => $servicio->nombre,
            'servicio_id' => $servicio->id,
            'especialista_id' => $especialista->id,
            'duracion_min' => $servicio->duracion_min,
            'respuestas' => $this->jsonOrdenado($datos['respuestas'] ?? []),
            'notas_pre_cita' => $datos['notas_pre_cita'] ?? null,
            // Sin monto congelado y SIEMPRE «Agendada»: la clínica coordina el
            // cobro, y confirmarla es su decisión.
            'estado' => 'Agendada',
            // **NULL explícito, no el default de la columna.** `citas.payment_status`
            // tiene DEFAULT 'pending' en las dos bases, pero el modelo de
            // SQLAlchemy no lo declara, así que Python escribe NULL. Omitir la
            // columna acá dejaba actuar al default y la cita del mostrador
            // aparecía con «pago pendiente» sin tener ningún monto que cobrar —
            // se colaría en la cola de abonos pendientes del panel.
            'payment_status' => null,
            'monto_abono_clp' => null,
            'monto_total_clp' => null,
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);

        $this->efectosDeReserva($id);

        return $this->citaOut(DB::table('citas')->where('id', $id)->first());
    }

    /** El tope de 3 meses y el pasado, comunes al autoservicio. */
    private function validarVentana(CarbonImmutable $fechaHora)
    {
        $ahora = CarbonImmutable::now();
        if ($fechaHora->lte($ahora)) {
            return response()->json(['detail' => 'No se puede agendar en el pasado'], 400);
        }
        if ($fechaHora->startOfDay()->gt($ahora->addDays(self::MAX_DIAS_ANTICIPACION)->startOfDay())) {
            return response()->json(
                ['detail' => 'Solo se puede agendar hasta con 3 meses de anticipación'], 400);
        }

        return null;
    }

    /** La hora tiene que estar en la disponibilidad REAL de ese día. */
    private function validarDisponible(SlotsService $slots, object $servicio,
        object $especialista, CarbonImmutable $fechaHora, ?string $mensaje = null)
    {
        $ahora = CarbonImmutable::now();
        $inicio = $ahora->gt($fechaHora->startOfDay()) ? $ahora : $fechaHora->startOfDay();
        $disponibles = $slots->para($servicio, $especialista, $inicio, 1);

        $dia = $fechaHora->format('Y-m-d');
        $hora = $fechaHora->format('H:i');
        if (in_array($hora, $disponibles[$dia] ?? [], true)) {
            return null;
        }

        return response()->json([
            'detail' => $mensaje ?: 'Ese horario ya no está disponible. Elige otro.',
        ], 409);
    }

    private function consultaReservas(CarbonImmutable $desde, CarbonImmutable $hasta)
    {
        return DB::table('citas as c')
            ->join('mascotas as m', 'm.id', '=', 'c.mascota_id')
            ->join('usuarios as u', 'u.id', '=', 'c.usuario_dueno_id')
            ->where('c.fecha_hora', '>=', $desde->format('Y-m-d H:i:s'))
            ->where('c.fecha_hora', '<', $hasta->format('Y-m-d H:i:s'))
            // El desempate por id es obligatorio (§9.14): sin él, dos citas a
            // la misma hora salen en un orden que puede cambiar entre
            // peticiones.
            ->orderBy('c.fecha_hora')->orderBy('c.id')
            ->select('c.*', 'm.nombre as m_nombre', 'm.especie as m_especie',
                     'm.alergias as m_alergias', 'm.id as m_id',
                     'u.nombre as u_nombre', 'u.telefono as u_telefono', 'u.email as u_email');
    }

    /** Ejecuta la consulta y serializa, trayendo los especialistas de una vez. */
    private function salidaReservas($q)
    {
        $filas = $q->get();
        $espIds = $filas->pluck('especialista_id')->filter()->unique();
        $esps = $espIds->isEmpty() ? collect() : DB::table('especialistas')
            ->whereIn('id', $espIds)->get()->keyBy('id');

        return $filas->map(fn ($f) => $this->reservaOut($f, $esps))->values();
    }

    /**
     * GET /citas/pendientes-abono — staff, permiso «reservas».
     *
     * La cola de trabajo del mostrador: reservas que exigen abono y siguen sin
     * pagar. Deliberadamente **fuera de todo rango de fechas** — quien reservó
     * y no abonó puede haberlo hecho semanas atrás.
     */
    public function pendientesAbono()
    {
        // `!= 'approved'` y NO `== 'pending'`. Con la igualdad se caía de la
        // cola el caso más urgente: el pago RECHAZADO. Quien abandona el
        // checkout al menos no lo intentó; a quien se le cayó la tarjeta sí
        // quiso pagar, tiene la hora tomada y nadie lo iba a llamar.
        //
        // Cubre además 'cancelled' y cualquier estado que Getnet agregue
        // mañana: la cola falla del lado seguro —de más— en vez de perder
        // plata en silencio. Los NULL quedan fuera solos, que es lo correcto:
        // un payment_status nulo significa que esa consulta no cobra en línea.
        $filas = DB::table('citas as c')
            ->join('mascotas as m', 'm.id', '=', 'c.mascota_id')
            ->join('usuarios as u', 'u.id', '=', 'c.usuario_dueno_id')
            ->whereNotNull('c.monto_abono_clp')
            ->where(function ($q) {
                $q->whereNull('c.payment_status')->orWhere('c.payment_status', '<>', 'approved');
            })
            ->whereNotNull('c.payment_status')
            ->where('c.estado', '<>', 'Cancelada')
            // Más antigua primero: son las más cerca de cumplir el plazo de
            // 48 h de la política. El desempate por id es obligatorio (§9.14).
            ->orderBy('c.created_at')->orderBy('c.id')
            ->select('c.*', 'm.nombre as m_nombre', 'm.especie as m_especie',
                     'm.alergias as m_alergias', 'm.id as m_id',
                     'u.nombre as u_nombre', 'u.telefono as u_telefono', 'u.email as u_email')
            ->get();

        $espIds = $filas->pluck('especialista_id')->filter()->unique();
        $esps = $espIds->isEmpty() ? collect() : DB::table('especialistas')
            ->whereIn('id', $espIds)->get()->keyBy('id');

        return $filas->map(fn ($f) => $this->reservaOut($f, $esps))->values();
    }

    /**
     * GET /citas/devoluciones-pendientes — staff, permiso «reservas».
     *
     * Horas PAGADAS que se cancelaron y cuya devolución nadie ha resuelto. La
     * devolución la hace una persona (decisión de la clínica, sep-2026), así
     * que esta cola es lo único que evita que una se olvide: el tutor puede
     * cancelar solo, por el portal o por WhatsApp, sin que nadie del equipo lo vea.
     *
     * Sin columna nueva: lo resuelto queda en `respuestas` («Devolución»), que
     * el panel ya muestra en el detalle. El filtro va en PHP porque las
     * canceladas con pago aprobado son pocas.
     */
    public function devolucionesPendientes()
    {
        $filas = DB::table('citas as c')
            ->join('mascotas as m', 'm.id', '=', 'c.mascota_id')
            ->join('usuarios as u', 'u.id', '=', 'c.usuario_dueno_id')
            ->where('c.estado', 'Cancelada')
            ->where('c.payment_status', 'approved')
            ->orderBy('c.fecha_hora')->orderBy('c.id')
            ->select('c.*', 'm.nombre as m_nombre', 'm.especie as m_especie',
                     'm.alergias as m_alergias', 'm.id as m_id',
                     'u.nombre as u_nombre', 'u.telefono as u_telefono', 'u.email as u_email')
            ->get()
            ->filter(fn ($c) => ! isset((json_decode($c->respuestas ?? '', true) ?: [])['Devolución']));

        $espIds = $filas->pluck('especialista_id')->filter()->unique();
        $esps = $espIds->isEmpty() ? collect() : DB::table('especialistas')
            ->whereIn('id', $espIds)->get()->keyBy('id');

        return $filas->map(fn ($f) => $this->reservaOut($f, $esps) + ['devolucion' => self::devolucionDe($f)])
            ->values();
    }

    /**
     * Cuánto corresponde devolver según la política (PoliticaDevolucion.vue):
     * 60% de lo pagado con 24 horas o más de aviso; nada con menos.
     * `corresponde_clp = null` cuando no se sabe cuándo se canceló (canceladas
     * antes de anotarlo, o desde Google): ahí decide una persona.
     */
    public static function devolucionDe(object $c): array
    {
        $pagado = (int) ($c->pago_modalidad === 'total' && $c->monto_total_clp !== null
            ? $c->monto_total_clp : $c->monto_abono_clp);
        $resp = $c->respuestas ? (json_decode($c->respuestas, true) ?: []) : [];
        $cancelada = $resp['Cancelada el'] ?? null;
        if (! $cancelada) {
            return ['pagado_clp' => $pagado, 'horas_aviso' => null, 'corresponde_clp' => null];
        }
        $horas = (int) floor(CarbonImmutable::parse($cancelada)->diffInMinutes(CarbonImmutable::parse($c->fecha_hora), false) / 60);

        return [
            'pagado_clp' => $pagado,
            'horas_aviso' => $horas,
            'corresponde_clp' => $horas >= 24 ? intdiv($pagado * 60, 100) : 0,
        ];
    }

    /**
     * PATCH /citas/{id}/devolucion — staff, permiso «reservas».
     *
     * Marca la devolución como hecha o como que no corresponde. No mueve
     * dinero: registra qué se decidió, cuándo y quién, y la saca de la cola.
     */
    public function resolverDevolucion(Request $request, string $citaId)
    {
        $datos = $request->validate(['resultado' => 'required|in:devuelta,no_corresponde']);
        $actor = $request->attributes->get('usuario');

        $cita = DB::table('citas')->where('id', $citaId)->first();
        if (! $cita) {
            return response()->json(['detail' => 'Cita no encontrada'], 404);
        }
        if ($cita->estado !== 'Cancelada' || $cita->payment_status !== 'approved') {
            return response()->json(['detail' => 'Solo una hora pagada y cancelada tiene devolución.'], 400);
        }

        $resp = $cita->respuestas ? (json_decode($cita->respuestas, true) ?: []) : [];
        $resp['Devolución'] = ($datos['resultado'] === 'devuelta' ? 'Devuelta' : 'No corresponde')
            . ' · ' . CarbonImmutable::now()->format('d-m-Y H:i') . ' · ' . ($actor->nombre ?? 'Equipo');

        DB::transaction(function () use ($citaId, $resp, $actor, $datos, $cita) {
            DB::table('citas')->where('id', $citaId)->update(['respuestas' => $this->jsonOrdenado($resp)]);
            $this->auditoria->registrar($actor, 'devolucion_resuelta', 'reservas', 'cita', $citaId, [
                'resultado' => $datos['resultado'],
                'calculo' => self::devolucionDe($cita),
            ]);
        });

        return ['ok' => true, 'devolucion' => $resp['Devolución']];
    }

    /** El contrato de `_reserva_out()`. */
    private function reservaOut($f, $esps): array
    {
        $esp = $f->especialista_id ? ($esps[$f->especialista_id] ?? null) : null;
        return [
            'id' => $f->id,
            // `isoformat()` de Python sobre un datetime naive: sin sufijo de
            // zona. Los microsegundos van solo si no son cero, igual que
            // Python — de ahí el rtrim.
            'fecha_hora' => $this->iso($f->fecha_hora),
            'duracion_min' => (int) ($f->duracion_min ?: 30),
            'servicio' => $f->servicio,
            'servicio_id' => $f->servicio_id,
            'especialista' => $esp ? [
                'id' => $esp->id,
                'nombre' => $esp->nombre,
                'especialidad' => $esp->especialidad,
                'emoji' => $esp->emoji,
            ] : null,
            'estado' => $f->estado,
            'notas_pre_cita' => $f->notas_pre_cita,
            'respuestas' => $this->mapa($f->respuestas ? json_decode($f->respuestas, true) : []),
            'monto_abono_clp' => $this->entero($f->monto_abono_clp),
            // El total y la modalidad: sin esto el mostrador no sabe si quien
            // llega ya pagó todo o le queda la mitad por cobrar.
            'monto_total_clp' => $this->entero($f->monto_total_clp),
            'pago_modalidad' => $f->pago_modalidad,
            'payment_status' => $f->payment_status,
            'forma_pago' => $f->forma_pago,
            // Para cuadrar un cobro con el panel de Getnet sin salir del sistema.
            'pago_referencia' => $f->pago_referencia,
            'pago_autorizacion' => $f->pago_autorizacion,
            'created_at' => $this->iso($f->created_at),
            'mascota' => [
                'id' => $f->m_id, 'nombre' => $f->m_nombre,
                'especie' => $f->m_especie, 'alergias' => $f->m_alergias,
            ],
            'dueno' => [
                'nombre' => $f->u_nombre, 'telefono' => $f->u_telefono,
                'email' => $f->u_email,
            ],
        ];
    }

    /**
     * `datetime.isoformat()` de Python.
     *
     * La regla exacta: si los microsegundos son **cero**, se omite la fracción
     * entera (`…T11:00:00`); si no, van los **seis dígitos completos**
     * (`…T18:53:00.780140`).
     *
     * Recortar los ceros de la derecha es lo intuitivo y está MAL: Python
     * escribe `.780140` y un `rtrim` lo deja en `.78014`, que es otro string.
     * MariaDB devuelve siempre los seis, así que solo hay que decidir entre
     * quitarlos todos o dejarlos todos. Lo cazó el arnés en cuatro casos.
     */
    private function iso(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = str_replace(' ', 'T', $v);
        [$base, $frac] = array_pad(explode('.', $v, 2), 2, null);
        if ($frac === null) {
            return $base;
        }
        $frac = substr(str_pad($frac, 6, '0'), 0, 6);
        return $frac === '000000' ? $base : "{$base}.{$frac}";
    }

    /** El contrato de `CitaResponse`. */
    private function citaOut($c): array
    {
        return [
            'id' => $c->id,
            'mascota_id' => $c->mascota_id,
            // Sin sufijo de zona: Pydantic serializa un `datetime` naive como
            // `2026-09-05T11:00:00`. Un `Z` o un `-03:00` acá rompe los
            // frontends aunque el instante sea correcto.
            'fecha_hora' => str_replace(' ', 'T', substr($c->fecha_hora, 0, 19)),
            'servicio' => $c->servicio,
            'servicio_id' => $c->servicio_id,
            'especialista_id' => $c->especialista_id,
            'duracion_min' => $c->duracion_min === null ? null : (int) $c->duracion_min,
            'estado' => $c->estado,
            'notas_pre_cita' => $c->notas_pre_cita,
            // `(object)` cuando queda vacío: `respuestas` es un MAPA, y un
            // array PHP vacío se serializa como `[]` (lista). Python devuelve
            // `{}`. Mismo trap que en /citas/slots — es la tercera vez que
            // aparece, así que conviene tenerlo presente en cada endpoint que
            // devuelva un diccionario.
            'respuestas' => $this->mapa($c->respuestas ? json_decode($c->respuestas, true) : null),
            'monto_abono_clp' => $c->monto_abono_clp === null ? null : (int) $c->monto_abono_clp,
            'payment_status' => $c->payment_status,
            'created_at' => str_replace(' ', 'T', $c->created_at),
            // La regla del plazo de pago la decide el servidor (LinkDePago):
            // el portal la calculaba solo desde `created_at` y no conocía el
            // caso de la hora coordinada, cuyo plazo corre desde el enlace.
            'hora_liberada' => LinkDePago::horaLiberada($c),
            'pagar_hasta' => LinkDePago::pagarHasta($c),
        ];
    }

    /** NULL se conserva; el resto va como entero, nunca como string. */
    private function entero($v): ?int
    {
        return $v === null ? null : (int) $v;
    }

    /** Un mapa vacío es `{}`, no `[]`. null se conserva. */
    private function mapa($v)
    {
        if ($v === null) {
            return null;
        }
        return empty($v) ? (object) [] : $v;
    }

    /** JSON con las claves ordenadas, para que el migrador y esto coincidan. */
    private function jsonOrdenado(array $v): string
    {
        ksort($v);
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /citas/{id}/orden-examen — **la cuarta escritura sin token**, y la
     * única que acepta un archivo.
     *
     * Exámenes de sangre, ecografía y radiología no se realizan sin la
     * indicación del veterinario tratante, y quien reserva no tiene sesión: el
     * `cita_id` es un UUID v4 que solo conoce quien acaba de reservar, mismo
     * criterio que `/pagos`.
     *
     * Lo que impide que sea un depósito de archivos gratis:
     *
     *  · solo citas cuyo servicio tiene `requiere_orden_examen`;
     *  · **una sola orden por cita** — la nueva reemplaza a la anterior y borra
     *    su archivo. Sin esto, un `cita_id` válido permite subir 10 MB por vez
     *    hasta llenar el disco;
     *  · tope de tamaño, lista blanca de extensiones y límite por IP.
     *
     * ⚠️ **El tope de tamaño no corta igual que en Python, y hay que saberlo.**
     * Allá `leer_con_tope()` aborta la LECTURA a los 10 MB y el archivo entero
     * nunca existe. PHP escribe el temporal ANTES de que este método corra, así
     * que el `getSize()` de abajo rechaza algo que el servidor ya guardó. Lo que
     * repone esa mitad es `public/.user.ini` (`upload_max_filesize = 12M`): sin
     * él, y con el default de Hostinger en 256 M, cada petición a este endpoint
     * —que es público— puede hacer escribir 256 MB en /tmp de un hosting
     * compartido antes de ser rechazada.
     *
     * El archivo entra a `documentos_clinicos/` como un documento más de la
     * ficha de la mascota —que es donde el profesional lo va a buscar el día de
     * la atención— con `subido_por_id = NULL`, porque no lo subió nadie del
     * equipo. **Nunca en `uploads/`**, que se sirve estático y sin
     * autenticación (§9.16). Subirla es público; LEERLA no.
     */
    public function subirOrdenExamen(Request $request, string $citaId, OrdenExamen $ordenes)
    {
        $ip = $request->ip();
        if ($this->demasiadosIntentosOrden('orden:' . $ip)) {
            return response()->json(
                ['detail' => 'Demasiados intentos seguidos. Espera unos minutos.'], 429);
        }

        $cita = DB::table('citas')->where('id', $citaId)->first();
        if (! $cita) {
            return response()->json(['detail' => 'Reserva no encontrada'], 404);
        }
        if ($cita->estado === 'Cancelada') {
            return response()->json(['detail' => 'Esta reserva está cancelada.'], 400);
        }

        $servicio = DB::table('servicios')->where('id', $cita->servicio_id)->first();
        if (! $servicio || ! $servicio->requiere_orden_examen) {
            return response()->json(
                ['detail' => 'Esta consulta no requiere orden de examen.'], 400);
        }

        $archivo = $request->file('archivo');
        if (! $archivo || ! $archivo->isValid()) {
            return response()->json(['detail' => 'Falta el archivo.'], 400);
        }
        $ext = strtolower($archivo->getClientOriginalExtension());
        if (! isset(FichasController::EXTENSIONES[$ext])) {
            return response()->json(
                ['detail' => 'Formato no soportado (PDF, DOC/DOCX, TXT, JPG, PNG o WebP).'], 400);
        }
        if ($archivo->getSize() > FichasController::TAMANO_MAX_BYTES) {
            return response()->json(['detail' => 'El archivo no puede superar 10 MB.'], 400);
        }

        return $ordenes->guardar($cita, $servicio, $ext, (string) $archivo->getClientOriginalName(),
            fn (string $dir, string $nombre) => $archivo->move($dir, $nombre));
    }

    /**
     * POST /citas/{id}/link-pago — staff, permiso «reservas».
     *
     * El enlace de pago de una hora que ya existe, para mandárselo al tutor
     * (típicamente una del mostrador, que nace sin monto). Las reglas viven en
     * LinkDePago, que también usa el asistente de WhatsApp.
     */
    public function linkPago(Request $request, string $citaId, LinkDePago $links)
    {
        $r = $links->generar($citaId);
        if (isset($r['error'])) {
            return response()->json(['detail' => $r['error']],
                DB::table('citas')->where('id', $citaId)->exists() ? 400 : 404);
        }
        if ($r['congelado']) {
            $this->auditoria->registrar($request->attributes->get('usuario'), 'link_pago_generado',
                'reservas', 'cita', $citaId, ['monto_abono' => $r['monto_abono_clp']]);
        }

        return $r;
    }

    /**
     * Tope por IP con la caché, no en memoria del proceso.
     *
     * Python lo lleva en memoria (`rate_limit.py`), pero en hosting compartido
     * hay varios procesos PHP y uno en memoria no vería los intentos de los
     * otros: el tope efectivo sería N veces más alto de lo que dice. Mismo
     * criterio que `ContactoController`.
     */
    private function demasiadosIntentosOrden(string $clave): bool
    {
        return LimitePorIP::demasiados(
            $clave, self::ORDEN_MAX_POR_IP, self::ORDEN_VENTANA_SEG);
    }
}
