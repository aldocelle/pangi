<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Esquema;
use App\Services\HistorialVentas;
use App\Services\LinkDePago;
use App\Services\SlotsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /pagos/mios — «Pagos y facturas» del portal del tutor.
 *
 * Dos fuentes que NO se suman entre sí, y por eso salen separadas:
 *
 *   · **Compras en la clínica** (`transacciones` + `ventas`): lo que se cobró
 *     en el mostrador. Se serializan con `HistorialVentas`, la misma regla que
 *     usa el panel —qué cuenta como venta viva, cómo se netea una devolución,
 *     de dónde sale el estado de pago— para que el tutor y la clínica nunca
 *     vean dos números distintos para la misma boleta.
 *   · **Abonos de reservas** (`citas.monto_abono_clp`): lo pagado (o por
 *     pagar) en línea para dejar firme una hora.
 *
 * Lo que se recorta a propósito, frente a lo que ve el panel:
 *   · `documento_id`: texto libre con notas internas del mostrador.
 *   · borradores y anuladas: un borrador es trabajo a medio hacer del equipo y
 *     una anulada vale cero; mostrarlas solo confunde a quien lee su cuenta.
 *   · quién cobró, costo, el reparto por razón social y el resto de las
 *     cuentas: nada de eso es del tutor.
 *
 * No hay «facturas» como documento: el sistema no emite boletas ni facturas
 * (las emite el SII aparte). Lo que se muestra es el comprobante interno.
 */
class MisPagosController extends Controller
{
    /** Tope de compras devueltas: un tutor de ACVet tiene a lo sumo decenas. */
    private const TOPE = 300;

    private const MEDIOS = [
        'pago_efectivo' => 'Efectivo',
        'pago_debito' => 'Débito',
        'pago_credito' => 'Crédito',
        'pago_transferencia' => 'Transferencia',
        'pago_otro' => 'Otro',
    ];

    public function __construct(private HistorialVentas $hv) {}

    public function mios(Request $request)
    {
        $u = $request->attributes->get('usuario');

        // El dueño puede estar en la cabecera o solo en las líneas: las ventas
        // importadas de ACVet lo traen en la línea (mismo caso que en
        // `HistorialVentas::serializar`).
        $porLinea = DB::table('ventas')->where('propietario_id', $u->id)
            ->whereNotNull('transaccion_id')->select('transaccion_id');
        $filas = DB::table('transacciones')
            ->where(fn ($w) => $w->where('propietario_id', $u->id)->orWhereIn('id', $porLinea))
            ->where(fn ($w) => $w->whereNull('estado_venta')
                ->orWhereNotIn('estado_venta', HistorialVentas::ESTADOS_SIN_VALOR))
            ->orderByDesc('fecha')->orderByDesc('numero')->orderBy('id')
            ->limit(self::TOPE)
            ->get();

        $compras = array_map(fn ($t) => [
            'id' => $t['id'],
            'numero' => $t['numero'],
            'fecha' => $t['fecha'],
            'detalle' => $t['detalle'],
            'pacientes' => array_map(fn ($p) => ['id' => $p['id'], 'nombre' => $p['nombre']], $t['pacientes']),
            'lineas' => array_map(fn ($l) => [
                'nombre' => $l['nombre'],
                'es_servicio' => $l['es_servicio'],
                'mascota' => $l['mascota'],
                'cantidad' => $l['cantidad'],
                'cantidad_devuelta' => $l['cantidad_devuelta'],
                'precio' => $l['precio_venta'],
                'descuento' => $l['descuento'],
                'subtotal' => $l['subtotal_efectivo'],
            ], $t['lineas']),
            'subtotal' => $t['subtotal'],
            'descuento' => $t['descuento'],
            'devuelto' => $t['devuelto'],
            'total' => $t['total_efectivo'],
            'pagado' => $t['pagado'],
            'pendiente' => $t['pendiente'],
            'medios' => array_values(array_filter(array_map(
                fn ($k) => $t['pagos'][$k] > 0 ? ['medio' => self::MEDIOS[$k], 'monto' => $t['pagos'][$k]] : null,
                array_keys(self::MEDIOS)))),
            'estado' => $t['estado_pago'],
            'estado_label' => $t['estado_pago_label'],
        ], $this->hv->serializar($filas));

        return [
            'compras' => $compras,
            'abonos' => $this->abonos($u->id),
            'resumen' => [
                'pendiente' => array_sum(array_column($compras, 'pendiente')),
                'pagado_anio' => array_sum(array_map(
                    fn ($c) => str_starts_with((string) $c['fecha'], CarbonImmutable::now('America/Santiago')->format('Y')) ? $c['pagado'] : 0,
                    $compras)),
                'n_compras' => count($compras),
            ],
        ];
    }

