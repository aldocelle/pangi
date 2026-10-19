<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Autenticar;
use App\Services\Auditoria;
use App\Services\Sesiones;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gestión del equipo (veterinarios y admin) desde el panel.
 *
 * **Listar es de cualquier staff; todo lo demás es SOLO ADMIN**, y no pasa por
 * el sistema de permisos granular: gestionar el equipo —incluido a quién se le
 * dan permisos— es por diseño una atribución exclusiva del administrador.
 *
 * Antes cualquier veterinario podía hacer PATCH sobre su propia cuenta con
 * `{"rol": "admin"}` y auto-ascenderse, porque estos endpoints solo exigían
 * `require_staff` sin mirar quién podía tocar `rol`.
 */
class StaffController extends Controller
{
    private const ROLES_STAFF = ['veterinario', 'admin'];

    public function __construct(private Auditoria $auditoria)
    {
    }

    /** GET /staff — cualquier staff puede ver quién es quién. */
    public function listar()
    {
        return DB::table('usuarios')
            ->whereIn('rol', self::ROLES_STAFF)
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->map(fn ($u) => $this->staffOut($u))
            ->values();
    }

    /** POST /staff */
    public function crear(Request $request)
    {
        $datos = $request->validate([
            'nombre' => 'required|string',
            'email' => 'required|email|max:255',
            'telefono' => 'nullable|string',
            'rol' => 'nullable|string',
            'cargo' => 'nullable|string',
            'password' => 'nullable|string',
            'permisos' => 'nullable|array',
        ]);

        $rol = $datos['rol'] ?? 'veterinario';
        if (! in_array($rol, self::ROLES_STAFF, true)) {
            return response()->json(['detail' => "Rol inválido. Usa 'veterinario' o 'admin'."], 400);
        }
        if ($err = $this->validarPermisos($datos['permisos'] ?? null)) {
            return $err;
        }
        if (DB::table('usuarios')->where('email', $datos['email'])->exists()) {
            return response()->json(['detail' => 'Ya existe un usuario con ese correo.'], 409);
        }

        $password = $datos['password'] ?? $this->claveAleatoria();
        $permisos = $rol === 'veterinario' ? ($datos['permisos'] ?? null) : null;
        $id = (string) Str::uuid();
        $actor = $request->attributes->get('usuario');

        DB::transaction(function () use ($datos, $id, $rol, $permisos, $password, $actor) {
            DB::table('usuarios')->insert([
                'id' => $id,
                'email' => $datos['email'],
                'nombre' => trim($datos['nombre']),
                'telefono' => $this->telefonoONull($datos['telefono'] ?? null),
                'rol' => $rol,
                'cargo' => $this->vacioANull($datos['cargo'] ?? null),
                'es_activo' => true,
                // Se da por VERIFICADO: a un miembro del equipo lo da de alta un
                // administrador que ya sabe quién es, y no hay a quién
                // confirmarle nada. Sin esto la cuenta nace sin verificar y el
                // login la rechaza con «Tu correo aún no está verificado» — el
                // admin daba de alta a alguien y esa persona no podía entrar.
                'email_verificado' => true,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                'permisos' => $permisos === null ? null : json_encode(array_values($permisos)),
                'terms_accepted' => true,
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
            $this->auditoria->registrar($actor, 'crear', 'config_equipo', 'usuario', $id,
                ['nombre' => trim($datos['nombre']), 'rol' => $rol, 'permisos' => $permisos]);
        });

        $u = DB::table('usuarios')->where('id', $id)->first();

        return response()->json([
            ...$this->staffOut($u),
            // La clave elegida por el admin NO se devuelve: ya la conoce quien
            // la escribió, y hacerle eco la dejaría en el log de la respuesta.
            'password_temporal' => isset($datos['password']) ? null : $password,
        ], 201);
    }

    /** PATCH /staff/{id} */
    public function editar(Request $request, string $staffId)
    {
        $u = DB::table('usuarios')->where('id', $staffId)
            ->whereIn('rol', self::ROLES_STAFF)->first();
        if (! $u) {
            return response()->json(['detail' => 'Miembro del equipo no encontrado'], 404);
        }

        $datos = $request->validate([
            'nombre' => 'nullable|string',
            'email' => 'nullable|email|max:255',
            'telefono' => 'nullable|string',
            'rol' => 'nullable|string',
            'cargo' => 'nullable|string',
            'es_activo' => 'nullable|boolean',
            'permisos' => 'nullable|array',
            'limpiar_permisos' => 'nullable|boolean',
        ]);

        $actor = $request->attributes->get('usuario');
        $set = [];
        $cambios = [];
        $rolFinal = $u->rol;

        if (($datos['rol'] ?? null) !== null) {
            if (! in_array($datos['rol'], self::ROLES_STAFF, true)) {
                return response()->json(['detail' => 'Rol inválido.'], 400);
            }
            if ($datos['rol'] !== $u->rol) {
                $cambios['rol'] = ['de' => $u->rol, 'a' => $datos['rol']];
            }
            $rolFinal = $datos['rol'];
            $set['rol'] = $rolFinal;
            if ($rolFinal === 'admin') {
                // Un admin no usa la columna: siempre tiene acceso total.
                $set['permisos'] = null;
            }
        }
        if (($datos['nombre'] ?? null) !== null) {
            $set['nombre'] = trim($datos['nombre']);
        }
        if (($datos['email'] ?? null) !== null && $datos['email'] !== $u->email) {
            $choca = DB::table('usuarios')->where('email', $datos['email'])
                ->where('id', '!=', $u->id)->exists();
            if ($choca) {
                return response()->json(['detail' => 'Ya existe un usuario con ese correo.'], 409);
            }
            $set['email'] = $datos['email'];
        }
        if (($datos['telefono'] ?? null) !== null) {
            $set['telefono'] = $this->telefonoONull($datos['telefono']);
        }
        if (($datos['cargo'] ?? null) !== null) {
            $set['cargo'] = $this->vacioANull($datos['cargo']);
        }
        if (($datos['es_activo'] ?? null) !== null) {
            // Nadie se desactiva a sí mismo: se quedaría fuera del sistema.
            if (! $datos['es_activo'] && (string) $u->id === (string) $actor->id) {
                return response()->json(['detail' => 'No puedes desactivar tu propia cuenta.'], 400);
            }
            $set['es_activo'] = $datos['es_activo'];
        }

        // Los permisos solo aplican al rol FINAL veterinario.
        if ($rolFinal === 'veterinario') {
            $actuales = $u->permisos === null ? null : json_decode($u->permisos, true);
            // `limpiar_permisos` distingue «no tocar» de «vaciar»: sin esa
            // bandera, un PATCH sin `permisos` y uno con `permisos: null` son
            // indistinguibles. Y NULL significa acceso total salvo auditoría.
            if (! empty($datos['limpiar_permisos'])) {
                $cambios['permisos'] = ['de' => $actuales, 'a' => null];
                $set['permisos'] = null;
            } elseif (($datos['permisos'] ?? null) !== null) {
                if ($err = $this->validarPermisos($datos['permisos'])) {
                    return $err;
                }
                $cambios['permisos'] = ['de' => $actuales, 'a' => $datos['permisos']];
                $set['permisos'] = json_encode(array_values($datos['permisos']));
            }
        }

        DB::transaction(function () use ($set, $cambios, $staffId, $actor) {
            if ($set) {
                DB::table('usuarios')->where('id', $staffId)->update($set);
            }
            if (isset($set['es_activo']) && ! $set['es_activo']) {
                Sesiones::revocar((string) $staffId);
            }
            // Solo se audita el ROL y los PERMISOS: son los cambios que
            // importan para saber quién pudo hacer qué. Cambiar un teléfono no
            // ensucia el registro.
            if ($cambios) {
                $this->auditoria->registrar($actor, 'editar', 'config_equipo',
                    'usuario', $staffId, $cambios);
            }
        });

        return $this->staffOut(DB::table('usuarios')->where('id', $staffId)->first());
    }

    /**
     * POST /staff/{id}/reset-password
     *
     * Sin cuerpo: genera una aleatoria y la devuelve UNA vez, para dictarla.
     * Con `password`: fija esa y **no la devuelve** —ya la conoce quien la
     * escribió, y hacerle eco la dejaría en el log de la respuesta.
     */
    public function resetPassword(Request $request, string $staffId)
    {
        $u = DB::table('usuarios')->where('id', $staffId)
            ->whereIn('rol', self::ROLES_STAFF)->first();
        if (! $u) {
            return response()->json(['detail' => 'Miembro del equipo no encontrado'], 404);
        }

        $elegida = trim((string) $request->input('password', ''));
        // El mínimo de 8 evita reintroducir por esta vía las «clave123» que
        // este endpoint existe para erradicar.
        if ($request->exists('password') && $request->input('password') !== null
            && strlen($elegida) < 8) {
            return response()->json(
                ['detail' => 'La clave debe tener al menos 8 caracteres.'], 422);
        }

        $nueva = $elegida !== '' ? $elegida : $this->claveAleatoria();

        DB::transaction(function () use ($u, $nueva, $elegida, $request) {
            DB::table('usuarios')->where('id', $u->id)->update([
                'password_hash' => password_hash($nueva, PASSWORD_BCRYPT, ['cost' => 12]),
            ]);
            Sesiones::revocar((string) $u->id);
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'reset_password', 'config_equipo', 'usuario', $u->id,
                ['nombre' => $u->nombre, 'elegida' => $elegida !== '']);
        });

        return ['password_temporal' => $elegida !== '' ? null : $nueva];
    }

    /** DELETE /staff/{id} */
    public function eliminar(Request $request, string $staffId)
    {
        $u = DB::table('usuarios')->where('id', $staffId)
            ->whereIn('rol', self::ROLES_STAFF)->first();
        if (! $u) {
            return response()->json(['detail' => 'Miembro del equipo no encontrado'], 404);
        }

        $actor = $request->attributes->get('usuario');
        if ((string) $u->id === (string) $actor->id) {
            return response()->json(['detail' => 'No puedes eliminar tu propia cuenta.'], 400);
        }

        // **Nunca dejar el sistema sin nadie que pueda entrar.** Sin esta
        // guarda, borrar al último activo deja la clínica fuera de su panel.
        $activos = DB::table('usuarios')->whereIn('rol', self::ROLES_STAFF)
            ->where('es_activo', true)->count();
        if ($activos <= 1) {
            return response()->json(
                ['detail' => 'Debe quedar al menos un miembro del equipo activo.'], 400);
        }

        DB::transaction(function () use ($actor, $u) {
            $this->auditoria->registrar($actor, 'eliminar', 'config_equipo', 'usuario', $u->id,
                ['nombre' => $u->nombre, 'rol' => $u->rol]);
            DB::table('usuarios')->where('id', $u->id)->delete();
        });

        return response()->noContent();
    }

    // ── internos ──────────────────────────────────────────────────────────

    private function validarPermisos(?array $permisos)
    {
        if ($permisos === null) {
            return null;
        }
        // Las claves de área son el contrato con el frontend: una inventada
        // acá deja a alguien sin acceso a una pantalla que cree tener.
        $validas = array_keys(Autenticar::AREAS);
        $desconocidas = array_values(array_diff($permisos, $validas));
        if ($desconocidas) {
            return response()->json([
                'detail' => 'Área(s) de permiso inválida(s): ' . implode(', ', $desconocidas) . '.',
            ], 400);
        }

        return null;
    }

    private function claveAleatoria(int $n = 10): string
    {
        $abc = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $s = '';
        for ($i = 0; $i < $n; $i++) {
            // `random_int` y no `rand`: es una credencial.
            $s .= $abc[random_int(0, strlen($abc) - 1)];
        }

        return $s;
    }

    /**
     * El índice único de `usuarios.telefono` es PARCIAL (`WHERE telefono IS NOT
     * NULL`): admite cualquier cantidad de NULL, pero un string vacío es un
     * valor como cualquier otro, así que el segundo miembro del equipo sin
     * teléfono chocaría contra el primero. El formulario manda '' cuando el
     * campo queda en blanco.
     */
    private function telefonoONull(?string $t): ?string
    {
        return $this->vacioANull($t);
    }

    private function vacioANull(?string $v): ?string
    {
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private function staffOut($u): array
    {
        return [
            'id' => $u->id,
            'nombre' => $u->nombre,
            'email' => $u->email,
            'telefono' => $u->telefono,
            'rol' => $u->rol,
            'cargo' => $u->cargo,
            'es_activo' => (bool) $u->es_activo,
            // NULL se conserva como NULL: significa «acceso total salvo
            // auditoría», que no es lo mismo que una lista vacía.
            'permisos' => $u->permisos === null ? null : json_decode($u->permisos, true),
            'created_at' => $this->iso($u->created_at),
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
}
