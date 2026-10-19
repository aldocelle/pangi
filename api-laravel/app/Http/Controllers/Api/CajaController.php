<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Services\HistorialVentas;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Fechas;

/**
 * Caja: apertura, cierre y cuadre del turno (permiso «caja»).
 *
 * **`cuenta_en_caja` es la regla que sostiene todo el cuadre.** Es False en dos
 * casos, y en los dos incluirlas haría que el turno cerrara reclamando dinero
 * que no existe: los borradores (ventas que todavía no se cobraron) y las
 * anuladas cuya plata NUNCA entró.
 *
 * Una venta anulada cuya plata SÍ entró y se devolvió mantiene el flag en True
 * a propósito: ese día el dinero entró de verdad, y la salida está registrada
 * como egreso del turno en que se devolvió. Descontarla acá además del egreso
 * la restaría dos veces.
 *
 * Los cuatro cálculos del día pasan por el mismo filtro justamente para que no
 * puedan divergir: si uno filtrara y otro no, el resumen contradiría al listado
 * que tiene al lado.
 */
class CajaController extends Controller
{
    private const MEDIOS = [
        'efectivo' => 'pago_efectivo',
        'debito' => 'pago_debito',
        'credito' => 'pago_credito',
        'transferencia' => 'pago_transferencia',
        'otro' => 'pago_otro',
    ];

    /** `paginar()` recorta a 20 aunque el endpoint pida 30. */
    private const LIMITE_MAX = 20;

    private const ETIQUETA_MEDIO = [
        'efectivo' => 'Efectivo',
        'debito' => 'Débito',
        'credito' => 'Crédito',
        'transferencia' => 'Transferencia',
        'otro' => 'Otro',
    ];

    public function __construct(
        private HistorialVentas $hv,
        private Auditoria $auditoria,
    ) {
    }

