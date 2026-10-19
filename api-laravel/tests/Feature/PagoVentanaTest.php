<?php

namespace Tests\Feature;

use App\Services\Esquema;
use App\Services\Getnet;
use App\Services\LinkDePago;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * La página de pago respeta el plazo de 15 minutos (12-oct-2026).
 *
 * Una reserva del portal sin pagar retiene la hora 15 minutos; pasados, la
 * agenda vuelve a ofrecer ese horario. La página seguía diciendo «Tu hora está
 * reservada, falta el pago» y abría el cobro igual, aunque la hora ya podía
 * ser de otra persona. Se vio en pruebas. Lo que se fija acá:
 *
 *   · dentro del plazo se cobra, y el resumen trae hasta cuándo;
 *   · pasado el plazo, o ya «Expirada», el resumen lo dice y el cobro no abre;
 *   · la hora coordinada por la clínica TAMBIÉN vence (decisión de producto, 12-oct: «no
 *     debería mostrarse como disponible para pagarla si está vencida»), con el
 *     plazo contado desde que se mandó el enlace, y el enlace lo fija una vez;
 *   · quien pagó a tiempo y cuyo pago se confirma tarde no queda bloqueado;
 *   · sin la migración de `pago_enlace_at` rige la regla anterior.
 */
#[RunTestsInSeparateProcesses]
class PagoVentanaTest extends TestCase
{
    private const CITA = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private int $sesionesAbiertas = 0;

