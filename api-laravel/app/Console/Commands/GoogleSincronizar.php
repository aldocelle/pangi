<?php

namespace App\Console\Commands;

use App\Services\CitasEmail;
use App\Services\EstadoSistema;
use App\Services\GoogleCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Trae de vuelta lo que cambió EN Google: un evento borrado a mano, una hora
 * movida.
 *
 * Es la única dirección que no cubre el resto del sistema. Cuando la clínica
 * mueve una hora desde el calendario —que es lo natural para ellos— sin esto el
 * sistema nunca se entera: el portal sigue ofreciendo la hora vieja como
 * ocupada y la nueva como libre.
 *
 * Cron sugerido (cada cinco minutos; el intervalo lo decide el CÓDIGO, no una
 * hora en la expresión — el cron de hPanel corre en UTC, §9.6):
 *
 *     *\/5 * * * * /usr/bin/php /home/.../api-laravel/artisan google:sincronizar
 *
 * ⚠️ **Correrlo a mano SÍ habla con Google**, con los tokens de la clínica que
 * están en la base. No es un comando inofensivo: si un evento fue borrado allá,
 * este comando **cancela la cita**. Para probarlo, `--sin-escribir` — consulta
 * igual, informa lo que haría y no toca nada.
 */
class GoogleSincronizar extends TareaDeFondo
{
    protected $signature = 'google:sincronizar
                            {--sin-escribir : consulta Google pero no toca ninguna cita}';

    protected $description = 'Aplica en las citas lo que se cambió en Google Calendar';

    /** Desde cuántas cancelaciones de una vez se sospecha una falla (ver abajo). */
    private const TOPE_CANCELACIONES = 5;

    public function __construct(private GoogleCalendar $google, private CitasEmail $emails)
    {
        parent::__construct();
    }

    protected function ejecutar(): string
    {
        $cuenta = $this->google->cuentaClinica();
        if (! $cuenta) {
            // Sin cuenta de la clínica conectada no hay nada que sincronizar.
            // No es un error: es el estado normal mientras Google no se conecta.
            return '';
        }
        [, $general] = $this->google->calendarioClinica();

        // Hora de la CLÍNICA, no UTC. Con `utcnow()` toda cita a menos de
        // cuatro horas quedaría «en el pasado» y esta sincronización la
        // ignoraría — justo cuando más importa, porque una cancelación hecha en
        // Google a última hora es la que hay que enterarse rápido (§9.6).
        $citas = DB::table('citas')
            ->whereNotNull('google_event_id')
            ->whereIn('estado', ['Agendada', 'Confirmada'])
            ->where('fecha_hora', '>', CarbonImmutable::now()->format('Y-m-d H:i:s.u'))
            ->orderBy('fecha_hora')->orderBy('id')
            ->get();

        // Un grupo por calendario. Cada cita se busca en el calendario anotado
        // en ELLA (`citas.google_calendar_id`), no en el que tenga hoy su
        // especialista: buscarla en otro daría 404 y se leería como «la
        // borraron». Sin dato, el general, que es donde estaban todas antes de
        // que hubiera calendarios por especialista.
        //
        // El general entra aunque no tenga citas, para que una cuenta que dejó
        // de verlo se note igual que antes.
        $grupos = $general ? [$general => []] : [];
        foreach ($citas as $cita) {
            $cal = ($cita->google_calendar_id ?? null) ?: $general;
            if ($cal) {
                $grupos[$cal][] = $cita;
            }
        }

        $sinEscribir = (bool) $this->option('sin-escribir');
        $revisadas = $mudos = $canceladas = $reagendadas = 0;
        $problemas = [];
        foreach ($grupos as $cal => $deEste) {
            $r = $this->sincronizarCalendario($cuenta, (string) $cal, $deEste, $sinEscribir,
                $this->nombreCalendario((string) $cal, $general));
            $revisadas += $r['revisadas'];
            $mudos += $r['mudos'];
            $canceladas += $r['canceladas'];
            $reagendadas += $r['reagendadas'];
            if ($r['problema']) {
                $problemas[] = $r['problema'];
            }
        }

        // Google no contestó por NINGUNA cita: no es una cancelación, es que no
        // hay conexión (token muerto, permisos, red). Con alguna respuesta, anda.
        if ($problemas) {
            self::anotar(false, implode(' ', $problemas));
        } else {
            self::anotar(! ($revisadas > 0 && $mudos === $revisadas),
                "Google no respondió por ninguna de las {$revisadas} citas revisadas (acceso vencido o sin conexión).");
        }

        if (! $revisadas && ! $problemas) {
            return '';
        }

        // `mudos` es el contador que importa cuando algo va mal: son las citas
        // que Google no supo contestar (token vencido, red, permisos). Sin él,
        // «revisadas 4 · canceladas 0» se lee como «todo en orden» tanto si
        // Google contestó que sí como si no contestó nada.
        $resumen = "Revisadas {$revisadas} · canceladas {$canceladas} "
            . "· reagendadas {$reagendadas} · sin respuesta {$mudos}";
        if ($problemas) {
            $resumen .= ' · DETENIDO: ' . implode(' ', $problemas);
        }

        return $sinEscribir ? $resumen . '  (--sin-escribir: no se tocó nada)' : $resumen;
    }

