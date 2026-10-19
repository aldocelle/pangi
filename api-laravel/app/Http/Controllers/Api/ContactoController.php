<?php

namespace App\Http\Controllers\Api;

use App\Services\LimitePorIP;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Formulario de contacto del sitio público.
 *
 * Es una de las cuatro escrituras sin token del sistema. La más simple de
 * todas —no toca agenda ni dinero— y por eso sirve de canario: si esta falla,
 * el problema es de infraestructura y no de la lógica de negocio.
 */
class ContactoController extends Controller
{
    /** Tope por IP: 5 consultas cada 10 minutos. */
    private const MAX_POR_IP = 5;
    private const VENTANA_SEG = 600;

    public function __construct(private \App\Services\Correo $correo)
    {
    }

    public function crear(Request $request)
    {
        $datos = $request->validate([
            // `MensajeIn` usa `extra="forbid"`, así que una clave de más es un
            // 422 y no un campo ignorado en silencio. Laravel no lo hace por
            // defecto: hay que pedirlo explícitamente (ver abajo).
            'nombre' => ['required', 'string', 'min:1', 'max:200'],
            'email' => ['required', 'email', 'max:255'],
            'asunto' => ['nullable', 'string', 'max:300'],
            'mensaje' => ['required', 'string', 'min:1', 'max:5000'],
            'recaptcha_token' => ['nullable', 'string'],
        ]);

        $extra = array_diff(array_keys($request->all()), [
            'nombre', 'email', 'asunto', 'mensaje', 'recaptcha_token',
        ]);
        if ($extra) {
            return response()->json(['detail' => array_map(fn ($k) => [
                'type' => 'extra_forbidden',
                'loc' => ['body', $k],
                'msg' => 'Extra inputs are not permitted',
            ], array_values($extra))], 422);
        }

        // Tope por IP. Va ANTES de reCAPTCHA porque es más barato: si alguien
        // está martillando el formulario, no vale la pena preguntarle a Google
        // en cada intento.
        $ip = $request->ip();
        $clave = 'contacto:' . $ip;
        if ($this->demasiadosIntentos($clave)) {
            return response()->json([
                'detail' => 'Recibimos varias consultas seguidas desde tu conexión. '
                    . 'Espera unos minutos o escríbenos por WhatsApp.',
            ], 429);
        }

        // ⚠ Acá reCAPTCHA sí BLOQUEA, al revés que en /citas/reservar.
        // La asimetría es deliberada: equivocarse acá sale barato —a la clínica
        // se le puede escribir por WhatsApp— mientras que un falso positivo en
        // la reserva es una hora que no se agenda y un abono que nunca se
        // cobra, sin que nadie sepa por qué.
        if (! app(\App\Services\Recaptcha::class)->verificar(
                $request->input('recaptcha_token'), $request->ip(), bloquear: true)) {
            return response()->json(
                ['detail' => 'No pudimos verificar que eres una persona. Intenta de nuevo.'],
                400);
        }

        $ahoraUtc = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        DB::table('mensajes_contacto')->insert([
            'id' => (string) Str::uuid(),
            'nombre' => trim($datos['nombre']),
            'email' => Str::lower(trim($datos['email'])),
            'asunto' => trim($datos['asunto'] ?? '') ?: null,
            'mensaje' => trim($datos['mensaje']),
            'leido' => 0,
            'created_at' => $ahoraUtc,
        ]);

        Log::info('Nueva consulta de contacto de ' . $datos['email']
            . ' (asunto: ' . (trim($datos['asunto'] ?? '') ?: 'sin asunto') . ')');

        // El aviso a la clínica va DENTRO de la respuesta acá, al revés que en
        // Python, y conviene decir por qué el cambio no es un descuido.
        //
        // Allá se manda en segundo plano porque **Railway bloquea el puerto
        // SMTP**: el intento tarda diez segundos en agotarse y dejaba el
        // formulario colgado todo ese rato. En el hosting compartido el relay
        // es local y esa razón desaparece; y aquí no hay dónde poner el trabajo
        // en segundo plano —`exec` y `proc_open` están deshabilitadas, no hay
        // scheduler— así que la alternativa real no es «en background» sino
        // «en un cron», y eso exige una columna para saber qué ya se avisó.
        //
        // Se prefiere mandarlo ahora, con el timeout de 10 s que `Correo` ya
        // impone, antes que agregar una columna y un cron por un correo.
        // **Si en el ensayo de la Fase 4 el formulario se nota lento, esto es
        // lo primero que hay que mirar.**
        //
        // Falle o no, la consulta ya está guardada: el envío no puede cambiar
        // la respuesta al visitante.
        //
        // Sin `CONTACTO_NOTIFICAR_A` no se avisa a nadie: la consulta queda
        // igual en el panel (Consultas web).
        $avisar = (string) env('CONTACTO_NOTIFICAR_A', '');
        if ($avisar !== '') {
            $this->correo->enviar(
                $avisar,
                'Consulta desde el sitio: ' . (trim($datos['asunto'] ?? '') ?: trim($datos['nombre'])),
                "Nombre: {$datos['nombre']}\nCorreo: {$datos['email']}\n\n{$datos['mensaje']}",
                self::htmlAviso($datos),
            );
        }

        return response()->json([
            'mensaje' => 'Recibimos tu consulta. Te responderemos dentro del horario de atención.',
        ], 201);
    }

