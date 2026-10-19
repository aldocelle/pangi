<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Services\GoogleCalendar;
use App\Services\GoogleOAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Conexión del Google Calendar de la clínica.
 *
 * Una sola cuenta de Google para toda la clínica. Las reservas caen en el
 * calendario general (`ajustes.calendario_reservas`) o, si el especialista
 * tiene uno propio, en el suyo (`especialistas.google_calendar_id`, oct-2026);
 * todos viven en esa misma cuenta. Cada especialista puede además marcar
 * calendarios de «conflicto» para bloquear su disponibilidad — eso vive en
 * `especialistas.calendarios_conflicto` y lo lee `SlotsService`.
 */
class GoogleController extends Controller
{
    public function __construct(private GoogleOAuth $google, private Auditoria $auditoria)
    {
    }

    /** GET /google/status */
    public function status()
    {
        $ajustes = $this->ajustes();
        $cuenta = $ajustes->google_account_id
            ? DB::table('google_accounts')->where('id', $ajustes->google_account_id)->first()
            : null;

        $configurado = $this->google->estaConfigurado();

        return [
            'configurado' => $configurado,
            'conectado' => $cuenta !== null,
            'cuenta' => $cuenta ? ['id' => $cuenta->id, 'email' => $cuenta->email] : null,
            'calendario_reservas' => $ajustes->calendario_reservas,
            // Las instrucciones solo aparecen cuando FALTA configurar: es un
            // mensaje para quien monta el sistema, no para el uso diario.
            'instrucciones' => $configurado ? null
                : 'Falta configurar GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET en backend/.env. '
                  . 'Créalos en console.cloud.google.com (OAuth app web, Calendar API habilitada, '
                  . 'redirect URI http://localhost:8100/google/callback).',
        ];
    }

    /** GET /google/auth-url */
    public function authUrl(Request $request)
    {
        if (! $this->google->estaConfigurado()) {
            return response()->json(
                ['detail' => 'Google no está configurado (ver /google/status).'], 400);
        }
        $staff = $request->attributes->get('usuario');

        return ['url' => $this->google->authUrl(
            $this->google->generarState((string) $staff->id))];
    }

