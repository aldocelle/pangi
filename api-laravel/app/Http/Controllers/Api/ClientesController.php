<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Services\Sesiones;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tutores y sus mascotas (permiso «personas»).
 *
 * Un «cliente» es un `usuarios` con `rol='dueño'`. La misma tabla guarda al
 * staff, así que ese filtro no es cosmético: sin él, el listado de clientes
 * mostraría a los veterinarios.
 */
class ClientesController extends Controller
{
    public function __construct(private Auditoria $auditoria)
    {
    }

    /**
     * Tope de filas por página. **20, no 30.**
     *
     * El endpoint declara `limit: int = 30`, pero `paginar()` lo recorta a
     * `LIMITE_MAX = 20`. O sea que el default de la firma es engañoso: pedir
     * 30 devuelve 20. Se replica el recorte, no la firma.
     */
    private const LIMITE_MAX = 20;

    public function listar(Request $request)
    {
        $orden = $request->query('orden', '-created_at');
        $porConteo = in_array($orden, ['n_mascotas', '-n_mascotas', 'n_citas', '-n_citas'], true);

        $q = DB::table('usuarios as u')->where('u.rol', 'dueño');
        $this->aplicarFiltros($q, $request);

        if ($porConteo) {
            // Ordenar por un conteo exige agregación. Las subconsultas evitan
            // el join + group_by de Python, que con dos outerjoin multiplica
            // filas y obliga a `count(distinct)`.
            $col = str_contains($orden, 'n_mascotas')
                ? DB::raw('(SELECT COUNT(*) FROM mascotas WHERE mascotas.usuario_id = u.id)')
                : DB::raw('(SELECT COUNT(*) FROM citas WHERE citas.usuario_dueno_id = u.id)');
            $q->orderBy($col, str_starts_with($orden, '-') ? 'desc' : 'asc');
        } elseif ($orden === 'nombre' || $orden === '-nombre') {
            $q->orderBy('u.nombre', $orden === '-nombre' ? 'desc' : 'asc');
        } else {
            // El default y cualquier valor desconocido: más nuevo primero.
            $q->orderBy('u.created_at', 'desc');
        }
        // El desempate por id SIEMPRE (§9.14). `paginar()` hace OFFSET/LIMIT
        // sobre este orden, y un grupo de empatados que cruza el borde de una
        // página sale repetido o no sale — medido sobre los datos reales.
        $q->orderBy('u.id');

        return $this->paginar($q, $request, fn ($u) => $this->clienteOut($u));
    }

    public function ver(string $id)
    {
        $u = DB::table('usuarios')->where('id', $id)->where('rol', 'dueño')->first();
        if (! $u) {
            return response()->json(['detail' => 'Cliente no encontrado'], 404);
        }
        $mascotas = DB::table('mascotas')
            ->where('usuario_id', $u->id)
            // Python no declara orden acá, así que Postgres devuelve el orden
            // físico. Se le da uno determinista y se declara la diferencia en
            // el arnés, igual que con `especialista_ids`.
            ->orderBy('nombre')->orderBy('id')
            ->get();

        return array_merge($this->clienteOut($u), [
            'mascotas' => $mascotas->map(fn ($m) => $this->mascotaOut($m))->values(),
        ]);
    }

