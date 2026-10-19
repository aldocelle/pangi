<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OAuth de la cuenta de Google de la clínica: UNA cuenta y UN calendario para
 * todos los especialistas, no una conexión por persona.
 *
 * **Falla ABIERTO en todo lo que consulta** (§4): si Google no responde, se
 * traga la excepción y sigue. La razón está medida — `_token()` no estaba dentro
 * de ningún `try` y un corte de DNS convertía el `/citas/slots` público en un
 * 500, dejando la página de reservas sin horarios.
 */
class GoogleOAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/calendar/v3';

    /**
     * `gmail.send` NO es opcional: el primer transporte de `Correo` es la API de
     * Gmail de esta misma cuenta. El port lo había perdido (Python lo pide), y
     * una cuenta conectada desde el panel Laravel quedaba sin permiso para
     * enviar: cada correo respondía 403 «insufficient authentication scopes».
     * Se vio el 14-sep en pruebas — ni la confirmación de reserva ni la
     * recuperación de clave llegaban. Agregarlo obliga a reconectar la cuenta:
     * los tokens ya emitidos no lo traen.
     */
    public const SCOPES = 'https://www.googleapis.com/auth/calendar'
        . ' https://www.googleapis.com/auth/userinfo.email'
        . ' https://www.googleapis.com/auth/gmail.send';

    /** El `state` vive 10 minutos: lo que tarda un consentimiento, no más. */
    private const STATE_TTL_SEG = 600;

    private const TIMEOUT = 15;

    public function __construct(private Fernet $fernet)
    {
    }

    public function estaConfigurado(): bool
    {
        return (string) env('GOOGLE_CLIENT_ID', '') !== ''
            && (string) env('GOOGLE_CLIENT_SECRET', '') !== '';
    }

    private function redirectUri(): string
    {
        return (string) env('GOOGLE_REDIRECT_URI',
            'http://localhost:8100/google/callback');
    }

    // ── El `state` anti-CSRF ──────────────────────────────────────────────

    /**
     * Liga el callback a quien inició el flujo, con expiración.
     *
     * El callback **no tiene sesión propia** —Google redirige a pelo—, así que
     * esta firma es lo único que impide que un tercero conecte SU cuenta de
     * Google engañando a un admin para que abra un enlace ajeno.
     *
     * Misma `SECRET_KEY` y mismo formato que Python (`sid:ts:firma`): un state
     * emitido por un backend tiene que valer en el otro mientras conviven.
     */
    public function generarState(string $staffId): string
    {
        $payload = $staffId . ':' . time();

        return $payload . ':' . $this->firmar($payload);
    }

    public function stateValido(string $state, string $staffId): bool
    {
        $partes = explode(':', $state);
        if (count($partes) !== 3) {
            return false;
        }
        [$sid, $ts, $firma] = $partes;

        if (! hash_equals($this->firmar("{$sid}:{$ts}"), $firma)) {
            return false;
        }
        if ($sid !== $staffId) {
            return false;
        }

        return (time() - (int) $ts) <= self::STATE_TTL_SEG;
    }

    private function firmar(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.jwt_secret'));
    }

    public function authUrl(string $state = ''): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            // `offline` + `consent`: sin los dos, Google no entrega
            // `refresh_token` en la segunda autorización y la conexión se cae
            // en una hora sin forma de renovarla.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /**
     * Valida un ID token de «Sign in with Google» contra el endpoint público.
     *
     * **Distinto del OAuth del resto de este servicio**: aquel conecta el
     * Calendar de un especialista (staff); este solo identifica a un cliente
     * que reserva. El frontend usa Google Identity Services y nos manda el ID
     * token; se verifica server-side antes de confiar en él.
     *
     * Las dos comprobaciones que lo hacen valer algo: que el token esté
     * emitido para NUESTRO `client_id` (`aud`) —si no, sirve uno de cualquier
     * otra app— y que el correo venga verificado por Google. Sin ellas esto es
     * un login con el correo que el cliente quiera escribir.
     *
     * Falla cerrado: devuelve `null` ante cualquier duda, y el endpoint
     * responde 401. Es lo contrario del Calendar, que falla abierto — acá lo
     * que está en juego es dejar entrar a alguien.
     */
    public function verificarIdToken(string $idToken): ?array
    {
        $clientId = env('GOOGLE_CLIENT_ID');
        if (! $clientId) {
            return null;
        }
        try {
            $r = Http::timeout(self::TIMEOUT)
                ->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);
        } catch (\Throwable $e) {
            return null;
        }
        if (! $r->successful()) {
            return null;
        }
        $payload = $r->json();
        if (! is_array($payload) || ($payload['aud'] ?? null) !== $clientId) {
            return null;
        }
        // Google manda el booleano como el STRING "true" en este endpoint.
        if (! in_array($payload['email_verified'] ?? null, ['true', true], true)) {
            return null;
        }
        return $payload;
    }

    // ── Tokens ────────────────────────────────────────────────────────────

    /** Canjea el código por tokens y guarda o actualiza la cuenta. */
    public function intercambiarCodigo(string $code): ?object
    {
        $r = Http::timeout(self::TIMEOUT)->asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        if (! $r->successful()) {
            return null;
        }
        $tok = $r->json();

        $info = Http::timeout(self::TIMEOUT)
            ->withToken($tok['access_token'])
            ->get('https://www.googleapis.com/oauth2/v2/userinfo')
            ->json();
        $email = $info['email'] ?? 'desconocido';

        $cuenta = DB::table('google_accounts')->where('email', $email)->first();
        $expira = CarbonImmutable::now('UTC')
            ->addSeconds((int) ($tok['expires_in'] ?? 3600))
            ->format('Y-m-d H:i:s.u');

        $set = [
            'access_token_enc' => $this->fernet->cifrar($tok['access_token']),
            'token_expiry' => $expira,
        ];
        // El `refresh_token` solo llega en la PRIMERA autorización: si no viene,
        // se conserva el que ya había. Sobrescribirlo con null dejaría la
        // cuenta sin poder renovarse.
        if (! empty($tok['refresh_token'])) {
            $set['refresh_token_enc'] = $this->fernet->cifrar($tok['refresh_token']);
        }

        if ($cuenta) {
            DB::table('google_accounts')->where('id', $cuenta->id)->update($set);

            return DB::table('google_accounts')->where('id', $cuenta->id)->first();
        }

        $id = (string) Str::uuid();
        DB::table('google_accounts')->insert([
            'id' => $id,
            'email' => $email,
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ] + $set);

        return DB::table('google_accounts')->where('id', $id)->first();
    }

    /**
     * Un access token vigente, renovándolo si hace falta. `null` si no se pudo.
     *
     * **Todo el cuerpo va dentro del `try`.** En Python el `requests.post` del
     * refresh quedó fuera y un fallo de red salía por sus nueve llamadores —el
     * peor, el `/citas/slots` PÚBLICO, que quedaba en 500 sin ningún horario.
     * Lo fija `tests/test_google_falla_abierto.py`.
     */
    public function token(object $cuenta): ?string
    {
        try {
            // `'UTC'` explícito, por lo mismo que en `TokensCorreo::vigente()`:
            // `token_expiry` se ESCRIBE con `now('UTC')` (más abajo, en el
            // refresco) y `APP_TIMEZONE` de esta app es `America/Santiago`. Sin
            // la zona, Carbon lee la marca como hora de Chile y le regala
            // cuatro horas de vida a un token ya muerto: el código se saltaría
            // el refresco y llamaría a Google con un access token vencido.
            // Google falla abierto, así que no se cae nada — simplemente el
            // calendario deja de sincronizar sin que nadie se entere.
            $expira = $cuenta->token_expiry
                ? CarbonImmutable::parse($cuenta->token_expiry, 'UTC') : null;
            // Con más de 2 minutos por delante, el que ya hay sirve.
            if ($expira && $expira->gt(CarbonImmutable::now('UTC')->addMinutes(2))) {
                return $this->fernet->descifrar($cuenta->access_token_enc);
            }

            $refresh = $this->fernet->descifrar($cuenta->refresh_token_enc);
            if (! $refresh) {
                return $this->fernet->descifrar($cuenta->access_token_enc);
            }

            $r = Http::timeout(self::TIMEOUT)->asForm()->post(self::TOKEN_URL, [
                'refresh_token' => $refresh,
                'client_id' => env('GOOGLE_CLIENT_ID'),
                'client_secret' => env('GOOGLE_CLIENT_SECRET'),
                'grant_type' => 'refresh_token',
            ]);
            if (! $r->successful()) {
                // Para Config → Estado del sistema: `invalid_grant` = Google
                // revocó el permiso y solo se arregla reconectando.
                Cache::put(EstadoSistema::GOOGLE_ERROR, ['at' => now()->toIso8601String(),
                    'error' => (string) $r->json('error', 'http_' . $r->status())], now()->addDays(7));

                return null;
            }
            $tok = $r->json();

            DB::table('google_accounts')->where('id', $cuenta->id)->update([
                'access_token_enc' => $this->fernet->cifrar($tok['access_token']),
                'token_expiry' => CarbonImmutable::now('UTC')
                    ->addSeconds((int) ($tok['expires_in'] ?? 3600))
                    ->format('Y-m-d H:i:s.u'),
            ]);

            return $tok['access_token'];
        } catch (\Throwable $e) {
            Log::warning('Google: no se pudo obtener el token: ' . $e->getMessage());
            return null;
        }
    }

    /** Los calendarios de la cuenta conectada. Lista vacía si Google falla. */
    public function listarCalendarios(object $cuenta): array
    {
        try {
            $t = $this->token($cuenta);
            if (! $t) {
                return [];
            }
            $r = Http::timeout(self::TIMEOUT)->withToken($t)
                ->get(self::API . '/users/me/calendarList');
            if (! $r->successful()) {
                return [];
            }

            return array_map(fn ($c) => [
                'id' => $c['id'],
                'nombre' => $c['summary'] ?? $c['id'],
                'primario' => $c['primary'] ?? false,
                // El panel ofrece como calendario de RESERVAS solo los que la
                // cuenta puede escribir (owner/writer); los de solo lectura
                // sirven nada más como calendarios de conflicto.
                'rol' => $c['accessRole'] ?? null,
            ], $r->json()['items'] ?? []);
        } catch (\Throwable $e) {
            Log::warning('Google: no se pudieron listar los calendarios: ' . $e->getMessage());
            return [];
        }
    }
}
