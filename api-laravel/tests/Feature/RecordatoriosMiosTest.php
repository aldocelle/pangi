<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * GET /recordatorios/mios es lo que el portal del cliente pide para mostrar los
 * cuidados de sus mascotas. Comparte la salida con el listado del panel, y por
 * eso le llegaban al dueño las `notas` internas del equipo.
 */
#[RunTestsInSeparateProcesses]
class RecordatoriosMiosTest extends TestCase
{
    private const SECRETO = 'secreto-de-test-con-largo-suficiente-para-hs256';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => self::SECRETO]);

        foreach (['recordatorios', 'mascotas', 'usuarios'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre')->nullable();
            $t->string('telefono')->nullable(); $t->string('rol'); $t->boolean('es_activo')->default(true);
            $t->text('permisos')->nullable();
        });
        Schema::create('mascotas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id'); $t->string('nombre');
            $t->string('especie')->nullable(); $t->string('estado')->nullable();
        });
        Schema::create('recordatorios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('mascota_id'); $t->string('usuario_dueno_id')->nullable();
            $t->string('titulo'); $t->string('categoria')->nullable(); $t->text('mensaje')->nullable();
            $t->date('fecha_programada'); $t->string('estado'); $t->timestamp('enviado_at')->nullable();
            $t->string('canal')->nullable(); $t->text('notas')->nullable(); $t->timestamp('created_at')->nullable();
        });

        DB::table('usuarios')->insert(['id' => 'ana', 'email' => 'ana@x.cl', 'nombre' => 'Ana', 'rol' => 'dueño']);
        DB::table('mascotas')->insert(['id' => 'luna', 'usuario_id' => 'ana', 'nombre' => 'Luna']);
        DB::table('recordatorios')->insert([
            'id' => 'r1', 'mascota_id' => 'luna', 'usuario_dueno_id' => 'ana',
            'titulo' => 'Vacuna antirrábica', 'mensaje' => 'Refuerzo anual.',
            'fecha_programada' => '2026-10-01', 'estado' => 'pendiente',
            'notas' => 'Llamé dos veces, no contesta.',
        ]);
    }

    private function comoAna()
    {
        $token = JWT::encode(
            ['sub' => 'ana@x.cl', 'rol' => 'dueño', 'user_id' => 'ana', 'exp' => time() + 3600],
            self::SECRETO, 'HS256'
        );

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_al_dueno_no_le_llegan_las_notas_del_equipo(): void
    {
        $r = $this->comoAna()->getJson('/recordatorios/mios')->assertOk();

        $this->assertCount(1, $r->json());
        $this->assertArrayNotHasKey('notas', $r->json()[0]);
        $this->assertStringNotContainsString('no contesta', $r->getContent());
    }

    public function test_lo_que_el_portal_si_usa_sigue_llegando(): void
    {
        $fila = $this->comoAna()->getJson('/recordatorios/mios')->assertOk()->json()[0];

        foreach (['id', 'mascota_id', 'mascota_nombre', 'titulo', 'categoria', 'mensaje',
                  'fecha_programada', 'vencido', 'dias_restantes'] as $campo) {
            $this->assertArrayHasKey($campo, $fila, "falta {$campo}, que lee RecordatoriosView");
        }
        $this->assertSame('Refuerzo anual.', $fila['mensaje']);
    }
}
