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
 * GET|PUT /configuracion/correos y GET /ajustes/correos/paleta.
 *
 * Leer es de cualquier staff (igual que recordatorios/ventas/impresión);
 * guardar es solo de admin, porque es la apariencia de TODOS los correos de
 * la clínica. Queda auditado.
 */
#[RunTestsInSeparateProcesses]
class ConfiguracionCorreosTest extends TestCase
{
    private const SECRETO = 'secreto-de-test-con-largo-suficiente-para-hs256';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => self::SECRETO]);
        Esquema::olvidar();

        foreach (['ajustes', 'logs_actividad', 'usuarios'] as $t) {
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
        });
        Schema::create('logs_actividad', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id')->nullable(); $t->string('usuario_nombre')->nullable();
            $t->string('usuario_rol')->nullable(); $t->string('accion')->nullable(); $t->string('area')->nullable();
            $t->string('entidad')->nullable(); $t->string('entidad_id')->nullable(); $t->text('detalle')->nullable();
            $t->string('ip')->nullable(); $t->timestamp('created_at')->nullable();
        });

        DB::table('usuarios')->insert([
            ['id' => 'adm', 'email' => 'admin@x.cl', 'nombre' => 'Admin', 'rol' => 'admin', 'permisos' => null],
            ['id' => 'vet', 'email' => 'vet@x.cl', 'nombre' => 'Vet', 'rol' => 'veterinario', 'permisos' => json_encode(['agenda'])],
        ]);
    }

    private function como(string $id): array
    {
        $u = DB::table('usuarios')->where('id', $id)->first();
        $token = app(Jwt::class)->firmar(['sub' => $u->email, 'rol' => $u->rol, 'user_id' => $id]);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_cualquier_staff_puede_leer(): void
    {
        $this->getJson('/configuracion/correos', $this->como('vet'))
            ->assertOk()->assertJsonPath('fondo_boton', '#1a2350');
    }

    public function test_un_veterinario_no_puede_guardar(): void
    {
        $this->putJson('/configuracion/correos', ['fondo_boton' => '#087c8a'], $this->como('vet'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'Solo un administrador puede cambiar la apariencia de los correos.');

        $this->assertSame('#1a2350', $this->getJson('/configuracion/correos', $this->como('vet'))->json('fondo_boton'));
    }

    public function test_un_admin_guarda_y_queda_auditado(): void
    {
        $this->putJson('/configuracion/correos', ['fondo_boton' => '#087c8a'], $this->como('adm'))
            ->assertOk()->assertJsonPath('fondo_boton', '#087c8a');

        $log = DB::table('logs_actividad')->sole();
        $this->assertSame(['editar', 'correos', 'configuracion', 'correos'],
            [$log->accion, $log->area, $log->entidad, $log->entidad_id]);
        $this->assertSame(['de' => '#1a2350', 'a' => '#087c8a'], json_decode($log->detalle, true)['fondo_boton']);
    }

    public function test_un_color_fuera_del_catalogo_da_422_con_detail(): void
    {
        $r = $this->putJson('/configuracion/correos', ['fondo_boton' => '#ff00ff'], $this->como('adm'));

        $r->assertStatus(422);
        $this->assertSame('fondo_boton', $r->json('detail.0.loc.1'));
    }

    public function test_la_paleta_es_publica_para_staff_y_coincide_con_lo_que_valida(): void
    {
        $r = $this->getJson('/ajustes/correos/paleta', $this->como('vet'))->assertOk()->json();

        $this->assertCount(4, $r['claros']);
        $this->assertCount(4, $r['oscuros']);
        $this->assertContains('#1a2350', array_column($r['oscuros'], 'hex'));
        $this->assertContains('#eef1fb', array_column($r['claros'], 'hex'));

        // Lo que ofrece la paleta es justo lo que validar() acepta: si un hex
        // de "claros" se rechaza para fondo_pagina, la pantalla ofrecería
        // opciones que el guardado tira abajo.
        foreach (array_column($r['claros'], 'hex') as $hex) {
            $this->putJson('/configuracion/correos', ['fondo_pagina' => $hex], $this->como('adm'))->assertOk();
        }
        foreach (array_column($r['oscuros'], 'hex') as $hex) {
            $this->putJson('/configuracion/correos', ['fondo_boton' => $hex], $this->como('adm'))->assertOk();
        }
    }

    public function test_sin_sesion_ninguno_de_los_dos_responde(): void
    {
        $this->getJson('/configuracion/correos')->assertStatus(401);
        $this->getJson('/ajustes/correos/paleta')->assertStatus(401);
    }
}
