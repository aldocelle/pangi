<?php

/**
 * Traduce el nombre de una regla de Laravel al `type` que usa Pydantic.
 *
 * No es exhaustivo a propósito: cubre las reglas que este backend usa de
 * verdad. Una regla nueva cae en `value_error`, que es lo que Pydantic emite
 * para lo que no encaja en un tipo concreto.
 */
function tipoPydantic(string $regla): string
{
    return match (strtolower($regla)) {
        'required' => 'missing',
        'email' => 'value_error',
        'uuid' => 'uuid_parsing',
        'date' => 'datetime_parsing',
        'integer' => 'int_parsing',
        'array' => 'list_type',
        'min' => 'string_too_short',
        'max' => 'string_too_long',
        // Pydantic `Field(pattern=...)` — lo usa `tipo` en /asistencia/marcar.
        'regex' => 'string_pattern_mismatch',
        default => 'value_error',
    };
}

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // `apiPrefix: ''` a propósito: las rutas van SIN el /api de Laravel.
        // El bundle ya publicado del portal pide /catalogo/servicios y el
        // webhook registrado en Getnet apunta a /pagos/notificacion — cambiar
        // la forma de la URL obligaría a recompilar los tres frontends y a
        // reconfigurar Getnet, que es justo lo que la migración evita al
        // cambiar el backend apuntando solo el DNS.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // `auth:area` exige sesión Y el permiso de esa área;
        // `auth` a secas solo exige sesión (rutas de cualquier usuario).
        $middleware->alias([
            'auth' => \App\Http\Middleware\Autenticar::class,
            // El agente de WhatsApp: clave de servicio, nunca JWT de usuario.
            'servicio' => \App\Http\Middleware\ClaveDeServicio::class,
            // El kiosco de fichaje: token de dispositivo, tampoco JWT.
            'fichaje' => \App\Http\Middleware\DispositivoFichaje::class,
        ]);

        // **Laravel recorta TODOS los strings de entrada por defecto; FastAPI
        // no.** Eso hace que cualquier campo de texto llegue distinto a los dos
        // backends, y el que decide qué se recorta tiene que ser el endpoint,
        // no el framework.
        //
        // No es cosmético: varios campos del sistema SÍ se recortan a propósito
        // y otros no. El `tono` del asistente y las `instrucciones_extra` se
        // guardan tal cual —incluidos sus espacios—, mientras que las cuatro
        // frases sí pasan por `.strip()` en su validador. Con el middleware
        // puesto, esa diferencia deliberada desaparecía.
        //
        // Lo mismo vale para `ConvertEmptyStringsToNull`, que convertiría un
        // «» en null: varios endpoints distinguen «no vino» de «vino vacío»
        // —el nombre en `/auth/register`, por ejemplo— y esa distinción es
        // justamente la que decide entre un 400 legible y un 422 genérico.
        // Un float sale como `21000.0`, igual que Python. Ver la cabecera del
        // middleware: sin esto cada campo decimal necesita su propia exención
        // en el arnés, y esa lista creciendo es lo que termina tapando un
        // campo que sí cambió.
        $middleware->append(\App\Http\Middleware\JsonComoPython::class);
        $middleware->append(\App\Http\Middleware\CabecerasDeSeguridad::class);

        $middleware->remove([
            \Illuminate\Foundation\Http\Middleware\TrimStrings::class,
            \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Todo este backend es una API pura (no sirve HTML), así que los
        // errores SIEMPRE van en JSON. El default de Laravel solo lo hace bajo
        // `api/*`, y acá las rutas no llevan ese prefijo: sin este cambio un
        // 404 devolvería una página HTML de error a un frontend que espera
        // JSON.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);

        // Los errores de validación salen con la forma de FastAPI/Pydantic, no
        // con la de Laravel.
        //
        // Laravel responde `{message, errors:{campo:[...]}}` y FastAPI
        // `{detail:[{type, loc, msg, input}]}`. Son formas incompatibles, y los
        // frontends ya publicados leen la segunda: `apiError()` en
        // portal-reservas busca `detail`. Sin esta traducción, un 422 llega
        // como error genérico y el usuario ve «algo salió mal» en vez de qué
        // campo corregir.
        $exceptions->render(function (ValidationException $e, Request $request) {
            $detail = [];
            foreach ($e->validator->failed() as $campo => $reglas) {
                foreach ($reglas as $regla => $parametros) {
                    $detail[] = [
                        'type' => tipoPydantic($regla),
                        'loc' => ['body', $campo],
                        'msg' => $e->validator->errors()->first($campo),
                        'input' => $request->input($campo),
                    ];
                }
            }

            // `failed()` solo trae las REGLAS que fallaron, así que una
            // excepción lanzada a mano —`ValidationException::withMessages()`,
            // que es como se rechaza lo que ninguna regla puede expresar, como
            // una clave desconocida— salía con `detail: []`: un 422 que no dice
            // qué campo está mal, o sea justo lo que esta traducción existe
            // para evitar. Se rellena desde `errors()`, que sí las tiene.
            if (! $detail) {
                foreach ($e->validator->errors()->messages() as $campo => $mensajes) {
                    $detail[] = [
                        'type' => 'value_error',
                        'loc' => ['body', $campo],
                        'msg' => $mensajes[0] ?? '',
                        'input' => $request->input($campo),
                    ];
                }
            }

            return response()->json(['detail' => $detail], 422);
        });
    })->create();
