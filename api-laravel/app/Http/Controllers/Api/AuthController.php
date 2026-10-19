<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleOAuth;
use App\Services\Jwt;
use App\Services\TokensCorreo;
use App\Services\Usuarios;
use Illuminate\Http\Request;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\DatosTitular;
use App\Services\LimitePorIP;
use App\Services\Sesiones;
use App\Services\Sysop;
use App\Services\Esquema;
use App\Services\FotosPerfil;

/**
 * Login y perfil propio.
 *
 * **El token que emite este endpoint tiene que ser idéntico al de FastAPI.**
 * Durante la migración los dos backends conviven, y un token firmado por uno
 * debe valer en el otro: si no, al cortar, todo el staff con sesión abierta se
 * queda fuera sin entender por qué. Misma clave, mismo algoritmo, mismo payload
 * (`sub`, `rol`, `user_id`, `exp`) y misma expiración de 12 h.
 */
class AuthController extends Controller
{
    /** Ocho intentos por IP+correo en cinco minutos. */
    private const LOGIN_MAX_INTENTOS = 8;

    private const LOGIN_VENTANA_SEG = 300;

    /**
     * Ocho y no seis: el sistema guarda fichas clínicas y datos de contacto.
     * Solo rige al CREAR o CAMBIAR una clave — nadie con una más corta ya
     * creada queda bloqueado.
     */
    private const PASSWORD_MIN_LARGO = 8;

    public function __construct(
        private Jwt $jwt,
        private Usuarios $usuarios,
        private TokensCorreo $tokens,
    ) {
    }

