<?php

namespace Tests\Feature;

use App\Services\Jwt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * La pestaña Vacunas de la ficha en el portal: `GET /mascotas/{id}/preventivo`.
 *
 * Antes las dosis aplicadas solo las veía el equipo (`/protocolos/mascota/{id}/
 * estado`, permiso `personas`) y el tutor tenía un «próximamente». Lo que fija
 * este archivo es lo que hace seguro abrírselo: cada tutor ve solo lo suyo, las
 * notas internas de cada dosis no salen, y el estado es el MISMO que ve el
 * panel —no un segundo cálculo que pueda contradecirlo—.
 */
#[RunTestsInSeparateProcesses]
class VacunasTutorTest extends TestCase
{
    private const SECRETO = 'secreto-de-test-con-largo-suficiente-para-hs256';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => self::SECRETO]);

        foreach (['protocolo_aplicaciones', 'protocolos_preventivos', 'mascotas', 'usuarios'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre')->nullable();
            $t->string('rol'); $t->boolean('es_activo')->default(true); $t->text('permisos')->nullable();
            $t->string('password_hash')->nullable(); $t->boolean('email_verificado')->default(true);
            $t->integer('sesion_version')->default(0);
        });
        Schema::create('mascotas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id'); $t->string('nombre');
            $t->string('especie')->nullable(); $t->date('fecha_nacimiento')->nullable();
        });
        Schema::create('protocolos_preventivos', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->string('categoria')->nullable();
            $t->string('especie')->nullable(); $t->integer('frecuencia_meses'); $t->integer('edad_inicio_meses')->nullable();
            $t->integer('edad_fin_meses')->nullable(); $t->boolean('inicial')->default(false);
            $t->boolean('activo')->default(true); $t->integer('orden')->default(0);
        });
        Schema::create('protocolo_aplicaciones', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('protocolo_id'); $t->string('mascota_id');
            $t->string('fecha_aplicacion'); $t->string('proxima_fecha')->nullable();
            $t->text('notas')->nullable(); $t->string('registrado_por_id')->nullable();
        });

        DB::table('usuarios')->insert([
            ['id' => 'ana', 'email' => 'ana@x.cl', 'rol' => 'dueño', 'permisos' => null],
            ['id' => 'beto', 'email' => 'beto@x.cl', 'rol' => 'dueño', 'permisos' => null],
            ['id' => 'vet', 'email' => 'vet@x.cl', 'rol' => 'veterinario', 'permisos' => null],
            // Staff restringido a Caja: no es trabajo clínico, no ve fichas.
            ['id' => 'caja', 'email' => 'caja@x.cl', 'rol' => 'veterinario', 'permisos' => json_encode(['caja'])],
        ]);
        DB::table('mascotas')->insert([
            'id' => 'luna', 'usuario_id' => 'ana', 'nombre' => 'Luna', 'especie' => 'perro',
        ]);
        DB::table('protocolos_preventivos')->insert([
            ['id' => 'rab', 'nombre' => 'Antirrábica', 'categoria' => 'Vacuna', 'especie' => 'perro', 'frecuencia_meses' => 12, 'orden' => 1],
            ['id' => 'sex', 'nombre' => 'Séxtuple', 'categoria' => 'Vacuna', 'especie' => 'perro', 'frecuencia_meses' => 12, 'orden' => 2],
            ['id' => 'tri', 'nombre' => 'Triple felina', 'categoria' => 'Vacuna', 'especie' => 'gato', 'frecuencia_meses' => 12, 'orden' => 3],
        ]);
        $hoy = CarbonImmutable::now()->startOfDay();
        DB::table('protocolo_aplicaciones')->insert([
            ['id' => 'a1', 'protocolo_id' => 'rab', 'mascota_id' => 'luna',
                'fecha_aplicacion' => $hoy->subYears(2)->toDateString(), 'proxima_fecha' => $hoy->subYear()->toDateString(),
                'notas' => 'Lote 77. Mordió al practicante.', 'registrado_por_id' => 'vet'],
            ['id' => 'a2', 'protocolo_id' => 'rab', 'mascota_id' => 'luna',
                'fecha_aplicacion' => $hoy->subMonths(2)->toDateString(), 'proxima_fecha' => $hoy->addMonths(10)->toDateString(),
                'notas' => 'Lote 81', 'registrado_por_id' => 'vet'],
        ]);
    }

    private function cabecera(string $id): array
    {
        $u = DB::table('usuarios')->where('id', $id)->first();
        $token = app(Jwt::class)->firmar(['sub' => $u->email, 'rol' => $u->rol, 'user_id' => $id]);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_el_tutor_ve_el_estado_y_el_historial_de_su_mascota(): void
    {
        $r = $this->getJson('/mascotas/luna/preventivo', $this->cabecera('ana'))->assertOk();

        // Estado: solo los protocolos de su especie, con la última dosis.
        $estado = collect($r->json('estado'))->keyBy('protocolo_id');
        $this->assertEqualsCanonicalizing(['rab', 'sex'], $estado->keys()->all());
        $this->assertSame('al_dia', $estado['rab']['estado']);
        // Nunca aplicada en la clínica: «sin registro», no «vencida».
        $this->assertSame('pendiente', $estado['sex']['estado']);

        // Historial: todas las dosis, la más reciente primero.
        $this->assertSame(['a2', 'a1'], array_column($r->json('aplicaciones'), 'id'));
        $this->assertSame('Antirrábica', $r->json('aplicaciones.0.nombre'));
    }

    public function test_las_notas_internas_de_cada_dosis_no_le_llegan_al_tutor(): void
    {
        $r = $this->getJson('/mascotas/luna/preventivo', $this->cabecera('ana'))->assertOk();

        $this->assertStringNotContainsString('Lote', $r->getContent());
        $this->assertStringNotContainsString('practicante', $r->getContent());
        $this->assertArrayNotHasKey('notas', $r->json('aplicaciones.0'));
        $this->assertArrayNotHasKey('registrado_por_id', $r->json('aplicaciones.0'));
    }

    public function test_otro_tutor_no_ve_las_vacunas_ajenas(): void
    {
        $this->getJson('/mascotas/luna/preventivo', $this->cabecera('beto'))->assertStatus(403);
    }

    public function test_el_staff_clinico_si_y_el_de_caja_no(): void
    {
        $this->getJson('/mascotas/luna/preventivo', $this->cabecera('vet'))->assertOk();
        $this->getJson('/mascotas/luna/preventivo', $this->cabecera('caja'))->assertStatus(403);
    }

    public function test_el_estado_es_el_mismo_que_ve_el_panel(): void
    {
        $tutor = $this->getJson('/mascotas/luna/preventivo', $this->cabecera('ana'))->json('estado');
        $panel = $this->getJson('/protocolos/mascota/luna/estado', $this->cabecera('vet'))->assertOk()->json();

        $this->assertSame($panel, $tutor);
    }

    public function test_sin_token_401_y_mascota_inexistente_404(): void
    {
        $this->getJson('/mascotas/luna/preventivo')->assertStatus(401);
        $this->getJson('/mascotas/nadie/preventivo', $this->cabecera('ana'))->assertStatus(404);
    }
}
