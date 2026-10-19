<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Services\BloqueosAgenda;
use App\Services\Fechas;
use App\Services\SlotsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Lo que la Agenda dibuja además de las citas (oct-2026): cuándo trabaja cada
 * especialista y qué horas están bloqueadas. Con eso la vista de día pinta en
 * gris lo que queda fuera de horario y rayado lo bloqueado, y recepción ve de
 * un vistazo dónde cabe una hora.
 *
 * Todo con permiso «agenda»: es la misma pantalla.
 */
class AgendaController extends Controller
{
    /** Igual que /citas/agenda: el mes completo con sus días de relleno. */
    private const MAX_DIAS = 62;

    public function __construct(
        private SlotsService $slots,
        private BloqueosAgenda $bloqueos,
        private Auditoria $auditoria,
    ) {
    }

    /**
     * GET /agenda/disponibilidad?fecha=YYYY-MM-DD&hasta=YYYY-MM-DD
     *
     * `ventanas[especialista][día]` = [{desde, ultimo_inicio}]: `ultimo_inicio`
     * es la última hora a la que se puede EMPEZAR (la regla de los Horarios),
     * no el fin de la jornada. Solo especialistas activos.
     */
    public function disponibilidad(Request $request)
    {
        try {
            $dia = CarbonImmutable::createFromFormat('Y-m-d', (string) $request->query('fecha'))->startOfDay();
            $ultimo = $request->query('hasta')
                ? CarbonImmutable::createFromFormat('Y-m-d', (string) $request->query('hasta'))->startOfDay()
                : $dia;
        } catch (\Throwable $e) {
            return response()->json(['detail' => 'Fecha inválida (YYYY-MM-DD)'], 400);
        }
        $fin = Fechas::sumarDias($ultimo, 1);
        if ($fin->lessThanOrEqualTo($dia) || Fechas::diasEntre($dia, $fin) > self::MAX_DIAS) {
            return response()->json(['detail' => 'El rango debe ser de 1 a ' . self::MAX_DIAS . ' días.'], 400);
        }

        $especialistas = DB::table('especialistas')->where('activo', 1)
            ->orderBy('orden')->orderBy('nombre')->orderBy('id')->get();

        $ventanas = [];
        foreach ($especialistas as $e) {
            for ($d = $dia; $d < $fin; $d = $d->addDay()) {
                $v = $this->slots->ventanasDelDia($e, null, $d);
                if ($v) {
                    usort($v, fn ($a, $b) => strcmp($a[0], $b[0]));
                    $ventanas[$e->id][$d->format('Y-m-d')] = array_map(
                        fn ($r) => ['desde' => $r[0], 'ultimo_inicio' => $r[1]], $v);
                }
            }
        }

        // Falla abierto, como en SlotsService: una base a la que todavía no se le
        // corrió base/cambios/ igual tiene que mostrar los horarios.
        try {
            $bloqueos = $this->bloqueos->entre($dia, $fin);
        } catch (\Throwable $e) {
            Log::warning('Bloqueos de la agenda (disponibilidad): ' . $e->getMessage());
            $bloqueos = collect();
        }
        $nombres = $this->nombres($bloqueos->pluck('creado_por_id')->filter()->unique()->all());

        return response()->json([
            // `(object)`: sin ventanas tiene que salir {} y no [], para que el
            // panel lo lea siempre como mapa.
            'ventanas' => (object) $ventanas,
            'bloqueos' => $bloqueos->map(fn ($b) => $this->bloqueos->salida($b, $nombres))->values(),
        ]);
    }

