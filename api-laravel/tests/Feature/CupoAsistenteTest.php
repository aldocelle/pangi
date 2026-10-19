<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CatalogoController;
use App\Services\UsoAsistente;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * El cupo diario de la IA se fija desde Ajustes → Asistente IA y le gana a
 * `ASISTENTE_TOKENS_DIA`. Antes solo existía la variable, y subirlo exigía
 * entrar al servidor (el lab se quedó sin cupo a las 18:36 del 29-sep-2026).
 *
 * Controlador directo sobre SQLite en memoria, como WordingValidacionTest: la
 * autorización de admin la cubre el middleware y no es lo que se prueba acá.
 */
#[RunTestsInSeparateProcesses]
class CupoAsistenteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ajustes', 'uso_asistente'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('ajustes', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->text('data')->nullable();
        });
        Schema::create('uso_asistente', function (Blueprint $t) {
            $t->date('fecha')->primary(); $t->integer('llamadas'); $t->integer('tokens_entrada'); $t->integer('tokens_salida');
        });
        config([
            'emr.asistente_ia_activo' => true,
            'emr.anthropic_api_key' => 'clave-de-prueba',
            'emr.asistente_tokens_dia' => 300000,
        ]);
    }

    private function gastoHoy(int $tokens): void
    {
        DB::table('uso_asistente')->insert([
            'fecha' => CarbonImmutable::now('America/Santiago')->format('Y-m-d'),
            'llamadas' => 40, 'tokens_entrada' => $tokens - 50000, 'tokens_salida' => 50000,
        ]);
    }

    private function guardar(array $cuerpo)
    {
        $req = Request::create('/catalogo/asistente', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($cuerpo));

        try {
            return app(CatalogoController::class)->guardarAsistente($req);
        } catch (ValidationException $e) {
            // Lo mismo que vería el panel: el 422 con `detail` de bootstrap/app.php.
            return app(ExceptionHandler::class)->render($req, $e);
        }
    }

    private function guardadoEnLaFila(): array
    {
        $data = DB::table('ajustes')->where('id', 1)->value('data');

        return $data ? (json_decode($data, true)['asistente'] ?? []) : [];
    }

    public function test_sin_nada_guardado_manda_la_variable(): void
    {
        $this->assertSame(300000, UsoAsistente::tope());
        $this->gastoHoy(310000);
        $this->assertFalse(app(UsoAsistente::class)->puedeLlamar());
    }

    public function test_el_cupo_del_panel_le_gana_a_la_variable(): void
    {
        $this->gastoHoy(310000);
        $this->assertIsArray($this->guardar(['tokens_dia' => 1000000]));

        $this->assertSame(1000000, UsoAsistente::tope());
        $this->assertTrue(app(UsoAsistente::class)->puedeLlamar());
    }

    public function test_bajarlo_por_debajo_de_lo_gastado_frena_a_la_ia(): void
    {
        $this->gastoHoy(120000);
        $this->guardar(['tokens_dia' => 100000]);

        $this->assertFalse(app(UsoAsistente::class)->puedeLlamar());
    }

    public function test_fuera_de_rango_es_422_y_no_se_guarda(): void
    {
        // 0 sería «sin tope»: desde el panel no se puede abrir el gasto sin techo.
        foreach ([0, 9999, 10000001, 'mucho'] as $valor) {
            $r = $this->guardar(['tokens_dia' => $valor]);
            $this->assertInstanceOf(JsonResponse::class, $r, "valor {$valor}");
            $this->assertSame(422, $r->getStatusCode());
            $this->assertStringContainsString('10.000.000', $r->getData(true)['detail'][0]['msg']);
            $this->assertArrayNotHasKey('tokens_dia', $this->guardadoEnLaFila());
        }
    }

    public function test_texto_numerico_se_guarda_como_entero(): void
    {
        // Guardado como "1000000", `tope()` lo ignoraría y volvería a la
        // variable sin que el panel lo mostrara.
        $this->guardar(['tokens_dia' => '1000000']);
        $this->assertSame(1000000, $this->guardadoEnLaFila()['tokens_dia']);
        $this->assertSame(1000000, UsoAsistente::tope());
    }

    public function test_guardar_otro_campo_no_toca_el_cupo(): void
    {
        $this->guardar(['tokens_dia' => 2000000]);
        $this->guardar(['tono' => 'Trata de usted.', 'tokens_dia' => null]);

        $this->assertSame(2000000, UsoAsistente::tope());
    }

    public function test_la_pantalla_recibe_el_cupo_vigente_y_el_gasto_de_hoy(): void
    {
        $this->gastoHoy(250000);

        $r = app(CatalogoController::class)->verAsistente();
        $this->assertSame(300000, $r['tokens_dia']);
        $this->assertSame(['tokens' => 250000, 'llamadas' => 40], $r['gasto_hoy']);

        $r = $this->guardar(['tokens_dia' => 1000000]);
        $this->assertSame(1000000, $r['tokens_dia']);
        $this->assertSame(250000, $r['gasto_hoy']['tokens']);
    }

    public function test_el_gasto_no_viaja_con_cada_mensaje(): void
    {
        // `asistenteConfig()` la lee cada WhatsApp: el gasto es solo para la pantalla.
        $this->assertArrayNotHasKey('gasto_hoy', CatalogoController::asistenteConfig());
    }

    public function test_sin_tabla_de_gasto_la_pantalla_carga_igual(): void
    {
        Schema::drop('uso_asistente');
        $r = app(CatalogoController::class)->verAsistente();
        $this->assertNull($r['gasto_hoy']);
        $this->assertSame(300000, $r['tokens_dia']);
    }

    public function test_sin_poder_leer_el_tope_no_se_gasta(): void
    {
        Schema::drop('ajustes');
        $this->assertFalse(app(UsoAsistente::class)->puedeLlamar());
    }
}
