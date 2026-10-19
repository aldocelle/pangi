<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El interruptor de los correos que se mandan solos.
 *
 * Dos cron escriben a clientes reales sin que nadie haga nada
 * (`recordatorios:citas` y `avisos:preventivos`). `CORREOS_AUTOMATICOS` apaga
 * su ENVÍO, y por defecto viene apagado.
 *
 * Lo que fija este archivo es la LECTURA de la variable, que es donde estaba el
 * bug y donde volvería a estar: **`env()` de Laravel castea solo**. Con
 * `CORREOS_AUTOMATICOS=true` en el `.env` devuelve el booleano `true`, no la
 * cadena `"true"`, así que el idiom `(string) env(...) === 'true'` da `"1" ===
 * "true"` → `false` y el interruptor queda apagado para siempre, pase lo que
 * pase en el entorno.
 *
 * Es un modo de falla silencioso —parece configurado y no lo está— y ya vivía
 * en `AvisosPreventivos` desde el port: `RECORDATORIOS_AVISO_AUTOMATICO` no se
 * podía encender de ninguna manera. No lo vio nadie porque el arnés ejercita
 * ese comando con `--forzar`, que se salta justo esa línea.
 */
class CorreosAutomaticosTest extends TestCase
{
    /** Lo que de verdad devuelve `env()` cuando el `.env` dice `=true`. */
    public static function valoresQueCuentanComoEncendido(): array
    {
        return [
            'booleano (lo que devuelve env() con =true)' => [true],
            'cadena "true" (si algún día env() dejara de castear)' => ['true'],
            'cadena "1"' => ['1'],
        ];
    }

    #[DataProvider('valoresQueCuentanComoEncendido')]
    public function test_enciende_con_cualquiera_de_las_formas_de_true(mixed $valor): void
    {
        $this->assertTrue(
            filter_var($valor, FILTER_VALIDATE_BOOLEAN),
            'El interruptor tiene que encenderse con esta forma de «true».',
        );
    }

    public static function valoresQueCuentanComoApagado(): array
    {
        return [
            'ausente' => [null],
            'booleano false' => [false],
            'cadena "false"' => ['false'],
            'vacío' => [''],
            'cualquier otra cosa' => ['si'],
        ];
    }

    #[DataProvider('valoresQueCuentanComoApagado')]
    public function test_queda_apagado_con_todo_lo_demas(mixed $valor): void
    {
        $this->assertFalse(filter_var($valor, FILTER_VALIDATE_BOOLEAN));
    }

    /**
     * El test que habría atrapado el bug. No es una curiosidad de PHP: es la
     * razón exacta por la que `RECORDATORIOS_AVISO_AUTOMATICO` no se podía
     * encender. Si alguien vuelve a escribir el idiom viejo, esto lo explica.
     */
    public function test_el_idiom_viejo_dejaba_el_interruptor_muerto(): void
    {
        $loQueDevuelveEnv = true; // env('CORREOS_AUTOMATICOS') con «=true» en .env

        $this->assertSame('1', (string) $loQueDevuelveEnv);
        $this->assertFalse(
            mb_strtolower((string) $loQueDevuelveEnv) === 'true',
            'Si esto pasa a true, el idiom viejo ya no es peligroso y este test sobra.',
        );
        $this->assertTrue(filter_var($loQueDevuelveEnv, FILTER_VALIDATE_BOOLEAN));
    }
}
