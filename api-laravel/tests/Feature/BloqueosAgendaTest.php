<?php

namespace Tests\Feature;

use App\Services\Esquema;
use App\Services\Jwt;
use App\Services\SlotsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * Bloqueos de la agenda (oct-2026).
 *
 * Lo que se fija:
 *   · un bloqueo saca esas horas del portal de reservas, sea del especialista o
 *     de toda la clínica, y uno anulado deja de contar;
 *   · crear uno avisa cuántas citas quedaron adentro, pero no las cancela;
 *   · se anula una sola vez, y la fila queda;
 *   · solo quien tiene «agenda» los toca.
 */
#[RunTestsInSeparateProcesses]
class BloqueosAgendaTest extends TestCase
{
    private const SECRETO = 'secreto-de-test-con-largo-suficiente-para-hs256';

    /** Lunes: el especialista atiende de 09:00 a 11:30 (último inicio). */
    private const LUNES = '2030-01-07';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => self::SECRETO]);
        Esquema::olvidar();

        foreach (['usuarios', 'especialistas', 'citas', 'horarios', 'horario_bloques',
                  'disponibilidad_especialistas', 'ajustes', 'google_accounts', 'logs_actividad',
                  'bloqueos_agenda'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre')->nullable();
            $t->string('telefono')->nullable(); $t->string('rol'); $t->boolean('es_activo')->default(true);
            $t->text('permisos')->nullable(); $t->integer('sesion_version')->default(0);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('especialistas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->integer('orden')->nullable(); $t->string('zona_horaria')->nullable();
            $t->string('google_calendar_id')->nullable(); $t->string('disponibilidad_modo')->nullable();
            $t->text('calendarios_conflicto')->nullable();
        });
        Schema::create('citas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('estado'); $t->string('fecha_hora');
            $t->string('especialista_id')->nullable(); $t->integer('duracion_min')->nullable();
            $t->integer('monto_abono_clp')->nullable(); $t->string('payment_status')->nullable();
            $t->string('created_at')->nullable(); $t->string('pago_enlace_at')->nullable();
        });
        Schema::create('horarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('especialista_id'); $t->string('nombre')->nullable();
            $t->integer('orden')->nullable(); $t->boolean('todos_servicios')->nullable();
            $t->text('servicio_ids')->nullable(); $t->string('valido_desde')->nullable();
            $t->string('valido_hasta')->nullable(); $t->string('semana_paridad')->nullable();
        });
        Schema::create('horario_bloques', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('horario_id'); $t->integer('dia_semana');
            $t->string('hora_inicio'); $t->string('hora_fin');
        });
        Schema::create('disponibilidad_especialistas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('especialista_id'); $t->integer('dia_semana');
            $t->string('hora_inicio'); $t->string('hora_fin');
        });
        Schema::create('ajustes', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->text('data')->nullable();
            $t->string('google_account_id')->nullable(); $t->string('calendario_reservas')->nullable();
        });
        Schema::create('google_accounts', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email')->nullable();
        });
        Schema::create('logs_actividad', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id')->nullable(); $t->string('usuario_nombre')->nullable();
            $t->string('usuario_rol')->nullable(); $t->string('accion')->nullable(); $t->string('area')->nullable();
            $t->string('entidad')->nullable(); $t->string('entidad_id')->nullable(); $t->text('detalle')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('bloqueos_agenda', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('especialista_id')->nullable();
            $t->string('inicio'); $t->string('fin'); $t->string('motivo');
            $t->string('creado_por_id')->nullable(); $t->string('created_at')->nullable();
            $t->string('anulado_at')->nullable(); $t->string('anulado_por_id')->nullable();
        });

        DB::table('usuarios')->insert([
            ['id' => 'rec', 'email' => 'rec@x.cl', 'nombre' => 'Recepción', 'rol' => 'veterinario',
             'permisos' => json_encode(['agenda', 'reservas'])],
            ['id' => 'caj', 'email' => 'caja@x.cl', 'nombre' => 'Solo caja', 'rol' => 'veterinario',
             'permisos' => json_encode(['caja'])],
        ]);
        DB::table('especialistas')->insert([
            ['id' => 'e1', 'nombre' => 'Dra. Rojas', 'activo' => 1, 'orden' => 1],
            ['id' => 'e2', 'nombre' => 'Dr. Soto', 'activo' => 1, 'orden' => 2],
        ]);
        // Esquema legado (sin Horarios): lunes = 0.
        DB::table('disponibilidad_especialistas')->insert([
            ['id' => 'd1', 'especialista_id' => 'e1', 'dia_semana' => 0, 'hora_inicio' => '09:00', 'hora_fin' => '11:30'],
        ]);
        DB::table('ajustes')->insert(['id' => 1, 'data' => '{}']);
    }

    private function como(string $id): array
    {
        $u = DB::table('usuarios')->where('id', $id)->first();
        $token = app(Jwt::class)->firmar(['sub' => $u->email, 'rol' => $u->rol, 'user_id' => $id]);

        return ['Authorization' => "Bearer {$token}"];
    }

    /** Las horas que ofrece el portal ese lunes a una consulta de 30 min. */
    private function horasDelLunes(): array
    {
        $esp = DB::table('especialistas')->where('id', 'e1')->first();
        $slots = app(SlotsService::class)->para((object) ['id' => 's1', 'duracion_min' => 30], $esp,
            CarbonImmutable::parse(self::LUNES)->subDay(), 2);

        return $slots[self::LUNES] ?? [];
    }

    private function bloqueo(?string $esp, string $ini, string $fin, ?string $anulado = null): void
    {
        DB::table('bloqueos_agenda')->insert([
            'id' => uniqid('b', true), 'especialista_id' => $esp, 'motivo' => 'Trámite',
            'inicio' => self::LUNES . " {$ini}:00", 'fin' => self::LUNES . " {$fin}:00", 'anulado_at' => $anulado,
        ]);
    }

    public function test_un_bloqueo_del_especialista_saca_esas_horas_del_portal(): void
    {
        $this->assertSame(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30'], $this->horasDelLunes());

        $this->bloqueo('e1', '10:00', '11:00');

        $this->assertSame(['09:00', '09:30', '11:00', '11:30'], $this->horasDelLunes());
    }

    public function test_el_de_toda_la_clinica_bloquea_y_el_de_otro_especialista_no(): void
    {
        $this->bloqueo(null, '09:00', '10:00');
        $this->bloqueo('e2', '10:00', '12:00');

        $this->assertSame(['10:00', '10:30', '11:00', '11:30'], $this->horasDelLunes());
    }

    public function test_uno_anulado_deja_de_contar(): void
    {
        $this->bloqueo('e1', '09:00', '12:00', '2029-12-31 10:00:00');

        $this->assertCount(6, $this->horasDelLunes());
    }

    public function test_crear_avisa_las_citas_que_quedan_adentro_sin_cancelarlas(): void
    {
        DB::table('citas')->insert([
            ['id' => 'c1', 'estado' => 'Confirmada', 'fecha_hora' => self::LUNES . ' 10:30:00',
             'especialista_id' => 'e1', 'duracion_min' => 30],
            // Empieza antes y termina adentro: también choca.
            ['id' => 'c2', 'estado' => 'Agendada', 'fecha_hora' => self::LUNES . ' 09:30:00',
             'especialista_id' => 'e1', 'duracion_min' => 60],
            ['id' => 'c3', 'estado' => 'Cancelada', 'fecha_hora' => self::LUNES . ' 10:30:00',
             'especialista_id' => 'e1', 'duracion_min' => 30],
            ['id' => 'c4', 'estado' => 'Confirmada', 'fecha_hora' => self::LUNES . ' 10:30:00',
             'especialista_id' => 'e2', 'duracion_min' => 30],
        ]);

        $r = $this->postJson('/agenda/bloqueos', [
            'especialista_id' => 'e1', 'inicio' => self::LUNES . 'T10:00', 'fin' => self::LUNES . 'T11:00',
            'motivo' => 'Salida por trámites',
        ], $this->como('rec'));

        $r->assertCreated()
            ->assertJsonPath('citas_en_conflicto', 2)
            ->assertJsonPath('motivo', 'Salida por trámites')
            ->assertJsonPath('creado_por', 'Recepción')
            ->assertJsonPath('inicio', self::LUNES . 'T10:00:00');
        $this->assertSame('Confirmada', DB::table('citas')->where('id', 'c1')->value('estado'));
        $this->assertSame(1, DB::table('logs_actividad')->where('entidad', 'bloqueo_agenda')->count());
    }

    public function test_rechaza_un_bloqueo_que_termina_antes_de_empezar(): void
    {
        $this->postJson('/agenda/bloqueos', [
            'especialista_id' => null, 'inicio' => self::LUNES . 'T11:00', 'fin' => self::LUNES . 'T10:00',
            'motivo' => 'Al revés',
        ], $this->como('rec'))->assertStatus(400);

        $this->assertSame(0, DB::table('bloqueos_agenda')->count());
    }

    public function test_sin_permiso_de_agenda_no_se_bloquea(): void
    {
        $this->postJson('/agenda/bloqueos', [
            'especialista_id' => null, 'inicio' => self::LUNES . 'T10:00', 'fin' => self::LUNES . 'T11:00',
            'motivo' => 'No debería',
        ], $this->como('caj'))->assertStatus(403);
    }

    public function test_anular_deja_la_fila_y_no_se_anula_dos_veces(): void
    {
        $this->bloqueo('e1', '10:00', '11:00');
        $id = DB::table('bloqueos_agenda')->value('id');

        $this->deleteJson("/agenda/bloqueos/{$id}", [], $this->como('rec'))->assertNoContent();
        $this->deleteJson("/agenda/bloqueos/{$id}", [], $this->como('rec'))->assertStatus(409);

        $fila = DB::table('bloqueos_agenda')->where('id', $id)->first();
        $this->assertNotNull($fila->anulado_at);
        $this->assertSame('rec', $fila->anulado_por_id);
        $this->assertCount(6, $this->horasDelLunes());
    }

    public function test_disponibilidad_trae_el_horario_y_solo_los_bloqueos_vigentes(): void
    {
        $this->bloqueo('e1', '10:00', '11:00');
        $this->bloqueo(null, '09:00', '09:30', '2029-12-31 10:00:00');

        $r = $this->getJson('/agenda/disponibilidad?fecha=' . self::LUNES, $this->como('rec'))->assertOk();

        $r->assertJsonPath('ventanas.e1.' . self::LUNES . '.0', ['desde' => '09:00', 'ultimo_inicio' => '11:30']);
        $this->assertArrayNotHasKey('e2', $r->json('ventanas'));
        $this->assertCount(1, $r->json('bloqueos'));
        $r->assertJsonPath('bloqueos.0.especialista_id', 'e1');
    }
}
