<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SlotsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Endpoints internos backend-to-backend para el agente de WhatsApp.
 *
 * **No expone nada que un dueño no pudiera ver o hacer ya sobre sus propios
 * datos** en el portal: mismo alcance que `/citas/mias` o `/mascotas/`, solo
 * que autenticado con clave de servicio en vez de JWT.
 *
 * Por eso cada endpoint recibe el `usuario_id` explícito y filtra por él: el
 * bot actúa en nombre de alguien ya identificado por su teléfono, no «como
 * administrador».
 */
class InternalController extends Controller
{
    /** ~3 meses, igual que el autoservicio. */
    private const MAX_DIAS_ANTICIPACION = 92;

    /**
     * POST /internal/resolve-user — identidad por TELÉFONO (canal WhatsApp).
     */
    public function resolveUser(Request $request)
    {
        $datos = $request->validate([
            'telefono' => 'required|string',
            'nombre' => 'nullable|string',
        ]);

        $telefono = $this->normalizarTelefono($datos['telefono']);
        if ($telefono === '') {
            return response()->json(['detail' => 'Teléfono inválido'], 400);
        }

        $variantes = $this->variantesTelefono($telefono);
        $placeholders = implode(',', array_fill(0, count($variantes), '?'));
        $usuario = DB::table('usuarios')
            ->whereRaw(
                "REPLACE(REPLACE(REPLACE(telefono, ' ', ''), '-', ''), '+', '') IN ($placeholders)",
                $variantes
            )
            ->first();

        if (! $usuario) {
            // El teléfono normalizado puede no calzar con ningún registro y
            // aun así el correo sintético sí existir (un WhatsApp previo ya
            // lo creó, o una reserva web dejó el mismo email por otra vía) —
            // sin este chequeo el insert de abajo viola el UNIQUE de `email`
            // y la persona recibe un 500 en vez de que se la reconozca.
            $usuario = DB::table('usuarios')
                ->where('email', "whatsapp-{$telefono}@whatsapp.local")
                ->first();
        }

        if (! $usuario) {
            $id = (string) Str::uuid();
            DB::table('usuarios')->insert([
                'id' => $id,
                // Correo inventado: esta persona llegó por WhatsApp y no dio
                // uno. Si después reserva por el sitio con su correo real,
                // quedará como cuenta separada — no hay unificación automática.
                'email' => "whatsapp-{$telefono}@whatsapp.local",
                'nombre' => trim($datos['nombre'] ?? '') ?: 'Cliente WhatsApp',
                'telefono' => $telefono,
                'rol' => 'dueño',
                // Clave aleatoria que nadie usa nunca: la columna es NOT NULL
                // y esta cuenta no tiene login propio.
                'password_hash' => password_hash(Str::uuid()->toString(),
                    PASSWORD_BCRYPT, ['cost' => 12]),
                'es_activo' => true,
                'email_verificado' => false,
                'terms_accepted' => false,
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
            $usuario = DB::table('usuarios')->where('id', $id)->first();
        } elseif ($usuario->telefono !== $telefono) {
            // Encontrado por la variante SIN el 56: se normaliza in situ para
            // que la próxima búsqueda calce directo.
            DB::table('usuarios')->where('id', $usuario->id)
                ->update(['telefono' => $telefono]);
        }

        return [
            'usuario_id' => (string) $usuario->id,
            'nombre' => $usuario->nombre,
            'email' => $usuario->email,
        ];
    }

    /**
     * POST /internal/resolve-user-by-email — identidad para el canal WEB.
     *
     * Un visitante sin login da su correo para poder agendar. Mismo patrón que
     * `/citas/reservar`.
     */
    public function resolveUserByEmail(Request $request)
    {
        $datos = $request->validate([
            'correo' => 'required|email',
            'nombre' => 'nullable|string',
            'telefono' => 'nullable|string',
        ]);

        $usuario = DB::table('usuarios')->where('email', $datos['correo'])->first();

        if (! $usuario) {
            $id = (string) Str::uuid();
            DB::table('usuarios')->insert([
                'id' => $id,
                'email' => $datos['correo'],
                'nombre' => trim($datos['nombre'] ?? '')
                    ?: explode('@', $datos['correo'])[0],
                'telefono' => ! empty($datos['telefono'])
                    ? $this->normalizarTelefono($datos['telefono']) : null,
                'rol' => 'dueño',
                'password_hash' => password_hash(Str::uuid()->toString(),
                    PASSWORD_BCRYPT, ['cost' => 12]),
                'es_activo' => true,
                'email_verificado' => false,
                'terms_accepted' => false,
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
            $usuario = DB::table('usuarios')->where('id', $id)->first();
        } else {
            // Completa lo que falte, sin PISAR lo que ya hay: el dato guardado
            // vale más que el que acaba de escribir alguien en un chat.
            $set = [];
            if (! empty($datos['nombre']) && empty($usuario->nombre)) {
                $set['nombre'] = trim($datos['nombre']);
            }
            if (! empty($datos['telefono']) && empty($usuario->telefono)) {
                $set['telefono'] = $this->normalizarTelefono($datos['telefono']);
            }
            if ($set) {
                DB::table('usuarios')->where('id', $usuario->id)->update($set);
                $usuario = DB::table('usuarios')->where('id', $usuario->id)->first();
            }
        }

        return [
            'usuario_id' => (string) $usuario->id,
            'nombre' => $usuario->nombre,
            'email' => $usuario->email,
        ];
    }

    /** GET /internal/catalogo — para que el agente mapee nombre → id. */
    public function catalogo()
    {
        return [
            'servicios' => DB::table('servicios')->where('activo', true)
                ->orderBy('orden')->orderBy('nombre')->orderBy('id')->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'nombre' => $s->nombre,
                    'duracion_min' => (int) $s->duracion_min,
                    'precio_clp' => $s->precio_clp === null ? null : (int) $s->precio_clp,
                ])->values(),
            'especialistas' => DB::table('especialistas')->where('activo', true)
                ->orderBy('orden')->orderBy('nombre')->orderBy('id')->get()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'nombre' => $e->nombre,
                    'especialidad' => $e->especialidad,
                ])->values(),
        ];
    }