    /**
     * POST /clientes — permiso «personas».
     *
     * **Un correo que ya existe NO es un error**, y ese es el punto delicado:
     * quien reservó sin cuenta ya tiene un `Usuario` «fantasma» (creado por
     * `/citas/reservar` con contraseña aleatoria). Si el mostrador da de alta a
     * esa misma persona, se ADOPTA esa cuenta —se le fija contraseña y se le
     * marca el correo como verificado— en vez de fallar. Así conserva sus
     * mascotas y sus citas previas, que es justo lo que se perdería creando
     * una cuenta nueva.
     */
    public function crear(Request $request)
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string'],
            'telefono' => ['required', 'string'],
            // Opcional a propósito: en el mostrador el dato que siempre se
            // tiene a mano es el teléfono, no el correo.
            'email' => ['nullable', 'email'],
            'password' => ['nullable', 'string'],
            'es_activo' => ['nullable', 'boolean'],
        ]);

        $esActivo = $datos['es_activo'] ?? true;
        $existente = ! empty($datos['email'])
            ? DB::table('usuarios')->where('email', $datos['email'])->first()
            : null;

        if ($existente && $existente->rol !== 'dueño') {
            return response()->json(['detail' => 'Ese correo pertenece a una cuenta de staff.'], 409);
        }

        $password = $datos['password'] ?? $this->passwordTemporal();
        // `cost 12` explícito, como en los otros cuatro sitios que hashean y
        // como `bcrypt.gensalt()` en Python. Sin él, PHP usa 10: la contraseña
        // que crea la recepcionista al dar de alta a un cliente quedaría
        // cuatro veces más barata de romper que las demás, sin que nada lo
        // diga — `password_verify` acepta cualquier coste.
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $vinculadas = 0;

        return DB::transaction(function () use ($request, $datos, $existente, $esActivo, $password, $hash, &$vinculadas) {
            if ($existente) {
                $vinculadas = DB::table('mascotas')->where('usuario_id', $existente->id)->count();
                DB::table('usuarios')->where('id', $existente->id)->update([
                    'nombre' => trim($datos['nombre']) ?: $existente->nombre,
                    'telefono' => $this->telefonoONull($datos['telefono'] ?? null)
                        ?? $existente->telefono,
                    'es_activo' => $esActivo,
                    'password_hash' => $hash,
                    // El staff está entregando la cuenta directamente, no es un
                    // autorregistro: sin esto, el login rechazaría para siempre
                    // a cualquier cliente creado desde el panel.
                    'email_verificado' => true,
                ]);
                $id = $existente->id;
            } else {
                $id = (string) Str::uuid();
                DB::table('usuarios')->insert([
                    'id' => $id,
                    // Sin correo real igual hace falta un valor único y no
                    // nulo: `usuarios.email` es la clave de login de todo el
                    // sistema. No es un contacto y no se le manda nada.
                    // `?? null` antes del `?:`: `validate()` NO devuelve las
                    // claves ausentes, así que un cuerpo sin `email` reventaba
                    // con «Undefined array key». No basta con que sea
                    // nullable en las reglas.
                    'email' => ($datos['email'] ?? null) ?: $this->emailPlaceholder(),
                    'nombre' => trim($datos['nombre']),
                    'telefono' => $this->telefonoONull($datos['telefono'] ?? null),
                    'rol' => 'dueño',
                    'es_activo' => $esActivo,
                    'password_hash' => $hash,
                    'email_verificado' => true,
                    'terms_accepted' => true,
                    'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                ]);
            }

            $u = DB::table('usuarios')->where('id', $id)->first();
            // `cuenta_existente` es el dato que importa de esta acción: dice si
            // se creó un cliente o se ADOPTÓ la cuenta fantasma que había
            // dejado una reserva sin registro. Sin él, en la auditoría las dos
            // cosas se leen igual — y una de ellas se llevó las mascotas y el
            // historial de alguien que ya existía.
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'crear', 'personas', 'cliente', $id,
                ['nombre' => $u->nombre, 'cuenta_existente' => (bool) $existente]);

            return response()->json(array_merge($this->clienteOut($u), [
                // Solo se devuelve la contraseña si la generamos nosotros: si
                // la eligió el staff, ya la sabe y repetirla es exponerla.
                'password_temporal' => empty($datos['password']) ? $password : null,
                'mascotas_vinculadas' => $vinculadas,
                'cuenta_existente' => (bool) $existente,
            ]), 201);
        });
    }

    /** PATCH /clientes/{id} — permiso «personas». */
    public function editar(Request $request, string $id)
    {
        $datos = $request->validate([
            'nombre' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'telefono' => ['nullable', 'string'],
            'rut' => ['nullable', 'string'],
            'direccion' => ['nullable', 'string'],
            'es_activo' => ['nullable', 'boolean'],
        ]);

        $u = DB::table('usuarios')->where('id', $id)->where('rol', 'dueño')->first();
        if (! $u) {
            return response()->json(['detail' => 'Cliente no encontrado'], 404);
        }

        $cambios = [];
        if (array_key_exists('email', $datos) && $datos['email'] !== null
            && $datos['email'] !== $u->email) {
            if (DB::table('usuarios')->where('email', $datos['email'])
                ->where('id', '<>', $u->id)->exists()) {
                return response()->json(['detail' => 'Ya existe un usuario con ese correo.'], 409);
            }
            $cambios['email'] = $datos['email'];
        }
        if (($datos['nombre'] ?? null) !== null) {
            $cambios['nombre'] = trim($datos['nombre']);
        }
        if (($datos['telefono'] ?? null) !== null) {
            $cambios['telefono'] = $this->telefonoONull($datos['telefono']);
        }
        foreach (['rut', 'direccion'] as $campo) {
            if (($datos[$campo] ?? null) !== null) {
                $cambios[$campo] = $datos[$campo];
            }
        }
        if (($datos['es_activo'] ?? null) !== null) {
            $cambios['es_activo'] = $datos['es_activo'];
        }

        if ($cambios) {
            DB::table('usuarios')->where('id', $u->id)->update($cambios);
        }
        if (isset($cambios['es_activo']) && ! $cambios['es_activo']) {
            Sesiones::revocar((string) $u->id);
        }
        $this->auditoria->registrar($request->attributes->get('usuario'),
            'editar', 'personas', 'cliente', $u->id, ['nombre' => $u->nombre]);

        return $this->clienteOut(DB::table('usuarios')->where('id', $u->id)->first());
    }

    /**
     * El índice único de `usuarios.telefono` es PARCIAL (`WHERE telefono IS
     * NOT NULL`): admite cualquier cantidad de NULL, pero un string vacío es
     * un valor como cualquier otro, así que el segundo cliente sin teléfono
     * chocaría contra el primero.
     */
    /**
     * GET /clientes/solicitudes-reset — los pedidos de recuperación pendientes.
     *
     * El reset de un cliente lo resuelve una persona del equipo, no un enlace
     * por correo: buena parte de los clientes importados de ACVet tiene un
     * correo inventado (`@sin-correo.local`) al que no llega nada.
     */
    public function solicitudesReset()
    {
        $filas = DB::table('solicitudes_reset')->where('resuelta', false)
            ->orderBy('created_at', 'desc')->orderBy('id', 'desc')->get();

        // Los nombres, en una consulta y no una por fila.
        $usuarios = $filas->isEmpty() ? collect() : DB::table('usuarios')
            ->whereIn('email', $filas->pluck('email')->unique()->values())
            ->get()->keyBy('email');

        return $filas->map(function ($s) use ($usuarios) {
            $u = $usuarios->get($s->email);
            return [
                'id' => $s->id,
                'email' => $s->email,
                'created_at' => $this->iso($s->created_at),
                'cliente_id' => $u->id ?? null,
                'cliente_nombre' => $u->nombre ?? null,
            ];
        })->values()->all();
    }

    /**
     * POST /clientes/{id}/reset-password — le fija una clave nueva al cliente.
     *
     * Sin `password` en el cuerpo se genera una temporal. **La respuesta la
     * devuelve siempre**, elegida o generada, porque quien la va a dictar por
     * teléfono es quien está mirando esta pantalla — a diferencia del reset de
     * staff, que devuelve `null` cuando la eligió el propio administrador.
     */
    public function resetPassword(Request $request, string $clienteId)
    {
        $u = DB::table('usuarios')->where('id', $clienteId)->where('rol', 'dueño')->first();
        if (! $u) {
            return response()->json(['detail' => 'Cliente no encontrado'], 404);
        }

        // `$request->exists()` y no `input()`: Laravel convierte un string en
        // blanco a null al validar, así que «   » llegaría como ausente y el
        // mínimo no saltaría. Python distingue «no vino» de «vino vacío».
        $elegida = trim((string) $request->input('password', ''));
        if ($request->exists('password') && $request->input('password') !== null
            && $elegida !== '' && strlen($elegida) < 8) {
            return response()->json(
                ['detail' => 'La contraseña debe tener al menos 8 caracteres.'], 422);
        }

        $nueva = $elegida !== '' ? $elegida : $this->passwordTemporal();

        DB::transaction(function () use ($u, $nueva, $request) {
            DB::table('usuarios')->where('id', $u->id)->update([
                'password_hash' => password_hash($nueva, PASSWORD_BCRYPT, ['cost' => 12]),
                // El staff acaba de entregarle esta clave al cliente por su
                // cuenta: si la cuenta seguía sin verificar, esto ya cumple ese
                // propósito y no debería seguir bloqueando el login.
                'email_verificado' => true,
            ]);
            Sesiones::revocar((string) $u->id);
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'reset_password', 'personas', 'cliente', $u->id, ['nombre' => $u->nombre]);

            // Las solicitudes de ese correo quedan resueltas: si no, la cola
            // del panel seguiría mostrando un pedido ya atendido.
            DB::table('solicitudes_reset')
                ->where('email', $u->email)->where('resuelta', false)
                ->update([
                    'resuelta' => true,
                    // Marca técnica, en UTC (§9.6): Python usa `utcnow()` acá.
                    'resuelta_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                ]);
        });

        return ['password_temporal' => $nueva];
    }

    private function telefonoONull(?string $t): ?string
    {
        return $t !== null && trim($t) !== '' ? trim($t) : null;
    }

    private function passwordTemporal(int $n = 8): string
    {
        $alfabeto = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $out .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        return $out;
    }

    private function emailPlaceholder(): string
    {
        return 'sin-correo-' . substr(bin2hex(random_bytes(8)), 0, 10) . '@interno.clinica';
    }

    private function aplicarFiltros($q, Request $request): void
    {
        if ($v = $request->query('q')) {
            $like = '%' . $v . '%';
            $q->where(function ($w) use ($like) {
                $w->where('u.nombre', 'like', $like)->orWhere('u.email', 'like', $like);
            });
        }
        if ($v = $request->query('telefono')) {
            $q->where('u.telefono', 'like', '%' . $v . '%');
        }
        if ($v = $request->query('rut')) {
            $q->where('u.rut', 'like', '%' . $v . '%');
        }
        if ($v = $request->query('mascota')) {
            // EXISTS y no join: filtra por mascota sin duplicar filas del
            // tutor ni interferir con el orden por conteo.
            $q->whereExists(function ($sub) use ($v) {
                $sub->select(DB::raw(1))->from('mascotas')
                    ->whereColumn('mascotas.usuario_id', 'u.id')
                    ->where('mascotas.nombre', 'like', '%' . $v . '%');
            });
        }
    }

    /** El sobre de `paginar()`: items, total, page, limit, total_paginas. */
    private function paginar($q, Request $request, callable $serializar): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min((int) $request->query('limit', 30), self::LIMITE_MAX));
        // `count()` sobre la consulta SIN orden, como hace `paginar()`
        // (`query.order_by(None).count()`): ordenar para contar es trabajo
        // tirado y en MySQL puede cambiar el plan.
        $total = (clone $q)->reorder()->count();
        $items = $q->offset(($page - 1) * $limit)->limit($limit)->get();

        return [
            'items' => $items->map($serializar)->values(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_paginas' => max(1, (int) ceil($total / $limit)),
        ];
    }

    private function clienteOut($u): array
    {
        return [
            'id' => $u->id,
            'nombre' => $u->nombre,
            'email' => $u->email,
            'telefono' => $u->telefono,
            'rut' => $u->rut,
            'direccion' => $u->direccion,
            'es_activo' => (bool) $u->es_activo,
            // La que el tutor sube desde el portal (`/auth/me/foto`), con la
            // misma forma que `/auth/me`: la base guarda solo el nombre. Con
            // `empty` porque la columna nace con `mariadb_usuario_foto.sql`:
            // sin la migración el listado sigue saliendo, con la inicial.
            'foto' => ! empty($u->foto) ? "/uploads/{$u->foto}" : null,
            'created_at' => $this->iso($u->created_at),
            'n_mascotas' => DB::table('mascotas')->where('usuario_id', $u->id)->count(),
            'n_citas' => DB::table('citas')->where('usuario_dueno_id', $u->id)->count(),
        ];
    }

    /** POST /clientes/{id}/mascotas — dar de alta un paciente. */
    public function agregarMascota(Request $request, string $clienteId)
    {
        $datos = $request->validate([
            'nombre' => 'required|string',
            'especie' => 'nullable|string',
            'raza' => 'nullable|string',
            'sexo' => 'nullable|string',
        ]);

        $u = DB::table('usuarios')->where('id', $clienteId)->where('rol', 'dueño')->first();
        if (! $u) {
            return response()->json(['detail' => 'Cliente no encontrado'], 404);
        }

        $id = (string) Str::uuid();
        DB::table('mascotas')->insert([
            'id' => $id,
            'usuario_id' => $u->id,
            'nombre' => trim($datos['nombre']),
            // El default vive en el schema (`especie: str = "perro"`), no en
            // la columna: un cuerpo sin especie guarda "perro", no NULL.
            'especie' => $datos['especie'] ?? 'perro',
            'raza' => $datos['raza'] ?? null,
            'sexo' => $datos['sexo'] ?? null,
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);

        return response()->json(
            $this->mascotaOut(DB::table('mascotas')->where('id', $id)->first()), 201);
    }

    /**
     * PATCH /clientes/mascotas/{id} — editar un paciente.
     *
     * **`is not None`, NO `exclude_unset`**, y la diferencia importa: mandar
     * `null` en un campo no lo borra, lo deja como estaba. Es lo que hace
     * Python y hay que replicarlo — la única excepción es `fecha_nacimiento`,
     * que sí acepta el vaciado con string vacío.
     */
    public function editarMascota(Request $request, string $mascotaId)
    {
        $m = DB::table('mascotas')->where('id', $mascotaId)->first();
        if (! $m) {
            return response()->json(['detail' => 'Mascota no encontrada'], 404);
        }

        $datos = $request->validate([
            'nombre' => 'nullable|string',
            'especie' => 'nullable|string',
            'raza' => 'nullable|string',
            'sexo' => 'nullable|string',
            'peso_kg' => 'nullable|numeric',
            'alergias' => 'nullable|string',
            'fecha_nacimiento' => 'nullable|string',
            'microchip_codigo' => 'nullable|string',
            'esterilizado' => 'nullable|boolean',
            'estado' => 'nullable|string',
        ]);

        $cambios = [];
        foreach (['nombre', 'especie', 'raza', 'sexo', 'peso_kg', 'alergias',
                  'microchip_codigo', 'esterilizado', 'estado'] as $campo) {
            if (($datos[$campo] ?? null) !== null) {
                $cambios[$campo] = $datos[$campo];
            }
        }
        // `fecha_nacimiento` va aparte: Python la trata distinto —un string
        // vacío la BORRA, y solo `None` la deja intacta.
        if (($datos['fecha_nacimiento'] ?? null) !== null) {
            $cambios['fecha_nacimiento'] = $datos['fecha_nacimiento'] === ''
                ? null : substr($datos['fecha_nacimiento'], 0, 10);
        }

        if ($cambios) {
            DB::table('mascotas')->where('id', $mascotaId)->update($cambios);
        }

        return $this->mascotaOut(DB::table('mascotas')->where('id', $mascotaId)->first());
    }

    /**
     * Cuenta lo que se destruiría al borrar estas mascotas. Alimenta la
     * pantalla de confirmación: nadie debería borrar un historial clínico sin
     * ver antes cuánto se lleva por delante.
     */
    private function impactoMascotas(array $mascotaIds): array
    {
        if (! $mascotaIds) {
            return ['mascotas' => 0, 'citas' => 0, 'fichas' => 0, 'notas' => 0];
        }
        $fichaIds = DB::table('fichas_clinicas')->whereIn('mascota_id', $mascotaIds)
            ->pluck('id')->all();

        return [
            'mascotas' => count($mascotaIds),
            'citas' => DB::table('citas')->whereIn('mascota_id', $mascotaIds)->count(),
            'fichas' => count($fichaIds),
            'notas' => $fichaIds
                ? DB::table('notas_clinicas')->whereIn('ficha_id', $fichaIds)->count() : 0,
        ];
    }

    /**
     * Borra mascotas y todo lo que cuelga de ellas, **en orden de FK**.
     *
     * Siete tablas apuntan a `mascotas`, `fichas_clinicas` o `notas_clinicas`, y
     * ninguna FK tiene cascada: la que se olvide levanta un error de integridad
     * y el borrado entero falla sin borrar nada.
     *
     * El orden va de nieta a abuela: recordatorios y aplicaciones de protocolo
     * apuntan también a la NOTA, así que borrar las notas primero revienta.
     *
     * Qué sobrevive: la VENTA no se borra —es plata cobrada, cuadra con Caja y
     * con los informes del período— y se le suelta el paciente, que es el mismo
     * estado en que están las 15.410 importadas de ACVet.
     */
    private function borrarMascotas(array $mascotaIds): void
    {
        if (! $mascotaIds) {
            return;
        }
        $fichaIds = DB::table('fichas_clinicas')->whereIn('mascota_id', $mascotaIds)
            ->pluck('id')->all();

        DB::table('recordatorios')->whereIn('mascota_id', $mascotaIds)->delete();
        DB::table('protocolo_aplicaciones')->whereIn('mascota_id', $mascotaIds)->delete();
        DB::table('ventas')->whereIn('mascota_id', $mascotaIds)
            ->update(['mascota_id' => null]);

        if ($fichaIds) {
            // Los documentos van ANTES que las notas: cuelgan de las dos.
            DB::table('documentos_clinicos')->whereIn('ficha_id', $fichaIds)->delete();
            DB::table('notas_clinicas')->whereIn('ficha_id', $fichaIds)->delete();
            DB::table('fichas_clinicas')->whereIn('id', $fichaIds)->delete();
        }
        DB::table('citas')->whereIn('mascota_id', $mascotaIds)->delete();
        DB::table('mascotas')->whereIn('id', $mascotaIds)->delete();
    }

    /** GET /clientes/mascotas/{id}/impacto */
    public function impactoMascota(string $mascotaId)
    {
        $m = DB::table('mascotas')->where('id', $mascotaId)->first();
        if (! $m) {
            return response()->json(['detail' => 'Mascota no encontrada'], 404);
        }

        return array_merge(['nombre' => $m->nombre], $this->impactoMascotas([$mascotaId]));
    }

    /** GET /clientes/{id}/impacto */
    public function impactoCliente(string $clienteId)
    {
        $u = DB::table('usuarios')->where('id', $clienteId)->where('rol', 'dueño')->first();
        if (! $u) {
            return response()->json(['detail' => 'Cliente no encontrado'], 404);
        }
        $ids = DB::table('mascotas')->where('usuario_id', $u->id)->pluck('id')->all();

        return array_merge(['nombre' => $u->nombre], $this->impactoMascotas($ids));
    }

    /** DELETE /clientes/mascotas/{id} — irreversible. Solo admin. */
    public function eliminarMascota(Request $request, string $mascotaId)
    {
        $m = DB::table('mascotas')->where('id', $mascotaId)->first();
        if (! $m) {
            return response()->json(['detail' => 'Mascota no encontrada'], 404);
        }
        if (trim((string) $request->query('confirmar', '')) !== trim((string) ($m->nombre ?? ''))) {
            return response()->json([
                'detail' => 'Debes escribir el nombre exacto del paciente para confirmar la eliminación.',
            ], 400);
        }

        DB::transaction(function () use ($request, $m) {
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'eliminar', 'personas', 'mascota', $m->id, ['nombre' => $m->nombre]);
            $this->borrarMascotas([$m->id]);
        });

        return response()->noContent();
    }

    /** DELETE /clientes/{id} — el cliente y todos sus pacientes. Solo admin. */
    public function eliminarCliente(Request $request, string $clienteId)
    {
        $u = DB::table('usuarios')->where('id', $clienteId)->where('rol', 'dueño')->first();
        if (! $u) {
            return response()->json(['detail' => 'Cliente no encontrado'], 404);
        }
        if (trim((string) $request->query('confirmar', '')) !== trim((string) ($u->nombre ?? ''))) {
            return response()->json([
                'detail' => 'Debes escribir el nombre exacto del cliente para confirmar la eliminación.',
            ], 400);
        }

        DB::transaction(function () use ($request, $u) {
            $ids = DB::table('mascotas')->where('usuario_id', $u->id)->pluck('id')->all();

            $this->auditoria->registrar($request->attributes->get('usuario'),
                'eliminar', 'personas', 'cliente', $u->id,
                ['nombre' => $u->nombre, 'mascotas' => count($ids)]);

            $this->borrarMascotas($ids);
            // Citas del dueño que no cuelguen de sus mascotas (una ya movida).
            DB::table('citas')->where('usuario_dueno_id', $u->id)->delete();
            DB::table('solicitudes_reset')->where('email', $u->email)->delete();

            // La PLATA no se va con la persona: venta y transacción cuadran con
            // Caja y con los informes de un período ya cerrado. Se les suelta el
            // cliente. Sin esto, borrar a cualquiera de los 1.587 dueños con
            // compras (58% del total) fallaba.
            DB::table('ventas')->where('propietario_id', $u->id)
                ->update(['propietario_id' => null]);
            DB::table('transacciones')->where('propietario_id', $u->id)
                ->update(['propietario_id' => null]);

            DB::table('usuarios')->where('id', $u->id)->delete();
        });

        return response()->noContent();
    }

    /**
     * POST /clientes/mascotas/{id}/mover — pasar el paciente a otro tutor.
     *
     * Corrige el caso real de la mascota cargada bajo el dueño equivocado: se
     * mueve con su ficha y su historial, en vez de borrarla y volver a crearla
     * —que perdería todo lo clínico.
     */
    public function moverMascota(Request $request, string $mascotaId)
    {
        $datos = $request->validate(['nuevo_cliente_id' => 'required|uuid']);

        $m = DB::table('mascotas')->where('id', $mascotaId)->first();
        if (! $m) {
            return response()->json(['detail' => 'Mascota no encontrada'], 404);
        }
        $destino = DB::table('usuarios')->where('id', $datos['nuevo_cliente_id'])
            ->where('rol', 'dueño')->first();
        if (! $destino) {
            return response()->json(['detail' => 'Cliente destino no encontrado'], 404);
        }

        DB::transaction(function () use ($request, $m, $destino) {
            DB::table('mascotas')->where('id', $m->id)->update(['usuario_id' => $destino->id]);
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'mover', 'personas', 'mascota', $m->id,
                ['nombre' => $m->nombre, 'de' => (string) $m->usuario_id,
                 'a' => (string) $destino->id]);
        });

        return $this->mascotaOut(DB::table('mascotas')->where('id', $m->id)->first());
    }

    private function mascotaOut($m): array
    {
        return [
            'id' => $m->id,
            'nombre' => $m->nombre,
            'especie' => $m->especie,
            'raza' => $m->raza,
            'sexo' => $m->sexo,
            'usuario_id' => $m->usuario_id,
            // `float` y no el string crudo: Python hace `float(m.peso_kg)`
            // sobre un Decimal, así que 8.50 sale como 8.5.
            'peso_kg' => $m->peso_kg === null ? null : (float) $m->peso_kg,
            'alergias' => $m->alergias,
            'fecha_nacimiento' => $m->fecha_nacimiento
                ? substr($m->fecha_nacimiento, 0, 10) : null,
            'microchip_codigo' => $m->microchip_codigo,
            'esterilizado' => $m->esterilizado === null ? null : (bool) $m->esterilizado,
            'estado' => $m->estado,
        ];
    }

    /** Igual que en CitasController: o sin fracción, o los seis dígitos. */
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

    // ── Solicitudes de derechos sobre datos personales (ley 21.719) ────────
    // El titular las crea desde el portal (`POST /auth/solicitudes`); acá el
    // equipo las ve y las cierra. Bajo el permiso `personas` porque quien las
    // atiende es la misma persona que ya administra tutores y pacientes.
    //
    // Cerrar una solicitud NO ejecuta nada: no borra la cuenta ni vacía la
    // ficha. Es a propósito — las herramientas para ejecutarla ya existen con
    // sus guardas de admin y confirmación por nombre. Lo que faltaba no era el
    // poder de borrar: era la constancia de que alguien recibió la petición,
    // decidió y respondió, con fecha y nombre.

    private const ESTADOS_SOLICITUD = ['pendiente', 'en_curso', 'resuelta', 'rechazada'];

    public function solicitudesDatos(Request $request)
    {
        $soloPendientes = filter_var($request->query('solo_pendientes', 'true'),
            FILTER_VALIDATE_BOOLEAN);

        $q = DB::table('solicitudes_titular');
        if ($soloPendientes) {
            $q->whereIn('estado', ['pendiente', 'en_curso']);
        }

        // ASCENDENTE: lo más antiguo primero, que es lo que lleva más tiempo
        // sin respuesta. La ley cuenta plazos desde que se recibe la solicitud,
        // así que el orden de una cola con reloj es al revés del de un listado
        // normal. Desempate por PK (§9.14).
        return $q->orderBy('created_at')->orderBy('id')->limit(200)->get()
            ->map(fn ($x) => $this->solicitudOut($x))->values()->all();
    }

    public function resolverSolicitudDatos(Request $request, string $solicitudId)
    {
        $datos = $request->validate([
            'estado' => 'required|string',
            'respuesta' => 'nullable|string',
        ]);
        if (! in_array($datos['estado'], self::ESTADOS_SOLICITUD, true)) {
            return response()->json([
                'detail' => 'Estado inválido. Usa uno de: '
                    . implode(', ', self::ESTADOS_SOLICITUD) . '.',
            ], 400);
        }

        $s = DB::table('solicitudes_titular')->where('id', $solicitudId)->first();
        if (! $s) {
            return response()->json(['detail' => 'Solicitud no encontrada'], 404);
        }

        $actor = $request->attributes->get('usuario');
        $respuesta = trim((string) ($datos['respuesta'] ?? '')) ?: $s->respuesta;
        $set = ['estado' => $datos['estado'], 'respuesta' => $respuesta];

        if (in_array($datos['estado'], ['resuelta', 'rechazada'], true)) {
            $set['resuelta_at'] = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
            $set['resuelta_por_id'] = $actor->id;
            $set['resuelta_por_nombre'] = $actor->nombre;
        }

        DB::table('solicitudes_titular')->where('id', $solicitudId)->update($set);

        $this->auditoria->registrar($actor, 'resolver', 'personas',
            'solicitud_titular', $solicitudId,
            ['tipo' => $s->tipo, 'estado' => $datos['estado'], 'email' => $s->email]);

        return $this->solicitudOut(
            DB::table('solicitudes_titular')->where('id', $solicitudId)->first());
    }

    private function solicitudOut($s): array
    {
        return [
            'id' => $s->id,
            'usuario_id' => $s->usuario_id,
            'email' => $s->email,
            'tipo' => $s->tipo,
            'detalle' => $s->detalle,
            'estado' => $s->estado,
            'respuesta' => $s->respuesta,
            'created_at' => $this->isoSolicitud($s->created_at),
            'resuelta_at' => $this->isoSolicitud($s->resuelta_at),
            'resuelta_por' => $s->resuelta_por_nombre,
        ];
    }

    private function isoSolicitud(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return str_replace(' ', 'T', rtrim(substr($v, 0, 26)));
    }
}
