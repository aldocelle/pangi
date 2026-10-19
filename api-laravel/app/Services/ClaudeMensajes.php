<?php

namespace App\Services;

use Anthropic\Client;

/**
 * La única línea que habla con la API de Anthropic. Existe aparte para que
 * los tests de AgenteIA la reemplacen por respuestas guionadas: ningún test
 * sale a internet ni gasta un peso (mismo criterio que el `pytest.ini`, §10).
 */
class ClaudeMensajes
{
    /**
     * Segundos por llamada, y sin reintentos del SDK. El chat espera con la
     * persona mirando y el hosting compartido corta el PHP antes que los 600 s
     * por defecto del SDK: mejor derivar a WhatsApp que colgar la burbuja.
     */
    private const TIMEOUT = 25.0;

    public function crear(array $system, array $tools, array $messages): object
    {
        $client = new Client(apiKey: config('emr.anthropic_api_key'));
        $modelo = (string) config('emr.asistente_ia_modelo');

        $args = [
            'messages' => $messages,
            'model' => $modelo,
            'system' => $system,
            'tools' => $tools,
            'workspaceID' => config('emr.anthropic_workspace_id'),
            'requestOptions' => ['timeout' => self::TIMEOUT, 'maxRetries' => 0],
        ];

        // Haiku 4.5 es el modelo por defecto (decisión de producto, 14-sep: «un
        // modelo económico de pocos tokens»). Sin pensamiento, así que 1024
        // alcanza para una respuesta de chat o una llamada a herramienta; y
        // Haiku 4.5 RECHAZA `effort` y no tiene `fallbacks`, por eso no van.
        if (str_starts_with($modelo, 'claude-haiku')) {
            return $client->messages->create(...$args, maxTokens: 1024);
        }

        // Cualquier otro modelo actual (Opus 5, Sonnet 5): el pensamiento va
        // encendido por defecto y cuenta dentro de maxTokens, así que el techo
        // es alto; `low` recorta llamadas y preámbulo, y `fallbacks` reintenta
        // en el servidor si el clasificador de seguridad rechaza.
        return $client->beta->messages->create(
            ...$args,
            maxTokens: 16000,
            outputConfig: ['effort' => 'low'],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );
    }
}
