<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Auditoria;
use App\Services\ConfigClinica;
use App\Services\Esquema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Fechas;

/**
 * Inventario: productos e insumos (permiso «inventario»).
 */
class InventarioController extends Controller
{
    private ?array $ventasCfg = null;

    public function __construct(private Auditoria $auditoria)
    {
    }

    /** `paginar()` recorta a 20 aunque la firma diga 30. */
    private const LIMITE_MAX = 20;

    /** Cuánto antes se avisa de un vencimiento. Tres meses es lo que la clínica
     *  pidió: alcanza para usar o rebajar el producto antes de perderlo. */
    private const MESES_AVISO_VENCIMIENTO = 3;

    /**
     * La fecha hasta la que se considera «por vencer».
     *
     * Suma meses a mano y NO con `addMonths()`, a propósito. El Python hace
     * `min(dia, 28)` para no inventar un 31 de febrero, y Carbon resuelve el
     * desborde de otra manera (`addMonths` rueda al mes siguiente,
     * `addMonthsNoOverflow` pega al último día). Cualquiera de las dos daría un
     * límite distinto al del Python los días 29, 30 y 31, y el arnés compara
     * respuesta contra respuesta: sería un rojo los tres últimos días de casi
     * todos los meses. Perder hasta tres días de precisión no importa —el
     * umbral es un aviso, no un vencimiento legal—, pero que las dos
     * implementaciones difieran sí.
     *
     * `now()` ya es hora de la clínica: `config/app.php` fija
     * `APP_TIMEZONE=America/Santiago`.
     */
    private function limiteVencimiento(int $meses = self::MESES_AVISO_VENCIMIENTO): string
    {
        $hoy = CarbonImmutable::now();
        $mes = $hoy->month + $meses;
        $anio = $hoy->year + intdiv($mes - 1, 12);
        $mes = ($mes - 1) % 12 + 1;

        return CarbonImmutable::create($anio, $mes, min($hoy->day, 28))->toDateString();
    }

