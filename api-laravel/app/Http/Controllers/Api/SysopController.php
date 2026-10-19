<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Services\CorridasTareas;
use Illuminate\Http\Request;

/**
 * Sysop → Tareas programadas: cuándo corrió cada tarea de fondo, cuánto tardó,
 * cómo terminó, y «Ejecutar ahora» para las que se pueden lanzar desde la web.
 * Todo `auth:sysop`.
 */
class SysopController extends Controller
{
    public function __construct(private Auditoria $auditoria)
    {
    }

    /** GET /sysop/tareas */
    public function tareas()
    {
        return response()->json(CorridasTareas::resumen());
    }

    /** GET /sysop/tareas/{tarea}/corridas */
    public function corridas(string $tarea)
    {
        abort_unless(isset(CorridasTareas::TAREAS[$tarea]), 404, 'Tarea desconocida.');

        return response()->json(CorridasTareas::historial($tarea));
    }

    /**
     * POST /sysop/tareas/{tarea}/ejecutar — corre la tarea dentro de esta
     * petición. Solo las marcadas `panel` (cortas y sin correos a clientes).
     * Si el cron la tiene corriendo en ese momento, el lock hace que esta se
     * salte y así lo dice la respuesta.
     */
    public function ejecutar(Request $request, string $tarea)
    {
        $def = CorridasTareas::TAREAS[$tarea] ?? null;
        abort_unless($def, 404, 'Tarea desconocida.');
        abort_unless($def['panel'], 422, 'Esta tarea no se lanza desde el panel: corre solo por su cron.');

        $usuario = $request->attributes->get('usuario');
        $inicio = microtime(true);
        [$codigo, $salida] = CorridasTareas::ejecutarDesdePanel($tarea, $usuario->id ?? null);
        $ms = (int) round((microtime(true) - $inicio) * 1000);

        $this->auditoria->registrar($usuario, 'ejecutar', 'sysop', 'tarea', $tarea,
            ['codigo' => $codigo, 'duracion_ms' => $ms, 'salida' => mb_substr($salida, 0, 300)]);

        return response()->json([
            'ok' => $codigo === 0,
            'salida' => $salida,
            'duracion_ms' => $ms,
        ]);
    }
}
