<?php

namespace Tests\Unit;

use App\Services\DatosTitular;
use PHPUnit\Framework\TestCase;

/**
 * La lista de «con quién se comparten tus datos» viaja en la descarga que se
 * lleva cada cliente, así que es un compromiso con él: tiene que decir lo que el
 * sistema hace HOY. Siguió diciendo «Railway» —donde ya no vive nada desde el
 * 14-sep-2026— hasta que alguien la leyó. Estos tests fijan los dos errores que
 * esa lista no puede tener: nombrar a un proveedor que ya no se usa, y olvidar a
 * uno que sí recibe datos.
 */
class DestinatariosDatosTest extends TestCase
{
    private function nombres(): array
    {
        return array_column(DatosTitular::DESTINATARIOS, 'quien');
    }

    public function test_no_nombra_proveedores_que_ya_no_se_usan(): void
    {
        $todo = json_encode(DatosTitular::DESTINATARIOS, JSON_UNESCAPED_UNICODE);
        foreach (['Railway', 'Vercel'] as $viejo) {
            $this->assertStringNotContainsString($viejo, $todo, "«{$viejo}» ya no recibe datos de nadie");
        }
    }

    public function test_incluye_a_todo_el_que_hoy_recibe_datos(): void
    {
        $nombres = implode(' | ', $this->nombres());
        foreach (['Proveedor de hosting', 'Google', 'Getnet', 'Resend', 'Meta', 'Anthropic'] as $quien) {
            $this->assertStringContainsString($quien, $nombres, "falta «{$quien}» en la lista de destinatarios");
        }
    }

    public function test_cada_entrada_dice_para_que_y_si_sale_de_chile(): void
    {
        foreach (DatosTitular::DESTINATARIOS as $d) {
            $this->assertNotSame('', trim($d['quien']));
            $this->assertNotSame('', trim($d['para_que']));
            $this->assertIsBool($d['fuera_de_chile']);
        }
    }
}
