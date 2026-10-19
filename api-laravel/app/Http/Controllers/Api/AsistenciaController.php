<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Asistencia;
use App\Services\Auditoria;
use App\Services\Fechas;
use App\Services\Permisos;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Control de asistencia del equipo (punto 1 de la lista de la clínica). Port de
 * `backend/app/routes/asistencia.py` + `asistencia_service.py`.
 *
 * DOS NIVELES DE ACCESO, Y LA ASIMETRÍA ES DELIBERADA
 *
 * Marcar lo propio NO pide permiso de área (`auth:staff`): cualquiera del staff
 * ficha su entrada y su salida. Exigirlo dejaría sin poder fichar justo a quien
 * no lo tiene. Ver las horas de OTROS —`/resumen`, el DELETE— sí lo pide
 * (`auth:asistencia`): son datos que alimentan un sueldo. Y `/marcar` mira el
 * permiso DENTRO del cuerpo para el caso de corregir a otra persona.
 *
 * EL KIOSCO es aparte: `/roster` y `/marcar-kiosco` no llevan JWT sino el token
 * de dispositivo (`X-Fichaje-Token`, middleware `fichaje`), porque el tablet
 * compartido de recepción no tiene sesión. Ver `App\Http\Middleware\DispositivoFichaje`.
 */
class AsistenciaController extends Controller
{
    /** Roles que fichan. Una peluquera o recepcionista es `veterinario` con
     *  `permisos` recortados; un `dueño` nunca es staff. */
    private const ROLES_STAFF = ['veterinario', 'admin'];

    public function __construct(private Auditoria $auditoria)
    {
    }

    /** POST /asistencia/marcar — marca la entrada o la salida. Sin `usuario_id`, la propia. */
    public function marcar(Request $request)
    {
        $datos = $request->validate([
            // Array y no string: la barra de la alternancia del regex chocaría
            // con el separador de reglas de Laravel. Es el port de
            // `Field(pattern="^(entrada|salida)$")` — mismatch → 422.
            'tipo' => ['required', 'regex:/^(entrada|salida)$/'],
            'nota' => 'nullable|string|max:300',
            // Solo para corregir a otra persona; exige el permiso de área.
            'usuario_id' => 'nullable|uuid',
            // Idem: una corrección casi siempre es de un momento pasado.
            'momento' => 'nullable|date',
        ]);

        $actor = $request->attributes->get('usuario');
        $ajeno = ! empty($datos['usuario_id']) && $datos['usuario_id'] !== $actor->id;
        $corrigeHora = ! empty($datos['momento']);

        if ($ajeno || $corrigeHora) {
            // Registrar por otro, o con una hora que no es «ahora», es una
            // CORRECCIÓN: cambia horas que alguien va a cobrar.
            if (! Permisos::tiene($actor, 'asistencia')) {
                return response()->json(
                    ['detail' => 'No puedes registrar marcas de otra persona ni con otra hora.'], 403);
            }
        }

        $objetivo = $datos['usuario_id'] ?? $actor->id;
        if ($ajeno && ! DB::table('usuarios')->where('id', $objetivo)->exists()) {
            return response()->json(['detail' => 'Esa persona no existe.'], 404);
        }

        // `now()` local (la app corre en America/Santiago): la marca se agrupa
        // por día de la clínica, NO en UTC (§9.6).
        $momento = ($datos['momento'] ?? null) !== null
            ? CarbonImmutable::parse($datos['momento'])->format('Y-m-d H:i:s.u')
            : CarbonImmutable::now()->format('Y-m-d H:i:s.u');

        $id = (string) Str::uuid();

        DB::transaction(function () use ($datos, $id, $objetivo, $momento, $actor, $ajeno, $corrigeHora) {
            DB::table('marcajes_asistencia')->insert([
                'id' => $id,
                'usuario_id' => $objetivo,
                'tipo' => $datos['tipo'],
                'momento' => $momento,
                'nota' => $datos['nota'] ?? null,
                // NULL = lo marcó la propia persona. Con valor, es una corrección.
                'registrado_por_id' => ($ajeno || $corrigeHora) ? $actor->id : null,
            ]);
            if ($ajeno || $corrigeHora) {
                // DENTRO de la transacción, como el resto del port: si el
                // insert falla, el log no queda describiendo algo que no pasó.
                $this->auditoria->registrar($actor, 'crear', 'asistencia', 'marcaje', null, [
                    'de' => $objetivo,
                    'tipo' => $datos['tipo'],
                    'momento' => $this->iso($momento),
                ]);
            }
        });

        return $this->unoOut($id);
    }

    /** GET /asistencia/mi-estado — qué ofrecerle al botón. */
    public function miEstado(Request $request)
    {
        return ['estado' => Asistencia::estadoActual($request->attributes->get('usuario')->id)];
    }

