<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * GET /fichas/mis-documentos alimenta la sección Documentos del portal del
 * cliente. Lo que no puede pasar: que un tutor vea documentos de otro, que el
 * listado cree fichas vacías, o que falte lo que el portal usa para abrirlos.
 */
#[RunTestsInSeparateProcesses]
class MisDocumentosTest extends TestCase
{
    private const SECRETO = 'secreto-de-test-con-largo-suficiente-para-hs256';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => self::SECRETO]);

        foreach (['documentos_clinicos', 'fichas_clinicas', 'mascotas', 'usuarios'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre')->nullable();
            $t->string('rol'); $t->boolean('es_activo')->default(true); $t->text('permisos')->nullable();
        });
        Schema::create('mascotas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id'); $t->string('nombre');
            $t->string('especie')->nullable(); $t->string('estado')->nullable();
        });
        Schema::create('fichas_clinicas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('mascota_id');
        });
        Schema::create('documentos_clinicos', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('ficha_id'); $t->string('nota_id')->nullable();
            $t->string('tipo'); $t->string('descripcion')->nullable(); $t->string('nombre_original');
            $t->string('nombre_archivo'); $t->string('mime_type')->nullable(); $t->integer('tamano_bytes')->nullable();
            $t->string('subido_por_id')->nullable(); $t->timestamp('created_at')->nullable();
        });

        DB::table('usuarios')->insert([
            ['id' => 'ana', 'email' => 'ana@x.cl', 'rol' => 'dueño'],
            ['id' => 'beto', 'email' => 'beto@x.cl', 'rol' => 'dueño'],
        ]);
        DB::table('mascotas')->insert([
            ['id' => 'luna', 'usuario_id' => 'ana', 'nombre' => 'Luna', 'especie' => 'Felino', 'estado' => 'Vivo'],
            ['id' => 'max', 'usuario_id' => 'ana', 'nombre' => 'Max', 'especie' => 'Canino', 'estado' => 'Fallecido'],
            ['id' => 'sin-ficha', 'usuario_id' => 'ana', 'nombre' => 'Kira', 'especie' => 'Canino', 'estado' => 'Vivo'],
            ['id' => 'toby', 'usuario_id' => 'beto', 'nombre' => 'Toby', 'especie' => 'Canino', 'estado' => 'Vivo'],
        ]);
        DB::table('fichas_clinicas')->insert([
            ['id' => 'f-luna', 'mascota_id' => 'luna'],
            ['id' => 'f-max', 'mascota_id' => 'max'],
            ['id' => 'f-toby', 'mascota_id' => 'toby'],
        ]);
        $doc = fn ($id, $ficha, $fecha, $tipo = 'examen') => [
            'id' => $id, 'ficha_id' => $ficha, 'tipo' => $tipo, 'nombre_original' => "{$id}.pdf",
            'nombre_archivo' => "{$id}-x.pdf", 'mime_type' => 'application/pdf', 'tamano_bytes' => 1000,
            'created_at' => $fecha,
        ];
        DB::table('documentos_clinicos')->insert([
            $doc('perfil-luna', 'f-luna', '2026-09-01 10:00:00'),
            $doc('rx-max', 'f-max', '2026-09-05 10:00:00', 'rx'),
            $doc('de-beto', 'f-toby', '2026-09-10 10:00:00'),
        ]);
    }

    private function como(string $id, string $email)
    {
        $token = JWT::encode(
            ['sub' => $email, 'rol' => 'dueño', 'user_id' => $id, 'exp' => time() + 3600],
            self::SECRETO, 'HS256'
        );

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_solo_los_de_sus_mascotas_y_los_mas_nuevos_primero(): void
    {
        $ids = array_column($this->como('ana', 'ana@x.cl')->getJson('/fichas/mis-documentos')
            ->assertOk()->json(), 'id');

        // Incluye la mascota fallecida; no incluye nada de Beto.
        $this->assertSame(['rx-max', 'perfil-luna'], $ids);
        $this->assertSame(['de-beto'], array_column(
            $this->como('beto', 'beto@x.cl')->getJson('/fichas/mis-documentos')->json(), 'id'));
    }

    public function test_trae_lo_que_el_portal_usa(): void
    {
        $d = $this->como('ana', 'ana@x.cl')->getJson('/fichas/mis-documentos')->json()[1];

        $this->assertSame('luna', $d['mascota_id']);
        $this->assertSame('Luna', $d['mascota_nombre']);
        $this->assertSame('examen', $d['tipo']);
        $this->assertSame('/fichas/documentos/perfil-luna/archivo', $d['url']);
        // El nombre del archivo en disco no sale: no le sirve al tutor.
        $this->assertArrayNotHasKey('nombre_archivo', $d);
    }

    public function test_listar_no_crea_fichas(): void
    {
        $this->como('ana', 'ana@x.cl')->getJson('/fichas/mis-documentos')->assertOk();

        $this->assertSame(0, DB::table('fichas_clinicas')->where('mascota_id', 'sin-ficha')->count());
    }

    public function test_sin_sesion_no_hay_nada(): void
    {
        $this->getJson('/fichas/mis-documentos')->assertStatus(401);
    }
}
