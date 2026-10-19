<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El evento espejo de una cita en el Google Calendar de la clínica.
 *
 * **Falla ABIERTO, siempre** (§4). Si Google no responde, se traga la
 * excepción y sigue: la reserva ya está guardada y no puede caerse porque un
 * servicio externo esté caído. Es la regla más importante de este archivo —
 * romperla convierte un corte de Google en una caída del portal de reservas,
 * que es exactamente lo que pasó con `_token()` en Python.
 *
 * Lo que compara el modo C del arnés es **qué evento se construye** —título,
 * descripción, horas, zona horaria e invitados—; el transporte va aparte, en los
 * cuatro métodos `protected` del final, justo para que el doble pueda sustituirlo
 * sin tocar esa construcción.
 *
 * `GET /events/{id}` (`obtenerEvento`) es el único que no crea nada: lo usa la
 * sincronización para enterarse de lo que cambió **en Google** —un evento
 * borrado a mano, una hora movida— y traerlo de vuelta.
 */
class GoogleCalendar
{
    private const API = 'https://www.googleapis.com/calendar/v3';

    /** Seis segundos: si Google tarda más, se falla abierto y se sigue. */
    private const TIMEOUT = 6;

    public function __construct(
        private CitasEmail $emails,
        private GoogleOAuth $oauth,
    )
    {
    }

    /**
     * Cuenta y calendario GENERAL de la clínica (`ajustes.calendario_reservas`).
     * Devuelve `[cuenta, calendarioId]` o `[null, null]`.
     *
     * Es el calendario de quien no tiene uno propio y el de lo que afecta a
     * todos (un feriado). Para escribir el evento de una cita no se usa este:
     * se usa `calendarioDe()` o `calendarioDeCita()`.
     */
    public function calendarioClinica(): array
    {
        $cuenta = $this->cuentaClinica();
        $ajustes = DB::table('ajustes')->where('id', 1)->first();
        if (! $cuenta || ! ($ajustes->calendario_reservas ?? null)) {
            return [null, null];
        }

        return [$cuenta, $ajustes->calendario_reservas];
    }

    /**
     * La cuenta de Google conectada, con o sin calendario general elegido.
     *
     * Aparte de `calendarioClinica()` porque un especialista con calendario
     * propio no depende de que el general esté elegido: la cuenta es una sola
     * y es dueña de todos los calendarios.
     */
    public function cuentaClinica(): ?object
    {
        $ajustes = DB::table('ajustes')->where('id', 1)->first();
        if (! ($ajustes->google_account_id ?? null)) {
            return null;
        }

        return DB::table('google_accounts')->where('id', $ajustes->google_account_id)->first();
    }

    /**
     * Dónde van las reservas NUEVAS de un especialista: su calendario propio
     * (`especialistas.google_calendar_id`) o, si no tiene, el de la clínica.
     * Devuelve `[cuenta, calendarioId]` o `[null, null]`.
     */
    public function calendarioDe(?object $especialista): array
    {
        $propio = $especialista->google_calendar_id ?? null;
        if ($propio) {
            $cuenta = $this->cuentaClinica();
            return $cuenta ? [$cuenta, $propio] : [null, null];
        }

        return $this->calendarioClinica();
    }

    /**
     * Dónde está el evento de una cita YA creada: el calendario anotado en la
     * propia cita (`citas.google_calendar_id`), nunca el que tenga hoy su
     * especialista.
     *
     * Esa diferencia es lo que hace seguro cambiarle el calendario a alguien:
     * si se recalculara, mover o borrar una cita vieja buscaría el evento en el
     * calendario nuevo, Google contestaría 404, y la sincronización lo leería
     * como «lo borraron» y cancelaría la cita con correo al tutor. Sin dato
     * (citas anteriores a la columna), el de la clínica, que es donde estaban
     * todas.
     */
    public function calendarioDeCita(object $cita): array
    {
        $anotado = $cita->google_calendar_id ?? null;
        if ($anotado) {
            $cuenta = $this->cuentaClinica();
            return $cuenta ? [$cuenta, $anotado] : [null, null];
        }

        return $this->calendarioClinica();
    }

