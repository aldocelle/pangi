<?php

namespace App\Services;

use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\ChatbotController;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Contesta un mensaje de WhatsApp, ya validado por WhatsappController. Corre
 * DESPUÉS de responderle 200 a Meta (ver el controlador).
 *
 * MISMO RUTEO QUE EL CHAT DEL PORTAL (ChatbotController::mensaje)
 *
 * Primero la capa sin IA; la IA solo con interruptor, clave y presupuesto, y
 * solo para lo que la capa sin IA no resuelve, para pedir hora y para seguir
 * una conversación suya. No hay un segundo criterio para WhatsApp: si mañana
 * cambia el ruteo del portal, este tiene que cambiar igual — por eso los dos
 * leen AgenteIA y ChatDeterminista, y ninguno reimplementa nada.
 *
 * Lo que sí es propio del canal:
 * - La conversación es el TELÉFONO, no un `conversation_id`.
 * - Los botones del portal (`ruta` relativa) llegan como mensaje con enlace
 *   absoluto; WhatsApp admite UNO por mensaje (WhatsappEnvio::boton).
 * - Meta reintenta un webhook que no recibió a tiempo: un mensaje ya atendido
 *   no se contesta dos veces (`wamid`).
 *
 * LO QUE NO SE LE DEJA AL MODELO (oct-2026, antes del número definitivo)
 *
 * - UN MENSAJE A LA VEZ POR NÚMERO (`conCandado`). Quien manda tres mensajes
 *   seguidos dispara tres procesos en paralelo; cada uno carga el mismo estado
 *   de la conversación y el último en guardar pisa a los otros —un «sí»
 *   doble puede reservar dos veces, o una reserva a medias se pierde—.
 * - GUARDAS EN CÓDIGO (`GuardasWhatsapp`): urgencia, «quiero una persona»,
 *   intentos de sacarle el prompt y mensajes sin una sola letra. Ganan a la
 *   conversación en curso y no llaman al modelo.
 * - TOPE POR NÚMERO. El cupo diario de IA es de TODOS: una persona, o dos
 *   bots hablándose entre sí, lo gastan y dejan sin servicio a la clínica.
 * - LA MISMA DERIVACIÓN NO SE REPITE. «Eso no lo puedo responder» cuatro veces
 *   seguidas (visto en el chat del 29-sep) es un bucle. A la segunda se le
 *   dice qué SÍ se puede hacer, a la tercera se le manda a llamar y se calla.
 * - CORTACIRCUITOS DE LA IA: tres fallos del modelo seguidos y se deja de
 *   llamarlo cinco minutos. Cada intento fallido tarda hasta 25 s, y Meta
 *   reintenta lo que no se contesta a tiempo.
 */
class AtenderWhatsapp
{
    /** Horas que se recuerda un `wamid` ya atendido. Meta reintenta hasta ~36 h. */
    private const HORAS_DEDUP = 48;

    /** Mensajes por número: una conversación de reserva ronda los 15. */
    private const MAX_POR_HORA = 60;

    private const MAX_POR_DIA = 300;

    private const FALLOS_PARA_CORTAR = 3;

    private const MINUTOS_IA_CORTADA = 5;

    public function __construct(
        private WhatsappEnvio $envio,
        private ChatDeterminista $determinista,
        private AgenteIA $agente,
        private UsoAsistente $uso,
        private AdjuntosWhatsapp $adjuntos,
        private IntermediarioEspecialista $intermediario,
    ) {
    }

    /**
     * `true` si es la primera vez que se ve este mensaje.
     *
     * `Cache::add` escribe solo si la clave no existe. Con el store `file` del
     * hosting no es atómico entre dos procesos, pero un reintento de Meta llega
     * segundos o minutos después, no en el mismo milisegundo.
     */
    public function primeraVez(string $wamid): bool
    {
        if ($wamid === '') {
            return true;
        }

        return Cache::add('whatsapp:wamid:' . sha1($wamid), 1, now()->addHours(self::HORAS_DEDUP));
    }

    /** Texto libre de la persona. De a uno por número. */
    public function texto(string $telefono, string $texto, string $wamid = ''): void
    {
        $this->conCandado($telefono, fn () => $this->atenderTexto($telefono, $texto, $wamid));
    }

