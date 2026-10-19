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
 * Aviso de la política de privacidad para quien no tiene ningún registro.
 *
 * Son los tutores que vinieron de ACVet (CLAUDE.md §14): llegaron con su
 * historial y sin constancia. El portal les muestra un aviso que NO bloquea
 * nada, y este es el contrato que lo sostiene: quién figura como pendiente, y
 * que aceptar deja una constancia que después nadie pisa.
 */
#[RunTestsInSeparateProcesses]
class AvisoPrivacidadTest extends TestCase
{
    private const SECRETO = 'secreto-de-test-con-largo-suficiente-para-hs256';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => self::SECRETO]);
        Esquema::olvidar();

        foreach (['logs_actividad', 'usuarios'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre')->nullable();
            $t->string('telefono')->nullable(); $t->string('rol'); $t->boolean('es_activo')->default(true);
            $t->text('permisos')->nullable(); $t->string('password_hash')->nullable();
            $t->boolean('email_verificado')->default(true);
            $t->integer('sesion_version')->default(0);
            $t->boolean('privacy_accepted')->default(false);
            $t->timestamp('privacy_accepted_at')->nullable();
            $t->string('privacy_version')->nullable();
            $t->boolean('marketing_consent')->default(false);
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
            // Importado: sin ningún registro.
            ['id' => 'ana', 'email' => 'ana@x.cl', 'rol' => 'dueño'],
            ['id' => 'vet', 'email' => 'vet@x.cl', 'rol' => 'veterinario'],
            ['id' => 'admin', 'email' => 'admin@x.cl', 'rol' => 'admin'],
        ]);
        // Aceptó hace tiempo, con una versión anterior de la política.
        DB::table('usuarios')->insert(['id' => 'beto', 'email' => 'beto@x.cl', 'rol' => 'dueño',
            'privacy_accepted' => true, 'privacy_accepted_at' => '2026-08-01 10:00:00',
            'privacy_version' => '2026-08-01']);
    }

    private function cabecera(string $id): array
    {
        $u = DB::table('usuarios')->where('id', $id)->first();
        $token = app(Jwt::class)->firmar(['sub' => $u->email, 'rol' => $u->rol, 'user_id' => $id]);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_un_dueno_sin_registro_figura_como_pendiente(): void
    {
        $this->getJson('/auth/me', $this->cabecera('ana'))
            ->assertOk()
            ->assertJsonPath('privacidad_pendiente', true);
    }

    public function test_quien_ya_acepto_no_vuelve_a_ver_el_aviso_aunque_la_version_sea_otra(): void
    {
        $this->getJson('/auth/me', $this->cabecera('beto'))
            ->assertOk()
            ->assertJsonPath('privacidad_pendiente', false);
    }

    public function test_el_equipo_nunca_figura_como_pendiente(): void
    {
        foreach (['vet', 'admin'] as $id) {
            $this->getJson('/auth/me', $this->cabecera($id))
                ->assertOk()
                ->assertJsonPath('privacidad_pendiente', false);
        }
    }

    public function test_aceptar_deja_constancia_con_fecha_y_version(): void
    {
        $this->postJson('/auth/aceptar-privacidad', [], $this->cabecera('ana'))
            ->assertOk()
            ->assertJsonPath('privacidad_pendiente', false);

        $u = DB::table('usuarios')->where('id', 'ana')->first();
        $this->assertTrue((bool) $u->privacy_accepted);
        $this->assertNotNull($u->privacy_accepted_at);
        $this->assertNotEmpty($u->privacy_version);

        $this->getJson('/auth/me', $this->cabecera('ana'))
            ->assertJsonPath('privacidad_pendiente', false);
    }

    public function test_aceptar_de_nuevo_no_pisa_la_constancia_original(): void
    {
        $this->postJson('/auth/aceptar-privacidad', [], $this->cabecera('beto'))->assertOk();

        $u = DB::table('usuarios')->where('id', 'beto')->first();
        $this->assertSame('2026-08-01', $u->privacy_version);
        $this->assertStringStartsWith('2026-08-01 10:00:00', (string) $u->privacy_accepted_at);
    }

    public function test_aceptar_solo_toca_a_quien_lo_pide(): void
    {
        $this->postJson('/auth/aceptar-privacidad', [], $this->cabecera('ana'))->assertOk();

        $this->assertSame(0, (int) DB::table('usuarios')->where('id', 'vet')->value('privacy_accepted'));
    }

    public function test_sin_sesion_no_se_puede_aceptar(): void
    {
        $this->postJson('/auth/aceptar-privacidad')->assertStatus(401);
    }
}
