<?php

namespace App\Services;

use App\Http\Controllers\Api\FichasController;

/**
 * La orden de examen que llega por WhatsApp, mientras espera su cita.
 *
 * En el portal el archivo se retiene en el navegador hasta que la cita existe.
 * Acá no hay navegador: el archivo llega ANTES de que haya cita (la consulta no
 * se reserva sin la orden), así que se guarda en el servidor y se engancha
 * después con OrdenExamen, la misma puerta del portal.
 *
 * Vive DENTRO de `documentos_clinicos/`, que no lo sirve nadie (§9.16): es una
 * orden médica con datos del paciente, no puede quedar en un directorio público
 * ni en `storage/`, que según cómo esté montado el sitio podría estarlo.
 *
 * Lo que no se usa se borra solo a las HORAS_RETENCION, la próxima vez que llega
 * otro archivo: no hay cron garantizado en el hosting.
 */
class AdjuntosWhatsapp
{
    private const HORAS_RETENCION = 48;

    public function __construct(private WhatsappEnvio $envio)
    {
    }

    public static function dir(): string
    {
        return rtrim(config('emr.documentos_dir'), '/') . '/whatsapp-pendientes';
    }

    /**
     * Baja y guarda. `['ruta', 'nombre', 'ext']` o `['error' => texto para la persona]`.
     */
    public function recibir(string $mediaId, string $nombre): array
    {
        $d = $this->envio->descargar($mediaId, FichasController::TAMANO_MAX_BYTES);
        if (($d['error'] ?? null) === 'grande') {
            return ['error' => 'El archivo pesa más de 10 MB. ¿Me mandas una foto de la orden en vez del archivo?'];
        }
        if (isset($d['error'])) {
            return ['error' => 'No pude descargar el archivo. ¿Me lo reenvías?'];
        }

        return self::guardarBytes($d['bytes'], $d['mime'], $nombre);
    }

    /**
     * Valida y guarda un archivo ya en memoria. La puerta común de WhatsApp y
     * del chat del portal (AdjuntosWeb): una sola regla de qué se acepta, para
     * que las dos no se separen. `['ruta', 'nombre', 'ext']` o `['error' => …]`.
     */
    public static function guardarBytes(string $bytes, string $mime, string $nombre): array
    {
        $ext = OrdenExamen::EXTENSION_POR_MIME[$mime] ?? null;
        // El MIME lo declara quien envía. Los primeros bytes no mienten: un
        // ejecutable renombrado a .pdf no entra a la ficha de un paciente.
        if (! $ext || ! self::contenidoCalza($ext, $bytes)) {
            return ['error' => 'La orden la puedo recibir como foto o como PDF.'];
        }

        $dir = self::dir();
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        self::purgarViejos($dir);

        $ruta = $dir . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        file_put_contents($ruta, $bytes);

        $nombre = trim(basename(str_replace('\\', '/', $nombre)));

        return ['ruta' => $ruta, 'nombre' => $nombre !== '' ? mb_substr($nombre, 0, 200) : "orden.{$ext}", 'ext' => $ext];
    }

    /** Borra un pendiente, solo si de verdad está en el directorio de pendientes. */
    public static function descartar(?string $ruta): void
    {
        if ($ruta && dirname($ruta) === self::dir() && is_file($ruta)) {
            @unlink($ruta);
        }
    }

    private static function contenidoCalza(string $ext, string $bytes): bool
    {
        return match ($ext) {
            'pdf' => str_starts_with($bytes, '%PDF'),
            'jpg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'png' => str_starts_with($bytes, "\x89PNG\r\n\x1A\n"),
            'webp' => str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP',
            default => false,
        };
    }

    private static function purgarViejos(string $dir): void
    {
        $limite = time() - self::HORAS_RETENCION * 3600;
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < $limite) {
                @unlink($f);
            }
        }
    }
}
