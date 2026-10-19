<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pedidos/Compras: recepción de facturas de proveedor (permiso «compras»).
 *
 * Una factura es la cabecera y sus líneas son lo que llegó; cada línea con
 * `item_id` **suma stock**, fija el precio de costo y, si trae lote, lo deja
 * como el vigente del ítem.
 *
 * Mismo patrón que `crear_transaccion`: se valida TODO antes de tocar la base,
 * el total lo calcula el servidor —nunca se confía en el que mande el
 * cliente— y el alta entera va en una sola transacción.
 *
 * **Deliberadamente sin anulación.** Revertir una factura ya recibida exigiría
 * descontar stock que puede haberse vendido, dejando un número inventado en vez
 * de uno real. Una carga mal hecha se corrige con el ajuste manual de
 * Inventario, que ya existe.
 */
class PedidosController extends Controller
{
    private const LIMITE_MAX = 20;

    /** Sin fecha de vencimiento propia, una factura se da por vencida a los 30 días. */
    private const DIAS_VENCIDA_SIN_FECHA = 30;

    public function __construct(private Auditoria $auditoria)
    {
    }

    /** POST /pedidos — recibir una factura. */
    public function recibir(Request $request)
    {
        $datos = $request->validate([
            'proveedor_id' => 'required|uuid',
            'numero_factura' => 'nullable|string',
            'fecha_recibo' => 'required|date',
            'fecha_emision' => 'nullable|date',
            'fecha_vencimiento_pago' => 'nullable|date',
            'notas' => 'nullable|string',
            'lineas' => 'required|array|min:1',
            'lineas.*.item_id' => 'nullable|uuid',
            'lineas.*.item_nombre_libre' => 'nullable|string',
            // `gt=0` del schema: recibir cero unidades no es una recepción.
            'lineas.*.cantidad' => 'required|integer|gt:0',
            'lineas.*.costo_unitario' => 'nullable|numeric',
            'lineas.*.lote' => 'nullable|string',
            'lineas.*.fecha_vencimiento' => 'nullable|date',
        ]);

        $proveedor = DB::table('proveedores')->where('id', $datos['proveedor_id'])->first();
        if (! $proveedor) {
            return response()->json(['detail' => 'No existe el proveedor indicado.'], 400);
        }

        // Todos los ítems se validan ANTES de tocar nada: media factura cargada
        // deja stock sumado de unas líneas y no de otras, y eso no se ve.
        $itemIds = array_values(array_unique(array_filter(
            array_map(fn ($l) => $l['item_id'] ?? null, $datos['lineas']))));
        if ($itemIds) {
            $existen = DB::table('items_inventario')->whereIn('id', $itemIds)
                ->pluck('id')->all();
            $faltan = array_diff($itemIds, $existen);
            if ($faltan) {
                sort($faltan);
                return response()->json([
                    'detail' => 'No existe el ítem de inventario indicado en una de las líneas: '
                        . implode(', ', $faltan),
                ], 400);
            }
        }

        // El TOTAL lo calcula el servidor. El cliente no lo manda ni se le cree.
        $total = '0';
        foreach ($datos['lineas'] as $l) {
            $total = bcadd($total, bcmul((string) $l['cantidad'],
                (string) ($l['costo_unitario'] ?? 0), 4), 4);
        }
        $total = bcadd($total, '0', 2);

        $id = (string) Str::uuid();
        $ahora = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $actor = $request->attributes->get('usuario');

        DB::transaction(function () use ($datos, $id, $total, $ahora, $actor, $proveedor) {
            DB::table('facturas_compra')->insert([
                'id' => $id,
                'proveedor_id' => $datos['proveedor_id'],
                // Un número en blanco se guarda como NULL, no como "": si no,
                // dos facturas «sin número» chocarían entre sí al buscarlas.
                'numero_factura' => $this->vacioANull($datos['numero_factura'] ?? null),
                'fecha_recibo' => substr($datos['fecha_recibo'], 0, 10),
                'fecha_emision' => isset($datos['fecha_emision'])
                    ? substr($datos['fecha_emision'], 0, 10) : null,
                'fecha_vencimiento_pago' => isset($datos['fecha_vencimiento_pago'])
                    ? substr($datos['fecha_vencimiento_pago'], 0, 10) : null,
                'total' => $total,
                'notas' => $this->vacioANull($datos['notas'] ?? null),
                'creado_por_id' => $actor->id,
                'creado_por_nombre' => $actor->nombre,
                'created_at' => $ahora,
            ]);

            foreach ($datos['lineas'] as $l) {
                $lote = $this->vacioANull($l['lote'] ?? null);
                DB::table('lineas_factura_compra')->insert([
                    'id' => (string) Str::uuid(),
                    'factura_id' => $id,
                    'item_id' => $l['item_id'] ?? null,
                    'item_nombre_libre' => $this->vacioANull($l['item_nombre_libre'] ?? null),
                    'cantidad' => $l['cantidad'],
                    'costo_unitario' => $l['costo_unitario'] ?? 0,
                    'lote' => $lote,
                    'fecha_vencimiento' => isset($l['fecha_vencimiento'])
                        ? substr($l['fecha_vencimiento'], 0, 10) : null,
                ]);

                if (empty($l['item_id'])) {
                    continue;
                }
                $item = DB::table('items_inventario')->where('id', $l['item_id'])->first();
                if (! $item) {
                    continue;
                }

                $cambios = [
                    'stock_actual' => (int) ($item->stock_actual ?? 0) + (int) $l['cantidad'],
                    'precio_costo' => $l['costo_unitario'] ?? 0,
                ];
                // El lote solo se pisa si la línea trae uno: recibir sin lote no
                // puede borrar el que el ítem ya tenía. Y el vencimiento viaja
                // CON el lote — son el mismo dato del mismo envío.
                if ($lote !== null) {
                    $cambios['lote'] = $lote;
                    $cambios['fecha_vencimiento'] = isset($l['fecha_vencimiento'])
                        ? substr($l['fecha_vencimiento'], 0, 10) : null;
                }
                DB::table('items_inventario')->where('id', $item->id)->update($cambios);
            }

            $this->auditoria->registrar($actor, 'crear', 'compras', 'factura_compra', $id, [
                'proveedor' => $proveedor->nombre,
                'total' => (string) $total,
                'n_lineas' => count($datos['lineas']),
            ]);
        });

        return response()->json($this->facturaOut($id), 201);
    }

