<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

/**
 * Fotos de perfil que sube el propio tutor: la de cada mascota y la suya.
 *
 * Van a `uploads/`, que se sirve sin token — igual que la foto de un
 * especialista — porque se muestran con un `<img>` en el portal y en el panel,
 * y un `<img>` no manda cabecera de autorización. Lo que lo hace aceptable para
 * una foto de una persona es el NOMBRE: 128 bits al azar y nada más. No lleva
 * el id de la mascota ni del usuario (al revés que `esp-<id>-…`), así que no se
 * puede adivinar ni recorrer; solo la conoce quien ya ve el perfil.
 *
 * Dos diferencias deliberadas con `CatalogoController::subirFoto`:
 *
 *   · **Se mira el contenido, no solo la extensión.** Esa ruta la usa el staff;
 *     esta, cualquier tutor con cuenta. Un HTML renombrado a `.png` en un
 *     directorio público es un sitio de phishing con nuestro dominio.
 *   · **La foto anterior SÍ se borra.** Allá se conserva para que dos ediciones
 *     cruzadas no dejen al sitio sin imagen; acá la persona que quita su foto
 *     espera que deje de existir, no que quede huérfana en un directorio
 *     público.
 */
class FotosPerfil
{
    public const MAX_BYTES = 4 * 1024 * 1024;

    /** Extensión aceptada → tipo que tiene que detectar `getimagesize`. */
    private const TIPOS = [
        'jpg' => IMAGETYPE_JPEG,
        'jpeg' => IMAGETYPE_JPEG,
        'png' => IMAGETYPE_PNG,
        'webp' => IMAGETYPE_WEBP,
    ];

    /**
     * Valida y guarda. Devuelve el nombre del archivo o, si no se pudo, el
     * mensaje de error listo para mostrarle a la persona.
     *
     * @return array{0: ?string, 1: ?string} [nombre, error]
     */
    public static function guardar(?UploadedFile $archivo, string $prefijo): array
    {
        if (! $archivo || ! $archivo->isValid()) {
            return [null, 'Falta la imagen.'];
        }
        $ext = strtolower($archivo->getClientOriginalExtension());
        if (! isset(self::TIPOS[$ext])) {
            return [null, 'Formato no soportado (usa JPG, PNG o WebP).'];
        }
        if ($archivo->getSize() > self::MAX_BYTES) {
            return [null, 'La imagen no puede superar 4 MB.'];
        }
        $info = @getimagesize($archivo->getRealPath());
        if (! $info || $info[2] !== self::TIPOS[$ext]) {
            return [null, 'El archivo no parece ser una imagen válida.'];
        }

        $nombre = $prefijo . '-' . bin2hex(random_bytes(16)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $destino = rtrim(config('emr.uploads_dir'), '/');
        if (! is_dir($destino)) {
            // Obligatorio: un volumen recién creado se monta vacío (§9.16).
            mkdir($destino, 0775, true);
        }
        $archivo->move($destino, $nombre);

        return [$nombre, null];
    }

    /**
     * Borra un archivo que haya generado ESTA clase. Acepta el nombre a secas
     * o la ruta `/uploads/<nombre>` (así guarda `mascotas.foto_url`). Cualquier
     * otra cosa —una URL de ACVet, la foto de un especialista— no se toca.
     */
    public static function borrar(?string $valor, string $prefijo): void
    {
        if (! $valor) {
            return;
        }
        $nombre = basename($valor);
        if (! preg_match('/^' . preg_quote($prefijo, '/') . '-[0-9a-f]{32}\.(jpg|png|webp)$/', $nombre)) {
            return;
        }
        $ruta = rtrim(config('emr.uploads_dir'), '/') . '/' . $nombre;
        if (is_file($ruta)) {
            @unlink($ruta);
        }
    }
}
