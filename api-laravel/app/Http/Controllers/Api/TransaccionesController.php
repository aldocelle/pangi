<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Services\ConfigClinica;
use App\Services\Esquema;
use App\Services\HistorialVentas;
use Illuminate\Http\Request;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Historial de ventas (permiso «ventas»).
 *
 * Los filtros que miran las LÍNEAS van por `EXISTS` y no por `join`: un cobro
 * con tres productos aparecería tres veces con un join.
 */
class TransaccionesController extends Controller
{
    private const LIMITE_MAX = 20;

    public function __construct(
        private HistorialVentas $hv,
        private Auditoria $auditoria,
    ) {
    }

    public function listar(Request $request)
    {
        $q = $this->filtrar(DB::table('transacciones as t'), $request);

        // Más reciente primero, y el desempate llega hasta la PK (§9.14):
        // 7.750 de 15.410 ventas comparten `fecha`, así que sin `numero` e
        // `id` la paginación repetiría o saltaría filas.
        $q->orderBy('t.fecha', 'desc')->orderBy('t.numero', 'desc')->orderBy('t.id', 'desc');

        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min((int) $request->query('limit', 20), self::LIMITE_MAX));
        $total = (clone $q)->reorder()->count();
        $filas = $q->select('t.*')->offset(($page - 1) * $limit)->limit($limit)->get();

        return [
            'items' => $this->hv->serializar($filas),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_paginas' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /**
     * Totales del mismo conjunto que devuelve el listado con estos filtros.
     *
     * Es lo que encabeza el historial de un cliente («12 compras por
     * $512.000») sin traerse las 12 al navegador.
     *
     * **Dos consultas y no una**: los totales de la cabecera (lo cobrado, lo
     * pendiente) se contarían tantas veces como líneas tenga el cobro si
     * salieran del mismo join.
     */
    public function resumen(Request $request)
    {
        $ids = $this->filtrar(DB::table('transacciones as t'), $request)
            ->reorder()->select('t.id');

        $cobrado = $this->sumaPagosSql();
        $sinValor = "'" . implode("','", HistorialVentas::ESTADOS_SIN_VALOR) . "'";
        // Una venta anulada sigue listada —es parte del historial— pero vale
        // CERO en el dinero: sumarla diría que la clínica facturó plata que
        // devolvió o que nunca entró.
        $viva = "t.estado_venta IS NULL OR t.estado_venta NOT IN ($sinValor)";

        $cab = DB::table('transacciones as t')->whereIn('t.id', $ids)->selectRaw(
            "COUNT(t.id) as n_ventas,
             COALESCE(SUM(CASE WHEN $viva THEN $cobrado ELSE 0 END), 0) as pagado,
             COALESCE(SUM(CASE WHEN $viva THEN COALESCE(t.total_pendiente, 0) ELSE 0 END), 0) as pendiente,
             MAX(t.fecha) as ultima_fecha,
             COUNT(CASE WHEN t.estado_venta = 'anulada' THEN 1 END) as n_anuladas"
        )->first();

        // Los filtros de línea se repiten a nivel de línea a propósito: sin
        // eso, el total de «las ventas de Royal Canin» sumaría también el
        // resto de lo que iba en la misma boleta.
        $lineas = $this->hv->filtrarLineas(
            DB::table('ventas as v')->whereIn('v.transaccion_id', $ids),
            [
                'item_id' => $request->query('item_id'),
                'servicio_id' => $request->query('servicio_id'),
                'mascota_id' => $request->query('mascota_id'),
                'categoria' => $request->query('categoria'),
            ]
        );
        $agg = (clone $lineas)->selectRaw(
            'COUNT(v.id) as n_lineas,
             COALESCE(SUM(' . $this->hv->unidadesLineaSql() . '), 0) as n_unidades,
             COALESCE(SUM(' . $this->hv->vendidoLineaSql() . '), 0) as total,
             COALESCE(SUM(' . $this->hv->devueltoLineaSql() . '), 0) as devuelto'
        )->first();

        // Los borradores viven fuera del historial (ver `filtrar`), así que se
        // cuentan aparte: sin esto no habría forma de saber desde el panel que
        // quedaron ventas a medio armar.
        $nBorradores = $this->filtrar(
            DB::table('transacciones as t'),
            $this->conParametros($request, ['estado' => 'borrador', 'incluir_borradores' => '1'])
        )->reorder()->count();

        return [
            'n_ventas' => (int) ($cab->n_ventas ?? 0),
            'pagado' => (float) ($cab->pagado ?? 0),
            'pendiente' => (float) ($cab->pendiente ?? 0),
            'ultima_fecha' => $this->hv->iso($cab->ultima_fecha ?? null),
            'n_anuladas' => (int) ($cab->n_anuladas ?? 0),
            'n_borradores' => $nBorradores,
            'n_lineas' => (int) ($agg->n_lineas ?? 0),
            'n_unidades' => (int) ($agg->n_unidades ?? 0),
            'total' => (float) ($agg->total ?? 0),
            'devuelto' => (float) ($agg->devuelto ?? 0),
        ];
    }

    /**
     * Historial línea a línea: una fila por producto o servicio vendido.
     *
     * Es lo que pide la ficha de un producto («a quién se le vendió Royal
     * Canin») o la de un servicio («cuántas consultas se cobraron»). Sale de
     * las mismas `ventas` que el listado de arriba, mirado por línea en vez de
     * por cobro — no hay tabla de historial.
     */
    public function lineas(Request $request)
    {
        $filtros = [
            'item_id' => $request->query('item_id'),
            'servicio_id' => $request->query('servicio_id'),
            'servicio_nombre' => $request->query('servicio_nombre'),
            'producto' => $request->query('producto'),
            'propietario_id' => $request->query('propietario_id'),
            'mascota_id' => $request->query('mascota_id'),
            'categoria' => $request->query('categoria'),
            'desde' => $request->query('desde'),
            'hasta' => $request->query('hasta'),
        ];

        $q = $this->hv->filtrarLineas(DB::table('ventas as v'), $filtros);

        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min((int) $request->query('limit', 20), self::LIMITE_MAX));
        $total = (clone $q)->reorder()->count();

        // El desempate llega a la PK (§9.14): 7.750 de 15.410 ventas comparten
        // `fecha`, así que sin él una línea puede salir en dos páginas.
        $filas = (clone $q)->select('v.*')
            ->orderBy('v.fecha', 'desc')->orderBy('v.created_at', 'desc')->orderBy('v.id', 'desc')
            ->offset(($page - 1) * $limit)->limit($limit)->get();

        return [
            'items' => $this->hv->serializarLineas($filas),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_paginas' => max(1, (int) ceil($total / $limit)),
            // El encabezado («vendido 34 veces, 41 unidades») se calcula sobre
            // TODAS las líneas que coinciden, no sobre la página visible.
            'resumen' => $this->hv->resumenLineas(
                $this->hv->filtrarLineas(DB::table('ventas as v'), $filtros)
            ),
        ];
    }

