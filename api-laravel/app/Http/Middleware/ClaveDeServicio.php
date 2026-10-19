<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Autenticación backend-to-backend para el agente de WhatsApp.
 *
 * **Nunca un JWT de usuario**: el bot no tiene la contraseña de nadie, así que
 * actúa «en nombre de» un usuario ya resuelto por teléfono, pasando su
 * `usuario_id` explícito en cada llamada.
 *
 * Sin `SERVICE_API_KEY` configurada, TODO se rechaza. Es deliberado: una clave
 * vacía que aceptara una cabecera vacía dejaría estos endpoints abiertos a
 * internet, y desde acá se crean citas y se leen datos de clientes.
 */
class ClaveDeServicio
{
    public function handle(Request $request, Closure $next)
    {
        $esperada = (string) env('SERVICE_API_KEY', '');
        $recibida = (string) $request->header('X-Service-Key', '');

        // `hash_equals` y no `===`: comparación en tiempo constante, para que
        // el tiempo de respuesta no filtre cuántos caracteres coinciden.
        if ($esperada === '' || ! hash_equals($esperada, $recibida)) {
            return response()->json(['detail' => 'Clave de servicio inválida'], 401);
        }

        return $next($request);
    }
}
