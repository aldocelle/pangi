<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;

/**
 * Días de CALENDARIO, sin que el cambio de hora se meta en la cuenta.
 *
 * EL BUG QUE ESTO EXISTE PARA CERRAR
 *
 * `$hoy->diffInDays($fecha, false)` parece la forma obvia de contar días, y lo
 * es —mientras entre las dos fechas no haya un cambio de horario—. Cuando lo
 * hay, ese tramo tiene 23 o 25 horas y Carbon devuelve un decimal:
 *
 *     6 sep 2026 → 13 sep 2026, en America/Santiago:  6,9583 días (167 horas)
 *
 * El `(int)` trunca hacia cero y quedan **6**. Python, que resta `date` contra
 * `date` sin husos de por medio, da **7**.
 *
 * Medido el 06-sep-2026, que fue justo el día del cambio en Chile: el arnés
 * marcó `Py=7 Lv=6` en `dias_restantes` de los recordatorios, en el panel y en
 * el portal del dueño. El mismo patrón estaba en otros cuatro sitios —el
 * vencimiento de un lote de inventario, la clasificación próximo/al-día de los
 * protocolos, el tope de días de los slots y el rango de la agenda— y ninguno
 * lo habría delatado, porque **diez meses de doce este código parece correcto**.
 * Se activa dos veces al año y se apaga solo.
 *
 * Es el §9.6 del CLAUDE.md con otra cara: allá el error era comparar hora de la
 * clínica contra UTC; acá es contar días dentro de una zona con horario de
 * verano. La lección es la misma — una fecha de calendario no lleva hora, y
 * hacerla pasar por una que sí la lleva introduce un error que no se ve.
 *
 * Por eso la cuenta se hace en UTC, que no tiene transiciones: no es que los
 * datos «sean UTC», es que un día de calendario dura 24 horas ahí y solo ahí.
 */
class Fechas
{
    /**
     * Días enteros de `$desde` a `$hasta`, negativo si `$hasta` ya pasó.
     *
     * Acepta cualquier cosa que Carbon sepa leer; se queda con los primeros
     * diez caracteres, que es la parte `Y-m-d`, y descarta la hora si venía.
     */
    public static function diasEntre($desde, $hasta): int
    {
        return (int) self::dia($desde)->diffInDays(self::dia($hasta), false);
    }

    /**
     * `$v` más `$n` días de CALENDARIO.
     *
     * `->addDays()` de Carbon tampoco es inmune, y esto no se dedujo: se midió.
     * En America/Santiago, `parse('2026-09-06')->addDays(7)` devuelve
     * **2026-09-13 a las 01:00**, no a las 00:00.
     *
     * LA CAUSA, que es más interesante que el síntoma: esa medianoche **no
     * existe**. El 6 de septiembre el reloj salta de 00:00 a 01:00, así que
     * `parse('2026-09-06')` ya devuelve las 01:00 y todo lo que se calcule
     * desde ahí arrastra la hora. Es la única fecha del año con esa propiedad.
     *
     * CUÁNTO PESA, medido con un barrido del año entero: `addDay()` local y
     * UTC difieren en **2 días de 365** —el 5 y el 6 de septiembre—. Y
     * `diffInDays` solo se equivoca cuando el intervalo CRUZA ese salto.
     *
     * Una corrección al comentario que estuvo acá antes: decía que en abril el
     * error iba al otro lado. **Es falso** — se dedujo en vez de medirlo. En la
     * transición de abril el reloj atrasa, la medianoche del 5 sí existe, y
     * local y UTC coinciden. Queda escrito porque una explicación equivocada en
     * un comentario es peor que ninguna: se cita como si fuera un hecho.
     *
     * Sumar en UTC no tiene el problema porque no hay transiciones que
     * absorber. Es el mismo criterio que `diasEntre()`.
     */
    public static function sumarDias($v, int $n): CarbonImmutable
    {
        return self::dia($v)->addDays($n);
    }

    /**
     * La fecha, como día de calendario en UTC y a medianoche.
     *
     * Útil también para comparar (`lt`, `gt`) sin arrastrar la zona.
     */
    public static function dia($v): CarbonImmutable
    {
        if ($v instanceof CarbonInterface) {
            $v = $v->format('Y-m-d');
        }
        return CarbonImmutable::parse(substr((string) $v, 0, 10), 'UTC');
    }
}
