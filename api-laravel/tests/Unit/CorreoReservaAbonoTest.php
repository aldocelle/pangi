<?php

namespace Tests\Unit;

use App\Services\CitasEmail;
use App\Services\Correo;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * El correo de reserva creada, con y sin abono pendiente.
 *
 * Caso real (pruebas, 14-sep-2026): reservas de Radiología sin pagar llegaban
 * como «Tu visita está confirmada ✓», y la versión HTML —la que muestra Gmail—
 * no traía ni el monto ni el enlace de pago. A los 15 minutos la hora se
 * liberaba y el tutor seguía con un correo que se la prometía.
 */
#[RunTestsInSeparateProcesses]
class CorreoReservaAbonoTest extends TestCase
{
    /** @var array{asunto:string,texto:string,html:string}|null */
    private ?array $enviado = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['emr.portal_reservas_url' => 'https://pruebas.example.cl']);

        $correo = Mockery::mock(Correo::class);
        $correo->shouldReceive('enviar')->andReturnUsing(function ($para, $asunto, $texto, $html) {
            $this->enviado = compact('asunto', 'texto', 'html');

            return true;
        });
        $this->app->instance(Correo::class, $correo);
    }

    private function enviar(array $cita): array
    {
        $usuario = (object) ['nombre' => 'Daniel Tigre', 'email' => 'daniel@x.cl'];
        $mascota = (object) ['nombre' => 'Pelusa'];
        $base = [
            'id' => 'cita-1', 'fecha_hora' => '2026-09-17 11:00:00', 'servicio' => 'Radiología',
            'respuestas' => null, 'monto_abono_clp' => null, 'monto_total_clp' => null,
            'payment_status' => null, 'pago_modalidad_requerida' => null,
        ];

        app(CitasEmail::class)->notificarReservaCreada(
            $usuario, $mascota, (object) array_merge($base, $cita), null);

        $this->assertNotNull($this->enviado, 'no salió ningún correo');

        return $this->enviado;
    }

    public function test_con_abono_pendiente_no_dice_confirmada_y_el_html_trae_como_pagar(): void
    {
        $m = $this->enviar(['monto_abono_clp' => 27000, 'payment_status' => 'pending',
                            'pago_modalidad_requerida' => 'abono']);

        $this->assertStringContainsString('Tu hora está reservada', $m['html']);
        $this->assertStringNotContainsString('Tu hora está confirmada', $m['html']);
        $this->assertStringContainsString('https://pruebas.example.cl/pago/cita-1', $m['html']);
        $this->assertStringContainsString('27.000', $m['html']);
        $this->assertStringContainsString('15 minutos', $m['html']);

        $this->assertStringContainsString('Reservamos tu hora', $m['texto']);
        $this->assertStringNotContainsString('Tu hora está confirmada', $m['texto']);
    }

    public function test_sin_cobro_en_linea_sigue_diciendo_confirmada_y_sin_enlace(): void
    {
        $m = $this->enviar([]);

        $this->assertStringContainsString('Tu hora está confirmada ✓', $m['html']);
        $this->assertStringNotContainsString('/pago/', $m['html']);
    }

    public function test_con_el_pago_ya_aprobado_dice_confirmada(): void
    {
        $m = $this->enviar(['monto_abono_clp' => 27000, 'payment_status' => 'approved']);

        $this->assertStringContainsString('Tu hora está confirmada ✓', $m['html']);
        $this->assertStringNotContainsString('/pago/', $m['html']);
    }

    public function test_el_asunto_no_cambia_para_que_gmail_siga_agrupando(): void
    {
        // Decisión de la clínica del 05-sep-2026 (ver asuntoReserva()): el
        // asunto es el mismo que el del comprobante de pago.
        $m = $this->enviar(['monto_abono_clp' => 27000, 'payment_status' => 'pending']);

        $this->assertSame('Reserva confirmada en PANGI: Pelusa, jueves 17 de septiembre, 11:00', $m['asunto']);
    }
}
