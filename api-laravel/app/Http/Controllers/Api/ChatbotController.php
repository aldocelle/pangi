<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use App\Services\AdjuntosWeb;
use App\Services\AdjuntosWhatsapp;
use App\Services\AgenteIA;
use App\Services\ChatDeterminista;
use App\Services\ProbarPregunta;
use App\Services\RespuestaDirecta;
use App\Services\UsoAsistente;
use App\Services\Usuarios;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Asistente conversacional.
 *
 * `/mensaje` lo llama la burbuja de chat del portal de reservas
 * (`ChatAsistente.vue`), y desde sep-2026 hace de ASISTENTE DE RESERVAS: lleva a
 * la persona a reservar en el portal en vez de derivarla a WhatsApp. Cada
 * pregunta queda en `chatbot_preguntas`, sin datos personales, para que la
 * oficina vea lo que el chat no supo contestar.
 */
class ChatbotController extends Controller
{
    /**
     * Ocho mensajes por minuto y por IP. Más estricto que el resto de los
     * límites: **cada mensaje llama al modelo y tiene costo**, así que el abuso
     * automatizado no dispara una factura, no solo carga.
     */
    private const MAX_INTENTOS = 8;

    private const VENTANA_SEG = 60;

    public function __construct(
        private Usuarios $usuarios,
        private RespuestaDirecta $directa,
        private ChatDeterminista $determinista,
    ) {
    }

