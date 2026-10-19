<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Qué cuenta como venta viva, y cuánto vale.
 *
 * Puerto de `backend/app/services/historial_ventas.py`. Es el servicio con más
 * riesgo de toda la migración: lo comparten las cuatro pantallas que muestran
 * el historial (cliente, paciente, ítem de inventario y catálogo de servicios)
 * y además Estadísticas. Un error acá **no rompe nada visible** — cambia los
 * números que la clínica usa para decidir.
 *
 * Las reglas que más cuesta acertar, y por qué son así:
 *
 * **Un descuento mayor que la línea la deja en CERO, no en negativo.** Si no,
 * una línea absorbe plata de las otras y el total de la venta baja sin que
 * ninguna cifra visible lo explique.
 *
 * **Lo devuelto se PRORRATEA**, no se calcula como precio × unidades
 * devueltas: devolver 1 de 2 unidades de una línea con $5.000 de descuento
 * devuelve $2.500 de ese descuento. Con el precio pelado, la clínica devolvería
 * más plata de la que cobró por esa unidad.
 *
 * **Una venta anulada o en borrador vale CERO** para los agregados, pero
 * conserva sus montos a la vista: hay que poder ver qué se anuló.
 */
class HistorialVentas
{
    /** Las columnas de cobro de la cabecera. */
    public const MEDIOS_PAGO = [
        'pago_efectivo', 'pago_debito', 'pago_credito', 'pago_transferencia', 'pago_otro',
    ];

    /**
     * Dos ejes, no uno: en qué va el documento y en qué va la plata. Meterlos
     * en una sola columna obligaría a inventar combinaciones («anulada y
     * parcialmente pagada») o a perder información al elegir cuál gana.
     */
    public const ESTADOS = [
        'pagada' => 'Pagada',
        'parcial' => 'Parcialmente pagada',
        'pendiente' => 'Pendiente de pago',
    ];

    public const ESTADOS_VENTA = [
        'borrador' => 'Borrador',
        'confirmada' => 'Confirmada',
        'anulada' => 'Anulada',
        'devuelta_parcial' => 'Devuelta en parte',
        'devuelta' => 'Devuelta',
    ];

    /** Lo que ya no es una venta viva: se lista, pero suma cero. */
    public const ESTADOS_SIN_VALOR = ['borrador', 'anulada'];

    public const TIPOS_SERVICIO = [
        'Clinico', 'Laboratorio', 'Peluqueria', 'Quirurgico', 'Imagenología',
    ];

    public const TIPOS_PRODUCTO = ['Farmaco', 'Pet-shop', 'Alimento', 'Insumo'];

    /**
     * ¿Esta línea es un servicio?
     *
     * Regla única, unión de las dos que había. El historial miraba solo
     * `servicio_id` y la caja solo `tipo`: una peluquería importada de ACVet
     * (tipo 'Peluqueria', sin referencia al catálogo) contaba como servicio en
     * el cuadre del día y como producto en la venta. Con dos razones sociales
     * de por medio, eso deja de ser una incoherencia de reportes y pasa a ser
     * **la empresa equivocada emitiendo**.
     */
    public function esLineaServicio($servicioId, ?string $tipo): bool
    {
        if ($servicioId) {
            return true;
        }
        $t = trim((string) $tipo);
        return in_array($t, self::TIPOS_SERVICIO, true) || strtolower($t) === 'servicio';
    }

    /** Lo que vale una línea. Un descuento mayor la deja en cero. */
    public function totalLinea($v): float
    {
        $bruto = (float) ($v->precio_venta ?? 0) * (int) ($v->cantidad ?? 0)
               - (float) ($v->descuento ?? 0);
        return max(0.0, $bruto);
    }

    /** Lo devuelto de una línea, a prorrata. */
    public function devueltoLinea($v): float
    {
        $cantidad = (int) ($v->cantidad ?? 0);
        $devuelta = (int) ($v->cantidad_devuelta ?? 0);
        if ($cantidad <= 0 || $devuelta <= 0) {
            return 0.0;
        }
        $devuelta = min($devuelta, $cantidad);
        // `quantize(Decimal("1"))` de Python redondea al entero con banker's
        // rounding (mitad al par). `round()` de PHP redondea la mitad hacia
        // arriba, así que se usa la función que replica el criterio.
        return $this->redondearAlPar($this->totalLinea($v) * $devuelta / $cantidad);
    }

