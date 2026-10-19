<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CatalogoController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * PUT /catalogo/ajustes: el port rechaza en `wording` lo mismo que `WordingIn`
 * en Python. Antes aceptaba y GUARDABA una clave inventada y un texto sobre el
 * máximo (medido contra los dos backends, sep-2026: Py=422, Lv=200).
 *
 * Se llama al controlador directo —la autorización de admin la cubre el
 * middleware y no es lo que se prueba acá—, sobre SQLite en memoria. Nunca
 * contra la MariaDB de `.env` (§13.4). Procesos separados por lo mismo que
 * RespuestaDirectaDatosTest.
 */
#[RunTestsInSeparateProcesses]
class WordingValidacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('ajustes');
        Schema::create('ajustes', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->text('data')->nullable();
        });
        DB::table('ajustes')->insert(['id' => 1, 'data' => json_encode(['wording' => ['consultas' => 'Tipo de consulta']])]);
    }

    private function guardar(array $cuerpo)
    {
        $req = Request::create('/catalogo/ajustes', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($cuerpo));

        return app(CatalogoController::class)->guardarAjustes($req);
    }

    private function fila(): array
    {
        return json_decode(DB::table('ajustes')->where('id', 1)->value('data'), true);
    }

    public function test_clave_inventada_dentro_de_wording_es_422_y_no_se_guarda(): void
    {
        $r = $this->guardar(['wording' => ['inventada_xyz' => 'hola']]);
        $this->assertInstanceOf(JsonResponse::class, $r);
        $this->assertSame(422, $r->getStatusCode());
        $this->assertSame(['body', 'wording', 'inventada_xyz'], $r->getData(true)['detail'][0]['loc']);
        $this->assertArrayNotHasKey('inventada_xyz', $this->fila()['wording']);
    }

    public function test_texto_sobre_el_maximo_es_422_y_no_se_guarda(): void
    {
        $r = $this->guardar(['wording' => ['pago_en_atencion' => str_repeat('a', 201)]]);
        $this->assertSame(422, $r->getStatusCode());
        $this->assertSame('string_too_long', $r->getData(true)['detail'][0]['type']);
        $this->assertArrayNotHasKey('pago_en_atencion', $this->fila()['wording']);
    }

    public function test_justo_en_el_maximo_se_guarda(): void
    {
        $r = $this->guardar(['wording' => ['pago_en_atencion' => str_repeat('a', 200)]]);
        $this->assertIsArray($r);
        $this->assertSame(200, mb_strlen($this->fila()['wording']['pago_en_atencion']));
    }

    public function test_null_adentro_no_borra_lo_guardado(): void
    {
        $this->guardar(['wording' => ['consultas' => null, 'review' => 'Revisa']]);
        $w = $this->fila()['wording'];
        $this->assertSame('Tipo de consulta', $w['consultas']);
        $this->assertSame('Revisa', $w['review']);
    }

    public function test_los_maximos_cubren_exactamente_las_claves_de_wording(): void
    {
        // Si alguien agrega una frase a AJUSTES_DEFAULT y no su máximo, el PUT
        // la rechazaría como «inventada»: la clínica no podría guardarla nunca.
        $ref = new \ReflectionClass(CatalogoController::class);
        $defaults = array_keys($ref->getConstant('AJUSTES_DEFAULT')['wording']);
        $maximos = array_keys($ref->getConstant('WORDING_MAX'));
        sort($defaults);
        sort($maximos);
        $this->assertSame($defaults, $maximos);
    }
}
