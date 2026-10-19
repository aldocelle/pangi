<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * POST /internal/whatsapp/resolve-user no reconocía a un cliente ya existente cuando
 * su teléfono estaba guardado con espacios, guiones o "+" — la reserva web
 * y el alta manual del staff solo hacen `trim()`, a diferencia del número
 * que manda Meta, que siempre llega limpio. El fallback intentaba crear una
 * cuenta nueva con el mismo correo sintético `whatsapp-{telefono}@whatsapp.local`
 * que ya existía y violaba el UNIQUE de `usuarios.email`: la persona veía
 * "Ya existe un usuario con ese correo" en vez de ser reconocida.
 */
#[RunTestsInSeparateProcesses]
class ResolverIdentidadWhatsappTest extends TestCase
{
    private const CLAVE = 'clave-de-prueba-service';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('SERVICE_API_KEY=' . self::CLAVE);
        $_ENV['SERVICE_API_KEY'] = self::CLAVE;
        $_SERVER['SERVICE_API_KEY'] = self::CLAVE;

        Schema::dropIfExists('usuarios');
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('email')->unique();
            $t->string('nombre')->nullable();
            $t->string('telefono')->nullable();
            $t->string('rol')->default('dueño');
            $t->string('password_hash');
            $t->boolean('es_activo')->default(true);
            $t->boolean('email_verificado')->default(false);
            $t->boolean('terms_accepted')->default(false);
            $t->timestamp('created_at')->nullable();
        });
    }

    private function postInterno(string $uri, array $body)
    {
        return $this->withHeaders(['X-Service-Key' => self::CLAVE])->postJson($uri, $body);
    }

    public function test_reconoce_telefono_guardado_con_espacios_y_mas(): void
    {
        $id = (string) Str::uuid();
        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => 'pedro@example.com',
            'nombre' => 'Pedro',
            'telefono' => '+56 9 8790 1080',
            'password_hash' => 'x',
        ]);

        $resp = $this->postInterno('/internal/whatsapp/resolve-user', ['telefono' => '56987901080']);

        $resp->assertOk();
        $this->assertSame($id, $resp->json('usuario_id'));
        $this->assertSame('pedro@example.com', $resp->json('email'));
        $this->assertSame(1, DB::table('usuarios')->count());
    }

    public function test_si_el_telefono_no_calza_pero_el_correo_sintetico_ya_existe_no_duplica(): void
    {
        $id = (string) Str::uuid();
        DB::table('usuarios')->insert([
            'id' => $id,
            // Lo dejó una cuenta WhatsApp previa; el teléfono quedó en un
            // formato tan roto que ninguna variante normalizada lo alcanza.
            'email' => 'whatsapp-56987901080@whatsapp.local',
            'nombre' => 'Cliente WhatsApp',
            'telefono' => '987901080-viejo',
            'password_hash' => 'x',
        ]);

        $resp = $this->postInterno('/internal/whatsapp/resolve-user', ['telefono' => '56987901080']);

        $resp->assertOk();
        $this->assertSame($id, $resp->json('usuario_id'));
        $this->assertSame(1, DB::table('usuarios')->count());
    }
}
