<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El estado de las tres piezas externas que fallan EN SILENCIO (decisión de
 * producto, 05-oct): WhatsApp, Google y la IA. Las tres degradan sin romper nada
 * visible, que es justo lo que las hace peligrosas:
 *
 * - WhatsApp con el token vencido (código 190) RECIBE los mensajes, la IA los
 *   contesta, y nada le llega al cliente.
 * - Google con la cuenta sin acceso al calendario detiene la sincronización
 *   cada 5 minutos y solo lo dice el log del servidor (producción, 2-oct).
 * - La IA sin cupo o sin saldo pasa a la capa sin IA y el bot «entiende menos»
 *   sin que nadie sepa por qué (lab, 05-oct, 20:01).
 *
 * Cada pieza devuelve `estado`: `ok`, `aviso` (funciona pero va a fallar o
 * algo anda raro), `error` (no funciona) o `apagado` (no está configurado o
 * se apagó a propósito: no es una alerta). Más `resumen`, `detalle` y
 * `que_hacer`, escritos para quien administra la clínica, no para quien lee
 * el código.
 *
 * SONDEAR O NO. Con `$sondear` se le pregunta de verdad a cada servicio
 * (Meta, Google, Anthropic): unas tres llamadas HTTP gratuitas, que no gastan
 * tokens. Sin sondear se usa lo último sondeado más lo que dejaron anotado los
 * propios envíos y la sincronización. El cron (`estado:revisar`) sondea cada
 * 15 minutos; el panel lee lo guardado y sondea solo con «Revisar ahora».
 */
class EstadoSistema
{
    /** Lo último sondeado, para el panel y para comparar en la próxima vuelta. */
    public const CLAVE = 'estado-sistema:ultimo';

    /** Lo que dejan anotado los servicios al fallar (y al volver a funcionar). */
    public const GOOGLE_ERROR = 'google:ultimo-error';
    public const GOOGLE_SINCRONIZACION = 'google:sincronizacion';
    public const IA_ERROR = 'asistente:ultimo-error';
    public const IA_OK = 'asistente:ultimo-ok';

    private const TIMEOUT = 8;

    public function __construct(private WhatsappEnvio $envio, private GoogleOAuth $oauth, private GoogleCalendar $google)
    {
    }

    /** `['revisado_at', 'peor', 'servicios' => [...]]`. Nunca lanza. */
    public function revisar(bool $sondear = false): array
    {
        $anterior = Cache::get(self::CLAVE) ?: [];
        $previos = collect($anterior['servicios'] ?? [])->keyBy('id')->all();

        $servicios = [];
        foreach (['whatsapp' => 'WhatsApp', 'google' => 'Google Calendar', 'ia' => 'Asistente IA (Claude)'] as $id => $nombre) {
            try {
                $s = match ($id) {
                    'whatsapp' => $this->whatsapp($sondear, $previos[$id] ?? null),
                    'google' => $this->googleEstado($sondear, $previos[$id] ?? null),
                    'ia' => $this->ia($sondear, $previos[$id] ?? null),
                };
            } catch (\Throwable $e) {
                Log::warning("estado del sistema: no se pudo revisar {$id}: " . $e->getMessage());
                $s = self::pieza('aviso', 'No se pudo revisar', 'La revisión misma falló: ' . mb_substr($e->getMessage(), 0, 160));
            }
            $servicios[] = ['id' => $id, 'nombre' => $nombre] + $s;
        }

        $r = [
            'revisado_at' => now()->toIso8601String(),
            'sondeado' => $sondear,
            'peor' => self::peor(array_column($servicios, 'estado')),
            'servicios' => $servicios,
        ];
        if ($sondear) {
            try {
                Cache::forever(self::CLAVE, $r);
            } catch (\Throwable) {
            }
        }

        return $r;
    }

    // ── WhatsApp ──────────────────────────────────────────────────────────

