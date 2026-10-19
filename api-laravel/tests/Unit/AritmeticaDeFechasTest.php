<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Días de calendario contados dentro de una zona con horario de verano.
 *
 * EL BUG, Y POR QUÉ NO SE VE
 *
 * Chile cambia la hora dos veces al año. En esos tramos un «día» dura 23 o 25
 * horas, y Carbon lo nota:
 *
 *   · `diffInDays` devuelve un decimal (6,9583 en vez de 7) y el `(int)` lo
 *     trunca hacia abajo.
 *   · `addDays` corre la hora: `parse('2026-09-06')->addDays(7)` da las 01:00
 *     del día 13, no las 00:00. En abril el error va al otro lado.
 *
 * Los dos se descubrieron el 06-sep-2026, que fue justo el día del cambio. El
 * primero lo cazó el arnés (`Py=7 Lv=6` en `dias_restantes`); el segundo NO lo
 * habría cazado nadie: apareció midiendo, después de estar a punto de dar por
 * seguro que `addDays` era inmune.
 *
 * **Diez meses de doce este código parece correcto.** Por eso hace falta un
 * detector y no basta con haberlo arreglado: la próxima vez que alguien escriba
 * `->addDay()` sobre un borde de día, el fallo no aparecerá hasta la siguiente
 * transición, y para entonces nadie relacionará las dos cosas.
 *
 * LO QUE ESTE TEST FIJA
 *
 * Primero, que el problema es REAL en esta zona horaria — si algún día PHP o
 * Carbon lo arreglan, este test se cae y avisa de que la precaución sobra.
 * Después, que `App\Services\Fechas` lo esquiva. Y por último, la regla amplia:
 * que nadie use `diffInDays` fuera del helper (§13.8 — más amplia que el bug,
 * sin falsos negativos y sin nada que discutir en una revisión).
 */
class AritmeticaDeFechasTest extends TestCase
{
    private const TZ = 'America/Santiago';

    /** 6-sep-2026: el día en que Chile adelantó el reloj. */
    private const CAMBIO = '2026-09-06';

    protected function setUp(): void
    {
        parent::setUp();
        date_default_timezone_set(self::TZ);
    }

    public function test_el_problema_existe_de_verdad_en_esta_zona(): void
    {
        $hoy = CarbonImmutable::parse(self::CAMBIO, self::TZ);
        $destino = CarbonImmutable::parse('2026-09-13', self::TZ);

        // 167 horas, no 168: la madrugada del 6 tuvo 23.
        $this->assertSame(167, (int) $hoy->diffInHours($destino, false),
            'Si esto da 168, Chile dejó de cambiar la hora o cambió la fecha de la transición.');

        // Y por eso `diffInDays` no da 7 entero.
        $this->assertNotSame(7, (int) $hoy->diffInDays($destino, false),
            'Carbon dejó de truncar: revisar si App\Services\Fechas sigue haciendo falta.');

        // `addDays` tampoco cae a medianoche.
        $this->assertNotSame('00:00', $hoy->addDays(7)->format('H:i'),
            'addDays dejó de correrse: revisar si sumarDias sigue haciendo falta.');
    }

    public function test_contar_dias_en_UTC_da_el_numero_de_calendario(): void
    {
        // Lo que hace `Fechas::diasEntre`. Se reproduce con las mismas llamadas
        // en vez de invocar el servicio: `config()` necesita la aplicación y lo
        // que se prueba acá es la ARITMÉTICA, no el cableado de Laravel.
        $a = CarbonImmutable::parse(self::CAMBIO, 'UTC');
        $b = CarbonImmutable::parse('2026-09-13', 'UTC');

        $this->assertSame(7, (int) $a->diffInDays($b, false));
        $this->assertSame(-7, (int) $b->diffInDays($a, false));
        $this->assertSame(15, (int) $a->diffInDays(CarbonImmutable::parse('2026-09-21', 'UTC'), false));
    }

    public function test_sumar_dias_en_UTC_cae_a_medianoche(): void
    {
        $a = CarbonImmutable::parse(self::CAMBIO, 'UTC');

        $this->assertSame('2026-09-13 00:00', $a->addDays(7)->format('Y-m-d H:i'));
        // Y el borde de «día siguiente», que es el uso que rompía el cuadre de
        // caja y el rango de la agenda.
        $this->assertSame('2026-09-07 00:00', $a->addDays(1)->format('Y-m-d H:i'));
    }

