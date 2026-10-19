<?php

namespace Tests\Feature;

use App\Services\GoogleOAuth;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * «Continuar con Google» en el panel. La contracara de /auth/google, que es de
 * clientes y rechaza al equipo: esta solo deja entrar a una cuenta del equipo
 * que YA existe, y no crea ni toca cuentas de clientes.
 */
#[RunTestsInSeparateProcesses]
class LoginEquipoGoogleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => 'secreto-de-test-con-largo-suficiente-para-hs256']);
        Schema::dropIfExists('usuarios');
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre')->nullable();
            $t->string('rol'); $t->boolean('es_activo')->default(true);
            $t->string('google_id')->nullable(); $t->string('password_hash')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        foreach ([
            ['id' => 'adm', 'email' => 'Admin@Clinica.test', 'rol' => 'admin'],
            ['id' => 'vet', 'email' => 'vet@clinica.test', 'rol' => 'veterinario', 'google_id' => 'g-vet'],
            ['id' => 'off', 'email' => 'fuera@clinica.test', 'rol' => 'veterinario', 'es_activo' => false],
            ['id' => 'cli', 'email' => 'cliente@gmail.com', 'rol' => 'dueño'],
        ] as $fila) {
            DB::table('usuarios')->insert($fila);
        }
    }

    private function google(?array $payload): void
    {
        $g = Mockery::mock(GoogleOAuth::class);
        $g->shouldReceive('verificarIdToken')->andReturn($payload);
        $this->app->instance(GoogleOAuth::class, $g);
    }

    private function entrar(?array $payload)
    {
        $this->google($payload);

        return $this->postJson('/auth/google/equipo', ['id_token' => 'x']);
    }

    public function test_admin_existente_entra_y_queda_vinculado(): void
    {
        $r = $this->entrar(['email' => 'admin@clinica.test', 'sub' => 'g-adm']);
        $r->assertOk();
        $jwt = JWT::decode($r->json('access_token'), new Key(config('app.jwt_secret'), 'HS256'));
        $this->assertSame(['adm', 'admin'], [$jwt->user_id, $jwt->rol]);
        $this->assertSame('g-adm', DB::table('usuarios')->where('id', 'adm')->value('google_id'));
    }

    public function test_correo_desconocido_no_crea_cuenta(): void
    {
        $this->entrar(['email' => 'nadie@gmail.com', 'sub' => 'g-x'])->assertStatus(403);
        $this->assertSame(4, DB::table('usuarios')->count());
    }

    public function test_un_cliente_no_entra_al_panel_y_no_se_toca(): void
    {
        $this->entrar(['email' => 'cliente@gmail.com', 'sub' => 'g-cli'])->assertStatus(403);
        $this->assertNull(DB::table('usuarios')->where('id', 'cli')->value('google_id'));
    }

    public function test_otra_cuenta_de_google_con_el_mismo_correo_no_entra(): void
    {
        $this->entrar(['email' => 'vet@clinica.test', 'sub' => 'g-otro'])->assertStatus(403);
        $this->entrar(['email' => 'vet@clinica.test', 'sub' => 'g-vet'])->assertOk();
    }

    public function test_cuenta_desactivada_no_entra(): void
    {
        $this->entrar(['email' => 'fuera@clinica.test', 'sub' => 'g-off'])->assertStatus(403);
    }

    public function test_token_invalido_es_401(): void
    {
        $this->entrar(null)->assertStatus(401);
    }

    public function test_cliente_con_mayusculas_entra_a_su_cuenta_sin_duplicarla(): void
    {
        DB::table('usuarios')->where('id', 'cli')->update(['email' => 'Cliente@Gmail.com']);
        $this->google(['email' => 'cliente@gmail.com', 'sub' => 'g-cli', 'name' => 'Cli']);
        $r = $this->postJson('/auth/google', ['id_token' => 'x'])->assertOk();
        $jwt = JWT::decode($r->json('access_token'), new Key(config('app.jwt_secret'), 'HS256'));
        $this->assertSame('cli', $jwt->user_id);
        $this->assertSame(4, DB::table('usuarios')->count());
    }

    public function test_cliente_nuevo_por_google_se_crea_en_minusculas(): void
    {
        $this->google(['email' => 'Nuevo@Gmail.com', 'sub' => 'g-new', 'name' => 'Nuevo']);
        $this->postJson('/auth/google', ['id_token' => 'x'])->assertOk();
        $this->assertSame(1, DB::table('usuarios')->where('email', 'nuevo@gmail.com')->count());
    }

    public function test_cliente_desactivado_no_entra_por_google(): void
    {
        DB::table('usuarios')->where('id', 'cli')->update(['es_activo' => false]);
        $this->google(['email' => 'cliente@gmail.com', 'sub' => 'g-cli']);
        $this->postJson('/auth/google', ['id_token' => 'x'])->assertStatus(403);
    }

    public function test_la_ruta_de_clientes_sigue_rechazando_al_equipo(): void
    {
        // Correo idéntico al guardado: SQLite compara con mayúsculas y MariaDB
        // no (`utf8mb4_uca1400_as_ci`), y lo que se prueba acá es el rol.
        $this->google(['email' => 'vet@clinica.test', 'sub' => 'g-vet']);
        $this->postJson('/auth/google', ['id_token' => 'x'])->assertStatus(403);
    }
}