    /**
     * GET /inventario/alertas
     *
     * Lo que hay que mirar hoy en bodega: lo vencido, lo que está por vencer y
     * lo que está bajo su punto de reorden.
     *
     * TRES DECISIONES QUE HACEN QUE ESTO SE USE Y NO SE IGNORE
     *
     * 1. **Solo lo que tiene stock.** Un producto vencido con existencia 0 no
     *    es un problema: no hay nada que retirar ni que perder. Incluirlo llena
     *    la lista de filas sobre las que no se puede hacer nada, y una lista
     *    así se deja de mirar.
     * 2. **Vencido y por vencer van separados**, no en un montón. Son dos
     *    trabajos distintos: lo vencido se retira, lo que está por vencer se
     *    usa o se rebaja primero.
     * 3. **Orden por fecha ascendente**, lo más urgente arriba, con desempate
     *    por `id`: varios productos comparten vencimiento cuando llegaron en la
     *    misma factura (§9.14).
     *
     * `dias` viene calculado y en negativo si ya venció, para que el frontend
     * no repita la aritmética de fechas ni arriesgue otro resultado que este.
     */
    public function alertas(Request $request)
    {
        $meses = (int) ($request->query('meses') ?: self::MESES_AVISO_VENCIMIENTO);
        $meses = max(1, min(24, $meses));

        $hoy = CarbonImmutable::now()->startOfDay();
        $limite = $this->limiteVencimiento($meses);

        $conFecha = DB::table('items_inventario')
            ->where('activo', true)
            ->whereNotNull('fecha_vencimiento')
            ->where('stock_actual', '>', 0)
            ->where('fecha_vencimiento', '<=', $limite)
            ->orderBy('fecha_vencimiento')->orderBy('id')
            ->get();

        $salida = function ($it) use ($hoy) {
            $venc = CarbonImmutable::parse($it->fecha_vencimiento)->startOfDay();

            return [
                'id' => $it->id,
                'nombre' => $it->nombre,
                'lote' => $it->lote,
                'proveedor' => $it->proveedor,
                'stock_actual' => $it->stock_actual,
                'unidad' => $it->unidad,
                'fecha_vencimiento' => $venc->toDateString(),
                // Días de calendario (App\Services\Fechas): con la resta de
                // Carbon, un lote que vence pasando un cambio de hora mostraba
                // un día menos del que le queda.
                'dias' => Fechas::diasEntre($hoy, $venc),
            ];
        };

        $vencidos = [];
        $porVencer = [];
        foreach ($conFecha as $it) {
            $venc = CarbonImmutable::parse($it->fecha_vencimiento)->startOfDay();
            if ($venc->lt($hoy)) {
                $vencidos[] = $salida($it);
            } else {
                $porVencer[] = $salida($it);
            }
        }

        $bajoStock = DB::table('items_inventario')
            ->where('activo', true)
            ->whereColumn('stock_actual', '<=', 'stock_minimo')
            ->orderBy('nombre')->orderBy('id')
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'nombre' => $i->nombre,
                'proveedor' => $i->proveedor,
                'stock_actual' => $i->stock_actual,
                'stock_minimo' => $i->stock_minimo,
                'unidad' => $i->unidad,
            ])->values()->all();

        return [
            'meses' => $meses,
            'vencidos' => $vencidos,
            'por_vencer' => $porVencer,
            'bajo_stock' => $bajoStock,
            'total' => count($vencidos) + count($porVencer) + count($bajoStock),
        ];
    }

    public function listar(Request $request)
    {
        $q = DB::table('items_inventario')->where('activo', true);

        if ($v = $request->query('q')) {
            $like = '%' . $v . '%';
            $q->where(function ($w) use ($like) {
                $w->where('nombre', 'like', $like)->orWhere('descripcion', 'like', $like);
            });
        }
        if ($v = $request->query('tipo')) {
            $q->where('tipo', $v);
        }
        if ($v = $request->query('proveedor')) {
            $q->where('proveedor', $v);
        }
        if (($v = $request->query('bodega')) && Esquema::columna('items_inventario', 'bodega')) {
            $q->where('bodega', $v);
        }
        if ($v = $request->query('lote')) {
            $q->where('lote', 'like', '%' . $v . '%');
        }
        if ($this->booleano($request->query('bajo_stock'))) {
            // Bajo el punto de reorden: `<=`, no `<`. Un ítem exactamente en
            // su mínimo ya hay que pedirlo.
            $q->whereColumn('stock_actual', '<=', 'stock_minimo');
        }
        if ($this->booleano($request->query('por_vencer'))) {
            // Mismo criterio que `GET /inventario/alertas`, que es donde está
            // explicado: solo lo que tiene stock, y hasta el límite de aviso
            // hacia adelante. Incluye lo ya vencido, que sigue ocupando bodega.
            $q->whereNotNull('fecha_vencimiento')
                ->where('fecha_vencimiento', '<=', $this->limiteVencimiento())
                ->where('stock_actual', '>', 0);
        }
        $usoInterno = $request->query('uso_interno');
        if ($usoInterno !== null) {
            $q->where('uso_interno', $this->booleano($usoInterno));
        }
        if ($this->booleano($request->query('excluir_uso_interno'))) {
            // Lo usa el buscador de Ventas: un insumo de uso interno no es una
            // opción vendible.
            $q->where('uso_interno', false);
        }

        $q->orderBy('nombre')->orderBy('id');   // desempate obligatorio (§9.14)

        return $this->paginar($q, $request);
    }

    /**
     * GET /inventario/proveedores
     *
     * Para poblar el filtro como desplegable en vez de un campo libre.
     *
     * **Este `DISTINCT` es una de las dos excepciones a la regla del desempate
     * por PK** (§9.14): al ser sobre la propia columna de orden, ya es un orden
     * total, y agregarle el id rompería la consulta —cada fila volvería a ser
     * distinta y el DISTINCT dejaría de agrupar—.
     */
    public function proveedores()
    {
        return DB::table('items_inventario')
            ->whereNotNull('proveedor')
            ->where('activo', true)
            ->distinct()
            ->orderBy('proveedor')
            ->pluck('proveedor')
            ->values();
    }

    private function paginar($q, Request $request): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min((int) $request->query('limit', 30), self::LIMITE_MAX));
        $total = (clone $q)->reorder()->count();
        $items = $q->offset(($page - 1) * $limit)->limit($limit)->get();

        return [
            'items' => $items->map(fn ($i) => $this->itemOut($i))->values(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_paginas' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /**
     * El contrato de `ItemInventarioResponse`.
     *
     * Ojo: incluye `id_legado` y `updated_at`, que **no están declarados en el
     * schema** — Pydantic los deja pasar desde el modelo ORM. Se descubrió
     * mirando la respuesta real, no el schema.
     */
    /**
     * Los campos del ítem. `tipo` es obligatorio al crear (Fármaco, Insumo,
     * Pet-shop…) porque de él cuelga la agrupación del inventario y los
     * informes de Estadísticas.
     */
    private function reglas(bool $creando): array
    {
        $req = $creando ? 'required' : 'nullable';

        return [
            'nombre' => "{$req}|string",
            'tipo' => "{$req}|string",
            'descripcion' => 'nullable|string',
            'unidad' => 'nullable|string',
            'precio_costo' => 'nullable|numeric',
            'precio_venta' => 'nullable|numeric',
            'stock_actual' => 'nullable|integer',
            'stock_minimo' => 'nullable|integer',
            'lote' => 'nullable|string',
            'fecha_vencimiento' => 'nullable|date',
            'proveedor' => 'nullable|string',
            'uso_interno' => 'nullable|boolean',
            'activo' => 'nullable|boolean',
            'bodega' => 'nullable|string|in:' . implode(',', ConfigClinica::BODEGAS),
        ];
    }

    /** POST /inventario — alta de un ítem. */
    public function crear(Request $request)
    {
        $datos = $request->validate($this->reglas(true));
        $id = (string) Str::uuid();

        DB::transaction(function () use ($request, $datos, $id) {
            DB::table('items_inventario')->insert([
                'id' => $id,
                'nombre' => $datos['nombre'],
                'tipo' => $datos['tipo'],
                'descripcion' => $datos['descripcion'] ?? null,
                'unidad' => $datos['unidad'] ?? null,
                // Los defaults salen del SCHEMA de Pydantic, no de la columna:
                // un cuerpo sin precio guarda 0, no NULL.
                'precio_costo' => $datos['precio_costo'] ?? 0,
                'precio_venta' => $datos['precio_venta'] ?? 0,
                'stock_actual' => $datos['stock_actual'] ?? 0,
                'stock_minimo' => $datos['stock_minimo'] ?? 0,
                'lote' => $datos['lote'] ?? null,
                'fecha_vencimiento' => isset($datos['fecha_vencimiento'])
                    ? substr($datos['fecha_vencimiento'], 0, 10) : null,
                'proveedor' => $datos['proveedor'] ?? null,
                'uso_interno' => $datos['uso_interno'] ?? false,
                'activo' => $datos['activo'] ?? true,
            ] + (Esquema::columna('items_inventario', 'bodega') ? ['bodega' => $datos['bodega'] ?? 'Principal'] : []) + [
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);

            $this->auditoria->registrar($request->attributes->get('usuario'),
                // Sin `entidad_id`: Python audita el alta ANTES del commit,
                // cuando el id todavía no está asignado. Se replica tal cual.
                'crear', 'inventario', 'item_inventario', null,
                ['nombre' => $datos['nombre']]);
        });

        return response()->json(
            $this->itemGuardadoOut(DB::table('items_inventario')->where('id', $id)->first()), 201);
    }

    /**
     * PATCH /inventario/{id}
     *
     * `exclude_unset`: solo los campos que vinieron en el cuerpo. **El detalle
     * de la auditoría son esos mismos cambios**, que es lo que permite
     * reconstruir después quién subió un precio o ajustó un stock a mano.
     */
    public function actualizar(Request $request, string $itemId)
    {
        $item = DB::table('items_inventario')->where('id', $itemId)->first();
        if (! $item) {
            return response()->json(['detail' => 'Ítem no encontrado'], 404);
        }

        $datos = $request->validate($this->reglas(false));

        $cambios = [];
        foreach (array_keys($this->reglas(false)) as $campo) {
            if ($request->exists($campo)) {
                $cambios[$campo] = $datos[$campo] ?? null;
            }
        }
        if (array_key_exists('fecha_vencimiento', $cambios) && $cambios['fecha_vencimiento']) {
            $cambios['fecha_vencimiento'] = substr($cambios['fecha_vencimiento'], 0, 10);
        }
        if (array_key_exists('bodega', $cambios) && ! Esquema::columna('items_inventario', 'bodega')) {
            unset($cambios['bodega']);
        }
        // «Permitir reiniciar stock solo a administradores» (Ajustes → Ventas):
        // corregir el stock a mano es lo que esconde un faltante, así que la
        // clínica puede reservárselo a un admin. Recibir mercadería no pasa
        // por acá (Pedidos suma stock por su cuenta).
        $usuario = $request->attributes->get('usuario');
        if (array_key_exists('stock_actual', $cambios) && (int) $cambios['stock_actual'] !== (int) $item->stock_actual
            && ($usuario->rol ?? null) !== 'admin' && ConfigClinica::leer('ventas')['reiniciar_stock_solo_admin']) {
            return response()->json(['detail' => 'Solo un administrador puede corregir el stock a mano.'], 403);
        }

        DB::transaction(function () use ($request, $cambios, $itemId) {
            if ($cambios) {
                DB::table('items_inventario')->where('id', $itemId)->update($cambios);
            }
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'editar', 'inventario', 'item_inventario', $itemId, $cambios);
        });

        return $this->itemGuardadoOut(DB::table('items_inventario')->where('id', $itemId)->first());
    }

    /**
     * DELETE /inventario/{id} — **no borra, DESACTIVA**.
     *
     * El ítem sigue referenciado por cada línea de venta que lo cobró: borrarlo
     * dejaría el historial de compras del cliente apuntando a nada. `activo`
     * en false lo saca del listado y del buscador, que es lo que la persona
     * quiere decir con «eliminar».
     */
    public function eliminar(Request $request, string $itemId)
    {
        $item = DB::table('items_inventario')->where('id', $itemId)->first();
        if (! $item) {
            return response()->json(['detail' => 'Ítem no encontrado'], 404);
        }

        DB::transaction(function () use ($request, $item) {
            DB::table('items_inventario')->where('id', $item->id)->update(['activo' => false]);
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'eliminar', 'inventario', 'item_inventario', $item->id,
                ['nombre' => $item->nombre]);
        });

        return response()->noContent();
    }

    /**
     * El ítem tal como lo devuelven POST y PATCH, que **no es igual** a como
     * lo devuelve el listado.
     *
     * Los precios salen como STRING con dos decimales (`"2999.00"`) y no como
     * número. Verificado contra el backend vivo:
     *
     *     POST  -> {"precio_venta": "2999.00"}
     *     PATCH -> {"precio_venta": "2999.00"}
     *     GET   -> {"precio_venta": 2999.0}
     *
     * La razón es de dónde sale cada respuesta: el listado arma los dicts a
     * mano y hace `float()`, mientras que crear y editar devuelven el objeto
     * ORM, que `ItemInventarioResponse` declara como `Decimal` — y Pydantic
     * serializa un Decimal como texto conservando la escala de la columna,
     * `numeric(12,2)`.
     *
     * Manda el tipo DECLARADO en cada sitio, no la columna. Es el mismo caso
     * de `temperatura` en las notas clínicas y de `peso_kg` en la ficha del
     * paciente, y ya llevaba dos correcciones por esto.
     */
    private function itemGuardadoOut($i): array
    {
        $salida = $this->itemOut($i);
        foreach (['precio_costo', 'precio_venta'] as $campo) {
            $salida[$campo] = $i->$campo === null
                ? null : number_format((float) $i->$campo, 2, '.', '');
        }

        // Y DOS CAMPOS MENOS que en el listado, que es una asimetría del
        // original y no una omisión: `GET /inventario` no declara
        // `response_model`, así que FastAPI serializa la fila ORM entera y
        // salen `id_legado` y `updated_at`; el alta y la edición sí lo
        // declaran (`ItemInventarioResponse`) y Pydantic los recorta, porque el
        // schema no los nombra. Mismo endpoint, dos formas, según haya o no
        // `response_model`. Lo encontró `modo_b_items_y_servicios`: el listado
        // ya estaba verde en el modo A y por eso la diferencia solo aparecía al
        // ESCRIBIR.
        unset($salida['id_legado'], $salida['updated_at']);

        return $salida;
    }

    private function itemOut($i): array
    {
        return [
            'nombre' => $i->nombre,
            'descripcion' => $i->descripcion,
            'tipo' => $i->tipo,
            'unidad' => $i->unidad,
            // Los Decimal salen como NÚMERO en JSON, no como string: Pydantic
            // serializa `Decimal("10000.00")` como `10000.0`. Un `(string)`
            // acá produciría "10000.00", que para Vue es otra cosa (regla 3
            // del comparador).
            'precio_costo' => $i->precio_costo === null ? null : (float) $i->precio_costo,
            'precio_venta' => $i->precio_venta === null ? null : (float) $i->precio_venta,
            'stock_actual' => $i->stock_actual === null ? null : (int) $i->stock_actual,
            'stock_minimo' => $i->stock_minimo === null ? null : (int) $i->stock_minimo,
            'lote' => $i->lote,
            'fecha_vencimiento' => $i->fecha_vencimiento
                ? substr($i->fecha_vencimiento, 0, 10) : null,
            'proveedor' => $i->proveedor,
            'uso_interno' => (bool) $i->uso_interno,
            'activo' => (bool) $i->activo,
        ] + (property_exists($i, 'bodega') ? ['bodega' => $i->bodega] : []) + $this->margen($i) + [
            'id' => $i->id,
            'created_at' => $this->iso($i->created_at),
            'id_legado' => $i->id_legado,
            'updated_at' => $this->iso($i->updated_at),
        ];
    }

    /**
     * Margen sobre el precio de venta, (venta − costo) / venta, y si queda bajo
     * el mínimo de Ajustes → Ventas (el del tipo, o el global). Sin mínimo
     * configurado no sale nada: el contrato de antes queda intacto.
     */
    private function margen($i): array
    {
        $this->ventasCfg ??= ConfigClinica::leer('ventas');   // una lectura por request, no por fila
        $minimo = ConfigClinica::margenMinimo($i->tipo, $this->ventasCfg);
        if ($minimo === null) {
            return [];
        }
        $venta = (float) $i->precio_venta;
        $pct = $venta > 0 ? round(($venta - (float) $i->precio_costo) / $venta * 100, 1) : null;

        return ['margen_pct' => $pct, 'margen_minimo' => $minimo, 'margen_bajo' => $pct !== null && $pct < $minimo];
    }

    /** `true`, `1`, `on`… como los interpreta FastAPI para un bool de query. */
    private function booleano($v): ?bool
    {
        if ($v === null) {
            return null;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
    }

    /** Igual que en los otros controladores: o sin fracción, o los 6 dígitos. */
    private function iso(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = str_replace(' ', 'T', $v);
        [$base, $frac] = array_pad(explode('.', $v, 2), 2, null);
        if ($frac === null) {
            return $base;
        }
        $frac = substr(str_pad($frac, 6, '0'), 0, 6);
        return $frac === '000000' ? $base : "{$base}.{$frac}";
    }
}
