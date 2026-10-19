<?php

namespace Tests\Feature;

use App\Services\Esquema;
use App\Services\Jwt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * «Pagos y facturas» del portal: GET /pagos/mios.
 *
 * Lo que se fija: cada tutor ve solo lo suyo (también las ventas de ACVet,
 * que traen el dueño en la línea y no en la cabecera), no ve borradores ni
 * anuladas ni las notas internas de `documento_id`, el saldo pendiente sale
 * de las compras vivas, y un abono pendiente solo aparece si la hora sigue
 * en pie y por venir.
 */
#[RunTestsInSeparateProcesses]
class MisPagosTest extends TestCase
{
    private const SECRETO = 'secreto-de-test-con-largo-suficiente-para-hs256';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.jwt_secret' => self::SECRETO]);
        Esquema::olvidar();

        foreach (['logs_actividad', 'ventas', 'transacciones', 'citas', 'servicios', 'especialistas', 'mascotas', 'usuarios'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre')->nullable();
            $t->string('telefono')->nullable(); $t->string('rut')->nullable(); $t->string('rol'); $t->boolean('es_activo')->default(true);
            $t->text('permisos')->nullable(); $t->string('password_hash')->nullable();
            $t->boolean('email_verificado')->default(true); $t->integer('sesion_version')->default(0);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('mascotas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id'); $t->string('nombre'); $t->string('especie')->nullable();
        });
        Schema::create('especialistas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre');
        });
        Schema::create('servicios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre');
        });
        // Con los nombres REALES de `citas` (migracion/esquema.sql): la primera
        // versión de este test usaba `usuario_id`, pasaba en verde y el
        // endpoint daba 500 en el lab, donde la columna es `usuario_dueno_id`.
        Schema::create('citas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_dueno_id'); $t->string('mascota_id')->nullable();
            $t->string('servicio_id')->nullable(); $t->string('servicio')->nullable();
            $t->string('fecha_hora'); $t->string('estado');
            $t->string('payment_status')->nullable(); $t->integer('monto_abono_clp')->nullable();
            $t->integer('monto_total_clp')->nullable(); $t->string('pago_modalidad')->nullable();
            $t->string('pago_referencia')->nullable(); $t->string('pago_actualizado_at')->nullable();
            $t->string('pago_modalidad_requerida')->nullable(); $t->string('created_at')->nullable();
            $t->string('pago_enlace_at')->nullable();
        });
        Schema::create('transacciones', function (Blueprint $t) {
            $t->string('id')->primary(); $t->integer('numero')->nullable(); $t->string('fecha');
            $t->string('creado_por_nombre')->nullable(); $t->boolean('cuenta_en_caja')->default(true);
            $t->string('estado_venta')->nullable(); $t->string('propietario_id')->nullable();
            $t->string('especialista_id')->nullable(); $t->string('documento_id')->nullable();
            $t->decimal('total_pendiente')->default(0);
            foreach (['pago_efectivo', 'pago_debito', 'pago_credito', 'pago_transferencia', 'pago_otro'] as $c) {
                $t->decimal($c)->default(0);
            }
            $t->string('anulada_at')->nullable(); $t->string('anulada_por_nombre')->nullable();
            $t->string('motivo_anulacion')->nullable();
        });
        Schema::create('ventas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('transaccion_id')->nullable(); $t->string('fecha')->nullable();
            $t->string('item_id')->nullable(); $t->string('servicio_id')->nullable(); $t->string('propietario_id')->nullable();
            $t->string('mascota_id')->nullable(); $t->string('especialista_id')->nullable(); $t->string('nombre');
            $t->string('tipo')->nullable(); $t->integer('cantidad')->default(1); $t->decimal('precio_costo')->default(0);
            $t->decimal('precio_venta')->default(0); $t->decimal('descuento')->default(0);
            $t->integer('cantidad_devuelta')->default(0); $t->string('devuelta_at')->nullable();
            $t->string('devuelta_por_nombre')->nullable(); $t->string('motivo_devolucion')->nullable();
            $t->string('created_at')->nullable();
        });

        DB::table('usuarios')->insert([
            ['id' => 'ana', 'email' => 'ana@x.cl', 'nombre' => 'Ana', 'rol' => 'dueño'],
            ['id' => 'beto', 'email' => 'beto@x.cl', 'nombre' => 'Beto', 'rol' => 'dueño'],
        ]);
        DB::table('mascotas')->insert([
            ['id' => 'luna', 'usuario_id' => 'ana', 'nombre' => 'Luna'],
            ['id' => 'rex', 'usuario_id' => 'beto', 'nombre' => 'Rex'],
        ]);
        DB::table('servicios')->insert(['id' => 'derm', 'nombre' => 'Consulta Dermatológica']);

        $tx = fn (array $d) => DB::table('transacciones')->insert($d + [
            'fecha' => '2026-10-01 10:00:00', 'estado_venta' => 'confirmada',
        ]);
        $linea = fn (array $d) => DB::table('ventas')->insert($d + ['tipo' => 'Farmaco', 'cantidad' => 1]);

        // Ana: pagada con débito, nota interna en documento_id.
        $tx(['id' => 't1', 'numero' => 1, 'propietario_id' => 'ana', 'pago_debito' => 30000,
            'documento_id' => 'cliente difícil, revisar']);
        $linea(['id' => 'v1', 'transaccion_id' => 't1', 'mascota_id' => 'luna', 'nombre' => 'Apoquel 16 mg', 'precio_venta' => 30000]);
        // Ana: parcial, quedan 5.000.
        $tx(['id' => 't2', 'numero' => 2, 'propietario_id' => 'ana', 'pago_efectivo' => 10000, 'total_pendiente' => 5000,
            'fecha' => '2026-10-05 10:00:00']);
        $linea(['id' => 'v2', 'transaccion_id' => 't2', 'nombre' => 'Consulta', 'tipo' => 'Clinico', 'precio_venta' => 15000]);
        // Ana, de ACVet: el dueño solo en la línea.
        $tx(['id' => 't3', 'numero' => 3, 'pago_efectivo' => 8000, 'fecha' => '2025-03-01 10:00:00']);
        $linea(['id' => 'v3', 'transaccion_id' => 't3', 'propietario_id' => 'ana', 'nombre' => 'Shampoo', 'precio_venta' => 8000]);
        // Ana: borrador y anulada, que no se muestran.
        $tx(['id' => 't4', 'numero' => 4, 'propietario_id' => 'ana', 'estado_venta' => 'borrador', 'total_pendiente' => 99000]);
        $tx(['id' => 't5', 'numero' => 5, 'propietario_id' => 'ana', 'estado_venta' => 'anulada', 'pago_efectivo' => 1000]);
        // Beto.
        $tx(['id' => 't6', 'numero' => 6, 'propietario_id' => 'beto', 'total_pendiente' => 70000]);
        $linea(['id' => 'v6', 'transaccion_id' => 't6', 'mascota_id' => 'rex', 'nombre' => 'Cirugía', 'precio_venta' => 70000]);

        $futuro = CarbonImmutable::now('America/Santiago')->addDays(3)->format('Y-m-d H:i:s');
        $pasado = CarbonImmutable::now('America/Santiago')->subDays(3)->format('Y-m-d H:i:s');
        $hace = fn (int $min) => CarbonImmutable::now('UTC')->subMinutes($min)->format('Y-m-d H:i:s');
        $cita = fn (array $d) => DB::table('citas')->insert($d + [
            'usuario_dueno_id' => 'ana', 'mascota_id' => 'luna', 'servicio_id' => 'derm', 'monto_abono_clp' => 22500,
            // Reserva del portal recién hecha: dentro de su plazo de 15 min.
            'pago_modalidad_requerida' => 'abono', 'created_at' => $hace(2),
        ]);
        $cita(['id' => 'c-pagada', 'fecha_hora' => $pasado, 'estado' => 'Confirmada', 'payment_status' => 'approved',
            'pago_referencia' => 'REF-1']);
        $cita(['id' => 'c-por-pagar', 'fecha_hora' => $futuro, 'estado' => 'Agendada', 'payment_status' => 'pending']);
        // Del portal, sin pagar y con el plazo vencido: la hora ya se libera.
        $cita(['id' => 'c-plazo-vencido', 'fecha_hora' => $futuro, 'estado' => 'Agendada', 'payment_status' => 'pending',
            'created_at' => $hace(30)]);
        // Coordinada por la clínica, agendada hace días y sin pagar: TAMBIÉN
        // vence (decisión de producto, 12-oct). Es el caso que se vio en pruebas.
        $cita(['id' => 'c-coordinada', 'fecha_hora' => $futuro, 'estado' => 'Agendada', 'payment_status' => 'pending',
            'pago_modalidad_requerida' => null, 'created_at' => $hace(60 * 24 * 2)]);
        // Coordinada con el enlace recién mandado: su plazo corre desde ahí.
        $cita(['id' => 'c-enlace-reciente', 'fecha_hora' => $futuro, 'estado' => 'Agendada', 'payment_status' => 'pending',
            'pago_modalidad_requerida' => null, 'created_at' => $hace(60 * 24 * 2), 'pago_enlace_at' => $hace(3)]);
        $cita(['id' => 'c-cancelada', 'fecha_hora' => $futuro, 'estado' => 'Cancelada', 'payment_status' => 'pending']);
        $cita(['id' => 'c-vencida', 'fecha_hora' => $pasado, 'estado' => 'Agendada', 'payment_status' => 'pending']);
        // Importada: sin `servicio_id`, con el nombre como texto.
        $cita(['id' => 'c-importada', 'servicio_id' => null, 'servicio' => 'Peluquería', 'fecha_hora' => $pasado,
            'estado' => 'Confirmada', 'payment_status' => 'approved']);
        $cita(['id' => 'c-beto', 'usuario_dueno_id' => 'beto', 'fecha_hora' => $futuro, 'estado' => 'Agendada', 'payment_status' => 'pending']);
    }

    private function cabecera(string $id): array
    {
        $u = DB::table('usuarios')->where('id', $id)->first();
        $token = app(Jwt::class)->firmar(['sub' => $u->email, 'rol' => $u->rol, 'user_id' => $id]);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_sin_sesion_no_hay_nada(): void
    {
        $this->getJson('/pagos/mios')->assertStatus(401);
    }

    public function test_el_tutor_ve_sus_compras_vivas_incluidas_las_de_acvet_y_nada_de_otro(): void
    {
        $r = $this->getJson('/pagos/mios', $this->cabecera('ana'))->assertOk();

        $this->assertSame(['t2', 't1', 't3'], array_column($r->json('compras'), 'id'));
        $this->assertSame(5000, (int) $r->json('resumen.pendiente'));
        $this->assertSame('parcial', $r->json('compras.0.estado'));
        $this->assertSame([['medio' => 'Débito', 'monto' => 30000]],
            array_map(fn ($m) => ['medio' => $m['medio'], 'monto' => (int) $m['monto']], $r->json('compras.1.medios')));
        $this->assertSame('Luna', $r->json('compras.1.lineas.0.mascota'));
    }

    public function test_no_sale_ninguna_nota_interna_ni_datos_del_mostrador(): void
    {
        $cuerpo = $this->getJson('/pagos/mios', $this->cabecera('ana'))->getContent();

        $this->assertStringNotContainsString('documento_id', $cuerpo);
        $this->assertStringNotContainsString('cliente difícil', $cuerpo);
        $this->assertStringNotContainsString('precio_costo', $cuerpo);
        $this->assertStringNotContainsString('Cirugía', $cuerpo);
    }

    /** Lo que se vio en pruebas el 12-oct: «Pagar» sobre una hora que ya debía estar liberada. */
    public function test_abonos_pagados_y_solo_los_pendientes_que_todavia_se_pueden_pagar(): void
    {
        $abonos = $this->getJson('/pagos/mios', $this->cabecera('ana'))->assertOk()->json('abonos');
        $ids = array_column($abonos, 'cita_id');
        sort($ids);

        $this->assertSame(['c-enlace-reciente', 'c-importada', 'c-pagada', 'c-por-pagar'], $ids);
        $porId = array_column($abonos, null, 'cita_id');
        $this->assertTrue($porId['c-pagada']['pagado']);
        $this->assertSame('REF-1', $porId['c-pagada']['referencia']);
        $this->assertFalse($porId['c-por-pagar']['pagado']);
        $this->assertSame(22500, $porId['c-por-pagar']['monto']);
        $this->assertSame('Consulta Dermatológica', $porId['c-pagada']['servicio']);
        $this->assertSame('Peluquería', $porId['c-importada']['servicio']);
        // La cuenta regresiva: solo la del portal dentro de su plazo.
        $this->assertNotNull($porId['c-por-pagar']['pagar_hasta']);
        $this->assertNotNull($porId['c-enlace-reciente']['pagar_hasta']);
        $this->assertNull($porId['c-pagada']['pagar_hasta']);
    }

    public function test_otro_tutor_ve_lo_suyo(): void
    {
        $r = $this->getJson('/pagos/mios', $this->cabecera('beto'))->assertOk();
        $this->assertSame(['t6'], array_column($r->json('compras'), 'id'));
        $this->assertSame(['c-beto'], array_column($r->json('abonos'), 'cita_id'));
        $this->assertSame(70000, (int) $r->json('resumen.pendiente'));
    }
}
