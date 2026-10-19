<?php

namespace App\Services;

use App\Http\Controllers\Api\FichasController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guardar la orden de examen de una cita. Una sola puerta para el portal
 * (POST /citas/{id}/orden-examen) y para WhatsApp, que la recibe como foto o PDF.
 *
 * Las reglas son las del endpoint y no se aflojan por canal:
 *  · **una sola orden por cita**: la nueva reemplaza a la anterior y borra su
 *    archivo;
 *  · entra a `documentos_clinicos/` como documento de la ficha, **nunca a
 *    `uploads/`**, que se sirve sin autenticación (§9.16);
 *  · `subido_por_id = NULL`: no la subió nadie del equipo.
 *
 * Quien llama valida extensión y tamaño ANTES, porque cada canal lo sabe en un
 * momento distinto (el portal con el archivo ya subido; WhatsApp antes de
 * descargarlo, por el `file_size` de Meta).
 */
class OrdenExamen
{
    /** Extensión permitida por MIME, para lo que no trae nombre de archivo (las fotos de WhatsApp). */
    public const EXTENSION_POR_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private FichasController $fichas)
    {
    }

    /**
     * @param  callable(string $dir, string $nombre): void  $escribir  deja el archivo en $dir/$nombre
     * @return array{documento_id: string, nombre_original: string, tamano_bytes: int}
     */
    public function guardar(object $cita, object $servicio, string $ext, string $nombreOriginal,
                            callable $escribir): array
    {
        $ficha = $this->fichas->fichaDe($cita->mascota_id);
        $respuestas = $cita->respuestas ? (json_decode($cita->respuestas, true) ?: []) : [];

        return DB::transaction(function () use ($cita, $servicio, $ext, $nombreOriginal, $escribir, $ficha, $respuestas) {
            // Una sola orden por cita: se borra la anterior, archivo incluido.
            // Además evita que el veterinario tenga que adivinar cuál de tres
            // PDFs es el bueno.
            $previoId = $respuestas['orden_examen_documento_id'] ?? null;
            if ($previoId) {
                $previo = DB::table('documentos_clinicos')->where('id', $previoId)->first();
                if ($previo) {
                    $ruta = $this->fichas->rutaDocumento($previo->nombre_archivo);
                    // Si el archivo ya no está se sigue igual y se borra la
                    // fila: no tiene sentido rechazar una orden nueva por un
                    // archivo viejo que falta.
                    if ($ruta && is_file($ruta)) {
                        @unlink($ruta);
                    }
                    DB::table('documentos_clinicos')->where('id', $previoId)->delete();
                }
            }

            $nombreArchivo = 'doc-' . $ficha->id . '-'
                . substr(bin2hex(random_bytes(8)), 0, 10) . '.' . $ext;
            $destino = rtrim(config('emr.documentos_dir'), '/');
            if (! is_dir($destino)) {
                // Obligatorio: un volumen recién creado se monta vacío (§9.16).
                mkdir($destino, 0775, true);
            }
            $escribir($destino, $nombreArchivo);

            $docId = (string) Str::uuid();
            DB::table('documentos_clinicos')->insert([
                'id' => $docId,
                'ficha_id' => $ficha->id,
                'subido_por_id' => null,
                'tipo' => 'examen',
                'descripcion' => 'Orden de examen adjuntada por el tutor al reservar '
                    . $servicio->nombre,
                'nombre_original' => $nombreOriginal !== '' ? $nombreOriginal : $nombreArchivo,
                'nombre_archivo' => $nombreArchivo,
                'mime_type' => FichasController::EXTENSIONES[$ext],
                'tamano_bytes' => filesize($destino . '/' . $nombreArchivo),
                'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            ]);

            // `respuestas` es JSON libre y ya guarda todo el formulario (§9.3):
            // anotarlo acá evita una columna nueva para un dato que el panel
            // solo necesita mostrar.
            DB::table('citas')->where('id', $cita->id)->update([
                'respuestas' => json_encode(
                    array_merge($respuestas, ['orden_examen_documento_id' => $docId]),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $d = DB::table('documentos_clinicos')->where('id', $docId)->first();

            return [
                'documento_id' => $d->id,
                'nombre_original' => $d->nombre_original,
                'tamano_bytes' => (int) $d->tamano_bytes,
            ];
        });
    }
}
