<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * La verificación GET /whatsapp/webhook, aparte de `WhatsappTest.php`
 * (que cubre el POST de mensajes entrantes).
 *
 * Meta manda los parámetros con punto (`hub.mode`, `hub.verify_token`,
 * `hub.challenge`), y PHP los convierte a guion bajo antes de que lleguen a
 * `$_GET` — es el propio intérprete, no Laravel. Un endpoint que buscara la
 * clave con el punto literal (`$request->query('hub.mode')`) nunca la
 * encontraría y devolvería 403 siempre, con cualquier token, incluido el
 * correcto: exactamente lo que pasó en el lab el 29-sep-2026 al registrar el
 * webhook por primera vez. Por eso la query se arma acá como string crudo con
 * el punto literal, no con el array que usa normalmente `get()` con query
 * params nombrados (que lo escaparía distinto) — así el test viaja por el
 * mismo parseo de PHP que un request real, en vez de esquivarlo.
 */
#[RunTestsInSeparateProcesses]
class WhatsappWebhookVerificacionTest extends TestCase
{
    private const TOKEN = 'token-de-prueba-para-el-webhook';

    protected function setUp(): void
    {
        parent::setUp();
        config(['emr.whatsapp_verify_token' => self::TOKEN]);
    }

    public function test_verifica_con_el_token_correcto_y_devuelve_el_challenge(): void
    {
        $query = 'hub.mode=subscribe&hub.verify_token=' . self::TOKEN . '&hub.challenge=abc123';

        $respuesta = $this->get('/whatsapp/webhook?' . $query);

        $respuesta->assertOk();
        $respuesta->assertSee('abc123', false);
    }

    public function test_rechaza_con_token_incorrecto(): void
    {
        $query = 'hub.mode=subscribe&hub.verify_token=token-equivocado&hub.challenge=abc123';

        $respuesta = $this->get('/whatsapp/webhook?' . $query);

        $respuesta->assertStatus(403);
    }

    public function test_rechaza_sin_token_configurado(): void
    {
        config(['emr.whatsapp_verify_token' => '']);

        $query = 'hub.mode=subscribe&hub.verify_token=' . self::TOKEN . '&hub.challenge=abc123';

        $respuesta = $this->get('/whatsapp/webhook?' . $query);

        $respuesta->assertStatus(403);
    }
}