    /**
     * POST /chatbot/mensaje — **público, sin token.**
     *
     * DOS CAPAS
     *
     * 1. Sin IA (ChatDeterminista): lo que la base sabe con certeza —precios,
     *    catálogo, dónde, preguntas frecuentes, «mis citas»—, gratis y exacto.
     * 2. Con IA (AgenteIA), SOLO si están el interruptor, la clave y el tope del
     *    día (UsoAsistente). Sin eso, esta ruta se comporta exactamente como
     *    antes: lo que la capa 1 no resuelve se deriva a WhatsApp.
     *
     * CUÁNDO CONTESTA LA IA (decisión de producto, 14-sep-2026: «que la IA lleve el
     * proceso de agenda en el chat»)
     *
     * - Lo que la capa 1 devuelve como null — el lugar de la derivación, como
     *   estaba previsto.
     * - **Pedir hora**, aunque AsistenteReservas lo reconozca. Es el único caso
     *   en que la IA va antes que una regla, y a propósito: reservar es una
     *   conversación («¿para qué es?», «¿el jueves?», «¿con quién?») y la capa
     *   sin IA no tiene memoria entre mensajes. Las horas que muestra igual
     *   salen de SlotsService y se comprueban antes de hacerse botón.
     * - Todo mensaje que continúa una conversación de la IA (`conversation_id`
     *   vigente): «sí, el de las 10» no significa nada sin el mensaje anterior.
     *
     * Si la IA falla (red, tope, rechazo), la respuesta es la de la capa 1 si la
     * había, o la derivación de siempre. Nunca un 500.
     *
     * SESIÓN OPCIONAL (`auth:opcional`): sin sesión, el chat atiende como
     * siempre. Con la del portal, el middleware ya dejó al usuario VALIDADO en
     * la petición y el agente la usa como WhatsApp usa el número verificado: de
     * ahí sale de quién son las horas que se pueden ver, cambiar y pagar. Se
     * toma de `attributes`, que solo escribe el middleware, nunca del cuerpo.
     *
     * ARCHIVO (la orden médica): puede venir en el mismo POST, como multipart,
     * con o sin texto. Se guarda en el servidor y queda en la conversación hasta
     * que se engancha a una cita (AdjuntosWeb).
     */
    public function mensaje(Request $request, AgenteIA $agente, UsoAsistente $uso)
    {
        $request->validate([
            // Con un archivo el texto es opcional: «te mando la orden» no hace
            // falta que lo escriba la persona.
            'texto' => 'required_without:archivo|nullable|string|max:1000',
            'conversation_id' => 'nullable|string|max:64',
            'archivo' => 'nullable|file',
        ]);

        if ($this->usuarios->demasiadosIntentos('chatbot:' . $request->ip(),
                self::MAX_INTENTOS, self::VENTANA_SEG)) {
            return response()->json(
                ['detail' => 'Muchos mensajes seguidos. Espera un momento.'], 429);
        }

        $usuario = $request->attributes->get('usuario');
        $conversacion = $request->input('conversation_id');
        $ia = $uso->puedeLlamar();

        $adjunto = null;
        if ($request->hasFile('archivo')) {
            // Sin IA no hay quien lea la orden ni la enganche a una cita: se
            // dice de frente y no se guarda un archivo que nadie va a usar.
            if (! $ia) {
                return response()->json([
                    'respuesta' => 'Ahora mismo no puedo recibir la orden por acá. Puedes adjuntarla al reservar en el '
                        . 'formulario, o escribirnos por WhatsApp al ' . config('emr.clinica_telefono') . '.',
                    'conversation_id' => $conversacion ?: '', 'acciones' => [],
                ]);
            }
            $adjunto = AdjuntosWeb::recibir($request->file('archivo'));
            if (isset($adjunto['error'])) {
                return response()->json([
                    'respuesta' => $adjunto['error'], 'conversation_id' => $conversacion ?: '', 'acciones' => [],
                ]);
            }
        }
        // Solo el archivo, sin texto: el agente necesita algo a lo que responder.
        $texto = trim((string) $request->input('texto')) !== '' ? (string) $request->input('texto') : 'Te mando un archivo.';
        // «Te mando un archivo.» no es una pregunta que enseñar.
        $preguntaReal = $request->filled('texto');

        // LA IA PRIMERO, SIEMPRE (decisión de producto, 05-oct): las respuestas sin
        // modelo quedan como respaldo para cuando la IA no está —apagada, sin
        // cupo, sin clave o con error—. La IA consulta esas mismas respuestas
        // con info_clinica, así que no se pierde ninguna. Antes iban primero
        // las respuestas fijas, por costo, y la IA solo veía lo que ellas no
        // resolvían.
        $r = null;
        $sigueConIA = $ia && $agente->conversacionViva($conversacion, null, $usuario);

        if ($ia) {
            $ai = $agente->responder($texto, $sigueConIA ? $conversacion : null, null, null, $adjunto, $usuario);
            if ($ai !== null) {
                // Solo el mensaje que ABRE la conversación. Los que la siguen son
                // respuestas («sí», «el jueves», nombre, correo y teléfono), no
                // preguntas que enseñar, y son justo los que traen datos
                // personales que `anonimizar()` no alcanza (un nombre).
                // `ia` y no null: cuenta como contestada, pero aparte (preguntas()).
                if (! $sigueConIA && $preguntaReal) {
                    $this->registrar($texto, $ai['motivo'] ?? 'ia');
                }

                return response()->json([
                    'respuesta' => $ai['texto'],
                    'conversation_id' => $ai['conversation_id'],
                    'acciones' => $ai['acciones'],
                ]);
            }
            // La IA no pudo (red, tope): el archivo no quedó en ninguna
            // conversación, así que no se deja huérfano en el disco.
            if ($adjunto !== null) {
                AdjuntosWhatsapp::descartar($adjunto['ruta'] ?? null);
            }
        }
        $r = $this->determinista->responder($texto);

        if ($preguntaReal) {
            $this->registrar($texto, $r['motivo'] ?? null);
        }

        if ($r !== null) {
            // `conversation_id` vacío y no un id: acá no hay conversación que
            // guardar —no hubo modelo ni hilo— y abrir una dejaría filas
            // huérfanas en `chatbot_conversations`. Mismo criterio que Python.
            return response()->json([
                'respuesta' => $r['texto'],
                'conversation_id' => '',
                // Botones con ruta RELATIVA al portal. Quien no los entiende
                // (WhatsApp, clientes viejos) igual tiene el texto.
                'acciones' => $r['acciones'] ?? [],
            ]);
        }

        return response()->json([
            'respuesta' => 'Ahora mismo no puedo responderte por acá. Escríbenos por '
                . 'WhatsApp al ' . config('emr.clinica_telefono') . ' y te ayudamos al tiro.',
            'conversation_id' => '',
            'acciones' => [],
        ]);
    }

    /**
     * Guarda la pregunta, sin nada que identifique a quien la hizo.
     *
     * Existe para que el chat aprenda de lo que la gente pregunta DE VERDAD: las
     * preguntas frecuentes se cargaban con lo que imaginábamos. No se guarda IP,
     * usuario ni sesión, y el texto pasa por `anonimizar()` antes de tocar la
     * base — alguien puede escribir su teléfono o su correo en el chat.
     *
     * Nunca rompe la respuesta: si la tabla no existe (base sin migrar, §13.13)
     * o falla la escritura, se loguea y el cliente recibe su respuesta igual.
     */
    private function registrar(string $texto, ?string $motivo): void
    {
        self::registrarPregunta($texto, $motivo, 'web');
    }