    /** GET /pedidos — listado con filtros. */
    public function listar(Request $request)
    {
        $q = DB::table('facturas_compra');

        if ($p = $request->query('proveedor_id')) {
            $q->where('proveedor_id', $p);
        }
        if ($e = $request->query('estado_pago')) {
            $q->where('estado_pago', $e);
        }
        if ($d = $request->query('desde')) {
            $q->where('fecha_recibo', '>=', substr($d, 0, 10));
        }
        if ($h = $request->query('hasta')) {
            $q->where('fecha_recibo', '<=', substr($h, 0, 10));
        }
        if ($busca = trim((string) $request->query('q', ''))) {
            $q->where('numero_factura', 'like', '%' . $this->escaparLike($busca) . '%');
        }

        // Más reciente primero, con desempate hasta la PK (§9.14).
        $q->orderBy('fecha_recibo', 'desc')->orderBy('id', 'desc');

        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min((int) $request->query('limit', self::LIMITE_MAX), self::LIMITE_MAX));
        $total = (clone $q)->reorder()->count();
        $filas = $q->offset(($page - 1) * $limit)->limit($limit)->get();

        // El nombre del proveedor se resuelve en LOTE, no una consulta por fila.
        $provs = $filas->isEmpty() ? collect() : DB::table('proveedores')
            ->whereIn('id', $filas->pluck('proveedor_id')->unique())
            ->pluck('nombre', 'id');

