<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Services\Esquema;
use App\Services\Permisos;
use App\Services\RecordatoriosPreventivos;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Fechas;

/**
 * Medicina preventiva: qué protocolo le corresponde a cada mascota y si está
 * al día.
 *
 * `protocolos_preventivos` es la REGLA (especie, frecuencia, ventana de edad);
 * `protocolo_aplicaciones` es el historial real de dosis puestas. **El estado
 * no se guarda en ninguna tabla**: se deriva acá, igual que el `vencido` de un
 * recordatorio.
 *
 * Ojo con los permisos, que son dos distintos: las REGLAS se configuran con
 * `config_protocolos`, pero el estado y las aplicaciones de una mascota van con
 * `personas` — es trabajo clínico, no de configuración.
 */
class ProtocolosController extends Controller
{
    private const LIMITE_MAX = 20;

    /**
     * Ventana de «próximo» antes del vencimiento real. Mismo umbral que los
     * «próximos 30 días» del resumen de recordatorios, para que el badge de la
     * ficha y la cola de trabajo cuenten la misma historia.
     */
    private const DIAS_PROXIMO = 30;

    public function __construct(
        private Auditoria $auditoria,
        private RecordatoriosPreventivos $recordatorios,
    ) {
    }

    // ── Reglas (configuración) ────────────────────────────────────────────

    /** GET /protocolos */
    public function listar(Request $request)
    {
        $q = DB::table('protocolos_preventivos')->where('activo', true);
        if ($busca = trim((string) $request->query('q', ''))) {
            $q->where('nombre', 'like', '%' . $this->escaparLike($busca) . '%');
        }
        $q->orderBy('orden')->orderBy('nombre')->orderBy('id');

        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min((int) $request->query('limit', self::LIMITE_MAX), self::LIMITE_MAX));
        $total = (clone $q)->reorder()->count();
        $filas = $q->offset(($page - 1) * $limit)->limit($limit)->get();

        return [
            'items' => $filas->map(fn ($p) => $this->protocoloOut($p))->values(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_paginas' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /** POST /protocolos */
    public function crear(Request $request)
    {
        $datos = $request->validate($this->reglas(true));
        $id = (string) Str::uuid();

        DB::transaction(function () use ($request, $datos, $id) {
            DB::table('protocolos_preventivos')->insert([
                'id' => $id,
                'nombre' => $datos['nombre'],
                'categoria' => $datos['categoria'] ?? null,
                'especie' => $datos['especie'] ?? null,
                'frecuencia_meses' => $datos['frecuencia_meses'],
                'edad_inicio_meses' => $datos['edad_inicio_meses'] ?? null,
                'edad_fin_meses' => $datos['edad_fin_meses'] ?? null,
                'descripcion' => $datos['descripcion'] ?? null,
                'activo' => $datos['activo'] ?? true,
                'orden' => $datos['orden'] ?? 0,
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ] + (Esquema::columna('protocolos_preventivos', 'inicial') ? ['inicial' => $datos['inicial'] ?? false] : []));
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'crear', 'config_protocolos', 'protocolo', null,
                ['nombre' => $datos['nombre']]);
        });

        return response()->json($this->protocoloOut(
            DB::table('protocolos_preventivos')->where('id', $id)->first()), 201);
    }

    /** PATCH /protocolos/{id} */
    public function actualizar(Request $request, string $protocoloId)
    {
        $p = DB::table('protocolos_preventivos')->where('id', $protocoloId)->first();
        if (! $p) {
            return response()->json(['detail' => 'Protocolo no encontrado'], 404);
        }

        $reglas = $this->reglas(false);
        $datos = $request->validate($reglas);

        $cambios = [];
        foreach (array_keys($reglas) as $campo) {
            if ($request->exists($campo)) {
                $cambios[$campo] = $datos[$campo] ?? null;
            }
        }
        if (array_key_exists('inicial', $cambios)) {
            if (Esquema::columna('protocolos_preventivos', 'inicial')) {
                $cambios['inicial'] = (bool) $cambios['inicial'];
            } else {
                unset($cambios['inicial']);
            }
        }

        DB::transaction(function () use ($request, $cambios, $protocoloId) {
            if ($cambios) {
                DB::table('protocolos_preventivos')->where('id', $protocoloId)->update($cambios);
            }
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'editar', 'config_protocolos', 'protocolo', $protocoloId, $cambios);
        });

        return $this->protocoloOut(
            DB::table('protocolos_preventivos')->where('id', $protocoloId)->first());
    }