    public function estado(Request $request)
    {
        $dia = $request->query('fecha')
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->query('fecha'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();
        $desde = $dia->format('Y-m-d H:i:s');
        // Día de calendario: `addDay()` se corre una hora alrededor del salto
        // de septiembre (2 días al año, medidos) y este es el borde superior
        // del cuadre de caja — metería la primera hora del día siguiente en el
        // turno de hoy. Con la clínica cerrada a esa hora el efecto real es
        // nulo, pero el borde de un cuadre de plata no se deja torcido a
        // propósito. Ver App\Services\Fechas.
        $hasta = Fechas::sumarDias($dia, 1)->format('Y-m-d H:i:s');

        $sesiones = DB::table('sesiones_caja')
            ->whereDate('fecha', $dia->format('Y-m-d'))
            ->orderBy('abierta_at')->orderBy('id')
            ->get();
        $abierta = $sesiones->firstWhere('cerrada_at', null);

        // `abrir()`/`cerrar()`/`cambioTurno()` operan sobre la caja abierta
        // GLOBAL (§sesionAbierta), sin mirar qué día se está viendo en el
        // panel. Si esa caja es de otro día, `$abierta` (de arriba, acotada a
        // $dia) sale null y el panel no mostraría ningún botón para cerrarla
        // mientras se mira "hoy" — quedaría bloqueando «Abrir caja» sin que
        // nadie pueda llegar a ella desde la vista normal. Por eso se expone
        // aparte, solo cuando no es la misma que ya se muestra para $dia.
        $abiertaOtroDia = $abierta ? null : $this->sesionAbierta();

        $movimientos = $sesiones->isEmpty() ? collect() : DB::table('movimientos_caja')
            ->whereIn('sesion_id', $sesiones->pluck('id'))
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $nombres = $movimientos->pluck('usuario_id')->filter()->unique();
        $nombres = $nombres->isEmpty() ? collect() : DB::table('usuarios')
            ->whereIn('id', $nombres)->pluck('nombre', 'id');

        $ventas = $this->resumenVentas($desde, $hasta);

        return [
            'fecha' => $dia->format('Y-m-d'),
            'es_hoy' => $dia->isSameDay(CarbonImmutable::now()),
            'sesion_abierta' => $abierta ? $this->sesionOut($abierta) : null,
            'sesion_abierta_otro_dia' => $abiertaOtroDia ? $this->sesionOut($abiertaOtroDia) : null,
            'efectivo_esperado' => $abierta ? $this->esperadoDeSesion($abierta) : null,
            'efectivo_esperado_otro_dia' => $abiertaOtroDia ? $this->esperadoDeSesion($abiertaOtroDia) : null,
            'sesiones' => $sesiones->map(fn ($s) => $this->sesionOut($s))->values(),
            'movimientos' => $movimientos->map(fn ($m) => $this->movimientoOut($m, $nombres))->values(),
            'resumen_productos' => $ventas['productos'],
            'resumen_servicios' => $ventas['servicios'],
            'resumen_otros' => $ventas['otros'],
            'resumen_por_tipo' => $ventas['por_tipo'],
            'resumen_transacciones' => $this->resumenTransacciones($desde, $hasta),
        ];
    }

    /**
     * POST /caja/abrir — permiso «caja».
     *
     * Una sola caja abierta a la vez: dos turnos simultáneos harían que el
     * efectivo esperado de cada uno contara las ventas del otro.
     */
    public function abrir(Request $request)
    {
        $datos = $request->validate([
            'monto_inicial' => ['nullable', 'numeric', 'min:0'],
            'comentario' => ['nullable', 'string'],
        ]);

        if ($this->sesionAbierta()) {
            return response()->json([
                'detail' => 'Ya hay una caja abierta. Ciérrala o haz un cambio de turno.',
            ], 409);
        }

        $usuario = $request->attributes->get('usuario');
        $monto = (float) ($datos['monto_inicial'] ?? 0);

        return DB::transaction(function () use ($datos, $usuario, $monto) {
            // Hora de la CLÍNICA: el día operativo sale de la hora local, así
            // que una caja abierta a las 23:50 pertenece a ESE día y no al
            // siguiente en UTC (§9.6).
            $ahora = CarbonImmutable::now();
            $id = (string) Str::uuid();

            DB::table('sesiones_caja')->insert([
                'id' => $id,
                'usuario_id' => $usuario->id,
                'fecha' => $ahora->format('Y-m-d'),
                'abierta_at' => $ahora->format('Y-m-d H:i:s.u'),
                'monto_inicial' => $monto,
                'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            ]);

            // El fondo inicial queda como movimiento, no solo como columna:
            // así el turno se lee entero en la lista de movimientos.
            DB::table('movimientos_caja')->insert([
                'id' => (string) Str::uuid(),
                'sesion_id' => $id,
                'usuario_id' => $usuario->id,
                'tipo' => 'inicio',
                'monto' => $monto,
                'comentario' => $datos['comentario'] ?? null,
                'created_at' => $ahora->format('Y-m-d H:i:s.u'),
            ]);

            $this->auditoria->registrar($usuario, 'abrir', 'caja', 'sesion_caja', $id,
                ['monto_inicial' => (string) $monto]);

            $s = DB::table('sesiones_caja')->where('id', $id)->first();
            return response()->json($this->sesionOut($s), 201);
        });
    }

    /** POST /caja/cerrar — permiso «caja». */
    public function cerrar(Request $request)
    {
        $datos = $request->validate([
            'monto_declarado' => ['required', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string'],
        ]);

        $sesion = $this->sesionAbierta();
        if (! $sesion) {
            return response()->json(['detail' => 'No hay ninguna caja abierta.'], 400);
        }

        $usuario = $request->attributes->get('usuario');
        $declarado = (float) $datos['monto_declarado'];

        return DB::transaction(function () use ($sesion, $datos, $usuario, $declarado) {
            $ahora = CarbonImmutable::now();

            // **Se calcula ANTES de marcar el cierre.** `esperadoDeSesion`
            // corta la ventana en `cerrada_at`, así que con la sesión ya
            // cerrada esa ventana quedaría vacía y el esperado saldría sin el
            // efectivo del turno — un faltante igual a todo lo cobrado.
            $esperado = $this->esperadoDeSesion($sesion);

            DB::table('sesiones_caja')->where('id', $sesion->id)->update([
                'monto_esperado' => $esperado,
                'monto_declarado' => $declarado,
                'notas' => $datos['notas'] ?? null,
                'cerrada_at' => $ahora->format('Y-m-d H:i:s.u'),
            ]);

            DB::table('movimientos_caja')->insert([
                'id' => (string) Str::uuid(),
                'sesion_id' => $sesion->id,
                'usuario_id' => $usuario->id,
                'tipo' => 'cierre',
                'monto' => $declarado,
                'comentario' => $datos['notas'] ?? null,
                'created_at' => $ahora->format('Y-m-d H:i:s.u'),
            ]);

            // La DIFERENCIA se guarda calculada y no solo los dos montos: es
            // el número por el que alguien pregunta después —«¿cuánto faltó
            // ese día?»— y dejarlo derivar de dos strings en la auditoría
            // invita a que cada quien lo reste a su manera.
            $this->auditoria->registrar($usuario, 'cerrar', 'caja', 'sesion_caja', $sesion->id, [
                'monto_declarado' => (string) $declarado,
                'monto_esperado' => (string) $esperado,
                'diferencia' => (string) ($declarado - $esperado),
            ]);

            $s = DB::table('sesiones_caja')->where('id', $sesion->id)->first();
            return $this->sesionOut($s);
        });
    }

    /**
     * La caja abierta, si la hay.
     *
     * `abierta_at DESC, id DESC`: si por un fallo quedaran dos abiertas, manda
     * la más reciente. El desempate por id es obligatorio (§9.14).
     */
    /**
     * POST /caja/cambio-turno — cierra el turno actual y abre uno nuevo a
     * nombre de quien entra, con el efectivo contado como fondo inicial.
     *
     * El cajón no se vacía entremedio: el cierre de uno y la apertura del otro
     * son el mismo acto, y por eso van en una sola transacción.
     */
    public function cambioTurno(Request $request)
    {
        $datos = $request->validate([
            'usuario_id' => ['required', 'uuid'],
            'monto_declarado' => ['required', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string'],
        ]);

        $sesion = $this->sesionAbierta();
        if (! $sesion) {
            return response()->json(['detail' => 'No hay ninguna caja abierta.'], 400);
        }

        $entrante = DB::table('usuarios')->where('id', $datos['usuario_id'])
            ->whereIn('rol', ['veterinario', 'admin'])->first();
        if (! $entrante) {
            return response()->json(['detail' =>
                'El miembro del equipo que recibe la caja no existe.'], 404);
        }
        if (! $entrante->es_activo) {
            return response()->json(['detail' =>
                'Esa cuenta está inactiva y no puede recibir la caja.'], 400);
        }

        $saliente = DB::table('usuarios')->where('id', $sesion->usuario_id)->first();
        $actor = $request->attributes->get('usuario');
        $declarado = (float) $datos['monto_declarado'];
        $notas = $datos['notas'] ?? null;

        return DB::transaction(function () use (
            $sesion, $entrante, $saliente, $actor, $declarado, $notas
        ) {
            // Hora de la CLÍNICA, no UTC: decide a qué día de caja pertenece
            // el turno (§9.6).
            $ahora = CarbonImmutable::now();
            $ahoraStr = $ahora->format('Y-m-d H:i:s.u');
            $esperado = $this->esperadoDeSesion($sesion);

            DB::table('sesiones_caja')->where('id', $sesion->id)->update([
                'monto_esperado' => $esperado,
                'monto_declarado' => $declarado,
                'notas' => $notas,
                'cerrada_at' => $ahoraStr,
            ]);
            DB::table('movimientos_caja')->insert([
                'id' => (string) Str::uuid(),
                'sesion_id' => $sesion->id,
                'usuario_id' => $actor->id,
                'tipo' => 'cierre',
                'monto' => $declarado,
                'comentario' => $notas ?: ('Cambio de turno a ' . $entrante->nombre),
                'created_at' => $ahoraStr,
            ]);

            $nuevaId = (string) Str::uuid();
            DB::table('sesiones_caja')->insert([
                'id' => $nuevaId,
                'usuario_id' => $entrante->id,
                'fecha' => $ahora->format('Y-m-d'),
                'abierta_at' => $ahoraStr,
                'monto_inicial' => $declarado,
            ]);
            DB::table('movimientos_caja')->insert([
                'id' => (string) Str::uuid(),
                'sesion_id' => $nuevaId,
                'usuario_id' => $actor->id,
                'tipo' => 'inicio',
                'monto' => $declarado,
                'comentario' => 'Recibe el turno de ' . ($saliente->nombre ?? '—'),
                'created_at' => $ahoraStr,
            ]);

            $this->auditoria->registrar($actor, 'cambio_turno', 'caja', 'sesion_caja', $sesion->id, [
                'entra' => $entrante->nombre,
                'sale' => $saliente->nombre ?? null,
                'monto_declarado' => (string) $declarado,
            ]);

            return [
                'cerrada' => $this->sesionOut(DB::table('sesiones_caja')->where('id', $sesion->id)->first()),
                'abierta' => $this->sesionOut(DB::table('sesiones_caja')->where('id', $nuevaId)->first()),
            ];
        });
    }

    /**
     * POST /caja/movimientos — registra una salida de efectivo del cajón.
     *
     * `abono` se retiró a pedido de la clínica (22-ago-2026): el único
     * movimiento manual que queda es el egreso. Los abonos YA REGISTRADOS se
     * siguen leyendo y sumando (ver `esperadoDeSesion`) — dejarlos fuera del
     * cálculo descuadraría todos los turnos anteriores.
     */
    public function crearMovimiento(Request $request)
    {
        // La regla `in` va DENTRO del validate, y no como un `if` con su propio
        // `response()->json`, porque el 422 tiene que decir QUÉ CAMPO falló:
        // el frontend usa ese `loc` para pintar el error junto al input. Con el
        // `if` la respuesta era un texto suelto sin campo, mientras Python
        // señalaba `tipo` — lo encontró `modo_b_caja`. El mensaje se conserva
        // tal cual: es el que lee la persona, y está en español.
        $datos = $request->validate([
            'tipo' => ['required', 'string', 'in:egreso'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'comentario' => ['nullable', 'string'],
        ], [
            'tipo.in' => "Tipo inválido. El único movimiento manual es 'egreso'.",
        ]);

        $sesion = $this->sesionAbierta();
        if (! $sesion) {
            return response()->json(['detail' =>
                'Abre la caja antes de registrar movimientos.'], 400);
        }

        // Un egreso mayor al efectivo disponible dejaría el cajón en negativo:
        // o el monto está mal tipeado, o hay ventas en efectivo sin registrar.
        $disponible = $this->esperadoDeSesion($sesion);
        if ((float) $datos['monto'] > $disponible) {
            return response()->json(['detail' => sprintf(
                'El egreso supera el efectivo en caja ($%s). '
                . 'Revisa el monto o el efectivo declarado al abrir el turno.',
                number_format($disponible, 0, ',', ','),
            )], 400);
        }

        $actor = $request->attributes->get('usuario');
        $id = (string) Str::uuid();
        DB::table('movimientos_caja')->insert([
            'id' => $id,
            'sesion_id' => $sesion->id,
            'usuario_id' => $actor->id,
            'tipo' => $datos['tipo'],
            'monto' => (float) $datos['monto'],
            'comentario' => $datos['comentario'] ?? null,
            'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
        ]);

        $this->auditoria->registrar($actor, $datos['tipo'], 'caja', 'movimiento_caja', null, [
            'monto' => (string) (float) $datos['monto'],
            'comentario' => $datos['comentario'] ?? null,
        ]);

        $mov = DB::table('movimientos_caja')->where('id', $id)->first();
        return response()->json(
            $this->movimientoOut($mov, [$actor->id => $actor->nombre]), 201);
    }

    /**
     * DELETE /caja/movimientos/{id} — borra un apunte manual.
     *
     * El fondo inicial y el cierre no: son la estructura del turno, y
     * borrarlos dejaría una sesión sin fondo o sin cuadre.
     */
    public function eliminarMovimiento(Request $request, string $id)
    {
        $mov = DB::table('movimientos_caja')->where('id', $id)->first();
        if (! $mov) {
            return response()->json(['detail' => 'Movimiento no encontrado'], 404);
        }
        if (! in_array($mov->tipo, ['abono', 'egreso'], true)) {
            return response()->json(['detail' =>
                'El fondo inicial y el cierre no se pueden eliminar.'], 400);
        }
        $sesion = DB::table('sesiones_caja')->where('id', $mov->sesion_id)->first();
        if ($sesion && $sesion->cerrada_at !== null) {
            return response()->json(['detail' =>
                'La caja de ese movimiento ya está cerrada.'], 400);
        }

        $this->auditoria->registrar($request->attributes->get('usuario'),
            'eliminar', 'caja', 'movimiento_caja', $id, [
                'tipo' => $mov->tipo,
                'monto' => (string) (float) ($mov->monto ?? 0),
                'comentario' => $mov->comentario,
            ]);
        DB::table('movimientos_caja')->where('id', $id)->delete();

        return response()->noContent();
    }

    /** GET /caja/transacciones — los cobros del día, con cliente y mascota. */
    public function transaccionesDelDia(Request $request)
    {
        $dia = $request->query('fecha')
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->query('fecha'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $q = DB::table('transacciones as t')
            ->where('t.cuenta_en_caja', true)
            ->where('t.fecha', '>=', $dia->format('Y-m-d H:i:s'))
            ->where('t.fecha', '<', Fechas::sumarDias($dia, 1)->format('Y-m-d H:i:s'));

        if ($texto = trim((string) $request->query('q', ''))) {
            $like = '%' . $texto . '%';
            $q->where(fn ($w) => $w
                ->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('usuarios')
                    ->whereColumn('usuarios.id', 't.propietario_id')
                    ->where('usuarios.nombre', 'like', $like))
                ->orWhere('t.documento_id', 'like', $like)
                ->orWhere(DB::raw('CAST(t.numero AS CHAR)'), 'like', $like));
        }

        [$page, $limit, $total] = $this->pagina($request, $q);
        $filas = $q->select('t.*')
            ->orderBy('t.fecha', 'desc')->orderBy('t.id', 'desc')
            ->offset(($page - 1) * $limit)->limit($limit)->get();

        // Los nombres se resuelven en tres consultas y no una por fila: con 20
        // cobros por página serían 60 viajes a la base para pintar la tabla.
        $clientes = $this->nombresDe('usuarios', $filas->pluck('propietario_id'));
        $especialistas = $this->nombresDe('especialistas', $filas->pluck('especialista_id'));

        $mascotas = [];
        if ($filas->isNotEmpty()) {
            $porVenta = DB::table('ventas as v')
                ->join('mascotas as m', 'm.id', '=', 'v.mascota_id')
                ->whereIn('v.transaccion_id', $filas->pluck('id'))
                ->distinct()->select('v.transaccion_id', 'm.nombre')->get();
            foreach ($porVenta as $f) {
                $mascotas[$f->transaccion_id][] = $f->nombre;
            }
        }

        return [
            'items' => $filas->map(fn ($t) => [
                'id' => $t->id,
                'numero' => $t->numero === null ? null : (int) $t->numero,
                'fecha' => $this->hv->iso($t->fecha),
                'ingreso' => $this->cobrado($t),
                'tipo' => $this->tipoDePago($t),
                'cliente' => $clientes[$t->propietario_id] ?? '—',
                'mascota' => implode(', ', $mascotas[$t->id] ?? []) ?: '—',
                'asignado_a' => $especialistas[$t->especialista_id] ?? '—',
                'documento' => $t->documento_id ?: null,
                'pendiente' => (float) ($t->total_pendiente ?? 0),
                // Una venta devuelta en parte sigue contando acá (esa plata
                // entró) y la salida está como egreso; verlo marcado evita que
                // el cuadre parezca un error de suma.
                'estado_venta' => $t->estado_venta ?: 'confirmada',
            ])->values()->all(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_paginas' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /** GET /caja/egresos — en qué se fue la plata del cajón. */
    public function egresos(Request $request)
    {
        $q = DB::table('movimientos_caja as m')->where('m.tipo', 'egreso');
        if ($v = $request->query('desde')) {
            $q->where('m.created_at', '>=', $v . ' 00:00:00');
        }
        if ($v = $request->query('hasta')) {
            // `<` del día siguiente, como el `rango_del_dia()` de Python.
            // Acá el `format('Y-m-d …')` se queda con la fecha ya corrida. En
        // septiembre eso no cambia el día —01:00 sigue siendo el día
        // siguiente— así que hoy no rompe nada; se unifica igual para que el
        // borde salga de un solo sitio y no haya que volver a razonarlo.
            $q->where('m.created_at', '<', Fechas::sumarDias($v, 1)->format('Y-m-d 00:00:00'));
        }

        [$page, $limit, $total] = $this->pagina($request, $q);
        $filas = $q->select('m.*')
            ->orderBy('m.created_at', 'desc')->orderBy('m.id', 'desc')
            ->offset(($page - 1) * $limit)->limit($limit)->get();

        $nombres = $this->nombresDe('usuarios', $filas->pluck('usuario_id'));

        return [
            'items' => $filas->map(fn ($m) => $this->movimientoOut($m, $nombres))->values()->all(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_paginas' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /** GET /caja/sesiones — turnos ya cuadrados, con su diferencia. */
    public function sesiones(Request $request)
    {
        $q = DB::table('sesiones_caja as s');
        if ($v = $request->query('desde')) {
            $q->where('s.fecha', '>=', $v);
        }
        if ($v = $request->query('hasta')) {
            $q->where('s.fecha', '<=', $v);
        }

        [$page, $limit, $total] = $this->pagina($request, $q);
        $filas = $q->select('s.*')
            ->orderBy('s.abierta_at', 'desc')->orderBy('s.id', 'desc')
            ->offset(($page - 1) * $limit)->limit($limit)->get();

        return [
            'items' => $filas->map(fn ($s) => $this->sesionOut($s))->values()->all(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_paginas' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /** page/limit/total con el mismo recorte que `paginar()` de Python. */
    private function pagina(Request $request, $q): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min((int) $request->query('limit', 30), self::LIMITE_MAX));
        return [$page, $limit, (clone $q)->reorder()->count()];
    }

    /** Nombres por id, en una sola consulta. */
    private function nombresDe(string $tabla, $ids): array
    {
        $ids = $ids->filter()->unique()->values();
        return $ids->isEmpty() ? []
            : DB::table($tabla)->whereIn('id', $ids)->pluck('nombre', 'id')->all();
    }

    private function cobrado($t): float
    {
        $total = 0.0;
        foreach (self::MEDIOS as $col) {
            $total += (float) ($t->$col ?? 0);
        }
        return $total;
    }

    /**
     * La columna «Tipo» del listado. Una transacción puede repartirse entre
     * varios medios; en ese caso no hay un tipo único que mostrar.
     */
    private function tipoDePago($t): string
    {
        $usados = [];
        foreach (self::MEDIOS as $clave => $col) {
            if ((float) ($t->$col ?? 0) > 0) {
                $usados[] = self::ETIQUETA_MEDIO[$clave];
            }
        }
        if (! $usados) {
            return 'Sin pago';
        }
        return count($usados) === 1 ? $usados[0] : 'Mixto';
    }

    private function sesionAbierta()
    {
        return DB::table('sesiones_caja')
            ->whereNull('cerrada_at')
            ->orderBy('abierta_at', 'desc')->orderBy('id', 'desc')
            ->first();
    }

    private function sesionOut($s): array
    {
        $usuario = DB::table('usuarios')->where('id', $s->usuario_id)->first();
        $declarado = $s->monto_declarado === null ? null : (float) $s->monto_declarado;
        $esperado = $s->monto_esperado === null ? null : (float) $s->monto_esperado;
        return [
            'id' => $s->id,
            'usuario_id' => $s->usuario_id,
            'usuario_nombre' => $usuario->nombre ?? '—',
            'fecha' => substr($s->fecha, 0, 10),
            'abierta_at' => $this->hv->iso($s->abierta_at),
            'cerrada_at' => $this->hv->iso($s->cerrada_at),
            'abierta' => $s->cerrada_at === null,
            'monto_inicial' => (float) ($s->monto_inicial ?? 0),
            'monto_declarado' => $declarado,
            'monto_esperado' => $esperado,
            'diferencia' => ($declarado !== null && $esperado !== null)
                ? $declarado - $esperado : null,
            'notas' => $s->notas,
        ];
    }

    private function movimientoOut($m, $nombres): array
    {
        return [
            'id' => $m->id,
            'sesion_id' => $m->sesion_id,
            'tipo' => $m->tipo,
            'monto' => (float) ($m->monto ?? 0),
            'comentario' => $m->comentario,
            'usuario_nombre' => $nombres[$m->usuario_id] ?? '—',
            'created_at' => $this->hv->iso($m->created_at),
            // inicio y cierre son la estructura del turno, no apuntes sueltos:
            // borrarlos dejaría una sesión sin fondo o sin cuadre.
            'eliminable' => in_array($m->tipo, ['abono', 'egreso'], true),
        ];
    }

    /**
     * Lo que debería haber en el cajón: fondo inicial, más el efectivo cobrado
     * durante el turno, menos los egresos.
     *
     * Los abonos siguen sumando aunque ya no se puedan crear: los turnos
     * anteriores al retiro de esa acción tienen movimientos de ese tipo, y
     * sacarlos cambiaría hacia atrás el efectivo esperado de cajas ya cerradas
     * cuadradas.
     */
    private function esperadoDeSesion($s): float
    {
        // Con `.u`: el corte es `fecha < hasta`, así que truncar el «ahora» al
        // segundo deja fuera una venta cobrada en ese mismo segundo — y el
        // esperado del cajón saldría corto justo mientras se está mirando.
        $hasta = $s->cerrada_at ?: CarbonImmutable::now()->format('Y-m-d H:i:s.u');
        $movs = DB::table('movimientos_caja')->where('sesion_id', $s->id)->get();
        $abonos = $movs->where('tipo', 'abono')->sum(fn ($m) => (float) ($m->monto ?? 0));
        $egresos = $movs->where('tipo', 'egreso')->sum(fn ($m) => (float) ($m->monto ?? 0));

        return (float) ($s->monto_inicial ?? 0) + $abonos - $egresos
            + $this->efectivoDeVentas($s->abierta_at, $hasta);
    }

    /** Efectivo cobrado en ventas dentro de la ventana: lo único que cuadra. */
    private function efectivoDeVentas(?string $desde, string $hasta): float
    {
        if ($desde === null) {
            return 0.0;
        }
        return (float) DB::table('transacciones')
            ->where('cuenta_en_caja', true)
            ->where('fecha', '>=', $desde)->where('fecha', '<', $hasta)
            ->sum(DB::raw('COALESCE(pago_efectivo, 0)'));
    }

    /** Ingreso por productos y por servicios, según el tipo de cada línea. */
    private function resumenVentas(string $desde, string $hasta): array
    {
        $filas = DB::table('ventas as v')
            ->join('transacciones as t', 'v.transaccion_id', '=', 't.id')
            ->where('t.cuenta_en_caja', true)
            ->where('t.fecha', '>=', $desde)->where('t.fecha', '<', $hasta)
            ->groupBy('v.tipo')
            ->select('v.tipo',
                DB::raw('COALESCE(SUM(v.precio_venta * v.cantidad), 0) as bruto'),
                DB::raw('COALESCE(SUM(v.descuento), 0) as descuento'),
                DB::raw('COUNT(v.id) as n'))
            ->get();

        $vacio = ['ingreso_bruto' => 0.0, 'descuentos' => 0.0, 'ingreso_neto' => 0.0, 'lineas' => 0];
        $resumen = ['productos' => $vacio, 'servicios' => $vacio, 'otros' => $vacio];
        $porTipo = [];

        foreach ($filas as $f) {
            $grupo = in_array($f->tipo, HistorialVentas::TIPOS_PRODUCTO, true) ? 'productos'
                : (in_array($f->tipo, HistorialVentas::TIPOS_SERVICIO, true) ? 'servicios' : 'otros');
            $bruto = (float) $f->bruto;
            $desc = (float) $f->descuento;
            $resumen[$grupo]['ingreso_bruto'] += $bruto;
            $resumen[$grupo]['descuentos'] += $desc;
            $resumen[$grupo]['ingreso_neto'] += $bruto - $desc;
            $resumen[$grupo]['lineas'] += (int) $f->n;
            $porTipo[] = [
                'tipo' => $f->tipo ?: 'Sin tipo',
                'grupo' => $grupo,
                'ingreso_bruto' => $bruto,
                'lineas' => (int) $f->n,
            ];
        }

        usort($porTipo, fn ($a, $b) => $b['ingreso_bruto'] <=> $a['ingreso_bruto']);
        $resumen['por_tipo'] = $porTipo;
        return $resumen;
    }

    private function resumenTransacciones(string $desde, string $hasta): array
    {
        $sel = [];
        foreach (self::MEDIOS as $clave => $col) {
            $sel[] = DB::raw("COALESCE(SUM($col), 0) as $clave");
        }
        $sel[] = DB::raw('COALESCE(SUM(total_pendiente), 0) as pendiente');
        $sel[] = DB::raw('COUNT(id) as n');

        $f = DB::table('transacciones')
            ->where('cuenta_en_caja', true)
            ->where('fecha', '>=', $desde)->where('fecha', '<', $hasta)
            ->select($sel)->first();

        $montos = [];
        foreach (array_keys(self::MEDIOS) as $clave) {
            $montos[$clave] = (float) $f->$clave;
        }

        return array_merge($montos, [
            'total' => array_sum($montos),
            'pendiente' => (float) $f->pendiente,
            'n_transacciones' => (int) $f->n,
        ]);
    }
}