    private function atenderTexto(string $telefono, string $texto, string $wamid): void
    {
        try {
            $config = CatalogoController::asistenteConfig();
            if (! ($config['activo_whatsapp'] ?? true)) {
                // Apagado desde el panel: se contesta igual —dejar a alguien sin
                // respuesta es peor que decirle que no estamos—, sin modelo.
                $this->envio->texto($telefono, $config['frases']['apagado']);

                return;
            }
            $this->envio->leidoYEscribiendo($wamid);

            // Un especialista contestando una solicitud que espera su respuesta:
            // se le transmite al tutor y no pasa por el asistente.
            if ($this->intermediario->recibirDeEspecialista($telefono, $texto)) {
                return;
            }

            // Guardas en código (GuardasWhatsapp). La urgencia va ANTES del tope:
            // es lo único que no puede quedar sin respuesta.
            $guarda = GuardasWhatsapp::clasificar($texto);
            if ($guarda === 'urgencia') {
                $this->aplicarGuarda($telefono, $texto, $guarda);

                return;
            }
            if (! $this->dentroDelLimite($telefono)) {
                return;
            }
            if ($guarda !== null) {
                $this->aplicarGuarda($telefono, $texto, $guarda);

                return;
            }

            // Un horario de la lista escrito en vez de tocado: se agenda sin
            // modelo. Y un «sí»/«no» a la pregunta del especialista, tal cual.
            $elegido = $this->intermediario->eleccionEscrita($telefono, $texto)
                ?? $this->intermediario->respuestaCorta($telefono, $texto);
            if ($elegido !== null) {
                $this->enviar($telefono, $elegido, $this->intermediario->tomarAcciones());

                return;
            }
            // «¿Y?», «¿qué dijo?», «no entiendo» con una consulta abierta: el
            // estado real, sin modelo.
            if ($this->intermediario->dudaDelTutor($telefono, $texto)) {
                return;
            }
            // «No» a «¿Necesitas agendar otra hora?»: se despide, sin modelo y
            // aunque la IA esté apagada o sin cupo.
            if (($adios = $this->agente->despedida($telefono, $texto)) !== null) {
                $this->envio->texto($telefono, $adios);

                return;
            }

            $r = $this->responder($telefono, $texto);
            if (! empty($r['fallback'])) {
                $r = $this->escalarFallback($telefono, $r);
                if ($r === null) {
                    return;   // ya se le dijo que llame: repetirlo no ayuda
                }
            } else {
                Cache::forget($this->clave('fallbacks', $telefono));
            }
        } catch (\Throwable $e) {
            Log::error("whatsapp: error atendiendo a {$telefono}: " . $e->getMessage());
            $r = ['texto' => $this->fraseError(), 'acciones' => []];
        }

        $this->enviar($telefono, $r['texto'], $r['acciones'] ?? []);
    }

    /** El tutor tocó un horario que ofreció un especialista. Sin modelo. */
    public function opcionEspecialista(string $telefono, string $id, string $wamid = ''): void
    {
        $this->conCandado($telefono, fn () => $this->atenderOpcion($telefono, $id, $wamid));
    }

    private function atenderOpcion(string $telefono, string $id, string $wamid): void
    {
        try {
            $this->envio->leidoYEscribiendo($wamid);
            $texto = $this->intermediario->elegirOpcion($telefono, $id);
        } catch (\Throwable $e) {
            Log::error("whatsapp: error con la opción {$id} de {$telefono}: " . $e->getMessage());
            $texto = $this->fraseError();
        }
        // Con el botón de pagar el abono, si la hora quedó retenida.
        $this->enviar($telefono, $texto, $this->intermediario->tomarAcciones());
    }

    /** El especialista tocó un horario de su lista, o «Enviar al tutor». Sin modelo. */
    public function opcionDelEspecialista(string $telefono, string $id, string $wamid = ''): void
    {
        $this->conCandado($telefono, function () use ($telefono, $id, $wamid) {
            try {
                $this->envio->leidoYEscribiendo($wamid);
                $texto = $this->intermediario->opcionDelEspecialista($telefono, $id);
            } catch (\Throwable $e) {
                Log::error("whatsapp: error con la opción {$id} del especialista {$telefono}: " . $e->getMessage());
                $texto = $this->fraseError();
            }
            if ($texto !== '') {
                $this->envio->texto($telefono, $texto);
            }
        });
    }

