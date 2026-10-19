<?php

namespace Tests\Feature;

use App\Services\Esquema;
use App\Services\Jwt;
use Firebase\JWT\JWT as FirebaseJwt;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * VID-04: cambiar la clave o desactivar una cuenta tiene que matar los tokens
 * que ya estaban emitidos, no esperar a que venzan (12 h).
 */
#[RunTestsInSeparateProcesses]
class RevocarSesionesTest extends TestCase
{
    private const SECRETO = 'secreto-de-test-con-largo-suficiente-para-hs256';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => self::SECRETO]);
        Esquema::olvidar();

        foreach (['logs_actividad', 'solicitudes_reset', 'usuarios'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre')->nullable();
            $t->string('telefono')->nullable(); $t->string('rut')->nullable(); $t->string('direccion')->nullable();
            $t->string('rol'); $t->boolean('es_activo')->default(true); $t->text('permisos')->nullable();
            $t->string('cargo')->nullable(); $t->string('password_hash')->nullable();
            $t->boolean('email_verificado')->default(true);
            $t->string('token_reset')->nullable(); $t->timestamp('token_reset_expira')->nullable();
            $t->integer('sesion_version')->default(0);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('logs_actividad', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id')->nullable(); $t->string('usuario_nombre')->nullable();
            $t->string('usuario_rol')->nullable();
            $t->string('accion')->nullable(); $t->string('area')->nullable(); $t->string('entidad')->nullable();
            $t->string('entidad_id')->nullable(); $t->text('detalle')->nullable(); $t->string('ip')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        DB::table('usuarios')->insert([
            ['id' => 'admin', 'email' => 'admin@x.cl', 'rol' => 'admin',
                'password_hash' => password_hash('Clave1234', PASSWORD_BCRYPT)],
            ['id' => 'vet', 'email' => 'vet@x.cl', 'rol' => 'veterinario',
                'password_hash' => password_hash('Clave1234', PASSWORD_BCRYPT)],
            ['id' => 'ana', 'email' => 'ana@x.cl', 'rol' => 'dueño',
                'password_hash' => password_hash('Clave1234', PASSWORD_BCRYPT)],
        ]);
    }

    private function token(string $id): string
    {
        $u = DB::table('usuarios')->where('id', $id)->first();

        return app(Jwt::class)->firmar(['sub' => $u->email, 'rol' => $u->rol, 'user_id' => $id]);
    }

    private function me(string $token)
    {
        return $this->getJson('/auth/me', ['Authorization' => "Bearer {$token}"]);
    }

    public function test_el_token_lleva_la_version_de_sesion(): void
    {
        DB::table('usuarios')->where('id', 'ana')->update(['sesion_version' => 3]);
        $payload = (array) FirebaseJwt::decode($this->token('ana'),
            new \Firebase\JWT\Key(self::SECRETO, 'HS256'));

        $this->assertSame(3, $payload['sv']);
    }

    public function test_un_token_sin_version_sigue_valiendo_mientras_no_se_revoque(): void
    {
        $viejo = FirebaseJwt::encode(['sub' => 'ana@x.cl', 'rol' => 'dueño', 'user_id' => 'ana',
            'exp' => time() + 3600], self::SECRETO, 'HS256');

        $this->me($viejo)->assertOk();

        DB::table('usuarios')->where('id', 'ana')->update(['sesion_version' => 1]);
        $this->me($viejo)->assertStatus(401);
    }

    public function test_restablecer_la_clave_mata_los_tokens_anteriores(): void
    {
        $robado = $this->token('ana');
        DB::table('usuarios')->where('id', 'ana')->update([
            'token_reset' => hash('sha256', 'tk'),
            'token_reset_expira' => now('UTC')->addHour(),
        ]);

        $r = $this->postJson('/auth/restablecer', ['token' => 'tk', 'password' => 'Nueva1234']);
        $r->assertOk();

        $this->me($robado)->assertStatus(401);
        $this->me($r->json('access_token'))->assertOk();
    }

    public function test_cambiar_la_clave_propia_cierra_las_otras_sesiones_y_no_esta(): void
    {
        $otraSesion = $this->token('ana');
        $esta = $this->token('ana');

        $r = $this->patchJson('/auth/me',
            ['password_actual' => 'Clave1234', 'password_nueva' => 'Nueva1234'],
            ['Authorization' => "Bearer {$esta}"]);
        $r->assertOk();

        $this->me($otraSesion)->assertStatus(401);
        $this->me($r->json('access_token'))->assertOk();
    }

    public function test_editar_el_perfil_sin_tocar_la_clave_no_desloguea(): void
    {
        $t = $this->token('ana');
        $r = $this->patchJson('/auth/me', ['nombre' => 'Ana María'], ['Authorization' => "Bearer {$t}"]);

        $r->assertOk();
        $this->assertNull($r->json('access_token'));
        $this->me($t)->assertOk();
    }

    public function test_el_admin_resetea_la_clave_de_un_veterinario(): void
    {
        $delVet = $this->token('vet');
        $this->postJson('/staff/vet/reset-password', [],
            ['Authorization' => 'Bearer ' . $this->token('admin')])->assertOk();

        $this->me($delVet)->assertStatus(401);
    }

    public function test_desactivar_y_reactivar_no_revive_el_token_viejo(): void
    {
        $delVet = $this->token('vet');
        $admin = ['Authorization' => 'Bearer ' . $this->token('admin')];

        $this->patchJson('/staff/vet', ['es_activo' => false], $admin)->assertOk();
        $this->patchJson('/staff/vet', ['es_activo' => true], $admin)->assertOk();

        $this->me($delVet)->assertStatus(401);
        $this->me($this->token('vet'))->assertOk();
    }

    public function test_sin_la_columna_todo_sigue_funcionando(): void
    {
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('sesion_version'));
        Esquema::olvidar();

        $t = $this->token('ana');
        $this->me($t)->assertOk();
        $this->patchJson('/auth/me',
            ['password_actual' => 'Clave1234', 'password_nueva' => 'Nueva1234'],
            ['Authorization' => "Bearer {$t}"])->assertOk();
    }
}
