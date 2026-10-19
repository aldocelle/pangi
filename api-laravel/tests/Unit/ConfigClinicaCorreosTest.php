<?php

namespace Tests\Unit;

use App\Services\ConfigClinica;
use App\Services\Esquema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * La sección `correos` de ConfigClinica: Ajustes → Correos solo deja elegir un
 * fondo de un catálogo cerrado (`SWATCHES_CLAROS`/`SWATCHES_OSCUROS`), nunca un
 * color libre — ver el porqué en `Plantilla::colores()`.
 */
#[RunTestsInSeparateProcesses]
class ConfigClinicaCorreosTest extends TestCase
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

    public function test_los_defaults_son_los_colores_que_el_correo_siempre_tuvo(): void
    {
        $this->assertSame([
            'fondo_pagina' => '#eef1fb',
            'fondo_cabecera' => '#eef1fb',
            'fondo_boton' => '#1a2350',
            'fondo_aviso' => '#fbeee6',
            'pie_texto' => 'PANGI',
        ], ConfigClinica::leer('correos'));
    }

    public function test_guarda_un_fondo_de_la_lista(): void
    {
        $r = ConfigClinica::guardar('correos', ['fondo_boton' => '#087c8a', 'pie_texto' => 'Clínica Demo']);

        $this->assertSame('#087c8a', $r['fondo_boton']);
        $this->assertSame('Clínica Demo', $r['pie_texto']);
        // Lo no tocado queda como estaba.
        $this->assertSame('#eef1fb', $r['fondo_pagina']);
    }

    public function test_rechaza_un_fondo_fuera_del_catalogo(): void
    {
        $this->expectException(ValidationException::class);
        ConfigClinica::guardar('correos', ['fondo_pagina' => '#123456']);
    }

    /** El catálogo de oscuros es para el botón (texto blanco); uno claro ahí lo dejaría ilegible. */
    public function test_no_deja_poner_un_color_claro_en_el_boton(): void
    {
        $this->expectException(ValidationException::class);
        ConfigClinica::guardar('correos', ['fondo_boton' => '#eef1fb']);
    }

    /** Y al revés: un oscuro en un fondo que lleva texto oscuro encima. */
    public function test_no_deja_poner_un_color_oscuro_en_el_fondo_de_pagina(): void
    {
        $this->expectException(ValidationException::class);
        ConfigClinica::guardar('correos', ['fondo_pagina' => '#1a2350']);
    }

    public function test_el_pie_tiene_un_largo_maximo(): void
    {
        $this->expectException(ValidationException::class);
        ConfigClinica::guardar('correos', ['pie_texto' => str_repeat('x', 121)]);
    }

    public function test_rechaza_una_clave_desconocida(): void
    {
        $this->expectException(ValidationException::class);
        ConfigClinica::guardar('correos', ['color_random' => '#000000']);
    }
}
