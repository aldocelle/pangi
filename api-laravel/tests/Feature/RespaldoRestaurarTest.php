<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * La red previa de `respaldo:restaurar` (respaldar lo que hay antes de pisarlo)
 * no puede impedir restaurar sobre una base VACÍA —el caso de un desastre o de
 * una base nueva—, pero tiene que seguir siendo obligatoria en cuanto haya algo.
 *
 * Procesos separados, como el resto de la suite: `bootstrap/app.php` declara
 * una función global y no aguanta levantarse dos veces en el mismo proceso.
 */
#[RunTestsInSeparateProcesses]
class RespaldoRestaurarTest extends TestCase
{
    private string $sql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sql = tempnam(sys_get_temp_dir(), 'restaurar') . '.sql';
        file_put_contents($this->sql, "-- prueba\nCREATE TABLE restaurada (id INTEGER);\nINSERT INTO restaurada VALUES (1);\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->sql);
        parent::tearDown();
    }

    private function restaurar(): string
    {
        Artisan::call('respaldo:restaurar', [
            '--sql' => $this->sql,
            '--confirmar' => DB::connection()->getDatabaseName(),
        ]);
        return Artisan::output();
    }

    public function test_sobre_una_base_vacia_restaura_sin_pedir_la_red(): void
    {
        $this->assertSame([], Schema::getTables());
        // La verificación final de archivos mira tablas que esta base de
        // juguete no tiene; no es lo que se prueba acá.
        Artisan::command('respaldo:verificar', fn () => 0);
        $salida = $this->restaurar();
        $this->assertStringContainsString('está vacía', $salida);
        $this->assertStringNotContainsString('El respaldo previo falló', $salida);
        $this->assertSame(1, DB::table('restaurada')->count());
    }

    public function test_un_volcado_nuestro_truncado_se_rechaza_sin_tocar_nada(): void
    {
        // Cabecera de respaldo:crear, datos, y SIN la línea de cierre: lo que
        // dejaba un respaldo cortado a mitad antes de escribir en `.parcial`.
        file_put_contents($this->sql, "-- Respaldo de x\n-- Generado por `php artisan respaldo:crear`. Restaurar con `respaldo:restaurar`.\n"
            . "CREATE TABLE restaurada (id INTEGER);\nINSERT INTO restaurada VALUES (1);\n");
        $salida = $this->restaurar();
        $this->assertStringContainsString('truncado', $salida);
        $this->assertFalse(Schema::hasTable('restaurada'));
    }

    public function test_con_una_sola_tabla_la_red_sigue_siendo_obligatoria(): void
    {
        Schema::create('algo', fn ($t) => $t->id());
        // El respaldo real no corre dentro de un test (tiene su propio corte por
        // tiempo). Lo que se fija acá es que la red SE PIDA y que, si falla, no
        // se restaure nada: un sustituto que falla basta para eso.
        $pedido = false;
        Artisan::command('respaldo:crear {--solo-base}', function () use (&$pedido) {
            $pedido = true;
            return 1;
        });
        $salida = $this->restaurar();
        $this->assertTrue($pedido, 'Con tablas en la base, la red previa tiene que pedirse.');
        $this->assertStringContainsString('El respaldo previo falló', $salida);
        $this->assertFalse(Schema::hasTable('restaurada'));
    }
}
