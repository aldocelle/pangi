<?php

namespace App\Services;

/**
 * El nombre de la clínica tal como lo lee el tutor: asunto y firma de los
 * correos, mensajes de WhatsApp, recibos, respaldos y el prompt del asistente.
 *
 * Sale de `CLINICA_NOMBRE` (config/emr.php) y no de un texto escrito en cada
 * sitio: cada instalación tiene el suyo sin tocar código.
 */
class Marca
{
    public static function nombre(): string
    {
        try {
            $nombre = config('emr.clinica_nombre');
        } catch (\Throwable) {
            // Sin la aplicación levantada (un test unitario puro) no hay config.
            $nombre = null;
        }

        return (string) ($nombre ?: 'PANGI');
    }
}
