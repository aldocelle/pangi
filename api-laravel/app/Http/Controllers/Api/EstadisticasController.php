<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EstadisticasService;
use Illuminate\Http\Request;

/**
 * Dashboard de ingresos (permiso «estadisticas»).
 *
 * Un solo endpoint rico que arma toda la pantalla de una: comparación contra
 * el mes anterior y el mismo mes del año pasado, ingresos por tipo, top-10 de
 * productos y servicios, ingresos por especialista y valorización de
 * inventario. Pedirlo en pedazos multiplicaría los viajes sin ganar nada: la
 * pantalla los muestra todos juntos.
 */
class EstadisticasController extends Controller
{
    public function resumen(Request $request, EstadisticasService $svc)
    {
        $anio = $request->query('anio');
        $mes = $request->query('mes');

        return $svc->resumen(
            $anio === null ? null : (int) $anio,
            $mes === null ? null : (int) $mes,
        );
    }
}
