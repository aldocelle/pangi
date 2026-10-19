<?php

namespace App\Services;

use App\Http\Controllers\Api\FichasController;
use Illuminate\Http\UploadedFile;

/**
 * La orden médica que llega por el chat del portal, mientras espera su cita.
 *
 * Es la misma situación que por WhatsApp (AdjuntosWhatsapp): el archivo llega
 * ANTES de que haya cita, porque la consulta no se reserva sin la orden. Por eso
 * no se reusa el flujo del formulario, que retiene el archivo en el navegador:
 * acá el archivo viaja en el mismo `POST /chatbot/mensaje` que el texto y queda
 * guardado en el servidor hasta que OrdenExamen lo engancha a una cita.
 *
 * **La ruta es pública** —se puede reservar sin cuenta—, así que esto es lo que
 * impide que sea un depósito de archivos gratis. No se agrega nada nuevo: se
 * reusa lo de WhatsApp, que ya cubre lo importante:
 *  · el tipo se decide por lo que el servidor VE en el archivo, no por el que
 *    declara el navegador, y además por sus primeros bytes;
 *  · va a `documentos_clinicos/`, que no sirve nadie (§9.16): es una orden con
 *    datos de un paciente;
 *  · un solo pendiente por conversación (el nuevo reemplaza al viejo, ver
 *    AgenteIA) y lo que nadie usa se borra solo a las 48 h;
 *  · y el límite de mensajes por IP del propio chat, más el tope de tamaño de
 *    PHP que corta antes de que el archivo llegue a la aplicación.
 */
class AdjuntosWeb
{
    /**
     * `['ruta', 'nombre', 'ext']` o `['error' => texto para la persona]`.
     */
    public static function recibir(UploadedFile $archivo): array
    {
        if (! $archivo->isValid()) {
            // Lo más común: pasó el tope de PHP (12 MB) y llegó cortado.
            return ['error' => 'No pude recibir el archivo. ¿Lo mandas de nuevo, o una foto de la orden en vez del archivo?'];
        }
        if ($archivo->getSize() > FichasController::TAMANO_MAX_BYTES) {
            return ['error' => 'El archivo pesa más de 10 MB. ¿Me mandas una foto de la orden en vez del archivo?'];
        }

        $bytes = @file_get_contents($archivo->getRealPath());
        if ($bytes === false || $bytes === '') {
            return ['error' => 'No pude leer el archivo. ¿Lo mandas de nuevo?'];
        }

        // `getMimeType()` es el que detecta el servidor (finfo), no el que
        // declaró el navegador (`getClientMimeType()`).
        return AdjuntosWhatsapp::guardarBytes($bytes, (string) $archivo->getMimeType(),
            (string) $archivo->getClientOriginalName());
    }
}
