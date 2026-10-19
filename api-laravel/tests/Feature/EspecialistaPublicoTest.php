<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CatalogoController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * `especialistas.publico` (sep-2026): quien no es público no sale en lo que ve
 * cualquiera —el equipo del sitio, el paso 2 del portal, su página `/b/{slug}`—
 * y el panel sí lo ve y lo edita.
 *
 * El caso que lo motivó: el especialista con quien el asistente de WhatsApp
 * coordina (uno de prueba, el Dr. Tigre) no tenía que aparecer en el sitio, y
 * ni `activo` (lo borra también del asistente) ni `reservable` (lo deja en el
 * equipo del sitio) servían.
 */
#[RunTestsInSeparateProcesses]
class EspecialistaPublicoTest extends TestCase
{
    private const SVC = '11111111-1111-4111-8111-111111111111';

    private const VISIBLE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const OCULTO = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['especialistas', 'servicios', 'servicio_especialista', 'disponibilidad_especialistas',
            'horarios', 'horario_bloques', 'logs_actividad'] as $t) {
            Schema::dropIfExists($t);
        }
        $this->tablaEspecialistas(true);
        Schema::create('servicios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->string('modalidad_pago')->default('nada'); $t->integer('orden')->default(0);
        });
        Schema::create('servicio_especialista', function (Blueprint $t) {
            $t->string('servicio_id'); $t->string('especialista_id');
        });
        Schema::create('disponibilidad_especialistas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('especialista_id'); $t->integer('dia_semana');
            $t->string('hora_inicio'); $t->string('hora_fin');
        });
        Schema::create('horarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('especialista_id'); $t->string('nombre');
            $t->integer('orden')->default(0); $t->boolean('todos_servicios')->default(true);
            $t->text('servicio_ids')->nullable(); $t->string('valido_desde')->nullable();
            $t->string('valido_hasta')->nullable(); $t->string('semana_paridad')->nullable();
        });
        Schema::create('horario_bloques', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('horario_id'); $t->integer('dia_semana');
            $t->string('hora_inicio'); $t->string('hora_fin');
        });
        Schema::create('logs_actividad', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id')->nullable(); $t->string('usuario_nombre');
            $t->string('usuario_rol')->nullable(); $t->string('accion'); $t->string('area');
            $t->string('entidad')->nullable(); $t->string('entidad_id')->nullable(); $t->text('detalle')->nullable();
            $t->string('created_at');
        });

        DB::table('servicios')->insert(['id' => self::SVC, 'nombre' => 'Radiología']);
        DB::table('especialistas')->insert([
            ['id' => self::VISIBLE, 'nombre' => 'Dr. Fuentes', 'slug' => 'fuentes', 'publico' => true],
            ['id' => self::OCULTO, 'nombre' => 'Dr. Daniel Tigre', 'slug' => 'tigre', 'publico' => false],
        ]);
        DB::table('servicio_especialista')->insert([
            ['servicio_id' => self::SVC, 'especialista_id' => self::VISIBLE],
            ['servicio_id' => self::SVC, 'especialista_id' => self::OCULTO],
        ]);
    }

    private function tablaEspecialistas(bool $conPublico): void
    {
        Schema::dropIfExists('especialistas');
        Schema::create('especialistas', function (Blueprint $t) use ($conPublico) {
            $t->string('id')->primary(); $t->string('nombre'); $t->string('especialidad')->nullable();
            $t->string('emoji')->nullable(); $t->string('slug')->nullable(); $t->text('descripcion')->nullable();
            $t->string('foto')->nullable(); $t->string('zona_horaria')->nullable(); $t->boolean('activo')->default(true);
            $t->integer('orden')->default(0); $t->string('disponibilidad_modo')->nullable();
            $t->text('calendarios_conflicto')->nullable(); $t->boolean('solo_consulta_whatsapp')->default(true);
            $t->boolean('reservable')->default(false); $t->integer('precio_clp')->nullable();
            $t->string('email')->nullable(); $t->string('telefono')->nullable(); $t->boolean('recibe_avisos')->default(false);
            if ($conPublico) {
                $t->boolean('publico')->default(true);
            }
        });
    }

    private function editar(string $id, array $cuerpo)
    {
        $req = Request::create("/catalogo/especialistas/{$id}", 'PUT', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode($cuerpo));
        $req->attributes->set('usuario', (object) ['id' => 'admin', 'nombre' => 'Admin', 'rol' => 'admin', 'email' => 'a@x.cl']);

        return app(CatalogoController::class)->editarEspecialista($req, $id);
    }

    public function test_el_oculto_no_sale_en_el_equipo_del_sitio_ni_en_el_paso_2_del_portal(): void
    {
        $this->assertSame(['Dr. Fuentes'], array_column($this->getJson('/catalogo/especialistas')->assertOk()->json(), 'nombre'));
        $this->assertSame(['Dr. Fuentes'], array_column(
            $this->getJson('/catalogo/especialistas?servicio_id=' . self::SVC)->assertOk()->json(), 'nombre'));
    }

    public function test_su_pagina_b_slug_no_existe(): void
    {
        $this->getJson('/catalogo/especialistas/slug/tigre')->assertNotFound();
        $this->getJson('/catalogo/especialistas/slug/fuentes')->assertOk();
    }

    public function test_el_panel_lo_ve_y_puede_volverlo_publico(): void
    {
        $todos = collect(app(CatalogoController::class)->especialistasTodos())->keyBy('id');
        $this->assertFalse($todos[self::OCULTO]['publico']);
        $this->assertTrue($todos[self::VISIBLE]['publico']);

        $this->editar(self::OCULTO, ['nombre' => 'Dr. Daniel Tigre', 'publico' => true]);

        $this->assertCount(2, $this->getJson('/catalogo/especialistas')->json());
    }

    public function test_sin_la_migracion_el_sitio_no_se_cae_y_guardar_no_revienta(): void
    {
        // La base todavía sin el ALTER: todos se leen como públicos, que es lo
        // que eran, y un panel nuevo que manda `publico` no convierte cada
        // guardado en un 500.
        $this->tablaEspecialistas(false);
        DB::table('especialistas')->insert(['id' => self::VISIBLE, 'nombre' => 'Dr. Fuentes', 'slug' => 'fuentes']);

        $this->getJson('/catalogo/especialistas')->assertOk()->assertJsonCount(1);
        $this->editar(self::VISIBLE, ['nombre' => 'Dr. Fuentes Soto', 'publico' => false]);

        $this->assertSame('Dr. Fuentes Soto', DB::table('especialistas')->value('nombre'));
    }
}