        return [
            'items' => $filas->map(fn ($f) => $this->filaListado($f, $provs))->values(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_paginas' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /**
     * GET /pedidos/resumen — los números para decidir a quién pagar hoy.
     * Sin `desde`/`hasta`, el mes en curso.
     */
    public function resumen(Request $request)
    {
        $hoy = CarbonImmutable::now();
        $desde = $request->query('desde')
            ? substr($request->query('desde'), 0, 10) : $hoy->startOfMonth()->format('Y-m-d');
        $hasta = $request->query('hasta')
            ? substr($request->query('hasta'), 0, 10) : $hoy->format('Y-m-d');

        $base = fn () => DB::table('facturas_compra')
            ->where('fecha_recibo', '>=', $desde)
            ->where('fecha_recibo', '<=', $hasta);

        $pendientes = fn () => $base()->where('estado_pago', 'no_pagada');

        // «Vencida» = no pagada Y, o pasó su fecha de vencimiento propia, o
        // —sin esa fecha— lleva más de 30 días recibida.
        $piso = $hoy->subDays(self::DIAS_VENCIDA_SIN_FECHA)->format('Y-m-d');
        $vencidas = fn () => $pendientes()->where(function ($w) use ($hoy, $piso) {
            $w->where(function ($x) use ($hoy) {
                $x->whereNotNull('fecha_vencimiento_pago')
                  ->where('fecha_vencimiento_pago', '<', $hoy->format('Y-m-d'));
            })->orWhere(function ($x) use ($piso) {
                $x->whereNull('fecha_vencimiento_pago')
                  ->where('fecha_recibo', '<', $piso);
            });
        });

        return [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'gasto_total' => $this->num($base()->sum('total')),
            'n_facturas' => $base()->count(),
            'pendientes' => [
                'n' => $pendientes()->count(),
                'total' => $this->num($pendientes()->sum('total')),
            ],
            'vencidas' => [
                'n' => $vencidas()->count(),
                'total' => $this->num($vencidas()->sum('total')),
            ],
        ];
    }

    /** GET /pedidos/{id} */
    public function ver(string $facturaId)
    {
        if (! DB::table('facturas_compra')->where('id', $facturaId)->exists()) {
            return response()->json(['detail' => 'Factura no encontrada'], 404);
        }

        return $this->facturaOut($facturaId);
    }

    /** PATCH /pedidos/{id}/pago */
    public function actualizarPago(Request $request, string $facturaId)
    {
        $datos = $request->validate([
            'estado_pago' => 'required|in:no_pagada,pagada',
            'fecha_pago' => 'nullable|date',
        ]);

        if (! DB::table('facturas_compra')->where('id', $facturaId)->exists()) {
            return response()->json(['detail' => 'Factura no encontrada'], 404);
        }

        DB::transaction(function () use ($request, $datos, $facturaId) {
            DB::table('facturas_compra')->where('id', $facturaId)->update([
                'estado_pago' => $datos['estado_pago'],
                // Volver a «no pagada» BORRA la fecha de pago: dejarla sería
                // una factura sin pagar con fecha de pago.
                'fecha_pago' => $datos['estado_pago'] === 'pagada'
                    ? (isset($datos['fecha_pago']) ? substr($datos['fecha_pago'], 0, 10) : null)
                    : null,
            ]);
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'editar', 'compras', 'factura_compra', $facturaId,
                ['estado_pago' => $datos['estado_pago']]);
        });

        return $this->facturaOut($facturaId);
    }

    // ── salidas ───────────────────────────────────────────────────────────

    /**
     * La factura con sus líneas, como la devuelven POST, GET-uno y PATCH
     * (filtrada por `FacturaCompraResponse`).
     *
     * **No es igual al listado**, que arma su propio dict con
     * `proveedor_nombre` y sin líneas.
     */
    private function facturaOut(string $facturaId): array
    {
        $f = DB::table('facturas_compra')->where('id', $facturaId)->first();
        $lineas = DB::table('lineas_factura_compra')
            ->where('factura_id', $facturaId)
            ->orderBy('id')
            ->get();

        return [
            'id' => $f->id,
            'proveedor_id' => $f->proveedor_id,
            'numero_factura' => $f->numero_factura,
            'fecha_recibo' => $this->fecha($f->fecha_recibo),
            'fecha_emision' => $this->fecha($f->fecha_emision),
            'fecha_vencimiento_pago' => $this->fecha($f->fecha_vencimiento_pago),
            'total' => $this->dec($f->total),
            'estado_pago' => $f->estado_pago,
            'fecha_pago' => $this->fecha($f->fecha_pago),
            'notas' => $f->notas,
            'creado_por_id' => $f->creado_por_id,
            'creado_por_nombre' => $f->creado_por_nombre,
            'created_at' => $this->iso($f->created_at),
            'lineas' => $lineas->map(fn ($l) => [
                'id' => $l->id,
                'item_id' => $l->item_id,
                'item_nombre_libre' => $l->item_nombre_libre,
                'cantidad' => (int) $l->cantidad,
                'costo_unitario' => $this->dec($l->costo_unitario),
                'lote' => $l->lote,
                'fecha_vencimiento' => $this->fecha($l->fecha_vencimiento),
            ])->values(),
        ];
    }

    /** La fila del LISTADO: trae `proveedor_nombre` y no trae líneas. */
    private function filaListado($f, $provs): array
    {
        return [
            'id' => $f->id,
            'proveedor_id' => $f->proveedor_id,
            'proveedor_nombre' => $provs[$f->proveedor_id] ?? null,
            'numero_factura' => $f->numero_factura,
            'fecha_recibo' => $this->fecha($f->fecha_recibo),
            'fecha_emision' => $this->fecha($f->fecha_emision),
            'fecha_vencimiento_pago' => $this->fecha($f->fecha_vencimiento_pago),
            'total' => $this->num($f->total),
            'estado_pago' => $f->estado_pago,
            'fecha_pago' => $this->fecha($f->fecha_pago),
            'notas' => $f->notas,
            'creado_por_nombre' => $f->creado_por_nombre,
            'created_at' => $this->iso($f->created_at),
        ];
    }

    /**
     * Decimal como TEXTO con dos decimales.
     *
     * Es lo que devuelven POST, GET-uno y PATCH, porque pasan por
     * `FacturaCompraResponse`, que declara `total: Decimal` — y Pydantic
     * serializa un Decimal como texto conservando la escala.
     */
    private function dec($v): string
    {
        return number_format((float) ($v ?? 0), 2, '.', '');
    }

    /**
     * El mismo importe como NÚMERO, que es como lo devuelven el LISTADO y el
     * RESUMEN: esos dos arman sus dicts a mano y no pasan por ningún schema,
     * así que el Decimal termina serializado como float.
     *
     * Cuarta vez que aparece este contraste (temperatura, peso_kg, precios de
     * inventario y ahora los totales de compra): **manda el tipo declarado en
     * cada respuesta, no la columna**. Un `(string)` acá y un `(float)` allá
     * son ambos correctos, en su sitio.
     */
    /**
     * El total de una suma, con el MISMO tipo JSON que Python.
     *
     * Sutil y verificado con el arnés: Python devuelve el escalar crudo de
     * `COALESCE(SUM(total), 0)`. Sin filas, ese COALESCE cae en su literal y
     * sale un **entero** `0`; con filas, la suma de un DECIMAL sale como float
     * (`50000.0`). O sea que el tipo de este campo depende de si hay facturas.
     *
     * Devolver siempre float haría que un panel vacío dijera `0.0` donde
     * Python dice `0`. Es el mismo número, pero la regla 3 del comparador los
     * trata como distintos a propósito — es lo único que caza una deriva de
     * redondeo en un DECIMAL.
     */
    private function num($v): float|int
    {
        // `null` no alcanza: `sum()` de Laravel ya devuelve el entero 0 cuando
        // no hay filas, y con filas devuelve el DECIMAL como string («0.00»).
        // Esa distinción es justo la que hay que conservar — una suma que da
        // cero HABIENDO facturas sí sale como `0.0`, igual que en Python.
        return ($v === null || $v === 0) ? 0 : (float) $v;
    }

    private function fecha(?string $v): ?string
    {
        return $v === null ? null : substr($v, 0, 10);
    }

    private function iso(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = str_replace(' ', 'T', substr($v, 0, 26));

        return str_ends_with($t, '.000000') ? substr($t, 0, 19) : $t;
    }

    private function vacioANull(?string $v): ?string
    {
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private function escaparLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }
}