    private function whatsapp(bool $sondear, ?array $previo): array
    {
        if (! $this->envio->habilitado()) {
            return self::pieza('apagado', 'Sin credenciales', 'Faltan WHATSAPP_ACCESS_TOKEN o WHATSAPP_PHONE_NUMBER_ID en el servidor: el bot no envía nada.');
        }
        // El último rechazo de Meta al ENVIAR. Lo anota WhatsappEnvio y lo
        // borra el primer envío que sale bien.
        $rechazo = Cache::get(WhatsappEnvio::CLAVE_ERROR);
        if ($rechazo && ($rechazo['codigo'] ?? null) === 190) {
            return self::pieza('error', 'Token vencido: el bot no puede responder', $rechazo['explicacion'],
                'Genera un token nuevo en Meta (Configuración del negocio → Usuarios del sistema → «Asistente» → Generar token) y cárgalo en el servidor como WHATSAPP_ACCESS_TOKEN.',
                $rechazo['at'] ?? null);
        }

        if (! $sondear) {
            return $previo ? self::sinId($previo) : self::pieza('aviso', 'Sin revisar todavía', 'Toca «Revisar ahora».');
        }
        $r = Http::withToken((string) config('emr.whatsapp_access_token'))->timeout(self::TIMEOUT)
            ->get('https://graph.facebook.com/' . config('emr.whatsapp_graph_version') . '/'
                . config('emr.whatsapp_phone_number_id'), ['fields' => 'display_phone_number,verified_name,quality_rating']);
        if ($r->successful()) {
            $numero = $r->json('display_phone_number');
            $calidad = $r->json('quality_rating');
            if ($rechazo) {
                return self::pieza('aviso', 'Conectado, con un envío rechazado', $rechazo['explicacion'], '', $rechazo['at'] ?? null);
            }
            if ($calidad === 'RED') {
                return self::pieza('aviso', "Conectado ({$numero}), calidad en rojo",
                    'Meta marcó la calidad del número en rojo (muchos bloqueos o reportes). Si sigue así, limita los envíos.');
            }

            return self::pieza('ok', "Conectado ({$numero})", 'El token es válido y Meta responde.');
        }
        $codigo = (int) $r->json('error.code', 0);

        return self::pieza('error', $codigo === 190 ? 'Token vencido: el bot no puede responder' : 'Meta rechaza la conexión',
            WhatsappEnvio::explicarError($codigo),
            $codigo === 190
                ? 'Genera un token nuevo en Meta (Configuración del negocio → Usuarios del sistema → «Asistente» → Generar token) y cárgalo en el servidor como WHATSAPP_ACCESS_TOKEN.'
                : 'Revisa en Meta que la cuenta de WhatsApp y el número sigan activos.');
    }

    // ── Google ────────────────────────────────────────────────────────────

    private function googleEstado(bool $sondear, ?array $previo): array
    {
        if (! $this->oauth->estaConfigurado()) {
            return self::pieza('apagado', 'Sin configurar', 'Faltan las credenciales de Google en el servidor.');
        }
        [$cuenta, $calendario] = $this->google->calendarioClinica();
        if (! $cuenta || ! $calendario) {
            return self::pieza('aviso', 'Sin calendario conectado',
                'Las reservas se guardan, pero no llegan a ningún calendario de Google.',
                'Conecta la cuenta de la clínica en Config → Integraciones y elige el calendario de reservas.');
        }
        $correo = (string) ($cuenta->email ?? 'la cuenta conectada');
        $reconectar = "Reconecta Google en Config → Integraciones con la cuenta que administra el calendario ({$correo}).";

        // Lo que vio la sincronización (cada 5 min): es la señal que más
        // importa, porque una sincronización detenida no tumba nada visible.
        $sinc = Cache::get(self::GOOGLE_SINCRONIZACION);
        if ($sinc && ! ($sinc['ok'] ?? true)) {
            return self::pieza('error', 'Sincronización detenida', $sinc['motivo'] ?? 'La sincronización se detuvo.', $reconectar, $sinc['at'] ?? null);
        }

        if (! $sondear) {
            return $previo ? self::sinId($previo) : self::pieza('aviso', 'Sin revisar todavía', 'Toca «Revisar ahora».');
        }
        if (! $this->oauth->token($cuenta)) {
            $e = Cache::get(self::GOOGLE_ERROR);

            return self::pieza('error', 'No se pudo renovar el acceso',
                ($e['error'] ?? '') === 'invalid_grant'
                    ? 'Google revocó el permiso de la cuenta (se cambió la clave, se quitó el acceso o venció).'
                    : 'Google no entregó un acceso nuevo para la cuenta conectada.',
                $reconectar, $e['at'] ?? null);
        }
        if (! $this->google->calendarioAccesible($cuenta, $calendario)) {
            return self::pieza('error', 'La cuenta no ve el calendario',
                "La cuenta {$correo} no tiene acceso al calendario de reservas: las reservas no llegan a Google y la sincronización se detiene.",
                $reconectar);
        }

        // Los calendarios propios de cada especialista viven en la misma
        // cuenta, pero se pueden borrar o dejar de compartir uno por uno: el
        // general respondiendo no dice nada de ellos.
        $propios = [];
        try {
            $propios = DB::table('especialistas')->where('activo', true)
                ->whereNotNull('google_calendar_id')->where('google_calendar_id', '!=', $calendario)
                ->orderBy('nombre')->orderBy('id')->get(['nombre', 'google_calendar_id']);
        } catch (\Throwable) {
            // Sin la columna (migración pendiente) no hay calendarios propios.
        }
        $sinAcceso = [];
        foreach ($propios as $p) {
            if (! $this->google->calendarioAccesible($cuenta, $p->google_calendar_id)) {
                $sinAcceso[] = $p->nombre;
            }
        }
        if ($sinAcceso) {
            return self::pieza('error', 'La cuenta no ve el calendario de ' . implode(', ', $sinAcceso),
                "La cuenta {$correo} no tiene acceso a ese calendario: sus reservas nuevas no llegan a Google y su sincronización se detiene.",
                'En la ficha del especialista, vuelve a elegirle un calendario (o créale uno nuevo); sus citas ya agendadas no se pierden.');
        }

        $extra = count($propios) ? ' y los ' . count($propios) . ' calendarios de especialistas' : '';
        return self::pieza('ok', "Conectado ({$correo})", "El acceso se renueva bien y el calendario de reservas{$extra} responde.");
    }