    /**
     * Las citas de UN calendario. Las dos guardas van por calendario y no una
     * vez para todos: con un calendario por especialista, uno mal compartido
     * no puede frenar a los demás, y sobre todo no puede quedar escondido en
     * el promedio — cinco citas desaparecidas de un calendario chico serían
     * «pocas» contra el total de la clínica y se cancelarían con correo.
     *
     * @return array{revisadas:int, mudos:int, canceladas:int, reagendadas:int, problema:?string}
     */
    private function sincronizarCalendario(object $cuenta, string $calendarioId, array $citas,
                                           bool $sinEscribir, string $nombre): array
    {
        $r = ['revisadas' => 0, 'mudos' => 0, 'canceladas' => 0, 'reagendadas' => 0, 'problema' => null];

        // Primero: ¿esta cuenta ve este calendario? Si no lo ve, Google contesta
        // 404 a CADA evento, exactamente igual que si los hubieran borrado, y el
        // ciclo cancelaría todas las citas futuras y le escribiría «tu cita fue
        // cancelada» a cada tutor. Pasa al conectar otra cuenta de Google —la de
        // la clínica, por ejemplo— sin acceso al calendario anterior. Un correo
        // enviado no se recupera: ante la duda, no se toca nada.
        if (! $this->google->calendarioAccesible($cuenta, $calendarioId)) {
            Log::warning("Sincronización de Google detenida en {$nombre}: la cuenta conectada no ve el "
                . "calendario {$calendarioId}. No se tocó ninguna de sus citas.");
            $r['problema'] = 'La cuenta ' . ($cuenta->email ?? 'conectada') . " no ve {$nombre}: la sincronización "
                . 'se detuvo ahí para no cancelar citas por error, y sus reservas nuevas no llegan a Google.';
            return $r;
        }

        $aCancelar = $aMover = [];

        // Dos pasadas: primero se pregunta todo, después se decide. Así se puede
        // mirar el conjunto antes de escribir una sola fila (ver el tope abajo).
        foreach ($citas as $cita) {
            $r['revisadas']++;
            $estado = $this->google->obtenerEvento($cuenta, $calendarioId, $cita->google_event_id);

            // `null` es «no se pudo consultar», NO «no existe». Tratarlos igual
            // cancelaría citas buenas cada vez que Google tosa. Se reintenta en
            // el ciclo siguiente.
            if ($estado === null) {
                $r['mudos']++;
                continue;
            }

            if (! $estado['existe']) {
                $aCancelar[] = $cita;
                continue;
            }

            $nueva = $this->horaDeGoogle($estado['inicio'] ?? null);
            if ($nueva && $nueva !== substr((string) $cita->fecha_hora, 0, 19)) {
                $aMover[] = [$cita, $nueva];
            }
        }

        // Tope de cancelaciones en masa. La guarda del calendario de arriba cubre
        // el caso conocido; esta cubre los que no se previeron (el calendario
        // vaciado, otro calendario con el mismo id de cuenta…). Borrar a mano en
        // Google más de la mitad de las horas futuras en cinco minutos no es algo
        // que haga una recepción: es casi seguro una falla, y equivocarse para
        // este lado solo atrasa unas cancelaciones hasta que alguien lo mire.
        $canceladas = count($aCancelar);
        $revisadas = $r['revisadas'];
        if ($canceladas >= self::TOPE_CANCELACIONES && $canceladas * 2 > $revisadas) {
            Log::warning("Sincronización de Google detenida en {$nombre}: {$canceladas} de {$revisadas} "
                . 'eventos no aparecen en Google. Parece una falla, no cancelaciones: '
                . 'no se tocó ninguna cita ni se mandó ningún correo.');
            $r['problema'] = "{$canceladas} de {$revisadas} citas no aparecen en {$nombre}: parece una falla "
                . '(¿se cambió la cuenta o el calendario?). No se tocó ninguna cita.';
            return $r;
        }

        foreach ($aCancelar as $cita) {
            if (! $sinEscribir) {
                DB::table('citas')->where('id', $cita->id)->update([
                    'estado' => 'Cancelada',
                    // Se limpia el id: el evento ya no está, y conservarlo
                    // haría que el próximo ciclo lo vuelva a consultar para
                    // siempre.
                    'google_event_id' => null,
                ]);
                $this->avisarTutor($cita->id, 'cancelada');
            }
            Log::info("Cita {$cita->id} cancelada: el evento fue borrado en Google Calendar.");
        }
        $r['canceladas'] = $canceladas;

        foreach ($aMover as [$cita, $nueva]) {
            if (! $sinEscribir) {
                DB::table('citas')->where('id', $cita->id)->update(['fecha_hora' => $nueva]);
                $this->avisarTutor($cita->id, 'reagendada');
            }
            Log::info("Cita {$cita->id} actualizada a {$nueva}: hora movida en Google Calendar.");
        }
        $r['reagendadas'] = count($aMover);

        return $r;
    }