    /**
     * Título, descripción y ubicación del evento. Mismo contenido al crear y
     * al reagendar, así el evento en Google nunca queda desactualizado
     * respecto de la cita real.
     */
    public function datosEvento(object $cita, object $usuario, object $mascota,
                                ?object $especialista): array
    {
        $especie = $mascota->especie ? " ({$mascota->especie})" : '';
        $nombreEsp = $especialista->nombre ?? '';

        // Mascota y servicio PRIMERO: en la vista mensual de Google la celda es
        // angosta y corta el título, así que lo más útil para reconocer la cita
        // de un vistazo va al inicio.
        $titulo = "{$mascota->nombre} ({$cita->servicio}) · {$nombreEsp}{$especie} · {$usuario->nombre}";

        $lineas = [
            'Reserva ' . Marca::nombre(),
            "Especialista: {$nombreEsp}",
            "Servicio: {$cita->servicio}",
            "Mascota: {$mascota->nombre}{$especie}",
            "Dueño: {$usuario->nombre} · " . ($usuario->telefono ?: 'sin teléfono')
                . " · {$usuario->email}",
        ];

        $respuestas = $cita->respuestas ? (json_decode($cita->respuestas, true) ?: []) : [];
        if ($respuestas) {
            $lineas[] = '';
            foreach ($respuestas as $k => $v) {
                if ($v) {
                    $lineas[] = "{$k}: {$v}";
                }
            }
        }
        $lineas[] = '';
        $lineas[] = 'Notas: ' . ($cita->notas_pre_cita ?: '-');

        // La vía que de verdad reagenda. Nació cuando el tutor iba invitado y
        // los botones RSVP de Google («No puedo») no cancelaban nada acá; desde
        // el 14-sep ya no lo está, y la línea se deja porque el evento se
        // reenvía y porque Python la emite igual (el arnés compara la
        // descripción).
        $portal = config('emr.portal_reservas_url');
        $tel = config('emr.clinica_telefono');
        $lineas[] = '';
        $lineas[] = "¿Necesitas otra hora? Reagenda en {$portal}/citas "
            . "o escríbenos por WhatsApp al {$tel}.";

        $ajustes = DB::table('ajustes')->where('id', 1)->first();
        $data = $ajustes && $ajustes->data ? (json_decode($ajustes->data, true) ?: []) : [];
        $direccion = $data['wording']['global'] ?? null;

        return [$titulo, implode("\n", $lineas), $direccion];
    }

    /** Crea el evento de una cita recién reservada. Nunca lanza. */
    public function crearEventoDeCita(object $cita, object $usuario, object $mascota,
                                      ?object $especialista): ?string
    {
        try {
            [$cuenta, $calendarioId] = $this->calendarioDe($especialista);
            if (! $cuenta) {
                return null;
            }

            [$titulo, $descripcion, $direccion] = $this->datosEvento(
                $cita, $usuario, $mascota, $especialista);

            $inicio = CarbonImmutable::parse($cita->fecha_hora);
            $fin = $inicio->addMinutes((int) ($cita->duracion_min ?: 30));

            // Solo el especialista queda invitado: así recibe de Google la
            // invitación con su botón de aceptar y su recordatorio. Se pregunta
            // con LA MISMA función que decide los correos, para que no pueda
            // recibir la invitación sin el aviso (o al revés).
            //
            // El tutor NO va invitado (14-sep-2026). Lo estuvo, y cada reserva
            // le llegaba dos veces: nuestro correo en español y la invitación
            // de Google en el idioma de SU cuenta —en la prueba, inglés— con el
            // remitente de la cuenta de Google y no el de la clínica. La API no
            // deja elegir ese idioma. Al tutor le escribe solo el sistema, y
            // cuando la hora se mueve o se borra EN Google le avisa
            // `GoogleSincronizar`, que antes callaba porque contaba con Google.
            $correoEsp = $this->emails->correoEspecialista($especialista);

            $eventoId = $this->crearEvento($cuenta, $calendarioId, [
                'titulo' => $titulo,
                'descripcion' => $descripcion,
                // Las horas son lo que el especialista ve BLOQUEADO en su
                // agenda. `fecha_hora` está en hora de la clínica, no en UTC
                // (§9.6): convertirla acá correría el evento cuatro horas.
                'inicio' => $inicio->format('Y-m-d H:i:s'),
                'fin' => $fin->format('Y-m-d H:i:s'),
                'tz' => $especialista->zona_horaria ?? 'America/Santiago',
                'ubicacion' => $direccion,
                'attendee' => null,
                'invitados' => $correoEsp ? [$correoEsp] : null,
            ]);

            if ($eventoId) {
                // El calendario va con el id, en la misma escritura: un id de
                // evento sin su calendario es justo lo que deja citas que nadie
                // puede volver a encontrar (ver `calendarioDeCita`).
                DB::table('citas')->where('id', $cita->id)->update([
                    'google_event_id' => $eventoId,
                    'google_calendar_id' => $calendarioId,
                ]);
            }

            return $eventoId;
        } catch (\Throwable $e) {
            // FALLA ABIERTO: la cita ya está guardada.
            Log::warning('Google Calendar (crear evento, cita ' . $cita->id . '): '
                . $e->getMessage());
            return null;
        }
    }