    // ── IA ────────────────────────────────────────────────────────────────

    private function ia(bool $sondear, ?array $previo): array
    {
        if (! config('emr.asistente_ia_activo')) {
            return self::pieza('apagado', 'Apagada', 'ASISTENTE_IA_ACTIVO no está encendido: el bot contesta solo con respuestas fijas.');
        }
        $clave = (string) config('emr.anthropic_api_key');
        if ($clave === '') {
            return self::pieza('error', 'Sin clave de Anthropic', 'La IA está encendida pero no hay ANTHROPIC_API_KEY: el bot contesta solo con respuestas fijas.',
                'Carga la clave en el servidor.');
        }

        // Lo que respondió Anthropic la última vez que falló, si es más nuevo
        // que el último éxito.
        $error = Cache::get(self::IA_ERROR);
        $ok = Cache::get(self::IA_OK);
        if ($error && (! $ok || ($error['at'] ?? '') > $ok)) {
            $m = mb_strtolower((string) ($error['mensaje'] ?? ''));
            if (str_contains($m, 'credit balance')) {
                return self::pieza('error', 'Sin saldo en Anthropic',
                    'La cuenta de Anthropic se quedó sin créditos: el bot contesta solo con respuestas fijas.',
                    'Carga saldo en console.anthropic.com → Billing (cuenta de la clínica).', $error['at']);
            }
            if (str_contains($m, 'invalid x-api-key') || str_contains($m, 'authentication')) {
                return self::pieza('error', 'Clave de Anthropic inválida', 'Anthropic rechaza la clave cargada.',
                    'Genera una clave nueva en console.anthropic.com y cárgala como ANTHROPIC_API_KEY.', $error['at']);
            }
        }
        if (Cache::has('whatsapp:ia-caida')) {
            return self::pieza('error', 'Cortada por fallos seguidos',
                'El modelo falló 3 veces seguidas y se dejó de llamar por unos minutos.'
                . ($error ? ' Último error: ' . mb_substr((string) $error['mensaje'], 0, 160) : ''),
                'Se reintenta sola. Si se repite, revisa el estado de Anthropic (status.anthropic.com).', $error['at'] ?? null);
        }

        // El cupo del día: lo único de esta lista que se ve venir.
        $tope = UsoAsistente::tope();
        $gasto = UsoAsistente::gastoDeHoy()['tokens'];
        $uso = $tope > 0 ? "Hoy van " . number_format($gasto, 0, ',', '.') . ' de ' . number_format($tope, 0, ',', '.') . ' tokens.' : 'Sin tope diario.';
        if ($tope > 0 && $gasto >= $tope) {
            return self::pieza('error', 'Sin cupo por hoy',
                "Se acabó el cupo diario de la IA: hasta medianoche el bot contesta solo con respuestas fijas. {$uso}",
                'Si hace falta hoy, sube el cupo en Config → Asistente IA.');
        }
        if ($tope > 0 && $gasto >= 0.8 * $tope) {
            return self::pieza('aviso', 'Cupo casi agotado', $uso, 'Si hace falta, sube el cupo en Config → Asistente IA.');
        }

        if ($sondear) {
            // Lista de modelos: gratis, no gasta tokens, y dice si la clave sirve.
            $r = Http::withHeaders(['x-api-key' => $clave, 'anthropic-version' => '2023-06-01'])
                ->timeout(self::TIMEOUT)->get('https://api.anthropic.com/v1/models', ['limit' => 1]);
            if ($r->status() === 401) {
                return self::pieza('error', 'Clave de Anthropic inválida', 'Anthropic rechaza la clave cargada.',
                    'Genera una clave nueva en console.anthropic.com y cárgala como ANTHROPIC_API_KEY.');
            }
            if (! $r->successful()) {
                return self::pieza('aviso', 'Anthropic no responde bien', "Respondió {$r->status()} a la revisión. {$uso}",
                    'Revisa status.anthropic.com. Si sigue, el bot contesta con respuestas fijas.');
            }
        }

        return self::pieza('ok', 'Funcionando', $uso);
    }

