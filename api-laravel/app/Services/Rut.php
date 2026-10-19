<?php

namespace App\Services;

/**
 * RUT chileno: validar, normalizar, formatear y enmascarar.
 *
 * Los RUT de la base llegan de todas formas: con puntos o sin ellos, con o sin
 * guion, con «k» minúscula, y algunos del import de ACVet rotos por delante
 * («.6.645.549-1»). Todo pasa por acá para que «el mismo RUT» sea la misma
 * cadena en todas partes.
 */
class Rut
{
    /** `12345678-9` (sin puntos, DV en mayúscula), o null si no es un RUT válido. */
    public static function normalizar(?string $rut): ?string
    {
        $limpio = strtoupper(preg_replace('/[^0-9kK]/', '', (string) $rut));
        if (! preg_match('/^(\d{6,8})([0-9K])$/', $limpio, $m)) {
            return null;
        }
        $cuerpo = ltrim($m[1], '0');

        return ($cuerpo !== '' && self::dv($cuerpo) === $m[2]) ? "{$cuerpo}-{$m[2]}" : null;
    }

    /** `12.345.678-9`, el formato con que la base ya guarda los RUT de ACVet. */
    public static function formatear(string $normalizado): string
    {
        [$cuerpo, $dv] = explode('-', $normalizado);

        return number_format((int) $cuerpo, 0, ',', '.') . "-{$dv}";
    }

    /**
     * `••.•••.678-9`: lo justo para que la persona reconozca el suyo, sin que
     * un enlace de pago filtrado (la ruta es pública) entregue un RUT entero.
     */
    public static function enmascarar(string $normalizado): string
    {
        [$cuerpo, $dv] = explode('-', $normalizado);

        return '••.•••.' . substr($cuerpo, -3) . "-{$dv}";
    }

    /** Dígito verificador, módulo 11. */
    private static function dv(string $cuerpo): string
    {
        $suma = 0;
        $factor = 2;
        for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
            $suma += (int) $cuerpo[$i] * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }
        $r = 11 - ($suma % 11);

        return match ($r) {
            11 => '0',
            10 => 'K',
            default => (string) $r,
        };
    }
}
