<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GoogleController;
use App\Services\CitasEmail;
use App\Services\GoogleCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * Lo que hace la sincronización con Google cuando la cuenta o el calendario
 * cambian — el caso del traspaso a la cuenta de la clínica.
 *
 * Google contesta 404 igual a un evento borrado que a uno de un calendario que
 * la cuenta no ve. Sin estas guardas, cambiar de cuenta cancelaba todas las
 * citas futuras y le escribía «tu cita fue cancelada» a cada tutor.
 */
#[RunTestsInSeparateProcesses]
class GoogleSincronizarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['citas', 'usuarios', 'mascotas', 'ajustes'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('citas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('estado'); $t->string('fecha_hora');
            $t->string('google_event_id')->nullable(); $t->string('google_calendar_id')->nullable();
            $t->string('usuario_dueno_id');
            $t->string('mascota_id'); $t->string('especialista_id')->nullable();
            $t->string('servicio')->nullable();
        });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre');
        });
        Schema::create('mascotas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre');
        });
        Schema::create('ajustes', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->string('google_account_id')->nullable();
            $t->string('calendario_reservas')->nullable(); $t->text('data')->nullable();
        });
        DB::table('usuarios')->insert(['id' => 'u', 'email' => 'tutor@gmail.com', 'nombre' => 'T']);
        DB::table('mascotas')->insert(['id' => 'm', 'nombre' => 'Pelusa']);

        $manana = CarbonImmutable::now()->addDay()->format('Y-m-d 10:00:00');
        for ($i = 1; $i <= 6; $i++) {
            DB::table('citas')->insert([
                'id' => "c{$i}", 'estado' => 'Agendada', 'fecha_hora' => $manana,
                'google_event_id' => "ev{$i}", 'usuario_dueno_id' => 'u', 'mascota_id' => 'm',
            ]);
        }
    }

    /** @param callable(string):?array $evento  qué contesta Google por cada id */
    private function sincronizar(bool $accesible, callable $evento, CitasEmail $emails): void
    {
        $g = Mockery::mock(GoogleCalendar::class);
        $g->shouldReceive('cuentaClinica')->andReturn((object) ['id' => 'cta']);
        $g->shouldReceive('calendarioClinica')->andReturn([(object) ['id' => 'cta'], 'cal']);
        $g->shouldReceive('calendarioAccesible')->andReturn($accesible);
        $g->shouldReceive('obtenerEvento')->andReturnUsing(fn ($c, $cal, $id) => $evento($id));
        $this->app->instance(GoogleCalendar::class, $g);
        $this->app->instance(CitasEmail::class, $emails);

        $this->artisan('google:sincronizar')->assertExitCode(0);
    }

    private function canceladas(): int
    {
        return DB::table('citas')->where('estado', 'Cancelada')->count();
    }

    public function test_cuenta_sin_acceso_al_calendario_no_cancela_ni_escribe(): void
    {
        $emails = Mockery::mock(CitasEmail::class);
        $emails->shouldNotReceive('notificarCambioEstado');

        $this->sincronizar(false, fn () => ['existe' => false], $emails);

        $this->assertSame(0, $this->canceladas());
    }

    public function test_si_desaparecen_casi_todos_los_eventos_se_detiene(): void
    {
        $emails = Mockery::mock(CitasEmail::class);
        $emails->shouldNotReceive('notificarCambioEstado');

        $this->sincronizar(true, fn () => ['existe' => false], $emails);

        $this->assertSame(0, $this->canceladas());
    }

    public function test_un_evento_borrado_si_cancela_y_avisa_al_tutor(): void
    {
        $emails = Mockery::mock(CitasEmail::class);
        $emails->shouldReceive('notificarCambioEstado')->once();

        $this->sincronizar(true, fn ($id) => $id === 'ev3'
            ? ['existe' => false]
            : ['existe' => true, 'inicio' => null], $emails);

        $this->assertSame(['c3'], DB::table('citas')->where('estado', 'Cancelada')->pluck('id')->all());
    }

    public function test_elegir_otro_calendario_suelta_los_eventos_del_anterior(): void
    {
        DB::table('ajustes')->insert(['id' => 1, 'google_account_id' => 'cta',
            'calendario_reservas' => 'viejo@gmail.com']);

        app(GoogleController::class)->elegirCalendario(
            Request::create('/google/calendario-reservas?calendario_id=clinica@gmail.com', 'PUT'));

        $this->assertSame(0, DB::table('citas')->whereNotNull('google_event_id')->count());
        $this->assertSame(0, $this->canceladas());
    }

    public function test_volver_a_elegir_el_mismo_calendario_no_suelta_nada(): void
    {
        DB::table('ajustes')->insert(['id' => 1, 'google_account_id' => 'cta',
            'calendario_reservas' => 'viejo@gmail.com']);

        app(GoogleController::class)->elegirCalendario(
            Request::create('/google/calendario-reservas?calendario_id=viejo@gmail.com', 'PUT'));

        $this->assertSame(6, DB::table('citas')->whereNotNull('google_event_id')->count());
    }
}
