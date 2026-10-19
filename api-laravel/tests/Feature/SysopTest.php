<?php

namespace Tests\Feature;

use App\Services\Esquema;
use App\Services\Jwt;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * `auth:sysop`: importar datos y desconectar Google son de quien figura en
 * `SYSOP_EMAILS`, no de cualquier admin de la clínica.
 *
 * Lo que fija: que un admin común NO pasa, que la lista vacía no deja pasar a
 * nadie (falla cerrado), que figurar en la lista no basta sin ser admin, y que
 * lo que se hace queda en auditoría.
 */
#[RunTestsInSeparateProcesses]
class SysopTest extends TestCase
{
    private const SECRETO = 'secreto-de-test-con-largo-suficiente-para-hs256';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => self::SECRETO, 'emr.sysop_emails' => ['soporte@x.cl']]);
        Esquema::olvidar();

        foreach (['logs_actividad', 'google_accounts', 'ajustes', 'usuarios'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre')->nullable();
            $t->string('telefono')->nullable(); $t->string('rol'); $t->boolean('es_activo')->default(true);
            $t->text('permisos')->nullable(); $t->integer('sesion_version')->default(0);
            $t->timestamp('created_at')->nullable();
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
            $t->string('ip')->nullable(); $t->timestamp('created_at')->nullable();
        });

        DB::table('usuarios')->insert([
            ['id' => 'sys', 'email' => 'Soporte@X.cl', 'nombre' => 'Soporte', 'rol' => 'admin'],
            ['id' => 'adm', 'email' => 'marta@x.cl', 'nombre' => 'Marta', 'rol' => 'admin'],
            ['id' => 'vet', 'email' => 'soporte@x.cl', 'nombre' => 'Vet con el mismo correo', 'rol' => 'veterinario'],
        ]);
        DB::table('google_accounts')->insert(['id' => 'g1', 'email' => 'clinica@gmail.com']);
        DB::table('ajustes')->insert(['id' => 1, 'data' => '{}', 'google_account_id' => 'g1',
            'calendario_reservas' => 'cal-1']);
    }

    private function como(string $id): array
    {
        $u = DB::table('usuarios')->where('id', $id)->first();
        $token = app(Jwt::class)->firmar(['sub' => $u->email, 'rol' => $u->rol, 'user_id' => $id]);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_un_admin_comun_no_puede_desconectar_google(): void
    {
        $this->deleteJson('/google/desconectar', [], $this->como('adm'))->assertStatus(403);

        $this->assertSame('g1', DB::table('ajustes')->where('id', 1)->value('google_account_id'));
        $this->assertSame(1, DB::table('google_accounts')->count());
    }

    public function test_un_admin_comun_no_ve_el_importador(): void
    {
        $this->getJson('/importacion/tipos', $this->como('adm'))->assertStatus(403);
        $this->postJson('/importacion/clientes', [], $this->como('adm'))->assertStatus(403);
    }

    public function test_el_sysop_entra_aunque_el_correo_venga_con_mayusculas(): void
    {
        $this->getJson('/importacion/tipos', $this->como('sys'))->assertOk();
    }

    public function test_desconectar_suelta_la_cuenta_y_queda_en_auditoria(): void
    {
        $this->deleteJson('/google/desconectar', [], $this->como('sys'))->assertNoContent();

        $this->assertNull(DB::table('ajustes')->where('id', 1)->value('google_account_id'));
        $this->assertNull(DB::table('ajustes')->where('id', 1)->value('calendario_reservas'));
        $this->assertSame(0, DB::table('google_accounts')->count());

        $log = DB::table('logs_actividad')->first();
        $this->assertNotNull($log, 'desconectar Google no dejó rastro en auditoría');
        $this->assertSame(['desconectar', 'integraciones', 'google', 'sys'],
            [$log->accion, $log->area, $log->entidad, $log->usuario_id]);
        $this->assertSame('clinica@gmail.com', json_decode($log->detalle, true)['cuenta']);
    }

    public function test_figurar_en_la_lista_no_alcanza_si_no_es_admin(): void
    {
        $this->deleteJson('/google/desconectar', [], $this->como('vet'))->assertStatus(403);
        $this->getJson('/importacion/tipos', $this->como('vet'))->assertStatus(403);
    }

    public function test_sin_lista_nadie_es_sysop(): void
    {
        config(['emr.sysop_emails' => []]);

        $this->deleteJson('/google/desconectar', [], $this->como('sys'))->assertStatus(403);
        $this->getJson('/importacion/tipos', $this->como('sys'))->assertStatus(403);
    }

    public function test_me_avisa_al_panel_quien_es_sysop(): void
    {
        $this->getJson('/auth/me', $this->como('sys'))->assertOk()->assertJsonPath('es_sysop', true);
        $this->getJson('/auth/me', $this->como('adm'))->assertOk()->assertJsonPath('es_sysop', false);
        $this->getJson('/auth/me', $this->como('vet'))->assertOk()->assertJsonPath('es_sysop', false);
    }
}
