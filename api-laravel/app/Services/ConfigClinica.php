<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Configuración operativa que trajo la paridad con ACVet (oct-2026): cómo salen
 * los recordatorios, qué reglas frenan una venta y cómo se imprime.
 *
 * Vive en `ajustes.data`, bajo una clave por sección, igual que el asistente:
 * JSON de fila única, sin migración por cada interruptor nuevo.
 *
 * **Nada de esto sale por `GET /catalogo/ajustes`**, que es público: ahí no
 * tiene nada que hacer el hash de la clave de descuentos ni las reglas internas
 * de caja. `CatalogoController::ajustes()` quita estas claves, y el PUT de
 * ajustes generales las rechaza (no están en sus claves permitidas).
 *
 * **Cada default reproduce lo que el sistema hacía antes de existir el
 * interruptor.** Encender una regla es decisión de la clínica, no del
 * despliegue: el día que esto llega a producción nada cambia de comportamiento.
 */
class ConfigClinica
{
    public const SECCIONES = ['recordatorios', 'ventas', 'impresion', 'correos'];

    /** Las bodegas que ofrece ACVet. Una lista cerrada: el stock no se reparte entre ellas. */
    public const BODEGAS = ['Principal', 'Hospital', 'Farmacia'];

    /**
     * El catálogo de fondos que Ajustes → Correos deja elegir — nunca un color
     * libre (ver `Plantilla::colores()`). Las dos listas están separadas porque
     * llevan texto de distinto color encima: CLAROS van con `Plantilla::INK`/
     * `INK_SOFT` (oscuro) y tienen que seguir leyéndose claras; OSCUROS van con
     * el texto BLANCO del botón y tienen que seguir siendo oscuros. Mezclarlas
     * — un claro en `fondo_boton`, o un oscuro en `fondo_aviso` — deja texto
     * invisible, así que `validar()` nunca acepta uno de una lista donde va el
     * otro.
     *
     * Son hex que YA existen en el proyecto: los cuatro oscuros, en el correo
     * mismo (`Plantilla::BRAND_STRONG`/`INK`) y en el panel (`--accent`/
     * `--accent-strong` de `portal-admin-clinica/src/assets/app.css`); los
     * claros, ídem (`Plantilla::BRAND_TINT`/`ACENTO_SUAVE` y `--accent-tint`).
     * Nunca se inventa un color nuevo para esta pantalla.
     */
    public const SWATCHES_CLAROS = [
        '#eef1fb' => 'Azul claro (el de siempre)',
        '#fbeee6' => 'Durazno claro (el de siempre, en el aviso)',
        '#e6f7f9' => 'Turquesa claro',
        '#ffffff' => 'Blanco',
    ];

    public const SWATCHES_OSCUROS = [
        '#1a2350' => 'Azul marca (el de siempre)',
        '#26326e' => 'Azul medio',
        '#087c8a' => 'Turquesa',
        '#056773' => 'Turquesa oscuro',
    ];

    public const DEFAULTS = [
        'recordatorios' => [
            // 0 = el aviso sale el mismo día que vence, que es lo que hacía
            // AvisosPreventivos antes de este ajuste.
            'dias_anticipacion' => 0,
            'firma' => '',
        ],
        'ventas' => [
            'bodegas' => ['Principal'],
            'reiniciar_stock_solo_admin' => false,
            // null = sin alerta. Es un porcentaje sobre el PRECIO DE VENTA:
            // (venta − costo) / venta.
            'margen_minimo' => null,
            'margenes_por_tipo' => [],
            'clave_descuentos' => false,
            'no_vender_sin_stock' => false,
            'exigir_caja_abierta' => false,
            'exigir_paciente' => false,
            'puntos_activo' => false,
            // Cuántos pesos pagados dan un punto, y cuánto vale un punto al canjear.
            'pesos_por_punto' => 100,
            'valor_punto' => 10,
        ],
        'impresion' => [
            'tipo_impresora' => 'escritorio',
            'retencion_pct' => null,
            'auto_imprimir' => false,
            'desglose_iva' => false,
            'margenes_mm' => ['superior' => 21.17, 'derecho' => 10.58, 'inferior' => 18.52, 'izquierdo' => 10.58],
            'mensaje_recibo' => '',
        ],
        // Los valores de fábrica son EXACTAMENTE los que Plantilla.php tenía
        // hardcodeados antes de esta pantalla: nada cambia de aspecto hasta que
        // alguien del equipo elige otra cosa en Ajustes → Correos.
        'correos' => [
            'fondo_pagina' => '#eef1fb',
            'fondo_cabecera' => '#eef1fb',
            'fondo_boton' => '#1a2350',
            'fondo_aviso' => '#fbeee6',
            // Vacío = el nombre de la clínica (`Marca`), resuelto en `leer()`.
            'pie_texto' => '',
        ],
    ];

