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
 * `servicios.coordinar_whatsapp` (30-sep-2026): ecografías y radiografías
 * «tienen que confirmar disponibilidad por WhatsApp, no tener disponible la
 * toma de horas». Es de la CONSULTA y no del especialista: quien hace
 * ecografías puede atender otras consultas que sí se reservan en línea.
 */
#[RunTestsInSeparateProcesses]
class ServicioCoordinarWhatsappTest extends TestCase
{
    private const ECO = '11111111-1111-4111-8111-111111111111';

    private const CG = '22222222-2222-4222-8222-222222222222';

    private const DRA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['especialistas', 'servicios', 'servicio_especialista', 'disponibilidad_especialistas',
            'horarios', 'horario_bloques', 'logs_actividad', 'citas'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('citas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('especialista_id')->nullable(); $t->string('servicio_id')->nullable();
            $t->string('fecha_hora'); $t->integer('duracion_min')->nullable(); $t->string('estado');
            $t->integer('monto_abono_clp')->nullable(); $t->string('payment_status')->nullable();
            $t->string('pago_modalidad_requerida')->nullable(); $t->string('created_at')->nullable();
        });
        Schema::create('especialistas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->string('especialidad')->nullable();
            $t->string('emoji')->nullable(); $t->string('slug')->nullable(); $t->text('descripcion')->nullable();
            $t->string('foto')->nullable(); $t->string('zona_horaria')->nullable(); $t->boolean('activo')->default(true);
            $t->integer('orden')->default(0); $t->string('disponibilidad_modo')->nullable();
            $t->text('calendarios_conflicto')->nullable(); $t->boolean('solo_consulta_whatsapp')->default(false);
            $t->boolean('reservable')->default(true); $t->integer('precio_clp')->nullable();
            $t->string('email')->nullable(); $t->string('telefono')->nullable(); $t->boolean('recibe_avisos')->default(false);
            $t->boolean('publico')->default(true);
        });
        $this->tablaServicios(true);
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
        $this->datos(true);
    }

    private function tablaServicios(bool $conColumna): void
    {
        Schema::dropIfExists('servicios');
        Schema::create('servicios', function (Blueprint $t) use ($conColumna) {
            $t->string('id')->primary(); $t->string('nombre'); $t->text('descripcion')->nullable();
            $t->string('emoji')->nullable(); $t->string('imagen')->nullable(); $t->boolean('activo')->default(true);
            $t->integer('orden')->default(0); $t->integer('duracion_min')->default(30);
            $t->integer('duracion_visible_min')->nullable(); $t->integer('precio_clp')->nullable();
            $t->integer('precio_sabado_clp')->nullable(); $t->string('precio_detalle')->nullable();
            $t->string('modalidad_pago')->default('nada'); $t->boolean('tarifa_por_especialista')->default(false);
            $t->integer('abono_fijo_clp')->nullable(); $t->boolean('requiere_orden_examen')->default(false);
            $t->string('grupo')->nullable();
            if ($conColumna) {
                $t->boolean('coordinar_whatsapp')->default(false);
            }
        });
    }

    private function datos(bool $conColumna): void
    {
        DB::table('servicios')->insert(['id' => self::ECO, 'nombre' => 'Ecografía'] + ($conColumna ? ['coordinar_whatsapp' => true] : []));
        DB::table('servicios')->insert(['id' => self::CG, 'nombre' => 'Consulta General']);
        DB::table('especialistas')->insertOrIgnore(['id' => self::DRA, 'nombre' => 'Dra. Rivas', 'slug' => 'rivas']);
    }

    private function editar(string $id, array $cuerpo)
    {
        $req = Request::create("/catalogo/servicios/{$id}", 'PUT', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode($cuerpo));
        $req->attributes->set('usuario', (object) ['id' => 'admin', 'nombre' => 'Admin', 'rol' => 'admin', 'email' => 'a@x.cl']);

        return app(CatalogoController::class)->editarServicio($req, $id);
    }

    public function test_el_catalogo_publico_la_marca(): void
    {
        $svcs = collect($this->getJson('/catalogo/servicios')->assertOk()->json())->keyBy('id');
        $this->assertTrue($svcs[self::ECO]['coordinar_whatsapp']);
        $this->assertFalse($svcs[self::CG]['coordinar_whatsapp']);
    }

    public function test_en_el_paso_2_sale_con_whatsapp_solo_en_esa_consulta(): void
    {
        // La misma profesional: con Ecografía, botón de WhatsApp; con la
        // Consulta General, su calendario de siempre.
        $eco = $this->getJson('/catalogo/especialistas?servicio_id=' . self::ECO)->assertOk()->json();
        $this->assertTrue($eco[0]['solo_consulta_whatsapp']);
        $cg = $this->getJson('/catalogo/especialistas?servicio_id=' . self::CG)->assertOk()->json();
        $this->assertFalse($cg[0]['solo_consulta_whatsapp']);
    }

    public function test_no_da_horas_aunque_se_pidan_a_mano(): void
    {
        $this->getJson('/citas/slots?servicio_id=' . self::ECO . '&especialista_id=' . self::DRA)
            ->assertStatus(409)->assertJsonPath('detail', 'Ecografía se coordina directamente con la clínica.');
        $this->getJson('/citas/slots?servicio_id=' . self::CG . '&especialista_id=' . self::DRA)->assertOk();
    }

    public function test_el_panel_la_marca_y_un_panel_viejo_no_la_desmarca(): void
    {
        $this->editar(self::CG, ['nombre' => 'Consulta General', 'coordinar_whatsapp' => true]);
        $this->assertSame(1, (int) DB::table('servicios')->where('id', self::CG)->value('coordinar_whatsapp'));

        // Un panel que no conoce el campo guarda sin mandarlo.
        $this->editar(self::ECO, ['nombre' => 'Ecografía abdominal']);
        $this->assertSame(1, (int) DB::table('servicios')->where('id', self::ECO)->value('coordinar_whatsapp'));
    }

    public function test_sin_la_migracion_todo_sigue_reservable_y_guardar_no_revienta(): void
    {
        $this->tablaServicios(false);
        $this->datos(false);

        $svcs = collect($this->getJson('/catalogo/servicios')->assertOk()->json())->keyBy('id');
        $this->assertFalse($svcs[self::ECO]['coordinar_whatsapp']);
        $this->getJson('/citas/slots?servicio_id=' . self::ECO . '&especialista_id=' . self::DRA)->assertOk();
        $this->editar(self::ECO, ['nombre' => 'Ecografía', 'coordinar_whatsapp' => true]);
        $this->assertSame('Ecografía', DB::table('servicios')->where('id', self::ECO)->value('nombre'));
    }
}
