<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * La única salida hacia la Graph API de WhatsApp. Port de `_enviar*` de
 * `whatsapp.py`, con los mismos límites.
 *
 * **Nunca lanza.** Un envío que falla se loguea y devuelve false: quien llama
 * ya terminó de responderle a Meta y no hay a quién devolverle un error.
 *
 * Los límites de abajo no son recomendaciones: pasarse de cualquiera hace que
 * Meta rechace el mensaje con un 400 y la persona no reciba NADA. Se recortan
 * acá, una sola vez, en vez de confiar en que cada llamador se acuerde.
 */
class WhatsappEnvio
{
    private const TIMEOUT = 15;

    /** Cuerpo de un mensaje de texto. */
    private const MAX_TEXTO = 4096;

    /** Cuerpo de un mensaje interactivo (el del botón). */
    private const MAX_CUERPO_INTERACTIVO = 1024;

    /** Texto visible de un botón con URL. */
    private const MAX_TEXTO_BOTON = 20;

    /** Un parámetro de plantilla. */
    private const MAX_PARAMETRO = 500;

    public function habilitado(): bool
    {
        return config('emr.whatsapp_access_token') !== '' && config('emr.whatsapp_phone_number_id') !== '';
    }

    public function texto(string $para, string $cuerpo): bool
    {
        return $this->enviar($para, [
            'type' => 'text',
            'text' => ['body' => self::cortar($cuerpo, self::MAX_TEXTO), 'preview_url' => false],
        ]);
    }