    /**
     * PATCH /transacciones/{id}/pago — corrige por qué medio entró el cobro
     * (se tipeó «efectivo» y era débito).
     *
     * **El total tiene que quedar igual**: si cambió, no es un error de medio
     * de pago, es otra venta.
     */
    public function cambiarPago(Request $request, string $id)
    {
        $datos = $request->validate([
            'pago_efectivo' => ['nullable', 'numeric'],
            'pago_debito' => ['nullable', 'numeric'],
            'pago_credito' => ['nullable', 'numeric'],
            'pago_transferencia' => ['nullable', 'numeric'],
            'pago_otro' => ['nullable', 'numeric'],
        ]);

        $t = DB::table('transacciones')->where('id', $id)->first();
        if (! $t) {
            return response()->json(['detail' => 'Transacción no encontrada'], 404);
        }

        $anterior = [];
        $nuevos = [];
        $totalAnterior = 0.0;
        $totalNuevo = 0.0;
        foreach (HistorialVentas::MEDIOS_PAGO as $m) {
            $anterior[substr($m, 5)] = (string) $t->$m;
            $nuevos[$m] = (float) ($datos[$m] ?? 0);
            $totalAnterior += (float) $t->$m;
            $totalNuevo += $nuevos[$m];
        }

        if ($totalNuevo !== $totalAnterior) {
            return response()->json(['detail' => sprintf(
                'El total no puede cambiar al reclasificar el medio de pago '
                . '(quedaba en $%s, el nuevo desglose da $%s).',
                number_format($totalAnterior, 0, ',', ','),
                number_format($totalNuevo, 0, ',', ','),
            )], 400);
        }

        DB::table('transacciones')->where('id', $id)->update($nuevos);
        $this->auditoria->registrar($request->attributes->get('usuario'),
            'editar', 'ventas', 'transaccion', $id,
            ['medio_de_pago' => ['de' => $anterior, 'a' => $datos]]);

        return $this->transaccionOut(DB::table('transacciones')->where('id', $id)->first());
    }

    /**
     * POST /transacciones/{id}/confirmar — pasa un borrador a venta real.
     *
     * Recién acá descuenta stock y empieza a contar en el cuadre de caja.
     */
    public function confirmar(Request $request, string $id)
    {
        $t = DB::table('transacciones')->where('id', $id)->first();
        if (! $t) {
            return response()->json(['detail' => 'Venta no encontrada'], 404);
        }
        if ($t->estado_venta !== 'borrador') {
            return response()->json(['detail' => 'Esta venta ya está confirmada.'], 409);
        }

        $usuario = $request->attributes->get('usuario');

        // Confirmar un borrador ES vender: las mismas reglas que al crear.
        $cfg = ConfigClinica::leer('ventas');
        $comoArray = DB::table('ventas')->where('transaccion_id', $id)->get()
            ->map(fn ($v) => (array) $v)->all();
        if ($error = $this->reglasDeVenta($comoArray, $cfg)) {
            return $error;
        }

        return DB::transaction(function () use ($t, $id, $usuario) {
            $lineas = DB::table('ventas')->where('transaccion_id', $id)->get();
            foreach ($lineas as $v) {
                if (! $v->item_id) {
                    continue;
                }
                // Mismo criterio que al vender: el stock puede quedar
                // negativo, y eso es lo que hace visible cuánto reponer.
                DB::table('items_inventario')->where('id', $v->item_id)
                    ->update(['stock_actual' => DB::raw(
                        'COALESCE(stock_actual, 0) - ' . (int) ($v->cantidad ?? 0))]);
            }

            DB::table('transacciones')->where('id', $id)
                ->update(['estado_venta' => 'confirmada', 'cuenta_en_caja' => true]);

            $this->auditoria->registrar($usuario, 'editar', 'ventas', 'transaccion', $id, [
                'accion' => 'confirmar_borrador',
                'numero' => $t->numero === null ? null : (int) $t->numero,
                'lineas' => $lineas->count(),
            ]);

            return $this->hv->serializar(collect([
                DB::table('transacciones')->where('id', $id)->first(),
            ]))[0];
        });
    }

