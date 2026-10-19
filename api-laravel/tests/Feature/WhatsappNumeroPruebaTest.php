<?php

namespace Tests\Feature;

use App\Console\Commands\DemoSembrar;
use App\Services\WhatsappEnvio;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * Los clientes inventados de la base de pruebas (`demo:sembrar`) tienen
 * teléfonos `+56 9 0000 0…`. Ningún envío de WhatsApp puede llegar a ellos,
 * aunque las credenciales de Meta estén cargadas.
 */
#[RunTestsInSeparateProcesses]
class WhatsappNumeroPruebaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['emr.whatsapp_access_token' => 'token-falso', 'emr.whatsapp_phone_number_id' => '123']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]])]);
    }

    public function test_a_un_numero_de_prueba_no_se_le_envia_nada(): void
    {
        $envio = app(WhatsappEnvio::class);
        $this->assertFalse($envio->texto(DemoSembrar::telefono(7), 'hola'));
        $this->assertFalse($envio->texto('56900000123', 'hola'));
        Http::assertNothingSent();
    }

    public function test_a_un_numero_real_si(): void
    {
        $this->assertTrue(app(WhatsappEnvio::class)->texto('56912345678', 'hola'));
        Http::assertSentCount(1);
    }

    public function test_el_rango_de_los_datos_de_prueba_es_el_que_se_bloquea(): void
    {
        $this->assertStringStartsWith(WhatsappEnvio::PREFIJO_PRUEBA,
            preg_replace('/\D/', '', DemoSembrar::telefono(1)));
    }
}