    /** Aviso fijo para lo que no es texto (foto, audio). Sin modelo. */
    public function aviso(string $telefono, string $texto): void
    {
        $this->envio->texto($telefono, $texto);
    }

    /**
     * Una foto o un PDF: la orden médica para reservar, o para una hora ya
     * tomada.
     *
     * Sin IA no hay quién la use, así que ni se descarga: sale el mismo aviso
     * fijo de siempre (`$avisoSinIA`). Con IA se guarda (AdjuntosWhatsapp) y el
     * agente decide a qué cita va, con la persona.
     */
    public function archivo(string $telefono, string $mediaId, string $nombre, string $leyenda,
                            string $wamid, ?string $avisoSinIA): void
    {
        $this->conCandado($telefono, fn () => $this->atenderArchivo($telefono, $mediaId, $nombre, $leyenda, $wamid, $avisoSinIA));
    }

    private function atenderArchivo(string $telefono, string $mediaId, string $nombre, string $leyenda,
                                    string $wamid, ?string $avisoSinIA): void
    {
        $adjunto = null;
        try {
            if (! $this->dentroDelLimite($telefono)) {
                return;
            }
            $config = CatalogoController::asistenteConfig();
            if (! ($config['activo_whatsapp'] ?? true)) {
                $this->envio->texto($telefono, $config['frases']['apagado']);

                return;
            }
            if (! $this->uso->puedeLlamar()) {
                if ($avisoSinIA) {
                    $this->envio->texto($telefono, $avisoSinIA);
                }

                return;
            }
            $this->envio->leidoYEscribiendo($wamid);

            $adjunto = $this->adjuntos->recibir($mediaId, $nombre);
            if (isset($adjunto['error'])) {
                $this->envio->texto($telefono, $adjunto['error']);

                return;
            }

            $ai = $this->agente->responder($leyenda !== '' ? $leyenda : 'Te mando un archivo.', null, null, $telefono, $adjunto);
            if ($ai === null) {
                // El modelo no contestó y la conversación no se guardó: el archivo
                // no quedó enganchado a nada, así que no se deja tirado.
                AdjuntosWhatsapp::descartar($adjunto['ruta']);
                $r = ['texto' => 'Recibí tu archivo, pero no pude procesarlo ahora. Llámanos al '
                    . config('emr.clinica_telefono') . ' y lo vemos contigo.', 'acciones' => []];
            } else {
                $r = ['texto' => $ai['texto'], 'acciones' => $ai['acciones']];
            }
        } catch (\Throwable $e) {
            Log::error("whatsapp: error atendiendo un archivo de {$telefono}: " . $e->getMessage());
            $r = ['texto' => $this->fraseError(), 'acciones' => []];
        }

        $this->enviar($telefono, $r['texto'], $r['acciones'] ?? []);
    }

