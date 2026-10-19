<?php

namespace Tests\Feature;

use App\Services\RespuestaDirecta;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * Espejo de las pruebas de `asistente.datos` en
 * `backend/tests/test_respuesta_directa.py`: lo que la oficina le escribe al
 * asistente se contesta, y sobre todo no le gana a lo que tiene que derivar.
 *
 * Corre sobre SQLite en memoria (phpunit.xml), con solo las dos tablas que lee
 * el resolver. NUNCA contra la MariaDB del hosting, que es a la que apunta
 * `.env` (§13.4).
 *
 * En procesos separados: con PHP 8.5, rearmar la app sobre SQLite en memoria
 * entre dos tests del mismo proceso terminaba el proceso («Premature end of PHP
 * process») en el `setUp` del segundo. Cada test suelto pasaba.
 */
#[RunTestsInSeparateProcesses]
class RespuestaDirectaDatosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('ajustes');
        Schema::dropIfExists('servicios');
        Schema::create('ajustes', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->text('data')->nullable();
        });
        Schema::create('servicios', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('nombre');
            $t->boolean('activo')->default(true);
            $t->integer('orden')->default(0);
            $t->integer('precio_clp')->nullable();
            $t->integer('precio_sabado_clp')->nullable();
            $t->string('precio_detalle')->nullable();
            $t->string('grupo')->nullable();
        });
        DB::table('servicios')->insert([
            ['id' => 'a', 'nombre' => 'Consulta General', 'activo' => true, 'orden' => 0,
                'precio_clp' => 15000, 'precio_sabado_clp' => 20000],
        ]);
    }

    private function datos(array $datos): void
    {
        DB::table('ajustes')->where('id', 1)->delete();
        DB::table('ajustes')->insert(['id' => 1, 'data' => json_encode(['asistente' => ['datos' => $datos]])]);
    }

    private function r(string $msg): ?array
    {
        return (new RespuestaDirecta())->resolver($msg);
    }

    public function test_la_direccion_viene_por_defecto(): void
    {
        $r = $this->r('donde estan ubicados?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('Av. Principal 1234', $r['texto']);
        $this->assertStringContainsString('local 5', $r['texto']);
    }

    public function test_sin_direccion_se_deriva_como_antes(): void
    {
        $this->datos(['direccion' => '']);
        $this->assertNull($this->r('donde estan ubicados?'));
    }

    public function test_el_horario_se_contesta_solo_si_la_oficina_lo_escribio(): void
    {
        $this->assertNull($this->r('a que hora abren?'));
        $this->datos(['horario' => 'Lunes a viernes de 10 a 19 h']);
        $this->assertStringContainsString('10 a 19', $this->r('a que hora abren?')['texto']);
        $this->assertNotNull($this->r('atienden los domingos?'));
    }

    public function test_lugar_y_horario_juntos_o_ninguno(): void
    {
        $this->datos(['direccion' => 'Av. Principal 1234', 'horario' => '']);
        $this->assertNull($this->r('donde estan y a que hora abren?'));
        $this->datos(['direccion' => 'Av. Principal 1234', 'horario' => 'De 10 a 19 h']);
        $texto = $this->r('donde estan y a que hora abren?')['texto'];
        $this->assertStringContainsString('Av. Principal', $texto);
        $this->assertStringContainsString('10 a 19', $texto);
    }

    public function test_el_precio_del_sabado_no_se_contesta_con_el_horario(): void
    {
        // Hasta sep-2026 esto derivaba, y lo que protegía era no contestar el
        // HORARIO a una pregunta de valor. Ahora contesta los valores, que traen
        // el del sábado: lo que se sigue exigiendo es que no sea el horario.
        $this->datos(['horario' => 'De 10 a 19 h']);
        $r = $this->r('cuanto sale la consulta el sabado?');
        $this->assertStringStartsWith('precio', $r['motivo']);
        $this->assertStringNotContainsString('10 a 19', $r['texto']);
        $this->assertStringContainsString('$20.000', $r['texto']);
    }

    public function test_pregunta_frecuente_de_la_oficina(): void
    {
        $this->datos(['preguntas' => [['claves' => 'estacionamiento, estacionar', 'respuesta' => 'Hay estacionamiento en la calle.']]]);
        $this->assertSame('Hay estacionamiento en la calle.', $this->r('hay donde estacionar?')['texto']);
    }

    public function test_dos_preguntas_que_calzan_no_contestan_ninguna(): void
    {
        $this->datos(['preguntas' => [
            ['claves' => 'vacuna', 'respuesta' => 'A'],
            ['claves' => 'vacunas, antirrabica', 'respuesta' => 'B'],
        ]]);
        $this->assertNull($this->r('ponen la vacuna antirrabica?'));
    }

    public function test_una_pregunta_frecuente_no_le_gana_a_escalar(): void
    {
        $this->datos(['preguntas' => [['claves' => 'hora', 'respuesta' => 'Reserva en el portal.']]]);
        $this->assertNull($this->r('quiero cambiar mi hora'));
    }

    public function test_una_fila_vieja_sin_datos_recibe_la_direccion(): void
    {
        DB::table('ajustes')->insert(['id' => 1, 'data' => json_encode(['asistente' => ['tono' => 'breve']])]);
        $this->assertNotNull($this->r('cual es la direccion?'));
    }
}
