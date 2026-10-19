<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Directorio de proveedores y pedido sugerido por stock mínimo
 * (permiso «proveedores»).
 */
class ProveedoresController extends Controller
{
    /**
     * **20, no 30.** Es `LIMITE_MAX` de `paginacion.py`, y actúa como tope Y
     * como valor por defecto: `limit` sin especificar da 20, y pedir 50 da 20.
     */
    private const LIMITE_MAX = 20;

    public function __construct(private Auditoria $auditoria)
    {
    }

    /**
     * GET /proveedores
     *
     * **Solo los activos.** Dar de baja a un proveedor lo saca del listado sin
     * borrarlo, porque su nombre sigue escrito en cada ítem de inventario que
     * le compró la clínica.
     */
    public function listar(Request $request)
    {
        $q = DB::table('proveedores')->where('activo', true);

        if ($busqueda = trim((string) $request->query('q', ''))) {
            // `ilike` de Postgres -> `like` de MariaDB: la colación de la base
            // (`utf8mb4_uca1400_as_ci`) ya es case-insensitive, así que `like`
            // se comporta igual sin necesidad de `LOWER()`.
            $q->where('nombre', 'like', '%' . $this->escaparLike($busqueda) . '%');
        }

        $q->orderBy('nombre')->orderBy('id');

        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min((int) $request->query('limit', self::LIMITE_MAX), self::LIMITE_MAX));
        $total = (clone $q)->reorder()->count();
        $items = $q->offset(($page - 1) * $limit)->limit($limit)->get();

