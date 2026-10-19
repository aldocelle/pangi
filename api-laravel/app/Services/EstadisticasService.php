<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Dashboard de ingresos. Puerto de `estadisticas_service.py`.
 *
 * **Todo se calcula al vuelo**, sin tabla de agregados: con el volumen de una
 * clínica no se justifica, y una tabla de agregados es una copia que se
 * desincroniza.
 *
 * Reusa las fórmulas de `HistorialVentas` a propósito. Reimplementar «vendido
 * neto de devoluciones» acá es exactamente el riesgo que advierte el docstring
 * de `total_linea()`: dos versiones de la misma regla que un día dejan de dar
 * lo mismo, y entonces Estadísticas dice una cifra y Ventas otra.
 */
class EstadisticasService
{
    private const TOP_N = 10;

    public function __construct(private HistorialVentas $hv)
    {
    }

    public function resumen(?int $anio = null, ?int $mes = null): array
    {
        $hoy = now();
        $anio = $anio ?: (int) $hoy->format('Y');
        $mes = $mes ?: (int) $hoy->format('n');

        [$desde, $hasta] = $this->rangoMes($anio, $mes);
        [$anioAnt, $mesAnt] = $mes === 1 ? [$anio - 1, 12] : [$anio, $mes - 1];
        [$desdeAnt, $hastaAnt] = $this->rangoMes($anioAnt, $mesAnt);
        [$desdeAP, $hastaAP] = $this->rangoMes($anio - 1, $mes);

        [$productos, $servicios] = $this->top($desde, $hasta);

        return [
            'periodo' => ['anio' => $anio, 'mes' => $mes, 'desde' => $desde, 'hasta' => $hasta],
            'comparacion' => [
                'actual' => $this->resumenPeriodo($desde, $hasta),
                'mes_anterior' => $this->resumenPeriodo($desdeAnt, $hastaAnt),
                'anio_anterior' => $this->resumenPeriodo($desdeAP, $hastaAP),
            ],
            'por_tipo' => $this->porTipo($desde, $hasta),
            'top_productos' => $productos,
            'top_servicios' => $servicios,
            'por_especialista' => $this->porEspecialista($desde, $hasta),
            'inventario' => $this->inventario(),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function rangoMes(int $anio, int $mes): array
    {
        $ultimo = (int) date('t', mktime(0, 0, 0, $mes, 1, $anio));
        return [sprintf('%04d-%02d-01', $anio, $mes), sprintf('%04d-%02d-%02d', $anio, $mes, $ultimo)];
    }

    private function resumenPeriodo(string $desde, string $hasta): array
    {
        // Dos consultas y no una: los totales de la cabecera se contarían
        // tantas veces como líneas tenga el cobro si salieran del mismo join.
        $lineas = $this->lineasVivas($desde, $hasta)
            ->select(
                DB::raw('COUNT(v.id) as n'),
                DB::raw($this->vendidoSql() . ' as total'),
                DB::raw($this->devueltoSql() . ' as devuelto'))
            ->first();

        $cabeceras = $this->cabecerasVivas($desde, $hasta)
            ->select(DB::raw('COUNT(*) as n'))->first();

        return [
            'ingreso' => (float) ($lineas->total ?? 0),
            'n_ventas' => (int) ($cabeceras->n ?? 0),
            'devuelto' => (float) ($lineas->devuelto ?? 0),
        ];
    }

    private function porTipo(string $desde, string $hasta): array
    {
        $filas = $this->lineasVivas($desde, $hasta)
            ->groupBy('v.tipo')
            ->select('v.tipo',
                DB::raw($this->vendidoSql() . ' as ingreso'),
                DB::raw('COUNT(v.id) as n'))
            ->get();

        $vacio = ['ingreso' => 0.0, 'lineas' => 0];
        $g = ['productos' => $vacio, 'servicios' => $vacio, 'otros' => $vacio];
        foreach ($filas as $f) {
            $k = in_array($f->tipo, HistorialVentas::TIPOS_PRODUCTO, true) ? 'productos'
                : (in_array($f->tipo, HistorialVentas::TIPOS_SERVICIO, true) ? 'servicios' : 'otros');
            $g[$k]['ingreso'] += (float) $f->ingreso;
            $g[$k]['lineas'] += (int) $f->n;
        }
        return $g;
    }

    /** @return array{0: array, 1: array} */
    private function top(string $desde, string $hasta): array
    {
        $filas = $this->lineasVivas($desde, $hasta)
            ->groupBy('v.nombre', 'v.tipo', 'v.servicio_id')
            ->select('v.nombre', 'v.tipo', 'v.servicio_id',
                // Unidades NETAS de devoluciones, con piso en cero: decir que
                // un producto vendió 10 cuando 8 volvieron sería mentir.
                DB::raw('SUM(' . $this->hv->unidadesLineaSql() . ') as cantidad'),
                DB::raw($this->vendidoSql() . ' as ingreso'))
            ->get();

        $productos = [];
        $servicios = [];
        foreach ($filas as $f) {
            $fila = [
                'nombre' => $f->nombre,
                'tipo' => $f->tipo,
                'cantidad' => (int) ($f->cantidad ?? 0),
                'ingreso' => (float) $f->ingreso,
            ];
            if ($this->hv->esLineaServicio($f->servicio_id, $f->tipo)) {
                $servicios[] = $fila;
            } else {
                $productos[] = $fila;
            }
        }
        // **Desempate por nombre.** Ordenar solo por ingreso deja el orden de
        // los empatados a merced de cómo devuelva las filas el GROUP BY, y
        // Postgres y MariaDB no coinciden: dos productos de $72.000 salían
        // intercambiados. Los dos sorts son estables (Python siempre, PHP
        // desde 8.0), así que el problema no es el sort sino su ENTRADA.
        //
        // Es el mismo principio del §9.14 —todo orden termina en un
        // desempate determinista— aplicado a un ranking en memoria.
        $porIngreso = fn ($a, $b) => ($b['ingreso'] <=> $a['ingreso'])
            ?: strcmp((string) $a['nombre'], (string) $b['nombre']);
        usort($productos, $porIngreso);
        usort($servicios, $porIngreso);

        return [array_slice($productos, 0, self::TOP_N), array_slice($servicios, 0, self::TOP_N)];
    }

    /**
     * Ingresos y atenciones por profesional, con servicios y productos aparte.
     *
     * POR QUÉ SE SEPARA
     *
     * La clínica pidió «cantidad de consultas realizadas por cada especialista
     * durante el mes», y de ahí quiere colgar comisiones. Las dos cosas
     * necesitan distinguir el servicio del producto: vender un saco de alimento
     * no es una atención, y una comisión sobre honorarios no se calcula igual
     * que sobre la reventa de un producto.
     *
     * Antes esto devolvía un solo `n_ventas` que sumaba las dos cosas, así que
     * el número no servía para ninguna de las dos preguntas. Sobre los datos
     * reales la diferencia es enorme: en junio de 2026 hay un profesional con
     * 1 atención y 123 productos vendidos, que aparecía con 124 y se leía como
     * el que más atendió.
     *
     * `esLineaServicio` es la MISMA función que usa el top de servicios (y la
     * misma decisión que toma el Python): si acá se clasificara por cuenta
     * propia, el panel podría contar una peluquería como atención en una
     * tarjeta y como producto en la de al lado.
     *
     * `n_ventas` e `ingreso` se conservan: son lo que consumía el panel antes,
     * y sacarlos rompería a cualquiera que todavía los lea.
     */
    private function porEspecialista(string $desde, string $hasta): array
    {
        // Se agrupa TAMBIÉN por tipo y servicio_id, que es lo que permite
        // clasificar cada grupo; el especialista se recompone sumando.
        $filas = $this->lineasVivas($desde, $hasta)
            ->whereNotNull('v.especialista_id')
            ->groupBy('v.especialista_id', 'v.tipo', 'v.servicio_id')
            ->select('v.especialista_id', 'v.tipo', 'v.servicio_id',
                DB::raw($this->vendidoSql() . ' as ingreso'),
                DB::raw('COUNT(v.id) as n'))
            ->get();

        if ($filas->isEmpty()) {
            return [];
        }

        $acum = [];
        foreach ($filas as $f) {
            $id = $f->especialista_id;
            $acum[$id] ??= [
                'ingreso' => 0.0, 'n_ventas' => 0,
                'ingreso_servicios' => 0.0, 'n_servicios' => 0,
                'ingreso_productos' => 0.0, 'n_productos' => 0,
            ];
            $monto = (float) $f->ingreso;
            $cuenta = (int) $f->n;
            $acum[$id]['ingreso'] += $monto;
            $acum[$id]['n_ventas'] += $cuenta;
            $grupo = $this->hv->esLineaServicio($f->servicio_id, $f->tipo) ? 'servicios' : 'productos';
            $acum[$id]["ingreso_{$grupo}"] += $monto;
            $acum[$id]["n_{$grupo}"] += $cuenta;
        }

        $nombres = DB::table('especialistas')
            ->whereIn('id', array_keys($acum))->pluck('nombre', 'id');

        $salida = [];
        foreach ($acum as $id => $datos) {
            $salida[] = ['especialista_id' => $id, 'nombre' => $nombres[$id] ?? '—'] + $datos;
        }

        // Mismo desempate que el top: sin él, dos especialistas con el mismo
        // ingreso (o con cero) salen en cualquier orden.
        usort($salida, fn ($a, $b) => ($b['ingreso'] <=> $a['ingreso'])
            ?: strcmp((string) $a['nombre'], (string) $b['nombre']));
        return $salida;
    }

    /** Valorización a precio COSTO, que es lo que la clínica tiene invertido. */
    private function inventario(): array
    {
        $filas = DB::table('items_inventario')
            ->where('activo', true)
            ->groupBy('tipo')
            ->select('tipo',
                DB::raw('SUM(stock_actual * precio_costo) as valor'),
                DB::raw('SUM(stock_actual) as unidades'))
            ->get();

        $porTipo = $filas->map(fn ($f) => [
            'tipo' => $f->tipo ?: 'Sin tipo',
            'valor' => (float) ($f->valor ?? 0),
            'unidades' => (int) ($f->unidades ?? 0),
        ])->values()->all();
        usort($porTipo, fn ($a, $b) => ($b['valor'] <=> $a['valor'])
            ?: strcmp((string) $a['tipo'], (string) $b['tipo']));

        return [
            'valorizacion_total' => array_sum(array_column($porTipo, 'valor')),
            'por_tipo' => $porTipo,
            'n_bajo_stock' => DB::table('items_inventario')
                ->where('activo', true)
                ->whereColumn('stock_actual', '<=', 'stock_minimo')
                ->count(),
        ];
    }

    /**
     * Las líneas que cuentan: las de ventas ni anuladas ni en borrador.
     *
     * `NOT EXISTS` y no un join, igual que Python: deja pasar las líneas
     * sueltas sin cabecera, que la importación de ACVet sí produce.
     */
    private function lineasVivas(string $desde, string $hasta)
    {
        $sin = "'" . implode("','", HistorialVentas::ESTADOS_SIN_VALOR) . "'";
        return DB::table('ventas as v')
            ->whereRaw("NOT EXISTS (SELECT 1 FROM transacciones t
                WHERE t.id = v.transaccion_id AND t.estado_venta IN ($sin))")
            ->where('v.fecha', '>=', $desde . ' 00:00:00')
            ->where('v.fecha', '<=', $hasta . ' 23:59:59.999999');
    }

    private function cabecerasVivas(string $desde, string $hasta)
    {
        return DB::table('transacciones as t')
            ->where(fn ($w) => $w->where('t.estado_venta', '<>', 'borrador')
                ->orWhereNull('t.estado_venta'))
            ->where('t.fecha', '>=', $desde . ' 00:00:00')
            ->where('t.fecha', '<=', $hasta . ' 23:59:59.999999');
    }

    // Las fórmulas viven en `HistorialVentas`, no acá: son las mismas que usan
    // las cuatro pantallas de historial, y tenerlas escritas dos veces es el
    // modo de falla que este archivo dice evitar en su cabecera.

    /** `total − devuelto`, sumado. */
    private function vendidoSql(): string
    {
        return 'COALESCE(SUM(' . $this->hv->vendidoLineaSql() . '), 0)';
    }

    private function devueltoSql(): string
    {
        return 'COALESCE(SUM(' . $this->hv->devueltoLineaSql() . '), 0)';
    }
}
