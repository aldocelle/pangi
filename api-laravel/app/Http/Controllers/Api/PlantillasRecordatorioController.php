<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Services\ConfigClinica;
use App\Services\Esquema;
use App\Services\MensajeRecordatorio;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plantillas de recordatorio — los «Recordatorios predefinidos» de ACVet
 * (captura 1 del dossier). Una plantilla es el texto y la cadencia de un aviso
 * que se repite («Vacuna antirrábica», cada 365 días); al crear un recordatorio
 * para una mascota se elige una y se prellenan título, mensaje y fecha.
 *
 * Permiso `recordatorios`, el mismo de la pantalla: quien llama a los tutores
 * es quien sabe qué texto les funciona.
 */
class PlantillasRecordatorioController extends Controller
{
    public function __construct(private Auditoria $auditoria) {}

    /** GET /recordatorios/plantillas */
    public function listar()
    {
        if (! Esquema::tabla('plantillas_recordatorio')) {
            return [];
        }

        return DB::table('plantillas_recordatorio')
            ->orderBy('orden')->orderBy('titulo')->orderBy('id')   // §9.14
            ->get()->map(fn ($p) => $this->out($p))->values();
    }

    /** POST /recordatorios/plantillas */
    public function crear(Request $request)
    {
        if ($r = $this->sinTabla()) {
            return $r;
        }
        $datos = $request->validate($this->reglas(true));
        $id = (string) Str::uuid();
        $ahora = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

        DB::transaction(function () use ($request, $datos, $id, $ahora) {
            DB::table('plantillas_recordatorio')->insert([
                'id' => $id,
                'titulo' => trim($datos['titulo']),
                'mensaje' => $this->vacio($datos['mensaje'] ?? null),
                'dias_repeticion' => $datos['dias_repeticion'] ?? null,
                'orden' => (int) ($datos['orden'] ?? 0),
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'crear', 'recordatorios', 'plantilla_recordatorio', $id, ['titulo' => trim($datos['titulo'])]);
        });

        return response()->json($this->out(DB::table('plantillas_recordatorio')->where('id', $id)->first()), 201);
    }

    /** PATCH /recordatorios/plantillas/{id} */
    public function actualizar(Request $request, string $id)
    {
        if ($r = $this->sinTabla()) {
            return $r;
        }
        if (! DB::table('plantillas_recordatorio')->where('id', $id)->exists()) {
            return response()->json(['detail' => 'Plantilla no encontrada.'], 404);
        }
        $datos = $request->validate($this->reglas(false));
        $cambios = [];
        foreach (['titulo', 'mensaje', 'dias_repeticion', 'orden'] as $c) {
            if ($request->exists($c)) {
                $cambios[$c] = match ($c) {
                    'titulo' => trim($datos['titulo']),
                    'mensaje' => $this->vacio($datos['mensaje'] ?? null),
                    'orden' => (int) ($datos['orden'] ?? 0),
                    default => $datos[$c] ?? null,
                };
            }
        }
        DB::transaction(function () use ($request, $id, $cambios) {
            if ($cambios) {
                DB::table('plantillas_recordatorio')->where('id', $id)->update($cambios + [
                    'updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]);
            }
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'editar', 'recordatorios', 'plantilla_recordatorio', $id, $cambios);
        });

        return $this->out(DB::table('plantillas_recordatorio')->where('id', $id)->first());
    }

    /**
     * DELETE /recordatorios/plantillas/{id}. Borra de verdad: los recordatorios
     * ya creados copiaron su texto y no la referencian, así que no quedan
     * colgando.
     */
    public function eliminar(Request $request, string $id)
    {
        if ($r = $this->sinTabla()) {
            return $r;
        }
        $p = DB::table('plantillas_recordatorio')->where('id', $id)->first();
        if (! $p) {
            return response()->json(['detail' => 'Plantilla no encontrada.'], 404);
        }
        DB::transaction(function () use ($request, $id, $p) {
            DB::table('plantillas_recordatorio')->where('id', $id)->delete();
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'eliminar', 'recordatorios', 'plantilla_recordatorio', $id, ['titulo' => $p->titulo]);
        });

        return response()->noContent();
    }

    /**
     * POST /recordatorios/vista-previa — el correo tal cual saldría, con datos
     * de ejemplo («Luna», «María González»). Es el «Ejemplo de recordatorio» de
     * ACVet, pero armado por el MISMO código que envía: si la vista previa y el
     * envío tuvieran cada uno su texto, se separarían en la primera edición.
     */
    public function vistaPrevia(Request $request)
    {
        $datos = $request->validate([
            'titulo' => 'nullable|string|max:120',
            'mensaje' => 'nullable|string|max:2000',
            'firma' => 'nullable|string|max:300',
        ]);
        $config = ConfigClinica::leer('recordatorios');
        if ($request->exists('firma')) {
            $config['firma'] = (string) ($datos['firma'] ?? '');
        }
        $fecha = CarbonImmutable::now('America/Santiago')->addDays(7)->format('Y-m-d');
        [$asunto, $cuerpo] = MensajeRecordatorio::correo(
            (object) ['nombre' => 'María González', 'telefono' => '+56 9 1234 5678', 'email' => 'maria@ejemplo.cl'],
            (object) ['nombre' => 'Luna', 'especie' => 'Canino', 'raza' => 'Mestiza'],
            (object) ['titulo' => $datos['titulo'] ?? 'Vacuna antirrábica', 'categoria' => null,
                'mensaje' => $datos['mensaje'] ?? null, 'fecha_programada' => $fecha],
            $config);

        return [
            'asunto' => $asunto,
            'cuerpo' => $cuerpo,
            'mensaje' => MensajeRecordatorio::ejemplo((string) ($datos['mensaje'] ?? '')),
            'dias_anticipacion' => $config['dias_anticipacion'],
        ];
    }

    /** GET /recordatorios/variables — para los chips del panel. */
    public function variables()
    {
        return MensajeRecordatorio::VARIABLES;
    }

    private function reglas(bool $creando): array
    {
        return [
            'titulo' => ($creando ? 'required' : 'sometimes') . '|string|min:2|max:120',
            'mensaje' => 'nullable|string|max:2000',
            'dias_repeticion' => 'nullable|integer|min:1|max:3650',
            'orden' => 'nullable|integer|min:0|max:9999',
        ];
    }

    private function out($p): array
    {
        return [
            'id' => $p->id,
            'titulo' => $p->titulo,
            'mensaje' => $p->mensaje,
            'dias_repeticion' => $p->dias_repeticion === null ? null : (int) $p->dias_repeticion,
            'orden' => (int) $p->orden,
        ];
    }

    private function sinTabla()
    {
        return Esquema::tabla('plantillas_recordatorio') ? null
            : response()->json(['detail' => 'Falta correr la migración de plantillas (mariadb_paridad_acvet.sql).'], 503);
    }

    private function vacio(?string $v): ?string
    {
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