    /** La sección con sus defaults. Para uso INTERNO: trae el hash de la clave. */
    public static function leer(string $seccion): array
    {
        $guardado = self::todo()[$seccion] ?? [];
        $r = array_merge(self::DEFAULTS[$seccion], is_array($guardado) ? $guardado : []);
        if ($seccion === 'correos' && trim((string) $r['pie_texto']) === '') {
            $r['pie_texto'] = Marca::nombre();
        }

        return $r;
    }

    /** Lo que se le muestra al panel: sin el hash, con un booleano en su lugar. */
    public static function publica(string $seccion): array
    {
        $c = self::leer($seccion);
        if ($seccion === 'ventas') {
            $c['tiene_clave_descuento'] = ! empty($c['clave_descuento_hash']);
            unset($c['clave_descuento_hash']);
        }

        return $c;
    }

    /**
     * Guarda los campos enviados (los no enviados se conservan) y devuelve la
     * versión pública. Valida cada campo: lo que no se conoce, 422.
     */
    public static function guardar(string $seccion, array $enviado): array
    {
        $actual = self::leer($seccion);
        $permitidas = array_keys(self::DEFAULTS[$seccion]);
        if ($seccion === 'ventas') {
            $permitidas[] = 'clave_descuento';
        }
        $sobran = array_diff(array_keys($enviado), $permitidas);
        if ($sobran) {
            throw ValidationException::withMessages(array_fill_keys(array_values($sobran), 'Campo no reconocido.'));
        }

        foreach ($enviado as $k => $v) {
            $actual[$k] = self::validar($seccion, $k, $v);
        }
        if ($seccion === 'ventas' && array_key_exists('clave_descuento', $actual)) {
            $clave = $actual['clave_descuento'];
            unset($actual['clave_descuento']);
            if ($clave !== null) {
                $actual['clave_descuento_hash'] = $clave === '' ? null : password_hash($clave, PASSWORD_BCRYPT);
            }
        }
        // Pedir clave sin haber definido una dejaría todo descuento bloqueado.
        if ($seccion === 'ventas' && $actual['clave_descuentos'] && empty($actual['clave_descuento_hash'])) {
            throw ValidationException::withMessages(['clave_descuento' => 'Define la clave antes de exigirla.']);
        }

        $todo = self::todo();
        $todo[$seccion] = $actual;
        if (DB::table('ajustes')->where('id', 1)->exists()) {
            DB::table('ajustes')->where('id', 1)->update(['data' => json_encode($todo, JSON_UNESCAPED_UNICODE)]);
        } else {
            DB::table('ajustes')->insert(['id' => 1, 'data' => json_encode($todo, JSON_UNESCAPED_UNICODE)]);
        }

        return self::publica($seccion);
    }

    /** Margen mínimo que aplica a un tipo de ítem: su override, o el global. */
    public static function margenMinimo(?string $tipo, ?array $ventas = null): ?float
    {
        $ventas ??= self::leer('ventas');
        foreach ($ventas['margenes_por_tipo'] as $m) {
            if (mb_strtolower($m['tipo']) === mb_strtolower((string) $tipo)) {
                return (float) $m['margen'];
            }
        }

        return $ventas['margen_minimo'] === null ? null : (float) $ventas['margen_minimo'];
    }

