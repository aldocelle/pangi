<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GoogleController;
use App\Services\CitasEmail;
use App\Services\GoogleCalendar;
use App\Services\GoogleOAuth;
use App\Services\SlotsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * Un calendario de Google por especialista (oct-2026).
 *
 * Lo que se fija, y por qué cada cosa:
 *   · la reserva nueva cae en el calendario del especialista y la cita anota
 *     cuál es — sin eso, una cita vieja no se puede volver a encontrar;
 *   · mover y borrar usan el calendario ANOTADO, no el de hoy: buscar en el
 *     otro daría 404 y la sincronización cancelaría la cita con correo;
 *   · la sincronización frena por calendario, no en promedio;
 *   · cambiar el calendario general no suelta las citas de los propios;
 *   · en el calendario propio todo evento bloquea, sin leer nombres.
 */
#[RunTestsInSeparateProcesses]
class CalendarioEspecialistaTest extends TestCase
{
    private const GENERAL = 'clinica@gmail.com';
    private const PROPIO = 'propio@group.calendar.google.com';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['citas', 'usuarios', 'mascotas', 'ajustes', 'google_accounts', 'especialistas',
                  'logs_actividad'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('citas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('estado'); $t->string('fecha_hora');
            $t->string('google_event_id')->nullable(); $t->string('google_calendar_id')->nullable();
            $t->string('usuario_dueno_id'); $t->string('mascota_id');
            $t->string('especialista_id')->nullable(); $t->string('servicio')->nullable();
            $t->integer('duracion_min')->nullable(); $t->text('respuestas')->nullable();
            $t->text('notas_pre_cita')->nullable(); $t->integer('monto_abono_clp')->nullable();
            $t->string('payment_status')->nullable(); $t->string('pago_modalidad_requerida')->nullable();
            $t->string('created_at')->nullable();
        });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre');
            $t->string('telefono')->nullable();
        });
        Schema::create('mascotas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->string('especie')->nullable();
        });
        Schema::create('ajustes', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->string('google_account_id')->nullable();
            $t->string('calendario_reservas')->nullable(); $t->text('data')->nullable();
        });
        Schema::create('google_accounts', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email');
        });
        Schema::create('especialistas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->string('zona_horaria')->nullable(); $t->string('email')->nullable();
            $t->string('google_calendar_id')->nullable();
            $t->string('disponibilidad_modo')->nullable(); $t->text('calendarios_conflicto')->nullable();
        });
        Schema::create('logs_actividad', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id')->nullable();
            $t->string('usuario_nombre')->nullable(); $t->string('usuario_rol')->nullable();
            $t->string('accion'); $t->string('area');
            $t->string('entidad')->nullable(); $t->string('entidad_id')->nullable();
            $t->text('detalle')->nullable(); $t->string('created_at')->nullable();
        });

        DB::table('google_accounts')->insert(['id' => 'cta', 'email' => 'clinica@gmail.com']);
        DB::table('ajustes')->insert(['id' => 1, 'google_account_id' => 'cta',
            'calendario_reservas' => self::GENERAL, 'data' => '{}']);
        DB::table('usuarios')->insert(['id' => 'u', 'email' => 'tutor@gmail.com', 'nombre' => 'Tutora']);
        DB::table('mascotas')->insert(['id' => 'm', 'nombre' => 'Pelusa']);
        DB::table('especialistas')->insert([
            ['id' => 'conpropio', 'nombre' => 'Dra. Propia', 'google_calendar_id' => self::PROPIO],
            ['id' => 'sinpropio', 'nombre' => 'Dr. General', 'google_calendar_id' => null],
        ]);
    }

    /** El servicio de verdad, con Google simulado por HTTP. */
    private function calendarioReal(): GoogleCalendar
    {
        $oauth = Mockery::mock(GoogleOAuth::class);
        $oauth->shouldReceive('token')->andReturn('tok');
        $emails = Mockery::mock(CitasEmail::class);
        $emails->shouldReceive('correoEspecialista')->andReturn(null);
        Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'ev-nuevo'], 200)]);

        return new GoogleCalendar($emails, $oauth);
    }

    private function cita(string $id, ?string $especialista, array $extra = []): object
    {
        DB::table('citas')->insert([
            'id' => $id, 'estado' => 'Agendada',
            'fecha_hora' => CarbonImmutable::now()->addDay()->format('Y-m-d 10:00:00'),
            'usuario_dueno_id' => 'u', 'mascota_id' => 'm', 'especialista_id' => $especialista,
            'servicio' => 'Consulta', 'duracion_min' => 30,
        ] + $extra);

        return DB::table('citas')->where('id', $id)->first();
    }

    private function esp(string $id): object
    {
        return DB::table('especialistas')->where('id', $id)->first();
    }

    private function enviadoA(string $calendario, string $metodo): bool
    {
        $buscado = '/calendars/' . rawurlencode($calendario) . '/events';

        return Http::recorded(fn ($r) => $r->method() === $metodo
            && str_contains($r->url(), $buscado))->isNotEmpty();
    }

    // ── Escribir el evento ────────────────────────────────────────────────

    public function test_la_reserva_cae_en_el_calendario_propio_y_la_cita_lo_anota(): void
    {
        $g = $this->calendarioReal();
        $cita = $this->cita('c1', 'conpropio');

        $g->crearEventoDeCita($cita, DB::table('usuarios')->first(), DB::table('mascotas')->first(),
            $this->esp('conpropio'));

        $this->assertTrue($this->enviadoA(self::PROPIO, 'POST'));
        $this->assertFalse($this->enviadoA(self::GENERAL, 'POST'));
        $guardada = DB::table('citas')->where('id', 'c1')->first();
        $this->assertSame('ev-nuevo', $guardada->google_event_id);
        $this->assertSame(self::PROPIO, $guardada->google_calendar_id);
    }

    public function test_sin_calendario_propio_sigue_yendo_al_general(): void
    {
        $g = $this->calendarioReal();
        $cita = $this->cita('c1', 'sinpropio');

        $g->crearEventoDeCita($cita, DB::table('usuarios')->first(), DB::table('mascotas')->first(),
            $this->esp('sinpropio'));

        $this->assertTrue($this->enviadoA(self::GENERAL, 'POST'));
        $this->assertSame(self::GENERAL, DB::table('citas')->where('id', 'c1')->value('google_calendar_id'));
    }

    /**
     * La cita se creó cuando su especialista usaba el general; hoy tiene uno
     * propio. Mover y borrar tienen que ir al general, donde está el evento.
     */
    public function test_mover_y_borrar_usan_el_calendario_anotado_en_la_cita(): void
    {
        $g = $this->calendarioReal();
        $cita = $this->cita('c1', 'conpropio',
            ['google_event_id' => 'ev-viejo', 'google_calendar_id' => self::GENERAL]);

        $g->actualizarEventoDeCita($cita, DB::table('usuarios')->first(), DB::table('mascotas')->first(),
            $this->esp('conpropio'));
        $g->eliminarEventoDeCita($cita);

        $this->assertTrue($this->enviadoA(self::GENERAL, 'PATCH'));
        $this->assertTrue($this->enviadoA(self::GENERAL, 'DELETE'));
        $this->assertFalse($this->enviadoA(self::PROPIO, 'PATCH'));
        $this->assertFalse($this->enviadoA(self::PROPIO, 'DELETE'));
    }

    public function test_cita_anterior_a_la_columna_se_busca_en_el_general(): void
    {
        $g = $this->calendarioReal();
        $cita = $this->cita('c1', 'conpropio', ['google_event_id' => 'ev-viejo']);

        $g->eliminarEventoDeCita($cita);

        $this->assertTrue($this->enviadoA(self::GENERAL, 'DELETE'));
    }

    // ── Sincronización ────────────────────────────────────────────────────

    /**
     * @param array<string,bool> $accesibles  calendario → ¿lo ve la cuenta?
     * @param callable(string,string):?array $evento  (calendario, id) → respuesta
     */
    private function sincronizar(array $accesibles, callable $evento, CitasEmail $emails): void
    {
        $g = Mockery::mock(GoogleCalendar::class);
        $g->shouldReceive('cuentaClinica')->andReturn((object) ['id' => 'cta', 'email' => 'clinica@gmail.com']);
        $g->shouldReceive('calendarioClinica')->andReturn([(object) ['id' => 'cta'], self::GENERAL]);
        $g->shouldReceive('calendarioAccesible')->andReturnUsing(fn ($c, $cal) => $accesibles[$cal] ?? false);
        $g->shouldReceive('obtenerEvento')->andReturnUsing(fn ($c, $cal, $id) => $evento($cal, $id));
        $this->app->instance(GoogleCalendar::class, $g);
        $this->app->instance(CitasEmail::class, $emails);

        $this->artisan('google:sincronizar')->assertExitCode(0);
    }

    public function test_cada_cita_se_busca_en_su_calendario(): void
    {
        $this->cita('g1', 'sinpropio', ['google_event_id' => 'evg', 'google_calendar_id' => self::GENERAL]);
        $this->cita('p1', 'conpropio', ['google_event_id' => 'evp', 'google_calendar_id' => self::PROPIO]);
        $preguntas = [];
        $emails = Mockery::mock(CitasEmail::class);
        $emails->shouldNotReceive('notificarCambioEstado');

        $this->sincronizar([self::GENERAL => true, self::PROPIO => true],
            function ($cal, $id) use (&$preguntas) {
                $preguntas[$id] = $cal;
                return ['existe' => true, 'inicio' => null];
            }, $emails);

        $this->assertSame(['evg' => self::GENERAL, 'evp' => self::PROPIO], $preguntas);
    }

    /** Un calendario propio sin acceso no frena al general, ni se cancela nada de él. */
    public function test_un_calendario_propio_sin_acceso_no_frena_a_los_demas(): void
    {
        $this->cita('g1', 'sinpropio', ['google_event_id' => 'evg', 'google_calendar_id' => self::GENERAL]);
        $this->cita('p1', 'conpropio', ['google_event_id' => 'evp', 'google_calendar_id' => self::PROPIO]);
        $emails = Mockery::mock(CitasEmail::class);
        $emails->shouldReceive('notificarCambioEstado')->once();

        $this->sincronizar([self::GENERAL => true, self::PROPIO => false],
            fn () => ['existe' => false], $emails);

        $this->assertSame('Cancelada', DB::table('citas')->where('id', 'g1')->value('estado'));
        $this->assertSame('Agendada', DB::table('citas')->where('id', 'p1')->value('estado'));
    }

    /**
     * Cinco de seis eventos desaparecen del calendario de la Dra. Propia: eso
     * es una falla de ESE calendario aunque la clínica tenga muchas más citas
     * en el general. El tope se mide por calendario.
     */
    public function test_el_tope_de_cancelaciones_se_mide_por_calendario(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->cita("p{$i}", 'conpropio', ['google_event_id' => "evp{$i}", 'google_calendar_id' => self::PROPIO]);
        }
        for ($i = 1; $i <= 10; $i++) {
            $this->cita("g{$i}", 'sinpropio', ['google_event_id' => "evg{$i}", 'google_calendar_id' => self::GENERAL]);
        }
        $emails = Mockery::mock(CitasEmail::class);
        $emails->shouldNotReceive('notificarCambioEstado');

        $this->sincronizar([self::GENERAL => true, self::PROPIO => true],
            fn ($cal, $id) => $cal === self::PROPIO && $id !== 'evp6'
                ? ['existe' => false]
                : ['existe' => true, 'inicio' => null], $emails);

        $this->assertSame(0, DB::table('citas')->where('estado', 'Cancelada')->count());
    }

    // ── Cambiar el calendario general ─────────────────────────────────────

    public function test_cambiar_el_general_no_suelta_las_citas_de_calendarios_propios(): void
    {
        $this->cita('g1', 'sinpropio', ['google_event_id' => 'evg', 'google_calendar_id' => self::GENERAL]);
        $this->cita('v1', 'sinpropio', ['google_event_id' => 'evv']);   // anterior a la columna
        $this->cita('p1', 'conpropio', ['google_event_id' => 'evp', 'google_calendar_id' => self::PROPIO]);

        app(GoogleController::class)->elegirCalendario(
            Request::create('/google/calendario-reservas?calendario_id=nuevo@gmail.com', 'PUT'));

        $this->assertNull(DB::table('citas')->where('id', 'g1')->value('google_event_id'));
        $this->assertNull(DB::table('citas')->where('id', 'v1')->value('google_event_id'));
        $this->assertSame('evp', DB::table('citas')->where('id', 'p1')->value('google_event_id'));
    }

    // ── Disponibilidad ────────────────────────────────────────────────────

    /** @return array<array{0:CarbonImmutable,1:CarbonImmutable}> */
    private function ocupados(string $especialistaId, array $eventosPorCalendario): array
    {
        $g = Mockery::mock(GoogleCalendar::class);
        $g->shouldReceive('eventosOcupados')
            ->andReturnUsing(fn ($c, $cal) => $eventosPorCalendario[$cal] ?? []);
        $g->shouldReceive('ocupadosFreebusy')->andReturn([]);
        $slots = new SlotsService($g);
        $m = new \ReflectionMethod($slots, 'ocupados');
        $desde = CarbonImmutable::parse('2026-11-02 00:00:00');

        return $m->invoke($slots, $this->esp($especialistaId), $desde, $desde->addDays(1));
    }

    /**
     * En el general, un evento con el nombre de OTRO especialista no le tapa
     * la agenda (2 box en paralelo). En el propio, todo bloquea: una hora
     * anotada a mano sin nombre es suya.
     */
    public function test_en_el_calendario_propio_todo_evento_bloquea(): void
    {
        $eventos = [
            self::GENERAL => [['2026-11-02 10:00:00', '2026-11-02 10:30:00', 'Toby · Dr. General']],
            self::PROPIO => [['2026-11-02 12:00:00', '2026-11-02 12:30:00', 'Trámite personal']],
        ];

        $bloques = $this->ocupados('conpropio', $eventos);

        $inicios = array_map(fn ($b) => $b[0]->format('H:i'), $bloques);
        $this->assertSame(['12:00'], $inicios);
    }

    public function test_sin_calendario_propio_solo_se_lee_el_general(): void
    {
        $eventos = [
            self::GENERAL => [['2026-11-02 10:00:00', '2026-11-02 10:30:00', 'Sin nombre']],
            self::PROPIO => [['2026-11-02 12:00:00', '2026-11-02 12:30:00', 'Trámite']],
        ];

        $bloques = $this->ocupados('sinpropio', $eventos);

        $this->assertSame(['10:00'], array_map(fn ($b) => $b[0]->format('H:i'), $bloques));
    }

    // ── Asignar o crear el calendario ─────────────────────────────────────

    private function validar(string $esp, ?string $calendario)
    {
        $m = new \ReflectionMethod(\App\Http\Controllers\Api\CatalogoController::class, 'validarCalendarioPropio');

        return $m->invoke(app(\App\Http\Controllers\Api\CatalogoController::class), $esp, $calendario);
    }

    /**
     * Un calendario compartido «ver todos los detalles» aparece en la lista
     * igual que uno propio, y asignarlo dejaría cada reserva sin evento.
     */
    public function test_no_se_asigna_un_calendario_que_la_cuenta_no_puede_escribir(): void
    {
        $oauth = Mockery::mock(GoogleOAuth::class);
        $oauth->shouldReceive('token')->andReturn('tok');
        $this->app->instance(GoogleOAuth::class, $oauth);
        Http::fake([
            '*calendarList/solo-lectura*' => Http::response(['accessRole' => 'reader'], 200),
            '*calendarList/editable*' => Http::response(['accessRole' => 'writer'], 200),
        ]);

        $this->assertSame(400, $this->validar('sinpropio', 'solo-lectura')->getStatusCode());
        $this->assertNull($this->validar('sinpropio', 'editable'));
        // Volver al general y reenviar el mismo valor no preguntan a Google.
        $this->assertNull($this->validar('sinpropio', null));
        $this->assertNull($this->validar('conpropio', self::PROPIO));
        Http::assertSentCount(2);
    }

    public function test_crear_su_calendario_lo_asigna_y_lo_comparte_en_solo_lectura(): void
    {
        DB::table('especialistas')->where('id', 'sinpropio')->update(['email' => 'dr@gmail.com']);
        $oauth = Mockery::mock(GoogleOAuth::class);
        $oauth->shouldReceive('token')->andReturn('tok');
        $this->app->instance(GoogleOAuth::class, $oauth);
        Http::fake([
            '*/calendars/nuevo-cal/acl' => Http::response(['id' => 'user:dr@gmail.com'], 200),
            '*/calendars' => Http::response(['id' => 'nuevo-cal'], 200),
        ]);
        $req = Request::create('/google/calendario-especialista/sinpropio', 'POST', ['compartir' => true]);

        $r = app(GoogleController::class)->crearCalendarioEspecialista($req, 'sinpropio',
            app(GoogleCalendar::class));

        $this->assertSame('nuevo-cal', $r['google_calendar_id']);
        $this->assertSame('dr@gmail.com', $r['compartido_con']);
        $this->assertSame('nuevo-cal', $this->esp('sinpropio')->google_calendar_id);
        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/acl')
            && $q['role'] === 'reader' && $q['scope']['value'] === 'dr@gmail.com');
        $this->assertSame(1, DB::table('logs_actividad')->where('accion', 'crear_calendario')->count());
    }
}