    /**
     * DELETE /protocolos/{id} — **desactiva, no borra**: sus aplicaciones son
     * el historial de vacunación real de cada paciente.
     */
    public function eliminar(Request $request, string $protocoloId)
    {
        $p = DB::table('protocolos_preventivos')->where('id', $protocoloId)->first();
        if (! $p) {
            return response()->json(['detail' => 'Protocolo no encontrado'], 404);
        }

        DB::transaction(function () use ($request, $p) {
            DB::table('protocolos_preventivos')->where('id', $p->id)->update(['activo' => false]);
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'eliminar', 'config_protocolos', 'protocolo', $p->id, ['nombre' => $p->nombre]);
        });

        return response()->noContent();
    }

    // ── Estado y aplicaciones por mascota ─────────────────────────────────

    /** GET /protocolos/mascota/{id}/estado */
    public function estadoPorMascota(string $mascotaId)
    {
        $mascota = DB::table('mascotas')->where('id', $mascotaId)->first();
        if (! $mascota) {
            return response()->json(['detail' => 'Mascota no encontrada'], 404);
        }

        return $this->estadoCalculado($mascota);
    }

    /**
     * GET /mascotas/{id}/preventivo — las vacunas y desparasitaciones que ve el
     * TUTOR en el portal (pestaña Vacunas de la ficha).
     *
     * Mismas dos audiencias que la foto de la mascota: el dueño la suya, el
     * staff con `personas` cualquiera. El estado sale del MISMO cálculo que el
     * panel (`estadoCalculado`), para que la ficha de la clínica y la del tutor
     * no digan cosas distintas de la misma vacuna.
     *
     * Lo que NO sale: `notas` de cada dosis y quién la registró. Son anotaciones
     * del equipo —lote, observaciones, «se resistió»—, escritas sin pensar en
     * que las lea el tutor.
     */
    public function delTutor(Request $request, string $mascotaId)
    {
        $mascota = DB::table('mascotas')->where('id', $mascotaId)->first();
        if (! $mascota) {
            return response()->json(['detail' => 'Mascota no encontrada'], 404);
        }
        $u = $request->attributes->get('usuario');
        if ($mascota->usuario_id !== $u->id && ! Permisos::tiene($u, 'personas')) {
            return response()->json(['detail' => 'Sin permiso'], 403);
        }

        // El historial es TODO lo aplicado, también de protocolos que hoy ya no
        // corresponden (inactivos, o fuera de la ventana de edad): una dosis
        // puesta sigue siendo un hecho de su carnet.
        $aplicaciones = DB::table('protocolo_aplicaciones as a')
            ->join('protocolos_preventivos as p', 'p.id', '=', 'a.protocolo_id')
            ->where('a.mascota_id', $mascotaId)
            ->orderBy('a.fecha_aplicacion', 'desc')->orderBy('a.id', 'desc')
            ->get(['a.id', 'a.protocolo_id', 'p.nombre', 'p.categoria', 'a.fecha_aplicacion', 'a.proxima_fecha'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'protocolo_id' => $a->protocolo_id,
                'nombre' => $a->nombre,
                'categoria' => $a->categoria,
                'fecha_aplicacion' => substr((string) $a->fecha_aplicacion, 0, 10),
                'proxima_fecha' => $a->proxima_fecha ? substr((string) $a->proxima_fecha, 0, 10) : null,
            ])->values();

        return [
            'estado' => $this->estadoCalculado($mascota),
            'aplicaciones' => $aplicaciones,
        ];
    }

    /** Qué protocolos le corresponden a la mascota y en qué estado está cada uno. */
    private function estadoCalculado($mascota)
    {
        $mascotaId = $mascota->id;
        $hoy = CarbonImmutable::now()->startOfDay();
        $edad = $this->edadMeses($mascota, $hoy);

        $protocolos = DB::table('protocolos_preventivos')->where('activo', true)
            ->orderBy('orden')->orderBy('nombre')->orderBy('id')->get()
            ->filter(fn ($p) => $this->aplicaA($p, $mascota, $edad))
            ->values();

        if ($protocolos->isEmpty()) {
            return [];
        }

        // La ÚLTIMA aplicación de cada protocolo: se ordena descendente y se
        // queda la primera que aparece de cada uno.
        $ultimas = [];
        $filas = DB::table('protocolo_aplicaciones')
            ->where('mascota_id', $mascotaId)
            ->whereIn('protocolo_id', $protocolos->pluck('id'))
            ->orderBy('fecha_aplicacion', 'desc')->orderBy('id', 'desc')
            ->get();
        foreach ($filas as $f) {
            if (! isset($ultimas[$f->protocolo_id])) {
                $ultimas[$f->protocolo_id] = $f;
            }
        }

        return $protocolos->map(function ($p) use ($ultimas, $hoy) {
            $u = $ultimas[$p->id] ?? null;
            $proxima = $u && $u->proxima_fecha ? substr((string) $u->proxima_fecha, 0, 10) : null;

            return [
                'protocolo_id' => $p->id,
                'nombre' => $p->nombre,
                'categoria' => $p->categoria,
                'frecuencia_meses' => (int) $p->frecuencia_meses,
                'ultima_aplicacion' => $u ? substr((string) $u->fecha_aplicacion, 0, 10) : null,
                'proxima_fecha' => $proxima,
                'estado' => $this->estadoDe($proxima, $hoy),
            ];
        })->values();
    }

    /** POST /protocolos/mascota/{id}/aplicaciones — registrar una dosis puesta. */
    public function registrarAplicacion(Request $request, string $mascotaId)
    {
        $datos = $request->validate([
            'protocolo_id' => 'required|uuid',
            'fecha_aplicacion' => 'required|date',
            'nota_id' => 'nullable|uuid',
            'notas' => 'nullable|string',
        ]);

        $mascota = DB::table('mascotas')->where('id', $mascotaId)->first();
        if (! $mascota) {
            return response()->json(['detail' => 'Mascota no encontrada'], 404);
        }
        $protocolo = DB::table('protocolos_preventivos')
            ->where('id', $datos['protocolo_id'])->first();
        if (! $protocolo) {
            return response()->json(['detail' => 'Protocolo no encontrado'], 404);
        }

        $fecha = substr($datos['fecha_aplicacion'], 0, 10);
        // La próxima fecha se CALCULA Y SE GUARDA ahora, no se recalcula al
        // vuelo: si mañana cambia la frecuencia del protocolo, el historial ya
        // aplicado no puede reescribirse solo.
        $proxima = $this->sumarMeses($fecha, (int) $protocolo->frecuencia_meses);

        $id = (string) Str::uuid();
        $actor = $request->attributes->get('usuario');

        DB::transaction(function () use ($datos, $id, $mascota, $protocolo, $fecha, $proxima, $actor) {
            DB::table('protocolo_aplicaciones')->insert([
                'id' => $id,
                'protocolo_id' => $protocolo->id,
                'mascota_id' => $mascota->id,
                'fecha_aplicacion' => $fecha,
                'proxima_fecha' => $proxima,
                'nota_id' => $datos['nota_id'] ?? null,
                'registrado_por_id' => $actor->id,
                'notas' => $this->vacioANull($datos['notas'] ?? null),
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);

            // La dosis puesta genera —o corrige— su propio recordatorio, igual
            // que el «próximo control» de una nota clínica.
            $this->recordatorios->sincronizarDesdeAplicacion(
                (object) ['proxima_fecha' => $proxima, 'registrado_por_id' => $actor->id],
                $protocolo, $mascota);

            $this->auditoria->registrar($actor, 'crear', 'personas', 'protocolo_aplicacion', $id, [
                'protocolo' => $protocolo->nombre,
                'mascota' => $mascota->nombre,
                'fecha' => $fecha,
            ]);
        });

        $ap = DB::table('protocolo_aplicaciones')->where('id', $id)->first();

        // `nota_id` y `registrado_por_id` SÍ se guardan pero NO se devuelven:
        // `AplicacionResponse` no los declara. Quién registró la dosis es dato
        // interno —vive en la auditoría—, y devolverlo de más es un cambio de
        // contrato aunque parezca inocente.
        return response()->json([
            'id' => $ap->id,
            'protocolo_id' => $ap->protocolo_id,
            'mascota_id' => $ap->mascota_id,
            'fecha_aplicacion' => substr((string) $ap->fecha_aplicacion, 0, 10),
            'proxima_fecha' => $ap->proxima_fecha ? substr((string) $ap->proxima_fecha, 0, 10) : null,
            'notas' => $ap->notas,
            'created_at' => $this->iso($ap->created_at),
        ], 201);
    }

    // ── derivaciones ──────────────────────────────────────────────────────

    /**
     * Suma meses a una fecha **sin librería de fechas**, igual que Python.
     *
     * `min(día, últimoDelMes)` es lo que evita el día inválido: 31 de enero +
     * 1 mes da 28 (o 29) de febrero, no un 31 que no existe.
     */
    private function sumarMeses(string $fecha, int $meses): string
    {
        $d = CarbonImmutable::parse($fecha);
        $total = $d->month - 1 + $meses;
        $anio = $d->year + intdiv($total, 12);
        $mes = $total % 12 + 1;
        $dia = min($d->day, (int) CarbonImmutable::create($anio, $mes, 1)->daysInMonth);

        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }

    private function edadMeses($mascota, CarbonImmutable $hoy): ?int
    {
        if (empty($mascota->fecha_nacimiento)) {
            return null;
        }
        $fn = CarbonImmutable::parse(substr((string) $mascota->fecha_nacimiento, 0, 10));
        $meses = ($hoy->year - $fn->year) * 12 + ($hoy->month - $fn->month);
        if ($hoy->day < $fn->day) {
            $meses--;
        }

        return max(0, $meses);
    }

    private function aplicaA($p, $mascota, ?int $edadMeses): bool
    {
        if ($p->especie && $mascota->especie
            && mb_strtolower($p->especie) !== mb_strtolower($mascota->especie)) {
            return false;
        }
        // **Sin fecha de nacimiento el protocolo se INCLUYE igual**, en vez de
        // ocultarlo por un dato que falta. Es frecuente en las mascotas
        // importadas del legado, y esconderles la vacuna sería peor que
        // mostrarla de más.
        if ($edadMeses === null) {
            return true;
        }
        if ($p->edad_inicio_meses !== null && $edadMeses < (int) $p->edad_inicio_meses) {
            return false;
        }
        // Profilaxis inicial (cachorros/gatitos, como en ACVet): sin edad
        // máxima escrita, la ventana termina al año. Pasado eso no aplica ni
        // se sigue repitiendo —la dosis de refuerzo es otro protocolo—.
        $fin = $p->edad_fin_meses ?? (! empty($p->inicial) ? 12 : null);
        if ($fin !== null && $edadMeses > (int) $fin) {
            return false;
        }

        return true;
    }

    private function estadoDe(?string $proxima, CarbonImmutable $hoy): string
    {
        if ($proxima === null) {
            return 'pendiente';
        }
        $f = CarbonImmutable::parse($proxima);
        if ($f->lt($hoy)) {
            return 'vencido';
        }

        // Días de calendario (App\Services\Fechas): justo en el borde de
        // DIAS_PROXIMO, la hora perdida del cambio de horario adelantaba la
        // clasificación un día.
        return Fechas::diasEntre($hoy, $f) <= self::DIAS_PROXIMO ? 'proximo' : 'al_dia';
    }

    private function reglas(bool $creando): array
    {
        $req = $creando ? 'required' : 'nullable';

        return [
            'nombre' => "{$req}|string|min:2|max:255",
            'categoria' => 'nullable|string|max:80',
            'especie' => 'nullable|string|max:50',
            'frecuencia_meses' => "{$req}|integer|gt:0",
            'edad_inicio_meses' => 'nullable|integer|min:0',
            'edad_fin_meses' => 'nullable|integer|min:0',
            'descripcion' => 'nullable|string',
            'activo' => 'nullable|boolean',
            'orden' => 'nullable|integer',
            'inicial' => 'nullable|boolean',
        ];
    }

    private function protocoloOut($p): array
    {
        return [
            'nombre' => $p->nombre,
            'categoria' => $p->categoria,
            'especie' => $p->especie,
            'frecuencia_meses' => (int) $p->frecuencia_meses,
            'edad_inicio_meses' => $p->edad_inicio_meses === null ? null : (int) $p->edad_inicio_meses,
            'edad_fin_meses' => $p->edad_fin_meses === null ? null : (int) $p->edad_fin_meses,
            'descripcion' => $p->descripcion,
            'activo' => (bool) $p->activo,
            'orden' => (int) $p->orden,
        ] + (property_exists($p, 'inicial') ? ['inicial' => (bool) $p->inicial] : []) + [
            'id' => $p->id,
            'created_at' => $this->iso($p->created_at),
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
