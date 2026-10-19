<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad de la API, en toda respuesta.
 *
 * La API de Python las mandaba (`main.py`) y el port las había perdido sin que
 * nada avisara: se midió el 01-oct-2026 con `curl -I` contra producción y no
 * salía ninguna. Es el mismo defecto de siempre —lo que el original hacía en
 * silencio y nadie escribió como requisito— y por eso lo fija un test.
 *
 * - `nosniff`, `X-Frame-Options: DENY` y `Referrer-Policy` cierran lo clásico.
 * - HSTS sin `includeSubDomains` a propósito: el dominio raíz y otros
 *   subdominios (servicios de terceros, correo) no son nuestros para forzarles HTTPS.
 * - **`Cache-Control: no-store` cuando la petición viene autenticada.** Esas
 *   respuestas llevan fichas, RUT, teléfonos y ventas de los tutores
 *   (ley 21.719): que un proxy, el CDN del hosting o el caché del navegador
 *   las guarde es una copia de datos personales que nadie controla. Lo público
 *   (catálogo, horarios) no lleva `Authorization` y conserva su caché.
 */
class CabecerasDeSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        $respuesta->headers->set('X-Content-Type-Options', 'nosniff');
        $respuesta->headers->set('X-Frame-Options', 'DENY');
        $respuesta->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $respuesta->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->isSecure()) {
            $respuesta->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if ($request->headers->has('Authorization')) {
            $respuesta->headers->set('Cache-Control', 'no-store, private');
        }

        return $respuesta;
    }
}
