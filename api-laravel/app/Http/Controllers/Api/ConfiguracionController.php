<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Services\ConfigClinica;
use App\Services\Permisos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET|PUT /configuracion/{recordatorios|ventas|impresion}.
 *
 * **Leer es de cualquier staff**: el modal de venta tiene que saber si exige
 * paciente o caja abierta para avisar ANTES de cobrar, y lo abre gente sin
 * permiso de Ajustes. **Escribir** depende de la sección: los recordatorios los
 * ajusta quien tiene el área `recordatorios`; las reglas de venta e impresión,
 * solo un admin, porque frenan la caja de todos.
 */
class ConfiguracionController extends Controller
{
    public function __construct(private Auditoria $auditoria) {}

    public function ver(string $seccion)
    {
        if (! in_array($seccion, ConfigClinica::SECCIONES, true)) {
            return response()->json(['detail' => 'Sección desconocida.'], 404);
        }

        return ConfigClinica::publica($seccion);
    }

    public function guardar(Request $request, string $seccion)
    {
        if (! in_array($seccion, ConfigClinica::SECCIONES, true)) {
            return response()->json(['detail' => 'Sección desconocida.'], 404);
        }
        $usuario = $request->attributes->get('usuario');
        $puede = $seccion === 'recordatorios'
            ? Permisos::tiene($usuario, 'recordatorios')
            : $usuario->rol === 'admin';
        if (! $puede) {
            return response()->json(['detail' => $seccion === 'recordatorios'
                ? 'No tienes acceso a «Recordatorios». Pídeselo a un administrador.'
                : ($seccion === 'correos'
                    ? 'Solo un administrador puede cambiar la apariencia de los correos.'
                    : 'Solo un administrador puede cambiar las reglas de venta e impresión.')], 403);
        }

        $enviado = $request->all();

        return DB::transaction(function () use ($seccion, $enviado, $usuario) {
            $antes = ConfigClinica::publica($seccion);
            $despues = ConfigClinica::guardar($seccion, $enviado);
            $cambios = [];
            foreach ($despues as $k => $v) {
                if (($antes[$k] ?? null) !== $v) {
                    $cambios[$k] = ['de' => $antes[$k] ?? null, 'a' => $v];
                }
            }
            // La clave nunca entra a la auditoría: solo que cambió.
            if (array_key_exists('clave_descuento', $enviado)) {
                $cambios['clave_descuento'] = 'cambiada';
            }
            $this->auditoria->registrar($usuario, 'editar',
                $seccion === 'recordatorios' ? 'recordatorios' : ($seccion === 'correos' ? 'correos' : 'ventas'),
                'configuracion', $seccion, $cambios);

            return $despues;
        });
    }
}