    /** POST /auth/login */
    public function login(Request $request)
    {
        $datos = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
        ]);

        $clave = $request->ip() . ':' . mb_strtolower($datos['email']);
        if ($this->usuarios->demasiadosIntentos($clave, self::LOGIN_MAX_INTENTOS,
                                                self::LOGIN_VENTANA_SEG)) {
            return response()->json([
                'detail' => 'Demasiados intentos. Espera unos minutos antes de volver a intentar.',
            ], 429);
        }

        $usuario = $this->usuarios->porEmail($datos['email']);

        // Mismo mensaje para «no existe» y «clave mala»: distinguirlos le diría
        // a cualquiera qué correos están registrados en la clínica.
        if (! $usuario || ! $usuario->password_hash
            || ! password_verify($datos['password'], $usuario->password_hash)) {
            return response()->json(['detail' => 'Credenciales inválidas'], 401);
        }

        if (! $usuario->email_verificado) {
            return response()->json([
                'detail' => 'Tu correo aún no está verificado. Revisa el enlace que te enviamos (o pide uno nuevo).',
            ], 403);
        }

        // Se rechaza acá ADEMÁS de en el middleware, para no entregar un token
        // que va a fallar en el primer request: quien fue desactivado merece
        // leer por qué no entra, no un error genérico dos pantallas después.
        if (! $usuario->es_activo) {
            return response()->json([
                'detail' => 'Esta cuenta está desactivada. Habla con la administración de la clínica.',
            ], 403);
        }

        $this->usuarios->limpiarIntentos($clave);

        return [
            'access_token' => $this->jwt->firmar([
                'sub' => $usuario->email,
                'rol' => $usuario->rol,
                'user_id' => (string) $usuario->id,
            ]),
            'token_type' => 'bearer',
        ];
    }

    /** GET /auth/me */
    public function me(Request $request)
    {
        $u = $request->attributes->get('usuario');

        // `es_sysop` solo sale acá y no en `usuarioOut`: ese lo comparten el
        // login y otros cuerpos, y esto es para que el panel sepa si dibuja la
        // sección Sysop. Mostrarla no autoriza nada: lo decide el backend.
        return $this->usuarioOut($u) + ['es_sysop' => Sysop::es($u)];
    }

    /**
     * POST /auth/me/foto — la foto de perfil de quien está logueado.
     *
     * Solo la propia: no hay ruta para ponerle foto a otra cuenta. Sin la
     * migración (`mariadb_usuario_foto.sql`) responde 503 en vez de 500.
     */
    public function subirFoto(Request $request)
    {
        if (! Esquema::columna('usuarios', 'foto')) {
            return response()->json(['detail' => 'La foto de perfil todavía no está disponible.'], 503);
        }
        $u = $request->attributes->get('usuario');

        [$nombre, $error] = FotosPerfil::guardar($request->file('archivo'), 'tu');
        if ($error) {
            return response()->json(['detail' => $error], 400);
        }
        DB::table('usuarios')->where('id', $u->id)->update(['foto' => $nombre]);
        FotosPerfil::borrar($u->foto ?? null, 'tu');

        return $this->usuarioOut(DB::table('usuarios')->where('id', $u->id)->first());
    }

    /** DELETE /auth/me/foto — vuelve a las iniciales. */
    public function quitarFoto(Request $request)
    {
        $u = $request->attributes->get('usuario');
        if (Esquema::columna('usuarios', 'foto')) {
            DB::table('usuarios')->where('id', $u->id)->update(['foto' => null]);
            FotosPerfil::borrar($u->foto ?? null, 'tu');
        }

        return $this->usuarioOut(DB::table('usuarios')->where('id', $u->id)->first());
    }

    /**
     * POST /auth/aceptar-privacidad — constancia de que la persona vio la
     * política vigente.
     *
     * Para quien no tiene ningún registro (ver `privacidad_pendiente` en
     * usuarioOut). Es un «entendido», no un requisito: nada del portal queda
     * condicionado a esto, y mucho menos los derechos del titular. Idempotente
     * y sin pisar: si ya había una constancia, se conserva la FECHA Y VERSIÓN
     * ORIGINALES, que es lo único que sirve para demostrar cuándo se aceptó
     * qué.
     */
    public function aceptarPrivacidad(Request $request)
    {
        $u = $request->attributes->get('usuario');

        if (! (bool) ($u->privacy_accepted ?? false)) {
            DB::table('usuarios')->where('id', $u->id)->update([
                'privacy_accepted' => true,
                'privacy_accepted_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                'privacy_version' => self::PRIVACY_VERSION,
            ]);
        }

        return response()->json(['privacidad_pendiente' => false]);
    }

    /**
     * PATCH /auth/me — autoservicio.
     *
     * El usuario edita sus datos de contacto y, opcionalmente, su contraseña.
     * **No puede tocar su rol ni el de nadie**: ese campo no se lee del cuerpo.
     */
    public function actualizarMe(Request $request)
    {
        $datos = $request->validate([
            'nombre' => 'nullable|string',
            'telefono' => 'nullable|string',
            'email' => 'nullable|email',
            'password_actual' => 'nullable|string',
            'password_nueva' => 'nullable|string',
            // Derecho de OPOSICIÓN (ley 21.719). Faltaba: el consentimiento se
            // podía dar al registrarse y no había forma de retirarlo. Un
            // derecho que solo se ejerce en un sentido no es un derecho.
            'acepta_marketing' => 'nullable|boolean',
        ]);

        $u = $request->attributes->get('usuario');
        $set = [];

        // `$request->exists` y no `$datos['nombre'] !== null`: Laravel
        // convierte un string en blanco a null al validar `nullable`, así que
        // «   » llegaría como ausente y el 400 no saltaría. Python distingue
        // «no vino» de «vino vacío» y rechaza el segundo.
        if ($request->exists('nombre')) {
            $nombre = trim((string) ($datos['nombre'] ?? ''));
            if ($nombre === '') {
                return response()->json(['detail' => 'El nombre no puede quedar vacío.'], 400);
            }
            $set['nombre'] = $nombre;
        }

        if (($datos['telefono'] ?? null) !== null) {
            // 409 legible en vez del 500 que salía del índice único: sin esto,
            // la pantalla le decía al cliente que no había conexión cuando el
            // problema era un teléfono repetido.
            $tel = trim($datos['telefono']);
            $tel = $tel === '' ? null : $tel;
            if ($tel !== null && DB::table('usuarios')->where('telefono', $tel)
                    ->where('id', '!=', $u->id)->exists()) {
                return response()->json([
                    'detail' => 'Ese teléfono ya está registrado en otra cuenta.',
                ], 409);
            }
            $set['telefono'] = $tel;
        }

        // En minúsculas al guardar Y al comparar, las dos cosas. Con la
        // comparación exacta que había, `VICTIMA@x.com` pasaba el control sobre
        // un `victima@x.com` ya registrado y quedaban dos filas con el mismo
        // correo efectivo — y como el login busca en minúsculas y sin
        // desempate, cuál de las dos contesta dejaba de estar definido.
        $emailNuevo = $datos['email'] ?? null;
        if ($emailNuevo !== null) {
            $emailNuevo = mb_strtolower(trim($emailNuevo));
        }
        if ($emailNuevo !== null && $emailNuevo !== mb_strtolower((string) $u->email)) {
            if (DB::table('usuarios')->whereRaw('LOWER(email) = ?', [$emailNuevo])
                    ->where('id', '!=', $u->id)->exists()) {
                return response()->json(['detail' => 'Ya existe una cuenta con ese correo.'], 409);
            }
            $set['email'] = $emailNuevo;
        }

        if ($request->exists('acepta_marketing') && ($datos['acepta_marketing'] ?? null) !== null) {
            $quiere = (bool) $datos['acepta_marketing'];
            if ($quiere !== (bool) $u->marketing_consent) {
                $ahora = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
                $set['marketing_consent'] = $quiere;
                if ($quiere) {
                    $set['marketing_consent_at'] = $ahora;
                    $set['marketing_consent_retirado_at'] = null;
                } else {
                    // `marketing_consent_at` NO se limpia: queda cuándo dijo que
                    // sí y cuándo dijo que no. Poder demostrar que hubo
                    // consentimiento sirve de poco si no se puede demostrar
                    // hasta cuándo duró.
                    $set['marketing_consent_retirado_at'] = $ahora;
                }
            }
        }

        if (! empty($datos['password_nueva'])) {
            // Una cuenta creada con «Iniciar sesión con Google» no tiene
            // contraseña propia: no hay «actual» que verificar.
            if (! $u->password_hash) {
                return response()->json([
                    'detail' => 'Esta cuenta inició sesión con Google y no tiene contraseña propia.',
                ], 400);
            }
            if (empty($datos['password_actual'])
                || ! password_verify($datos['password_actual'], $u->password_hash)) {
                return response()->json(['detail' => 'La contraseña actual no es correcta.'], 401);
            }
            if ($error = $this->passwordInvalida($datos['password_nueva'])) {
                return response()->json(['detail' => $error], 400);
            }
            if ($datos['password_nueva'] === ($datos['password_actual'] ?? null)) {
                return response()->json([
                    'detail' => 'La nueva contraseña debe ser distinta de la actual.',
                ], 400);
            }
            $set['password_hash'] = password_hash($datos['password_nueva'],
                PASSWORD_BCRYPT, ['cost' => 12]);
        }

        if ($set) {
            DB::table('usuarios')->where('id', $u->id)->update($set);
        }

        $out = $this->usuarioOut(DB::table('usuarios')->where('id', $u->id)->first());
        if (isset($set['password_hash'])) {
            // Cierra las demás sesiones y le devuelve un token nuevo a esta,
            // para que cambiar la clave no la saque del panel.
            Sesiones::revocar((string) $u->id);
            $out['access_token'] = $this->jwt->firmar([
                'sub' => $u->email, 'rol' => $u->rol, 'user_id' => (string) $u->id,
            ]);
        }

        return $out;
    }

    /** Ocho registros por IP en quince minutos: el registro manda correos. */
    private const REGISTRO_MAX_INTENTOS = 8;

    private const REGISTRO_VENTANA_SEG = 900;

    private const TERMS_VERSION = '2026-08-13';

    private const PRIVACY_VERSION = '2026-10-02';

    private const MSG_REVISA_CORREO = 'Te enviamos un correo para confirmar tu cuenta. '
        . 'Revisa tu bandeja (y la carpeta de spam): el enlace vence en 24 horas.';

    private const MSG_EMAIL_NO_SALIO = 'Creamos tu cuenta pero no pudimos enviarte el correo '
        . 'de confirmación. Inténtalo de nuevo en unos minutos o escríbenos por WhatsApp.';

    private const MSG_RESET_SOLICITADO = 'Si el correo está registrado, te enviamos un enlace '
        . 'para restablecer tu contraseña. Revisa tu bandeja (y la carpeta de spam): vence en 1 hora.';

    /**
     * POST /auth/register — registro público.
     *
     * **No entrega sesión**: la cuenta queda pendiente y solo se activa con el
     * clic del correo de confirmación.
     *
     * Y **no revela si el correo ya existe**. Con una cuenta activa responde
     * exactamente igual que con un registro nuevo, y en vez del enlace manda un
     * aviso a esa cuenta. Decirlo —como hacía antes, con un 409— deja enumerar
     * qué correos son clientes de la clínica probando uno por uno.
     */
    public function register(Request $request)
    {
        $datos = $request->validate([
            'email' => 'required|email',
            // `present` y no `required`: «   » es un valor presente pero
            // vacío, y el 400 con el mensaje legible lo da el endpoint, no el
            // validador. Con `required`, Laravel lo convierte a null y
            // responde 422 con su propio texto.
            'nombre' => 'present',
            'password' => 'required|string',
            'telefono' => 'nullable|string',
            'acepta_terminos' => 'nullable|boolean',
            'acepta_privacidad' => 'nullable|boolean',
            'acepta_marketing' => 'nullable|boolean',
            'recaptcha_token' => 'nullable|string',
        ]);

        if ($this->usuarios->demasiadosIntentos('registro:' . $request->ip(),
                self::REGISTRO_MAX_INTENTOS, self::REGISTRO_VENTANA_SEG)) {
            return response()->json([
                'detail' => 'Demasiados registros seguidos. Espera unos minutos antes de volver a intentar.',
            ], 429);
        }

        if ($error = $this->passwordInvalida($datos['password'])) {
            return response()->json(['detail' => $error], 400);
        }

        $nombre = trim((string) ($datos['nombre'] ?? ''));
        if ($nombre === '') {
            return response()->json(['detail' => 'El nombre no puede quedar vacío.'], 400);
        }

        // El frontend ya bloquea el envío sin estos checkboxes, pero eso es UX
        // y no seguridad: el backend nunca confía en un booleano del cliente
        // sin volver a exigirlo.
        if (empty($datos['acepta_terminos'])) {
            return response()->json([
                'detail' => 'Debes aceptar los Términos y Condiciones para crear tu cuenta.',
            ], 400);
        }
        if (empty($datos['acepta_privacidad'])) {
            return response()->json([
                'detail' => 'Debes aceptar la Política de Privacidad para crear tu cuenta.',
            ], 400);
        }

        $email = mb_strtolower(trim($datos['email']));
        $existente = $this->usuarios->porEmail($email);
        $esReclamo = false;

        if ($existente) {
            if ($existente->rol !== 'dueño') {
                return response()->json([
                    'detail' => 'Esta cuenta pertenece al equipo de la clínica. Contacta al administrador.',
                ], 403);
            }
            if ($existente->password_hash) {
                // Cuenta ACTIVA: no se toca nada ni se manda enlace de
                // confirmación —eso le daría a cualquiera con este correo un
                // camino para forzar una reconfirmación—. Solo se avisa a la
                // cuenta real, y la respuesta es indistinguible de un registro
                // nuevo exitoso.
                $this->tokens->enviarCuentaExistente($existente);

                return ['mensaje' => self::MSG_REVISA_CORREO];
            }

            // Reclamo: la cuenta existe pero nadie la activó todavía (cliente
            // importado de ACVet, o alguien que se registró y no confirmó).
            //
            // **No se toca `password_hash` ni se da el correo por verificado**:
            // si no, bastaría registrarse con el correo de un cliente para
            // quedarse con su ficha.
            $usuarioId = $existente->id;
            $set = ['nombre' => $nombre ?: $existente->nombre];
            if (! empty($datos['telefono']) && trim($datos['telefono']) !== '') {
                $set['telefono'] = trim($datos['telefono']);
            }
            DB::table('usuarios')->where('id', $usuarioId)->update($set);
            $esReclamo = true;
        } else {
            $usuarioId = (string) Str::uuid();
            DB::table('usuarios')->insert([
                'id' => $usuarioId,
                'email' => $email,
                'nombre' => $nombre,
                'telefono' => trim((string) ($datos['telefono'] ?? '')) ?: null,
                // El registro público NUNCA crea staff ni admin.
                'rol' => 'dueño',
                'es_activo' => true,
                // Se promueve desde `password_hash_pendiente` al confirmar.
                'password_hash' => null,
                'email_verificado' => false,
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
        }

        $ahora = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        DB::table('usuarios')->where('id', $usuarioId)->update([
            'terms_accepted' => true,
            'terms_accepted_at' => $ahora,
            'terms_version' => self::TERMS_VERSION,
            'privacy_accepted' => true,
            'privacy_accepted_at' => $ahora,
            'privacy_version' => self::PRIVACY_VERSION,
            'marketing_consent' => (bool) ($datos['acepta_marketing'] ?? false),
            'marketing_consent_at' => ($datos['acepta_marketing'] ?? false) ? $ahora : null,
        ]);

        $token = $this->tokens->prepararConfirmacion($usuarioId,
            password_hash($datos['password'], PASSWORD_BCRYPT, ['cost' => 12]));
        $usuario = DB::table('usuarios')->where('id', $usuarioId)->first();

        // Este correo NO falla en silencio: sin él la cuenta queda inservible,
        // porque nadie podría confirmarla.
        if (! $this->tokens->enviarConfirmacion($usuario, $token, $esReclamo)) {
            return response()->json(['detail' => self::MSG_EMAIL_NO_SALIO], 502);
        }

        return ['mensaje' => self::MSG_REVISA_CORREO];
    }

    /**
     * POST /auth/google — «Sign in with Google» para CLIENTES.
     *
     * Distinto del OAuth de `/google/*`: aquel conecta el Calendar de un
     * especialista; este solo identifica a quien reserva. El frontend
     * (`BotonGoogle.vue`) manda el ID token y acá se verifica contra Google
     * antes de confiar en él — nunca se cree lo que diga el cliente.
     *
     * **Staff y admin no entran por esta vía** aunque su correo sea de Google:
     * mezclar identidades y permisos entre paneles es exactamente lo que no
     * conviene hacer con una sola pregunta de por medio.
     */
    public function loginConGoogle(Request $request, GoogleOAuth $google)
    {
        $datos = $request->validate(['id_token' => 'required|string']);

        $payload = $google->verificarIdToken($datos['id_token']);
        if (! $payload) {
            return response()->json(['detail' => 'Token de Google inválido.'], 401);
        }

        $email = $payload['email'] ?? null;
        if (! $email) {
            return response()->json(
                ['detail' => 'Google no entregó un correo verificado.'], 401);
        }
        $googleId = $payload['sub'] ?? null;

        // Por `porEmail` y guardado en minúsculas (§9.18): con `where` exacto,
        // la regla dependía de la colación — MariaDB ignora mayúsculas, pero
        // cualquier otro motor le crearía una cuenta duplicada a un cliente
        // importado como «Juan@Gmail.com», partiendo sus mascotas en dos.
        $email = mb_strtolower(trim($email));
        $u = $this->usuarios->porEmail($email);
        if ($u) {
            if ($u->rol !== 'dueño') {
                return response()->json(
                    ['detail' => 'Esta cuenta no usa acceso con Google.'], 403);
            }
            // Igual que el login con clave: el middleware lo rechazaría en el
            // primer request, pero quien fue desactivado merece leer por qué.
            if (! $u->es_activo) {
                return response()->json([
                    'detail' => 'Esta cuenta está desactivada. Habla con la administración de la clínica.',
                ], 403);
            }
            if (! $u->google_id) {
                // Primera vez que se vincula Google a una cuenta ya existente.
                DB::table('usuarios')->where('id', $u->id)->update(['google_id' => $googleId]);
            }
        } else {
            $id = (string) Str::uuid();
            DB::table('usuarios')->insert([
                'id' => $id,
                'email' => $email,
                'nombre' => trim($payload['name'] ?? '') ?: explode('@', $email)[0],
                'rol' => 'dueño',
                'es_activo' => true,
                'google_id' => $googleId,
                // Sin clave: la identidad la sostiene Google. Login normal con
                // este correo no entra, y es lo correcto.
                'password_hash' => null,
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
            $u = DB::table('usuarios')->where('id', $id)->first();
        }

        return [
            'access_token' => $this->jwt->firmar([
                'sub' => $u->email, 'rol' => $u->rol, 'user_id' => (string) $u->id,
            ]),
            'token_type' => 'bearer',
        ];
    }

    /**
     * POST /auth/google/equipo — «Continuar con Google» en el panel.
     *
     * Existe aparte de /auth/google por la razón que da ese endpoint: no se
     * mezclan identidades entre paneles. Cada ruta acepta solo lo suyo — aquella
     * clientes, esta el equipo — y esta además **no crea nada**: el equipo lo
     * da de alta un admin, nunca un clic en Google. Un correo que no es del
     * equipo recibe el mismo 403 exista o no, para no confirmar quién trabaja
     * en la clínica.
     *
     * La primera vez vincula la cuenta de Google (`google_id`); desde entonces
     * solo esa entra, aunque otra cuenta de Google llegue con el mismo correo.
     */
    public function loginEquipoConGoogle(Request $request, GoogleOAuth $google)
    {
        $datos = $request->validate(['id_token' => 'required|string']);

        $payload = $google->verificarIdToken($datos['id_token']);
        if (! $payload || empty($payload['email']) || empty($payload['sub'])) {
            return response()->json(['detail' => 'Token de Google inválido.'], 401);
        }

        $u = $this->usuarios->porEmail($payload['email']);
        $noEsDelEquipo = response()->json(
            ['detail' => 'Esta cuenta de Google no es del equipo de la clínica.'], 403);
        if (! $u || ! in_array($u->rol, ['veterinario', 'admin'], true)) {
            return $noEsDelEquipo;
        }
        if ($u->google_id && $u->google_id !== $payload['sub']) {
            return $noEsDelEquipo;
        }
        if (! $u->es_activo) {
            return response()->json([
                'detail' => 'Esta cuenta está desactivada. Habla con la administración de la clínica.',
            ], 403);
        }
        if (! $u->google_id) {
            DB::table('usuarios')->where('id', $u->id)->update(['google_id' => $payload['sub']]);
        }

        return [
            'access_token' => $this->jwt->firmar([
                'sub' => $u->email, 'rol' => $u->rol, 'user_id' => (string) $u->id,
            ]),
            'token_type' => 'bearer',
        ];
    }

    /**
     * POST /auth/confirmar — cierra el registro.
     *
     * Promueve la contraseña pendiente, marca el correo como probado y **deja
     * al usuario dentro**: pedirle otra vez la clave que escribió hace un
     * minuto no aporta nada.
     */
    public function confirmar(Request $request)
    {
        $datos = $request->validate(['token' => 'required|string']);

        $u = DB::table('usuarios')
            ->where('token_confirmacion', $this->tokens->hashToken($datos['token']))
            ->first();
        if (! $u) {
            return response()->json([
                'detail' => 'Este enlace no es válido o ya fue usado. Pide uno nuevo desde el portal.',
            ], 400);
        }
        if (! $this->tokens->vigente($u->token_confirmacion_expira)) {
            return response()->json([
                'detail' => 'Este enlace venció. Pide uno nuevo desde el portal e inténtalo otra vez.',
            ], 400);
        }

        $this->tokens->confirmar($u);

        return [
            'access_token' => $this->jwt->firmar([
                'sub' => $u->email, 'rol' => $u->rol, 'user_id' => (string) $u->id,
            ]),
            'token_type' => 'bearer',
        ];
    }

    /**
     * POST /auth/reenviar-confirmacion — un token nuevo; el anterior queda
     * inutilizable. Tampoco revela si el correo existe.
     */
    public function reenviarConfirmacion(Request $request)
    {
        $datos = $request->validate(['email' => 'required|email']);

        if ($this->usuarios->demasiadosIntentos('reenvio:' . $request->ip(),
                self::REGISTRO_MAX_INTENTOS, self::REGISTRO_VENTANA_SEG)) {
            return response()->json([
                'detail' => 'Demasiadas solicitudes seguidas. Espera unos minutos antes de volver a intentar.',
            ], 429);
        }

        $u = $this->usuarios->porEmail($datos['email']);
        // Solo tiene sentido para una cuenta SIN confirmar: si ya está activa,
        // reenviar le daría a cualquiera un modo de forzar una reconfirmación.
        if ($u && $u->rol === 'dueño' && ! $u->email_verificado && $u->password_hash_pendiente) {
            $token = $this->tokens->prepararConfirmacion($u->id, $u->password_hash_pendiente);
            $this->tokens->enviarConfirmacion(
                DB::table('usuarios')->where('id', $u->id)->first(), $token, false);
        }

        return ['mensaje' => self::MSG_REVISA_CORREO];
    }

    /**
     * POST /auth/solicitar-reset
     *
     * **No revela si el correo existe**: la respuesta es idéntica exista o no
     * la cuenta, mismo criterio que el registro.
     */
    public function solicitarReset(Request $request)
    {
        $datos = $request->validate(['email' => 'required|email']);

        if ($this->usuarios->demasiadosIntentos('reset:' . $request->ip(),
                self::REGISTRO_MAX_INTENTOS, self::REGISTRO_VENTANA_SEG)) {
            return response()->json([
                'detail' => 'Demasiadas solicitudes seguidas. Espera unos minutos antes de volver a intentar.',
            ], 429);
        }

        // Coincidencia EXACTA de correo acá, no case-insensitive: es lo que
        // hace Python en este endpoint (a diferencia del login).
        $u = DB::table('usuarios')->where('email', $datos['email'])
            ->where('rol', 'dueño')->first();

        if ($u && $u->password_hash) {
            $token = $this->tokens->prepararReset($u->id);
            if (! $this->tokens->enviarReset($u, $token)) {
                // Si el correo no sale, queda una solicitud para que el equipo
                // la resuelva a mano. Decirle al usuario que algo falló también
                // revelaría que el correo existe.
                $pendiente = DB::table('solicitudes_reset')
                    ->where('email', $datos['email'])->where('resuelta', false)->exists();
                if (! $pendiente) {
                    DB::table('solicitudes_reset')->insert([
                        'id' => (string) Str::uuid(),
                        'email' => $datos['email'],
                        'resuelta' => false,
                        'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                    ]);
                }
            }
        }

        return ['mensaje' => self::MSG_RESET_SOLICITADO];
    }

    /**
     * POST /auth/restablecer — cierra la recuperación y entrega sesión directa,
     * igual que `/confirmar`.
     */
    public function restablecer(Request $request)
    {
        $datos = $request->validate([
            'token' => 'required|string',
            'password' => 'required|string',
        ]);

        $u = DB::table('usuarios')
            ->where('token_reset', $this->tokens->hashToken($datos['token']))->first();
        if (! $u) {
            return response()->json([
                'detail' => 'Este enlace no es válido o ya fue usado. Pide uno nuevo desde el portal.',
            ], 400);
        }
        if (! $this->tokens->vigente($u->token_reset_expira)) {
            return response()->json([
                'detail' => 'Este enlace venció. Pide uno nuevo desde el portal e inténtalo otra vez.',
            ], 400);
        }
        if ($error = $this->passwordInvalida($datos['password'])) {
            return response()->json(['detail' => $error], 400);
        }

        DB::table('usuarios')->where('id', $u->id)->update([
            'password_hash' => password_hash($datos['password'], PASSWORD_BCRYPT, ['cost' => 12]),
        ]);
        $this->tokens->limpiarReset($u->id);
        // Quien pide un reset suele sospechar que alguien más entró: las
        // sesiones abiertas con la clave vieja se cierran todas.
        Sesiones::revocar((string) $u->id);

        return [
            'access_token' => $this->jwt->firmar([
                'sub' => $u->email, 'rol' => $u->rol, 'user_id' => (string) $u->id,
            ]),
            'token_type' => 'bearer',
        ];
    }

    /**
     * `null` si la contraseña cumple; si no, el mensaje exacto.
     *
     * Al menos una letra y un número, sin exigir símbolos: sube la barra sobre
     * «solo longitud» sin convertir el formulario en un examen.
     */
    private function passwordInvalida(string $password): ?string
    {
        if (mb_strlen($password) < self::PASSWORD_MIN_LARGO) {
            return 'La contraseña debe tener al menos ' . self::PASSWORD_MIN_LARGO . ' caracteres.';
        }
        if (! preg_match('/[A-Za-z]/', $password) || ! preg_match('/\d/', $password)) {
            return 'La contraseña debe incluir al menos una letra y un número.';
        }

        return null;
    }

    // ── Derechos del titular sobre sus datos (ley 21.719) ───────────────────
    // Se resuelven por dos vías distintas a propósito. Acceso y portabilidad
    // son automáticos: una consulta de lectura sobre datos que ya son de esta
    // persona, y hacerla pasar por un humano solo agrega demora. Supresión,
    // oposición sobre otros usos y bloqueo abren una solicitud que atiende el
    // equipo — no por burocracia: una supresión puede arrastrar la ficha
    // clínica del paciente, que tiene plazos de conservación ajenos a esta ley.

    /** Tipos que necesitan que una persona decida. Acceso y portabilidad no
     *  están: salen solos por `misDatos()`. */
    private const TIPOS_SOLICITUD = [
        'supresion', 'oposicion', 'bloqueo', 'rectificacion', 'otro',
    ];

    /**
     * GET /auth/mis-datos — derecho de ACCESO y de PORTABILIDAD.
     *
     * JSON y no PDF ni pantalla: la portabilidad exige un formato que pueda
     * leer otra máquina. Ver App\Services\DatosTitular para qué queda fuera.
     */
    public function misDatos(Request $request)
    {
        // La exportación recorre siete tablas: no tiene sentido pedirla dos
        // veces por minuto. Holgado igual — es un derecho, no un favor.
        if (LimitePorIP::demasiados('mis-datos:' . $request->ip(), 10, 600)) {
            return response()->json([
                'detail' => 'Demasiadas descargas seguidas. Espera unos minutos.',
            ], 429);
        }

        return (new DatosTitular())->exportar($request->attributes->get('usuario'));
    }

    /**
     * POST /auth/solicitudes — supresión, oposición, bloqueo, rectificación.
     *
     * Registra el ejercicio de un derecho que necesita que alguien decida.
     */
    public function crearSolicitud(Request $request)
    {
        $datos = $request->validate([
            'tipo' => 'required|string',
            'detalle' => 'nullable|string|max:2000',
        ]);
        if (! in_array($datos['tipo'], self::TIPOS_SOLICITUD, true)) {
            return response()->json([
                'detail' => 'Tipo de solicitud inválido. Usa uno de: '
                    . implode(', ', self::TIPOS_SOLICITUD) . '.',
            ], 400);
        }

        $u = $request->attributes->get('usuario');

        // Una pendiente por tipo y por persona: insistir no acelera nada y
        // llenar la cola de duplicados sí entorpece a quien tiene que atender.
        $ya = DB::table('solicitudes_titular')
            ->where('usuario_id', $u->id)
            ->where('tipo', $datos['tipo'])
            ->whereIn('estado', ['pendiente', 'en_curso'])
            ->exists();
        if ($ya) {
            return response()->json([
                'mensaje' => 'Ya tienes una solicitud de este tipo en curso. '
                    . 'Te responderemos a tu correo.',
            ], 201);
        }

        DB::table('solicitudes_titular')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $u->id,
            // El correo se copia a propósito: si la solicitud es de supresión y
            // termina ejecutándose, la fila del usuario desaparece — pero la
            // constancia de que se atendió tiene que sobrevivir a lo que ella
            // misma provocó.
            'email' => $u->email,
            'tipo' => $datos['tipo'],
            'detalle' => trim((string) ($datos['detalle'] ?? '')) ?: null,
            'estado' => 'pendiente',
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);

        return response()->json([
            'mensaje' => 'Recibimos tu solicitud. El equipo de la clínica la revisará '
                . 'y te responderá a tu correo.',
        ], 201);
    }

    private function usuarioOut($u): array
    {
        return [
            'email' => $u->email,
            'nombre' => $u->nombre,
            'telefono' => $u->telefono,
            'rol' => $u->rol,
            'id' => $u->id,
            'es_activo' => (bool) $u->es_activo,
            'created_at' => $this->iso($u->created_at),
            // NULL se conserva: significa «acceso total salvo auditoría», que
            // no es lo mismo que una lista vacía.
            'permisos' => $u->permisos === null ? null : json_decode($u->permisos, true),
            // Sale acá porque el portal necesita saber en qué estado está el
            // interruptor de comunicaciones ANTES de dibujarlo. Sin esto la
            // pantalla de Configuración lo mostraba siempre apagado, y quien ya
            // estaba suscrito veía «quiero recibirlas»: la única forma de darse
            // de baja empezaba mintiéndole sobre su situación actual.
            //
            // Casteado a bool y no crudo: MariaDB devuelve 0/1 y Python un
            // booleano JSON. El arnés compara los cuerpos enteros, así que un
            // `0` contra un `false` es un rojo — y con razón, porque el
            // frontend hace `Boolean(...)` sobre esto.
            'marketing_consent' => (bool) ($u->marketing_consent ?? false),
            // A esta persona todavía no se le ha mostrado la política. Solo
            // dueños: el staff no acepta la política del cliente. Son los
            // ~2.600 tutores que vinieron de ACVet con su historial y sin
            // ningún registro (CLAUDE.md §14). El portal lo usa para mostrar un
            // aviso que NO bloquea nada.
            'privacidad_pendiente' => $u->rol === 'dueño'
                && ! (bool) ($u->privacy_accepted ?? false),
            // Ruta relativa, como la foto del especialista: el frontend le
            // antepone la URL de la API.
            'foto' => ! empty($u->foto) ? "/uploads/{$u->foto}" : null,
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
