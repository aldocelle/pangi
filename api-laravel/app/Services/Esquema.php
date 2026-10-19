<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;

/**
 * ¿Ya corrió la migración? La guarda de §13.13, memorizada por proceso: el
 * código que lee una columna nueva no puede dar 500 en una base sin el ALTER,
 * pero tampoco preguntarle a `information_schema` en cada fila.
 */
class Esquema
{
    private static array $cache = [];

    public static function columna(string $tabla, string $columna): bool
    {
        return self::$cache["{$tabla}.{$columna}"] ??= Schema::hasColumn($tabla, $columna);
    }

    public static function tabla(string $tabla): bool
    {
        return self::$cache[$tabla] ??= Schema::hasTable($tabla);
    }

    /** Para los tests, que crean y borran tablas entre casos. */
    public static function olvidar(): void
    {
        self::$cache = [];
    }
}