    /**
     * Mueve el evento a la hora nueva. **ACTUALIZA, no crea otro**: crear uno
     * nuevo dejaría la hora vieja y la nueva juntas en el calendario. Nunca
     * lanza.
     */
    public function actualizarEventoDeCita(object $cita, object $usuario, object $mascota,
                                           ?object $especialista): bool
    {
        try {
            [$cuenta, $calendarioId] = $this->calendarioDeCita($cita);
            if (! $cuenta || ! $cita->google_event_id) {
                return false;
            }

            [$titulo, $descripcion, $direccion] = $this->datosEvento(
                $cita, $usuario, $mascota, $especialista);
            $inicio = CarbonImmutable::parse($cita->fecha_hora);
            $fin = $inicio->addMinutes((int) ($cita->duracion_min ?: 30));

            return $this->actualizarEvento($cuenta, $calendarioId, $cita->google_event_id, [
                'titulo' => $titulo,
                'descripcion' => $descripcion,
                'inicio' => $inicio->format('Y-m-d H:i:s'),
                'fin' => $fin->format('Y-m-d H:i:s'),
                'tz' => $especialista->zona_horaria ?? 'America/Santiago',
                'ubicacion' => $direccion,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Google Calendar (actualizar, cita ' . $cita->id . '): ' . $e->getMessage());
            return false;
        }
    }

    /** Borra el evento espejo de una cita cancelada. Nunca lanza. */
    public function eliminarEventoDeCita(object $cita): bool
    {
        try {
            [$cuenta, $calendarioId] = $this->calendarioDeCita($cita);
            if (! $cuenta || ! $cita->google_event_id) {
                return false;
            }

            return $this->eliminarEvento($cuenta, $calendarioId, $cita->google_event_id);
        } catch (\Throwable $e) {
            Log::warning('Google Calendar (eliminar, cita ' . $cita->id . '): ' . $e->getMessage());
            return false;
        }
    }

    protected function actualizarEvento(object $cuenta, string $calendarioId,
                                        string $eventoId, array $evento): bool
    {
        if (RegistroEfectos::activo()) {
            RegistroEfectos::anotar('google_actualizar',
                ['calendario' => $calendarioId, 'evento_id' => $eventoId] + $evento);
            return true;
        }

        $t = $this->oauth->token($cuenta);
        if (! $t) {
            return false;
        }
        [$cuerpo, $asistentes] = $this->cuerpoEvento($evento);
        try {
            $r = Http::withToken($t)->timeout(self::TIMEOUT)
                ->patch($this->url($calendarioId, $eventoId)
                    . ($asistentes ? '?sendUpdates=all' : ''), $cuerpo);

            return $r->successful();
        } catch (\Throwable $e) {
            Log::warning('Google Calendar (actualizar): ' . $e->getMessage());
            return false;
        }
    }

    protected function eliminarEvento(object $cuenta, string $calendarioId,
                                      string $eventoId): bool
    {
        if (RegistroEfectos::activo()) {
            RegistroEfectos::anotar('google_eliminar',
                ['calendario' => $calendarioId, 'evento_id' => $eventoId]);
            return true;
        }

        $t = $this->oauth->token($cuenta);
        if (! $t) {
            return false;
        }
        try {
            $r = Http::withToken($t)->timeout(self::TIMEOUT)
                ->delete($this->url($calendarioId, $eventoId));

            // 404 y 410 cuentan como ÉXITO: si el evento ya no está en Google,
            // el resultado que se buscaba ya está logrado.
            return $r->successful() || in_array($r->status(), [404, 410], true);
        } catch (\Throwable $e) {
            Log::warning('Google Calendar (eliminar): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Crea el evento y devuelve su id. `null` si no se pudo — **nunca lanza**.
     *
     * Está aparte para que el arnés pueda sustituirlo por un doble sin tocar la
     * construcción del evento, que es lo que de verdad se compara.
     */
    protected function crearEvento(object $cuenta, string $calendarioId, array $evento): ?string
    {
        if (RegistroEfectos::activo()) {
            RegistroEfectos::anotar('google', ['calendario' => $calendarioId] + $evento);
            return 'evento-doble-123';
        }

        $t = $this->oauth->token($cuenta);
        if (! $t) {
            return null;
        }
        [$cuerpo, $asistentes] = $this->cuerpoEvento($evento);
        try {
            $r = Http::withToken($t)->timeout(self::TIMEOUT)
                ->post($this->url($calendarioId) . ($asistentes ? '?sendUpdates=all' : ''),
                    $cuerpo);

            return $r->successful() ? ($r->json()['id'] ?? null) : null;
        } catch (\Throwable $e) {
            Log::warning('Google Calendar (crear): ' . $e->getMessage());
            return null;
        }
    }

    /**
     * El estado actual de un evento en Google.
     *
     * Tres respuestas distintas y las tres importan:
     *   · `null` — no se pudo consultar (red, token). **Falla abierto**: quien
     *     llama no toca nada y reintenta en el ciclo siguiente. Confundirlo con
     *     «no existe» cancelaría citas buenas cada vez que Google tosa.
     *   · `['existe' => false]` — Google confirma que ya no está.
     *   · `['existe' => true, 'inicio' => …, 'fin' => …]`.
     */
    public function obtenerEvento(object $cuenta, string $calendarioId,
                                  string $eventoId): ?array
    {
        $t = $this->oauth->token($cuenta);
        if (! $t) {
            return null;
        }
        try {
            $r = Http::withToken($t)->timeout(self::TIMEOUT)
                ->get($this->url($calendarioId, $eventoId));
        } catch (\Throwable $e) {
            Log::warning('Google Calendar (obtener): ' . $e->getMessage());
            return null;
        }

        if (in_array($r->status(), [404, 410], true)) {
            return ['existe' => false];
        }
        if (! $r->successful()) {
            return null;
        }
        $data = $r->json();
        // Un evento «cancelled» sigue devolviendo 200: para Google es una fila
        // que existe. Para nosotros no.
        if (($data['status'] ?? null) === 'cancelled') {
            return ['existe' => false];
        }

        return [
            'existe' => true,
            'inicio' => $data['start']['dateTime'] ?? null,
            'fin' => $data['end']['dateTime'] ?? null,
        ];
    }

    /**
     * ¿La cuenta conectada puede leer este calendario?
     *
     * Solo `true` con un 200. Existe porque Google contesta 404 a un evento de
     * un calendario que la cuenta no ve, igual que a uno borrado: sin esta
     * pregunta previa, la sincronización leería «cuenta sin acceso» como «todas
     * las citas fueron canceladas». Cualquier otra respuesta —403, 404, red— es
     * `false`: la sincronización no hace nada y reintenta en el ciclo siguiente.
     */
    public function calendarioAccesible(object $cuenta, string $calendarioId): bool
    {
        $t = $this->oauth->token($cuenta);
        if (! $t) {
            return false;
        }
        try {
            return Http::withToken($t)->timeout(self::TIMEOUT)
                ->get(self::API . '/calendars/' . rawurlencode($calendarioId))
                ->successful();
        } catch (\Throwable $e) {
            Log::warning('Google Calendar (acceso al calendario): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * ¿La cuenta conectada puede CREAR eventos en este calendario?
     *
     * `calendarioAccesible` solo pregunta si lo ve, y un calendario compartido
     * «ver todos los detalles» se ve pero no se escribe: asignarlo como
     * calendario de reservas dejaría cada reserva sin evento, en silencio.
     * `null` = no se pudo preguntar (red, token); quien llama decide.
     */
    public function puedeEscribir(object $cuenta, string $calendarioId): ?bool
    {
        $t = $this->oauth->token($cuenta);
        if (! $t) {
            return null;
        }
        try {
            $r = Http::withToken($t)->timeout(self::TIMEOUT)
                ->get(self::API . '/users/me/calendarList/' . rawurlencode($calendarioId));
        } catch (\Throwable $e) {
            Log::warning('Google Calendar (permisos del calendario): ' . $e->getMessage());
            return null;
        }
        if (in_array($r->status(), [403, 404], true)) {
            return false;
        }
        if (! $r->successful()) {
            return null;
        }

        return in_array($r->json()['accessRole'] ?? '', ['owner', 'writer'], true);
    }

    /**
     * Crea un calendario nuevo en la cuenta de la clínica y devuelve su id.
     * `null` si Google no lo creó. Google limita cuántos calendarios crea una
     * cuenta por día: si se arman varios, de a pocos.
     */
    public function crearCalendario(object $cuenta, string $nombre, string $tz): ?string
    {
        $t = $this->oauth->token($cuenta);
        if (! $t) {
            return null;
        }
        try {
            $r = Http::withToken($t)->timeout(self::TIMEOUT)
                ->post(self::API . '/calendars', ['summary' => $nombre, 'timeZone' => $tz]);

            return $r->successful() ? ($r->json()['id'] ?? null) : null;
        } catch (\Throwable $e) {
            Log::warning('Google Calendar (crear calendario): ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Comparte el calendario con una persona, **solo lectura** (`reader` =
     * «ver todos los detalles» en Google).
     *
     * Solo lectura a propósito: borrar un evento en Google CANCELA la cita real
     * y le escribe al tutor (`GoogleSincronizar`). Con permiso de edición,
     * cualquiera con el calendario en su teléfono podría hacerlo sin querer.
     * Google le manda a esa persona el aviso de que se lo compartieron.
     */
    public function compartirCalendario(object $cuenta, string $calendarioId, string $correo): bool
    {
        $t = $this->oauth->token($cuenta);
        if (! $t) {
            return false;
        }
        try {
            return Http::withToken($t)->timeout(self::TIMEOUT)
                ->post(self::API . '/calendars/' . rawurlencode($calendarioId) . '/acl', [
                    'role' => 'reader',
                    'scope' => ['type' => 'user', 'value' => $correo],
                ])->successful();
        } catch (\Throwable $e) {
            Log::warning('Google Calendar (compartir calendario): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Los eventos del calendario de la clínica, en hora local.
     *
     * Devuelve `[inicio, fin, texto]`. El TEXTO hace falta y no es adorno: ese
     * calendario es uno solo para toda la clínica, pero hay **2 box en
     * paralelo**, así que un evento de otro especialista no puede tapar la
     * agenda de este. Google no dice de quién es cada evento; se resuelve
     * leyendo el título y la descripción (ver `SlotsService`).
     *
     * Los eventos de **día completo se ignoran a propósito**: en este
     * calendario suelen ser recordatorios o cumpleaños, y tomarlos como
     * «ocupado» borraría el día entero.
     *
     * Falla abierto: ante cualquier problema devuelve `[]`, que significa «no
     * bloquea nada». Es la regla de toda esta integración —`/citas/slots` es
     * PÚBLICO y un corte de Google no puede dejar la página de reservas sin
     * horarios— y es exactamente lo que se rompió una vez en Python.
     *
     * @return array<array{0:string,1:string,2:string}>
     */
    public function eventosOcupados(object $cuenta, string $calendarioId,
                                    string $desde, string $hasta, string $tz): array
    {
        if (! $calendarioId) {
            return [];
        }

        // Caché corta: `/citas/slots` es público y se llama en cada cambio de
        // semana; sin esto cada visitante dispara una llamada a Google.
        $clave = 'google:eventos:' . md5($calendarioId . $desde . $hasta . $tz);
        $guardado = cache()->get($clave);
        if (is_array($guardado)) {
            return $guardado;
        }

        $t = $this->oauth->token($cuenta);
        if (! $t) {
            return [];
        }
        try {
            $r = Http::withToken($t)->timeout(self::TIMEOUT)
                ->get($this->url($calendarioId), [
                    'timeMin' => $this->aUtc($desde, $tz),
                    'timeMax' => $this->aUtc($hasta, $tz),
                    'singleEvents' => 'true',   // expande las series repetidas
                    'orderBy' => 'startTime',
                    'maxResults' => 2500,
                ]);
            if (! $r->successful()) {
                return [];
            }
        } catch (\Throwable $e) {
            Log::warning('Google Calendar (eventos): ' . $e->getMessage());
            return [];
        }

        $eventos = [];
        foreach ($r->json()['items'] ?? [] as $ev) {
            if (($ev['status'] ?? null) === 'cancelled') {
                continue;
            }
            if (($ev['transparency'] ?? null) === 'transparent') {
                continue;   // marcado «disponible»
            }
            $ini = $ev['start']['dateTime'] ?? null;
            $fin = $ev['end']['dateTime'] ?? null;
            if (! $ini || ! $fin) {
                continue;   // día completo
            }
            $eventos[] = [
                $this->aLocal($ini, $tz), $this->aLocal($fin, $tz),
                ($ev['summary'] ?? '') . "\n" . ($ev['description'] ?? ''),
            ];
        }

        cache()->put($clave, $eventos, 60);
        return $eventos;
    }

    /**
     * Los bloques ocupados de OTROS calendarios, vía `freeBusy`.
     *
     * Se usa solo cuando el especialista declara calendarios de conflicto
     * propios (`disponibilidad_modo = 'especificos'`): ahí no interesa qué es
     * cada evento, solo que la hora está tomada. Falla abierto igual.
     *
     * @return array<array{0:string,1:string}>
     */
    public function ocupadosFreebusy(object $cuenta, array $calendarioIds,
                                     string $desde, string $hasta, string $tz): array
    {
        if (! $calendarioIds) {
            return [];
        }
        $t = $this->oauth->token($cuenta);
        if (! $t) {
            return [];
        }
        try {
            $r = Http::withToken($t)->timeout(self::TIMEOUT)
                ->post(self::API . '/freeBusy', [
                    'timeMin' => $this->aUtc($desde, $tz),
                    'timeMax' => $this->aUtc($hasta, $tz),
                    'timeZone' => $tz,
                    'items' => array_map(fn ($id) => ['id' => $id], $calendarioIds),
                ]);
            if (! $r->successful()) {
                return [];
            }
        } catch (\Throwable $e) {
            Log::warning('Google Calendar (freeBusy): ' . $e->getMessage());
            return [];
        }

        $bloques = [];
        foreach ($r->json()['calendars'] ?? [] as $cal) {
            foreach ($cal['busy'] ?? [] as $b) {
                $bloques[] = [$this->aLocal($b['start'], $tz), $this->aLocal($b['end'], $tz)];
            }
        }
        return $bloques;
    }

    /** Hora local de la clínica → instante UTC en ISO, que es lo que pide Google. */
    private function aUtc(string $local, string $tz): string
    {
        return CarbonImmutable::parse($local, $tz)->setTimezone('UTC')->toIso8601String();
    }

    /**
     * Lo que devuelve Google (ISO con offset) → hora local SIN zona.
     *
     * Sin zona porque así se guarda todo en este sistema (§9.6). Convertir a la
     * zona de la clínica antes de soltar el offset es justamente el paso que no
     * se puede saltar: quedarse con la parte literal de un ISO en otra zona
     * corre el bloqueo de hora.
     */
    private function aLocal(string $iso, string $tz): string
    {
        return CarbonImmutable::parse($iso)->setTimezone($tz)->format('Y-m-d H:i:s');
    }

    /** La URL de la colección de eventos, o la de uno concreto. */
    private function url(string $calendarioId, ?string $eventoId = null): string
    {
        $u = self::API . '/calendars/' . rawurlencode($calendarioId) . '/events';

        return $eventoId ? $u . '/' . rawurlencode($eventoId) : $u;
    }

    /**
     * Traduce el evento del port al JSON de Google.
     *
     * @return array{0: array<string,mixed>, 1: bool} cuerpo, y si lleva invitados
     */
    private function cuerpoEvento(array $evento): array
    {
        $cuerpo = [
            'summary' => $evento['titulo'],
            'description' => $evento['descripcion'],
            // Con `timeZone` explícito y la hora SIN offset: `fecha_hora` está
            // en hora de la clínica (§9.6), así que mandarla como UTC correría
            // el evento cuatro horas en la agenda del especialista.
            'start' => ['dateTime' => str_replace(' ', 'T', $evento['inicio']),
                        'timeZone' => $evento['tz']],
            'end' => ['dateTime' => str_replace(' ', 'T', $evento['fin']),
                      'timeZone' => $evento['tz']],
        ];
        if (! empty($evento['ubicacion'])) {
            $cuerpo['location'] = $evento['ubicacion'];
        }

        // Tutor y especialista, sin repetidos: Google trata dos direcciones que
        // solo difieren en mayúsculas como dos invitados y le manda dos correos
        // a la misma persona. Se conserva el orden para que el tutor quede
        // primero, que es como se lee la lista en el evento.
        $asistentes = [];
        $vistos = [];
        foreach (array_merge([$evento['attendee'] ?? null], $evento['invitados'] ?? []) as $c) {
            $limpio = trim((string) $c);
            $clave = mb_strtolower($limpio);
            if ($limpio !== '' && ! in_array($clave, $vistos, true)) {
                $vistos[] = $clave;
                $asistentes[] = ['email' => $limpio];
            }
        }
        if ($asistentes) {
            $cuerpo['attendees'] = $asistentes;
        }

        return [$cuerpo, (bool) $asistentes];
    }
}