    /** Lo que quedó vendido de verdad: cobrado menos devuelto. */
    public function vendidoLinea($v): float
    {
        return $this->totalLinea($v) - $this->devueltoLinea($v);
    }

    /**
     * Estado derivado del dinero, no de una columna aparte.
     *
     * `total_pendiente` es el campo que el mostrador mantiene al cobrar de
     * menos, así que manda. Deliberadamente NO existen «borrador»,
     * «cancelada» ni «devuelta» acá: la base no guarda nada que permita
     * distinguirlas, y devolver una etiqueta sin dato detrás es inventar.
     */
    public function estadoPago($cobrado, $pendiente): string
    {
        if ((float) ($pendiente ?? 0) > 0) {
            return (float) ($cobrado ?? 0) > 0 ? 'parcial' : 'pendiente';
        }
        return 'pagada';
    }

    /**
     * El único estado que se pinta en la fila.
     *
     * Mientras la venta está viva manda el de pago (es lo que el mostrador
     * necesita saber); si se anuló o devolvió, eso pasa a ser lo importante y
     * el de pago queda de contexto en el detalle.
     *
     * @return array{0: string, 1: string}
     */
    public function estadoMostrado(?string $estadoVenta, string $estadoDePago): array
    {
        if ($estadoVenta && $estadoVenta !== 'confirmada') {
            return [$estadoVenta, self::ESTADOS_VENTA[$estadoVenta] ?? $estadoVenta];
        }
        return [$estadoDePago, self::ESTADOS[$estadoDePago]];
    }

    /** «2 productos · 1 servicio», para leer la fila sin abrir la venta. */
    public function resumenDetalle(array $lineas): string
    {
        $productos = count(array_filter($lineas, fn ($l) => ! $l['es_servicio']));
        $servicios = count($lineas) - $productos;
        $partes = [];
        if ($productos) {
            $partes[] = $productos . ' producto' . ($productos !== 1 ? 's' : '');
        }
        if ($servicios) {
            $partes[] = $servicios . ' servicio' . ($servicios !== 1 ? 's' : '');
        }
        return $partes ? implode(' · ', $partes) : 'Sin líneas';
    }