    /**
     * Un mensaje con UN botón que abre una URL (`cta_url`).
     *
     * Los botones de respuesta (`button`) no sirven para esto: devuelven un id
     * al webhook, no abren nada. Y `cta_url` admite uno solo por mensaje, así
     * que quien tenga varios destinos manda varios mensajes o elige uno.
     */
    public function boton(string $para, string $cuerpo, string $textoBoton, string $url): bool
    {
        return $this->enviar($para, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'cta_url',
                'body' => ['text' => self::cortar($cuerpo, self::MAX_CUERPO_INTERACTIVO)],
                'action' => [
                    'name' => 'cta_url',
                    'parameters' => [
                        'display_text' => self::cortar($textoBoton, self::MAX_TEXTO_BOTON),
                        'url' => $url,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Opciones para TOCAR, que vuelven al webhook con su id (`button_reply` o
     * `list_reply`). Hasta 3 van como botones —un toque, sin abrir nada—; más,
     * como lista (máx. 10 filas).
     *
     * @param list<array{id: string, titulo: string}> $opciones
     */
    public function opciones(string $para, string $cuerpo, string $textoLista, array $opciones): bool
    {
        $opciones = array_slice($opciones, 0, 10);
        $cuerpo = self::cortar($cuerpo, self::MAX_CUERPO_INTERACTIVO);
        if (count($opciones) <= 3) {
            return $this->enviar($para, [
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => $cuerpo],
                    'action' => ['buttons' => array_map(fn ($o) => ['type' => 'reply', 'reply' => [
                        'id' => $o['id'], 'title' => self::cortar($o['titulo'], self::MAX_TEXTO_BOTON)]], $opciones)],
                ],
            ]);
        }

        return $this->enviar($para, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $cuerpo],
                'action' => [
                    'button' => self::cortar($textoLista, self::MAX_TEXTO_BOTON),
                    'sections' => [['rows' => array_map(fn ($o) => [
                        'id' => $o['id'], 'title' => self::cortar($o['titulo'], 24)], $opciones)]],
                ],
            ],
        ]);
    }

    /**
     * Un mensaje de plantilla aprobada por Meta, con parámetros de cuerpo.
     *
     * Es la única forma de escribirle a alguien que no le habló a este número
     * en las últimas 24 h (un especialista, por ejemplo): fuera de esa ventana
     * Meta acepta el texto libre con 200 y después no lo entrega.
     *
     * Meta rechaza un parámetro con salto de línea, tabulación o más de cuatro
     * espacios seguidos, así que se aplanan acá.
     */
    /**
     * @param  list<string>  $payloadsBotones  el dato que devuelve cada botón de
     *   respuesta rápida de la plantilla al tocarlo (en orden). Meta lo manda
     *   de vuelta en `button.payload`: así se sabe a QUÉ solicitud responde un
     *   «Ver horarios», aunque el especialista tenga dos abiertas.
     */
    public function plantilla(string $para, string $nombre, string $idioma, array $parametros,
        array $payloadsBotones = []): bool
    {
        $componentes = [[
            'type' => 'body',
            'parameters' => array_map(fn ($v) => [
                'type' => 'text',
                'text' => self::cortar(preg_replace('/\s+/u', ' ', (string) $v) ?: '-', self::MAX_PARAMETRO),
            ], array_values($parametros)),
        ]];
        foreach (array_values($payloadsBotones) as $i => $payload) {
            $componentes[] = ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => (string) $i,
                'parameters' => [['type' => 'payload', 'payload' => $payload]]];
        }

        return $this->enviar($para, [
            'type' => 'template',
            'template' => ['name' => $nombre, 'language' => ['code' => $idioma], 'components' => $componentes],
        ]);
    }

    /**
     * Marca el mensaje como leído y muestra «escribiendo…» mientras se arma la
     * respuesta. La IA puede tardar varios segundos, y sin señal la persona
     * vuelve a escribir lo mismo — que es un segundo mensaje al modelo.
     */
    public function leidoYEscribiendo(string $wamid): void
    {
        if (! $this->habilitado() || $wamid === '') {
            return;
        }
        try {
            Http::withToken(config('emr.whatsapp_access_token'))->timeout(self::TIMEOUT)
                ->post($this->url(), [
                    'messaging_product' => 'whatsapp',
                    'status' => 'read',
                    'message_id' => $wamid,
                    'typing_indicator' => ['type' => 'text'],
                ]);
        } catch (\Throwable $e) {
            // Cosmético: si falla, la respuesta sale igual.
            Log::info('whatsapp: no se pudo marcar como leído: ' . $e->getMessage());
        }
    }

    /**
     * Baja un archivo que mandó la persona (foto o PDF).
     *
     * Son dos llamadas: el `media_id` del webhook da una URL temporal (vale unos
     * minutos) y esa URL se pide con el mismo token. El tamaño se mira en la
     * PRIMERA, antes de bajar nada: WhatsApp admite documentos de 100 MB y
     * traerlos a memoria para después rechazarlos es justo lo que hay que evitar
     * en un hosting compartido.
     *
     * @return array{bytes: string, mime: string}|array{error: 'grande'|'fallo'}
     */
    public function descargar(string $mediaId, int $tope): array
    {
        if (! $this->habilitado() || $mediaId === '') {
            return ['error' => 'fallo'];
        }
        try {
            $info = Http::withToken(config('emr.whatsapp_access_token'))->timeout(self::TIMEOUT)
                ->get('https://graph.facebook.com/' . config('emr.whatsapp_graph_version') . '/' . rawurlencode($mediaId));
            if ($info->failed() || ! $info->json('url')) {
                Log::warning('whatsapp: no se pudo leer el archivo ' . $mediaId . ': ' . mb_substr($info->body(), 0, 300));

                return ['error' => 'fallo'];
            }
            if ((int) $info->json('file_size', 0) > $tope) {
                return ['error' => 'grande'];
            }
            $r = Http::withToken(config('emr.whatsapp_access_token'))->timeout(30)->get($info->json('url'));
            if ($r->failed()) {
                Log::warning('whatsapp: no se pudo bajar el archivo ' . $mediaId);

                return ['error' => 'fallo'];
            }
            $bytes = $r->body();
            if (strlen($bytes) > $tope) {
                return ['error' => 'grande'];
            }

            return ['bytes' => $bytes, 'mime' => strtolower(trim(explode(';', (string) $info->json('mime_type'))[0]))];
        } catch (\Throwable $e) {
            Log::error('whatsapp: error de red al bajar un archivo: ' . $e->getMessage());

            return ['error' => 'fallo'];
        }
    }

    /** Rango de teléfonos de los datos de prueba (`demo:sembrar`): `+56 9 0000 0…`. */
    public const PREFIJO_PRUEBA = '5690000';

    private function enviar(string $para, array $cuerpo): bool
    {
        // A un número del rango de prueba no se le escribe nunca, venga de donde
        // venga el envío. Es el equivalente de los correos `@pruebas.invalid`:
        // en la base de pruebas hay clientes inventados con estos teléfonos, y
        // un aviso disparado ahí no puede terminar en el WhatsApp de alguien.
        if (str_starts_with(preg_replace('/\D/', '', $para), self::PREFIJO_PRUEBA)) {
            Log::info('whatsapp: envío omitido, número del rango de prueba');

            return false;
        }
        if (! $this->habilitado()) {
            Log::warning('whatsapp: envío omitido, faltan WHATSAPP_ACCESS_TOKEN o WHATSAPP_PHONE_NUMBER_ID');

            return false;
        }
        try {
            $r = Http::withToken(config('emr.whatsapp_access_token'))->timeout(self::TIMEOUT)
                ->post($this->url(), ['messaging_product' => 'whatsapp', 'to' => $para] + $cuerpo);
            if ($r->failed()) {
                Log::error('whatsapp: Meta rechazó el envío: ' . mb_substr($r->body(), 0, 500));
                self::anotarRechazo((int) $r->json('error.code'), (string) $r->json('error.message'));

                return false;
            }
            // Si venía fallando y volvió a funcionar, el aviso del panel se va solo.
            Cache::forget(self::CLAVE_ERROR);

            return true;
        } catch (\Throwable $e) {
            Log::error('whatsapp: error de red al enviar: ' . $e->getMessage());

            return false;
        }
    }

    /** Dónde queda el último rechazo de Meta, para el aviso de Ajustes → Asistente IA. */
    public const CLAVE_ERROR = 'whatsapp:ultimo-error-envio';

    /**
     * Lo que significa el código de error que devuelve Meta, dicho para quien
     * administra la clínica. El que más importa es el 190: el token de acceso
     * venció o lo revocaron, y el bot sigue «funcionando» —recibe los mensajes,
     * la IA contesta— pero NADA le llega al cliente. Sin este aviso, solo se
     * entera quien lea el log del servidor.
     */
    public static function explicarError(int $codigo): string
    {
        return match (true) {
            $codigo === 190 => 'El token de acceso de WhatsApp venció o fue revocado. Hay que generar uno nuevo '
                . '(Meta → Usuarios del sistema) y cargarlo en el servidor.',
            $codigo === 131030 => 'Ese número no está en la lista de destinatarios permitidos del número de prueba de Meta.',
            $codigo === 131047 => 'Pasaron más de 24 horas desde que la persona escribió: solo se le puede enviar una plantilla aprobada.',
            $codigo === 131026 => 'El mensaje no se pudo entregar: el número no tiene WhatsApp o lo bloqueó.',
            $codigo === 131056 => 'Se están enviando demasiados mensajes a la misma persona; Meta frena el envío un rato.',
            $codigo === 132000 || ($codigo >= 132001 && $codigo <= 132015) =>
                'La plantilla de WhatsApp no existe, no está aprobada o sus variables no calzan.',
            $codigo === 130429 || $codigo === 80007 => 'Se pasó el límite de envíos por segundo de la cuenta; Meta frena unos minutos.',
            $codigo === 368 || $codigo === 131031 => 'Meta bloqueó o restringió la cuenta de WhatsApp de la clínica.',
            default => 'Meta rechazó el envío' . ($codigo ? " (código {$codigo})" : '') . '.',
        };
    }

    private static function anotarRechazo(int $codigo, string $mensaje): void
    {
        try {
            Cache::put(self::CLAVE_ERROR, [
                'at' => now()->toIso8601String(),
                'codigo' => $codigo ?: null,
                'explicacion' => self::explicarError($codigo),
                'detalle' => mb_substr($mensaje, 0, 200),
            ], now()->addDays(2));
        } catch (\Throwable) {
            // El aviso es un extra: que falle no puede tumbar el envío.
        }
    }

    private function url(): string
    {
        return 'https://graph.facebook.com/' . config('emr.whatsapp_graph_version')
            . '/' . config('emr.whatsapp_phone_number_id') . '/messages';
    }

    private static function cortar(string $t, int $tope): string
    {
        $t = trim($t);

        return mb_strlen($t) <= $tope ? $t : rtrim(mb_substr($t, 0, $tope - 1)) . '…';
    }
}