    // ── Ayudas ────────────────────────────────────────────────────────────

    private static function pieza(string $estado, string $resumen, string $detalle, string $queHacer = '', ?string $desde = null): array
    {
        return ['estado' => $estado, 'resumen' => $resumen, 'detalle' => $detalle, 'que_hacer' => $queHacer, 'desde' => $desde];
    }

    private static function sinId(array $s): array
    {
        unset($s['id'], $s['nombre']);

        return $s;
    }

    /** El peor estado de la lista; `apagado` no cuenta como problema. */
    public static function peor(array $estados): string
    {
        foreach (['error', 'aviso'] as $e) {
            if (in_array($e, $estados, true)) {
                return $e;
            }
        }

        return 'ok';
    }

    // ── Indicadores ───────────────────────────────────────────────────────

    /**
     * Los números de la pantalla (decisión de producto, 05-oct). Cada uno nunca
     * lanza: si su tabla no existe o la consulta falla, vale `null` y el panel
     * muestra «—» en vez de tumbar la pantalla entera.
     *
     * Ojo con las horas: `created_at` y `updated_at` se guardan en UTC, y
     * `fecha_hora` en hora de la clínica (§9.6). «Hoy» es el día de la clínica.
     */
    public function kpis(): array
    {
        $hoyUtc = CarbonImmutable::now('America/Santiago')->startOfDay()->utc()->format('Y-m-d H:i:s');
        $hace7 = CarbonImmutable::now('UTC')->subDays(7)->format('Y-m-d H:i:s');
        $hace14 = CarbonImmutable::now('UTC')->subDays(14)->format('Y-m-d H:i:s');
        $intentar = function (callable $f) {
            try {
                return $f();
            } catch (\Throwable $e) {
                Log::info('estado del sistema: indicador no disponible: ' . $e->getMessage());

                return null;
            }
        };
        // «Canal» lo escriben las tres vías del bot: asistente IA, acordada con
        // el especialista, y el portal no (no es WhatsApp).
        $delBot = fn ($q) => $q->where(fn ($w) => $w->where('respuestas', 'like', '%"Canal":"WhatsApp%')
            ->orWhere('respuestas', 'like', '%"Canal": "WhatsApp%'));

        $gasto = $intentar(fn () => UsoAsistente::gastoDeHoy());
        $tope = $intentar(fn () => UsoAsistente::tope());

        return [
            'conversaciones_hoy' => $intentar(fn () => DB::table('chatbot_conversations')
                ->whereNotNull('telefono')->where('updated_at', '>=', $hoyUtc)->count()),
            'reservas_bot_7d' => $intentar(fn () => $delBot(DB::table('citas')->where('created_at', '>=', $hace7))->count()),
            'reservas_bot_7d_antes' => $intentar(fn () => $delBot(DB::table('citas')
                ->where('created_at', '>=', $hace14)->where('created_at', '<', $hace7))->count()),
            'especialistas_esperando' => $intentar(fn () => DB::table('solicitudes_especialista')
                ->whereIn('estado', ['esperando_especialista', 'esperando_tutor'])->count()),
            'fuera_de_google' => $intentar(function () {
                [$cuenta] = $this->google->calendarioClinica();
                if (! $cuenta) {
                    return null;   // sin calendario conectado la pregunta no aplica
                }
                $q = DB::table('citas')->whereIn('estado', ['Agendada', 'Confirmada'])
                    ->where('fecha_hora', '>', CarbonImmutable::now('America/Santiago')->format('Y-m-d H:i:s'));

                return ['sin_evento' => (clone $q)->whereNull('google_event_id')->count(), 'proximas' => $q->count()];
            }),
            'ia_hoy' => $gasto === null ? null : [
                'tokens' => $gasto['tokens'], 'llamadas' => $gasto['llamadas'], 'tope' => $tope,
                'activa' => (bool) config('emr.asistente_ia_activo'),
            ],
            'alertas_7d' => $intentar(fn () => count(array_filter(
                Cache::get(\App\Console\Commands\EstadoRevisar::ENVIADAS) ?: [],
                fn ($at) => $at >= now()->subDays(7)->toIso8601String()))),
            'disponibilidad_7d' => $intentar(fn () => self::disponibilidad(
                Cache::get(\App\Console\Commands\EstadoRevisar::HISTORIAL) ?: [])),
        ];
    }

