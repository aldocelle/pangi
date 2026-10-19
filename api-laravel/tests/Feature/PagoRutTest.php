<?php

namespace Tests\Feature;

use App\Services\Getnet;
use App\Services\Rut;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * El RUT en la pantalla de pago (decisión de producto, 06-oct): opcional, validado,
 * enviado a Getnet para que el checkout no lo pida de nuevo, y guardado sin
 * que una ruta PÚBLICA pueda reescribir el RUT de una cuenta.
 */
#[RunTestsInSeparateProcesses]
class PagoRutTest extends TestCase
{
    private const CITA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    /** Lo que se le mandó a Getnet, sesión por sesión. */
    private array $sesiones = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['emr.portal_reservas_url' => 'https://reservas.test']);
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
        });
        DB::table('usuarios')->insert(['id' => 'ana', 'email' => 'ana@x.cl', 'nombre' => 'Ana', 'rut' => null]);
        DB::table('mascotas')->insert(['id' => 'toby', 'usuario_id' => 'ana', 'nombre' => 'Toby']);
        DB::table('citas')->insert(['id' => self::CITA, 'mascota_id' => 'toby', 'usuario_dueno_id' => 'ana',
            'fecha_hora' => '2026-10-09 16:00:00', 'servicio' => 'Consulta Dermatológica', 'estado' => 'Agendada',
            'respuestas' => '{}', 'monto_abono_clp' => 22500, 'monto_total_clp' => 45000, 'pago_modalidad_requerida' => 'abono']);
    }

    /** Getnet real en todo salvo «habilitado» y el envío, que se capturan. */
    private function getnet(bool $rechazaConDocumento = false): void
    {
        $g = Mockery::mock(Getnet::class)->makePartial();
        $g->shouldReceive('habilitado')->andReturn(true);
        $g->shouldReceive('post')->andReturnUsing(function ($ruta, $cuerpo = []) use ($rechazaConDocumento) {
            // Solo las que CREAN un cobro: la consulta de una sesión anterior
            // (refrescarDesdeGetnet) también pasa por acá.
            if ($ruta === 'api/session') {
                $this->sesiones[] = $cuerpo;
            }
            if ($rechazaConDocumento && ! empty($cuerpo['buyer']['document'])) {
                throw new \RuntimeException('La pasarela de pago rechazó la solicitud.');
            }

            return ['requestId' => 1, 'processUrl' => 'https://checkout.test/1'];
        });
        $this->app->instance(Getnet::class, $g);
    }

    private function pagar(array $cuerpo = [])
    {
        return $this->postJson('/pagos/cita/' . self::CITA . '/sesion', $cuerpo + ['modalidad' => 'abono']);
    }

    public function test_el_rut_escrito_va_a_getnet_queda_en_la_cita_y_llena_la_ficha_vacia(): void
    {
        $this->getnet();
        $this->pagar(['rut' => '12.345.678-5'])->assertOk();

        $this->assertSame('CLRUT', $this->sesiones[0]['buyer']['documentType']);
        $this->assertSame('12345678-5', $this->sesiones[0]['buyer']['document']);
        $this->assertSame('12.345.678-5', json_decode(DB::table('citas')->value('respuestas'), true)['RUT del pago']);
        $this->assertSame('12.345.678-5', DB::table('usuarios')->value('rut'));
    }

    public function test_sin_rut_escrito_usa_el_guardado_y_sin_ninguno_no_manda_documento(): void
    {
        $this->getnet();
        $this->pagar()->assertOk();
        $this->assertArrayNotHasKey('document', $this->sesiones[0]['buyer']);

        DB::table('usuarios')->update(['rut' => '.6.645.549-1']);   // como vienen algunos de ACVet
        DB::table('citas')->update(['payment_status' => null]);
        $this->pagar()->assertOk();
        $this->assertSame('6645549-1', $this->sesiones[1]['buyer']['document']);
    }

    /** La ruta es pública: con el id de la cita no se puede reescribir el RUT de una cuenta. */
    public function test_no_reescribe_el_rut_que_la_cuenta_ya_tiene(): void
    {
        DB::table('usuarios')->update(['rut' => '11.111.111-1']);
        $this->getnet();
        $this->pagar(['rut' => '12.345.678-5'])->assertOk();

        $this->assertSame('11.111.111-1', DB::table('usuarios')->value('rut'));
        $this->assertSame('12345678-5', $this->sesiones[0]['buyer']['document'], 'a Getnet va el de quien paga');
        $this->assertSame('12.345.678-5', json_decode(DB::table('citas')->value('respuestas'), true)['RUT del pago']);
    }

    public function test_un_rut_invalido_se_rechaza_antes_de_abrir_el_cobro(): void
    {
        $this->getnet();
        $this->pagar(['rut' => '12.345.678-9'])->assertStatus(422)->assertJsonFragment(['detail' =>
            'El RUT no es válido. Revisa el número y el dígito verificador, o déjalo en blanco.']);
        $this->assertSame([], $this->sesiones);
        $this->assertNull(DB::table('usuarios')->value('rut'));
    }

    /** Un RUT nunca bloquea un pago: si Getnet rechaza con documento, se reintenta sin él. */
    public function test_si_getnet_rechaza_el_documento_se_cobra_igual_sin_el(): void
    {
        $this->getnet(rechazaConDocumento: true);
        $this->pagar(['rut' => '12.345.678-5'])->assertOk()->assertJsonFragment(['process_url' => 'https://checkout.test/1']);

        $this->assertCount(2, $this->sesiones);
        $this->assertArrayNotHasKey('document', $this->sesiones[1]['buyer']);
    }

    public function test_la_pantalla_ve_el_rut_registrado_solo_enmascarado(): void
    {
        DB::table('usuarios')->update(['rut' => '12.345.678-5']);
        $r = $this->getJson('/pagos/cita/' . self::CITA)->assertOk();

        $this->assertSame('••.•••.678-5', $r->json('rut_registrado'));
        $this->assertStringNotContainsString('12.345', $r->getContent());
    }

    public function test_validador(): void
    {
        foreach (['11.111.111-1' => '11111111-1', '12345678-5' => '12345678-5', '7.654.321-6' => '7654321-6',
            '.6.645.549-1' => '6645549-1', '12.345.678-9' => null, 'abc' => null, '' => null] as $entrada => $esperado) {
            $this->assertSame($esperado, Rut::normalizar($entrada), $entrada);
        }
        $this->assertSame('12.345.678-5', Rut::formatear('12345678-5'));
    }
}