    /**
     * POST /transacciones/{id}/anular — anula la venta **sin borrarla**.
     *
     * La fila y sus líneas se conservan enteras, con quién la anuló, cuándo y
     * por qué. Antes esto era un `DELETE` y destruía el registro: la compra
     * desaparecía de la ficha del cliente y de la del paciente como si nunca
     * hubiera existido, que es justo lo que este módulo conserva.
     *
     * `plata_entro` decide qué pasa con el cuadre del cajón, y solo lo puede
     * responder quien anula: si nunca entró, Caja deja de esperarla; si entró
     * y se devolvió, la venta sigue contando y lo que se registra es la salida.
     */
    public function anular(Request $request, string $id)
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
            'plata_entro' => ['required', 'boolean'],
            'efectivo_devuelto' => ['nullable', 'numeric'],
        ]);
        $efectivoDevuelto = (float) ($datos['efectivo_devuelto'] ?? 0);
        $plataEntro = (bool) $datos['plata_entro'];

        $t = DB::table('transacciones')->where('id', $id)->first();
        if (! $t) {
            return response()->json(['detail' => 'Venta no encontrada'], 404);
        }
        if ($t->estado_venta === 'anulada') {
            return response()->json(['detail' => 'Esta venta ya está anulada.'], 409);
        }
        if ($t->estado_venta === 'borrador') {
            return response()->json(['detail' =>
                'Un borrador no se anula: nunca fue una venta. Puedes eliminarlo directamente.',
            ], 409);
        }

        if ($efectivoDevuelto > (float) ($t->pago_efectivo ?? 0)) {
            return response()->json(['detail' => sprintf(
                'No puedes devolver más efectivo del que entró por esta venta ($%s).',
                number_format((float) ($t->pago_efectivo ?? 0), 0, ',', ','),
            )], 400);
        }
        if (! $plataEntro && $efectivoDevuelto > 0) {
            return response()->json(['detail' =>
                'Marcaste que la plata nunca entró, así que no puede haber efectivo '
                . 'que devolver. Revisa cuál de las dos cosas es.',
            ], 400);
        }

        // Todo lo que puede fallar, resuelto ANTES de tocar la venta: la
        // primera versión marcaba la venta y devolvía el stock y recién
        // entonces buscaba la caja abierta, así que un error dejaba la venta
        // anulada aunque la respuesta fuera un 409.
        $sesion = $this->sesionParaEgreso($plataEntro ? $efectivoDevuelto : 0.0);
        if ($sesion instanceof \Illuminate\Http\JsonResponse) {
            return $sesion;
        }

        $usuario = $request->attributes->get('usuario');
        $totalCobrado = 0.0;
        foreach (HistorialVentas::MEDIOS_PAGO as $m) {
            $totalCobrado += (float) ($t->$m ?? 0);
        }

        return DB::transaction(function () use (
            $t, $id, $datos, $plataEntro, $efectivoDevuelto, $sesion, $usuario, $totalCobrado
        ) {
            $lineas = DB::table('ventas')->where('transaccion_id', $id)->get();
            // Solo vuelven las unidades que seguían en poder del cliente: lo
            // ya devuelto en una devolución parcial volvió al estante, y
            // sumarlo otra vez inflaría el stock.
            $unidades = [];
            foreach ($lineas as $v) {
                $unidades[$v->id] = max(0, (int) ($v->cantidad ?? 0) - (int) ($v->cantidad_devuelta ?? 0));
            }
            $devueltos = $this->devolverStock($lineas, $unidades);

            DB::table('transacciones')->where('id', $id)->update([
                'estado_venta' => 'anulada',
                'anulada_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
                'anulada_por_id' => $usuario->id,
                'anulada_por_nombre' => $usuario->nombre,
                'motivo_anulacion' => trim($datos['motivo']),
                // Si la plata nunca entró, Caja deja de esperarla. Si entró y
                // se devolvió, ese día sí ingresó y lo que se registra es la
                // salida, abajo.
                'cuenta_en_caja' => $plataEntro,
            ]);

            $this->registrarEgreso($usuario, $sesion, $efectivoDevuelto,
                'Devolución por anulación de la venta #' . ($t->numero ?: '—'));

            // Los puntos vuelven a como estaban: se le quita lo que ganó con
            // esta venta y se le devuelve lo que canjeó. Sin piso en cero, igual
            // que el stock: si ya gastó lo ganado, el saldo negativo lo muestra.
            if ($t->propietario_id && Esquema::columna('transacciones', 'puntos_ganados')) {
                $neto = (int) ($t->puntos_canjeados ?? 0) - (int) ($t->puntos_ganados ?? 0);
                if ($neto !== 0) {
                    DB::table('usuarios')->where('id', $t->propietario_id)->update(['puntos_fidelizacion' => DB::raw(
                        'COALESCE(puntos_fidelizacion, 0) + (' . $neto . ')')]);
                }
            }

            $this->auditoria->registrar($usuario, 'editar', 'ventas', 'transaccion', $id, [
                'accion' => 'anular',
                'numero' => $t->numero === null ? null : (int) $t->numero,
                'motivo' => trim($datos['motivo']),
                'total_cobrado' => (string) $totalCobrado,
                'plata_entro' => $plataEntro,
                'cuenta_en_caja' => $plataEntro,
                'efectivo_devuelto' => (string) $efectivoDevuelto,
                'lineas' => $lineas->count(),
                'stock_devuelto' => $devueltos,
            ]);

            return $this->hv->serializar(collect([
                DB::table('transacciones')->where('id', $id)->first(),
            ]))[0];
        });
    }

    /**
     * POST /transacciones/{id}/devolucion — devuelve parte (o todo) lo comprado.
     *
     * **La venta original no se toca**: `cantidad` sigue siendo lo que el
     * cliente compró y las unidades devueltas se anotan aparte, para que el
     * historial siga mostrando que el producto fue comprado. Restarlas de
     * `cantidad` haría desaparecer la compra de a poco.
     */
    public function devolucion(Request $request, string $id)
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.venta_id' => ['required', 'uuid'],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1'],
            'efectivo_devuelto' => ['nullable', 'numeric'],
        ]);
        $efectivoDevuelto = (float) ($datos['efectivo_devuelto'] ?? 0);

        $t = DB::table('transacciones')->where('id', $id)->first();
        if (! $t) {
            return response()->json(['detail' => 'Venta no encontrada'], 404);
        }
        if (in_array($t->estado_venta, ['anulada', 'borrador'], true)) {
            return response()->json(['detail' =>
                'Solo se puede registrar una devolución sobre una venta confirmada.',
            ], 409);
        }

        $lineas = DB::table('ventas')->where('transaccion_id', $id)->get()->keyBy('id');
        $unidades = [];
        foreach ($datos['lineas'] as $pedida) {
            $linea = $lineas->get($pedida['venta_id']);
            if (! $linea) {
                return response()->json(['detail' =>
                    'Una de las líneas no pertenece a esta venta.'], 400);
            }
            $disponible = (int) ($linea->cantidad ?? 0) - (int) ($linea->cantidad_devuelta ?? 0);
            if ((int) $pedida['cantidad'] > $disponible) {
                return response()->json(['detail' => sprintf(
                    '«%s»: se intenta devolver %d y solo quedan %d sin devolver de las %d compradas.',
                    $linea->nombre, (int) $pedida['cantidad'], $disponible, (int) ($linea->cantidad ?? 0),
                )], 400);
            }
            $unidades[$linea->id] = ($unidades[$linea->id] ?? 0) + (int) $pedida['cantidad'];
        }

        if ($efectivoDevuelto > (float) ($t->pago_efectivo ?? 0)) {
            return response()->json(['detail' => sprintf(
                'No puedes devolver más efectivo del que entró por esta venta ($%s).',
                number_format((float) ($t->pago_efectivo ?? 0), 0, ',', ','),
            )], 400);
        }

        $sesion = $this->sesionParaEgreso($efectivoDevuelto);
        if ($sesion instanceof \Illuminate\Http\JsonResponse) {
            return $sesion;
        }

        $usuario = $request->attributes->get('usuario');

        return DB::transaction(function () use (
            $t, $id, $datos, $lineas, $unidades, $efectivoDevuelto, $sesion, $usuario
        ) {
            $ahora = CarbonImmutable::now()->format('Y-m-d H:i:s.u');
            foreach ($unidades as $ventaId => $cantidad) {
                DB::table('ventas')->where('id', $ventaId)->update([
                    'cantidad_devuelta' => DB::raw('COALESCE(cantidad_devuelta, 0) + ' . (int) $cantidad),
                    'devuelta_at' => $ahora,
                    'devuelta_por_nombre' => $usuario->nombre,
                    'motivo_devolucion' => trim($datos['motivo']),
                ]);
            }

            $devueltos = $this->devolverStock($lineas->values(), $unidades);

            // Todo devuelto = la venta quedó en nada, aunque no se haya
            // «anulado»: son dos hechos distintos y el historial tiene que
            // poder distinguirlos.
            $todoDevuelto = $lineas->isNotEmpty();
            foreach ($lineas as $v) {
                $ya = (int) ($v->cantidad_devuelta ?? 0) + ($unidades[$v->id] ?? 0);
                if ($ya < (int) ($v->cantidad ?? 0)) {
                    $todoDevuelto = false;
                    break;
                }
            }
            $estado = $todoDevuelto ? 'devuelta' : 'devuelta_parcial';
            DB::table('transacciones')->where('id', $id)->update(['estado_venta' => $estado]);

            $this->registrarEgreso($usuario, $sesion, $efectivoDevuelto,
                'Devolución de la venta #' . ($t->numero ?: '—'));

            $this->auditoria->registrar($usuario, 'editar', 'ventas', 'transaccion', $id, [
                'accion' => 'devolucion',
                'numero' => $t->numero === null ? null : (int) $t->numero,
                'motivo' => trim($datos['motivo']),
                'estado' => $estado,
                'unidades' => array_map('intval', $unidades),
                'efectivo_devuelto' => (string) $efectivoDevuelto,
                'stock_devuelto' => $devueltos,
            ]);

            return $this->hv->serializar(collect([
                DB::table('transacciones')->where('id', $id)->first(),
            ]))[0];
        });
    }

    /**
     * DELETE /transacciones/{id} — elimina un BORRADOR. Solo admin.
     *
     * Una venta confirmada ya no se borra: se anula. Antes esto borraba
     * cualquier transacción con sus líneas, y con eso se iba el historial que
     * el módulo existe para conservar. Un borrador sí: nunca fue una venta,
     * no descontó stock ni contó en caja.
     */
    public function eliminar(Request $request, string $id)
    {
        $t = DB::table('transacciones')->where('id', $id)->first();
        if (! $t) {
            return response()->json(['detail' => 'Venta no encontrada'], 404);
        }
        if ($t->estado_venta !== 'borrador') {
            return response()->json(['detail' => sprintf(
                'Una venta confirmada no se elimina: se anula, para que quede el '
                . 'registro de qué se anuló, quién lo hizo y por qué. '
                . 'Usa «Anular» en el detalle de la venta #%s.',
                $t->numero ?: '—',
            )], 409);
        }

        $usuario = $request->attributes->get('usuario');

        return DB::transaction(function () use ($t, $id, $usuario) {
            $n = DB::table('ventas')->where('transaccion_id', $id)->count();
            $this->auditoria->registrar($usuario, 'eliminar', 'ventas', 'transaccion', $id, [
                'estado' => 'borrador',
                'numero' => $t->numero === null ? null : (int) $t->numero,
                'documento_id' => $t->documento_id,
                'lineas' => $n,
            ]);
            // Sin devolución de stock: un borrador nunca lo descontó.
            DB::table('ventas')->where('transaccion_id', $id)->delete();
            DB::table('transacciones')->where('id', $id)->delete();

            return response()->noContent();
        });
    }

    /**
     * Comprueba que haya dónde registrar la salida de efectivo.
     *
     * Devuelve la sesión, `null` si no hace falta (monto 0), o la respuesta
     * 409 ya armada. Se llama ANTES de tocar la venta a propósito: validar
     * primero hace que un error deje todo exactamente como estaba.
     */
    private function sesionParaEgreso(float $monto)
    {
        if ($monto <= 0) {
            return null;
        }
        $sesion = DB::table('sesiones_caja')->whereNull('cerrada_at')
            ->orderBy('abierta_at', 'desc')->orderBy('id', 'desc')->first();
        if (! $sesion) {
            return response()->json(['detail' =>
                'No hay una caja abierta para registrar la salida de efectivo. '
                . 'Abre la caja y vuelve a intentarlo: sin el egreso, el cierre del '
                . 'turno mostraría un faltante por ese monto.',
            ], 409);
        }
        return $sesion;
    }

    /**
     * La plata que sale del cajón por una anulación o devolución se registra
     * como egreso del turno abierto.
     *
     * Ventas no lleva su propio libro de dinero: sin este egreso el cajón
     * cerraría con un faltante exactamente igual al monto devuelto, que
     * alguien del mostrador tendría que explicar sin tener con qué.
     */
    private function registrarEgreso($usuario, $sesion, float $monto, string $comentario): void
    {
        if (! $sesion || $monto <= 0) {
            return;
        }
        DB::table('movimientos_caja')->insert([
            'id' => (string) Str::uuid(),
            'sesion_id' => $sesion->id,
            'usuario_id' => $usuario->id,
            'tipo' => 'egreso',
            'monto' => $monto,
            'comentario' => $comentario,
            'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * Devuelve unidades al inventario. Operación inversa exacta de la que
     * hizo `crear()` al vender.
     */
    private function devolverStock($lineas, array $unidades): int
    {
        $devueltos = 0;
        foreach ($lineas as $v) {
            $n = $unidades[$v->id] ?? 0;
            if (! $v->item_id || $n <= 0) {
                continue;
            }
            // El ítem pudo darse de baja después de la venta.
            $tocadas = DB::table('items_inventario')->where('id', $v->item_id)
                ->update(['stock_actual' => DB::raw('COALESCE(stock_actual, 0) + ' . (int) $n)]);
            if ($tocadas) {
                $devueltos++;
            }
        }
        return $devueltos;
    }

    /** Una copia del request con algunos parámetros forzados. */
    private function conParametros(Request $request, array $extra): Request
    {
        $copia = clone $request;
        $copia->query->replace(array_merge($request->query->all(), $extra));
        return $copia;
    }

    public function ver(string $id)
    {
        $t = DB::table('transacciones')->where('id', $id)->first();
        if (! $t) {
            return response()->json(['detail' => 'Venta no encontrada'], 404);
        }
        return $this->hv->serializar(collect([$t]))[0];
    }

    /**
     * Las categorías presentes, para poblar el filtro.
     *
     * Otro `DISTINCT` sobre la propia columna de orden: segunda excepción
     * documentada a la regla del desempate por PK (§9.14).
     */
    public function categorias()
    {
        return DB::table('ventas')
            ->whereNotNull('tipo')->where('tipo', '<>', '')
            ->distinct()->orderBy('tipo')
            ->pluck('tipo')->values();
    }

    /**
     * POST /transacciones — staff, permiso «ventas».
     *
     * Crea el cobro, sus líneas y descuenta stock. **Todo en una sola
     * transacción**: una venta a medias —cabecera sin líneas, o stock
     * descontado sin cobro— es peor que una venta que falla.
     */
    public function crear(Request $request)
    {
        $datos = $request->validate([
            'estado_venta' => ['nullable', 'in:borrador,confirmada'],
            'propietario_id' => ['nullable', 'uuid'],
            'especialista_id' => ['nullable', 'uuid'],
            'documento_id' => ['nullable', 'string', 'max:100'],
            'pago_efectivo' => ['nullable', 'numeric'],
            'pago_debito' => ['nullable', 'numeric'],
            'pago_credito' => ['nullable', 'numeric'],
            'pago_transferencia' => ['nullable', 'numeric'],
            'pago_otro' => ['nullable', 'numeric'],
            'total_pendiente' => ['nullable', 'numeric'],
            'ventas' => ['nullable', 'array'],
            'ventas.*.nombre' => ['required', 'string'],
            'ventas.*.item_id' => ['nullable', 'uuid'],
            'ventas.*.servicio_id' => ['nullable', 'uuid'],
            'ventas.*.propietario_id' => ['nullable', 'uuid'],
            'ventas.*.mascota_id' => ['nullable', 'uuid'],
            'ventas.*.especialista_id' => ['nullable', 'uuid'],
            'ventas.*.tipo' => ['nullable', 'string'],
            'ventas.*.cantidad' => ['nullable', 'integer'],
            'ventas.*.precio_costo' => ['nullable', 'numeric'],
            'ventas.*.precio_venta' => ['nullable', 'numeric'],
            'ventas.*.descuento' => ['nullable', 'numeric'],
            'clave_descuento' => ['nullable', 'string', 'max:64'],
            'puntos_canjeados' => ['nullable', 'integer', 'min:0'],
        ]);

        $lineas = $datos['ventas'] ?? [];
        $estadoVenta = $datos['estado_venta'] ?? 'confirmada';
        $esBorrador = $estadoVenta === 'borrador';

        $pagos = [];
        $cobrado = 0.0;
        foreach (HistorialVentas::MEDIOS_PAGO as $m) {
            $pagos[$m] = (float) ($datos[$m] ?? 0);
            $cobrado += $pagos[$m];
        }

        // El desglose no puede superar lo que se está vendiendo. Cobrar de
        // MENOS sí es legítimo (queda en `total_pendiente`); cobrar de más no
        // lo es nunca. El caso real: el modal prellena el efectivo con el
        // total, el cajero escribe el monto en «Débito» y no borra el
        // efectivo — la venta queda con el doble de pago. Eso no es un dato
        // feo en una tabla: Caja suma `pago_efectivo` para saber qué debe
        // haber en el cajón, así que al cerrar el turno aparece un faltante
        // falso que alguien del mostrador tiene que explicar.
        $totalVentas = 0.0;
        foreach ($lineas as $v) {
            $totalVentas += max(0.0,
                (float) ($v['precio_venta'] ?? 0) * (int) ($v['cantidad'] ?? 1)
                - (float) ($v['descuento'] ?? 0));
        }
        // Reglas de venta que la clínica encienda en Ajustes → Ventas (paridad
        // con ACVet). Apagadas por defecto: cada una frena el mostrador, y
        // esa decisión es de la clínica, no del despliegue.
        $cfg = ConfigClinica::leer('ventas');
        if (! $esBorrador && ($error = $this->reglasDeVenta($lineas, $cfg))) {
            return $error;
        }
        if (! empty($cfg['clave_descuentos']) && collect($lineas)->contains(fn ($v) => (float) ($v['descuento'] ?? 0) > 0)
            && ! (is_string($datos['clave_descuento'] ?? null) && ! empty($cfg['clave_descuento_hash'])
                && password_verify($datos['clave_descuento'], $cfg['clave_descuento_hash']))) {
            return response()->json(['detail' => 'Los descuentos requieren la clave de autorización. '
                . 'Pídesela a quien la administra.'], 403);
        }

        // Canje de puntos: rebaja lo que hay que pagar, no es un medio de pago
        // (no entra plata al cajón), así que se descuenta del total ANTES de
        // comparar con lo cobrado.
        $puntosCanje = (int) ($datos['puntos_canjeados'] ?? 0);
        $montoCanje = 0.0;
        if ($puntosCanje > 0) {
            if ($esBorrador || empty($cfg['puntos_activo']) || empty($datos['propietario_id'])
                || ! Esquema::columna('transacciones', 'puntos_canjeados')) {
                return response()->json(['detail' => 'Los puntos solo se canjean en una venta confirmada, '
                    . 'con cliente y con el sistema de puntos activo.'], 400);
            }
            $saldo = (int) DB::table('usuarios')->where('id', $datos['propietario_id'])->value('puntos_fidelizacion');
            if ($puntosCanje > $saldo) {
                return response()->json(['detail' => "El cliente tiene {$saldo} puntos; no alcanza para canjear {$puntosCanje}."], 400);
            }
            $montoCanje = min($totalVentas, $puntosCanje * (float) $cfg['valor_punto']);
            $totalVentas -= $montoCanje;
        }

        if ($cobrado > $totalVentas) {
            return response()->json(['detail' => sprintf(
                'El pago ingresado ($%s) supera el total de la venta ($%s). '
                . 'Revisa el desglose por medio de pago.',
                number_format($cobrado, 0, ',', ','), number_format($totalVentas, 0, ',', ','),
            )], 400);
        }

        // Un borrador no cuenta en el cuadre del turno. Si además trajera
        // plata cobrada, ese dinero estaría en el cajón sin que Caja lo
        // espere, y el turno cerraría con un sobrante inexplicable.
        if ($esBorrador && $cobrado > 0) {
            return response()->json(['detail' =>
                'Un borrador no puede llevar pagos registrados. Confírmalo primero '
                . 'y cobra después, o registra la venta directamente.',
            ], 400);
        }

        // Quién atendió. Un id inexistente rompía la FK recién en el commit
        // —500 y «error del servidor» en pantalla— sin que el mostrador
        // supiera que el problema era ese campo. Se validan cabecera y líneas
        // de una sola consulta, y sale 400 con el nombre del campo.
        $espIds = collect($lineas)->pluck('especialista_id')
            ->push($datos['especialista_id'] ?? null)->filter()->unique();
        if ($espIds->isNotEmpty()) {
            $existen = DB::table('especialistas')->whereIn('id', $espIds)->pluck('id');
            $faltan = $espIds->diff($existen)->sort()->values();
            if ($faltan->isNotEmpty()) {
                return response()->json(['detail' =>
                    'No existe el especialista indicado en «quién atendió»: '
                    . $faltan->implode(', '),
                ], 400);
            }
        }

        $usuario = $request->attributes->get('usuario');

        return DB::transaction(function () use (
            $datos, $lineas, $pagos, $estadoVenta, $esBorrador, $usuario, $cobrado, $cfg, $puntosCanje, $montoCanje
        ) {
            // Con MICROSEGUNDOS (`.u`), como Python. La columna es
            // `DATETIME(6)` y `fecha` es la clave de orden del historial y del
            // cuadre del día: truncada al segundo, dos ventas del mismo segundo
            // quedan empatadas y las desempata el id —un UUID—, o sea que el
            // listado del cajero las muestra en orden aleatorio en vez de
            // cronológico. Los RANGOS (desde/hasta) no lo necesitan: son
            // medianoche.
            //
            // Hora de la CLÍNICA, no UTC: `fecha` decide a qué día de caja
            // pertenece el cobro, y con UTC todo lo cobrado desde media tarde
            // caía en el día siguiente. Además deja lo nuevo en la misma
            // escala que lo importado de ACVet (§9.6).
            $fecha = CarbonImmutable::now()->format('Y-m-d H:i:s.u');
            $id = (string) Str::uuid();

            DB::table('transacciones')->insert(array_merge($pagos, [
                'id' => $id,
                'fecha' => $fecha,
                'creado_por_id' => $usuario->id,
                'creado_por_nombre' => $usuario->nombre,
                // Un borrador es una venta que todavía no ocurrió: no puede
                // sumar en el cuadre del turno ni sacar unidades del estante.
                'cuenta_en_caja' => ! $esBorrador,
                'estado_venta' => $estadoVenta,
                'propietario_id' => $datos['propietario_id'] ?? null,
                'especialista_id' => $datos['especialista_id'] ?? null,
                'documento_id' => $datos['documento_id'] ?? null,
                'total_pendiente' => (float) ($datos['total_pendiente'] ?? 0),
                // Marca técnica, en UTC (§9.6): a diferencia de `fecha`, que
                // está en hora de la clínica, `created_at` nunca se compara
                // con horarios de negocio.
                'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            ]));

            $this->moverPuntos($id, $datos['propietario_id'] ?? null, $esBorrador, $cobrado, $cfg, $puntosCanje, $montoCanje);

            foreach ($lineas as $v) {
                DB::table('ventas')->insert([
                    'id' => (string) Str::uuid(),
                    'transaccion_id' => $id,
                    'fecha' => $fecha,
                    'item_id' => $v['item_id'] ?? null,
                    'servicio_id' => $v['servicio_id'] ?? null,
                    'propietario_id' => $v['propietario_id'] ?? null,
                    'mascota_id' => $v['mascota_id'] ?? null,
                    'especialista_id' => $v['especialista_id'] ?? null,
                    'nombre' => $v['nombre'],
                    'tipo' => $v['tipo'] ?? null,
                    'cantidad' => (int) ($v['cantidad'] ?? 1),
                    'precio_costo' => (float) ($v['precio_costo'] ?? 0),
                    'precio_venta' => (float) ($v['precio_venta'] ?? 0),
                    'descuento' => (float) ($v['descuento'] ?? 0),
                    'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                ]);

                if (! empty($v['item_id']) && ! $esBorrador) {
                    // **Sin piso en cero, a propósito.** Si se venden 10 de un
                    // ítem con 3, el stock queda en -7 y el descuadre se ve.
                    // Clampeando a 0, las 7 unidades desaparecían sin rastro:
                    // el listado decía «0» tanto si no quedaba nada como si
                    // faltaban siete, que es justo lo que hay que reponer.
                    //
                    // Tampoco se bloquea la venta: frenar el mostrador por un
                    // stock mal cargado sale más caro que mostrar un negativo.
                    //
                    // `COALESCE`: la columna es nullable y el importador de
                    // ACVet inserta por SQL crudo.
                    DB::table('items_inventario')->where('id', $v['item_id'])
                        ->update(['stock_actual' => DB::raw(
                            'COALESCE(stock_actual, 0) - ' . (int) ($v['cantidad'] ?? 1))]);
                }
            }

            $t = DB::table('transacciones')->where('id', $id)->first();
            $this->auditoria->registrar($usuario, 'crear', 'ventas', 'transaccion', $id, [
                'total' => (string) $cobrado,
                'documento_id' => $t->documento_id,
                'estado' => $t->estado_venta,
            ]);

            return response()->json($this->transaccionOut($t), 201);
        });
    }

    /** GET /transacciones/puntos/{usuarioId} — saldo para el modal de venta. */
    public function puntos(string $usuarioId)
    {
        $cfg = ConfigClinica::leer('ventas');
        $saldo = DB::table('usuarios')->where('id', $usuarioId)->value('puntos_fidelizacion');

        return ['activo' => (bool) $cfg['puntos_activo'], 'saldo' => (int) $saldo,
            'valor_punto' => (int) $cfg['valor_punto'], 'pesos_por_punto' => (int) $cfg['pesos_por_punto']];
    }

    /**
     * Las reglas de Ajustes → Ventas que bloquean una venta, o null si pasa.
     * Mismos mensajes que muestra el modal ANTES de cobrar: el backend es la
     * guarda real, el modal solo evita que alguien llegue al error.
     *
     * @param  list<array>  $lineas
     */
    private function reglasDeVenta(array $lineas, array $cfg): ?\Illuminate\Http\JsonResponse
    {
        if (! empty($cfg['exigir_paciente'])
            && collect($lineas)->contains(fn ($v) => empty($v['mascota_id']))) {
            return response()->json(['detail' => 'La clínica exige registrar el paciente para vender. '
                . 'Elige la mascota en el primer paso.'], 400);
        }
        if (! empty($cfg['exigir_caja_abierta'])
            && ! DB::table('sesiones_caja')->whereNull('cerrada_at')->exists()) {
            return response()->json(['detail' => 'No hay caja abierta. Abre la caja del día antes de vender.'], 400);
        }
        if (! empty($cfg['no_vender_sin_stock'])) {
            $pedidas = [];
            foreach ($lineas as $v) {
                if (! empty($v['item_id'])) {
                    $pedidas[$v['item_id']] = ($pedidas[$v['item_id']] ?? 0) + (int) ($v['cantidad'] ?? 1);
                }
            }
            if ($pedidas) {
                $faltan = DB::table('items_inventario')->whereIn('id', array_keys($pedidas))->get()
                    ->filter(fn ($i) => (int) ($i->stock_actual ?? 0) < $pedidas[$i->id])
                    ->map(fn ($i) => "{$i->nombre} (hay " . (int) ($i->stock_actual ?? 0) . ", se piden {$pedidas[$i->id]})")
                    ->sort()->values();
                if ($faltan->isNotEmpty()) {
                    return response()->json(['detail' => 'Sin stock suficiente: ' . $faltan->implode('; ') . '.'], 400);
                }
            }
        }

        return null;
    }

    /**
     * Puntos de fidelización de una venta: descuenta lo canjeado y suma lo
     * ganado, y deja ambos anotados en la venta para poder revertirlos al
     * anular. Gana puntos solo lo PAGADO (no el canje) y solo en una venta
     * confirmada con cliente.
     */
    private function moverPuntos(string $id, ?string $clienteId, bool $esBorrador, float $cobrado, array $cfg,
        int $canjeados, float $montoCanje): void
    {
        if (! $clienteId || $esBorrador || ! Esquema::columna('transacciones', 'puntos_ganados')) {
            return;
        }
        $ganados = ! empty($cfg['puntos_activo'])
            ? (int) floor($cobrado / max(1, (int) $cfg['pesos_por_punto'])) : 0;
        if ($ganados === 0 && $canjeados === 0) {
            return;
        }
        DB::table('transacciones')->where('id', $id)->update([
            'puntos_ganados' => $ganados, 'puntos_canjeados' => $canjeados, 'monto_canje' => $montoCanje]);
        DB::table('usuarios')->where('id', $clienteId)->update(['puntos_fidelizacion' => DB::raw(
            'COALESCE(puntos_fidelizacion, 0) + (' . ($ganados - $canjeados) . ')')]);
    }

    /**
     * El contrato de `TransaccionResponse`, campo por campo y en su orden.
     *
     * Los `Decimal` van como STRING con su escala (`"0.00"`), no como número:
     * el schema los declara `Decimal` y Pydantic los serializa así. Es el
     * mismo criterio que `peso_kg` en las fichas, y el contrario al de
     * `/inventario` — lo que manda es el tipo DECLARADO, no la columna.
     */
    private function transaccionOut($t): array
    {
        $ventas = DB::table('ventas')->where('transaccion_id', $t->id)
            ->orderBy('created_at')->orderBy('nombre')->orderBy('id')->get();

        return [
            'id' => $t->id,
            // El correlativo legible del cobro. Existía en la tabla desde
            // migrate_caja.py pero no salía por la API, así que el panel
            // identificaba las ventas por el número de boleta —opcional— o
            // por nada.
            'numero' => $t->numero === null ? null : (int) $t->numero,
            'estado_venta' => $t->estado_venta ?: 'confirmada',
            'cuenta_en_caja' => (bool) $t->cuenta_en_caja,
            'propietario_id' => $t->propietario_id,
            'especialista_id' => $t->especialista_id,
            'fecha' => $this->hv->iso($t->fecha),
            'documento_id' => $t->documento_id,
            'pago_efectivo' => (string) $t->pago_efectivo,
            'pago_debito' => (string) $t->pago_debito,
            'pago_credito' => (string) $t->pago_credito,
            'pago_transferencia' => (string) $t->pago_transferencia,
            'pago_otro' => (string) $t->pago_otro,
            'total_pendiente' => (string) $t->total_pendiente,
            'creado_por_id' => $t->creado_por_id,
            'creado_por_nombre' => $t->creado_por_nombre,
            'created_at' => $this->hv->iso($t->created_at),
            'ventas' => $ventas->map(fn ($v) => [
                'item_id' => $v->item_id,
                'servicio_id' => $v->servicio_id,
                'propietario_id' => $v->propietario_id,
                'mascota_id' => $v->mascota_id,
                'especialista_id' => $v->especialista_id,
                'nombre' => $v->nombre,
                'tipo' => $v->tipo,
                'cantidad' => (int) $v->cantidad,
                'precio_costo' => (string) $v->precio_costo,
                'precio_venta' => (string) $v->precio_venta,
                'descuento' => (string) $v->descuento,
                'id' => $v->id,
                'transaccion_id' => $v->transaccion_id,
                'fecha' => $this->hv->iso($v->fecha),
                'created_at' => $this->hv->iso($v->created_at),
            ])->values(),
        ] + (property_exists($t, 'puntos_ganados') ? [
            // Solo con la migración de paridad: sin ella el contrato no cambia.
            'puntos_ganados' => (int) $t->puntos_ganados,
            'puntos_canjeados' => (int) $t->puntos_canjeados,
            'monto_canje' => (string) $t->monto_canje,
        ] : []);
    }

    private function filtrar($q, Request $request)
    {
        // El filtro de texto vive en el servicio: el buscador global usa
        // EXACTAMENTE el mismo, para que los dos encuentren lo mismo.
        $this->hv->filtrarTexto($q, (string) $request->query('q', ''));

        if ($v = $request->query('propietario_id')) {
            $q->where('t.propietario_id', $v);
        }
        if ($v = trim((string) $request->query('propietario', ''))) {
            $like = '%' . $v . '%';
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('usuarios')
                ->whereColumn('usuarios.id', 't.propietario_id')
                ->where(fn ($x) => $x->where('usuarios.nombre', 'like', $like)
                    ->orWhere('usuarios.email', 'like', $like)
                    ->orWhere('usuarios.rut', 'like', $like)));
        }
        if ($v = $request->query('mascota_id')) {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('ventas')
                ->whereColumn('ventas.transaccion_id', 't.id')->where('ventas.mascota_id', $v));
        }
        if ($v = trim((string) $request->query('paciente', ''))) {
            $like = '%' . $v . '%';
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('ventas')
                ->join('mascotas', 'mascotas.id', '=', 'ventas.mascota_id')
                ->whereColumn('ventas.transaccion_id', 't.id')
                ->where('mascotas.nombre', 'like', $like));
        }
        if ($v = $request->query('item_id')) {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('ventas')
                ->whereColumn('ventas.transaccion_id', 't.id')->where('ventas.item_id', $v));
        }
        if ($v = $request->query('servicio_id')) {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('ventas')
                ->whereColumn('ventas.transaccion_id', 't.id')->where('ventas.servicio_id', $v));
        }
        if ($v = trim((string) $request->query('producto', ''))) {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('ventas')
                ->whereColumn('ventas.transaccion_id', 't.id')
                ->where('ventas.nombre', 'like', '%' . $v . '%'));
        }
        if ($v = $request->query('categoria')) {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('ventas')
                ->whereColumn('ventas.transaccion_id', 't.id')->where('ventas.tipo', $v));
        }
        if ($v = trim((string) $request->query('documento', ''))) {
            $q->where('t.documento_id', 'like', '%' . $v . '%');
        }
        if ($v = $request->query('especialista_id')) {
            // El especialista puede estar en la cabecera o en una línea.
            $q->where(fn ($w) => $w->where('t.especialista_id', $v)
                ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('ventas')
                    ->whereColumn('ventas.transaccion_id', 't.id')
                    ->where('ventas.especialista_id', $v)));
        }

        $estado = $request->query('estado');
        if ($estado && isset(HistorialVentas::ESTADOS_VENTA[$estado])) {
            // Estado del DOCUMENTO. «devuelta» cubre también la parcial.
            if ($estado === 'devuelta') {
                $q->whereIn('t.estado_venta', ['devuelta', 'devuelta_parcial']);
            } else {
                $q->where('t.estado_venta', $estado);
            }
        } elseif ($estado) {
            // Estado de la PLATA: solo tiene sentido sobre ventas vivas. Una
            // anulada con $0 pendientes no es una «venta pagada».
            $cobrado = $this->sumaPagosSql();
            $q->whereNotIn('t.estado_venta', HistorialVentas::ESTADOS_SIN_VALOR);
            if ($estado === 'pagada') {
                $q->whereRaw('COALESCE(t.total_pendiente, 0) <= 0');
            } elseif ($estado === 'parcial') {
                $q->whereRaw('COALESCE(t.total_pendiente, 0) > 0')->whereRaw("$cobrado > 0");
            } elseif ($estado === 'pendiente') {
                $q->whereRaw('COALESCE(t.total_pendiente, 0) > 0')->whereRaw("$cobrado <= 0");
            }
        }

        // Los BORRADORES quedan fuera salvo que se pidan: un borrador es una
        // venta que todavía no ocurrió, y mezclarla con las cobradas
        // descuadraría los totales. Las ANULADAS sí se listan —son parte del
        // historial— pero suman cero en los agregados.
        if (! $this->booleano($request->query('incluir_borradores')) && $estado !== 'borrador') {
            $q->where(fn ($w) => $w->where('t.estado_venta', '<>', 'borrador')
                ->orWhereNull('t.estado_venta'));
        }

        if ($v = $request->query('desde')) {
            $q->where('t.fecha', '>=', $v . ' 00:00:00');
        }
        if ($v = $request->query('hasta')) {
            // El día completo: comparar un DATETIME contra una fecha pelada
            // dejaba fuera todo lo cobrado ese mismo día después de
            // medianoche — buscar «hasta hoy» no mostraba las ventas de hoy.
            $q->where('t.fecha', '<=', $v . ' 23:59:59.999999');
        }

        return $q;
    }

    /**
     * Lo cobrado, en SQL. `COALESCE` porque las columnas son nullable y el
     * importador de ACVet inserta por SQL crudo: un NULL sumado envenena la
     * expresión entera y la transacción desaparecería de todos los filtros.
     */
    private function sumaPagosSql(): string
    {
        return '(' . implode(' + ', array_map(
            fn ($m) => "COALESCE(t.$m, 0)", HistorialVentas::MEDIOS_PAGO)) . ')';
    }

    private function booleano($v): bool
    {
        return $v !== null
            && in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
    }
}