    public function test_la_causa_es_una_medianoche_que_no_existe(): void
    {
        // El 6 de septiembre el reloj salta de 00:00 a 01:00, así que esa
        // medianoche NO EXISTE y `parse()` devuelve ya las 01:00. Todo lo que
        // se calcule desde ahí arrastra la hora — esta es la causa, y el
        // decimal de `diffInDays` es solo el síntoma.
        $this->assertSame('01:00',
            CarbonImmutable::parse(self::CAMBIO, self::TZ)->format('H:i'),
            'Si esto da 00:00, la fecha de la transición cambió: rehacer las medidas.');

        // El día anterior y el siguiente sí tienen su medianoche.
        $this->assertSame('00:00', CarbonImmutable::parse('2026-09-05', self::TZ)->format('H:i'));
        $this->assertSame('00:00', CarbonImmutable::parse('2026-09-07', self::TZ)->format('H:i'));
    }

    public function test_abril_NO_esta_afectado_y_esto_se_midio(): void
    {
        // Corrige una afirmación falsa que estuvo escrita en el código: que en
        // la transición de abril el error iba «al otro lado». No es cierto —
        // se dedujo en vez de medirlo. En abril el reloj ATRASA, la medianoche
        // del 5 existe, y local y UTC coinciden.
        foreach (['2026-04-04', '2026-04-05', '2026-04-06'] as $d) {
            $this->assertSame(
                CarbonImmutable::parse($d, 'UTC')->addDay()->format('Y-m-d H:i'),
                CarbonImmutable::parse($d, self::TZ)->addDay()->format('Y-m-d H:i'),
                "En {$d} local y UTC deberían coincidir: abril no salta hacia adelante.");
        }
    }

    public function test_el_dano_es_de_dos_dias_al_ano(): void
    {
        // Barrido del año entero. Sirve para dos cosas: acota el problema —no
        // es «las fechas están mal», son dos días— y avisa si algún día PHP,
        // Carbon o las reglas de horario de Chile cambian.
        $afectados = [];
        for ($i = 0; $i < 365; $i++) {
            $d = date('Y-m-d', strtotime("2026-01-01 +{$i} days"));
            $local = CarbonImmutable::parse($d, self::TZ)->addDay()->format('Y-m-d H:i');
            $utc = CarbonImmutable::parse($d, 'UTC')->addDay()->format('Y-m-d H:i');
            if ($local !== $utc) {
                $afectados[] = $d;
            }
        }

        $this->assertSame(['2026-09-05', '2026-09-06'], $afectados,
            'Cambió el conjunto de días afectados: revisar App\Services\Fechas '
            . 'y los comentarios que citan estas fechas.');
    }

    /**
     * La regla amplia: `diffInDays` solo puede vivir en el helper.
     *
     * Más amplia que el bug a propósito. No tiene falsos negativos y no admite
     * discusión en una revisión — una regla que exige criterio caso por caso
     * deja de aplicarse.
     */
    public function test_nadie_usa_diffInDays_fuera_del_helper(): void
    {
        $culpables = [];
        $raiz = dirname(__DIR__, 2) . '/app';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));

        foreach ($it as $archivo) {
            if (! $archivo->isFile() || $archivo->getExtension() !== 'php') {
                continue;
            }
            if ($archivo->getFilename() === 'Fechas.php') {
                continue;   // el único lugar donde la cuenta es correcta
            }
            foreach (file($archivo->getPathname()) as $n => $linea) {
                // Se ignoran los comentarios: varios explican este mismo bug y
                // el detector nacería en rojo por su propia documentación —el
                // mismo motivo por el que `test_fechas_para_el_cliente.py` va
                // por AST y no por grep.
                $limpia = ltrim($linea);
                if (str_starts_with($limpia, '//') || str_starts_with($limpia, '*')) {
                    continue;
                }
                if (str_contains($linea, 'diffInDays(')) {
                    $culpables[] = str_replace($raiz . '/', '', $archivo->getPathname())
                        . ':' . ($n + 1);
                }
            }
        }

        $this->assertSame([], $culpables,
            "diffInDays fuera de App\\Services\\Fechas: cuenta las horas y el cambio "
            . "de horario le roba una. Usar Fechas::diasEntre(). En: " . implode(', ', $culpables));
    }
}
