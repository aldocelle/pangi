<?php

namespace Tests\Unit;

use App\Services\ConfigClinica;
use App\Services\Esquema;
use App\Services\Plantilla;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * `Plantilla::pagina()/tarjeta()/boton()` toman sus fondos y el pie de
 * `ConfigClinica::leer('correos')`, no de las constantes fijas — eso es lo
 * que hace que un cambio en Ajustes → Correos se vea en el correo real.
 */
#[RunTestsInSeparateProcesses]
class PlantillaColoresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Esquema::olvidar();
        Schema::dropIfExists('ajustes');
        Schema::create('ajustes', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->text('data')->nullable();
        });
    }

    public function test_sin_configuracion_usa_los_colores_de_siempre(): void
    {
        $html = Plantilla::pagina('Título', 'Hola', Plantilla::parrafo('cuerpo'));

        $this->assertStringContainsString('background:#eef1fb', $html);
        $this->assertStringContainsString('PANGI', $html);
        $this->assertStringContainsString('background:#1a2350', Plantilla::boton('https://x', 'Ir'));
    }

    public function test_un_fondo_guardado_aparece_en_la_pagina_la_tarjeta_y_el_boton(): void
    {
        ConfigClinica::guardar('correos', [
            'fondo_pagina' => '#ffffff',
            'fondo_cabecera' => '#e6f7f9',
            'fondo_boton' => '#087c8a',
            'fondo_aviso' => '#fbeee6',
            'pie_texto' => 'Clínica Demo',
        ]);

        $pagina = Plantilla::pagina('Título', 'Hola', Plantilla::parrafo('cuerpo'));
        $tarjeta = Plantilla::tarjeta('Cabecera', Plantilla::fila('🐾', 'Luna'), 'un aviso');
        $boton = Plantilla::boton('https://x', 'Ir');

        $this->assertStringContainsString('background:#ffffff', $pagina);
        $this->assertStringContainsString('Clínica Demo', $pagina);
        // El texto elegido reemplaza al nombre en el pie (el logo de arriba sigue siendo el de la clínica).
        $this->assertStringContainsString("text-align:center'>Clínica Demo</p>", $pagina);
        $this->assertStringNotContainsString("text-align:center'>PANGI</p><p style='margin:4px", $pagina);
        $this->assertStringContainsString('background:#e6f7f9', $tarjeta);
        $this->assertStringContainsString('background:#fbeee6', $tarjeta);
        $this->assertStringContainsString('background:#087c8a', $boton);
    }

    /** El texto nunca es editable: solo cambian los fondos. */
    public function test_el_color_del_texto_no_cambia(): void
    {
        ConfigClinica::guardar('correos', ['fondo_pagina' => '#ffffff']);

        $this->assertStringContainsString('color:#26326e', Plantilla::pagina('T', 'Hola', ''));
    }
}
