<?php

namespace Tests\Feature;

use App\Services\AvisoPagoWhatsapp;
use App\Services\CitasEmail;
use App\Services\Correo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/** «Recibimos tu pago» en el chat de quien reservó por WhatsApp (05-oct-2026). */
#[RunTestsInSeparateProcesses]
class AvisoPagoWhatsappTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['emr.whatsapp_access_token' => 'token-de-prueba', 'emr.whatsapp_phone_number_id' => '999']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]])]);
    }

    private static function cita(array $cambios = []): object
    {
        return (object) ($cambios + [
            'id' => 'c1', 'fecha_hora' => '2026-10-07 16:00:00', 'estado' => 'Confirmada',
            'respuestas' => json_encode(['Canal' => 'WhatsApp (asistente IA)', 'WhatsApp' => '+56987901080']),
        ]);
    }

    private function enviados(): array
    {
        return Http::recorded()->map(fn ($p) => $p[0]->data())->values()->all();
    }

    public function test_la_hora_reservada_por_whatsapp_recibe_la_confirmacion_del_pago(): void
    {
        $this->assertTrue(app(AvisoPagoWhatsapp::class)->avisar(self::cita(), (object) ['nombre' => 'Amara']));

        $e = $this->enviados();
        $this->assertCount(1, $e);
        $this->assertSame('56987901080', $e[0]['to']);
        $this->assertStringContainsString('¡Recibimos tu pago! La hora de Amara del miércoles 7 de octubre, 16:00 quedó confirmada',
            $e[0]['text']['body']);
    }

    /** Decisión de producto (05-oct): la dirección al final; domicilio y telemedicina no son en la clínica. */
    public function test_termina_con_la_direccion_salvo_domicilio_y_telemedicina(): void
    {
        Schema::dropIfExists('ajustes');
        Schema::create('ajustes', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->text('data')->nullable();
        });
        $aviso = app(AvisoPagoWhatsapp::class);
        $mascota = (object) ['nombre' => 'Benito'];

        $aviso->avisar(self::cita(['servicio' => 'Ecografía']), $mascota);
        $aviso->avisar(self::cita(['servicio' => 'Consulta General a Domicilio']), $mascota);
        $aviso->avisar(self::cita(['servicio' => 'Telemedicina general']), $mascota);

        $cuerpos = array_map(fn ($e) => $e['text']['body'], $this->enviados());
        $this->assertStringEndsWith('Te esperamos en PANGI, Av. Principal 1234, local 5, Puente Alto.', $cuerpos[0]);
        $this->assertStringEndsWith('La atención es a domicilio, en tu dirección.', $cuerpos[1]);
        $this->assertStringEndsWith('La atención es por videollamada.', $cuerpos[2]);

        // Con la dirección que dio al reservar, esa y no «en tu dirección».
        $aviso->avisar(self::cita(['servicio' => 'Consulta General a Domicilio', 'respuestas' => json_encode(
            ['WhatsApp' => '+56987901080', 'Dirección' => 'Los Aromos 123, Puente'])]), $mascota);
        $this->assertStringEndsWith('La atención es a domicilio, en Los Aromos 123, Puente.', $this->enviados()[3]['text']['body']);
    }

    /** Sin poder leer la dirección (aquí no existe la tabla `ajustes`), el aviso del pago sale igual. */
    public function test_sin_direccion_el_aviso_sale_igual(): void
    {
        $this->assertTrue(app(AvisoPagoWhatsapp::class)->avisar(self::cita(['servicio' => 'Ecografía']), (object) ['nombre' => 'Benito']));
        $this->assertStringEndsWith('Te esperamos en PANGI.', $this->enviados()[0]['text']['body']);
    }

    /** Decisión de producto (05-oct): cuánto se pagó, y a qué correo salió el comprobante. */
    public function test_dice_cuanto_se_pago_el_saldo_y_a_donde_salio_el_comprobante(): void
    {
        $aviso = app(AvisoPagoWhatsapp::class);
        $amara = (object) ['nombre' => 'Amara'];

        // Abono sobre un total conocido: el monto y el saldo.
        $aviso->avisar(self::cita(['monto_abono_clp' => 22500, 'monto_total_clp' => 45000]), $amara, 22500, 'ana@x.cl');
        // Pagó el total: sin saldo.
        $aviso->avisar(self::cita(['monto_abono_clp' => 22500, 'monto_total_clp' => 45000]), $amara, 45000, 'ana@x.cl');
        // Abono fijo, total desconocido: «el resto», nunca un $0.
        $aviso->avisar(self::cita(['monto_abono_clp' => 27000, 'monto_total_clp' => null]), $amara, 27000, 'ana@x.cl');
        // El correo no salió: no se dice que se envió.
        $aviso->avisar(self::cita(['monto_abono_clp' => 22500, 'monto_total_clp' => 45000]), $amara, 22500, null);

        $c = array_map(fn ($e) => $e['text']['body'], $this->enviados());
        $this->assertStringStartsWith('¡Recibimos tu pago de $22.500! La hora de Amara del miércoles 7 de octubre, 16:00 quedó confirmada.'
            . ' Saldo a pagar en la clínica: $22.500. Te enviamos el comprobante a ana@x.cl.', $c[0]);
        $this->assertStringStartsWith('¡Recibimos tu pago de $45.000!', $c[1]);
        $this->assertStringNotContainsString('Saldo', $c[1]);
        $this->assertStringContainsString('quedó confirmada. Te enviamos el comprobante a ana@x.cl.', $c[1]);
        $this->assertStringContainsString('$27.000', $c[2]);
        $this->assertStringContainsString('El resto se paga en la clínica.', $c[2]);
        $this->assertStringNotContainsString('$0', $c[2]);
        $this->assertStringNotContainsString('Te enviamos', $c[3]);
        $this->assertStringContainsString('Si necesitas el comprobante, pídelo en la clínica.', $c[3]);
    }

    /**
     * El comprobante usaba solo la modalidad REQUERIDA: quien elegía pagar el
     * total de una consulta de abono recibía «abono de $22.500» y un saldo que
     * ya había pagado. Ahora dice lo que se cobró, y avisa si salió.
     */
    public function test_el_comprobante_dice_lo_cobrado_aunque_haya_elegido_pagar_el_total(): void
    {
        $enviado = null;
        $correo = \Mockery::mock(Correo::class);
        $correo->shouldReceive('enviar')->andReturnUsing(function ($para, $asunto, $texto) use (&$enviado) {
            $enviado = $texto;

            return true;
        });
        $this->app->instance(Correo::class, $correo);
        $cita = self::cita(['servicio' => 'Consulta Dermatológica', 'monto_abono_clp' => 22500, 'monto_total_clp' => 45000,
            'pago_modalidad_requerida' => 'abono', 'pago_autorizacion' => null]);

        $ok = app(CitasEmail::class)->notificarPagoRecibido((object) ['nombre' => 'Ana', 'email' => 'ana@x.cl'],
            (object) ['nombre' => 'Amara'], $cita, null, 45000);

        $this->assertTrue($ok);
        $this->assertStringContainsString('Recibimos tu pago de $45.000', $enviado);
        $this->assertStringNotContainsString('Saldo', $enviado);
    }

    public function test_si_el_cupo_lo_tomo_otro_no_dice_confirmada(): void
    {
        app(AvisoPagoWhatsapp::class)->avisar(self::cita(['estado' => 'Expirada']), (object) ['nombre' => 'Amara']);

        $cuerpo = $this->enviados()[0]['text']['body'];
        $this->assertStringNotContainsString('confirmada', $cuerpo);
        $this->assertStringContainsString('La clínica te va a contactar', $cuerpo);
    }

    public function test_una_reserva_del_portal_no_manda_nada(): void
    {
        $this->assertFalse(app(AvisoPagoWhatsapp::class)->avisar(
            self::cita(['respuestas' => json_encode(['Canal' => 'Portal'])]), (object) ['nombre' => 'Toby']));
        $this->assertSame([], $this->enviados());
    }

    /** Decisión de producto (06-oct): el RUT que se escribió al pagar va en el comprobante. */
    public function test_el_comprobante_lleva_el_rut_de_quien_pago(): void
    {
        $enviado = null;
        $correo = \Mockery::mock(Correo::class);
        $correo->shouldReceive('enviar')->andReturnUsing(function ($para, $asunto, $texto) use (&$enviado) {
            $enviado = $texto;

            return true;
        });
        $this->app->instance(Correo::class, $correo);
        $cita = self::cita(['servicio' => 'Consulta Dermatológica', 'monto_abono_clp' => 22500, 'monto_total_clp' => 45000,
            'pago_modalidad_requerida' => 'abono', 'pago_autorizacion' => null,
            'respuestas' => json_encode(['RUT del pago' => '12.345.678-5'])]);

        app(CitasEmail::class)->notificarPagoRecibido((object) ['nombre' => 'Ana', 'email' => 'ana@x.cl', 'rut' => '11.111.111-1'],
            (object) ['nombre' => 'Amara'], $cita, null, 22500);
        $this->assertStringContainsString('RUT: 12.345.678-5', $enviado, 'el de quien pagó, no el de la cuenta');

        // Sin RUT del pago, el de la cuenta; uno roto, ninguno.
        app(CitasEmail::class)->notificarPagoRecibido((object) ['nombre' => 'Ana', 'email' => 'ana@x.cl', 'rut' => '11.111.111-1'],
            (object) ['nombre' => 'Amara'], self::cita(['servicio' => 'X', 'monto_abono_clp' => 1, 'monto_total_clp' => 2,
                'pago_modalidad_requerida' => 'abono', 'pago_autorizacion' => null]), null, 1);
        $this->assertStringContainsString('RUT: 11.111.111-1', $enviado);
        app(CitasEmail::class)->notificarPagoRecibido((object) ['nombre' => 'Ana', 'email' => 'ana@x.cl', 'rut' => '1-2'],
            (object) ['nombre' => 'Amara'], self::cita(['servicio' => 'X', 'monto_abono_clp' => 1, 'monto_total_clp' => 2,
                'pago_modalidad_requerida' => 'abono', 'pago_autorizacion' => null]), null, 1);
        $this->assertStringNotContainsString('RUT:', $enviado);
    }

    /** 09-oct: la hora acordada con un especialista ahora expira sin abono; él también se entera. */
    public function test_la_hora_liberada_de_un_especialista_tambien_se_le_avisa_a_el(): void
    {
        Schema::create('especialistas', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('telefono')->nullable();
        });
        \Illuminate\Support\Facades\DB::table('especialistas')->insert(['id' => 'tigre', 'telefono' => '+56 9 1111 2222']);
        $cita = self::cita(['estado' => 'Expirada', 'especialista_id' => 'tigre',
            'respuestas' => json_encode(['Canal' => 'WhatsApp (acordada con el especialista)',
                'WhatsApp' => '+56987901080', 'Solicitud' => 'N° 4'])]);

        $this->assertTrue(app(AvisoPagoWhatsapp::class)->avisarHoraLiberada($cita, (object) ['nombre' => 'Amara']));

        $e = collect($this->enviados());
        $this->assertStringContainsString('No alcanzamos a recibir el abono', $e->firstWhere('to', '56987901080')['text']['body']);
        $this->assertStringContainsString('(solicitud N° 4) se liberó', $e->firstWhere('to', '56911112222')['text']['body']);
    }

    public function test_una_hora_liberada_sin_especialista_de_por_medio_solo_avisa_al_tutor(): void
    {
        app(AvisoPagoWhatsapp::class)->avisarHoraLiberada(self::cita(['estado' => 'Expirada']), (object) ['nombre' => 'Amara']);

        $this->assertCount(1, $this->enviados());
    }
}
