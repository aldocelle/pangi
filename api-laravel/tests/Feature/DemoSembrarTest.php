<?php

namespace Tests\Feature;

use App\Console\Commands\DemoSembrar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * `demo:sembrar` BORRA datos. Lo que se fija acá son sus candados: dónde se
 * niega a correr. La siembra en sí se probó contra una MariaDB 11.8 local con
 * la configuración del lab (ver el commit que la agrega).
 */
#[RunTestsInSeparateProcesses]
class DemoSembrarTest extends TestCase
{
    public function test_solo_corre_en_bases_de_pruebas_y_nunca_en_las_de_produccion(): void
    {
        foreach (['cuenta_lab_emr', 'clinica_demo', 'emr_pruebas'] as $b) {
            $this->assertTrue(DemoSembrar::esBaseDePruebas($b), $b);
        }
        foreach (['emr_produccion', 'sitio_produccion', 'produccion', 'emr_v0826', ':memory:'] as $b) {
            $this->assertFalse(DemoSembrar::esBaseDePruebas($b), $b);
        }
    }

    public function test_en_una_base_que_no_es_de_pruebas_no_toca_nada(): void
    {
        // La base de los tests se llama «:memory:»: no es de pruebas para el
        // comando, así que tiene que negarse aunque se le pase el nombre exacto.
        DB::statement('CREATE TABLE usuarios (id TEXT, email TEXT)');
        DB::table('usuarios')->insert(['id' => '1', 'email' => 'x@x.cl']);
        $codigo = Artisan::call('demo:sembrar', ['--confirmar' => DB::connection()->getDatabaseName(), '--sin-red' => true]);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('no es una base de pruebas', Artisan::output());
        $this->assertSame(1, DB::table('usuarios')->count());
    }

    public function test_los_rut_inventados_tienen_digito_verificador_correcto(): void
    {
        $this->assertSame('12.345.678-5', DemoSembrar::rut(12345678));
        $this->assertSame('11.111.111-1', DemoSembrar::rut(11111111));
    }

    public function test_los_correos_y_telefonos_inventados_no_pueden_llegar_a_nadie(): void
    {
        $this->assertSame('correo.test', DemoSembrar::DOMINIO);   // TLD reservado (RFC 2606)
        $this->assertTrue(app(\App\Services\Correo::class)->esDireccionDePrueba('a@' . DemoSembrar::DOMINIO));
        $this->assertStringStartsWith('+56 9 0000 0', DemoSembrar::telefono(12));
    }
}
