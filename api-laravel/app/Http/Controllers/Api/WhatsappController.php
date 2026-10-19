<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AtenderWhatsapp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de WhatsApp Cloud API (Meta): verificación y mensajes entrantes.
 *
 * Acá solo se ENRUTA: verificar la firma, distinguir un texto de un botón de
 * una foto, descartar reintentos. Contestar es de AtenderWhatsapp, que corre
 * después de haberle respondido a Meta.
 */
class WhatsappController extends Controller
{
    /**
     * Qué decirle a alguien que manda algo que no es texto.
     *
     * **Ausente = no merece respuesta.** Una reacción con un emoji o un aviso
     * de sistema no necesita que el bot conteste nada; hacerlo sería ruido.
     */
    private const NO_LEIBLE_POR_TIPO = [
        'image' => 'no alcancé a ver la imagen',
        'sticker' => 'no alcancé a ver lo que me enviaste',
        'video' => 'no alcancé a ver el video',
        'document' => 'no pude abrir el archivo',
        // Las notas de voz llegan como `audio` con la marca `voice`, así que
        // este caso cubre los dos.
        'audio' => 'no pude escuchar el audio',
        'location' => 'no pude leer la ubicación',
        'contacts' => 'no pude leer el contacto',
        'unsupported' => 'me llegó en un formato que no puedo leer',
    ];

    /**
     * GET /whatsapp/webhook — la verificación que Meta hace UNA vez al
     * registrar la URL.
     *
     * Devuelve el `hub.challenge` en texto plano si el token coincide. Sin
     * `WHATSAPP_VERIFY_TOKEN` configurado se responde 403 siempre: un token
     * vacío que aceptara un token vacío dejaría a cualquiera registrar este
     * webhook como suyo.
     *
     * **Los parámetros se leen como `hub_mode`, no `hub.mode`.** Meta manda
     * el punto (`hub.mode`, `hub.verify_token`, `hub.challenge`), pero PHP
     * convierte los puntos y espacios de un nombre de parámetro de query
     * string a guion bajo antes de que lleguen a `$_GET` — es el propio
     * intérprete, no Laravel, así que `$request->query('hub.mode')` buscaba
     * una clave que nunca existe y caía siempre al default (`''`). El
     * resultado era un 403 constante sin importar el token: no se podía
     * distinguir de un token mal copiado, y así se detectó el 29-sep-2026 al
     * registrar el webhook del lab por primera vez. Lo fija
     * `tests/Feature/WhatsappWebhookVerificacionTest.php`, que arma la query
     * con el punto literal (como la manda Meta) para pasar por el mismo
     * parseo de PHP que un request real.
     */
    public function verificar(Request $request)
    {
        $esperado = (string) config('emr.whatsapp_verify_token');
        $modo = (string) $request->query('hub_mode', '');
        $token = (string) $request->query('hub_verify_token', '');

        if ($modo === 'subscribe' && $esperado !== '' && hash_equals($esperado, $token)) {
            return response((string) $request->query('hub_challenge', ''))
                ->header('Content-Type', 'text/plain');
        }

        return response('', 403);
    }

    /**
     * POST /whatsapp/webhook — un mensaje entrante.
     *
     * **La firma se valida primero y sobre el cuerpo CRUDO.** Meta firma cada
     * POST con HMAC-SHA256 del body usando el App Secret. Sin esa comprobación,
     * cualquiera que descubra la URL podría mandar mensajes falsos suplantando
     * el número de un cliente real — y desde acá se agendan y cancelan horas.
     */
    public function recibir(Request $request)
    {
        $cuerpo = $request->getContent();
        if (! $this->firmaValida($cuerpo, (string) $request->header('X-Hub-Signature-256', ''))) {
            return response()->json(['detail' => 'Firma inválida'], 403);
        }

        $payload = json_decode($cuerpo, true);
        $valores = [];
        foreach ($payload['entry'] ?? [] as $entrada) {
            foreach ($entrada['changes'] ?? [] as $cambio) {
                if (is_array($cambio['value'] ?? null)) {
                    $valores[] = $cambio['value'];
                }
            }
        }
        if (! $valores) {
            // Estructura inesperada: se ignora sin error. Devolver un 4xx haría
            // que Meta reintentara el mismo payload roto una y otra vez.
            Log::warning('Payload de WhatsApp con estructura inesperada');

            return ['status' => 'ignored'];
        }

        // `messages` es un ARRAY porque Meta agrupa en un solo POST lo que llega
        // seguido, y `entry`/`changes` lo son por lo mismo. Atender solo el
        // primero dejaba al resto sin respuesta: quien escribe en ráfaga
        // —«hola», «quiero hora», «para mañana»— recibía una contestación y
        // silencio a las otras dos, que desde afuera se lee como un bot roto.
        //
        // El estado que se devuelve es el del mensaje que más lejos llegó. Meta
        // no lo mira (le basta el 200), pero es lo que leen los tests y el log.
        $estado = 'ignored';
        foreach ($valores as $valor) {
            // Los eventos de `statuses` (entregado, leído) no traen `messages`.
            foreach ($valor['messages'] ?? [] as $mensaje) {
                $r = is_array($mensaje) ? $this->atenderMensaje($valor, $mensaje) : 'ignored';
                if ($r === 'received' || ($r === 'duplicate' && $estado === 'ignored')) {
                    $estado = $r;
                }
            }
        }

        return ['status' => $estado];
    }

