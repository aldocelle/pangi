<?php

namespace Tests\Feature;

use App\Services\AsistenteReservas;
use App\Services\ProbarPregunta;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * El modal «Enseñarle» de Ajustes → Asistente: antes de guardar, muestra lo que
 * el chat contestaría DE VERDAD con la pregunta nueva, y por qué no, si no.
 * Las reglas son las del chat, no una copia en el frontend.
 */
#[RunTestsInSeparateProcesses]
class ProbarPreguntaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('ajustes');
        Schema::dropIfExists('servicios');
        Schema::create('ajustes', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->text('data')->nullable();
        });
        Schema::create('servicios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->integer('orden')->default(0); $t->integer('precio_clp')->nullable();
            $t->integer('precio_sabado_clp')->nullable(); $t->string('precio_detalle')->nullable(); $t->string('grupo')->nullable();
        });
        DB::table('ajustes')->insert(['id' => 1, 'data' => json_encode(['asistente' => ['datos' => [
            'preguntas' => [['claves' => 'estacionamiento, estacionar', 'respuesta' => 'Hay estacionamiento en la calle.']],
        ]]])]);
    }

    private function probar(array $textos, string $claves, string $respuesta, ?array $reservas = null): array
    {
        $mock = Mockery::mock(AsistenteReservas::class);
        $mock->shouldReceive('resolver')->andReturnUsing(
            fn ($t) => str_contains($t, 'agendar') ? ($reservas ?? ['texto' => 'Elige una consulta', 'motivo' => 'reservar:elegir']) : null);
        $this->app->instance(AsistenteReservas::class, $mock);

        return $this->app->make(ProbarPregunta::class)->probar($textos, $claves, $respuesta);
    }

    public function test_la_pregunta_nueva_contesta_con_su_respuesta(): void
    {
        $r = $this->probar(['atienden conejos?'], 'conejo', 'Sí, atendemos conejos.')['pruebas'][0];
        $this->assertSame('nueva', $r['origen']);
        $this->assertSame('Sí, atendemos conejos.', $r['respuesta']);
        $this->assertNull($r['explicacion']);
    }

    public function test_sin_palabra_que_calce_lo_explica(): void
    {
        $r = $this->probar(['atienden conejos?'], 'hurones', 'Sí.')['pruebas'][0];
        $this->assertNull($r['respuesta']);
        $this->assertStringContainsString('ninguna palabra clave', mb_strtolower($r['explicacion']));
    }

    public function test_si_calza_con_otra_no_contesta_ninguna_y_dice_con_cual(): void
    {
        $r = $this->probar(['se puede estacionar con conejos?'], 'conejo', 'Sí.')['pruebas'][0];
        $this->assertNull($r['respuesta']);
        $this->assertSame('estacionamiento, estacionar', $r['choca_con'][0]['claves']);
    }

    public function test_lo_que_siempre_va_a_una_persona_nombra_la_palabra(): void
    {
        $r = $this->probar(['mi perro es un conejo?'], 'conejo', 'Sí.')['pruebas'][0];
        $this->assertNull($r['respuesta']);
        $this->assertStringContainsString('«mi perro»', $r['explicacion']);
    }

    public function test_lo_que_toma_el_asistente_de_reservas_no_usa_la_respuesta(): void
    {
        $r = $this->probar(['quiero agendar para mi conejo'], 'conejo', 'Sí.')['pruebas'][0];
        $this->assertSame('reservas', $r['origen']);
        $this->assertSame('Elige una consulta', $r['respuesta']);
    }

    public function test_errores_con_las_mismas_reglas_que_al_guardar(): void
    {
        $this->assertArrayHasKey('claves', $this->probar(['x'], 'pez, gato', 'Sí.')['errores']);
        $this->assertArrayHasKey('respuesta', $this->probar(['x'], 'conejo', '  ')['errores']);
        $this->assertSame([], $this->probar(['x'], 'conejo', 'Sí.')['errores']);
    }
}