    /** `['texto', 'acciones']`. Nunca null: alguien escribió y espera algo. */
    private function responder(string $telefono, string $texto): array
    {
        $ia = $this->uso->puedeLlamar();
        $iaCortada = $ia && Cache::has($this->clave('ia-caida'));
        $ia = $ia && ! $iaCortada;
        $sigueConIA = $ia && $this->agente->conversacionViva(null, $telefono);

        // LA IA PRIMERO, SIEMPRE (decisión de producto, 05-oct): la capa sin modelo
        // es el respaldo para cuando la IA no está —apagada, sin cupo, cortada
        // por fallos o con error—. La IA lee esas mismas respuestas con
        // info_clinica. Las guardas (urgencia, manipulación, tope) siguen
        // ANTES de todo esto, en atenderTexto().
        $r = null;
        if ($ia) {
            try {
                $ai = $this->agente->responder($texto, null, null, $telefono);
            } catch (\Throwable $e) {
                Log::error("whatsapp: el agente lanzó una excepción con {$telefono}: " . $e->getMessage());
                $ai = null;
            }
            if ($ai === null) {
                $this->anotarFalloIA();
            } else {
                Cache::forget($this->clave('ia-fallos'));
            }
            if ($ai !== null) {
                if (! $sigueConIA) {
                    ChatbotController::registrarPregunta($texto, $ai['motivo'] ?? 'ia', 'whatsapp');
                }

                return ['texto' => $ai['texto'], 'acciones' => $ai['acciones']];
            }
        }
        $r = $this->determinista->responder($texto);

        ChatbotController::registrarPregunta($texto, $r['motivo'] ?? null, 'whatsapp');

        if ($r !== null) {
            // La capa sin IA está escrita para el portal, donde «Mis citas» pide
            // sesión. Acá el número ya identifica a la persona: sus horas se
            // dicen desde la base.
            if (($r['motivo'] ?? null) === 'mis_citas' && ($horas = $this->agente->horasSinIA($telefono)) !== null) {
                return ['texto' => $horas, 'acciones' => []];
            }
            // Su única derivación literal a WhatsApp, leída DENTRO de WhatsApp,
            // no dice nada.
            $r['texto'] = str_replace('escríbenos por WhatsApp al ', 'llámanos al ', $r['texto']);

            return $r;
        }

        // Con la IA cortada o sin cupo del día, lo que la capa sin IA no
        // resolvió no es «algo que no sé»: es un problema nuestro, y se dice
        // así. En vivo (lab, 05-oct) el cupo se acabó a mitad de una
        // conversación y la persona recibió «Todavía no logro entender qué
        // necesitas» a mensajes que la IA entendía un minuto antes, y después
        // silencio. Con la IA apagada a propósito (producción) sigue la
        // derivación que escala.
        if ($iaCortada || $this->uso->agotado()) {
            return [
                'texto' => 'Ahora mismo tengo problemas para atender por acá. Llámanos al '
                    . config('emr.clinica_telefono') . ' y te ayudamos al tiro.',
                'acciones' => [],
            ];
        }

        return [
            'texto' => 'Eso no lo puedo responder por acá. Llámanos al ' . config('emr.clinica_telefono')
                . ' y te ayudamos al tiro.',
            'acciones' => [],
            'fallback' => true,
        ];
    }

    // ── Lo que no se le deja al modelo ───────────────────────────────────

    private function clave(string $tipo, ?string $telefono = null): string
    {
        return "whatsapp:{$tipo}" . ($telefono !== null ? ':' . sha1($telefono) : '');
    }

    /**
     * Un mensaje a la vez por número. Si otro proceso lo tiene más de dos
     * minutos (un modelo colgado), se atiende igual: dejar sin respuesta es
     * peor que el riesgo de una carrera, que ya es raro.
     */
    private function conCandado(string $telefono, \Closure $trabajo): void
    {
        $candado = Cache::lock($this->clave('tel', $telefono), 170);
        $lo_tiene = false;
        try {
            $candado->block(120);
            $lo_tiene = true;
        } catch (LockTimeoutException) {
            Log::warning("whatsapp: el candado de {$telefono} seguía ocupado tras 120 s; se atiende igual");
        }
        try {
            $trabajo();
        } finally {
            if ($lo_tiene) {
                $candado->release();
            }
        }
    }

    /** La respuesta fija de una guarda, sin modelo. */
    private function aplicarGuarda(string $telefono, string $texto, string $guarda): void
    {
        Log::info("whatsapp: guarda «{$guarda}» con …" . substr($telefono, -4));
        ChatbotController::registrarPregunta($texto, 'guarda:' . $guarda, 'whatsapp');
        $this->envio->texto($telefono, GuardasWhatsapp::respuesta($guarda));
    }

    /**
     * ¿Sigue dentro del tope de mensajes? Al pasarse se le avisa UNA vez por
     * hora y después se calla: contestarle a cada mensaje del exceso es
     * justamente lo que se quiere evitar.
     */
    private function dentroDelLimite(string $telefono): bool
    {
        $k = sha1($telefono);
        if (! LimitePorIP::demasiados("wa-hora:{$k}", self::MAX_POR_HORA, 3600)
            && ! LimitePorIP::demasiados("wa-dia:{$k}", self::MAX_POR_DIA, 86400)) {
            return true;
        }
        if (Cache::add($this->clave('limite', $telefono), 1, now()->addHour())) {
            Log::warning("whatsapp: …" . substr($telefono, -4) . ' pasó el tope de mensajes');
            $this->envio->texto($telefono, 'Recibimos muchos mensajes seguidos de este número. '
                . 'Para que te atiendan bien, llámanos al ' . config('emr.clinica_telefono') . '.');
        }

        return false;
    }

