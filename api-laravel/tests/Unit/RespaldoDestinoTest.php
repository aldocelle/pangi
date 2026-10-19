<?php

namespace Tests\Unit;

use App\Console\Commands\RespaldoCrear;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * El respaldo NUNCA puede aterrizar en un directorio servido por la web.
 *
 * POR QUÉ ESTE TEST Y NO OTRO
 *
 * De todo `respaldo:crear`, esta es la única línea cuyo fallo no se nota. Si el
 * volcado sale mal, la restauración lo grita. Si los ZIP salen vacíos, el
 * conteo lo dice. Pero un respaldo escrito bajo la raíz web funciona
 * perfectamente —se genera, pesa lo que tiene que pesar, se restaura— y además
 * deja la base entera de la clínica descargable con un GET, con RUT,
 * direcciones y fichas clínicas adentro.
 *
 * 🔴 POR QUÉ ESTE ARCHIVO SE REESCRIBIÓ ENTERO (07-sep-2026)
 *
 * La versión anterior tenía cinco tests en verde mientras el código estaba
 * mal, y la razón es la que hay que recordar: **reimplementaba la regla en un
 * método privado del propio test** en vez de llamar a la del comando. Probaba
 * una regla correcta que nadie ejecutaba. El comando, mientras tanto, tenía
 * como destino por defecto `dirname(base_path())`, que en este plan es
 * `public_html` — la raíz del dominio padre — y su comprobación solo miraba el
 * document root del SUBdominio, así que lo dejaba pasar.
 *
 * De ahí las dos correcciones de método:
 *
 *   1. Se prueba **por reflexión sobre el comando**. Si la regla se mueve, el
 *      test se mueve con ella o falla; no puede volver a quedar en verde
 *      describiendo un código paralelo.
 *   2. Se prueba **el valor por defecto**, no solo la regla. Era el agujero
 *      exacto: el defecto no estaba en cómo se decide, sino en qué se decide
 *      cuando nadie configuró nada — que es el caso real del servidor, porque
 *      `env_servidor.py` no escribía `RESPALDO_DIR` (ahora sí).
 */
class RespaldoDestinoTest extends TestCase
{
    /** La ruta REAL de la app en el servidor, que es la que hay que proteger. */
    private const BASE_SERVIDOR =
        '/home/usuario/domains/ejemplo.cl/public_html/lab';

    private function motivo(string $dir, string $publico): ?string
    {
        $m = new ReflectionMethod(RespaldoCrear::class, 'motivoParaRechazar');
        return $m->invoke(null, $dir, $publico);
    }

    private function porDefecto(string $base): string
    {
        $m = new ReflectionMethod(RespaldoCrear::class, 'porDefecto');
        return $m->invoke(null, $base);
    }

    // ── El defecto que se encontró: el DEFAULT, con la ruta real ──────────

    public function test_el_destino_por_defecto_en_el_servidor_queda_fuera_de_public_html(): void
    {
        $dir = $this->porDefecto(self::BASE_SERVIDOR);

        $this->assertSame(
            '/home/usuario/domains/ejemplo.cl/respaldos', $dir);
        $this->assertStringNotContainsString('public_html', $dir);
    }

    public function test_el_defecto_anterior_habria_sido_rechazado(): void
    {
        // `dirname(base_path()) . '/respaldos'`, que es lo que había. Se deja
        // escrito el valor exacto: si alguien vuelve a esa fórmula, este test
        // dice qué pasaba.
        $motivo = $this->motivo(
            '/home/usuario/domains/ejemplo.cl/public_html/respaldos',
            self::BASE_SERVIDOR . '/public');

        $this->assertNotNull($motivo, 'el destino de antes tiene que rechazarse');
        $this->assertStringContainsString('public_html', $motivo);
    }

    // ── La regla, ahora sí sobre el código que corre ──────────────────────

    public function test_un_destino_dentro_del_docroot_del_subdominio_se_rechaza(): void
    {
        $this->assertNotNull($this->motivo(
            self::BASE_SERVIDOR . '/public/respaldos', self::BASE_SERVIDOR . '/public'));
    }

    public function test_el_propio_docroot_tambien_se_rechaza(): void
    {
        $this->assertNotNull($this->motivo(
            self::BASE_SERVIDOR . '/public', self::BASE_SERVIDOR . '/public'));
    }

    public function test_un_hermano_con_prefijo_parecido_SI_se_acepta(): void
    {
        // `…/public-respaldos` empieza igual que `…/public` pero NO está
        // adentro. Comparar por prefijo sin la barra lo rechazaría, y sería un
        // falso positivo que empuja a alguien a desactivar la comprobación.
        // (Va fuera de public_html para aislar esta regla de la otra.)
        $this->assertNull($this->motivo(
            '/home/usuario/lab/public-respaldos', '/home/usuario/lab/public'));
    }

    public function test_fuera_de_toda_raiz_web_se_acepta(): void
    {
        $this->assertNull($this->motivo(
            '/home/usuario/respaldos', self::BASE_SERVIDOR . '/public'));
    }

    // ── Y las dos siguen siendo privadas ──────────────────────────────────

    public function test_las_reglas_son_privadas(): void
    {
        // Si alguien las hace públicas, este test avisa: se aplican dentro del
        // comando y no debe poder saltárselas desde fuera.
        foreach (['destino', 'porDefecto', 'motivoParaRechazar'] as $m) {
            $this->assertTrue(
                (new ReflectionMethod(RespaldoCrear::class, $m))->isPrivate(),
                "{$m}() dejó de ser privado");
        }
    }
}
