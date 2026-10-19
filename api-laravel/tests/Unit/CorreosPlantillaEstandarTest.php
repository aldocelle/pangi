<?php

namespace Tests\Unit;

use App\Services\CitasEmail;
use App\Services\Correo;
use App\Services\MensajeRecordatorio;
use App\Services\Plantilla;
use App\Services\TokensCorreo;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * El correo de «Reserva confirmada» es el estándar de diseño de todos los
 * demás (`Plantilla`). Este test impide que un correo nuevo —o uno de los de
 * cuenta y de aviso de cita, que antes eran HTML suelto o solo texto— vuelva a
 * salir con su propio diseño.
 *
 * No compara píxeles: comprueba que cada correo pase por `Plantilla::pagina()`
 * (título, saludo y firma de la clínica) y que lo que viene de un formulario
 * público salga escapado.
 */
#[RunTestsInSeparateProcesses]
class CorreosPlantillaEstandarTest extends TestCase
{
    /** @var array{para:string,asunto:string,texto:string,html:?string}|null */
    private ?array $enviado = null;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'emr.portal_reservas_url' => 'https://pruebas.example.cl',
            'emr.clinica_telefono' => '+56 9 0000 0000',
        ]);

        $correo = Mockery::mock(Correo::class);
        $correo->shouldReceive('enviar')->andReturnUsing(
            function ($para, $asunto, $texto, $html = null) {
                $this->enviado = compact('para', 'asunto', 'texto', 'html');

                return true;
            });
        $this->app->instance(Correo::class, $correo);
    }

    private function assertEsPlantillaEstandar(?string $html, string $titulo): void
    {
        $this->assertNotNull($html, 'el correo salió solo en texto plano');
        $this->assertStringContainsString('border-radius:' . Plantilla::R_LG, $html, 'no pasa por Plantilla::pagina()');
        $this->assertMatchesRegularExpression('#<h1[^>]*>' . preg_quote($titulo, '#') . '</h1>#u', $html);
        // Pie institucional: el nombre y, debajo, dirección y contacto.
        $this->assertMatchesRegularExpression("#text-align:center'>PANGI</p><p [^>]*>[^<]+<br>WhatsApp [^<]+</p></td></tr></table></div>$#u", $html);
        $this->assertStringNotContainsString('font-family:sans-serif', $html, 'quedó HTML suelto');
    }

    private function cita(array $extra = []): object
    {
        return (object) array_merge([
            'id' => 'cita-1', 'fecha_hora' => '2026-10-08 12:30:00', 'servicio' => 'Consulta de prueba',
            'respuestas' => null, 'estado' => 'Agendada', 'monto_abono_clp' => null,
        ], $extra);
    }

    public function test_reagendada_usa_la_tarjeta_y_trae_el_lugar(): void
    {
        app(CitasEmail::class)->notificarCitaReagendada(
            (object) ['nombre' => 'Daniel', 'email' => 'd@x.cl'], (object) ['nombre' => 'Rocco'],
            $this->cita(), (object) ['nombre' => 'Dr. Tigre']);

        $this->assertEsPlantillaEstandar($this->enviado['html'], 'Tu cita cambió de hora');
        // La cabecera es el servicio; quien atiende va en su propia fila, debajo.
        $this->assertStringNotContainsString('Atiende', $this->enviado['html']);
        $this->assertMatchesRegularExpression("#font-weight:600'>Consulta de prueba</td>#u", $this->enviado['html']);
        $this->assertMatchesRegularExpression('#Consulta de prueba</td></tr><tr>.*?>👤</td>.*?>Dr\. Tigre</td>#su', $this->enviado['html']);
        $this->assertStringContainsString('Especialista: Dr. Tigre', $this->enviado['texto']);
        $this->assertStringContainsString(CitasEmail::DIRECCION_CLINICA, $this->enviado['html']);
        $this->assertStringContainsString(CitasEmail::DIRECCION_CLINICA, $this->enviado['texto']);
    }

    public function test_reagendar_es_un_boton_en_la_confirmada_y_la_reagendada_pero_no_con_abono_pendiente(): void
    {
        $usuario = (object) ['nombre' => 'Daniel', 'email' => 'd@x.cl'];
        $mascota = (object) ['nombre' => 'Rocco'];
        $enlace = "href='https://pruebas.example.cl/citas'";
        $base = ['pago_modalidad_requerida' => null, 'payment_status' => null, 'monto_total_clp' => null];

        app(CitasEmail::class)->notificarReservaCreada($usuario, $mascota, $this->cita($base), null);
        $this->assertStringContainsString($enlace, $this->enviado['html']);
        $this->assertStringContainsString('Reagendar mi cita', $this->enviado['html']);
        $this->assertStringContainsString('https://pruebas.example.cl/citas', $this->enviado['texto']);

        app(CitasEmail::class)->notificarCitaReagendada($usuario, $mascota, $this->cita($base), null);
        $this->assertStringContainsString($enlace, $this->enviado['html']);

        // Con abono pendiente el botón es «Pagar»: no compite con un segundo.
        app(CitasEmail::class)->notificarReservaCreada($usuario, $mascota, $this->cita($base + [
            'monto_abono_clp' => 27000, 'payment_status' => 'pending', 'pago_modalidad_requerida' => 'abono']), null);
        $this->assertStringNotContainsString('Reagendar mi cita', $this->enviado['html']);
        $this->assertStringContainsString('Pagar abono', $this->enviado['html']);
    }

    public function test_cancelada_no_lleva_check_ni_lugar_y_confirmada_si(): void
    {
        $usuario = (object) ['nombre' => 'Daniel', 'email' => 'd@x.cl'];
        $mascota = (object) ['nombre' => 'Rocco'];

        app(CitasEmail::class)->notificarCambioEstado($usuario, $mascota, $this->cita(['estado' => 'Cancelada']));
        $this->assertEsPlantillaEstandar($this->enviado['html'], 'Tu cita fue cancelada');
        // La dirección sigue en el pie institucional; lo que no va es la fila del lugar.
        $this->assertStringNotContainsString('📍', $this->enviado['html']);
        $this->assertStringNotContainsString('Lugar:', $this->enviado['texto']);
        $this->assertStringContainsString('Reservar otra hora', $this->enviado['html']);
        $this->assertSame('Cita cancelada: Rocco, jueves 8 de octubre, 12:30', $this->enviado['asunto']);

        app(CitasEmail::class)->notificarCambioEstado($usuario, $mascota, $this->cita(['estado' => 'Confirmada']));
        $this->assertEsPlantillaEstandar($this->enviado['html'], 'Tu cita fue confirmada ✓');
        $this->assertStringContainsString('📍', $this->enviado['html']);
        $this->assertStringContainsString('Lugar: ' . CitasEmail::DIRECCION_CLINICA, $this->enviado['texto']);
    }

    public function test_la_hora_va_en_24_horas_y_la_fecha_lleva_un_icono_sin_dia(): void
    {
        app(CitasEmail::class)->notificarCitaReagendada(
            (object) ['nombre' => 'Daniel', 'email' => 'd@x.cl'], (object) ['nombre' => 'Rocco'],
            $this->cita(['fecha_hora' => '2026-10-08 17:30:00']), null);

        $this->assertSame('Nueva hora para Rocco: jueves 8 de octubre, 17:30', $this->enviado['asunto']);
        $this->assertStringContainsString('Jueves 8 de octubre, 17:30', $this->enviado['texto']);
        $this->assertDoesNotMatchRegularExpression('/\b(AM|PM)\b/', $this->enviado['texto'] . $this->enviado['html']);
        // 📅 en iPhone dice «JUL 17» y se lee como otra fecha.
        $this->assertStringNotContainsString('📅', $this->enviado['html']);
        $this->assertStringContainsString(Plantilla::ICONO_FECHA, $this->enviado['html']);
    }

    public function test_el_nombre_que_viene_del_formulario_sale_escapado(): void
    {
        app(CitasEmail::class)->notificarCambioEstado(
            (object) ['nombre' => '<script>x</script>', 'email' => 'd@x.cl'],
            (object) ['nombre' => '<b>Rocco</b>'], $this->cita(['estado' => 'Cancelada']));

        $this->assertStringNotContainsString('<script>', $this->enviado['html']);
        $this->assertStringNotContainsString('<b>Rocco</b>', $this->enviado['html']);
    }

    public function test_restablecer_contrasena_es_boton_enlace_y_aviso_de_vencimiento(): void
    {
        app(TokensCorreo::class)->enviarReset((object) ['nombre' => 'Daniel Tigre', 'email' => 'd@x.cl'], 'tok');

        $html = $this->enviado['html'];
        $this->assertEsPlantillaEstandar($html, 'Restablece tu contraseña');
        $this->assertStringContainsString("href='https://pruebas.example.cl/restablecer?token=tok'", $html);
        $this->assertStringContainsString('Elegir nueva contraseña', $html);
        $this->assertStringContainsString('El enlace vence en <strong>1 hora</strong>.', $html);
        $this->assertStringContainsString('El enlace vence en 1 hora.', $this->enviado['texto']);
    }

    public function test_confirmar_correo_y_activar_acceso_usan_la_plantilla(): void
    {
        $usuario = (object) ['nombre' => 'Daniel Tigre', 'email' => 'd@x.cl'];

        app(TokensCorreo::class)->enviarConfirmacion($usuario, 'tok');
        $this->assertEsPlantillaEstandar($this->enviado['html'], 'Confirma tu correo');
        $this->assertStringContainsString('Confirmar mi correo', $this->enviado['html']);

        app(TokensCorreo::class)->enviarConfirmacion($usuario, 'tok', true);
        $this->assertStringContainsString('Ya teníamos tus datos', $this->enviado['html']);
    }

    public function test_aviso_preventivo_conserva_el_mensaje_de_la_oficina_escapado(): void
    {
        $html = MensajeRecordatorio::html(
            (object) ['nombre' => 'María', 'telefono' => null, 'email' => 'm@x.cl'],
            (object) ['nombre' => 'Luna', 'especie' => 'Canino', 'raza' => null],
            (object) ['titulo' => 'Vacuna antirrábica', 'categoria' => null, 'fecha_programada' => '2026-10-12',
                'mensaje' => "Trae el carnet.\n<script>1</script> @mascota_nombre"],
            ['firma' => 'Equipo PANGI']);

        $this->assertEsPlantillaEstandar($html, 'Un recordatorio para Luna');
        $this->assertStringContainsString('12-10-2026', $html);
        $this->assertStringContainsString('Trae el carnet.<br />', $html);
        $this->assertStringContainsString('&lt;script&gt;1&lt;/script&gt; Luna', $html);
        $this->assertStringContainsString('Equipo PANGI', $html);
    }

    public function test_los_bloques_nuevos_de_la_plantilla_escapan_y_no_cambian_la_confirmacion(): void
    {
        $this->assertSame('&lt;a&gt;&quot;x&quot;', Plantilla::e('<a>"x"'));
        $this->assertStringContainsString('&lt;i&gt;', Plantilla::enlaceAlterno('<i>'));
        // `bloqueQueEsperar` usa `subtitulo`: mismo estilo que los demás subtítulos.
        $this->assertStringStartsWith(
            "<p style='margin:0 0 8px;font-size:15px;font-weight:600;color:#26326e'>Para tu visita</p><table",
            Plantilla::bloqueQueEsperar(''));
    }
}
