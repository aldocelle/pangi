<?php

namespace Tests\Unit;

use App\Services\HorariosOfrecidos;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lo que un especialista escribe por WhatsApp, leído sin IA. Lo que no cuadra
 * se le pregunta a él; lo que no se entiende no se adivina.
 */
class HorariosOfrecidosTest extends TestCase
{
    /** Miércoles 30 de septiembre de 2026, 13:10: el día de la prueba en vivo. */
    private static function ahora(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 9, 30, 13, 10, 0, 'America/Santiago');
    }

    public static function casos(): array
    {
        return [
            'con fecha' => ['viernes 02 de octubre 10:00', ['Vie 2 oct · 10:00']],
            'varios, a las 3 es de tarde' => ['jue 1 10:00 y 15:30, vie 2 a las 3',
                ['Jue 1 oct · 10:00', 'Jue 1 oct · 15:30', 'Vie 2 oct · 15:00']],
            'mañana y pasado' => ['mañana 11:00 o pasado mañana 9.30', ['Jue 1 oct · 11:00', 'Vie 2 oct · 09:30']],
            'hrs y h' => ['martes 10h, jueves 16 hrs', ['Jue 1 oct · 16:00', 'Mar 6 oct · 10:00']],
            'barra' => ['2/10 12:00', ['Vie 2 oct · 12:00']],
            'y media' => ['el 5 de octubre a las 10 y media', ['Lun 5 oct · 10:30']],
            'teléfono no es hora' => ['martes 10:00 o jueves 16:00. llámeme al +56 9 8790 1080',
                ['Jue 1 oct · 16:00', 'Mar 6 oct · 10:00']],
            'mar de martes con mes' => ['mar 6 de oct 10:00', ['Mar 6 oct · 10:00']],
            'am' => ['sábado 8 am', ['Sáb 3 oct · 08:00']],
            'sin hora no hay opción' => ['¿podrá ser el miércoles?', []],
            'en la mañana no es un día' => ['en la mañana del martes', []],
        ];
    }

    #[DataProvider('casos')]
    public function test_lee_los_horarios(string $texto, array $esperado): void
    {
        $r = HorariosOfrecidos::extraer($texto, self::ahora());

        $this->assertSame([], $r['problemas']);
        $this->assertSame($esperado, array_map(fn ($c) => HorariosOfrecidos::corto($c), $r['opciones']));
    }

    public function test_el_dia_que_no_cuadra_con_la_fecha_se_pregunta(): void
    {
        // Lo que escribió el Dr. Tigre en la prueba en vivo.
        $r = HorariosOfrecidos::extraer('lunes 02 de octu10:00', self::ahora());

        $this->assertSame([], $r['opciones']);
        $this->assertSame(['El 2 de octubre es viernes, no lunes.'], $r['problemas']);
    }

    public function test_la_lista_propuesta_salta_el_domingo_y_lo_ocupado(): void
    {
        $p = HorariosOfrecidos::propuestos(self::ahora(), ['2026-10-02 12:30']);

        $this->assertSame("Jue 1 oct: 1) 10:00 · 2) 12:30 · 3) 16:00\n"
            . "Vie 2 oct: 4) 10:00 · 5) 16:00\n"
            . "Sáb 3 oct: 6) 10:00 · 7) 12:30 · 8) 16:00\n"
            . 'Lun 5 oct: 9) 10:00 · 10) 12:30 · 11) 16:00', HorariosOfrecidos::listaNumerada($p));
    }

    public function test_los_numeros_elegidos(): void
    {
        $this->assertSame([1, 5, 6], HorariosOfrecidos::numeros('1, 5, 6', 11));
        $this->assertSame([2, 4], HorariosOfrecidos::numeros('2 y 4', 11));
        $this->assertSame([4, 5, 6], HorariosOfrecidos::numeros('4 a 6', 11));
        $this->assertSame([1, 2, 3, 9], HorariosOfrecidos::numeros('1-3, 9', 11));
        $this->assertSame([1, 2], HorariosOfrecidos::numeros('me sirven 1 y 2', 11));
        $this->assertSame(range(1, 11), HorariosOfrecidos::numeros('todos', 11));
        // En vivo (30-sep): «Opción 12» a una lista de 12 se leía como texto libre.
        $this->assertSame([12], HorariosOfrecidos::numeros('Opción 12', 12));
        $this->assertSame([3], HorariosOfrecidos::numeros('la opción 3', 12));
        $this->assertSame([1, 4], HorariosOfrecidos::numeros('opciones 1 y 4', 12));
        $this->assertSame([2], HorariosOfrecidos::numeros('número 2', 12));
        // Un horario escrito no es una elección de números.
        $this->assertNull(HorariosOfrecidos::numeros('10:00', 11));
        $this->assertNull(HorariosOfrecidos::numeros('jue 2 10:00', 11));
    }

    public function test_fechas_imposibles_o_pasadas_se_dicen(): void
    {
        $this->assertSame(['No existe el 31 de septiembre.'],
            HorariosOfrecidos::extraer('31 de septiembre 10:00', self::ahora())['problemas']);
        $this->assertSame(['miércoles 30 de septiembre, 12:00 ya pasó.'],
            HorariosOfrecidos::extraer('hoy 12:00', self::ahora())['problemas']);
    }
}
