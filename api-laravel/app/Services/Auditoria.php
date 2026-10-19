<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registro de acciones del staff (tabla `logs_actividad`). Port de
 * `auditoria_service.registrar`.
 *
 * **Se llama DENTRO de la transacción de la operación que audita**, nunca
 * antes ni después: si esa operación falla y hace rollback, el log tampoco
 * queda huérfano describiendo algo que nunca pasó. En Python eso sale gratis
 * porque encola en la sesión del caller sin commit propio; acá hay que
 * llamarlo dentro del `DB::transaction` y no fuera.
 */
class Auditoria
{
    public function registrar(
        ?object $actor,
        string $accion,
        string $area,
        ?string $entidad = null,
        $entidadId = null,
        ?array $detalle = null,
    ): void {
        DB::table('logs_actividad')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $actor->id ?? null,
            // Sin actor la fila igual se escribe: la columna es NOT NULL y el
            // sistema también actúa (tareas de fondo, importadores).
            'usuario_nombre' => $actor->nombre ?? 'Sistema',
            'usuario_rol' => $actor->rol ?? null,
            'accion' => $accion,
            'area' => $area,
            'entidad' => $entidad,
            'entidad_id' => $entidadId === null ? null : (string) $entidadId,
            // JSONB en Postgres, JSON en MariaDB: en los dos viaja como texto
            // con las claves ORDENADAS, que es como lo dejó el migrador.
            'detalle' => $detalle === null
                ? null : json_encode($this->ordenar($detalle), JSON_UNESCAPED_UNICODE),
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }

    /** Claves ordenadas, para que el JSON no dependa del orden de escritura. */
    private function ordenar(array $d): array
    {
        ksort($d);
        return $d;
    }
}
