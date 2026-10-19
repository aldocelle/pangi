<?php

/**
 * CORS — sin esto, el día del corte los tres frontends dejan de funcionar.
 *
 * POR QUÉ ESTE ARCHIVO EXISTÍA VACÍO Y NADIE LO NOTÓ
 *
 * Laravel 11 trae el middleware `HandleCors` puesto de fábrica, pero lee
 * `config('cors')`: **sin este archivo, `paths` queda vacío, ninguna ruta casa
 * y no sale ni una cabecera**. La API responde 200 a todo, así que desde
 * `curl`, desde Postman y desde el arnés se ve perfecta — CORS lo aplica el
 * NAVEGADOR, y ninguna de esas tres herramientas es un navegador.
 *
 * Por eso los 533 casos del modo B y los 65 del modo A estaban verdes con esto
 * roto: `requests` no mira `Access-Control-Allow-Origin`. Es el ejemplo más
 * claro de lo que un arnés diferencial no puede ver, y se descubrió el
 * 04-sep-2026 al preguntar algo tan simple como «¿dónde puedo probar?».
 *
 * Qué habría pasado sin el arreglo: al repuntar el CNAME, el portal en
 * `reservas.ejemplo.cl` llamando a `api.ejemplo.cl`
 * es una petición de origen cruzado. El navegador la bloquea **entera**: el
 * sitio no muestra ni un servicio, el panel no deja entrar, no se puede
 * reservar. Y el backend no registra ningún error, porque para él la petición
 * salió bien.
 *
 * LA LISTA ES LA MISMA QUE LA DE PYTHON, Y ESO INCLUYE LO QUE DEJA FUERA
 *
 * `main.py` arma los orígenes así: los de producción vienen de
 * `ORIGENES_PRODUCCION` (separados por coma) y **los de localhost se agregan
 * solo fuera de production**, para no dejar habilitado en la API pública un
 * origen que cualquiera puede levantar en su máquina. Se replica igual, con la
 * misma consecuencia práctica: para probar el portal contra un servidor con
 * `APP_ENV=production` hay que agregar ese origen a `ORIGENES_PRODUCCION`, no
 * confiar en que localhost esté permitido.
 */

$dev = [
    'http://localhost:3100',  // website
    'http://localhost:3101',  // portal de reservas
    'http://localhost:3102',  // panel de la clínica
];

$produccion = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('ORIGENES_PRODUCCION', ''))
)));

return [
    // Todas las rutas, incluidas `/uploads` y `/pagos`: en Python el
    // middleware envuelve la app entera, no un prefijo.
    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_merge(
        $produccion,
        env('APP_ENV') !== 'production' ? $dev : []
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // `allow_credentials=True` en Python. Obliga a devolver el origen concreto
    // y nunca `*`, que es justo lo que hace el middleware al tener una lista.
    'supports_credentials' => true,
];