    /**
     * Serializa una página de transacciones.
     *
     * @param  \Illuminate\Support\Collection  $filas
     */
    public function serializar($filas): array
    {
        if ($filas->isEmpty()) {
            return [];
        }

        $ids = $filas->pluck('id')->all();
        $lineas = DB::table('ventas')
            ->whereIn('transaccion_id', $ids)
            ->orderBy('created_at')->orderBy('nombre')->orderBy('id')
            ->get();

        $mascotas = $this->mapa('mascotas', $lineas->pluck('mascota_id'));
        $especialistas = $this->mapa(
            'especialistas',
            $filas->pluck('especialista_id')->merge($lineas->pluck('especialista_id'))
        );
        // El dueño puede venir en la cabecera o solo en las líneas: las ventas
        // importadas de ACVet lo traen en la línea.
        $clientes = $this->mapa(
            'usuarios',
            $filas->pluck('propietario_id')->merge($lineas->pluck('propietario_id'))
        );

        $porTransaccion = $lineas->groupBy('transaccion_id');

        return $filas->map(function ($t) use ($porTransaccion, $mascotas, $especialistas, $clientes) {
            $propias = $porTransaccion[$t->id] ?? collect();
            $clienteId = $t->propietario_id
                ?: ($propias->firstWhere('propietario_id', '!=', null)->propietario_id ?? null);

            $lineasOut = $propias->map(fn ($v) => [
                'id' => $v->id,
                'item_id' => $v->item_id,
                'servicio_id' => $v->servicio_id,
                'mascota_id' => $v->mascota_id,
                'mascota' => $mascotas[$v->mascota_id]->nombre ?? null,
                'especialista' => $especialistas[$v->especialista_id]->nombre ?? null,
                'nombre' => $v->nombre,
                'tipo' => $v->tipo,
                'es_servicio' => $this->esLineaServicio($v->servicio_id, $v->tipo),
                'cantidad' => (int) ($v->cantidad ?? 0),
                'cantidad_devuelta' => (int) ($v->cantidad_devuelta ?? 0),
                'precio_venta' => (float) ($v->precio_venta ?? 0),
                'descuento' => (float) ($v->descuento ?? 0),
                'subtotal' => $this->totalLinea($v),
                'devuelto' => $this->devueltoLinea($v),
                'subtotal_efectivo' => $this->vendidoLinea($v),
                'devuelta_at' => $this->iso($v->devuelta_at),
                'devuelta_por' => $v->devuelta_por_nombre,
                'motivo_devolucion' => $v->motivo_devolucion,
            ])->values()->all();

            $pacientes = [];
            $vistos = [];
            foreach ($propias as $v) {
                if ($v->mascota_id && ! in_array($v->mascota_id, $vistos, true)) {
                    $vistos[] = $v->mascota_id;
                    $pacientes[] = [
                        'id' => $v->mascota_id,
                        'nombre' => $mascotas[$v->mascota_id]->nombre ?? null,
                        'especie' => $mascotas[$v->mascota_id]->especie ?? null,
                    ];
                }
            }

            $bruto = 0.0;
            foreach ($propias as $v) {
                $bruto += (float) ($v->precio_venta ?? 0) * (int) ($v->cantidad ?? 0);
            }
            $total = array_sum(array_column($lineasOut, 'subtotal'));
            // El descuento informado es el EFECTIVO (bruto − total), no la
            // suma de la columna: un descuento mayor que su línea la deja en
            // cero y no negativa, así que sumar la columna daba un resumen
            // donde subtotal − descuento ≠ total, con los tres a la vista.
            $descuento = $bruto - $total;
            $devuelto = array_sum(array_column($lineasOut, 'devuelto'));

            $cobrado = 0.0;
            $pagos = [];
            foreach (self::MEDIOS_PAGO as $medio) {
                $pagos[$medio] = (float) ($t->$medio ?? 0);
                $cobrado += $pagos[$medio];
            }
            $pendiente = (float) ($t->total_pendiente ?? 0);

            $estadoVenta = $t->estado_venta ?: 'confirmada';
            $dePago = $this->estadoPago($cobrado, $pendiente);
            [$claveEstado, $etiquetaEstado] = $this->estadoMostrado($estadoVenta, $dePago);
            // Una venta anulada vale cero para cualquier total, pero conserva
            // sus montos a la vista: hay que poder ver QUÉ se anuló.
            $efectivo = in_array($estadoVenta, self::ESTADOS_SIN_VALOR, true)
                ? 0.0 : $total - $devuelto;

            return [
                'id' => $t->id,
                'numero' => $t->numero,
                'fecha' => $this->iso($t->fecha),
                'documento_id' => $t->documento_id,
                'cliente' => $clienteId
                    ? $this->clienteOut($clientes[$clienteId] ?? null, $clienteId) : null,
                'pacientes' => $pacientes,
                'especialista' => $especialistas[$t->especialista_id]->nombre ?? null,
                'creado_por' => $t->creado_por_nombre,
                'lineas' => $lineasOut,
                'n_lineas' => count($lineasOut),
                'detalle' => $this->resumenDetalle($lineasOut),
                'subtotal' => $bruto,
                'descuento' => $descuento,
                'total' => $total,
                // Cuánto le toca a cada razón social. El reparto sale de QUÉ
                // se vendió, no de CÓMO se pagó: la naturaleza de cada línea
                // decide qué empresa emite.
                'desglose_razon' => [
                    'productos' => $this->sumaComoPython(array_column(
                        array_filter($lineasOut, fn ($l) => ! $l['es_servicio']), 'subtotal')),
                    'servicios' => $this->sumaComoPython(array_column(
                        array_filter($lineasOut, fn ($l) => $l['es_servicio']), 'subtotal')),
                ],
                'devuelto' => $devuelto,
                'total_efectivo' => $efectivo,
                'pagado' => $cobrado,
                'pendiente' => $pendiente,
                'pagos' => $pagos,
                'estado_venta' => $estadoVenta,
                'estado_venta_label' => self::ESTADOS_VENTA[$estadoVenta] ?? $estadoVenta,
                'estado_pago' => $dePago,
                'estado_pago_label' => self::ESTADOS[$dePago],
                'estado' => $claveEstado,
                'estado_label' => $etiquetaEstado,
                'cuenta_en_caja' => (bool) $t->cuenta_en_caja,
                'anulacion' => $t->anulada_at ? [
                    'at' => $this->iso($t->anulada_at),
                    'por' => $t->anulada_por_nombre,
                    'motivo' => $t->motivo_anulacion,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * `round(sum(xs), 2)` con la MISMA semántica de tipo que Python.
     *
     * Dos detalles que hay que reproducir juntos, y que solo se ven mirando el
     * literal del JSON (regla 3 del comparador):
     *
     *  · `sum([])` en Python es el ENTERO 0, y `round(0, 2)` sigue siendo un
     *    entero — así que una venta sin líneas de servicio publica `0`, no
     *    `0.0`. Con líneas, la suma es float y sale `0.0`.
     *  · `round()` de PHP devuelve float SIEMPRE, incluso sobre un int.
     *
     * Sumar todas las líneas mapeando la otra mitad a 0 tampoco sirve: da un
     * int 0 que `round()` convierte igual en float.
     */
    private function sumaComoPython(array $valores): float|int
    {
        if (! $valores) {
            return 0;
        }
        return round(array_sum($valores), 2);
    }

    private function clienteOut($datos, $clienteId): ?array
    {
        if (! $datos) {
            return null;
        }
        // `?? null` y no `->email`: con la lista de columnas recortada esas
        // propiedades no existen en la fila, y ahí está justamente la gracia.
        $email = $datos->email ?? null;
        return [
            'id' => $clienteId,
            'nombre' => $datos->nombre ?? null,
            'rut' => $datos->rut ?? null,
            'telefono' => $datos->telefono ?? null,
            // El backend fabrica un correo @interno.clinica cuando el cliente
            // se crea solo con nombre y teléfono; no es un contacto real.
            'email' => str_ends_with((string) $email, '@interno.clinica') ? null : $email,
        ];
    }

    /**
     * Trae de una vez las filas de una tabla por id, indexadas.
     *
     * `$columnas` no es una optimización: es lo que decide **qué se publica**.
     * El historial línea a línea pide solo `nombre` y `rut`, así que el correo
     * y el teléfono del cliente salen en `null` — y tienen que seguir saliendo
     * así. Traer la fila entera «porque ya está» los publicaría en un endpoint
     * que hoy no los lleva (§9.13, mismo criterio que `_esp_out`).
     */
    private function mapa(string $tabla, $ids, array $columnas = ['*'])
    {
        $ids = $ids->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        if ($columnas !== ['*'] && ! in_array('id', $columnas, true)) {
            $columnas[] = 'id';   // hace falta para indexar
        }
        return DB::table($tabla)->whereIn('id', $ids)->get($columnas)->keyBy('id');
    }

    /**
     * Banker's rounding: la mitad exacta va al PAR, como `Decimal.quantize`.
     * `round()` de PHP la manda hacia arriba, y sobre miles de líneas esa
     * diferencia se acumula en los totales.
     */
    private function redondearAlPar(float $v): float
    {
        return round($v, 0, PHP_ROUND_HALF_EVEN);
    }

    /**
     * El filtro de TEXTO de una venta: número, documento, cliente por
     * nombre/correo/RUT, paciente, producto o servicio.
     *
     * Vive acá y no en el controlador porque lo usan DOS sitios —el listado de
     * Ventas y el buscador global— y tienen que encontrar lo mismo. Antes el
     * buscador global solo miraba el documento y el nombre del ítem, así que
     * escribir el nombre de la mascota no encontraba nada desde ahí aunque sí
     * desde Ventas.
     *
     * `$alias` es cómo se llama la tabla `transacciones` en la consulta que
     * llama (normalmente `t`).
     */
    public function filtrarTexto($q, string $texto, string $alias = 't')
    {
        $texto = trim($texto);
        if ($texto === '') {
            return $q;
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $texto) . '%';

        return $q->where(function ($w) use ($like, $alias) {
            $w->where("{$alias}.documento_id", 'like', $like)
              // El número es entero: se castea a texto para poder buscar «104»
              // dentro de «1042».
              ->orWhere(DB::raw("CAST({$alias}.numero AS CHAR)"), 'like', $like)
              ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('ventas')
                  ->whereColumn('ventas.transaccion_id', "{$alias}.id")
                  ->where('ventas.nombre', 'like', $like))
              ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('usuarios')
                  ->whereColumn('usuarios.id', "{$alias}.propietario_id")
                  ->where(fn ($x) => $x->where('usuarios.nombre', 'like', $like)
                      ->orWhere('usuarios.email', 'like', $like)
                      ->orWhere('usuarios.rut', 'like', $like)))
              ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('ventas')
                  ->join('mascotas', 'mascotas.id', '=', 'ventas.mascota_id')
                  ->whereColumn('ventas.transaccion_id', "{$alias}.id")
                  ->where('mascotas.nombre', 'like', $like));
        });
    }

    // ── Las mismas reglas, en SQL ──
    //
    // Duplicar estas expresiones es exactamente lo que este servicio existe
    // para evitar: si el prorrateo de una devolución se escribe distinto en
    // Estadísticas que en el historial, los dos números salen y **ninguno
    // parece roto**. `$a` es el alias de `ventas` en la consulta que llama.

    /** Un descuento mayor que la línea la deja en cero, no en negativo. */
    public function totalLineaSql(string $a = 'v'): string
    {
        return "GREATEST(COALESCE($a.precio_venta,0) * COALESCE($a.cantidad,0)
                - COALESCE($a.descuento,0), 0)";
    }

    /**
     * Lo devuelto, a prorrata. El CASE evita la división por cero de una línea
     * con cantidad 0, que la importación de ACVet sí produce.
     */
    public function devueltoLineaSql(string $a = 'v'): string
    {
        return "CASE WHEN COALESCE($a.cantidad,0) > 0
                      AND LEAST(COALESCE($a.cantidad_devuelta,0), COALESCE($a.cantidad,0)) > 0
                 THEN " . $this->totalLineaSql($a) . "
                      * LEAST(COALESCE($a.cantidad_devuelta,0), COALESCE($a.cantidad,0))
                      / COALESCE($a.cantidad,0)
                 ELSE 0 END";
    }

    /** Lo que quedó vendido de verdad: cobrado menos devuelto. */
    public function vendidoLineaSql(string $a = 'v'): string
    {
        return '(' . $this->totalLineaSql($a) . ' - ' . $this->devueltoLineaSql($a) . ')';
    }

    /** Las unidades que quedaron vendidas, netas de devoluciones. */
    public function unidadesLineaSql(string $a = 'v'): string
    {
        return "GREATEST(COALESCE($a.cantidad,0) - COALESCE($a.cantidad_devuelta,0), 0)";
    }

    /**
     * Filtros del historial **línea a línea**: «quién compró este producto».
     *
     * `servicio_nombre` acompaña a `servicio_id` en vez de reemplazarlo: las
     * ventas anteriores a `ventas.servicio_id` —todo lo importado de ACVet—
     * solo guardan el nombre, y dejarlas fuera haría ver un servicio de años
     * como si se hubiera vendido la semana pasada por primera vez.
     */
    public function filtrarLineas($q, array $f, string $a = 'v')
    {
        if ($v = $f['item_id'] ?? null) {
            $q->where("$a.item_id", $v);
        }

        $servicioId = $f['servicio_id'] ?? null;
        $servicioNombre = trim((string) ($f['servicio_nombre'] ?? ''));
        if ($servicioId || $servicioNombre !== '') {
            $q->where(function ($w) use ($servicioId, $servicioNombre, $a) {
                if ($servicioId) {
                    $w->orWhere("$a.servicio_id", $servicioId);
                }
                if ($servicioNombre !== '') {
                    $w->orWhere("$a.nombre", 'like', '%' . $servicioNombre . '%');
                }
            });
        }
        if ($v = trim((string) ($f['producto'] ?? ''))) {
            $q->where("$a.nombre", 'like', '%' . $v . '%');
        }
        if ($v = $f['propietario_id'] ?? null) {
            $q->where("$a.propietario_id", $v);
        }
        if ($v = $f['mascota_id'] ?? null) {
            $q->where("$a.mascota_id", $v);
        }
        if ($v = $f['categoria'] ?? null) {
            $q->where("$a.tipo", $v);
        }

        // Las líneas de una venta anulada o en borrador no son compras: no
        // pueden aparecer en «qué se compró para Luna» ni sumar en el
        // historial de un producto. El NOT EXISTS (en vez de un join) deja
        // pasar las líneas sueltas sin cabecera, que ACVet sí produce.
        $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('transacciones')
            ->whereColumn('transacciones.id', "$a.transaccion_id")
            ->whereIn('transacciones.estado_venta', self::ESTADOS_SIN_VALOR));

        if ($v = $f['desde'] ?? null) {
            $q->where("$a.fecha", '>=', $v . ' 00:00:00');
        }
        if ($v = $f['hasta'] ?? null) {
            // El día entero, por lo mismo que el listado de cabeceras.
            $q->where("$a.fecha", '<=', $v . ' 23:59:59.999999');
        }
        return $q;
    }