    /**
     * Un mensaje suelto del webhook: a quién contestar y con qué.
     *
     * Devuelve `received` (se encoló una respuesta), `duplicate` (reintento de
     * Meta ya atendido) o `ignored` (no es para este número, o no hay nada que
     * contestar).
     */
    private function atenderMensaje(array $valor, array $mensaje): string
    {
        // Una app de Meta puede tener más de un número, y todos llegan a esta
        // URL. Solo se contesta lo que llegó al número configurado: lo demás
        // (el número de prueba de Meta con el de la clínica ya conectado, por
        // ejemplo) no es de este asistente.
        $numero = (string) ($valor['metadata']['phone_number_id'] ?? '');
        $propio = (string) config('emr.whatsapp_phone_number_id');
        if ($propio !== '' && $numero !== $propio) {
            return 'ignored';
        }

        $telefono = preg_replace('/\D/', '', (string) ($mensaje['from'] ?? ''));
        $tipo = $mensaje['type'] ?? null;
        $wamid = (string) ($mensaje['id'] ?? '');
        if ($telefono === '') {
            return 'ignored';
        }

        // Meta reintenta lo que no recibió a tiempo. Un mensaje ya atendido no
        // se contesta dos veces — con la IA, sería además un segundo cobro.
        if (! app(AtenderWhatsapp::class)->primeraVez($wamid)) {
            return 'duplicate';
        }

        // Respuesta a un botón o a una lista: una opción que ya le ofrecimos.
        if ($tipo === 'interactive') {
            $inter = $mensaje['interactive'] ?? [];
            $opcion = $inter['button_reply']['id'] ?? $inter['list_reply']['id'] ?? null;
            if (! $opcion) {
                Log::warning('Interactivo sin id utilizable');

                return 'ignored';
            }
            // Un horario ofrecido por un especialista: lo resuelve el código,
            // sin pasar por el modelo (IntermediarioEspecialista::elegirOpcion).
            if (str_starts_with($opcion, 'esp:')) {
                $this->atender(fn (AtenderWhatsapp $a) => $a->opcionEspecialista($telefono, $opcion, $wamid));

                return 'received';
            }
            // El ESPECIALISTA tocó un horario de su lista, o «enviar» su mensaje al tutor (IntermediarioEspecialista::opcionDelEspecialista).
            if (str_starts_with($opcion, 'hor:') || str_starts_with($opcion, 'msg:')) {
                $this->atender(fn (AtenderWhatsapp $a) => $a->opcionDelEspecialista($telefono, $opcion, $wamid));

                return 'received';
            }
            // El menú de botones de Python no está portado: hoy solo mandamos
            // botones con enlace, que no vuelven al webhook. Si llega uno (un
            // mensaje viejo), su título es lo que la persona eligió.
            $titulo = $inter['button_reply']['title'] ?? $inter['list_reply']['title'] ?? '';
            if ($titulo !== '') {
                $this->atender(fn (AtenderWhatsapp $a) => $a->texto($telefono, $titulo, $wamid));
            }

            return 'received';
        }

        // El botón de una PLANTILLA (no un interactivo nuestro) llega como
        // `button`, con el texto del botón. El caso: «Ver horarios» en el aviso
        // al especialista, que es lo que abre la ventana de 24 h para poder
        // mandarle la lista. Antes caía en «no puedo leer esto».
        if ($tipo === 'button') {
            $payload = (string) ($mensaje['button']['payload'] ?? '');
            // «ver_horarios:7» → «N° 7: Ver horarios», la misma forma que
            // escribe un especialista con varias solicitudes abiertas.
            $texto = preg_match('/^ver_horarios:(\d+)$/', $payload, $m)
                ? "N° {$m[1]}: Ver horarios"
                : trim((string) ($mensaje['button']['text'] ?? $payload));
            if ($texto !== '') {
                $this->atender(fn (AtenderWhatsapp $a) => $a->texto($telefono, mb_substr($texto, 0, 1000), $wamid));
            }

            return 'received';
        }

        // Foto o documento: puede ser la orden médica. Sin IA, AtenderWhatsapp
        // contesta el mismo aviso fijo de siempre y no descarga nada.
        if ($tipo === 'image' || $tipo === 'document') {
            $media = is_array($mensaje[$tipo] ?? null) ? $mensaje[$tipo] : [];
            $mediaId = (string) ($media['id'] ?? '');
            $nombre = (string) ($media['filename'] ?? '');
            $leyenda = mb_substr(trim((string) ($media['caption'] ?? '')), 0, 1000);
            $aviso = $this->textoNoLeible($tipo);
            $this->atender(fn (AtenderWhatsapp $a) => $a->archivo($telefono, $mediaId, $nombre, $leyenda, $wamid, $aviso));

            return 'received';
        }

        if ($tipo !== 'text') {
            // Texto fijo, sin llamada al modelo: quien manda una foto merece
            // una respuesta, pero no una que cueste tokens.
            $aviso = $this->textoNoLeible($tipo);
            if ($aviso) {
                $this->atender(fn (AtenderWhatsapp $a) => $a->aviso($telefono, $aviso));
            }

            return 'ignored';
        }

        $texto = trim((string) ($mensaje['text']['body'] ?? ''));
        if ($texto === '') {
            return 'ignored';
        }
        // Mismo tope que /chatbot/mensaje: lo que pasa de ahí no es una pregunta.
        $texto = mb_substr($texto, 0, 1000);
        $this->atender(fn (AtenderWhatsapp $a) => $a->texto($telefono, $texto, $wamid));

        return 'received';
    }