        return [
            // El listado devuelve el objeto ORM CRUDO —no declara
            // `response_model`—, así que sale con `updated_at`, que
            // `ProveedorResponse` no tiene. Las respuestas de POST y PATCH sí
            // pasan por el schema y NO lo llevan.
            'items' => $items->map(fn ($p) => $this->proveedorListadoOut($p))->values(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_paginas' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /**
     * POST /proveedores
     *
     * El nombre es la clave real: `items_inventario.proveedor` guarda TEXTO, no
     * un id, así que dos proveedores con el mismo nombre volverían ambiguo el
     * pedido sugerido. De ahí el 409.
     */
    public function crear(Request $request)
    {
        $datos = $request->validate([
            'nombre' => 'required|string',
            'contacto_nombre' => 'nullable|string',
            'telefono' => 'nullable|string',
            'email' => 'nullable|string',
            'notas' => 'nullable|string',
            'activo' => 'nullable|boolean',
        ]);

        // El duplicado se busca entre TODOS, activos y de baja: el nombre sigue
        // ligando los ítems de inventario aunque el proveedor esté dado de baja.
        if (DB::table('proveedores')->where('nombre', $datos['nombre'])->exists()) {
            return response()->json(['detail' => 'Ya existe un proveedor con ese nombre.'], 409);
        }

        $id = (string) Str::uuid();
        $ahora = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

        DB::transaction(function () use ($request, $datos, $id, $ahora) {
            DB::table('proveedores')->insert([
                'id' => $id,
                'nombre' => $datos['nombre'],
                'contacto_nombre' => $datos['contacto_nombre'] ?? null,
                'telefono' => $datos['telefono'] ?? null,
                'email' => $datos['email'] ?? null,
                'notas' => $datos['notas'] ?? null,
                'activo' => $datos['activo'] ?? true,
                'created_at' => $ahora,
                // `updated_at` la escribe SQLAlchemy sola (`default` +
                // `onupdate` en el modelo). Acá hay que ponerla a mano en cada
                // INSERT y en cada UPDATE, o el listado la devolvería en null.
                'updated_at' => $ahora,
            ]);
            // Sin `entidad_id`: Python audita antes del commit, cuando el id
            // todavía no está asignado. Se replica tal cual.
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'crear', 'proveedores', 'proveedor', null, ['nombre' => $datos['nombre']]);
        });

        return response()->json(
            $this->proveedorOut(DB::table('proveedores')->where('id', $id)->first()), 201);
    }

    /** PATCH /proveedores/{id} */
    public function actualizar(Request $request, string $proveedorId)
    {
        $proveedor = DB::table('proveedores')->where('id', $proveedorId)->first();
        if (! $proveedor) {
            return response()->json(['detail' => 'Proveedor no encontrado'], 404);
        }

        $reglas = [
            'nombre' => 'nullable|string',
            'contacto_nombre' => 'nullable|string',
            'telefono' => 'nullable|string',
            'email' => 'nullable|string',
            'notas' => 'nullable|string',
            'activo' => 'nullable|boolean',
        ];
        $datos = $request->validate($reglas);

        $cambios = [];
        foreach (array_keys($reglas) as $campo) {
            if ($request->exists($campo)) {
                $cambios[$campo] = $datos[$campo] ?? null;
            }
        }

        // El choque de nombre solo se comprueba si el nombre CAMBIA: si no,
        // un PATCH que reenvía el mismo nombre chocaría consigo mismo.
        if (array_key_exists('nombre', $cambios) && $cambios['nombre'] !== $proveedor->nombre) {
            $existe = DB::table('proveedores')
                ->where('nombre', $cambios['nombre'])
                ->where('id', '!=', $proveedorId)
                ->exists();
            if ($existe) {
                return response()->json(['detail' => 'Ya existe un proveedor con ese nombre.'], 409);
            }
        }

        DB::transaction(function () use ($request, $cambios, $proveedorId) {
            if ($cambios) {
                // `onupdate` del modelo: cada edición mueve `updated_at`.
                DB::table('proveedores')->where('id', $proveedorId)->update(
                    $cambios + ['updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]
                );
            }
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'editar', 'proveedores', 'proveedor', $proveedorId, $cambios);
        });

        return $this->proveedorOut(DB::table('proveedores')->where('id', $proveedorId)->first());
    }

    /**
     * DELETE /proveedores/{id} — **desactiva, no borra**, igual que un ítem de
     * inventario. Y a diferencia de aquel, acá basta el permiso `proveedores`:
     * no exige admin.
     */
    public function eliminar(Request $request, string $proveedorId)
    {
        $proveedor = DB::table('proveedores')->where('id', $proveedorId)->first();
        if (! $proveedor) {
            return response()->json(['detail' => 'Proveedor no encontrado'], 404);
        }

        DB::transaction(function () use ($request, $proveedor) {
            DB::table('proveedores')->where('id', $proveedor->id)->update([
                'activo' => false,
                'updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'eliminar', 'proveedores', 'proveedor', $proveedor->id,
                ['nombre' => $proveedor->nombre]);
        });

        return response()->noContent();
    }

    /**
     * GET /proveedores/{id}/pedido-sugerido
     *
     * Los ítems de este proveedor bajo su punto de reorden, con cuánto haría
     * falta para volver a quedar sobre el mínimo. Es una lista para armar el
     * pedido a mano (llamar, WhatsApp, correo) — **no manda nada por sí sola**.
     *
     * El cruce va por NOMBRE y no por id: `items_inventario.proveedor` es texto
     * libre, no una clave foránea. Renombrar un proveedor deja sus ítems
     * huérfanos del pedido sugerido, y eso es del esquema, no del port.
     */
    public function pedidoSugerido(string $proveedorId)
    {
        $proveedor = DB::table('proveedores')->where('id', $proveedorId)->first();
        if (! $proveedor) {
            return response()->json(['detail' => 'Proveedor no encontrado'], 404);
        }

        $items = DB::table('items_inventario')
            ->where('proveedor', $proveedor->nombre)
            ->where('activo', true)
            // `<=`, no `<`: quedarse justo en el mínimo ya es motivo de pedido.
            ->whereColumn('stock_actual', '<=', 'stock_minimo')
            ->orderBy('nombre')->orderBy('id')
            ->get();

        return [
            'proveedor' => $proveedor->nombre,
            'items' => $items->map(fn ($i) => [
                'id' => $i->id,
                'nombre' => $i->nombre,
                'unidad' => $i->unidad,
                'stock_actual' => (int) $i->stock_actual,
                'stock_minimo' => (int) $i->stock_minimo,
                // Piso de 1: un ítem con mínimo 0 y stock 0 daría «pedir 0»,
                // que en una lista de compras no dice nada.
                'cantidad_sugerida' => max(1, (int) $i->stock_minimo - (int) $i->stock_actual),
            ])->values(),
        ];
    }

    /**
     * El proveedor tal como sale del LISTADO: el ORM entero, con `updated_at`.
     * Distinto de `proveedorOut`, que es lo que devuelven crear y editar
     * (filtrado por `ProveedorResponse`).
     *
     * El orden de las claves también es el del modelo, no el del schema: el
     * comparador trata el orden de un objeto JSON como no significativo, pero
     * el conjunto de claves sí lo es.
     */
    private function proveedorListadoOut($p): array
    {
        return [
            'id' => $p->id,
            'nombre' => $p->nombre,
            'contacto_nombre' => $p->contacto_nombre,
            'telefono' => $p->telefono,
            'email' => $p->email,
            'notas' => $p->notas,
            'activo' => (bool) $p->activo,
            'created_at' => $this->iso($p->created_at),
            'updated_at' => $this->iso($p->updated_at),
        ];
    }

    private function proveedorOut($p): array
    {
        return [
            'nombre' => $p->nombre,
            'contacto_nombre' => $p->contacto_nombre,
            'telefono' => $p->telefono,
            'email' => $p->email,
            'notas' => $p->notas,
            'activo' => (bool) $p->activo,
            'id' => $p->id,
            'created_at' => $this->iso($p->created_at),
        ];
    }

    /** Igual que en los demás: o sin fracción, o los seis dígitos. */
    private function iso(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = str_replace(' ', 'T', substr($v, 0, 26));

        return str_ends_with($t, '.000000') ? substr($t, 0, 19) : $t;
    }

    /** `%` y `_` son comodines de LIKE; un nombre puede traerlos. */
    private function escaparLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }
}
