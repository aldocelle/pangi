<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Búsqueda de usuarios y límite de intentos de login. */
class Usuarios
{
    /**
     * El usuario por correo, **sin distinguir mayúsculas**.
     *
     * Los correos se guardan en minúsculas pero la gente los tipea como
     * quiere: sin esto, un cliente ya importado de ACVet que escribe su correo
     * con mayúsculas no encuentra su cuenta —y en el registro crearía una
     * duplicada en vez de reclamar la suya.
     */
    public function porEmail(string $email)
    {
        return DB::table('usuarios')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->first();
    }

    /**
     * ¿Se pasó de intentos esta combinación IP+correo?
     *
     * El contador vive en la caché y no en la base: es información efímera, y
     * escribir una fila por cada intento fallido de login convertiría un ataque
     * de fuerza bruta en un problema de disco además de uno de seguridad.
     */
    public function demasiadosIntentos(string $clave, int $max, int $ventanaSeg): bool
    {
        $k = 'intentos:' . sha1($clave);
        $n = (int) Cache::get($k, 0);
        if ($n >= $max) {
            return true;
        }
        // La ventana NO se renueva con cada intento: se fija en el primero, o
        // un atacante constante mantendría el bloqueo vivo para siempre — y de
        // paso dejaría al usuario legítimo fuera indefinidamente.
        Cache::add($k, 0, $ventanaSeg);
        Cache::increment($k);

        return false;
    }

    public function limpiarIntentos(string $clave): void
    {
        Cache::forget('intentos:' . sha1($clave));
    }
}
