<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Las fotos de los especialistas, servidas por la aplicación.
 *
 * POR QUÉ ESTO ES UNA RUTA Y NO UN DIRECTORIO DEL DOCUMENT ROOT
 *
 * Python monta `/uploads` como estáticos DESDE LA APP
 * (`app.mount("/uploads", StaticFiles(directory=UPLOADS))`), con el directorio
 * **fuera** del document root. El port no tenía nada equivalente: la respuesta
 * de subir una foto devolvía `/uploads/<archivo>` —idéntica a la de Python, así
 * que el arnés la daba por buena— y esa URL contestaba **404**. Comprobado el
 * 04-sep-2026 con las siete fotos reales: Python 200, Laravel 404 en las siete.
 *
 * Consecuencia si no se arregla: el día del corte la base queda perfecta y el
 * sitio público muestra siete fotos rotas. No lo ve ninguna comparación de JSON
 * porque el JSON coincide; solo se ve pidiendo la imagen.
 *
 * La alternativa —dejar los archivos dentro de `public/`— parece más simple y es
 * peor por dos razones concretas:
 *
 *   · El paso «vaciar el document root antes de la primera subida» (§Fase 5)
 *     los borraría. Ese paso existe porque en el laboratorio quedaron cinco
 *     archivos de la vida anterior del subdominio.
 *   · `DATOS_DIR` está pensado para apuntar a un volumen montado (§9.16), y un
 *     volumen no se monta dentro del document root.
 *
 * Y lo que NO se sirve: `documentos_clinicos/`. Es el directorio hermano y no
 * tiene ruta a propósito — ahí viven radiografías y exámenes, que se piden por
 * `/fichas/documentos/{id}/archivo` **con token**. Juntar los dos directorios o
 * darle ruta a este vuelve descargable una radiografía sin autenticación.
 */
class ArchivosController extends Controller
{
    public function upload(string $archivo): BinaryFileResponse
    {
        // La ruta se arma con el nombre a secas. `basename` es cinturón sobre
        // tirantes: la restricción de la ruta ya prohíbe la barra, así que un
        // `../../.env` no llega hasta acá.
        $ruta = rtrim(config('emr.uploads_dir'), '/') . '/' . basename($archivo);

        abort_unless(is_file($ruta), 404);

        return response()->file($ruta);
    }
}
