<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConfigClinica;
use App\Services\VistaPreviaCorreos;

/**
 * Ajustes → Correos: la vista previa de cada correo que manda el sistema.
 * Solo datos de ejemplo, así que lo ve cualquiera del equipo (`auth:staff`).
 *
 * La apariencia misma (fondos, pie) se lee y se guarda por el endpoint
 * genérico `GET|PUT /configuracion/correos` (`ConfiguracionController`), igual
 * que recordatorios, ventas e impresión — no hace falta duplicarlo acá.
 */
class CorreosController extends Controller
{
    /** GET /ajustes/correos */
    public function vistaPrevia(VistaPreviaCorreos $vista)
    {
        return response()->json($vista->todos());
    }

    /**
     * GET /ajustes/correos/paleta — el catálogo cerrado de fondos que se puede
     * elegir, separado en claros (van con texto oscuro) y oscuros (van con
     * texto blanco, es el botón). El front arma el selector con esta lista:
     * nunca ofrece un color que no esté acá, y el backend tampoco lo acepta
     * (`ConfigClinica::validar`).
     */
    public function paleta()
    {
        $mapa = fn (array $m) => collect($m)->map(fn ($etiqueta, $hex) => compact('hex', 'etiqueta'))->values();

        return response()->json([
            'claros' => $mapa(ConfigClinica::SWATCHES_CLAROS),
            'oscuros' => $mapa(ConfigClinica::SWATCHES_OSCUROS),
        ]);
    }
}