    private static function todo(): array
    {
        // Sin la tabla (una base a medio crear, o el esquema mínimo de un
        // test) rigen los defaults: que una regla de venta no exista nunca
        // puede tumbar una venta.
        try {
            $fila = DB::table('ajustes')->where('id', 1)->first();
        } catch (\Throwable) {
            return [];
        }

        return $fila && $fila->data ? (json_decode($fila->data, true) ?: []) : [];
    }

    private static function validar(string $seccion, string $k, $v)
    {
        $mal = fn (string $msg) => throw ValidationException::withMessages([$k => $msg]);
        $bool = fn () => is_bool($v) ? $v : $mal('Debe ser verdadero o falso.');
        $num = function (float $min, float $max, bool $nulo = false) use ($v, $mal) {
            if ($v === null && $nulo) {
                return null;
            }
            if (! is_numeric($v) || $v < $min || $v > $max) {
                $mal("Debe ser un número entre {$min} y {$max}.");
            }

            return $v + 0;
        };
        $texto = fn (int $max) => is_string($v) && mb_strlen($v) <= $max ? trim($v) : $mal("Texto de hasta {$max} caracteres.");

        return match ("{$seccion}.{$k}") {
            'recordatorios.dias_anticipacion' => (int) $num(0, 60),
            'recordatorios.firma' => $texto(300),

            'ventas.bodegas' => is_array($v) && $v && ! array_diff($v, self::BODEGAS)
                ? array_values(array_unique($v)) : $mal('Elige al menos una bodega de la lista.'),
            'ventas.margen_minimo' => $num(0, 99, true),
            'ventas.margenes_por_tipo' => self::margenes($v, $mal),
            'ventas.pesos_por_punto' => (int) $num(1, 1000000),
            'ventas.valor_punto' => (int) $num(0, 100000),
            'ventas.clave_descuento' => $v === null ? null
                : (is_string($v) && ($v === '' || mb_strlen($v) >= 4) && mb_strlen($v) <= 64 ? $v : $mal('Mínimo 4 caracteres.')),
            'ventas.reiniciar_stock_solo_admin', 'ventas.clave_descuentos', 'ventas.no_vender_sin_stock',
            'ventas.exigir_caja_abierta', 'ventas.exigir_paciente', 'ventas.puntos_activo',
            'impresion.auto_imprimir', 'impresion.desglose_iva' => $bool(),

            'impresion.tipo_impresora' => in_array($v, ['escritorio', 'termica'], true) ? $v : $mal('Escritorio o térmica.'),
            'impresion.retencion_pct' => $num(0, 50, true),
            'impresion.mensaje_recibo' => $texto(300),
            'impresion.margenes_mm' => self::margenesMm($v, $mal),

            'correos.fondo_pagina', 'correos.fondo_cabecera', 'correos.fondo_aviso' =>
                array_key_exists($v, self::SWATCHES_CLAROS)
                    ? $v : $mal('Elige uno de los colores claros de la lista.'),
            'correos.fondo_boton' =>
                array_key_exists($v, self::SWATCHES_OSCUROS)
                    ? $v : $mal('Elige uno de los colores oscuros de la lista.'),
            'correos.pie_texto' => $texto(120),
        };
    }

    private static function margenes($v, callable $mal): array
    {
        if (! is_array($v)) {
            $mal('Lista de {tipo, margen}.');
        }
        $out = [];
        foreach ($v as $m) {
            $tipo = trim((string) ($m['tipo'] ?? ''));
            $margen = $m['margen'] ?? null;
            if ($tipo === '' || mb_strlen($tipo) > 50 || ! is_numeric($margen) || $margen < 0 || $margen > 99) {
                $mal('Cada fila necesita un tipo y un margen entre 0 y 99.');
            }
            $out[mb_strtolower($tipo)] = ['tipo' => $tipo, 'margen' => $margen + 0];
        }

        return array_values($out);
    }

    private static function margenesMm($v, callable $mal): array
    {
        $out = [];
        foreach (['superior', 'derecho', 'inferior', 'izquierdo'] as $lado) {
            $x = $v[$lado] ?? null;
            if (! is_numeric($x) || $x < 0 || $x > 50) {
                $mal('Cada margen va entre 0 y 50 mm.');
            }
            $out[$lado] = round($x + 0, 2);
        }

        return $out;
    }
}