    /**
     * HMAC-SHA256 del cuerpo crudo con el App Secret.
     *
     * Se compara con `hash_equals` —tiempo constante— y se exige el prefijo
     * `sha256=` que Meta pone en la cabecera.
     */
    private function firmaValida(string $cuerpo, string $cabecera): bool
    {
        $secreto = (string) config('emr.whatsapp_app_secret');
        if ($secreto === '' || ! str_starts_with($cabecera, 'sha256=')) {
            return false;
        }
        $esperada = hash_hmac('sha256', $cuerpo, $secreto);

        return hash_equals($esperada, substr($cabecera, 7));
    }

    /**
     * El aviso para un tipo que no se puede leer, o `null` si no merece
     * respuesta.
     *
     * Si la frase configurada tiene un formato inválido se cae al default en
     * vez de reventar: el validador del panel ya bloquea las llaves raras, pero
     * una fila guardada antes de esa validación **no debe dejar al cliente sin
     * respuesta**.
     */
    private function textoNoLeible(?string $tipo): ?string
    {
        $detalle = self::NO_LEIBLE_POR_TIPO[$tipo] ?? null;
        if (! $detalle) {
            return null;
        }

        $fila = DB::table('ajustes')->where('id', 1)->first();
        $data = $fila && $fila->data ? (json_decode($fila->data, true) ?: []) : [];
        $plantilla = $data['asistente']['frases']['no_texto'] ?? null;

        $porDefecto = 'Por ahora solo puedo leer mensajes de texto, así que {detalle}. '
            . 'Cuéntame en pocas palabras qué necesitas y te ayudo a agendar una hora.';

        // Solo se reemplaza `{detalle}`: cualquier otra llave se deja tal cual
        // en vez de fallar, que es lo que hace el `except` de Python.
        $usar = $plantilla ?: $porDefecto;
        if (! str_contains($usar, '{detalle}')) {
            Log::warning("Frase 'no_texto' sin {detalle}; usando el default");
            $usar = $porDefecto;
        }

        return str_replace('{detalle}', $detalle, $usar);
    }

    /**
     * Contesta DESPUÉS de haberle respondido 200 a Meta.
     *
     * El webhook tiene que responder rápido: Meta reintenta si tarda, y la IA
     * puede tardar varios segundos. En el hosting compartido no hay un worker
     * de colas corriendo, y uno por cron contestaría con hasta un minuto de
     * atraso; `afterResponse()` corre en el mismo proceso, una vez que
     * LiteSpeed ya cerró la respuesta (`litespeed_finish_request`). En Python
     * era un `BackgroundTask`: el mismo contrato.
     */
    private function atender(\Closure $trabajo): void
    {
        dispatch(function () use ($trabajo) {
            // Varias vueltas del modelo (25 s cada una como máximo) no caben en
            // los 30 s por defecto de PHP.
            @set_time_limit(150);
            $trabajo(app(AtenderWhatsapp::class));
        })->afterResponse();
    }
}
