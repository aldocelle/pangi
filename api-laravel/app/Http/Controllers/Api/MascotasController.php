<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\FotosPerfil;
use App\Services\Permisos;

/**
 * Las mascotas del usuario logueado, y el listado de pacientes del staff.
 *
 * Dos audiencias en un router: `/` es del DUEÑO (solo lo suyo) y `/todas` es
 * del staff con permiso `personas` (todas, con el contacto del tutor).
 */
class MascotasController extends Controller
{
    private const LIMITE_MAX = 20;

    /** Los ordenamientos que acepta `/todas`. */
    private const ORDEN = [
        'nombre' => ['nombre', 'asc'],
        '-nombre' => ['nombre', 'desc'],
        'especie' => ['especie', 'asc'],
        '-especie' => ['especie', 'desc'],
        'peso_kg' => ['peso_kg', 'asc'],
        '-peso_kg' => ['peso_kg', 'desc'],
    ];

    /** GET /mascotas/ — solo las del usuario logueado. */
    public function mias(Request $request)
    {
        $u = $request->attributes->get('usuario');

        return DB::table('mascotas')
            ->where('usuario_id', $u->id)
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->map(fn ($m) => $this->mascotaOut($m))
            ->values();
    }

    /** POST /mascotas/ — el dueño da de alta a su propia mascota. */
    public function crear(Request $request)
    {
        $datos = $request->validate([
            'nombre' => 'required|string',
            'especie' => 'nullable|string',
            'raza' => 'nullable|string',
            'sexo' => 'nullable|string',
            'fecha_nacimiento' => 'nullable|date',
            'peso_kg' => 'nullable|numeric',
            'alergias' => 'nullable|string',
        ]);

        $u = $request->attributes->get('usuario');
        $id = (string) Str::uuid();

        DB::table('mascotas')->insert([
            'id' => $id,
            // El dueño sale de la SESIÓN, no del cuerpo: nadie puede crear una
            // mascota a nombre de otro.
            'usuario_id' => $u->id,
            'nombre' => $datos['nombre'],
            // El default vive en el schema (`especie: str = "perro"`).
            'especie' => $datos['especie'] ?? 'perro',
            'raza' => $datos['raza'] ?? null,
            'sexo' => $datos['sexo'] ?? null,
            'fecha_nacimiento' => isset($datos['fecha_nacimiento'])
                ? substr($datos['fecha_nacimiento'], 0, 10) : null,
            'peso_kg' => $datos['peso_kg'] ?? null,
            'alergias' => $datos['alergias'] ?? null,
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);

        return response()->json(
            $this->mascotaOut(DB::table('mascotas')->where('id', $id)->first()), 201);
    }

    /**
     * PATCH /mascotas/{id}
     *
     * El dueño edita la suya; el staff, cualquiera. La comprobación va adentro
     * porque la ruta la comparten las dos audiencias.
     */
    public function actualizar(Request $request, string $mascotaId)
    {
        $m = DB::table('mascotas')->where('id', $mascotaId)->first();
        if (! $m) {
            return response()->json(['detail' => 'Mascota no encontrada'], 404);
        }

        $u = $request->attributes->get('usuario');
        // El permiso, no el rol: con el rol a secas, una cuenta restringida a
        // Caja podía cambiarle el nombre, la especie o el peso a cualquier
        // mascota de la clínica.
        if ($m->usuario_id !== $u->id && ! Permisos::tiene($u, 'personas')) {
            return response()->json(['detail' => 'Sin permiso'], 403);
        }

        $reglas = [
            'nombre' => 'nullable|string',
            'especie' => 'nullable|string',
            'raza' => 'nullable|string',
            'sexo' => 'nullable|string',
            'fecha_nacimiento' => 'nullable|date',
            'peso_kg' => 'nullable|numeric',
            'alergias' => 'nullable|string',
            'microchip_codigo' => 'nullable|string',
            'esterilizado' => 'nullable|boolean',
            'estado' => 'nullable|string',
        ];
        $datos = $request->validate($reglas);

        $cambios = [];
        foreach (array_keys($reglas) as $campo) {
            if ($request->exists($campo)) {
                $cambios[$campo] = $datos[$campo] ?? null;
            }
        }
        if (array_key_exists('fecha_nacimiento', $cambios) && $cambios['fecha_nacimiento']) {
            $cambios['fecha_nacimiento'] = substr($cambios['fecha_nacimiento'], 0, 10);
        }
        if ($cambios) {
            DB::table('mascotas')->where('id', $mascotaId)->update($cambios);
        }

        return $this->mascotaOut(DB::table('mascotas')->where('id', $mascotaId)->first());
    }

    /**
     * POST /mascotas/{id}/foto — la foto de perfil de la mascota.
     *
     * Mismas dos audiencias que `actualizar`: el dueño la suya, el staff con
     * `personas` cualquiera. Se guarda en `foto_url` como `/uploads/<nombre>`,
     * la columna que el portal y el panel ya leían (vino de ACVet).
     */
    public function subirFoto(Request $request, string $mascotaId)
    {
        $m = DB::table('mascotas')->where('id', $mascotaId)->first();
        if (! $m) {
            return response()->json(['detail' => 'Mascota no encontrada'], 404);
        }
        $u = $request->attributes->get('usuario');
        if ($m->usuario_id !== $u->id && ! Permisos::tiene($u, 'personas')) {
            return response()->json(['detail' => 'Sin permiso'], 403);
        }

        [$nombre, $error] = FotosPerfil::guardar($request->file('archivo'), 'mf');
        if ($error) {
            return response()->json(['detail' => $error], 400);
        }
        DB::table('mascotas')->where('id', $mascotaId)->update(['foto_url' => "/uploads/{$nombre}"]);
        FotosPerfil::borrar($m->foto_url, 'mf');

        return $this->mascotaOut(DB::table('mascotas')->where('id', $mascotaId)->first());
    }

    /** DELETE /mascotas/{id}/foto — vuelve a la foto genérica por especie. */
    public function quitarFoto(Request $request, string $mascotaId)
    {
        $m = DB::table('mascotas')->where('id', $mascotaId)->first();
        if (! $m) {
            return response()->json(['detail' => 'Mascota no encontrada'], 404);
        }
        $u = $request->attributes->get('usuario');
        if ($m->usuario_id !== $u->id && ! Permisos::tiene($u, 'personas')) {
            return response()->json(['detail' => 'Sin permiso'], 403);
        }

        DB::table('mascotas')->where('id', $mascotaId)->update(['foto_url' => null]);
        FotosPerfil::borrar($m->foto_url, 'mf');

        return $this->mascotaOut(DB::table('mascotas')->where('id', $mascotaId)->first());
    }

    /**
     * DELETE /mascotas/{id} — el dueño borra SU mascota, y solo sin historial.
     *
     * Espejo de `eliminar_mi_mascota` en `routes/mascotas.py` de Python.
     *
     * El dueño puede crear mascotas, así que puede crear una por error y tiene
     * que poder deshacerlo sin escribirle a nadie. Lo que no puede es borrar
     * una ya atendida: ese borrado arrastra siete tablas, entre ellas
     * `fichas_clinicas` y `notas_clinicas`, que son el registro médico del
     * paciente. El equivalente para staff (`DELETE /clientes/mascotas/{id}`)
     * exige rol admin, escribir el nombre exacto y queda auditado.
     *
     * 409 y no 403 a propósito: no falta permiso, lo impide el estado de la
     * mascota. Se miran las CITAS además de la ficha porque puede haber hora
     * agendada sin ficha abierta, y borrarla dejaría un hueco en la agenda del
     * especialista sin explicación.
     */
    public function eliminarMia(Request $request, string $mascotaId)
    {
        $m = DB::table('mascotas')->where('id', $mascotaId)->first();
        if (! $m) {
            return response()->json(['detail' => 'Mascota no encontrada'], 404);
        }

        $u = $request->attributes->get('usuario');
        // Sin la excepción para staff que sí tiene `actualizar`: para eso está
        // la ruta de clientes, que audita y pide confirmación.
        if ($m->usuario_id !== $u->id) {
            return response()->json(['detail' => 'Sin permiso'], 403);
        }

        $tieneCitas = DB::table('citas')->where('mascota_id', $mascotaId)->exists();
        $tieneFicha = DB::table('fichas_clinicas')->where('mascota_id', $mascotaId)->exists();
        if ($tieneCitas || $tieneFicha) {
            return response()->json([
                'detail' => 'Esta mascota ya tiene atenciones registradas, así que su '
                    . 'ficha es parte del historial de la clínica. Escríbenos por '
                    . 'WhatsApp si necesitas darla de baja.',
            ], 409);
        }

        DB::table('mascotas')->where('id', $mascotaId)->delete();

        return response()->noContent();
    }

    /**
     * GET /mascotas/todas — el listado del staff, con el dueño y la última
     * atención.
     *
     * «Fecha de atención» es la fecha de la NOTA clínica más reciente, no
     * `mascotas.created_at` —que es cuándo se creó la ficha, no cuándo se
     * atendió—. Por eso hace falta el join.
     */
    public function todas(Request $request)
    {
        // La última nota de cada mascota, en una subconsulta agregada: traerlas
        // todas y quedarse con la mayor en memoria sería recorrer 6.687 notas
        // por página.
        $ultima = DB::table('fichas_clinicas as f')
            ->join('notas_clinicas as n', 'n.ficha_id', '=', 'f.id')
            ->select('f.mascota_id', DB::raw('MAX(n.created_at) as fecha'))
            ->groupBy('f.mascota_id');

        $q = DB::table('mascotas as m')
            ->join('usuarios as u', 'u.id', '=', 'm.usuario_id')
            ->leftJoinSub($ultima, 'ua', 'ua.mascota_id', '=', 'm.id')
            ->select('m.*', 'u.nombre as u_nombre', 'u.telefono as u_telefono',
                     'ua.fecha as ultima_atencion');

        if ($busca = trim((string) $request->query('q', ''))) {
            $q->where('m.nombre', 'like', '%' . $this->escaparLike($busca) . '%');
        }

        $especie = $request->query('especie');
        if ($especie === '__otro__') {
            // Valor especial: la base trae texto libre heredado del legado
            // (conejo, ave, hurón…), así que «Otro» NO busca la palabra literal
            // «otro» — filtra todo lo que no sea perro ni gato.
            $q->where('m.especie', 'not like', 'perro')
              ->where('m.especie', 'not like', 'gato');
        } elseif ($especie) {
            $q->where('m.especie', 'like', '%' . $this->escaparLike($especie) . '%');
        }
        if ($raza = trim((string) $request->query('raza', ''))) {
            $q->where('m.raza', 'like', '%' . $this->escaparLike($raza) . '%');
        }
        if ($dueno = trim((string) $request->query('dueno', ''))) {
            $like = '%' . $this->escaparLike($dueno) . '%';
            $q->where(fn ($w) => $w->where('u.nombre', 'like', $like)
                ->orWhere('u.email', 'like', $like));
        }

        $orden = (string) $request->query('orden', '-created_at');
        if ($orden === 'ultima_atencion' || $orden === '-ultima_atencion') {
            // NULLS LAST en AMBOS sentidos: una mascota sin atención nunca debe
            // aparecer primero solo porque «nunca» ordena antes que una fecha.
            // MariaDB no tiene `NULLS LAST`, así que va la columna auxiliar.
            $q->orderByRaw('CASE WHEN ua.fecha IS NULL THEN 1 ELSE 0 END')
              ->orderBy('ua.fecha', $orden === '-ultima_atencion' ? 'desc' : 'asc');
        } elseif (isset(self::ORDEN[$orden])) {
            [$col, $dir] = self::ORDEN[$orden];
            // Los ordenamientos de TEXTO van por la clave normalizada: sin eso
            // Postgres pone 'Gato' antes que 'conejo' —ordena las mayúsculas
            // primero— y MariaDB no, porque su colación es case-insensitive.
            // El peso es numérico y no pasa por acá.
            if ($col === 'peso_kg') {
                // Los NULL al FINAL en los dos sentidos, como Postgres:
                // MariaDB los pone primero en ASC, y son 2.545 de 3.485 —una
                // mascota sin peso registrado encabezaría la lista de «los más
                // livianos», que no dice nada.
                $q->orderByRaw("CASE WHEN m.{$col} IS NULL THEN 1 ELSE 0 END")
                  ->orderBy("m.{$col}", $dir);
            } else {
                $q->orderByRaw("REGEXP_REPLACE(LOWER(m.{$col}), '[^a-z0-9]', '') {$dir}");
            }
        } else {
            $q->orderBy('m.created_at', 'desc');
        }
        $q->orderBy('m.id');

        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min((int) $request->query('limit', self::LIMITE_MAX), self::LIMITE_MAX));
        $total = (clone $q)->reorder()->count();
        $filas = $q->offset(($page - 1) * $limit)->limit($limit)->get();

        return [
            'items' => $filas->map(fn ($m) => [
                ...$this->mascotaOut($m),
                'dueno_nombre' => $m->u_nombre,
                'dueno_telefono' => $m->u_telefono,
                'ultima_atencion' => $this->iso($m->ultima_atencion),
            ])->values(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_paginas' => max(1, (int) ceil($total / $limit)),
        ];
    }

    private function mascotaOut($m): array
    {
        return [
            'nombre' => $m->nombre,
            'especie' => $m->especie,
            'raza' => $m->raza,
            'sexo' => $m->sexo,
            'fecha_nacimiento' => $m->fecha_nacimiento
                ? substr($m->fecha_nacimiento, 0, 10) : null,
            // **String, no float**: `MascotaResponse` declara `peso_kg` como
            // Decimal y Pydantic lo serializa como texto conservando la escala
            // («7.90»). Es el mismo caso que en `/fichas/{id}/paciente`, y el
            // contrario que en `/clientes`, donde `_mascota_out` hace `float()`
            // a mano. Manda el tipo DECLARADO en cada sitio.
            'peso_kg' => $m->peso_kg === null ? null : (string) $m->peso_kg,
            'alergias' => $m->alergias,
            'id' => $m->id,
            'usuario_id' => $m->usuario_id,
            'created_at' => $this->iso($m->created_at),
            'microchip_codigo' => $m->microchip_codigo,
            // Campos del schema que no son columnas: salen siempre, aunque
            // valgan null.
            'foto_url' => $m->foto_url ?? null,
            'esterilizado' => $m->esterilizado === null ? null : (bool) $m->esterilizado,
            'estado' => $m->estado,
            'dueno_nombre' => null,
            'dueno_email' => null,
            'dueno_telefono' => null,
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

    private function escaparLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }
}
