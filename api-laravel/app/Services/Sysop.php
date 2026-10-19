<?php

namespace App\Services;

/**
 * Quién es sysop: un admin cuyo correo está en `SYSOP_EMAILS` (`.env`).
 *
 * **La lista vive en el `.env` y no en una columna de `usuarios` a propósito.**
 * Con una columna, cualquier admin podría darse el permiso a sí mismo desde
 * Equipo y permisos, y el nivel dejaría de separar nada. Con el `.env`, cambiar
 * quién es sysop exige tocar el servidor.
 *
 * **Falla cerrado**: la variable vacía o ausente significa que NADIE es sysop,
 * no que todos lo sean.
 *
 * Además del correo exige `rol = admin`: que alguien figure en la lista no
 * alcanza si su cuenta es de veterinario o de dueño.
 */
class Sysop
{
    public static function es(?object $usuario): bool
    {
        if (! $usuario || ($usuario->rol ?? null) !== 'admin') {
            return false;
        }
        $correo = mb_strtolower(trim((string) ($usuario->email ?? '')));

        return $correo !== '' && in_array($correo, (array) config('emr.sysop_emails', []), true);
    }
}