    /** Cómo nombrar un calendario en lo que lee la clínica (Estado del sistema). */
    private function nombreCalendario(string $calendarioId, ?string $general): string
    {
        if ($calendarioId === $general) {
            return 'el calendario de reservas de la clínica';
        }
        try {
            $nombre = DB::table('especialistas')->where('google_calendar_id', $calendarioId)
                ->orderBy('nombre')->orderBy('id')->value('nombre');
        } catch (\Throwable) {
            $nombre = null;
        }

        return $nombre ? "el calendario de {$nombre}" : "el calendario {$calendarioId}";
    }

    /**
     * Lo que vio esta vuelta, para Config → Estado del sistema y sus alertas
     * (EstadoSistema). Antes una sincronización detenida solo quedaba en el
     * log del servidor: en producción estuvo así desde el 2-oct sin que nadie
     * del panel se enterara.
     */
    private static function anotar(bool $ok, string $motivo): void
    {
        try {
            Cache::forever(EstadoSistema::GOOGLE_SINCRONIZACION,
                ['ok' => $ok, 'motivo' => $ok ? '' : $motivo, 'at' => now()->toIso8601String()]);
        } catch (\Throwable) {
            // Es un extra: que falle no frena la sincronización.
        }
    }

    /**
     * El correo al tutor de lo que se hizo EN Google.
     *
     * Hasta el 14-sep-2026 esta sincronización callaba, porque el tutor iba
     * invitado al evento y el aviso se lo mandaba Google — en el idioma de la
     * cuenta del tutor (en la prueba, inglés) y solo si quien borraba aceptaba
     * «notificar a los invitados». El tutor dejó de ir invitado, así que sin
     * esto una hora borrada en el calendario se cancelaba sin que nadie le
     * avisara. Son los mismos correos que manda el panel al cancelar o
     * reagendar: el acto es de una persona, no un envío automático, por eso no
     * pasa por `CORREOS_AUTOMATICOS`.
     *
     * Solo al tutor. El especialista es invitado del evento y Google ya le
     * avisa; y lo más probable es que haya sido él quien lo movió.
     *
     * Se lee la cita DESPUÉS del update, para que el correo lleve la hora
     * nueva y el estado nuevo. Nunca lanza: un correo fallido no puede frenar
     * la revisión de las demás citas.
     */
    private function avisarTutor(string $citaId, string $que): void
    {
        try {
            $cita = DB::table('citas')->where('id', $citaId)->first();
            $usuario = $cita ? DB::table('usuarios')->where('id', $cita->usuario_dueno_id)->first() : null;
            $mascota = $cita ? DB::table('mascotas')->where('id', $cita->mascota_id)->first() : null;
            if (! $cita || ! $usuario || ! $mascota) {
                return;
            }

            if ($que === 'cancelada') {
                $this->emails->notificarCambioEstado($usuario, $mascota, $cita);
                return;
            }

            $especialista = $cita->especialista_id
                ? DB::table('especialistas')->where('id', $cita->especialista_id)->first()
                : null;
            $this->emails->notificarCitaReagendada($usuario, $mascota, $cita, $especialista);
        } catch (\Throwable $e) {
            Log::error("Aviso al tutor tras cambio en Google (cita {$citaId}): " . $e->getMessage());
        }
    }

    /**
     * Google devuelve ISO con offset («2026-08-01T10:00:00-04:00»).
     *
     * Se guarda **sin** zona, igual que el resto del sistema: `fecha_hora` está
     * en hora de la clínica. Convertir a la zona local antes de soltar el offset
     * es justamente lo que hay que hacer — quedarse con la parte literal de un
     * ISO en otra zona movería la cita.
     */
    private function horaDeGoogle(?string $valor): ?string
    {
        if (! $valor) {
            return null;
        }
        try {
            return CarbonImmutable::parse($valor)
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
