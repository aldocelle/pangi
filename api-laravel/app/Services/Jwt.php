<?php

namespace App\Services;

use Firebase\JWT\JWT as FirebaseJwt;

/**
 * Firma de tokens. La otra mitad de `Autenticar`, que los verifica.
 *
 * **El token tiene que ser intercambiable con el de FastAPI.** Durante la
 * migración los dos backends conviven contra la misma base, y una sesión
 * abierta en uno debe seguir valiendo en el otro: si no, al cortar, todo el
 * staff se queda fuera sin entender por qué.
 *
 * Eso exige cuatro coincidencias exactas: la misma `SECRET_KEY`, el mismo
 * algoritmo (HS256), el mismo payload (`sub`, `rol`, `user_id`, `exp`) y la
 * misma expiración (12 h).
 */
class Jwt
{
    /** 12 horas, como `ACCESS_TOKEN_EXPIRE_MINUTES = 60 * 12`. */
    private const EXPIRA_SEGUNDOS = 60 * 60 * 12;

    public function firmar(array $datos): string
    {
        // `exp` en UTC, igual que `datetime.utcnow() + timedelta(...)`: el
        // estándar JWT lo exige en epoch, así que la zona no se negocia.
        $datos['exp'] = time() + self::EXPIRA_SEGUNDOS;
        // Se lee de la base al firmar, no lo pasa quien llama: así ningún
        // camino de login puede olvidarlo. Ver `Sesiones`.
        $datos['sv'] = Sesiones::versionDe((string) ($datos['user_id'] ?? ''));

        return FirebaseJwt::encode($datos, config('app.jwt_secret'), 'HS256');
    }
}
