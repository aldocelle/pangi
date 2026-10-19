<?php

namespace Tests\Feature;

use App\Console\Commands\TareaDeFondo;
use App\Services\CorridasTareas;
use App\Services\Esquema;
use App\Services\Jwt;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

class TareaPruebaOk extends TareaDeFondo
{
    protected $signature = 'prueba:ok';

    protected function ejecutar(): string
    {
        return '3 enviados';
    }
}

class TareaPruebaFalla extends TareaDeFondo
{
    protected $signature = 'prueba:falla';

    protected function ejecutar(): string
    {
        throw new \RuntimeException('Google respondió 401');
    }
}

/** Reemplaza a la de verdad: el test no tiene que salir a internet. */
class GoogleFalso extends TareaDeFondo
{
    protected $signature = 'google:sincronizar';

    protected function ejecutar(): string
    {
        return '0 eventos';
    }
}

/**
 * Sysop → Tareas programadas (§13.17). Fija que cada corrida queda anotada
 * (también la que falla y la que se salta por el lock), que una tarea sin
 * corridas recientes se ve como atrasada en vez de no verse, que la tabla
 * ausente no rompe ninguna tarea, y que solo sysop lo ve y lo ejecuta.
 */
#[RunTestsInSeparateProcesses]
class TareasCorridasTest extends TestCase
{
    private const SECRETO = 'secreto-de-test-con-largo-suficiente-para-hs256';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => self::SECRETO, 'emr.sysop_emails' => ['soporte@x.cl']]);
        Esquema::olvidar();

        foreach (['tareas_corridas', 'logs_actividad', 'usuarios'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre')->nullable();
            $t->string('telefono')->nullable(); $t->string('rol'); $t->boolean('es_activo')->default(true);
            $t->text('permisos')->nullable(); $t->integer('sesion_version')->default(0);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('logs_actividad', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id')->nullable(); $t->string('usuario_nombre')->nullable();
            $t->string('usuario_rol')->nullable(); $t->string('accion')->nullable(); $t->string('area')->nullable();
            $t->string('entidad')->nullable(); $t->string('entidad_id')->nullable(); $t->text('detalle')->nullable();
            $t->string('ip')->nullable(); $t->timestamp('created_at')->nullable();
        });
        $this->crearTabla();

        DB::table('usuarios')->insert([
            ['id' => 'sys', 'email' => 'soporte@x.cl', 'nombre' => 'Soporte', 'rol' => 'admin'],
            ['id' => 'adm', 'email' => 'marta@x.cl', 'nombre' => 'Marta', 'rol' => 'admin'],
        ]);

        $kernel = $this->app->make(Kernel::class);
        foreach ([TareaPruebaOk::class, TareaPruebaFalla::class, GoogleFalso::class] as $c) {
            $kernel->registerCommand($this->app->make($c));
        }
    }

    private function crearTabla(): void
    {
        Schema::create('tareas_corridas', function (Blueprint $t) {
            $t->id(); $t->string('tarea'); $t->dateTime('inicio', 3); $t->dateTime('fin', 3);
            $t->unsignedInteger('duracion_ms'); $t->string('resultado'); $t->string('resumen', 500)->nullable();
            $t->string('origen')->default('cron'); $t->string('usuario_id')->nullable();
        });
        Esquema::olvidar();
    }

    private function como(string $id): array
    {
        $u = DB::table('usuarios')->where('id', $id)->first();
        $token = app(Jwt::class)->firmar(['sub' => $u->email, 'rol' => $u->rol, 'user_id' => $id]);

        return ['Authorization' => "Bearer {$token}"];
    }

    /** Una corrida de hace `$min` minutos. */
    private function corrida(string $tarea, int $min, string $resultado = 'ok'): void
    {
        $t = CarbonImmutable::now('UTC')->subMinutes($min)->format('Y-m-d H:i:s.v');
        DB::table('tareas_corridas')->insert(['tarea' => $tarea, 'inicio' => $t, 'fin' => $t,
            'duracion_ms' => 120, 'resultado' => $resultado, 'origen' => 'cron']);
    }

    private function estados(): array
    {
        return collect(CorridasTareas::resumen()['tareas'])->pluck('estado', 'id')->all();
    }

    public function test_la_corrida_exitosa_queda_anotada_con_su_resumen(): void
    {
        $this->assertSame(0, Artisan::call('prueba:ok'));

        $f = DB::table('tareas_corridas')->sole();
        $this->assertSame(['prueba:ok', 'ok', '3 enviados', 'cron'], [$f->tarea, $f->resultado, $f->resumen, $f->origen]);
    }

    public function test_la_que_falla_queda_anotada_con_el_error(): void
    {
        $this->assertSame(1, Artisan::call('prueba:falla'));

        $f = DB::table('tareas_corridas')->sole();
        $this->assertSame(['fallo', 'Google respondió 401'], [$f->resultado, $f->resumen]);
    }

    public function test_la_que_encuentra_el_lock_tomado_se_anota_como_saltada(): void
    {
        $dir = storage_path('app/locks');
        is_dir($dir) || mkdir($dir, 0775, true);
        $fh = fopen("{$dir}/prueba-ok.lock", 'c');
        flock($fh, LOCK_EX);
        try {
            Artisan::call('prueba:ok');
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        $this->assertSame('saltada', DB::table('tareas_corridas')->value('resultado'));
    }

    public function test_sin_la_tabla_la_tarea_corre_igual(): void
    {
        Schema::drop('tareas_corridas');
        Esquema::olvidar();

        $this->assertSame(0, Artisan::call('prueba:ok'));
        $this->assertSame('sin_tabla', $this->estados()['google:sincronizar']);
    }

    public function test_el_estado_se_calcula_por_ausencia(): void
    {
        $this->corrida('google:sincronizar', 3);
        $this->corrida('recordatorios:citas', 40);          // corre cada minuto: 40 es atraso
        $this->corrida('estado:revisar', 20, 'fallo');
        $this->corrida('estado:revisar', 5, 'saltada');      // la saltada no tapa el fallo
        $this->corrida('respaldo:crear', 25 * 60);           // diario: 25 h todavía está bien

        $this->assertSame([
            'recordatorios:citas' => 'atrasada',
            'google:sincronizar' => 'ok',
            'estado:revisar' => 'fallando',
            'avisos:preventivos' => 'sin_corridas',           // el caso de producción sin cron
            'respaldo:crear' => 'ok',
        ], $this->estados());
    }

    public function test_un_respaldo_de_hace_27_horas_esta_atrasado(): void
    {
        $this->corrida('respaldo:crear', 27 * 60);

        $this->assertSame('atrasada', $this->estados()['respaldo:crear']);
    }

    public function test_purga_lo_de_mas_de_7_dias(): void
    {
        $this->corrida('google:sincronizar', 8 * 24 * 60);
        Artisan::call('prueba:ok');

        $this->assertSame(['prueba:ok'], DB::table('tareas_corridas')->pluck('tarea')->all());
    }

    public function test_un_admin_comun_no_ve_ni_ejecuta(): void
    {
        $this->getJson('/sysop/tareas', $this->como('adm'))->assertStatus(403);
        $this->postJson('/sysop/tareas/google:sincronizar/ejecutar', [], $this->como('adm'))->assertStatus(403);
        $this->assertSame(0, DB::table('tareas_corridas')->count());
    }

    public function test_el_sysop_ve_las_cinco_tareas(): void
    {
        $this->corrida('google:sincronizar', 2);

        $r = $this->getJson('/sysop/tareas', $this->como('sys'))->assertOk();
        $this->assertCount(5, $r->json('tareas'));
        $this->assertSame('ok', collect($r->json('tareas'))->firstWhere('id', 'google:sincronizar')['estado']);

        $this->getJson('/sysop/tareas/google:sincronizar/corridas', $this->como('sys'))
            ->assertOk()->assertJsonCount(1);
    }

    public function test_ejecutar_ahora_anota_origen_panel_y_queda_en_auditoria(): void
    {
        $this->postJson('/sysop/tareas/google:sincronizar/ejecutar', [], $this->como('sys'))
            ->assertOk()->assertJsonPath('ok', true);

        $f = DB::table('tareas_corridas')->sole();
        $this->assertSame(['google:sincronizar', 'panel', 'sys'], [$f->tarea, $f->origen, $f->usuario_id]);

        $log = DB::table('logs_actividad')->sole();
        $this->assertSame(['ejecutar', 'sysop', 'tarea', 'google:sincronizar', 'sys'],
            [$log->accion, $log->area, $log->entidad, $log->entidad_id, $log->usuario_id]);

        // Lo que corra después por cron vuelve a ser `cron`.
        Artisan::call('prueba:ok');
        $this->assertSame('cron', DB::table('tareas_corridas')->where('tarea', 'prueba:ok')->value('origen'));
    }

    public function test_las_tareas_largas_o_con_correos_no_se_lanzan_desde_el_panel(): void
    {
        foreach (['respaldo:crear', 'recordatorios:citas', 'avisos:preventivos'] as $t) {
            $this->postJson("/sysop/tareas/{$t}/ejecutar", [], $this->como('sys'))->assertStatus(422);
        }
        $this->postJson('/sysop/tareas/migrate:fresh/ejecutar', [], $this->como('sys'))->assertStatus(404);
        $this->assertSame(0, DB::table('tareas_corridas')->count());
    }
}