    /**
     * Historial línea a línea: una fila por producto o servicio vendido, con
     * a quién y para qué paciente.
     *
     * Para el PACIENTE la unidad es la línea y no la venta: una boleta puede
     * mezclar dos mascotas del mismo dueño, y mostrar la venta entera en la
     * ficha de Luna le atribuiría el alimento de Max.
     *
     * @param  \Illuminate\Support\Collection  $filas
     */
    public function serializarLineas($filas): array
    {
        if ($filas->isEmpty()) {
            return [];
        }

        // Solo `nombre` y `rut`: este historial NO publica el contacto del
        // cliente, a diferencia del listado de cobros. Es la misma lista de
        // campos que pide Python, y quitarla llenaría de correos y teléfonos
        // una pantalla que hoy no los muestra.
        $clientes = $this->mapa('usuarios', $filas->pluck('propietario_id'), ['nombre', 'rut']);
        $mascotas = $this->mapa('mascotas', $filas->pluck('mascota_id'), ['nombre']);
        $especialistas = $this->mapa('especialistas', $filas->pluck('especialista_id'), ['nombre']);
        $cabeceras = $this->mapa('transacciones', $filas->pluck('transaccion_id'),
            ['numero', 'propietario_id']);

        // El dueño puede no estar en la línea pero sí en la cabecera del cobro.
        $faltantes = $cabeceras->pluck('propietario_id')->filter()
            ->reject(fn ($id) => $clientes->has($id))->values();
        if ($faltantes->isNotEmpty()) {
            $clientes = $clientes->union($this->mapa('usuarios', $faltantes, ['nombre', 'rut']));
        }

        return $filas->map(function ($v) use ($clientes, $mascotas, $especialistas, $cabeceras) {
            $cabecera = $v->transaccion_id ? $cabeceras->get($v->transaccion_id) : null;
            $clienteId = $v->propietario_id ?: ($cabecera->propietario_id ?? null);

            return [
                'id' => $v->id,
                'transaccion_id' => $v->transaccion_id,
                'numero' => $cabecera->numero ?? null,
                'fecha' => $this->iso($v->fecha),
                'nombre' => $v->nombre,
                'tipo' => $v->tipo,
                'item_id' => $v->item_id,
                'servicio_id' => $v->servicio_id,
                'cliente' => $clienteId
                    ? $this->clienteOut($clientes->get($clienteId), $clienteId) : null,
                'paciente' => $v->mascota_id
                    ? ($mascotas->get($v->mascota_id)->nombre ?? null) : null,
                'mascota_id' => $v->mascota_id,
                'especialista' => $v->especialista_id
                    ? ($especialistas->get($v->especialista_id)->nombre ?? null) : null,
                'cantidad' => (int) ($v->cantidad ?? 0),
                'cantidad_devuelta' => (int) ($v->cantidad_devuelta ?? 0),
                'precio_venta' => (float) ($v->precio_venta ?? 0),
                'descuento' => (float) ($v->descuento ?? 0),
                'total' => $this->vendidoLinea($v),
                'total_bruto' => $this->totalLinea($v),
                'devuelto' => $this->devueltoLinea($v),
            ];
        })->values()->all();
    }

    /**
     * Agregados de un historial línea a línea (un producto, un servicio).
     *
     * Las unidades y el total son **netos de devoluciones**: decir que un
     * producto se vendió 41 veces cuando 6 volvieron es lo que llevaría a
     * reponer de más.
     */
    public function resumenLineas($q): array
    {
        $a = (clone $q)->reorder()->selectRaw(
            'COUNT(v.id) as n_lineas,
             COALESCE(SUM(' . $this->unidadesLineaSql() . '), 0) as n_unidades,
             COALESCE(SUM(' . $this->vendidoLineaSql() . '), 0) as total,
             MAX(v.fecha) as ultima_fecha,
             COALESCE(SUM(COALESCE(v.cantidad_devuelta, 0)), 0) as n_devueltas'
        )->first();

        return [
            'n_lineas' => (int) ($a->n_lineas ?? 0),
            'n_unidades' => (int) ($a->n_unidades ?? 0),
            'total' => (float) ($a->total ?? 0),
            'ultima_fecha' => $this->iso($a->ultima_fecha ?? null),
            'n_devueltas' => (int) ($a->n_devueltas ?? 0),
        ];
    }

    public function iso(?string $v): ?string
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
