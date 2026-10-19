<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tareas recurrentes: la REGLA ("revisar stock cada semana"), no sus
 * corridas — mismo permiso que `tareas`, porque es la misma pantalla
 * (Tablero → «Tareas recurrentes» → Administrar). Sin motor que las dispare
 * todavía (ver `migracion/mariadb_tareas_tablero.sql`): el equipo las
 * administra a mano, igual que una lista de turnos fijos.
 */
class TareasRecurrentesController extends Controller
{
    public function __construct(private Auditoria $auditoria)
    {
    }

    /** GET /tareas-recurrentes — activas primero, luego por título (§9.14: desempate por PK). */
    public function listar()
    {
        return DB::table('tareas_recurrentes')
            ->orderByDesc('activa')->orderBy('titulo')->orderBy('id')
            ->get()->map(fn ($r) => $this->out($r))->values();
    }

    /** POST /tareas-recurrentes */
    public function crear(Request $request)
    {
        $datos = $request->validate([
            'titulo' => 'required|string|max:255',
            'frecuencia' => 'required|in:diaria,semanal',
            'area' => 'nullable|string|max:60',
        ]);

        $actor = $request->attributes->get('usuario');
        $id = (string) Str::uuid();

        DB::transaction(function () use ($datos, $id, $actor) {
            DB::table('tareas_recurrentes')->insert([
                'id' => $id,
                'titulo' => $datos['titulo'],
                'frecuencia' => $datos['frecuencia'],
                'area' => $datos['area'] ?? null,
                'activa' => true,
                'creado_por_id' => $actor->id,
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
            $this->auditoria->registrar($actor, 'crear', 'tareas', 'tarea_recurrente', null,
                ['titulo' => $datos['titulo']]);
        });

        return response()->json($this->out(DB::table('tareas_recurrentes')->where('id', $id)->first()), 201);
    }

    /** PATCH /tareas-recurrentes/{id} — hoy solo activar/desactivar. */
    public function actualizar(Request $request, string $id)
    {
        $fila = DB::table('tareas_recurrentes')->where('id', $id)->first();
        if (! $fila) {
            return response()->json(['detail' => 'No encontrada'], 404);
        }
        $datos = $request->validate(['activa' => 'required|boolean']);

        DB::transaction(function () use ($request, $datos, $id) {
            DB::table('tareas_recurrentes')->where('id', $id)->update(['activa' => $datos['activa']]);
            $this->auditoria->registrar($request->attributes->get('usuario'), 'editar', 'tareas',
                'tarea_recurrente', $id, $datos);
        });

        return $this->out(DB::table('tareas_recurrentes')->where('id', $id)->first());
    }

    /** DELETE /tareas-recurrentes/{id} */
    public function eliminar(Request $request, string $id)
    {
        $fila = DB::table('tareas_recurrentes')->where('id', $id)->first();
        if (! $fila) {
            return response()->json(['detail' => 'No encontrada'], 404);
        }

        DB::transaction(function () use ($request, $fila) {
            $this->auditoria->registrar($request->attributes->get('usuario'), 'eliminar', 'tareas',
                'tarea_recurrente', $fila->id, ['titulo' => $fila->titulo]);
            DB::table('tareas_recurrentes')->where('id', $fila->id)->delete();
        });

        return response()->noContent();
    }

    private function out($r): array
    {
        return [
            'id' => $r->id,
            'titulo' => $r->titulo,
            'frecuencia' => $r->frecuencia,
            'area' => $r->area,
            'activa' => (bool) $r->activa,
        ];
    }
}
