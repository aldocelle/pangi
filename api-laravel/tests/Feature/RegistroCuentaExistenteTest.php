<?php

namespace Tests\Feature;

use App\Services\Correo;
use App\Services\Esquema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

class CorreoAnotador extends Correo
{
    public array $enviados = [];

    public function __construct()
    {
    }

    public function enviar(string $destinatario, string $asunto, string $cuerpoTexto, ?string $cuerpoHtml = null): bool
    {
        $this->enviados[] = compact('destinatario', 'asunto', 'cuerpoTexto', 'cuerpoHtml');

        return true;
    }
}

/**
 * Registrarse con el correo de una cuenta ACTIVA: la respuesta es igual a la de
 * un registro nuevo (no se revela que el correo existe), a esa cuenta le llega
 * «Ya tienes una cuenta» y su clave no cambia. El aviso salió del controlador a
 * TokensCorreo::enviarCuentaExistente para la vista previa de Ajustes → Correos.
 */
#[RunTestsInSeparateProcesses]
class RegistroCuentaExistenteTest extends TestCase
{
    private CorreoAnotador $correo;

    protected function setUp(): void
    {
        parent::setUp();
        config(['emr.portal_reservas_url' => 'https://reservas.ejemplo.cl']);
        Esquema::olvidar();
        Schema::dropIfExists('usuarios');
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre')->nullable();
            $t->string('telefono')->nullable(); $t->string('rol'); $t->boolean('es_activo')->default(true);
            $t->string('password_hash')->nullable(); $t->text('permisos')->nullable();
            $t->integer('sesion_version')->default(0); $t->timestamp('created_at')->nullable();
        });
        DB::table('usuarios')->insert(['id' => 'u1', 'email' => 'daniela@x.cl', 'nombre' => 'Daniela Rojas',
            'rol' => 'dueño', 'password_hash' => 'hash-original']);

        $this->correo = new CorreoAnotador();
        $this->app->instance(Correo::class, $this->correo);
    }

    public function test_avisa_a_la_cuenta_real_sin_revelar_ni_tocar_nada(): void
    {
        $r = $this->postJson('/auth/register', [
            'email' => 'Daniela@X.cl', 'nombre' => 'Otra Persona', 'password' => 'UnaClave-Larga-2026!',
            'acepta_terminos' => true, 'acepta_privacidad' => true,
        ]);

        $r->assertOk();
        $this->assertStringContainsString('Te enviamos un correo', $r->json('mensaje'));
        $this->assertSame('hash-original', DB::table('usuarios')->where('id', 'u1')->value('password_hash'));
        $this->assertSame(1, DB::table('usuarios')->count());

        $this->assertCount(1, $this->correo->enviados);
        $m = $this->correo->enviados[0];
        $this->assertSame('daniela@x.cl', $m['destinatario']);
        $this->assertSame('Ya tienes una cuenta — PANGI', $m['asunto']);
        $this->assertStringContainsString('Hola Daniela', $m['cuerpoTexto']);
        $this->assertStringContainsString('https://reservas.ejemplo.cl/login', $m['cuerpoHtml']);
    }
}
