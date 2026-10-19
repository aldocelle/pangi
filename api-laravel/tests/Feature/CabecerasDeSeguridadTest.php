<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/** La API manda sus cabeceras de seguridad, y no deja en caché lo autenticado. */
#[RunTestsInSeparateProcesses]
class CabecerasDeSeguridadTest extends TestCase
{
    public function test_toda_respuesta_lleva_las_cabeceras(): void
    {
        $r = $this->getJson('/up');
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $r->assertHeader('X-Frame-Options', 'DENY');
        $r->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('geolocation=()', $r->headers->get('Permissions-Policy'));
    }

    public function test_hsts_solo_por_https_y_sin_incluir_subdominios(): void
    {
        $this->assertNull($this->getJson('/up')->headers->get('Strict-Transport-Security'));
        $h = $this->getJson('https://api.test/up')->headers->get('Strict-Transport-Security');
        $this->assertSame('max-age=31536000', $h);
    }

    public function test_lo_autenticado_no_se_cachea_y_lo_publico_si_puede(): void
    {
        $this->assertStringNotContainsString('no-store', (string) $this->getJson('/up')->headers->get('Cache-Control'));
        $r = $this->getJson('/up', ['Authorization' => 'Bearer x']);
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }

    public function test_un_401_tambien_sale_con_las_cabeceras(): void
    {
        $r = $this->getJson('/clientes');
        $r->assertStatus(401);
        $r->assertHeader('X-Frame-Options', 'DENY');
    }
}
