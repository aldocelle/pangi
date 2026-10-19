<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * ¿Cada fila que apunta a un archivo tiene su archivo?
 *
 * POR QUÉ ES UN SERVICIO Y NO UN MÉTODO DE CADA COMANDO
 *
 * Lo usan dos: `respaldo:crear`, para no dar por bueno un respaldo con adjuntos
 * ya perdidos, y `respaldo:verificar`, que es lo que se corre después de
 * restaurar. Copiado en los dos, el día que alguien arregle uno el otro sigue
 * mintiendo — y el que miente es el de la restauración, que es el que se
 * consulta en el peor momento. (§13.11.)
 *
 * QUÉ MIRA, Y POR QUÉ NO ALCANZA CON CONTAR
 *
 * Fila por fila, no conteos. Que haya 18 documentos en la base y 18 archivos en
 * el disco no dice nada si no son los mismos 18: la comprobación por totales
 * pasa en verde justo en el caso que importa, que es el archivo cambiado de
 * sitio o perdido en una copia a medias.
 *
 * Equivalente PHP de `migracion/verificar_archivos.py`, que hace lo mismo del
 * lado Python. Existe porque la base **no guarda las imágenes**: guarda
 * nombres. Se puede migrar o restaurar con cero diferencias en las 31 tablas y
 * dejar todas las fichas con los adjuntos rotos, sin un solo error.
 */
class ArchivosDeFichas
{
    /** Descripciones de lo que falta. Vacío = todo en su sitio. */
    public static function faltantes(): array
    {
        $faltan = [];
        $ups = config('emr.uploads_dir');
        $docs = config('emr.documentos_dir');

        foreach (DB::table('especialistas')->whereNotNull('foto')->get(['id', 'foto']) as $e) {
            if ((string) $e->foto !== '' && ! is_file($ups . '/' . $e->foto)) {
                $faltan[] = "especialista {$e->id} → foto «{$e->foto}»";
            }
        }

        foreach (DB::table('documentos_clinicos')->get(['id', 'nombre_archivo', 'nombre_original']) as $d) {
            // Se busca en los DOS directorios. Los documentos subidos antes de
            // la separación siguen en `uploads/`, y `ruta_documento()` del lado
            // Python hace exactamente esto: mientras no se corra la migración
            // que los mueve, buscarlos solo en el privado los daría por
            // perdidos y este chequeo gritaría en falso.
            if (! is_file($docs . '/' . $d->nombre_archivo)
                && ! is_file($ups . '/' . $d->nombre_archivo)) {
                $faltan[] = "documento {$d->id} → «{$d->nombre_original}» ({$d->nombre_archivo})";
            }
        }

        return $faltan;
    }
}