    /**
     * Qué parte de las revisiones de 7 días dio el servicio funcionando (`ok`
     * o `aviso`: con aviso, funciona). `apagado` no cuenta ni a favor ni en
     * contra. Sin revisiones todavía, `null`.
     *
     * @return array<string, array{porcentaje: ?float, revisiones: int, desde: ?string}>
     */
    public static function disponibilidad(array $historial): array
    {
        $r = [];
        foreach (['whatsapp', 'google', 'ia'] as $id) {
            $validas = array_values(array_filter($historial, fn ($f) => isset($f[$id]) && $f[$id] !== 'apagado'));
            $buenas = count(array_filter($validas, fn ($f) => $f[$id] !== 'error'));
            $r[$id] = [
                'porcentaje' => $validas ? round(100 * $buenas / count($validas), 1) : null,
                'revisiones' => count($validas),
                'desde' => $validas[0]['at'] ?? null,
            ];
        }

        return $r;
    }

    // ── Destinatarios de las alertas ──────────────────────────────────────

    /**
     * Cómo están configuradas las alertas (`ajustes.data.alertas`):
     * - `activo`: el interruptor general. Apagado, no sale ningún correo; la
     *   pantalla y el punto rojo de «Config» siguen funcionando.
     * - `extra`: correos que no son de un admin (la casilla de la clínica).
     * - `apagados`: correos que no quieren recibirlas (un admin de pruebas).
     *
     * Por defecto (nada guardado): encendidas, a todos los admins activos.
     * Una lista vieja en `correos` se lee como `extra`.
     */
    public static function configAlertas(): array
    {
        $data = json_decode((string) DB::table('ajustes')->where('id', 1)->value('data'), true) ?: [];
        $a = (array) ($data['alertas'] ?? []);
        $limpiar = fn ($l) => array_values(array_filter((array) $l, 'is_string'));

        return [
            'activo' => (bool) ($a['activo'] ?? true),
            'extra' => $limpiar($a['extra'] ?? $a['correos'] ?? []),
            'apagados' => $limpiar($a['apagados'] ?? []),
        ];
    }

    /**
     * Todos los que PUEDEN recibir la alerta, cada uno con si la recibe:
     * los admins activos y los correos agregados a mano.
     *
     * @return array<array{correo:string, nombre:?string, origen:string, recibe:bool}>
     */
    public static function candidatos(): array
    {
        $cfg = self::configAlertas();
        $apagados = array_map('mb_strtolower', $cfg['apagados']);
        $lista = [];
        foreach (DB::table('usuarios')->where('rol', 'admin')->where('es_activo', 1)->whereNotNull('email')
            ->orderBy('email')->orderBy('id')->get(['email', 'nombre']) as $u) {
            $lista[mb_strtolower($u->email)] = ['correo' => $u->email, 'nombre' => $u->nombre ?? null, 'origen' => 'admin'];
        }
        foreach ($cfg['extra'] as $c) {
            $lista[mb_strtolower($c)] ??= ['correo' => $c, 'nombre' => null, 'origen' => 'extra'];
        }

        return array_values(array_map(fn ($x) => $x + ['recibe' => ! in_array(mb_strtolower($x['correo']), $apagados, true)], $lista));
    }

    /** Los correos a los que se manda la alerta ahora: ninguno con el interruptor general apagado. */
    public static function destinatarios(): array
    {
        if (! self::configAlertas()['activo']) {
            return [];
        }

        return array_values(array_column(array_filter(self::candidatos(), fn ($c) => $c['recibe']), 'correo'));
    }
}