    /**
     * GET /google/callback — aquí redirige Google tras el consentimiento.
     *
     * **Sin sesión propia**: la autenticidad depende de que el `state` venga
     * firmado y vigente desde `/google/auth-url`. Sin esa comprobación, un
     * tercero podría vincular SU cuenta de Google engañando a un admin para que
     * abra un enlace ajeno.
     *
     * Devuelve HTML, no JSON: lo abre un navegador, no un frontend.
     */
    public function callback(Request $request)
    {
        $code = (string) $request->query('code', '');
        $error = (string) $request->query('error', '');
        $state = (string) $request->query('state', '');

        if ($error !== '' || $code === '') {
            return response('<h3>Conexión cancelada. Puedes cerrar esta pestaña.</h3>')
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        $sid = explode(':', $state)[0] ?? '';
        if ($sid === '' || ! $this->google->stateValido($state, $sid)) {
            return response('<h3>Enlace de conexión inválido o expirado.</h3>'
                . '<p>Vuelve al panel e intenta de nuevo.</p>', 400)
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        $cuenta = $this->google->intercambiarCodigo($code);
        if (! $cuenta) {
            return response('<h3>Error al conectar con Google. Intenta de nuevo.</h3>', 500)
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        // La cuenta resultante queda como LA cuenta de la clínica,
        // reemplazando la anterior si había una.
        DB::table('ajustes')->where('id', 1)
            ->update(['google_account_id' => $cuenta->id]);

        return response(
            "<h3>✓ Cuenta {$cuenta->email} conectada como calendario de la clínica.</h3>"
            . '<p>Vuelve al panel, sección Config → Integraciones, y elige el calendario '
            . 'donde se crearán las reservas.</p>'
            . '<script>setTimeout(()=>window.close(), 2500)</script>'
        )->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** GET /google/calendars */
    public function calendars()
    {
        $ajustes = $this->ajustes();
        if (! $ajustes->google_account_id) {
            return response()->json(
                ['detail' => 'No hay ninguna cuenta de Google conectada todavía.'], 400);
        }
        $cuenta = DB::table('google_accounts')->where('id', $ajustes->google_account_id)->first();
        if (! $cuenta) {
            return response()->json(['detail' => 'Cuenta no encontrada'], 404);
        }

        return $this->google->listarCalendarios($cuenta);
    }

    /** PUT /google/calendario-reservas */
    public function elegirCalendario(Request $request)
    {
        $calendarioId = $request->query('calendario_id');
        if ($calendarioId === null || $calendarioId === '') {
            return response()->json(['detail' => [[
                'type' => 'missing',
                'loc' => ['query', 'calendario_id'],
                'msg' => 'Field required',
            ]]], 422);
        }

        $ajustes = $this->ajustes();
        if (! $ajustes->google_account_id) {
            return response()->json(
                ['detail' => 'Conecta una cuenta de Google primero.'], 400);
        }

        // Otro calendario: los ids de evento guardados en las citas son del
        // ANTERIOR y en este no existen. Si quedaran, la sincronización los
        // buscaría acá, Google diría «no existe» y se cancelarían las citas
        // futuras con correo al tutor incluido. Se sueltan: esas citas siguen
        // intactas en el sistema, solo dejan de tener evento espejo (los eventos
        // viejos quedan en el calendario anterior, y hay que borrarlos allá a
        // mano si molestan). Las reservas nuevas ya se crean en el nuevo.
        //
        // Solo las del calendario ANTERIOR: las que viven en el calendario
        // propio de un especialista (`citas.google_calendar_id`) no se mueven
        // por cambiar el general, y soltarlas las dejaría sin evento espejo.
        if ((string) $ajustes->calendario_reservas !== (string) $calendarioId) {
            $anterior = $ajustes->calendario_reservas;
            DB::table('citas')->whereNotNull('google_event_id')
                ->where(fn ($q) => $q->whereNull('google_calendar_id')
                    ->when($anterior, fn ($q) => $q->orWhere('google_calendar_id', $anterior)))
                ->update(['google_event_id' => null, 'google_calendar_id' => null]);
        }

        DB::table('ajustes')->where('id', 1)
            ->update(['calendario_reservas' => $calendarioId]);

        return ['calendario_reservas' => $calendarioId];
    }

    /**
     * POST /google/calendario-especialista/{id} — le crea su calendario de
     * reservas propio en la cuenta de la clínica, se lo asigna y, si se pide
     * (`compartir`) y tiene correo, se lo comparte en solo lectura.
     *
     * Las citas que ya tiene se quedan donde están: cada una recuerda su
     * calendario (`citas.google_calendar_id`). Solo las nuevas caen en este.
     *
     * Si compartir falla, el calendario queda creado y asignado igual —las
     * reservas ya funcionan— y la respuesta lo dice, para compartirlo a mano.
     */
    public function crearCalendarioEspecialista(Request $request, string $especialistaId,
                                                GoogleCalendar $calendar)
    {
        $esp = DB::table('especialistas')->where('id', $especialistaId)->first();
        if (! $esp) {
            return response()->json(['detail' => 'Especialista no encontrado'], 404);
        }
        $cuenta = $calendar->cuentaClinica();
        if (! $cuenta) {
            return response()->json(['detail' => 'Conecta primero la cuenta de Google de la clínica (Ajustes → Integraciones).'], 400);
        }

        $nombre = 'Reservas – ' . $esp->nombre;
        $calendarioId = $calendar->crearCalendario($cuenta, $nombre,
            $esp->zona_horaria ?: 'America/Santiago');
        if (! $calendarioId) {
            return response()->json(['detail' => 'Google no creó el calendario. Intenta de nuevo en un rato '
                . '(Google limita cuántos calendarios se crean por día).'], 502);
        }

        $correo = trim((string) ($esp->email ?? ''));
        $compartido = null;
        if ($request->boolean('compartir') && $correo !== '') {
            $compartido = $calendar->compartirCalendario($cuenta, $calendarioId, $correo) ? $correo : false;
        }

        DB::transaction(function () use ($request, $esp, $calendarioId, $nombre, $compartido) {
            DB::table('especialistas')->where('id', $esp->id)
                ->update(['google_calendar_id' => $calendarioId]);
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'crear_calendario', 'config_especialistas', 'especialista', $esp->id, [
                    'nombre' => $esp->nombre,
                    'calendario' => $nombre,
                    'calendario_anterior' => $esp->google_calendar_id ?? null,
                    'compartido_con' => $compartido ?: null,
                ]);
        });

        return [
            'google_calendar_id' => $calendarioId,
            'nombre' => $nombre,
            // `false` = se pidió y Google no lo compartió; `null` = no se pidió
            // o no tiene correo.
            'compartido_con' => $compartido,
        ];
    }

    /**
     * DELETE /google/desconectar — **solo sysop**: afecta a todos los
     * especialistas de una vez, no es la acción de un solo staff, y soltar la
     * cuenta corta la sincronización de toda la clínica.
     *
     * Queda en auditoría DENTRO de la transacción (ver `Auditoria`): si el
     * borrado falla, el registro tampoco queda describiendo algo que no pasó.
     */
    public function desconectar(Request $request)
    {
        $ajustes = $this->ajustes();
        $cuentaId = $ajustes->google_account_id;
        $cuenta = $cuentaId ? DB::table('google_accounts')->where('id', $cuentaId)->first() : null;

        DB::transaction(function () use ($request, $cuentaId, $cuenta) {
            // Primero se sueltan los ajustes y DESPUÉS se borra la cuenta: al
            // revés, la clave foránea de `ajustes.google_account_id` impediría
            // el borrado.
            DB::table('ajustes')->where('id', 1)->update([
                'google_account_id' => null,
                'calendario_reservas' => null,
            ]);
            if ($cuentaId) {
                DB::table('google_accounts')->where('id', $cuentaId)->delete();
            }
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'desconectar', 'integraciones', 'google', $cuentaId, ['cuenta' => $cuenta->email ?? null]);
        });

        return response()->noContent();
    }

    /** La fila única de ajustes, creándola si no existe. */
    private function ajustes()
    {
        $fila = DB::table('ajustes')->where('id', 1)->first();
        if (! $fila) {
            DB::table('ajustes')->insert(['id' => 1, 'data' => '{}']);
            $fila = DB::table('ajustes')->where('id', 1)->first();
        }

        return $fila;
    }
}
