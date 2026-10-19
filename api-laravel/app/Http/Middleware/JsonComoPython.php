<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Serializa el JSON con las mismas tres reglas que Python.
 *
 * **No es cosmético.** La regla 3 del comparador dice que los números se
 * comparan como su texto literal, porque es la única forma de cazar deriva de
 * redondeo en un DECIMAL. `21000` y `21000.0` son dos JSON distintos, y sin
 * esto cada campo `float` del port necesita su propia exención — llegaron a ser
 * 17, y una lista de exenciones que crece es exactamente lo que termina tapando
 * un campo que sí cambió.
 *
 * Python lo hace solo: `float(21000)` se serializa `21000.0`. PHP descarta la
 * parte decimal nula salvo que se le pida `JSON_PRESERVE_ZERO_FRACTION`.
 *
 * Solo toca lo que YA es float en PHP: un `(int)` sigue saliendo entero, así
 * que los campos que Python devuelve como int no se ven afectados.
 *
 * **Y los otros dos: acentos y barras sin escapar.** `JSONResponse` de
 * Starlette serializa con `ensure_ascii=False`, así que «está» viaja en UTF-8
 * crudo; PHP lo escribe `est\u00e1` por defecto. Lo mismo con `/`, que PHP
 * escapa y Python no.
 *
 * En la mayoría de las rutas da igual —el arnés compara el JSON ya parseado, y
 * ahí las dos formas son el mismo dato—, pero en las que devuelven un archivo
 * la comparación es **byte a byte**, y ahí cuatro bytes de más en un acento son
 * una diferencia real. Fue exactamente lo que delató este caso:
 * `/fichas/documentos/{id}/archivo` daba 404 en los dos lados, con el MISMO
 * texto, y aun así 47 bytes contra 51.
 */
class JsonComoPython
{
    public function handle(Request $request, Closure $next)
    {
        $respuesta = $next($request);

        if ($respuesta instanceof JsonResponse) {
            // **Hay que re-serializar desde `original`, no desde el JSON ya
            // escrito.** `setEncodingOptions()` por sí solo decodifica el
            // string y lo vuelve a codificar, y en ese viaje de ida y vuelta el
            // `0.0` ya se convirtió en un int: la opción se aplicaría sobre un
            // dato que perdió justo lo que se quiere conservar.
            $datos = $respuesta->getOriginalContent();
            $respuesta->setEncodingOptions(
                $respuesta->getEncodingOptions()
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
            if ($datos !== null) {
                $respuesta->setData($datos);
            }
        }

        return $respuesta;
    }
}