    /** Lo mismo, para otro canal (WhatsApp, ver AtenderWhatsapp). */
    public static function registrarPregunta(string $texto, ?string $motivo, string $canal): void
    {
        try {
            DB::table('chatbot_preguntas')->insert([
                'id' => (string) Str::uuid(),
                'texto' => mb_substr(self::anonimizar($texto), 0, 300),
                'motivo' => $motivo,
                'respondida' => $motivo !== null,
                'canal' => $canal,
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('chatbot_preguntas: no se pudo registrar la pregunta: ' . $e->getMessage());
        }
    }

    /**
     * Correos, teléfonos y RUT, reemplazados antes de guardar.
     *
     * Salvo el teléfono de la CLÍNICA, que es público y el asistente lo da a
     * cada rato. Tapado, el modelo releía sus propias respuestas con
     * «[telefono]» y en vivo (lab, 05-oct) se disculpó varias veces por un
     * número «que no salió»; y si copiaba la marca, el reemplazo de AgenteIA la
     * convertía en el teléfono del cliente o en «llama a la clínica al tu
     * teléfono».
     */
    public static function anonimizar(string $texto): string
    {
        $t = preg_replace('/[\w.+-]+@[\w-]+(\.[\w-]+)+/u', '[correo]', $texto);
        $t = preg_replace('/\b\d{1,2}\.?\d{3}\.?\d{3}-?[\dkK]\b/u', '[rut]', $t);
        $clinica = substr(preg_replace('/\D/', '', (string) config('emr.clinica_telefono')), -8);
        $t = preg_replace_callback('/\+?\d[\d\s.-]{6,}\d/u', fn ($m) => strlen($clinica) === 8
            && str_ends_with(preg_replace('/\D/', '', $m[0]), $clinica) ? $m[0] : '[telefono]', $t);

        return $t;
    }

    /**
     * GET /chatbot/preguntas — **solo admin**. Lo que el chat no supo contestar,
     * agrupado, para convertirlo en preguntas frecuentes en Ajustes → Asistente.
     */
    public function preguntas(Request $request)
    {
        $u = $request->attributes->get('usuario');
        if ($u->rol !== 'admin') {
            return response()->json(['detail' => 'Solo admin'], 403);
        }
        $dias = max(1, min(180, (int) $request->query('dias', 30)));
        $datosHoy = RespuestaDirecta::datos();
        $desde = CarbonImmutable::now('UTC')->subDays($dias)->format('Y-m-d H:i:s');

        try {
            $base = DB::table('chatbot_preguntas')->where('created_at', '>=', $desde);
            $total = (clone $base)->count();
            $respondidas = (clone $base)->where('respondida', true)->count();
            // Cuántas de las contestadas las contestó el modelo y no una regla:
            // cada una costó tokens, y es candidata a pasar a pregunta frecuente.
            $porIA = (clone $base)->where('motivo', 'ia')->count();
            $sin = (clone $base)->where('respondida', false)
                ->selectRaw('LOWER(texto) as texto, COUNT(*) as veces, MAX(created_at) as ultima')
                ->groupByRaw('LOWER(texto)')
                ->orderByDesc('veces')->orderByDesc('ultima')->orderBy('texto')
                ->limit(100)->get();
        } catch (\Throwable $e) {
            return ['dias' => $dias, 'total' => 0, 'respondidas' => 0, 'porcentaje' => null,
                'sin_respuesta' => [], 'aviso' => 'Falta la tabla chatbot_preguntas en esta base.'];
        }

        return [
            'dias' => $dias,
            'total' => $total,
            'respondidas' => $respondidas,
            'por_ia' => $porIA,
            'porcentaje' => $total ? round(100 * $respondidas / $total) : null,
            // «Sin respuesta» es HOY: lo que la oficina ya le enseñó sale de la
            // lista aunque en su momento no se contestara. Sin esto, después de
            // «Enseñarle» la pregunta seguiría ahí y parecería no haber servido.
            'sin_respuesta' => $sin->filter(fn ($f) => $this->directa->resolver($f->texto, $datosHoy) === null)
                ->take(50)->map(fn ($f) => [
                'texto' => $f->texto, 'veces' => (int) $f->veces, 'ultima' => $this->iso($f->ultima),
            ])->values(),
        ];
    }

    /**
     * POST /chatbot/probar — **solo admin**. Vista previa del modal «Enseñarle»:
     * qué contestaría el chat con una pregunta frecuente que todavía no se
     * guarda. No escribe nada (ver ProbarPregunta).
     */
    public function probar(Request $request, ProbarPregunta $probar)
    {
        $u = $request->attributes->get('usuario');
        if ($u->rol !== 'admin') {
            return response()->json(['detail' => 'Solo admin'], 403);
        }
        $request->validate([
            'textos' => 'required|array|min:1|max:5',
            'textos.*' => 'required|string|max:1000',
            'claves' => 'present|nullable|string|max:300',
            'respuesta' => 'present|nullable|string|max:800',
        ]);

        return $probar->probar($request->input('textos'), (string) $request->input('claves'), (string) $request->input('respuesta'));
    }

    private const CONV_LIMITE_MAX = 30;

    /**
     * GET /chatbot/admin/conversaciones — **solo admin**, no basta ser staff.
     *
     * Para depurar y analizar el asistente: lista paginada con filtros, sin
     * el contenido de los mensajes (eso lo trae `conversacion()`, por fila,
     * solo cuando alguien la abre — traer `messages` de las 30 filas de cada
     * página es texto que nadie va a leer en el listado).
     */
    public function conversaciones(Request $request)
    {
        $u = $request->attributes->get('usuario');
        if ($u->rol !== 'admin') {
            return response()->json(['detail' => 'Solo admin'], 403);
        }

        $q = DB::table('chatbot_conversations');

        if ($tel = trim((string) $request->query('telefono', ''))) {
            $q->where('telefono', 'like', '%' . $this->escaparLike($tel) . '%');
        }
        // «web» = sin teléfono (portal de reservas); «whatsapp» = con teléfono.
        // Es la misma distinción que usa AgenteIA para leer la última fila.
        if ($canal = $request->query('canal')) {
            if ($canal === 'whatsapp') {
                $q->whereNotNull('telefono');
            } elseif ($canal === 'web') {
                $q->whereNull('telefono');
            }
        }
        if ($d = $request->query('desde')) {
            $q->where('updated_at', '>=', substr($d, 0, 10) . ' 00:00:00');
        }
        if ($h = $request->query('hasta')) {
            $q->where('updated_at', '<=', substr($h, 0, 10) . ' 23:59:59.999999');
        }

        // Por nombre de usuario: hay que resolver antes los ids que calzan,
        // porque `chatbot_conversations` no guarda el nombre.
        if ($nombre = trim((string) $request->query('usuario', ''))) {
            $ids = DB::table('usuarios')->where('nombre', 'like', '%' . $this->escaparLike($nombre) . '%')->pluck('id');
            $q->whereIn('usuario_id', $ids);
        }

        $q->orderBy('updated_at', 'desc')->orderBy('id', 'desc');

        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min((int) $request->query('limit', self::CONV_LIMITE_MAX), self::CONV_LIMITE_MAX));
        $total = (clone $q)->reorder()->count();
        $convs = $q->offset(($page - 1) * $limit)->limit($limit)->get();

        $ids = $convs->pluck('usuario_id')->filter()->unique();
        $nombres = $ids->isEmpty() ? collect()
            : DB::table('usuarios')->whereIn('id', $ids)->pluck('nombre', 'id');

        return [
            'items' => $convs->map(function ($c) use ($nombres) {
                $msgs = $c->messages ? (json_decode($c->messages, true) ?: []) : [];

                return [
                    'id' => $c->id,
                    // «Anónimo» cuando no hay usuario: el chat se puede usar sin
                    // sesión, y esa fila igual es una conversación real.
                    'usuario' => $nombres[$c->usuario_id] ?? 'Anónimo',
                    'telefono' => $c->telefono,
                    'canal' => $c->telefono ? 'whatsapp' : 'web',
                    'es_activa' => (bool) $c->es_activa,
                    // Se divide por dos porque `messages` guarda el par
                    // pregunta+respuesta: lo que la clínica quiere ver es
                    // cuántos intercambios hubo, no cuántas filas.
                    'mensajes' => intdiv(count($msgs), 2),
                    'created' => $this->iso($c->created_at),
                    'updated' => $this->iso($c->updated_at),
                ];
            })->values(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_paginas' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /**
     * GET /chatbot/admin/conversaciones/{id} — **solo admin**. El detalle que
     * el listado no trae: los mensajes completos (ya anonimizados al
     * guardarse, ver `AgenteIA::guardar()`) y el estado del borrador/flujo.
     */
    public function conversacion(Request $request, string $id)
    {
        $u = $request->attributes->get('usuario');
        if ($u->rol !== 'admin') {
            return response()->json(['detail' => 'Solo admin'], 403);
        }

        $c = DB::table('chatbot_conversations')->where('id', $id)->first();
        if (! $c) {
            return response()->json(['detail' => 'No encontrada'], 404);
        }

        $nombre = $c->usuario_id
            ? DB::table('usuarios')->where('id', $c->usuario_id)->value('nombre')
            : null;

        return [
            'id' => $c->id,
            'usuario' => $nombre ?? 'Anónimo',
            'telefono' => $c->telefono,
            'canal' => $c->telefono ? 'whatsapp' : 'web',
            'es_activa' => (bool) $c->es_activa,
            'messages' => $c->messages ? (json_decode($c->messages, true) ?: []) : [],
            'estado' => $c->estado ? (json_decode($c->estado, true) ?: null) : null,
            'created' => $this->iso($c->created_at),
            'updated' => $this->iso($c->updated_at),
        ];
    }

    private function escaparLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
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
