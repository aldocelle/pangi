<?php

namespace Tests\Feature;

use App\Services\Esquema;
use App\Services\Jwt;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * Fotos de perfil que sube el tutor: la de su mascota y la suya.
 *
 * Van a `uploads/`, que se sirve sin token, así que lo que fija este archivo
 * es lo que impide que eso sea un problema: nadie le cambia la foto a la
 * mascota de otro, lo que no es imagen no entra aunque se llame `.png`, el
 * nombre no lleva ningún id, y quitar la foto la borra del disco.
 */
#[RunTestsInSeparateProcesses]
class FotoPerfilTest extends TestCase
{
    private const SECRETO = 'secreto-de-test-con-largo-suficiente-para-hs256';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => self::SECRETO]);
        $this->dir = sys_get_temp_dir() . '/fotos-test-' . bin2hex(random_bytes(4));
        config(['emr.uploads_dir' => $this->dir]);
        Esquema::olvidar();

        foreach (['logs_actividad', 'citas', 'mascotas', 'usuarios'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre')->nullable();
            $t->string('telefono')->nullable(); $t->string('rol'); $t->boolean('es_activo')->default(true);
            $t->string('rut')->nullable(); $t->string('direccion')->nullable();
            $t->text('permisos')->nullable(); $t->string('password_hash')->nullable();
            $t->boolean('email_verificado')->default(true);
            $t->integer('sesion_version')->default(0);
            $t->boolean('privacy_accepted')->default(true);
            $t->boolean('marketing_consent')->default(false);
            $t->string('foto')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('mascotas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id'); $t->string('nombre');
            $t->string('especie')->nullable(); $t->string('raza')->nullable(); $t->string('sexo')->nullable();
            $t->date('fecha_nacimiento')->nullable(); $t->decimal('peso_kg', 5, 2)->nullable();
            $t->string('alergias')->nullable(); $t->string('microchip_codigo')->nullable();
            $t->string('foto_url')->nullable(); $t->boolean('esterilizado')->nullable();
            $t->string('estado')->nullable(); $t->timestamp('created_at')->nullable();
        });
        // Solo para que Clientes pueda contar las citas de cada tutor.
        Schema::create('citas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_dueno_id')->nullable();
        });
        Schema::create('logs_actividad', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id')->nullable(); $t->string('usuario_nombre')->nullable();
            $t->string('usuario_rol')->nullable();
            $t->string('accion')->nullable(); $t->string('area')->nullable(); $t->string('entidad')->nullable();
            $t->string('entidad_id')->nullable(); $t->text('detalle')->nullable(); $t->string('ip')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        DB::table('usuarios')->insert([
            ['id' => 'ana', 'email' => 'ana@x.cl', 'rol' => 'dueño'],
            ['id' => 'beto', 'email' => 'beto@x.cl', 'rol' => 'dueño'],
            ['id' => 'vet', 'email' => 'vet@x.cl', 'rol' => 'veterinario'],
        ]);
        DB::table('mascotas')->insert([
            'id' => 'luna', 'usuario_id' => 'ana', 'nombre' => 'Luna', 'especie' => 'perro',
            // Importada de ACVet: una URL ajena que no es nuestra para borrar.
            'foto_url' => 'https://acvet.example/fotos/luna.jpg',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function cabecera(string $id): array
    {
        $u = DB::table('usuarios')->where('id', $id)->first();
        $token = app(Jwt::class)->firmar(['sub' => $u->email, 'rol' => $u->rol, 'user_id' => $id]);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function subir(string $ruta, string $quien, UploadedFile $archivo)
    {
        return $this->withHeaders($this->cabecera($quien))->post($ruta, ['archivo' => $archivo]);
    }

    public function test_el_dueno_sube_la_foto_de_su_mascota_con_un_nombre_sin_ids(): void
    {
        $r = $this->subir('/mascotas/luna/foto', 'ana', UploadedFile::fake()->image('luna.jpg', 400, 400))
            ->assertOk();

        $url = $r->json('foto_url');
        $this->assertMatchesRegularExpression('#^/uploads/mf-[0-9a-f]{32}\.jpg$#', $url);
        $this->assertStringNotContainsString('luna', $url);
        $this->assertFileExists($this->dir . '/' . basename($url));
        // La URL de ACVet no se borró de ningún lado: no era nuestra.
    }

    public function test_otro_tutor_no_puede_cambiarle_la_foto(): void
    {
        $this->subir('/mascotas/luna/foto', 'beto', UploadedFile::fake()->image('x.jpg'))
            ->assertStatus(403);
        $this->assertSame('https://acvet.example/fotos/luna.jpg',
            DB::table('mascotas')->where('id', 'luna')->value('foto_url'));
    }

    public function test_un_archivo_que_no_es_imagen_no_entra_aunque_se_llame_png(): void
    {
        $falso = UploadedFile::fake()->createWithContent('foto.png', '<html><script>alert(1)</script></html>');
        $this->subir('/mascotas/luna/foto', 'ana', $falso)->assertStatus(400);
        $this->assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function test_formato_no_permitido(): void
    {
        $svg = UploadedFile::fake()->createWithContent('foto.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->subir('/mascotas/luna/foto', 'ana', $svg)->assertStatus(400);
    }

    public function test_cambiar_o_quitar_la_foto_borra_la_anterior_del_disco(): void
    {
        $primera = $this->subir('/mascotas/luna/foto', 'ana', UploadedFile::fake()->image('a.png'))->json('foto_url');
        $segunda = $this->subir('/mascotas/luna/foto', 'ana', UploadedFile::fake()->image('b.png'))->json('foto_url');
        $this->assertFileDoesNotExist($this->dir . '/' . basename($primera));
        $this->assertFileExists($this->dir . '/' . basename($segunda));

        $this->withHeaders($this->cabecera('ana'))->deleteJson('/mascotas/luna/foto')
            ->assertOk()->assertJsonPath('foto_url', null);
        $this->assertFileDoesNotExist($this->dir . '/' . basename($segunda));
    }

    public function test_el_tutor_sube_y_quita_su_propia_foto(): void
    {
        $r = $this->subir('/auth/me/foto', 'ana', UploadedFile::fake()->image('yo.webp'))->assertOk();
        $foto = $r->json('foto');
        $this->assertMatchesRegularExpression('#^/uploads/tu-[0-9a-f]{32}\.webp$#', $foto);

        $this->getJson('/auth/me', $this->cabecera('ana'))->assertJsonPath('foto', $foto);
        // Es SU foto: la cuenta de al lado no cambia.
        $this->getJson('/auth/me', $this->cabecera('beto'))->assertJsonPath('foto', null);

        $this->withHeaders($this->cabecera('ana'))->deleteJson('/auth/me/foto')
            ->assertOk()->assertJsonPath('foto', null);
        $this->assertFileDoesNotExist($this->dir . '/' . basename($foto));
    }

    public function test_el_equipo_ve_la_foto_del_tutor_en_clientes(): void
    {
        $foto = $this->subir('/auth/me/foto', 'ana', UploadedFile::fake()->image('yo.jpg'))->json('foto');

        $lista = $this->getJson('/clientes', $this->cabecera('vet'))->assertOk()->json('items');
        $porId = array_column($lista, 'foto', 'id');
        $this->assertSame($foto, $porId['ana']);
        // Quien no subió foto sale sin ella, y el panel pone la inicial.
        $this->assertNull($porId['beto']);

        $this->getJson('/clientes/ana', $this->cabecera('vet'))->assertOk()->assertJsonPath('foto', $foto);
    }

    public function test_sin_la_migracion_responde_503_y_me_sigue_funcionando(): void
    {
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('foto'));
        Esquema::olvidar();

        $this->subir('/auth/me/foto', 'ana', UploadedFile::fake()->image('yo.jpg'))->assertStatus(503);
        $this->getJson('/auth/me', $this->cabecera('ana'))->assertOk()->assertJsonPath('foto', null);
        // Clientes tampoco se cae: sale con la inicial.
        $this->getJson('/clientes/ana', $this->cabecera('vet'))->assertOk()->assertJsonPath('foto', null);
    }
}
