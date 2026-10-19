<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Revocar las sesiones de UNA cuenta sin tocar la de las demás.
 *
 * El JWT es autocontenido: sin esto, un token robado seguía valiendo hasta 12 h
 * después de restablecer la clave (VID-04 del informe del 05-oct-2026). Cada
 * token lleva `sv`, la versión de sesión de su usuario al firmarse, y
 * `Autenticar` lo rechaza si ya no coincide. Subir la versión mata todos los
 * tokens anteriores de esa persona; rotar `SECRET_KEY` los mataría de todos.
 *
 * Sin la columna (base sin `mariadb_sesion_version.sql`) todo vale 0: los
 * tokens siguen funcionando como antes y revocar no hace nada, en vez de 500.
 */
class Sesiones
{
    public static function version(?object $usuario): int
    {
        return (int) ($usuario->sesion_version ?? 0);
    }

    public static function versionDe(string $usuarioId): int
    {
        if (! Esquema::columna('usuarios', 'sesion_version')) {
            return 0;
        }

        return (int) DB::table('usuarios')->where('id', $usuarioId)->value('sesion_version');
    }

    public static function revocar(string $usuarioId): void
    {
        if (! Esquema::columna('usuarios', 'sesion_version')) {
            return;
        }
        DB::table('usuarios')->where('id', $usuarioId)
            ->update(['sesion_version' => DB::raw('COALESCE(sesion_version, 0) + 1')]);
    }
}