    /**
     * La derivación genérica, sin repetirla. 1.ª vez: la de siempre. 2.ª: qué
     * SÍ se puede hacer por aquí. 3.ª o más: se le manda a llamar, una vez cada
     * 30 min, y después `null` (no se contesta). Cualquier respuesta útil
     * reinicia la cuenta, así que callarse nunca bloquea un pedido válido.
     */
    private function escalarFallback(string $telefono, array $r): ?array
    {
        $k = $this->clave('fallbacks', $telefono);
        $n = (int) Cache::get($k, 0) + 1;
        Cache::put($k, $n, now()->addHour());
        $tel = config('emr.clinica_telefono');

        if ($n === 1) {
            return $r;
        }
        if ($n === 2) {
            return ['acciones' => [], 'texto' => "Todavía no logro entender qué necesitas. Esto sí puedo hacerlo por acá:\n"
                . "• Reservar, cambiar o cancelar una hora\n• Contarte los valores y qué consultas atendemos\n"
                . "• Decirte dónde estamos\n\nSi es otra cosa, llámanos al {$tel}."];
        }
        if (Cache::add($this->clave('handoff', $telefono), 1, now()->addMinutes(30))) {
            return ['acciones' => [], 'texto' => "Parece que no estoy logrando ayudarte por acá. Mejor llámanos al {$tel} "
                . 'y una persona te atiende.'];
        }

        return null;
    }

    /** Un fallo del modelo. A los tres seguidos se deja de llamarlo unos minutos. */
    private function anotarFalloIA(): void
    {
        $k = $this->clave('ia-fallos');
        Cache::add($k, 0, now()->addMinutes(2));
        if ((int) Cache::increment($k) >= self::FALLOS_PARA_CORTAR) {
            Cache::put($this->clave('ia-caida'), 1, now()->addMinutes(self::MINUTOS_IA_CORTADA));
            Cache::forget($k);
            Log::error('whatsapp: el modelo falló ' . self::FALLOS_PARA_CORTAR . ' veces seguidas; '
                . 'no se le llama por ' . self::MINUTOS_IA_CORTADA . ' minutos');
        }
    }

    /**
     * El texto, y los botones como enlaces.
     *
     * Con un botón, va pegado al texto en un solo mensaje. Con varios (la capa
     * sin IA ofrece una consulta por botón), mandar un mensaje por cada uno
     * sería una ráfaga de avisos en el teléfono: se listan en el texto y va un
     * solo enlace al portal, donde se elige.
     */
    private function enviar(string $telefono, string $texto, array $acciones): void
    {
        $base = config('emr.portal_reservas_url');
        $acciones = array_values(array_filter($acciones, fn ($a) => ! empty($a['ruta']) && ! empty($a['texto'])));

        if (count($acciones) === 1) {
            $a = $acciones[0];
            // El botón admite 20 caracteres: «Pagar abono de $22.500» ya no cabe,
            // y cortado a «Pagar abono de $22.…» esconde justo el monto. Lo largo
            // va entero en el cuerpo y el botón dice solo qué hace.
            [$cuerpo, $etiqueta] = mb_strlen($a['texto']) <= 20
                ? [$texto, $a['texto']]
                : [$texto . "\n\n" . $a['texto'] . '.', str_starts_with($a['texto'], 'Pagar') ? 'Pagar' : 'Abrir'];
            if ($this->envio->boton($telefono, $cuerpo, $etiqueta, $base . $a['ruta'])) {
                return;
            }
            // Si Meta rechaza el interactivo, que al menos llegue el texto con el enlace.
            $this->envio->texto($telefono, $texto . "\n\n" . $a['texto'] . ': ' . $base . $a['ruta']);

            return;
        }

        if (count($acciones) > 1) {
            $lineas = array_map(fn ($a) => '• ' . $a['texto'], $acciones);
            $cuerpo = $texto . "\n\n" . implode("\n", $lineas);
            if ($this->envio->boton($telefono, $cuerpo, 'Reservar en línea', $base . '/reservar')) {
                return;
            }
            $this->envio->texto($telefono, $cuerpo . "\n\n" . $base . '/reservar');

            return;
        }

        $this->envio->texto($telefono, $texto);
    }

    private function fraseError(): string
    {
        try {
            return CatalogoController::asistenteConfig()['frases']['error_tecnico'];
        } catch (\Throwable) {
            // Si la base es justamente lo que falló, un texto fijo igual sale.
            return 'Disculpa, tuvimos un problema técnico. Intenta de nuevo en unos minutos.';
        }
    }
}
