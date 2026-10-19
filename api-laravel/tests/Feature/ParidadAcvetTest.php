<?php

namespace Tests\Feature;

use App\Services\Correo;
use App\Services\Esquema;
use App\Services\MensajeRecordatorio;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * Paridad con ACVet (oct-2026, dossier `analisis/funcionalidades-acvet.md`):
 * plantillas y variables de recordatorio, reglas de venta, puntos, márgenes,
 * bodegas, profilaxis inicial y exportación por dataset.
 *
 * Y lo que NO puede pasar: en modo pruebas no sale ningún recordatorio
 * (decisión de producto, 1-oct-2026) — el correo está prohibido en todo el archivo.
 */
#[RunTestsInSeparateProcesses]
class ParidadAcvetTest extends TestCase
{
    private const SECRETO = 'secreto-de-test-con-largo-suficiente-para-hs256';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => self::SECRETO, 'emr.correos_automaticos' => false]);
        Esquema::olvidar();
        // Nada de lo que se prueba acá puede mandar un correo.
        $this->mock(Correo::class)->shouldNotReceive('enviar');

        foreach (['usuarios', 'mascotas', 'recordatorios', 'plantillas_recordatorio', 'ajustes', 'logs_actividad',
            'items_inventario', 'transacciones', 'ventas', 'sesiones_caja', 'protocolos_preventivos',
            'protocolo_aplicaciones', 'especialistas'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre')->nullable();
            $t->string('telefono')->nullable(); $t->string('rol'); $t->boolean('es_activo')->default(true);
            $t->text('permisos')->nullable(); $t->string('password_hash')->nullable();
            $t->integer('puntos_fidelizacion')->default(0);
        });
        Schema::create('mascotas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id'); $t->string('nombre');
            $t->string('especie')->nullable(); $t->string('raza')->nullable(); $t->string('estado')->nullable();
            $t->string('fecha_nacimiento')->nullable();
        });
        Schema::create('recordatorios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('mascota_id'); $t->string('usuario_dueno_id')->nullable();
            $t->string('titulo'); $t->string('categoria')->nullable(); $t->text('mensaje')->nullable();
            $t->date('fecha_programada'); $t->integer('dias_repeticion')->nullable(); $t->string('estado');
            $t->string('enviado_at')->nullable(); $t->string('canal')->nullable(); $t->text('notas')->nullable();
            $t->string('creado_por_id')->nullable(); $t->string('created_at')->nullable();
        });
        Schema::create('plantillas_recordatorio', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('titulo'); $t->text('mensaje')->nullable();
            $t->integer('dias_repeticion')->nullable(); $t->integer('orden')->default(0);
            $t->string('created_at'); $t->string('updated_at')->nullable();
        });
        Schema::create('ajustes', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->text('data')->nullable();
        });
        Schema::create('logs_actividad', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id')->nullable(); $t->string('usuario_nombre');
            $t->string('usuario_rol')->nullable(); $t->string('accion'); $t->string('area');
            $t->string('entidad')->nullable(); $t->string('entidad_id')->nullable(); $t->text('detalle')->nullable();
            $t->string('created_at');
        });
        Schema::create('items_inventario', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->string('tipo'); $t->text('descripcion')->nullable();
            $t->string('unidad')->nullable(); $t->decimal('precio_costo')->default(0); $t->decimal('precio_venta')->default(0);
            $t->integer('stock_actual')->nullable(); $t->integer('stock_minimo')->default(0); $t->string('lote')->nullable();
            $t->string('fecha_vencimiento')->nullable(); $t->string('proveedor')->nullable();
            $t->string('bodega')->default('Principal');
            $t->boolean('uso_interno')->default(false); $t->boolean('activo')->default(true);
            $t->string('id_legado')->nullable(); $t->string('created_at')->nullable(); $t->string('updated_at')->nullable();
        });
        Schema::create('transacciones', function (Blueprint $t) {
            $t->string('id')->primary(); $t->integer('numero')->nullable(); $t->string('fecha');
            $t->string('creado_por_id')->nullable(); $t->string('creado_por_nombre')->nullable();
            $t->boolean('cuenta_en_caja')->default(true); $t->string('estado_venta')->nullable();
            $t->string('propietario_id')->nullable(); $t->string('especialista_id')->nullable();
            $t->string('documento_id')->nullable(); $t->decimal('total_pendiente')->default(0);
            foreach (['pago_efectivo', 'pago_debito', 'pago_credito', 'pago_transferencia', 'pago_otro'] as $c) {
                $t->decimal($c)->default(0);
            }
            $t->integer('puntos_ganados')->default(0); $t->integer('puntos_canjeados')->default(0);
            $t->decimal('monto_canje')->default(0); $t->string('created_at')->nullable();
        });
        Schema::create('ventas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('transaccion_id'); $t->string('fecha')->nullable();
            $t->string('item_id')->nullable(); $t->string('servicio_id')->nullable(); $t->string('propietario_id')->nullable();
            $t->string('mascota_id')->nullable(); $t->string('especialista_id')->nullable(); $t->string('nombre');
            $t->string('tipo')->nullable(); $t->integer('cantidad')->default(1); $t->decimal('precio_costo')->default(0);
            $t->decimal('precio_venta')->default(0); $t->decimal('descuento')->default(0);
            $t->integer('cantidad_devuelta')->default(0); $t->string('created_at')->nullable();
        });
        Schema::create('sesiones_caja', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('cerrada_at')->nullable();
        });
        Schema::create('protocolos_preventivos', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->string('categoria')->nullable();
            $t->string('especie')->nullable(); $t->integer('frecuencia_meses'); $t->integer('edad_inicio_meses')->nullable();
            $t->integer('edad_fin_meses')->nullable(); $t->boolean('inicial')->default(false);
            $t->text('descripcion')->nullable(); $t->boolean('activo')->default(true); $t->integer('orden')->default(0);
            $t->string('created_at')->nullable();
        });
        Schema::create('protocolo_aplicaciones', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('protocolo_id'); $t->string('mascota_id');
            $t->string('fecha_aplicacion'); $t->string('proxima_fecha')->nullable();
        });

        DB::table('usuarios')->insert([
            ['id' => 'adm', 'email' => 'adm@x.cl', 'nombre' => 'Marta', 'rol' => 'admin', 'password_hash' => 'x', 'puntos_fidelizacion' => 0],
            ['id' => 'vet', 'email' => 'vet@x.cl', 'nombre' => 'Carla', 'rol' => 'veterinario', 'password_hash' => 'x', 'puntos_fidelizacion' => 0],
            ['id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'email' => 'ana@x.cl', 'nombre' => 'Ana Pérez', 'rol' => 'dueño', 'password_hash' => 'SECRETO', 'puntos_fidelizacion' => 30],
        ]);
        DB::table('mascotas')->insert(['id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'usuario_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'nombre' => 'Luna', 'especie' => 'perro',
            'raza' => 'Mestiza', 'fecha_nacimiento' => CarbonImmutable::now()->subMonths(18)->format('Y-m-d')]);
        DB::table('items_inventario')->insert(['id' => 'cccccccc-0000-4000-8000-000000000003', 'nombre' => 'Pipeta', 'tipo' => 'Farmaco',
            'precio_costo' => 9000, 'precio_venta' => 10000, 'stock_actual' => 2]);
    }

    private function como(string $id, string $rol)
    {
        $token = JWT::encode(['sub' => "{$id}@x.cl", 'rol' => $rol, 'user_id' => $id, 'exp' => time() + 3600],
            self::SECRETO, 'HS256');

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function admin()
    {
        return $this->como('adm', 'admin');
    }

    private function venta(array $extra = [], array $linea = [])
    {
        return $this->admin()->postJson('/transacciones', array_merge([
            'propietario_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'pago_efectivo' => 10000,
            'ventas' => [array_merge(['nombre' => 'Pipeta', 'item_id' => 'cccccccc-0000-4000-8000-000000000003', 'cantidad' => 1,
                'precio_venta' => 10000, 'mascota_id' => 'bbbbbbbb-0000-4000-8000-000000000002'], $linea)],
        ], $extra));
    }

    // ── Recordatorios ────────────────────────────────────────────────────

    public function test_las_variables_de_acvet_se_resuelven_incluidos_los_alias_cortos(): void
    {
        $t = MensajeRecordatorio::resolver('Hola @propietario_nombre: @mascota_nombre (@mascota) tiene control el @fecha. @propietario',
            (object) ['nombre' => 'Luna'], (object) ['nombre' => 'Ana'], '2026-10-12');

        $this->assertSame('Hola Ana: Luna (Luna) tiene control el 12-10-2026. Ana', $t);
    }

    public function test_plantilla_crud_y_vista_previa_con_firma(): void
    {
        $p = $this->admin()->postJson('/recordatorios/plantillas', ['titulo' => 'Vacuna antirrábica',
            'mensaje' => 'A @mascota_nombre le toca su vacuna el @fecha.', 'dias_repeticion' => 365])
            ->assertCreated()->json();
        $this->assertSame(365, $p['dias_repeticion']);
        $this->admin()->getJson('/recordatorios/plantillas')->assertOk()->assertJsonCount(1);

        $this->admin()->putJson('/configuracion/recordatorios', ['firma' => 'Para mayor información contacte a su médico veterinario'])
            ->assertOk();
        $v = $this->admin()->postJson('/recordatorios/vista-previa', ['titulo' => $p['titulo'], 'mensaje' => $p['mensaje']])
            ->assertOk()->json();
        $this->assertStringContainsString('A Luna le toca su vacuna el', $v['cuerpo']);
        $this->assertStringContainsString('Para mayor información contacte a su médico veterinario', $v['cuerpo']);
        $this->assertStringNotContainsString('@', $v['cuerpo']);

        $this->admin()->deleteJson("/recordatorios/plantillas/{$p['id']}")->assertNoContent();
    }

    public function test_al_marcar_ya_vino_nace_el_siguiente_recordatorio(): void
    {
        DB::table('recordatorios')->insert(['id' => 'r1', 'mascota_id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'usuario_dueno_id' => 'aaaaaaaa-0000-4000-8000-000000000001',
            'titulo' => 'Vacuna', 'fecha_programada' => '2026-09-01', 'dias_repeticion' => 365, 'estado' => 'pendiente']);

        $r = $this->admin()->patchJson('/recordatorios/r1', ['estado' => 'completado'])->assertOk()->json();

        $sig = DB::table('recordatorios')->where('id', $r['siguiente_id'])->first();
        $this->assertSame(CarbonImmutable::now()->addDays(365)->format('Y-m-d'), substr($sig->fecha_programada, 0, 10));
        $this->assertSame('pendiente', $sig->estado);
        // Marcarlo otra vez no crea un tercero.
        $this->admin()->patchJson('/recordatorios/r1', ['estado' => 'completado'])->assertOk();
        $this->assertSame(2, DB::table('recordatorios')->count());
    }

    public function test_en_modo_pruebas_el_envio_masivo_solo_cuenta_y_no_manda_nada(): void
    {
        DB::table('recordatorios')->insert(['id' => 'r1', 'mascota_id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'usuario_dueno_id' => 'aaaaaaaa-0000-4000-8000-000000000001',
            'titulo' => 'Vacuna', 'fecha_programada' => CarbonImmutable::now()->subDays(5)->format('Y-m-d'),
            'estado' => 'pendiente']);

        // Aunque pida enviar de verdad (`simular: false`).
        $r = $this->admin()->postJson('/recordatorios/envio-masivo', ['simular' => false])->assertOk()->json();

        $this->assertTrue($r['simulado']);
        $this->assertFalse($r['envio_habilitado']);
        $this->assertSame(1, $r['enviables']);
        $this->assertSame('pendiente', DB::table('recordatorios')->value('estado'));
    }

    public function test_resumen_de_envios_por_canal(): void
    {
        DB::table('recordatorios')->insert([
            ['id' => 'e1', 'mascota_id' => 'm', 'titulo' => 'A', 'fecha_programada' => '2026-09-01', 'estado' => 'enviado',
                'canal' => 'email', 'enviado_at' => '2026-09-10 10:00:00'],
            ['id' => 'e2', 'mascota_id' => 'm', 'titulo' => 'B', 'fecha_programada' => '2026-09-01', 'estado' => 'enviado',
                'canal' => null, 'enviado_at' => '2026-09-11 10:00:00'],
            ['id' => 'e3', 'mascota_id' => 'm', 'titulo' => 'C', 'fecha_programada' => '2026-09-01', 'estado' => 'enviado',
                'canal' => 'email', 'enviado_at' => '2026-08-01 10:00:00'],
        ]);

        $r = $this->admin()->getJson('/recordatorios/resumen-envios?desde=2026-09-01&hasta=2026-09-30')->assertOk()->json();

        $this->assertSame(['email' => 1, 'sin canal' => 1], $r['por_canal']);
        $this->assertSame(2, $r['total']);
    }

    // ── Configuración ────────────────────────────────────────────────────

    public function test_la_configuracion_no_sale_por_el_endpoint_publico_ni_el_hash_por_el_panel(): void
    {
        $this->admin()->putJson('/configuracion/ventas', ['clave_descuento' => '4321', 'clave_descuentos' => true])->assertOk();

        $publico = $this->getJson('/catalogo/ajustes')->assertOk()->json();
        foreach (['ventas', 'recordatorios', 'impresion'] as $k) {
            $this->assertArrayNotHasKey($k, $publico);
        }
        $panel = $this->como('vet', 'veterinario')->getJson('/configuracion/ventas')->assertOk()->json();
        $this->assertTrue($panel['tiene_clave_descuento']);
        $this->assertArrayNotHasKey('clave_descuento_hash', $panel);
        $this->assertStringNotContainsString('4321', DB::table('logs_actividad')->pluck('detalle')->implode(' '));
    }

    public function test_las_reglas_de_venta_las_cambia_solo_un_admin(): void
    {
        $this->como('vet', 'veterinario')->putJson('/configuracion/ventas', ['exigir_paciente' => true])->assertForbidden();
        $this->admin()->putJson('/configuracion/ventas', ['inventada' => 1])->assertUnprocessable();
    }

    // ── Reglas de venta ──────────────────────────────────────────────────

    public function test_por_defecto_ninguna_regla_frena_la_venta(): void
    {
        $this->venta([], ['mascota_id' => null, 'cantidad' => 5, 'precio_venta' => 2000])->assertCreated();
    }

    public function test_exigir_paciente_caja_abierta_y_stock(): void
    {
        $this->admin()->putJson('/configuracion/ventas', ['exigir_paciente' => true, 'exigir_caja_abierta' => true,
            'no_vender_sin_stock' => true])->assertOk();

        $this->venta([], ['mascota_id' => null])->assertStatus(400)->assertJsonPath('detail', fn ($d) => str_contains($d, 'paciente'));
        $this->venta()->assertStatus(400)->assertJsonPath('detail', fn ($d) => str_contains($d, 'caja'));
        DB::table('sesiones_caja')->insert(['id' => 'c1']);
        $this->venta([], ['cantidad' => 3, 'precio_venta' => 3000, 'descuento' => 0], )
            ->assertStatus(400)->assertJsonPath('detail', fn ($d) => str_contains($d, 'Pipeta (hay 2, se piden 3)'));
        $this->venta()->assertCreated();
    }

    public function test_descuento_con_clave(): void
    {
        $this->admin()->putJson('/configuracion/ventas', ['clave_descuento' => '4321', 'clave_descuentos' => true])->assertOk();

        $this->venta(['pago_efectivo' => 9000], ['descuento' => 1000])->assertForbidden();
        $this->venta(['pago_efectivo' => 9000, 'clave_descuento' => 'mala'], ['descuento' => 1000])->assertForbidden();
        $this->venta(['pago_efectivo' => 9000, 'clave_descuento' => '4321'], ['descuento' => 1000])->assertCreated();
    }

    public function test_puntos_se_ganan_y_se_canjean_sin_pasar_el_saldo(): void
    {
        $this->admin()->putJson('/configuracion/ventas', ['puntos_activo' => true, 'pesos_por_punto' => 1000,
            'valor_punto' => 100])->assertOk();

        // 30 puntos × $100 = $3.000 de rebaja; paga $7.000 → gana 7.
        $t = $this->venta(['pago_efectivo' => 7000, 'puntos_canjeados' => 30])->assertCreated()->json();
        $this->assertSame(7, $t['puntos_ganados']);
        $this->assertSame(7, (int) DB::table('usuarios')->where('id', 'aaaaaaaa-0000-4000-8000-000000000001')->value('puntos_fidelizacion'));

        $this->venta(['pago_efectivo' => 0, 'puntos_canjeados' => 99])->assertStatus(400);
    }

    // ── Inventario ───────────────────────────────────────────────────────

    public function test_margen_bajo_y_stock_solo_admin(): void
    {
        $this->admin()->putJson('/configuracion/ventas', ['margen_minimo' => 30, 'reiniciar_stock_solo_admin' => true,
            'margenes_por_tipo' => [['tipo' => 'Alimento', 'margen' => 5]]])->assertOk();

        $it = collect($this->admin()->getJson('/inventario')->assertOk()->json('items'))->firstWhere('id', 'cccccccc-0000-4000-8000-000000000003');
        $this->assertSame(10.0, (float) $it['margen_pct']);
        $this->assertTrue($it['margen_bajo']);
        $this->assertSame('Principal', $it['bodega']);

        $this->como('vet', 'veterinario')->patchJson('/inventario/cccccccc-0000-4000-8000-000000000003', ['stock_actual' => 0])->assertForbidden();
        $this->como('vet', 'veterinario')->patchJson('/inventario/cccccccc-0000-4000-8000-000000000003', ['precio_venta' => 15000])->assertOk();
        $this->admin()->patchJson('/inventario/cccccccc-0000-4000-8000-000000000003', ['stock_actual' => 0, 'bodega' => 'Farmacia'])->assertOk()
            ->assertJsonPath('bodega', 'Farmacia');
    }

    // ── Protocolos ───────────────────────────────────────────────────────

    public function test_profilaxis_inicial_no_aplica_pasado_el_año(): void
    {
        DB::table('protocolos_preventivos')->insert([
            ['id' => 'p1', 'nombre' => 'Puppy DP', 'especie' => 'perro', 'frecuencia_meses' => 1, 'inicial' => true],
            ['id' => 'p2', 'nombre' => 'Antirrábica', 'especie' => 'perro', 'frecuencia_meses' => 12, 'inicial' => false],
        ]);

        $estado = $this->admin()->getJson('/protocolos/mascota/bbbbbbbb-0000-4000-8000-000000000002/estado')->assertOk()->json();

        $this->assertSame(['Antirrábica'], array_column($estado, 'nombre'));
    }

    // ── Exportación ──────────────────────────────────────────────────────

    public function test_dataset_paginado_sin_columnas_secretas_y_auditado(): void
    {
        $lista = collect($this->admin()->getJson('/exportacion/datasets')->assertOk()->json());
        $this->assertSame(1, $lista->firstWhere('clave', 'propietarios')['total']);

        $csv = $this->admin()->get('/exportacion/dataset/propietarios?pagina=1')->assertOk()->getContent();

        $this->assertStringContainsString('Ana Pérez', $csv);
        $this->assertStringNotContainsString('password_hash', $csv);
        $this->assertStringNotContainsString('SECRETO', $csv);
        $this->assertStringNotContainsString('Marta', $csv);   // staff no es propietario
        $this->assertSame('dataset', DB::table('logs_actividad')->where('accion', 'exportar')->value('entidad'));
        $this->como('vet', 'veterinario')->get('/exportacion/dataset/propietarios')->assertForbidden();
    }
}