    /**
     * POST /agenda/bloqueos  {especialista_id|null, inicio, fin, motivo}
     *
     * Las citas que ya estaban en ese horario NO se cancelan: se informa
     * cuántas son (`citas_en_conflicto`) para que recepción las mueva o avise.
     */
    public function crearBloqueo(Request $request)
    {
        $datos = $request->validate([
            'especialista_id' => 'nullable|string',
            'inicio' => 'required|date_format:Y-m-d\TH:i',
            'fin' => 'required|date_format:Y-m-d\TH:i',
            'motivo' => 'required|string|min:3|max:150',
        ], [
            'inicio.date_format' => 'Indica la fecha y hora de inicio.',
            'fin.date_format' => 'Indica la fecha y hora de término.',
            'motivo.min' => 'Escribe el motivo (al menos 3 letras).',
            'motivo.required' => 'Escribe el motivo del bloqueo.',
        ]);

        $inicio = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $datos['inicio'])->setSecond(0);
        $fin = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $datos['fin'])->setSecond(0);
        if ($fin->lessThanOrEqualTo($inicio)) {
            return response()->json(['detail' => 'El bloqueo tiene que terminar después de empezar.'], 400);
        }
        if (Fechas::diasEntre($inicio, $fin) > BloqueosAgenda::MAX_DIAS) {
            return response()->json(['detail' => 'Un bloqueo puede durar hasta ' . BloqueosAgenda::MAX_DIAS . ' días.'], 400);
        }

        $espId = $datos['especialista_id'] ?? null;
        $esp = null;
        if ($espId !== null) {
            $esp = DB::table('especialistas')->where('id', $espId)->first();
            if (! $esp) {
                return response()->json(['detail' => 'Especialista no encontrado'], 404);
            }
        }

        $usuario = $request->attributes->get('usuario');
        $id = (string) Str::uuid();
        $motivo = trim($datos['motivo']);
        DB::transaction(function () use ($id, $espId, $esp, $inicio, $fin, $motivo, $usuario) {
            DB::table('bloqueos_agenda')->insert([
                'id' => $id, 'especialista_id' => $espId,
                'inicio' => $inicio->format('Y-m-d H:i:s'), 'fin' => $fin->format('Y-m-d H:i:s'),
                'motivo' => $motivo, 'creado_por_id' => $usuario->id ?? null,
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
            $this->auditoria->registrar($usuario, 'crear', 'agenda', 'bloqueo_agenda', $id, [
                'especialista' => $esp->nombre ?? 'Toda la clínica',
                'inicio' => $inicio->format('Y-m-d H:i'), 'fin' => $fin->format('Y-m-d H:i'),
                'motivo' => $motivo,
            ]);
        });

        $b = DB::table('bloqueos_agenda')->where('id', $id)->first();

        return response()->json([
            ...$this->bloqueos->salida($b, $this->nombres([$b->creado_por_id])),
            'citas_en_conflicto' => $this->bloqueos->citasQueChocan($inicio, $fin, $espId),
        ], 201);
    }

    /** DELETE /agenda/bloqueos/{id}: lo anula; la fila queda. */
    public function anularBloqueo(Request $request, string $bloqueoId)
    {
        $b = DB::table('bloqueos_agenda')->where('id', $bloqueoId)->first();
        if (! $b) {
            return response()->json(['detail' => 'Bloqueo no encontrado'], 404);
        }
        if ($b->anulado_at) {
            return response()->json(['detail' => 'Ese bloqueo ya se había quitado.'], 409);
        }

        $usuario = $request->attributes->get('usuario');
        DB::transaction(function () use ($b, $usuario) {
            DB::table('bloqueos_agenda')->where('id', $b->id)->update([
                'anulado_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                'anulado_por_id' => $usuario->id ?? null,
            ]);
            $this->auditoria->registrar($usuario, 'anular', 'agenda', 'bloqueo_agenda', $b->id, [
                'inicio' => CarbonImmutable::parse($b->inicio)->format('Y-m-d H:i'),
                'fin' => CarbonImmutable::parse($b->fin)->format('Y-m-d H:i'),
                'motivo' => $b->motivo,
            ]);
        });

        return response()->noContent();
    }

    /** @return array<string, string> id de usuario => nombre */
    private function nombres(array $ids): array
    {
        $ids = array_values(array_filter($ids));
        return $ids ? DB::table('usuarios')->whereIn('id', $ids)->pluck('nombre', 'id')->all() : [];
    }
}