    /** GET /internal/slots */
    public function slots(Request $request, SlotsService $slots)
    {
        $inicio = CarbonImmutable::now();
        if ($desde = $request->query('desde')) {
            try {
                $d = CarbonImmutable::createFromFormat('Y-m-d', $desde)->startOfDay();
            } catch (\Throwable $e) {
                return response()->json(
                    ['detail' => "Parámetro 'desde' inválido (YYYY-MM-DD)"], 400);
            }
            $inicio = $d->gt($inicio) ? $d : $inicio;
        }

        $servicio = DB::table('servicios')->where('id', $request->query('servicio_id'))
            ->where('activo', true)->first();
        $especialista = DB::table('especialistas')->where('id', $request->query('especialista_id'))
            ->where('activo', true)->first();
        if (! $servicio || ! $especialista) {
            return response()->json(['detail' => 'Servicio o especialista no encontrado'], 404);
        }

        $dias = max(1, min((int) $request->query('dias', 7), 30));

        return (object) $slots->para($servicio, $especialista, $inicio, $dias);
    }

    /** POST /internal/citas — el agente agenda. */
    public function crearCita(Request $request, SlotsService $slots)
    {
        $datos = $request->validate([
            'usuario_id' => 'required|uuid',
            'mascota_nombre' => 'required|string',
            'servicio_id' => 'required|uuid',
            'especialista_id' => 'required|uuid',
            'fecha_hora' => 'required|date',
            'notas_pre_cita' => 'nullable|string',
        ]);

        $usuario = DB::table('usuarios')->where('id', $datos['usuario_id'])->first();
        if (! $usuario) {
            return response()->json(['detail' => 'Usuario no encontrado'], 404);
        }

        $servicio = DB::table('servicios')->where('id', $datos['servicio_id'])
            ->where('activo', true)->first();
        $especialista = DB::table('especialistas')->where('id', $datos['especialista_id'])->first();
        if (! $servicio || ! $especialista) {
            return response()->json(['detail' => 'Servicio o especialista no válido'], 400);
        }

        // Hora de la CLÍNICA, no UTC. El contenedor corre en UTC y comparar
        // contra `now()` de ahí rechazaba como «pasado» toda hora dentro de las
        // próximas 4 horas — justo las que alguien pide por WhatsApp (§9.6).
        $fechaHora = CarbonImmutable::createFromFormat('Y-m-d H:i:s',
            CarbonImmutable::parse($datos['fecha_hora'])->format('Y-m-d H:i:s'));
        $ahora = CarbonImmutable::now();

        if ($fechaHora->lte($ahora)) {
            return response()->json(['detail' => 'No se puede agendar en el pasado'], 400);
        }
        if ($fechaHora->startOfDay()->gt($ahora->addDays(self::MAX_DIAS_ANTICIPACION)->startOfDay())) {
            return response()->json(
                ['detail' => 'Solo se puede agendar hasta con 3 meses de anticipación'], 400);
        }

        $inicio = $ahora->gt($fechaHora->startOfDay()) ? $ahora : $fechaHora->startOfDay();
        $disponibles = $slots->para($servicio, $especialista, $inicio, 1);
        if (! in_array($fechaHora->format('H:i'),
                $disponibles[$fechaHora->format('Y-m-d')] ?? [], true)) {
            return response()->json(
                ['detail' => 'Ese horario ya no está disponible. Elige otro.'], 409);
        }

        $nombreMascota = trim($datos['mascota_nombre']);
        // Por NOMBRE y sin distinguir mayúsculas, bajo ese dueño: agendar dos
        // veces por WhatsApp no puede crear dos «Luna».
        $mascota = DB::table('mascotas')->where('usuario_id', $usuario->id)
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombreMascota)])
            ->first();
        if (! $mascota) {
            $mid = (string) Str::uuid();
            DB::table('mascotas')->insert([
                'id' => $mid,
                'usuario_id' => $usuario->id,
                'nombre' => $nombreMascota,
                // «perro» por defecto: el agente no pregunta la especie, y un
                // NULL dejaría la ficha sin ese dato para siempre.
                'especie' => 'perro',
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
            $mascota = DB::table('mascotas')->where('id', $mid)->first();
        }

        $id = (string) Str::uuid();
        DB::table('citas')->insert([
            'id' => $id,
            'mascota_id' => $mascota->id,
            'usuario_dueno_id' => $usuario->id,
            'fecha_hora' => $fechaHora->format('Y-m-d H:i:s'),
            'servicio' => $servicio->nombre,
            'servicio_id' => $servicio->id,
            'especialista_id' => $especialista->id,
            'duracion_min' => $servicio->duracion_min,
            'respuestas' => '{}',
            'notas_pre_cita' => $datos['notas_pre_cita'] ?? null,
            // Igual que `/crear-staff`: sin monto congelado y «Agendada». El
            // cobro lo coordina la clínica, y por eso `payment_status` va en
            // NULL explícito y no por el default de la columna.
            'estado' => 'Agendada',
            'payment_status' => null,
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);

        return [
            'id' => $id,
            'fecha_hora' => $fechaHora->format('Y-m-d\TH:i:s'),
            'servicio' => $servicio->nombre,
            'especialista' => $especialista->nombre,
            'mascota' => $mascota->nombre,
            'estado' => 'Agendada',
        ];
    }

    /**
     * GET /internal/citas — las citas PRÓXIMAS del usuario.
     *
     * Filtra por fecha y ordena ascendente: antes devolvía las últimas 20
     * incluyendo las pasadas, así que el asistente anunciaba como «tus próximas
     * horas» una cita del mes anterior y ofrecía cancelar algo que ya ocurrió.
     */
    public function citas(Request $request)
    {
        $citas = DB::table('citas')
            ->where('usuario_dueno_id', $request->query('usuario_id'))
            ->where('estado', '!=', 'Cancelada')
            ->where('fecha_hora', '>=', CarbonImmutable::now()->format('Y-m-d H:i:s'))
            ->orderBy('fecha_hora')->orderBy('id')
            ->limit(20)
            ->get();

        $mascotas = $citas->pluck('mascota_id')->filter()->unique();
        $esps = $citas->pluck('especialista_id')->filter()->unique();
        $nomM = $mascotas->isEmpty() ? collect()
            : DB::table('mascotas')->whereIn('id', $mascotas)->pluck('nombre', 'id');
        $nomE = $esps->isEmpty() ? collect()
            : DB::table('especialistas')->whereIn('id', $esps)->pluck('nombre', 'id');

        return $citas->map(fn ($c) => [
            'id' => $c->id,
            'fecha_hora' => $this->isoFecha($c->fecha_hora),
            'servicio' => $c->servicio,
            'especialista' => $nomE[$c->especialista_id] ?? null,
            'mascota' => $nomM[$c->mascota_id] ?? null,
            'estado' => $c->estado,
        ])->values();
    }

    /** PATCH /internal/citas/{id}/cancelar */
    public function cancelarCita(Request $request, string $citaId)
    {
        $cita = DB::table('citas')->where('id', $citaId)->first();
        if (! $cita) {
            return response()->json(['detail' => 'Cita no encontrada'], 404);
        }
        // El agente actúa en nombre de alguien: la cita tiene que ser SUYA.
        if ((string) $cita->usuario_dueno_id !== (string) $request->query('usuario_id')) {
            return response()->json(['detail' => 'Esa cita no pertenece a este usuario'], 403);
        }
        // Ya cancelada: se responde igual, sin volver a escribir. Idempotente.
        if ($cita->estado === 'Cancelada') {
            return ['id' => $cita->id, 'estado' => 'Cancelada'];
        }

        DB::table('citas')->where('id', $citaId)->update(['estado' => 'Cancelada']);

        return ['id' => $cita->id, 'estado' => 'Cancelada'];
    }

    /** GET /internal/mascotas */
    public function mascotas(Request $request)
    {
        return DB::table('mascotas')
            ->where('usuario_id', $request->query('usuario_id'))
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'nombre' => $m->nombre,
                'especie' => $m->especie,
                'raza' => $m->raza,
                'estado' => $m->estado,
            ])->values();
    }

    /** GET /internal/pagos — lo que este cliente debe. */
    public function pagos(Request $request)
    {
        $filas = DB::table('transacciones')
            ->where('propietario_id', $request->query('usuario_id'))
            ->orderBy('fecha', 'desc')->orderBy('id', 'desc')
            ->limit(10)
            ->get();

        return [
            // La suma es de las DIEZ que se devuelven, no de todas: es lo que
            // hace Python, y cambiarlo daría un número distinto al de la lista.
            'total_pendiente' => (float) $filas->sum(fn ($t) => (float) ($t->total_pendiente ?? 0)),
            'transacciones' => $filas->map(fn ($t) => [
                'id' => $t->id,
                'fecha' => $this->isoFecha($t->fecha),
                'documento_id' => $t->documento_id,
                'total_pendiente' => (float) ($t->total_pendiente ?? 0),
            ])->values(),
        ];
    }

    /** Solo dígitos: el número de Meta llega con formato y el guardado no. */
    private function normalizarTelefono(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }

    /**
     * El número de Meta SIEMPRE trae el 56 (56951755290), pero **~83% de los
     * registros legado están guardados sin él** (951755290) porque nunca se
     * corrió una limpieza masiva.
     *
     * Sin probar las dos variantes, cada cliente antiguo que escribiera por
     * WhatsApp estrenaría una cuenta duplicada — con su ficha vacía.
     */
    private function variantesTelefono(string $normalizado): array
    {
        $v = [$normalizado];
        if (str_starts_with($normalizado, '56') && strlen($normalizado) === 11) {
            $v[] = substr($normalizado, 2);
        }

        return $v;
    }

    private function isoFecha(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = str_replace(' ', 'T', substr($v, 0, 26));

        return str_ends_with($t, '.000000') ? substr($t, 0, 19) : $t;
    }
}