    /** GET /asistencia/mias — las marcas propias. No pide permiso: son de quien pregunta. */
    public function mias(Request $request)
    {
        $request->validate(['desde' => 'nullable|date', 'hasta' => 'nullable|date']);

        $hasta = $request->query('hasta')
            ? substr((string) $request->query('hasta'), 0, 10)
            : CarbonImmutable::now()->format('Y-m-d');
        $desde = $request->query('desde')
            ? substr((string) $request->query('desde'), 0, 10)
            : Fechas::sumarDias($hasta, -30)->format('Y-m-d');

        $actor = $request->attributes->get('usuario');
        $marcas = DB::table('marcajes_asistencia')
            ->where('usuario_id', $actor->id)
            ->where('momento', '>=', $desde . ' 00:00:00')
            ->where('momento', '<=', $hasta . ' 23:59:59.999999')
            ->orderByDesc('momento')->orderByDesc('id')   // §9.14
            ->get();

        return [
            'marcas' => $marcas->map(fn ($m) => $this->out($m))->values(),
            'resumen' => Asistencia::resumen($desde, $hasta, $actor->id),
        ];
    }

    /** GET /asistencia/resumen — horas por persona en el rango (permiso `asistencia`). */
    public function resumen(Request $request)
    {
        $datos = $request->validate(['desde' => 'required|date', 'hasta' => 'required|date']);
        $desde = substr($datos['desde'], 0, 10);
        $hasta = substr($datos['hasta'], 0, 10);

        if ($hasta < $desde) {
            return response()->json(['detail' => 'El rango termina antes de empezar.'], 400);
        }

        return ['desde' => $desde, 'hasta' => $hasta, 'personas' => Asistencia::resumen($desde, $hasta)];
    }

    /** DELETE /asistencia/{marcaje} — borra una marca mal puesta. Queda auditado. */
    public function eliminar(Request $request, string $marcajeId)
    {
        $m = DB::table('marcajes_asistencia')->where('id', $marcajeId)->first();
        if (! $m) {
            return response()->json(['detail' => 'Esa marca no existe.'], 404);
        }

        $detalle = $this->out($m);

        DB::transaction(function () use ($request, $m, $detalle) {
            DB::table('marcajes_asistencia')->where('id', $m->id)->delete();
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'eliminar', 'asistencia', 'marcaje', null, $detalle);
        });

        return ['ok' => true];
    }

    // ── Kiosco: el tablet compartido de recepción ────────────────────────────
    // Auth por token de dispositivo (middleware `fichaje`), no por JWT. No
    // decide horas ni corrige nada: solo alterna la marca de una persona de la
    // lista.

    /** GET /asistencia/roster — la grilla del kiosco: staff activo y su estado. */
    public function roster()
    {
        $personas = DB::table('usuarios')
            ->whereIn('rol', self::ROLES_STAFF)
            ->where('es_activo', 1)
            ->orderBy('nombre')->orderBy('id')   // §9.14: desempate por PK
            ->get(['id', 'nombre', 'cargo']);

        return [
            'personas' => $personas->map(fn ($u) => [
                'usuario_id' => (string) $u->id,
                'nombre' => $u->nombre,
                'cargo' => $u->cargo,
                'estado' => Asistencia::estadoActual($u->id),
            ])->values(),
        ];
    }

    /** POST /asistencia/marcar-kiosco — ficha desde el tablet. Body: `usuario_id`. */
    public function marcarKiosco(Request $request)
    {
        $datos = $request->validate(['usuario_id' => 'required|uuid']);

        $u = DB::table('usuarios')->where('id', $datos['usuario_id'])->first();
        if (! $u || ! in_array($u->rol, self::ROLES_STAFF, true) || (int) ($u->es_activo ?? 0) !== 1) {
            return response()->json(['detail' => 'Esa persona no está en la lista.'], 404);
        }

        // El servidor decide el tipo por el estado actual —entra si está fuera,
        // sale si está dentro—: así dos toques seguidos no abren dos turnos y no
        // hay un `tipo` del cliente en que confiar. `registrado_por_id` queda en
        // NULL: es la propia persona, solo que desde otro aparato.
        $tipo = Asistencia::estadoActual($u->id) === 'entrada' ? 'salida' : 'entrada';
        $momento = CarbonImmutable::now()->format('Y-m-d H:i:s.u');

        DB::table('marcajes_asistencia')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $u->id,
            'tipo' => $tipo,
            'momento' => $momento,
            'nota' => null,
            'registrado_por_id' => null,
        ]);

        return ['nombre' => $u->nombre, 'tipo' => $tipo, 'momento' => $this->iso($momento)];
    }

    // ── salida ──────────────────────────────────────────────────────────────

    private function unoOut(string $id): array
    {
        return $this->out(DB::table('marcajes_asistencia')->where('id', $id)->first());
    }

    private function out($m): array
    {
        return [
            'id' => $m->id,
            'usuario_id' => $m->usuario_id,
            'tipo' => $m->tipo,
            'momento' => $this->iso($m->momento),
            'nota' => $m->nota,
            // Con valor, alguien la registró por otra persona: se muestra como
            // corrección y no como una marca real.
            'registrado_por_id' => $m->registrado_por_id ?: null,
        ];
    }

    /** `datetime` de la base → isoformat de Python. `.000000` se recorta a
     *  segundos, igual que `RecordatoriosController::iso`. */
    private function iso(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = str_replace(' ', 'T', substr($v, 0, 26));

        return str_ends_with($t, '.000000') ? substr($t, 0, 19) : $t;
    }
}