    /**
     * El aviso a la clínica con la plantilla estándar. Todo viene de un
     * formulario público sin sesión: va escapado, y el mensaje conserva sus
     * saltos de línea.
     */
    public static function htmlAviso(array $datos): string
    {
        $e = fn ($v) => \App\Services\Plantilla::e($v);
        $correo = $e(Str::lower(trim($datos['email'])));
        $asunto = trim($datos['asunto'] ?? '');

        $filas = \App\Services\Plantilla::fila('👤', '<strong>' . $e(trim($datos['nombre'])) . '</strong>')
            . \App\Services\Plantilla::fila('✉️', "<a href='mailto:{$correo}' style='color:"
                . \App\Services\Plantilla::INK . "'>{$correo}</a>");
        if ($asunto !== '') {
            $filas .= \App\Services\Plantilla::fila('🔖', $e($asunto));
        }

        return \App\Services\Plantilla::pagina(
            'Nueva consulta desde el sitio',
            'Llegó por el formulario de contacto.',
            \App\Services\Plantilla::tarjeta('Quién escribe', $filas)
            . \App\Services\Plantilla::tarjeta('Mensaje',
                \App\Services\Plantilla::fila('💬', nl2br($e(trim($datos['mensaje']))))),
            'También queda en el panel, en Consultas web.'
        );
    }

    /**
     * GET /contacto/mensajes — las consultas recibidas, para el panel.
     *
     * Tope duro de 200 sin paginar, igual que Python: es una bandeja que se
     * vacía marcando leído, no un archivo histórico.
     */
    public function listar(Request $request)
    {
        $q = DB::table('mensajes_contacto');
        if ($this->booleano($request->query('solo_no_leidos'))) {
            $q->where('leido', false);
        }

        return $q->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit(200)
            ->get()->map(fn ($m) => [
                'id' => $m->id,
                'nombre' => $m->nombre,
                'email' => $m->email,
                'asunto' => $m->asunto,
                'mensaje' => $m->mensaje,
                'leido' => (bool) $m->leido,
                'created_at' => $this->iso($m->created_at),
            ])->values()->all();
    }

    /** PUT /contacto/mensajes/{id}/leido */
    public function marcarLeido(string $id)
    {
        $m = DB::table('mensajes_contacto')->where('id', $id)->first();
        if (! $m) {
            return response()->json(['detail' => 'Mensaje no encontrado'], 404);
        }
        DB::table('mensajes_contacto')->where('id', $id)->update([
            'leido' => true,
            // Marca técnica, en UTC: nunca se compara con horarios de negocio
            // (§9.6). Python usa `utcnow()` acá, no `ahora_local()`.
            'leido_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
        return ['ok' => true];
    }

    private function iso(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = str_replace(' ', 'T', substr($v, 0, 26));

        return str_ends_with($t, '.000000') ? substr($t, 0, 19) : $t;
    }

    private function booleano($v): bool
    {
        return $v !== null
            && in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
    }

    /** Tope por IP. La ventana deslizante vive en `LimitePorIP` (una sola copia). */
    private function demasiadosIntentos(string $clave): bool
    {
        return LimitePorIP::demasiados($clave, self::MAX_POR_IP, self::VENTANA_SEG);
    }
}