    /**
     * Abonos en línea: los aprobados (historial) y los pendientes que todavía
     * se pueden pagar. Un pendiente de una cita cancelada, pasada o cuyo plazo
     * de 15 minutos venció no es deuda: la hora ya no es suya, y ofrecerle
     * «Pagar» era cobrarle una hora que el sistema le estaba dando a otro.
     * Vale también para la hora coordinada por la clínica (decisión de producto, 12-oct): su
     * plazo corre desde que se le mandó el enlace.
     */
    private function abonos(string $usuarioId): array
    {
        // Hora de la clínica, no UTC: `fecha_hora` está en hora local (§9.6).
        $ahora = CarbonImmutable::now('America/Santiago')->format('Y-m-d H:i:s');
        // `created_at` es marca técnica en UTC: el plazo se mide contra eso.
        $limiteVentana = CarbonImmutable::now('UTC')
            ->subMinutes(SlotsService::MINUTOS_VENTANA_PAGO)->format('Y-m-d H:i:s');

        return DB::table('citas as c')
            ->leftJoin('servicios as s', 's.id', '=', 'c.servicio_id')
            ->leftJoin('mascotas as m', 'm.id', '=', 'c.mascota_id')
            // `usuario_dueno_id`, no `usuario_id`: así se llama en `citas`.
            ->where('c.usuario_dueno_id', $usuarioId)
            ->where('c.monto_abono_clp', '>', 0)
            ->where(fn ($w) => $w->where('c.payment_status', 'approved')
                ->orWhere(fn ($p) => $p->where('c.payment_status', 'pending')
                    ->whereIn('c.estado', ['Agendada', 'Confirmada'])
                    ->where('c.fecha_hora', '>', $ahora)
                    // Misma regla que `LinkDePago::horaLiberada`: 15 minutos
                    // desde que reservó, o desde que la clínica le mandó el
                    // enlace. Vencida, la hora ya no es suya: no se ofrece.
                    ->where(fn ($v) => LinkDePago::whereVentanaAbierta($v, $limiteVentana, 'c.'))))
            ->orderByDesc('c.fecha_hora')->orderBy('c.id')
            ->limit(self::TOPE)
            ->get(array_merge([
                'c.id', 'c.fecha_hora', 'c.estado', 'c.payment_status', 'c.monto_abono_clp',
                'c.monto_total_clp', 'c.pago_modalidad', 'c.pago_referencia', 'c.pago_actualizado_at',
                'c.pago_modalidad_requerida', 'c.created_at',
                's.nombre as servicio_catalogo', 'c.servicio as servicio_texto', 'm.nombre as mascota',
            ], Esquema::columna('citas', 'pago_enlace_at') ? ['c.pago_enlace_at'] : []))
            ->map(fn ($c) => [
                'cita_id' => $c->id,
                'fecha_cita' => str_replace(' ', 'T', substr($c->fecha_hora, 0, 19)),
                // Las citas importadas no tienen `servicio_id`: queda el texto.
                'servicio' => $c->servicio_catalogo ?: $c->servicio_texto,
                'mascota' => $c->mascota,
                'estado_cita' => $c->estado,
                'pagado' => $c->payment_status === 'approved',
                // Lo cobrado: si pagó el total, el total; si no, el abono.
                'monto' => $c->payment_status === 'approved' && $c->pago_modalidad === 'total' && $c->monto_total_clp
                    ? (int) $c->monto_total_clp : (int) $c->monto_abono_clp,
                'modalidad' => $c->pago_modalidad === 'total' ? 'total' : 'abono',
                'referencia' => $c->pago_referencia,
                // Para la cuenta regresiva de la lista; null si no corre plazo.
                'pagar_hasta' => LinkDePago::pagarHasta($c),
                'pagado_at' => $c->pago_actualizado_at
                    ? str_replace(' ', 'T', substr($c->pago_actualizado_at, 0, 19)) : null,
            ])
            ->values()->all();
    }
}
