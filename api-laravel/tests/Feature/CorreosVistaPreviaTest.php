<?php

namespace Tests\Feature;

use App\Services\Correo;
use App\Services\Esquema;
use App\Services\Jwt;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/** Si alguien llama al transporte real durante la vista previa, el test cae. */
class CorreoQueNoDebeUsarse extends Correo
{
    public function __construct()
    {
    }

    public function enviar(string $destinatario, string $asunto, string $cuerpoTexto, ?string $cuerpoHtml = null): bool
    {
        throw new \LogicException("La vista previa intentó ENVIAR un correo a {$destinatario}");
    }
}

/**
 * Ajustes → Correos. Fija que salen todos los correos, que ninguno se envía de
 * verdad, que no aparece ningún dato de la base (solo los de ejemplo), y que
 * es del equipo: un dueño no lo ve.
 */
#[RunTestsInSeparateProcesses]
class CorreosVistaPreviaTest extends TestCase
{
    private const SECRETO = 'secreto-de-test-con-largo-suficiente-para-hs256';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => self::SECRETO, 'emr.portal_reservas_url' => 'https://reservas.ejemplo.cl']);
        Esquema::olvidar();

        foreach (['ajustes', 'usuarios'] as $t) {
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
        DB::table('ajustes')->insert(['id' => 1,
            'data' => json_encode(['recordatorios' => ['firma' => 'Equipo de prueba PANGI']])]);
        DB::table('usuarios')->insert([
            ['id' => 'vet', 'email' => 'cliente.real@gmail.com', 'nombre' => 'Persona Real Base', 'rol' => 'veterinario',
                'permisos' => json_encode(['agenda'])],
            ['id' => 'duen', 'email' => 'duena@x.cl', 'nombre' => 'Dueña', 'rol' => 'dueño', 'permisos' => null],
        ]);

        $this->app->instance(Correo::class, new CorreoQueNoDebeUsarse());
    }

    private function como(string $id): array
    {
        $u = DB::table('usuarios')->where('id', $id)->first();
        $token = app(Jwt::class)->firmar(['sub' => $u->email, 'rol' => $u->rol, 'user_id' => $id]);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_salen_todos_y_ninguno_se_envia(): void
    {
        // Un veterinario limitado a Agenda también lo ve: es solo ejemplo.
        $r = $this->getJson('/ajustes/correos', $this->como('vet'))->assertOk()->json();

        $this->assertCount(17, $r);
        foreach ($r as $c) {
            $this->assertArrayNotHasKey('error', $c, "{$c['titulo']}: " . ($c['error'] ?? ''));
            $this->assertNotEmpty($c['asunto'], $c['titulo']);
            $this->assertStringContainsString('<', (string) $c['html'], "{$c['titulo']} sin HTML");
        }
    }

    public function test_usa_la_configuracion_real_y_ningun_dato_de_la_base(): void
    {
        $todo = json_encode($this->getJson('/ajustes/correos', $this->como('vet'))->json(), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Equipo de prueba PANGI', $todo, 'la firma de los recordatorios sale de Ajustes');
        $this->assertStringContainsString('reservas.ejemplo.cl', $todo, 'los enlaces usan la URL configurada del portal');
        $this->assertStringNotContainsString('cliente.real@gmail.com', $todo);
        $this->assertStringNotContainsString('Persona Real Base', $todo);
    }

    public function test_un_dueno_no_lo_ve_y_sin_sesion_tampoco(): void
    {
        $this->getJson('/ajustes/correos', $this->como('duen'))->assertStatus(403);
        $this->getJson('/ajustes/correos')->assertStatus(401);
    }
}
