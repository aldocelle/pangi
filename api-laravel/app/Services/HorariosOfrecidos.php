<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Los horarios que un especialista escribe por WhatsApp («jue 2 10:00, vie 3
 * 15:30»), convertidos en fechas concretas. Sin IA, a propósito (30-sep-2026).
 *
 * En la prueba en vivo el Dr. Tigre escribió «lunes 02 de octu10:00», cuando el
 * 2 de octubre es viernes, y el asistente lo reenvió tal cual: con eso el tutor
 * podía elegir un día que no existe. Un modelo que interpreta fechas se
 * equivoca en silencio; este código, cuando no cuadra, **pregunta**. Lo que no
 * entiende no lo adivina: devuelve cero opciones y el mensaje se transmite como
 * texto, igual que antes.
 *
 * Lo que entiende:
 * - día de la semana, solo o con fecha: «jueves», «jue 2», «lunes 02 de octubre»;
 * - fecha sola: «2 de octubre», «2/10», «02/10/2026»;
 * - «hoy», «mañana», «pasado mañana» («en la mañana» no es un día);
 * - horas «10:00», «10.30», «10h», «10 hrs», «a las 3», «3 pm».
 *   Una hora sin día no se usa: no hay de qué día sería.
 *
 * Una hora de 1 a 7 sin «am» se lee de la tarde («a las 3» son las 15:00):
 * nadie atiende a las 3 de la madrugada, y así es como se dice en Chile.
 */
class HorariosOfrecidos
{
    /** Tope de opciones: una lista de WhatsApp admite 10 filas, y una es «Ninguno me sirve». */
    public const MAX_OPCIONES = 9;

    /** Igual que la reserva por la web (MAX_DIAS_ANTICIPACION). */
    private const MAX_DIAS = 92;

    /**
     * Lo que se le PROPONE al especialista para que marque (30-sep-2026, decisión
     * de producto: «que se le presenten varias opciones y pueda elegir una o
     * varias»). Días hábiles de lunes a sábado desde mañana, tres horas por día.
     */
    public const DIAS_PROPUESTOS = 4;

    public const HORAS_PROPUESTAS = ['10:00', '12:30', '16:00'];

    /**
     * Los horarios a proponer, en orden, desde el día siguiente a `$desde`,
     * salteando domingos y lo que ya está en `$ocupadas` («Y-m-d H:i»).
     *
     * @return list<CarbonImmutable>
     */
    public static function propuestos(CarbonImmutable $desde, array $ocupadas = []): array
    {
        $d = $desde->setTimezone('America/Santiago')->startOfDay()->addDay();
        $out = [];
        for ($dias = 0; $dias < self::DIAS_PROPUESTOS; $d = $d->addDay()) {
            if ($d->dayOfWeekIso === 7) {
                continue;
            }
            $dias++;
            foreach (self::HORAS_PROPUESTAS as $h) {
                [$hh, $mm] = array_map('intval', explode(':', $h));
                $c = $d->setTime($hh, $mm);
                if (! in_array($c->format('Y-m-d H:i'), $ocupadas, true)) {
                    $out[] = $c;
                }
            }
        }

        return $out;
    }

    /**
     * La lista para el especialista, un día por línea:
     * «Jue 1 oct: 1) 10:00 · 2) 12:30 · 3) 16:00».
     *
     * @param list<CarbonImmutable> $propuestos
     */
    public static function listaNumerada(array $propuestos): string
    {
        $porDia = [];
        foreach ($propuestos as $i => $c) {
            $dia = ucfirst(mb_substr(self::NOMBRE_DIA[$c->dayOfWeekIso], 0, 3)) . ' ' . $c->day . ' '
                . mb_substr(self::NOMBRE_MES[$c->month], 0, 3);
            $porDia[$dia][] = ($i + 1) . ') ' . $c->format('H:i');
        }

        return implode("\n", array_map(fn ($dia, $horas) => "{$dia}: " . implode(' · ', $horas),
            array_keys($porDia), $porDia));
    }

