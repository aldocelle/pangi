<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Autenticar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Log de actividad: quién hizo qué, cuándo.
 *
 * **Solo lectura.** No hay endpoint para editar ni borrar una fila, ni siquiera
 * para un admin: un registro de auditoría que su propio sujeto puede modificar
 * no sirve para auditar nada.
 */
class AuditoriaController extends Controller
{
    private const LIMITE_MAX = 20;

    /** GET /auditoria */
    public function listar(Request $request)
    {
        $area = $request->query('area');
        if ($area !== null && ! isset(Autenticar::AREAS[$area])) {
            return response()->json(['detail' => "Área inválida: {$area}"], 400);
        }

        $q = DB::table('logs_actividad');

        if ($u = trim((string) $request->query('usuario', ''))) {
            $q->where('usuario_nombre', 'like', '%' . $this->escaparLike($u) . '%');
        }
        if ($uid = $request->query('usuario_id')) {
            $q->where('usuario_id', $uid);
        }
        if ($area) {
            $q->where('area', $area);
        }
        if ($a = $request->query('accion')) {
            $q->where('accion', $a);
        }
        // Los límites del día se arman con la hora mínima y máxima —igual que
        // `datetime.combine(desde, time.min)`—, no comparando fechas: la
        // columna es DATETIME y un `>= '2026-09-02'` dejaría fuera todo lo del
        // propio día 2.
        if ($d = $request->query('desde')) {
            $q->where('created_at', '>=', substr($d, 0, 10) . ' 00:00:00');
        }
        if ($h = $request->query('hasta')) {
            $q->where('created_at', '<=', substr($h, 0, 10) . ' 23:59:59.999999');
        }

        // Lo más reciente primero, con desempate hasta la PK (§9.14).
        $q->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min((int) $request->query('limit', self::LIMITE_MAX), self::LIMITE_MAX));
        $total = (clone $q)->reorder()->count();
        $filas = $q->offset(($page - 1) * $limit)->limit($limit)->get();

        return [
            'items' => $filas->map(fn ($l) => $this->logOut($l))->values(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_paginas' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /**
     * GET /auditoria/areas — para poblar el filtro del frontend sin que la
     * lista quede escrita a mano allá.
     */
    public function areas()
    {
        $salida = [];
        foreach (Autenticar::AREAS as $id => $label) {
            $salida[] = ['id' => $id, 'label' => $label];
        }

        return $salida;
    }

    private function logOut($l): array
    {
        return [
            'id' => $l->id,
            'usuario_id' => $l->usuario_id,
            'usuario_nombre' => $l->usuario_nombre,
            'usuario_rol' => $l->usuario_rol,
            'accion' => $l->accion,
            'area' => $l->area,
            // La etiqueta legible sale del MISMO mapa que los permisos: si un
            // área se renombra, el log viejo se lee con el nombre nuevo.
            'area_label' => Autenticar::AREAS[$l->area] ?? $l->area,
            'entidad' => $l->entidad,
            'entidad_id' => $l->entidad_id,
            'detalle' => $l->detalle === null ? null : json_decode($l->detalle, true),
            'created_at' => $this->iso($l->created_at),
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

    private function escaparLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }
}
