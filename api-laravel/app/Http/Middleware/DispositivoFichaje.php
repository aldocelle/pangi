<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Autenticación del kiosco de fichaje: el tablet compartido de recepción.
 *
 * **Nunca un JWT de usuario**: en el tablet no hay sesión de nadie. La persona
 * se elige de una lista (`GET /asistencia/roster`) y ficha con `POST
 * /asistencia/marcar-kiosco`; lo único que identifica al aparato es este token
 * fijo, en la cabecera `X-Fichaje-Token`.
 *
 * Sin `FICHAJE_DEVICE_TOKEN` configurada, TODO se rechaza — el kiosco queda
 * apagado. Es deliberado, mismo criterio que `ClaveDeServicio`: un token vacío
 * que aceptara una cabecera vacía dejaría estas rutas abiertas, y desde acá se
 * escribe en las horas que alguien va a cobrar.
 *
 * La vista `/asistencia` del panel (con login de staff) sigue siendo la vía
 * normal; esto es solo para el aparato de la entrada.
 */
class DispositivoFichaje
{
    public function handle(Request $request, Closure $next)
    {
        $esperada = (string) env('FICHAJE_DEVICE_TOKEN', '');
        $recibida = (string) $request->header('X-Fichaje-Token', '');

        // `hash_equals` y no `===`: comparación en tiempo constante.
        if ($esperada === '' || ! hash_equals($esperada, $recibida)) {
            return response()->json(['detail' => 'Dispositivo de fichaje no autorizado.'], 401);
        }

        return $next($request);
    }
}