    /**
     * «1, 5, 6», «2 y 4», «4 a 6», «todos»: los números elegidos (desde 1), o
     * null si el texto no es SOLO una elección de números — entonces se lee
     * como horarios escritos o como texto libre.
     *
     * @return list<int>|null
     */
    public static function numeros(string $texto, int $total): ?array
    {
        $t = trim(self::normalizar($texto));
        if (preg_match('/^(todos|todas|todos\s+me\s+sirven|todas\s+me\s+sirven)[.!]*$/u', $t)) {
            return range(1, $total);
        }
        // «opci(ones|on)» y no «opciones?»: eso exige la «e» y dejaba fuera el
        // singular. En vivo (30-sep) el Dr. Tigre respondió «Opción 12» a una
        // lista de 12, no se leyó como número, y al tutor le llegó «Opción 12»
        // pelado, sin saber qué horario era.
        $t = preg_replace('/^((la|las|el|los)\s+)?(opci(ones|on)|numeros?|nros?\.?|puedo( en)?|me sirven?( las?)?|las?)\s*:?\s*/u', '', $t);
        if (! preg_match('/^\d{1,2}(\s*(,|;|y|\-|a|\s)\s*\d{1,2})*[.!]*$/u', $t)) {
            return null;
        }
        $out = [];
        foreach (preg_split('/\s*(?:,|;|\by\b|\s)\s*/u', preg_replace('/\s*(-|\ba\b)\s*/u', '-', rtrim($t, '.!'))) as $trozo) {
            if ($trozo === '') {
                continue;
            }
            if (preg_match('/^(\d+)-(\d+)$/', $trozo, $m)) {
                $out = array_merge($out, range((int) min($m[1], $m[2]), (int) max($m[1], $m[2])));
            } else {
                $out[] = (int) $trozo;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    private const DIAS = [
        'lunes' => 1, 'lun' => 1, 'martes' => 2, 'mar' => 2, 'miercoles' => 3, 'mie' => 3, 'mier' => 3,
        'jueves' => 4, 'jue' => 4, 'viernes' => 5, 'vie' => 5, 'sabado' => 6, 'sab' => 6,
        'domingo' => 7, 'dom' => 7,
    ];

    private const MESES = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'ago' => 8, 'sep' => 9, 'set' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12];

    private const NOMBRE_DIA = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves',
        5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    private const NOMBRE_MES = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo',
        6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre',
        11 => 'noviembre', 12 => 'diciembre'];

    /**
     * @return array{opciones: list<CarbonImmutable>, problemas: list<string>}
     *   `problemas`: lo que no cuadra, en palabras para el especialista. Si hay
     *   alguno, no se le ofrece nada al tutor hasta que lo aclare.
     */
    public static function extraer(string $texto, CarbonImmutable $ahora): array
    {
        $ahora = $ahora->setTimezone('America/Santiago');
        $t = self::normalizar($texto);

        $dia = '(?<wd>lunes|lun|martes|mar|miercoles|mier|mie|jueves|jue|viernes|vie|sabado|sab|domingo|dom)\.?';
        $mes = '(?:ene|feb|mar|abr|may|jun|jul|ago|sep|set|oct|nov|dic)[a-z]*';
        $patron = '/'
            // «lunes 02 de octubre», «jue 2», «vie 3/10»; el número no es una hora («jue 10:00»)
            . "(?<conDia>\\b{$dia}(?:\\s+(?<wdN>\\d{1,2})(?![\\d:.]|\\s*(?:h|hrs?|horas|am|pm)\\b)(?:\\s+de\\s+(?<wdM>{$mes})|\\/(?<wdMn>\\d{1,2}))?)?)(?![a-z])"
            . "|(?<fechaDm>\\b(?<dmD>\\d{1,2})\\/(?<dmM>\\d{1,2})(?:\\/(?<dmA>\\d{2,4}))?\\b)"
            . "|(?<fechaDe>\\b(?<ddD>\\d{1,2})\\s+de\\s+(?<ddM>{$mes}))"
            . '|(?<rel>\bhoy\b|\bpasado\s+manana\b|(?<!la\s)(?<!las\s)\bmanana\b)'
            // Sin \b delante: en «octu10:00» la hora viene pegada al mes.
            . '|(?<hora>(?<!\d)(?<h1>\d{1,2})\s*[:.h]\s*(?<m>\d{2})(?!\d)'
            . '|(?<!\d)(?<h2>\d{1,2})\s*(?<suf>hrs?|horas|h|am|pm)\b'
            . '|\ba\s+las?\s+(?<h3>\d{1,2})(?![\d:.]))'
            . '/u';

        preg_match_all($patron, $t, $partes, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $opciones = [];
        $problemas = [];
        $diaActual = null;
        foreach ($partes as $p) {
            if (($p['conDia'] ?? null) !== null) {
                [$diaActual, $problema] = self::diaSemana($p, $ahora);
                if ($problema) {
                    $problemas[] = $problema;
                }
            } elseif (($p['fechaDm'] ?? null) !== null) {
                [$diaActual, $problema] = self::fecha((int) $p['dmD'], (int) $p['dmM'], $p['dmA'], $ahora);
                if ($problema) {
                    $problemas[] = $problema;
                }
            } elseif (($p['fechaDe'] ?? null) !== null) {
                [$diaActual, $problema] = self::fecha((int) $p['ddD'], self::mes($p['ddM']), null, $ahora);
                if ($problema) {
                    $problemas[] = $problema;
                }
            } elseif (($p['rel'] ?? null) !== null) {
                $hoy = $ahora->startOfDay();
                $diaActual = match (true) {
                    $p['rel'] === 'hoy' => $hoy,
                    str_starts_with($p['rel'], 'pasado') => $hoy->addDays(2),
                    default => $hoy->addDay(),
                };
            } elseif (($p['hora'] ?? null) !== null && $diaActual !== null) {
                $cuando = self::hora($diaActual, (int) ($p['h1'] ?? $p['h2'] ?? $p['h3']),
                    $p['m'] !== null ? (int) $p['m'] : 0, $p['suf']);
                if ($cuando === null) {
                    continue;
                }
                if ($cuando->lte($ahora)) {
                    $problemas[] = self::legible($cuando) . ' ya pasó.';
                } elseif ($cuando->startOfDay()->gt($ahora->startOfDay()->addDays(self::MAX_DIAS))) {
                    $problemas[] = self::legible($cuando) . ' está a más de tres meses; eso no se puede agendar todavía.';
                } else {
                    $opciones[$cuando->format('Y-m-d H:i')] = $cuando;
                }
            }
        }

        ksort($opciones);

        return [
            'opciones' => array_slice(array_values($opciones), 0, self::MAX_OPCIONES),
            'problemas' => array_values(array_unique($problemas)),
        ];
    }

    /** «viernes 2 de octubre, 10:00». */
    public static function legible(CarbonImmutable $c): string
    {
        return self::NOMBRE_DIA[$c->dayOfWeekIso] . ' ' . $c->day . ' de ' . self::NOMBRE_MES[$c->month]
            . ', ' . $c->format('H:i');
    }

    /** «Vie 2 oct · 10:00»: cabe en los 24 caracteres de una fila de lista (y en los 20 de un botón). */
    public static function corto(CarbonImmutable $c): string
    {
        return ucfirst(mb_substr(self::NOMBRE_DIA[$c->dayOfWeekIso], 0, 3)) . ' ' . $c->day . ' '
            . mb_substr(self::NOMBRE_MES[$c->month], 0, 3) . ' · ' . $c->format('H:i');
    }

    // ── internas ──────────────────────────────────────────────────────────

    private static function normalizar(string $t): string
    {
        $t = mb_strtolower($t);
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        // «a las 10 y 15» no es una lista de dos horas: sin esto el «15» suelto
        // se leería como otra opción. Se pasa a «10:15».
        $t = preg_replace('/\b(\d{1,2})\s+y\s+(cuarto|media)\b/u', '$1:$2', $t);

        return strtr($t, [':cuarto' => ':15', ':media' => ':30']);
    }

    /** @return array{0: ?CarbonImmutable, 1: ?string} */
    private static function diaSemana(array $p, CarbonImmutable $ahora): array
    {
        $iso = self::DIAS[$p['wd']];
        if ($p['wdN'] === null) {
            // Solo el día: el próximo con ese nombre. Hoy cuenta (la hora dirá si ya pasó).
            $d = $ahora->startOfDay();
            while ($d->dayOfWeekIso !== $iso) {
                $d = $d->addDay();
            }

            return [$d, null];
        }

        $mes = $p['wdM'] !== null ? self::mes($p['wdM']) : ($p['wdMn'] !== null ? (int) $p['wdMn'] : null);
        [$d, $problema] = self::fecha((int) $p['wdN'], $mes, null, $ahora);
        if ($d && $d->dayOfWeekIso !== $iso) {
            return [null, "El {$d->day} de " . self::NOMBRE_MES[$d->month] . ' es ' . self::NOMBRE_DIA[$d->dayOfWeekIso]
                . ', no ' . self::NOMBRE_DIA[$iso] . '.'];
        }

        return [$d, $problema];
    }

    /**
     * Un día del mes. Sin mes: este mes si todavía no pasó, si no el que
     * viene. Sin año: el próximo en que caiga esa fecha.
     *
     * @return array{0: ?CarbonImmutable, 1: ?string}
     */
    private static function fecha(int $dia, ?int $mes, ?string $anio, CarbonImmutable $ahora): array
    {
        $hoy = $ahora->startOfDay();
        if ($mes !== null && ($mes < 1 || $mes > 12)) {
            return [null, null];
        }
        $a = $anio !== null ? (int) $anio : $hoy->year;
        if ($anio !== null && $a < 100) {
            $a += 2000;
        }
        $m = $mes ?? $hoy->month;
        if (! checkdate($m, $dia, $a)) {
            return [null, "No existe el {$dia} de " . self::NOMBRE_MES[$m] . '.'];
        }
        $d = CarbonImmutable::create($a, $m, $dia, 0, 0, 0, 'America/Santiago');
        if ($anio === null && $d->lt($hoy)) {
            $d = $mes === null ? $d->addMonthNoOverflow() : $d->addYear();
            if ($d->day !== $dia) {
                return [null, "No existe el {$dia} de " . self::NOMBRE_MES[$d->month] . '.'];
            }
        }

        return [$d, null];
    }

    private static function mes(string $m): ?int
    {
        return self::MESES[substr($m, 0, 3)] ?? null;
    }

    private static function hora(CarbonImmutable $dia, int $h, int $m, ?string $suf): ?CarbonImmutable
    {
        if ($suf === 'pm' && $h < 12) {
            $h += 12;
        } elseif ($suf !== 'am' && $h >= 1 && $h <= 7) {
            $h += 12;
        }
        if ($h > 23 || $m > 59) {
            return null;
        }

        return $dia->setTime($h, $m);
    }
}