    private ?string $estadoGetnet = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['emr.portal_reservas_url' => 'https://reservas.test']);
        Esquema::olvidar();
        foreach (['citas', 'usuarios', 'mascotas', 'servicios', 'especialistas'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email')->nullable(); $t->string('nombre')->nullable();
            $t->string('telefono')->nullable(); $t->string('rut')->nullable(); $t->string('rol')->default('dueño');
        });
        Schema::create('mascotas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id'); $t->string('nombre');
        });
        Schema::create('servicios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->string('modalidad_pago')->nullable();
            $t->boolean('requiere_orden_examen')->default(false);
            $t->integer('abono_fijo_clp')->nullable(); $t->boolean('tarifa_por_especialista')->default(false);
        });
        Schema::create('especialistas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre');
        });
        Schema::create('citas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('mascota_id'); $t->string('usuario_dueno_id');
            $t->string('fecha_hora'); $t->string('servicio')->nullable(); $t->string('servicio_id')->nullable();
            $t->string('especialista_id')->nullable(); $t->string('estado'); $t->text('respuestas')->nullable();
            $t->integer('monto_abono_clp')->nullable(); $t->integer('monto_total_clp')->nullable();
            $t->string('payment_status')->nullable(); $t->string('pago_modalidad_requerida')->nullable();
            $t->string('pago_modalidad')->nullable(); $t->string('pago_request_id')->nullable();
            $t->string('pago_referencia')->nullable(); $t->string('pago_autorizacion')->nullable();
            $t->string('pago_process_url')->nullable(); $t->string('pago_actualizado_at')->nullable();
            $t->string('google_event_id')->nullable(); $t->string('created_at')->nullable();
            $t->string('pago_enlace_at')->nullable();
        });
        DB::table('usuarios')->insert(['id' => 'ana', 'email' => 'ana@x.cl', 'nombre' => 'Ana']);
        DB::table('mascotas')->insert(['id' => 'toby', 'usuario_id' => 'ana', 'nombre' => 'Toby']);

        $g = Mockery::mock(Getnet::class)->makePartial();
        $g->shouldReceive('habilitado')->andReturn(true);
        $g->shouldReceive('post')->andReturnUsing(function ($ruta) {
            if ($ruta === 'api/session') {
                $this->sesionesAbiertas++;

                return ['requestId' => 7, 'processUrl' => 'https://checkout.test/7'];
            }

            // Consulta de una sesión anterior (refrescarDesdeGetnet).
            return ['status' => ['status' => $this->estadoGetnet ?? 'PENDING'], 'payment' => []];
        });
        $this->app->instance(Getnet::class, $g);
    }

    private function cita(array $d): void
    {
        DB::table('citas')->insert($d + [
            'id' => self::CITA, 'mascota_id' => 'toby', 'usuario_dueno_id' => 'ana',
            'fecha_hora' => CarbonImmutable::now('America/Santiago')->addDays(9)->format('Y-m-d 16:00:00'),
            'servicio' => 'Consulta Dermatológica', 'estado' => 'Agendada', 'respuestas' => '{}',
            'monto_abono_clp' => 22500, 'monto_total_clp' => 45000, 'payment_status' => 'pending',
            'pago_modalidad_requerida' => 'abono',
        ]);
    }

    private function hace(int $minutos): string
    {
        return CarbonImmutable::now('UTC')->subMinutes($minutos)->format('Y-m-d H:i:s');
    }

    private function pagar()
    {
        return $this->postJson('/pagos/cita/' . self::CITA . '/sesion', ['modalidad' => 'abono']);
    }

    public function test_dentro_del_plazo_se_cobra_y_el_resumen_dice_hasta_cuando(): void
    {
        $this->cita(['created_at' => $this->hace(5)]);

        $r = $this->getJson('/pagos/cita/' . self::CITA)->assertOk();
        $this->assertFalse($r->json('hora_liberada'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $r->json('pagar_hasta'));
        $this->assertTrue(CarbonImmutable::parse($r->json('pagar_hasta'))->isFuture());

        $this->pagar()->assertOk();
        $this->assertSame(1, $this->sesionesAbiertas);
    }

    public function test_pasado_el_plazo_la_hora_se_libera_y_no_se_abre_ningun_cobro(): void
    {
        $this->cita(['created_at' => $this->hace(16)]);

        $this->getJson('/pagos/cita/' . self::CITA)->assertOk()->assertJsonPath('hora_liberada', true);
        $this->pagar()->assertStatus(410);
        $this->assertSame(0, $this->sesionesAbiertas);
    }

    public function test_una_reserva_ya_expirada_tampoco_se_cobra(): void
    {
        $this->cita(['created_at' => $this->hace(60), 'estado' => 'Expirada']);

        $this->getJson('/pagos/cita/' . self::CITA)->assertOk()->assertJsonPath('hora_liberada', true);
        $this->pagar()->assertStatus(410);
        $this->assertSame(0, $this->sesionesAbiertas);
    }

    /** El caso que se vio en pruebas: coordinada, agendada hace días, sin pagar. */
    public function test_la_hora_coordinada_sin_marca_de_enlace_vence_desde_que_se_agendo(): void
    {
        $this->cita(['created_at' => $this->hace(60 * 24 * 3), 'pago_modalidad_requerida' => null]);

        $this->getJson('/pagos/cita/' . self::CITA)->assertOk()->assertJsonPath('hora_liberada', true);
        $this->pagar()->assertStatus(410);
        $this->assertSame(0, $this->sesionesAbiertas);
    }

    public function test_la_coordinada_corre_su_plazo_desde_que_se_mando_el_enlace(): void
    {
        $this->cita(['created_at' => $this->hace(60 * 24 * 3), 'pago_modalidad_requerida' => null,
            'pago_enlace_at' => $this->hace(5)]);

        $r = $this->getJson('/pagos/cita/' . self::CITA)->assertOk();
        $this->assertFalse($r->json('hora_liberada'));
        $esperado = CarbonImmutable::now('UTC')->subMinutes(5)->addMinutes(15);
        $this->assertLessThan(5, abs(CarbonImmutable::parse($r->json('pagar_hasta'))->diffInSeconds($esperado)));
        $this->pagar()->assertOk();
    }

    public function test_la_coordinada_con_el_enlace_vencido_ya_no_se_cobra(): void
    {
        $this->cita(['created_at' => $this->hace(60 * 24 * 3), 'pago_modalidad_requerida' => null,
            'pago_enlace_at' => $this->hace(20)]);

        $this->getJson('/pagos/cita/' . self::CITA)->assertOk()->assertJsonPath('hora_liberada', true);
        $this->pagar()->assertStatus(410);
    }

    public function test_el_enlace_del_panel_fija_el_plazo_una_sola_vez(): void
    {
        DB::table('servicios')->insert(['id' => 'pelu', 'nombre' => 'Peluquería', 'modalidad_pago' => 'abono',
            'abono_fijo_clp' => 10000]);
        DB::table('especialistas')->insert(['id' => 'esp', 'nombre' => 'Dra. Prueba']);
        // Hora del mostrador: agendada hace días, sin monto todavía.
        $this->cita(['created_at' => $this->hace(60 * 24 * 3), 'pago_modalidad_requerida' => null,
            'monto_abono_clp' => null, 'monto_total_clp' => null, 'payment_status' => null,
            'servicio_id' => 'pelu', 'especialista_id' => 'esp']);

        $link = app(LinkDePago::class)->generar(self::CITA);
        $this->assertArrayNotHasKey('error', $link);
        $this->assertSame(10000, $link['monto_abono_clp']);
        $enlace = DB::table('citas')->value('pago_enlace_at');
        $this->assertNotNull($enlace);
        // No vence en el acto aunque la hora tenga días: corre desde el enlace.
        $this->assertTrue(CarbonImmutable::parse($link['pagar_hasta'])->isFuture());

        // Volver a generarlo no regala otros 15 minutos.
        $primero = $this->hace(3);
        DB::table('citas')->update(['pago_enlace_at' => $primero]);
        app(LinkDePago::class)->generar(self::CITA);
        $this->assertSame($primero, DB::table('citas')->value('pago_enlace_at'));
    }

    public function test_sin_la_migracion_rige_la_regla_anterior(): void
    {
        Schema::table('citas', fn (Blueprint $t) => $t->dropColumn('pago_enlace_at'));
        Esquema::olvidar();
        $this->cita(['created_at' => $this->hace(60 * 24 * 3), 'pago_modalidad_requerida' => null]);

        $r = $this->getJson('/pagos/cita/' . self::CITA)->assertOk();
        $this->assertFalse($r->json('hora_liberada'));
        $this->assertNull($r->json('pagar_hasta'));
        $this->pagar()->assertOk();
    }

    public function test_quien_pago_a_tiempo_y_se_confirma_tarde_ve_su_pago_no_un_410(): void
    {
        // Abrió el cobro en el minuto 14 y Getnet lo aprobó; el aviso todavía
        // no llega y la reserva ya pasó el plazo.
        $this->cita(['created_at' => $this->hace(20), 'pago_request_id' => '7']);
        $this->estadoGetnet = 'APPROVED';

        $this->pagar()->assertStatus(409);
        $this->assertSame('approved', DB::table('citas')->value('payment_status'));
        $this->getJson('/pagos/cita/' . self::CITA)->assertOk()->assertJsonPath('hora_liberada', false);
    }
}
