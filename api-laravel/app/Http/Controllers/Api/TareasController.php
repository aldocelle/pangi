<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Tareas internas del equipo (permiso «tareas»). */
class TareasController extends Controller
{
    public function __construct(private Auditoria $auditoria)
    {
    }

    /**
     * GET /tareas
     *
     * **Sin paginación a propósito**: el volumen de tareas internas de una
     * clínica chica no la justifica, y la vista las quiere todas de un vistazo,
     * como la Agenda.
     */
    public function listar(Request $request)
    {
        $q = DB::table('tareas');

        // `completada` es un booleano de query string: hay que distinguir
        // «no vino» de «vino false», o el filtro «pendientes» no funciona.
        if ($request->query('completada') !== null) {
            $q->where('completada', filter_var($request->query('completada'),
                FILTER_VALIDATE_BOOLEAN));
        }
        if ($a = $request->query('asignado_a_id')) {
            $q->where('asignado_a_id', $a);
        }

        // Pendientes primero; dentro, por vencimiento con los SIN FECHA al
        // final (`nullslast()`), y desempate hasta la PK (§9.14).
        //
        // MariaDB ordena los NULL primero en ASC, al revés que Postgres con
        // `NULLS LAST`, así que hace falta la columna auxiliar: sin ella, las
        // tareas sin fecha encabezarían la lista.
        $q->orderBy('completada')
          ->orderByRaw('CASE WHEN fecha_vencimiento IS NULL THEN 1 ELSE 0 END')
          ->orderBy('fecha_vencimiento')
          ->orderBy('created_at', 'desc')
          ->orderBy('id', 'desc');

        $filas = $q->get();
        $nombres = $this->nombresDe($filas);

        return $filas->map(fn ($t) => $this->tareaOut($t, $nombres))->values();
    }

    /** POST /tareas */
    public function crear(Request $request)
    {
        $datos = $request->validate([
            'titulo' => 'required|string',
            'descripcion' => 'nullable|string',
            'asignado_a_id' => 'nullable|uuid',
            'fecha_vencimiento' => 'nullable|date',
            'completada' => 'nullable|boolean',
            'en_curso' => 'nullable|boolean',
            'prioridad' => 'nullable|in:alta,media,baja',
            'area' => 'nullable|string|max:60',
        ]);

        $actor = $request->attributes->get('usuario');
        $id = (string) Str::uuid();

        DB::transaction(function () use ($datos, $id, $actor) {
            DB::table('tareas')->insert([
                'id' => $id,
                'titulo' => $datos['titulo'],
                'descripcion' => $datos['descripcion'] ?? null,
                'asignado_a_id' => $datos['asignado_a_id'] ?? null,
                'fecha_vencimiento' => isset($datos['fecha_vencimiento'])
                    ? substr($datos['fecha_vencimiento'], 0, 10) : null,
                'completada' => $datos['completada'] ?? false,
                'en_curso' => $datos['en_curso'] ?? false,
                'prioridad' => $datos['prioridad'] ?? 'media',
                'area' => $datos['area'] ?? null,
                'creado_por_id' => $actor->id,
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
            $this->auditoria->registrar($actor, 'crear', 'tareas', 'tarea', null,
                ['titulo' => $datos['titulo']]);
        });

        $t = DB::table('tareas')->where('id', $id)->first();

        return response()->json($this->tareaOut($t, $this->nombresDe(collect([$t]))), 201);
    }

    /** PATCH /tareas/{id} */
    public function actualizar(Request $request, string $tareaId)
    {
        $tarea = DB::table('tareas')->where('id', $tareaId)->first();
        if (! $tarea) {
            return response()->json(['detail' => 'Tarea no encontrada'], 404);
        }

        $reglas = [
            'titulo' => 'nullable|string',
            'descripcion' => 'nullable|string',
            'asignado_a_id' => 'nullable|uuid',
            'fecha_vencimiento' => 'nullable|date',
            'completada' => 'nullable|boolean',
            'en_curso' => 'nullable|boolean',
            'prioridad' => 'nullable|in:alta,media,baja',
            'area' => 'nullable|string|max:60',
        ];
        $datos = $request->validate($reglas);

        $cambios = [];
        foreach (array_keys($reglas) as $campo) {
            if ($request->exists($campo)) {
                $cambios[$campo] = $datos[$campo] ?? null;
            }
        }
        if (array_key_exists('fecha_vencimiento', $cambios) && $cambios['fecha_vencimiento']) {
            $cambios['fecha_vencimiento'] = substr($cambios['fecha_vencimiento'], 0, 10);
        }
        // Marcar completada SELLA la fecha; desmarcarla la borra. La escribe el
        // sistema, no el cliente: es la evidencia de cuándo se terminó.
        if (array_key_exists('completada', $cambios)) {
            $cambios['completada_at'] = $cambios['completada']
                ? CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u') : null;
            // Completar cierra el "en curso": no puede quedar completada Y
            // en curso a la vez, son columnas separadas (ver la migración).
            if ($cambios['completada']) {
                $cambios['en_curso'] = false;
            }
        }

        DB::transaction(function () use ($request, $cambios, $tareaId, $datos, $reglas) {
            if ($cambios) {
                DB::table('tareas')->where('id', $tareaId)->update($cambios);
            }
            // El detalle audita lo que MANDÓ el cliente, sin `completada_at`,
            // que es una consecuencia y no un cambio pedido.
            $pedidos = array_intersect_key($cambios, $reglas);
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'editar', 'tareas', 'tarea', $tareaId, $pedidos);
        });

        $t = DB::table('tareas')->where('id', $tareaId)->first();

        return $this->tareaOut($t, $this->nombresDe(collect([$t])));
    }

    /** DELETE /tareas/{id} — borrado real: una tarea no es historial clínico. */
    public function eliminar(Request $request, string $tareaId)
    {
        $tarea = DB::table('tareas')->where('id', $tareaId)->first();
        if (! $tarea) {
            return response()->json(['detail' => 'Tarea no encontrada'], 404);
        }

        DB::transaction(function () use ($request, $tarea) {
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'eliminar', 'tareas', 'tarea', $tarea->id, ['titulo' => $tarea->titulo]);
            DB::table('tareas')->where('id', $tarea->id)->delete();
        });

        return response()->noContent();
    }

    /** Los nombres de los asignados, en lote. */
    private function nombresDe($filas)
    {
        $ids = $filas->pluck('asignado_a_id')->filter()->unique();

        return $ids->isEmpty() ? collect()
            : DB::table('usuarios')->whereIn('id', $ids)->pluck('nombre', 'id');
    }

    private function tareaOut($t, $nombres): array
    {
        return [
            'id' => $t->id,
            'titulo' => $t->titulo,
            'descripcion' => $t->descripcion,
            'asignado_a_id' => $t->asignado_a_id,
            'asignado_a_nombre' => $nombres[$t->asignado_a_id] ?? null,
            'fecha_vencimiento' => $t->fecha_vencimiento
                ? substr($t->fecha_vencimiento, 0, 10) : null,
            'completada' => (bool) $t->completada,
            'en_curso' => (bool) ($t->en_curso ?? false),
            'prioridad' => $t->prioridad ?? 'media',
            'area' => $t->area ?? null,
            'completada_at' => $this->iso($t->completada_at),
            'creado_por_id' => $t->creado_por_id,
            'created_at' => $this->iso($t->created_at),
        ];
    }

    private function iso(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = str_replace(' ', 'T', substr($v, 0, 26));

        return str_ends_with($t, '.000000') ? substr($t, 0, 19) : $t;
    }
}
