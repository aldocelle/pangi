<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Services\Esquema;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Importador incremental de los CSV de respaldo de ACVet (el sistema anterior).
 * Es el espejo de «Exportar por tipo de dato» y el port de `importar_acvet.py`.
 *
 * **El navegador lee el CSV y lo manda por lotes**, igual que el export baja por
 * páginas: el hosting compartido corta un request largo y 15.346 ventas de una
 * vez no caben. Acá cada request trae a lo más {@see self::LOTE_MAX} filas ya
 * convertidas a objetos.
 *
 * **Idempotente por `id_legado`**, como el script de Python: volver a subir el
 * mismo archivo no duplica nada.
 *
 * **Por defecto solo agrega lo que falta (`actualizar = false`).** El script de
 * Python pisaba siempre. Con el sistema nuevo ya en uso eso es peligroso: el
 * stock de un producto, el teléfono que la clínica corrigió o el nombre de una
 * mascota se habrían vuelto a lo que dice ACVet. Pisar es una opción explícita.
 *
 * **Vista previa (`simular`)**: se corre de verdad dentro de una transacción y se
 * revierte, así que las cifras son las que daría el import real y no una
 * estimación aparte. Nada queda escrito.
 *
 * Lo que no se importa a propósito, y por qué:
 * - La agenda histórica: son 343 filas de ACVet contra las citas del sistema
 *   nuevo; no son comparables y mezclarlas ensuciaría la Agenda.
 * - Ventas cuya transacción no existe: una línea huérfana infla Estadísticas.
 *   Se cuentan como `sin_relacion`; al importar antes las transacciones, entran.
 * - Filas con `eliminado = 1`.
 */
class ImportacionController extends Controller
{
    private const LOTE_MAX = 300;

    /** Orden recomendado: cada tipo depende de los anteriores. */
    private const TIPOS = [
        'propietarios' => ['titulo' => 'Propietarios', 'archivo' => 'propietarios_respaldo.csv',
            'detalle' => 'Tutores: contacto, RUT y puntos. Si el correo ya existe, se vincula en vez de duplicar.',
            'requiere' => ['id']],
        'mascotas' => ['titulo' => 'Mascotas', 'archivo' => 'mascotas_respaldo.csv',
            'detalle' => 'Pacientes. Necesita sus propietarios ya importados.',
            'requiere' => ['id', 'propietario_id', 'fullname']],
        'inventario' => ['titulo' => 'Inventario', 'archivo' => 'inventario_respaldo.csv',
            'detalle' => 'Catálogo de productos con precios, stock y lote.',
            'requiere' => ['id', 'nombre']],
        'casos_clinicos' => ['titulo' => 'Casos clínicos', 'archivo' => 'casos_respaldo.csv',
            'detalle' => 'Notas clínicas de la ficha. Necesita sus mascotas ya importadas.',
            'requiere' => ['id', 'mascota_id']],
        'transacciones' => ['titulo' => 'Transacciones', 'archivo' => 'transacciones_respaldo.csv',
            'detalle' => 'Cobros (cabecera de cada venta). Se importan fuera de la caja del sistema nuevo.',
            'requiere' => ['id', 'fecha']],
        'ventas' => ['titulo' => 'Ventas', 'archivo' => 'ventas_respaldo.csv',
            'detalle' => 'Líneas de venta. Necesita sus transacciones ya importadas.',
            'requiere' => ['id', 'transaccion_id', 'nombre']],
    ];

    private bool $simulando = false;

    private const CLAVE_ALIAS = 'importacion.alias_propietarios';

    private const FORMATOS_FECHA = ['d/m/Y H:i', 'd/m/Y H:i:s', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d'];

    public function __construct(private Auditoria $auditoria) {}

    /** Cuenta del equipo a la que se atribuyen las notas de este lote (ver `cuentaParaNotas`). */
    private ?string $atribuirA = null;

    /**
     * Las cuentas del equipo que pueden firmar las notas importadas: activas y
     * con rol de equipo. Una desactivada no entra nunca — antes el import podía
     * caer en cualquiera, incluida una de prueba.
     */
    private function cuentasDelEquipo()
    {
        return DB::table('usuarios')->whereIn('rol', ['admin', 'veterinario'])
            ->where('es_activo', 1)
            ->orderBy('nombre')->orderBy('id')
            ->get(['id', 'nombre', 'email', 'rol']);
    }

    /**
     * A quién se atribuyen las notas de ACVet (`notas_clinicas.veterinario_id`,
     * NOT NULL). ACVet no distingue staff de especialista, así que el dato es
     * técnico: quién atendió de verdad va en `especialista_id`, que es lo que
     * muestra la ficha. Aun así no se adivina: antes caía en «el primer admin»
     * por orden de id, o sea al azar, y las 7.119 notas del import anterior
     * quedaron a nombre de admin@clinica.test, una cuenta de prueba de un dominio
     * que no es de la clínica. Con una sola cuenta activa no hay nada que
     * elegir; con más, se exige la elección.
     *
     * @return string|\Illuminate\Http\JsonResponse el id elegido, o el 422
     */
    private function cuentaParaNotas(?string $elegida)
    {
        $cuentas = $this->cuentasDelEquipo();
        if ($elegida !== null && $elegida !== '') {
            if (! $cuentas->contains('id', $elegida)) {
                return response()->json(['detail' =>
                    'Esa cuenta no es del equipo o está desactivada: elige otra para atribuir las notas.'], 422);
            }
            return $elegida;
        }
        if ($cuentas->count() === 1) {
            return (string) $cuentas->first()->id;
        }
        return response()->json(['detail' => $cuentas->isEmpty()
            ? 'No hay ninguna cuenta activa del equipo (admin o veterinario) a la que atribuir las notas.'
            : 'Elige a qué cuenta del equipo se atribuyen las notas importadas.'], 422);
    }

    /** GET /importacion/tipos — qué se puede importar, en el orden recomendado. */
    public function tipos()
    {
        $out = [];
        foreach (self::TIPOS as $clave => $t) {
            $tipo = ['clave' => $clave, 'titulo' => $t['titulo'], 'archivo' => $t['archivo'],
                'detalle' => $t['detalle'], 'requiere' => $t['requiere'], 'lote' => self::LOTE_MAX];
            // Las notas necesitan una cuenta del equipo que las firme: la
            // pantalla ofrece estas y exige elegir si hay más de una.
            if ($clave === 'casos_clinicos') {
                $tipo['cuentas'] = $this->cuentasDelEquipo()->values()->all();
            }
            $out[] = $tipo;
        }
        return $out;
    }

    /**
     * POST /importacion/{tipo} — un lote. Cuerpo: `filas` (objetos columna→valor),
     * `simular`, `actualizar`, y `primero`/`archivo` en el primer lote del archivo
     * (es lo que deja la fila de auditoría).
     */
    public function lote(Request $request, string $tipo)
    {
        $def = self::TIPOS[$tipo] ?? null;
        if (! $def) {
            return response()->json(['detail' => 'Ese tipo de dato no existe.'], 404);
        }
        $datos = $request->validate([
            'filas' => 'required|array|max:' . self::LOTE_MAX,
            'simular' => 'sometimes|boolean',
            'actualizar' => 'sometimes|boolean',
            'primero' => 'sometimes|boolean',
            'archivo' => 'sometimes|string|max:200',
            'total_filas' => 'sometimes|integer|min:0',
            'atribuir_a' => 'sometimes|nullable|string|max:36',
        ]);
        $simular = (bool) ($datos['simular'] ?? false);
        $this->simulando = $simular;
        $actualizar = (bool) ($datos['actualizar'] ?? false);
        $filas = array_values(array_filter($datos['filas'], 'is_array'));

        // Una columna que falta casi siempre es el archivo equivocado: se corta
        // acá, con un mensaje, y no con 300 filas «omitidas» sin explicación.
        if ($filas && ($falta = array_values(array_diff($def['requiere'], array_keys($filas[0]))))) {
            return response()->json(['detail' => 'Este archivo no parece ser «' . $def['titulo']
                . '»: le faltan las columnas ' . implode(', ', $falta) . '.'], 422);
        }

        // Se resuelve ANTES de la transacción: si falta la elección, se dice
        // con un 422 y no con un lote «fallido» a medio camino.
        if ($tipo === 'casos_clinicos') {
            $cuenta = $this->cuentaParaNotas($datos['atribuir_a'] ?? null);
            if (! is_string($cuenta)) {
                return $cuenta;
            }
            $this->atribuirA = $cuenta;
        }

        $r = ['creados' => 0, 'actualizados' => 0, 'omitidos' => 0, 'sin_relacion' => 0,
            'avisos' => [], 'errores' => []];

        DB::beginTransaction();
        try {
            // Auditoría ANTES de escribir, dentro de la transacción: si la
            // importación revienta, no queda una fila describiendo algo que no pasó.
            if (($datos['primero'] ?? false) && ! $simular) {
                $this->auditoria->registrar($request->attributes->get('usuario'),
                    'importar', 'auditoria', 'dataset', $tipo, [
                        'archivo' => $datos['archivo'] ?? $def['archivo'],
                        'filas' => $datos['total_filas'] ?? count($filas),
                        'actualizar_existentes' => $actualizar,
                    ] + ($this->atribuirA ? ['notas_atribuidas_a' => $this->atribuirA] : []));
            }
            $this->{'importar' . Str::studly($tipo)}($filas, $actualizar, $r);
            $simular ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['detail' => 'El lote falló y no se guardó nada de él: '
                . mb_substr($e->getMessage(), 0, 300)], 500);
        }

        return $r + ['simulado' => $simular];
    }

    // ── propietarios ────────────────────────────────────────────────────────

    private function importarPropietarios(array $filas, bool $actualizar, array &$r): void
    {
        $ids = $this->col($filas, 'id');
        $porLegado = DB::table('usuarios')->whereIn('id_legado', $ids)->get()->keyBy('id_legado');
        $correos = array_values(array_filter(array_map(fn ($f) => $this->correo($f['email'] ?? null), $filas)));
        $porCorreo = $correos
            ? DB::table('usuarios')->whereIn('email', $correos)->get()->keyBy(fn ($u) => mb_strtolower($u->email))
            : collect();
        $telefonos = array_values(array_filter(array_map(fn ($f) => $this->texto($f['celular'] ?? null) ?? $this->texto($f['telefono'] ?? null), $filas)));
        $telTomado = $telefonos ? DB::table('usuarios')->whereIn('telefono', $telefonos)->get()->keyBy('telefono') : collect();

        $clave = Hash::make(Str::random(24));   // una por lote: nadie la conoce, cada cuenta se resetea
        $alias = $this->aliasGuardado();
        $vistosCorreo = [];
        $vistosTel = [];
        $ahora = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

        foreach ($filas as $f) {
            $idLegado = $this->texto($f['id'] ?? null);
            if (! $idLegado) { $r['omitidos']++; continue; }
            $correo = $this->correo($f['email'] ?? null);

            // Misma persona con dos fichas en ACVet. El cliente marca la segunda
            // con `dup_de`; la fila no crea nada, solo deja un alias para que sus
            // mascotas encuentren al dueño.
            $dupDe = $this->texto($f['dup_de'] ?? null);
            if ($dupDe && ! isset($porLegado[$idLegado])) {
                $alias[$idLegado] = $alias[$dupDe] ?? $dupDe;
                $r['omitidos']++;
                $r['fusionados'] = ($r['fusionados'] ?? 0) + 1;
                continue;
            }

            $tel = $this->texto($f['celular'] ?? null) ?? $this->texto($f['telefono'] ?? null);
            $actual = $porLegado[$idLegado] ?? ($correo ? ($porCorreo[$correo] ?? null) : null);
            $otro = $tel ? ($telTomado[$tel] ?? null) : null;
            $telRepetido = $tel && (($otro && $otro->id !== ($actual->id ?? null))
                || (isset($vistosTel[$tel]) && $vistosTel[$tel] !== $idLegado));
            if ($telRepetido) {
                $tel = null;   // el teléfono es único en la base: el de otro tutor no se le quita
            }

            $campos = [
                'nombre' => mb_substr($this->texto($f['nombre'] ?? null) ?? 'Sin nombre', 0, 255),
                'telefono' => $tel ? mb_substr($tel, 0, 60) : null,
                'rut' => $this->recortar($f['rut'] ?? null, 20),
                'direccion' => $this->recortar($f['direccion'] ?? null, 300),
                'ciudad' => $this->recortar($f['ciudad'] ?? null, 120),
                'region' => $this->recortar($f['region'] ?? null, 120),
                'puntos_fidelizacion' => $this->entero($f['puntos'] ?? null),
            ];

            if ($actual) {
                $propio = ($actual->id_legado ?? null) === $idLegado;
                if (($actual->id_legado ?? null) && ! $propio) {
                    // Otro id de ACVet con el correo de alguien ya vinculado: misma persona.
                    $alias[$idLegado] = $actual->id_legado;
                    $r['omitidos']++;
                    continue;
                }
                if (! ($actual->id_legado ?? null)) {
                    // Cuenta del sistema nuevo con el mismo correo: se vincula, no se duplica.
                    DB::table('usuarios')->where('id', $actual->id)->update(['id_legado' => $idLegado]);
                    $r['vinculados'] = ($r['vinculados'] ?? 0) + 1;
                }
                if ($actualizar) {
                    DB::table('usuarios')->where('id', $actual->id)->update($campos + ['updated_at' => $ahora]);
                    $r['actualizados']++;
                    $this->contarTelefono($r, $telRepetido, $tel, $vistosTel, $idLegado);
                } elseif ($propio) {
                    $r['omitidos']++;
                }
                continue;
            }

            if ($correo && isset($vistosCorreo[$correo])) {
                $alias[$idLegado] = $vistosCorreo[$correo];
                $r['omitidos']++;
                continue;
            }
            DB::table('usuarios')->insert($campos + [
                'id' => (string) Str::uuid(),
                'email' => $correo ?? "legado-{$idLegado}@sin-correo.local",
                'password_hash' => $clave,
                'rol' => 'dueño',
                'es_activo' => 1,
                'id_legado' => $idLegado,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            if ($correo) { $vistosCorreo[$correo] = $idLegado; }
            $r['creados']++;
            $this->contarTelefono($r, $telRepetido, $tel, $vistosTel, $idLegado);
        }
        if (! $this->simulando) {
            $this->guardarAlias($alias);
        }
    }

    /**
     * Anota el teléfono de una fila que SE ESCRIBE. El aviso «teléfono repetido»
     * solo cuenta acá: en una fila que se salta (ya existía, fusión) el teléfono
     * no se toca, y contarla inflaba la cifra de la vista previa (47 reportados
     * cuando muchos eran tutores que ya estaban cargados).
     */
    private function contarTelefono(array &$r, bool $repetido, ?string $tel, array &$vistos, string $idLegado): void
    {
        if ($repetido) {
            $r['avisos']['telefono_repetido'] = ($r['avisos']['telefono_repetido'] ?? 0) + 1;
        } elseif ($tel) {
            $vistos[$tel] = $idLegado;
        }
    }

    // ── mascotas ────────────────────────────────────────────────────────────

    private function importarMascotas(array $filas, bool $actualizar, array &$r): void
    {
        $alias = $this->aliasGuardado();
        $propIds = array_values(array_unique(array_map(
            fn ($f) => $alias[$this->texto($f['propietario_id'] ?? null)] ?? $this->texto($f['propietario_id'] ?? null), $filas)));
        $duenos = DB::table('usuarios')->whereIn('id_legado', array_filter($propIds))->pluck('id', 'id_legado');
        $existentes = DB::table('mascotas')->whereIn('id_legado', $this->col($filas, 'id'))->pluck('id', 'id_legado');
        $ahora = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $especies = ['Canino' => 'perro', 'Felino' => 'gato'];
        $sexos = ['Macho' => 'M', 'Hembra' => 'F'];

        foreach ($filas as $f) {
            $idLegado = $this->texto($f['id'] ?? null);
            if (! $idLegado) { $r['omitidos']++; continue; }
            $prop = $this->texto($f['propietario_id'] ?? null);
            $dueno = $duenos[$alias[$prop] ?? $prop] ?? null;
            $existe = $existentes[$idLegado] ?? null;
            if (! $dueno && ! $existe) { $r['sin_relacion']++; continue; }
            if ($existe && ! $actualizar) { $r['omitidos']++; continue; }

            $especie = $this->texto($f['especie'] ?? null);
            $nac = $this->fecha($f['fecha_nacimiento'] ?? null);
            $peso = $this->decimal($f['peso'] ?? null);
            $fall = $this->fecha($f['fecha_fallecido'] ?? null);
            $campos = [
                'nombre' => mb_substr($this->texto($f['fullname'] ?? null) ?? 'Sin nombre', 0, 255),
                'especie' => $especie ? ($especies[$especie] ?? mb_substr(mb_strtolower($especie), 0, 50)) : null,
                'raza' => $this->recortar($f['raza'] ?? null, 100),
                'sexo' => $sexos[$this->texto($f['sexo'] ?? null) ?? ''] ?? null,
                'fecha_nacimiento' => $nac?->format('Y-m-d'),
                'peso_kg' => ($peso !== null && $peso >= 0 && $peso < 1000) ? $peso : null,   // DECIMAL(5,2)
                'microchip_codigo' => $this->recortar($f['microchip_codigo'] ?? null, 50),
                'foto_url' => $this->recortar($f['foto_url'] ?? null, 500),
                'esterilizado' => $this->texto($f['esterilizado'] ?? null) === '1' ? 1 : 0,
                'estado' => mb_substr($this->texto($f['estado'] ?? null) ?? 'Vivo', 0, 20),
                'fecha_fallecido' => $fall?->format('Y-m-d'),
            ];
            if ($existe) {
                DB::table('mascotas')->where('id', $existe)->update($campos + ['updated_at' => $ahora]);
                $r['actualizados']++;
            } else {
                DB::table('mascotas')->insert($campos + ['id' => (string) Str::uuid(), 'usuario_id' => $dueno,
                    'id_legado' => $idLegado, 'created_at' => $ahora, 'updated_at' => $ahora]);
                $r['creados']++;
            }
        }
    }

    // ── inventario ──────────────────────────────────────────────────────────

    private function importarInventario(array $filas, bool $actualizar, array &$r): void
    {
        $existentes = DB::table('items_inventario')->whereIn('id_legado', $this->col($filas, 'id'))->pluck('id', 'id_legado');
        $ahora = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

        foreach ($filas as $f) {
            $idLegado = $this->texto($f['id'] ?? null);
            if (! $idLegado) { $r['omitidos']++; continue; }
            $existe = $existentes[$idLegado] ?? null;
            if ($existe && ! $actualizar) { $r['omitidos']++; continue; }

            $venc = $this->fecha($f['fecha_vencimiento'] ?? null);
            // Suma de los tres canales de stock virtual, como el script de Python.
            $stock = $this->entero($f['stock_virtual_bodega'] ?? null) + $this->entero($f['stock_virtual_farmacia'] ?? null)
                + $this->entero($f['stock_virtual_hospital'] ?? null);
            $campos = [
                'nombre' => mb_substr($this->texto($f['nombre'] ?? null) ?? 'Sin nombre', 0, 255),
                'descripcion' => $this->recortar($f['descripcion'] ?? null, 500),
                'tipo' => mb_substr($this->texto($f['tipo'] ?? null) ?? 'Otro', 0, 50),
                'unidad' => $this->recortar($f['unidad'] ?? null, 30),
                'precio_costo' => $this->decimal($f['precio_costo'] ?? null) ?? 0,
                'precio_venta' => $this->decimal($f['precio_venta'] ?? null) ?? 0,
                'stock_actual' => $stock ?: $this->entero($f['stock_virtual'] ?? null),
                'stock_minimo' => $this->entero($f['punto_reorden'] ?? null),
                'lote' => $this->recortar($f['lote'] ?? null, 100),
                'fecha_vencimiento' => $venc?->format('Y-m-d'),
                'activo' => 1,
            ];
            if ($existe) {
                DB::table('items_inventario')->where('id', $existe)->update($campos + ['updated_at' => $ahora]);
                $r['actualizados']++;
            } else {
                DB::table('items_inventario')->insert($campos + ['id' => (string) Str::uuid(),
                    'id_legado' => $idLegado, 'created_at' => $ahora, 'updated_at' => $ahora]);
                $r['creados']++;
            }
        }
    }

    // ── casos clínicos ──────────────────────────────────────────────────────

    private function importarCasosClinicos(array $filas, bool $actualizar, array &$r): void
    {
        $mascotas = DB::table('mascotas')->whereIn('id_legado', $this->col($filas, 'mascota_id'))->pluck('id', 'id_legado');
        $fichas = DB::table('fichas_clinicas')->whereIn('mascota_id', $mascotas->values()->all())->pluck('id', 'mascota_id');
        $existentes = DB::table('notas_clinicas')->whereIn('id_legado', $this->col($filas, 'id'))->pluck('id', 'id_legado');

        // La cuenta la resolvió `lote()` con `cuentaParaNotas`: elegida, o la
        // única activa del equipo. Nunca se adivina acá.
        $staff = $this->atribuirA;
        if (! $staff) {
            throw new \RuntimeException('Falta la cuenta del equipo a la que atribuir las notas.');
        }
        $medicos = [];
        $ahora = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $conSanitario = Esquema::columna('notas_clinicas', 'sanitario');

        foreach ($filas as $f) {
            $idLegado = $this->texto($f['id'] ?? null);
            if (! $idLegado) { $r['omitidos']++; continue; }
            $mascota = $mascotas[$this->texto($f['mascota_id'] ?? null) ?? ''] ?? null;
            if (! $mascota) { $r['sin_relacion']++; continue; }
            $existe = $existentes[$idLegado] ?? null;
            if ($existe && ! $actualizar) { $r['omitidos']++; continue; }

            $ficha = $fichas[$mascota] ?? null;
            if (! $ficha) {
                $ficha = (string) Str::uuid();
                DB::table('fichas_clinicas')->insert(['id' => $ficha, 'mascota_id' => $mascota,
                    'created_at' => $ahora, 'updated_at' => $ahora]);
                $fichas[$mascota] = $ficha;
            }

            $signos = [];
            foreach (['fc' => 'FC', 'fr' => 'FR', 'temp' => 'Temp', 'trc' => 'TRC', 'pam' => 'PAM', 'pcm' => 'PCM', 'pd' => 'PD'] as $c => $et) {
                if ($v = $this->texto($f[$c] ?? null)) { $signos[] = "{$et}: {$v}"; }
            }
            $examen = implode("\n", array_filter([
                $this->texto($f['hallazgos'] ?? null), $this->texto($f['examenes'] ?? null), implode(', ', $signos)]));
            $fecha = $this->fecha($f['fecha'] ?? null);
            $campos = [
                'especialista_id' => $this->especialista($medicos, $this->texto($f['medico_id'] ?? null)),
                'motivo' => mb_substr($this->texto($f['descripcion'] ?? null) ?? 'Consulta', 0, 255),
                'examen' => $examen ?: null,
                'diagnostico' => $this->texto($f['diagnostico'] ?? null) ?? $this->texto($f['prediagnostico'] ?? null),
                'tratamiento' => $this->texto($f['tratamiento'] ?? null),
                'indicaciones' => $this->texto($f['receta'] ?? null),
            ];
            if ($conSanitario) {
                $san = implode(', ', array_filter([$this->texto($f['sanitario'] ?? null), $this->texto($f['sanitario_otros'] ?? null)]));
                $campos['sanitario'] = $san ?: null;
            }
            if ($existe) {
                DB::table('notas_clinicas')->where('id', $existe)->update($campos);
                $r['actualizados']++;
            } else {
                DB::table('notas_clinicas')->insert($campos + ['id' => (string) Str::uuid(), 'ficha_id' => $ficha,
                    'veterinario_id' => $staff, 'id_legado' => $idLegado,
                    'created_at' => $fecha ? $fecha->format('Y-m-d H:i:s.u') : $ahora]);
                $r['creados']++;
            }
        }
    }

    // ── transacciones ───────────────────────────────────────────────────────

    private function importarTransacciones(array $filas, bool $actualizar, array &$r): void
    {
        $alias = $this->aliasGuardado();
        $props = array_values(array_unique(array_filter(array_map(
            fn ($f) => $alias[$this->texto($f['propietario_id'] ?? null) ?? ''] ?? $this->texto($f['propietario_id'] ?? null), $filas))));
        $duenos = DB::table('usuarios')->whereIn('id_legado', $props)->pluck('id', 'id_legado');
        $existentes = DB::table('transacciones')->whereIn('id_legado', $this->col($filas, 'id'))->pluck('id', 'id_legado');
        $medicos = [];
        $ahora = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

        foreach ($filas as $f) {
            $idLegado = $this->texto($f['id'] ?? null);
            if (! $idLegado || $this->texto($f['eliminado'] ?? null) === '1') { $r['omitidos']++; continue; }
            $existe = $existentes[$idLegado] ?? null;
            if ($existe && ! $actualizar) { $r['omitidos']++; continue; }

            $prop = $this->texto($f['propietario_id'] ?? null);
            $campos = [
                'propietario_id' => $duenos[$alias[$prop] ?? $prop] ?? null,
                'especialista_id' => $this->especialista($medicos, $this->texto($f['medico_id'] ?? null)),
                'fecha' => ($this->fecha($f['fecha'] ?? null) ?? CarbonImmutable::now())->format('Y-m-d H:i:s'),
                'documento_id' => $this->recortar($f['documento_id'] ?? null, 500),
                'pago_efectivo' => $this->decimal($f['pago_efectivo'] ?? null) ?? 0,
                'pago_debito' => $this->decimal($f['pago_debito'] ?? null) ?? 0,
                'pago_credito' => $this->decimal($f['pago_credito'] ?? null) ?? 0,
                'pago_transferencia' => $this->decimal($f['pago_transferencia'] ?? null) ?? 0,
                'pago_otro' => $this->decimal($f['pago_otro'] ?? null) ?? 0,
                'total_pendiente' => $this->decimal($f['total_pendiente'] ?? null) ?? 0,
            ];
            if ($existe) {
                DB::table('transacciones')->where('id', $existe)->update($campos);
                $r['actualizados']++;
            } else {
                // Se cobró en ACVet, no en la caja de acá: sin esto, una venta de
                // ayer importada entraría al cierre de una sesión de caja abierta.
                $extra = Esquema::columna('transacciones', 'cuenta_en_caja') ? ['cuenta_en_caja' => 0] : [];
                DB::table('transacciones')->insert($campos + $extra + ['id' => (string) Str::uuid(),
                    'id_legado' => $idLegado, 'created_at' => $ahora]);
                $r['creados']++;
            }
        }
    }

    // ── ventas (líneas) ─────────────────────────────────────────────────────

    private function importarVentas(array $filas, bool $actualizar, array &$r): void
    {
        $alias = $this->aliasGuardado();
        $trans = DB::table('transacciones')->whereIn('id_legado', $this->col($filas, 'transaccion_id'))->pluck('id', 'id_legado');
        $items = DB::table('items_inventario')->whereIn('id_legado', $this->col($filas, 'item_id'))->pluck('id', 'id_legado');
        $props = array_values(array_unique(array_filter(array_map(
            fn ($f) => $alias[$this->texto($f['propietario_id'] ?? null) ?? ''] ?? $this->texto($f['propietario_id'] ?? null), $filas))));
        $duenos = DB::table('usuarios')->whereIn('id_legado', $props)->pluck('id', 'id_legado');
        $existentes = DB::table('ventas')->whereIn('id_legado', $this->col($filas, 'id'))->pluck('id', 'id_legado');
        $medicos = [];
        $ahora = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

        foreach ($filas as $f) {
            $idLegado = $this->texto($f['id'] ?? null);
            if (! $idLegado || $this->texto($f['eliminado'] ?? null) === '1') { $r['omitidos']++; continue; }
            $existe = $existentes[$idLegado] ?? null;
            if ($existe && ! $actualizar) { $r['omitidos']++; continue; }
            $transaccion = $trans[$this->texto($f['transaccion_id'] ?? null) ?? ''] ?? null;
            if (! $transaccion && ! $existe) { $r['sin_relacion']++; continue; }

            $prop = $this->texto($f['propietario_id'] ?? null);
            $campos = [
                'transaccion_id' => $transaccion,
                'item_id' => $items[$this->texto($f['item_id'] ?? null) ?? ''] ?? null,
                'propietario_id' => $duenos[$alias[$prop] ?? $prop] ?? null,
                'especialista_id' => $this->especialista($medicos, $this->texto($f['medico_id'] ?? null)),
                'fecha' => ($this->fecha($f['fecha'] ?? null) ?? CarbonImmutable::now())->format('Y-m-d H:i:s'),
                'nombre' => mb_substr($this->texto($f['nombre'] ?? null) ?? 'Sin nombre', 0, 255),
                'tipo' => $this->recortar($f['tipo'] ?? null, 50),
                'cantidad' => $this->entero($f['cantidad'] ?? null, 1),
                'precio_costo' => $this->decimal($f['precio_costo_actual'] ?? null) ?? 0,
                'precio_venta' => $this->decimal($f['precio_venta'] ?? null) ?? 0,
                'descuento' => $this->decimal($f['descuento'] ?? null) ?? 0,
            ];
            if ($existe) {
                DB::table('ventas')->where('id', $existe)->update($campos);
                $r['actualizados']++;
            } else {
                DB::table('ventas')->insert($campos + ['id' => (string) Str::uuid(),
                    'id_legado' => $idLegado, 'created_at' => $ahora]);
                $r['creados']++;
            }
        }
    }

    // ── utilidades ──────────────────────────────────────────────────────────

    /**
     * El especialista que corresponde a un código de médico de ACVet. El CSV no
     * trae nombres, así que se crea «Médico #123 (ACVet)» INACTIVO —no aparece en
     * el sitio ni en las reservas— para no perder quién atendió; la clínica lo
     * renombra y lo activa si corresponde. Igual que el script de Python.
     */
    private function especialista(array &$cache, ?string $medicoId): ?string
    {
        if (! $medicoId || $medicoId === '0') { return null; }
        if (isset($cache[$medicoId])) { return $cache[$medicoId]; }
        $nombre = "Médico #{$medicoId} (ACVet)";
        $id = DB::table('especialistas')->where('nombre', $nombre)->value('id');
        if (! $id) {
            $id = (string) Str::uuid();
            DB::table('especialistas')->insert(['id' => $id, 'nombre' => $nombre, 'activo' => 0]
                + (Esquema::columna('especialistas', 'reservable') ? ['reservable' => 0] : []));
        }
        return $cache[$medicoId] = $id;
    }

    /**
     * El alias «id duplicado de ACVet → id canónico» pasa de un archivo al
     * siguiente (propietarios → mascotas). Si el caché no responde se sigue sin
     * él: las mascotas de una ficha duplicada se cuentan `sin_relacion` y se
     * pueden volver a importar, que es mejor que tumbar el lote.
     */
    private function aliasGuardado(): array
    {
        try {
            return Cache::get(self::CLAVE_ALIAS, []);
        } catch (\Throwable) {
            return [];
        }
    }

    private function guardarAlias(array $alias): void
    {
        try {
            Cache::forever(self::CLAVE_ALIAS, $alias);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function col(array $filas, string $c): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($f) => $this->texto($f[$c] ?? null), $filas))));
    }

    private function texto($v): ?string
    {
        if ($v === null || is_array($v)) { return null; }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private function recortar($v, int $max): ?string
    {
        $t = $this->texto($v);
        return $t === null ? null : mb_substr($t, 0, $max);
    }

    private function correo($v): ?string
    {
        $t = $this->texto($v);
        return $t === null ? null : mb_strtolower($t);
    }

    private function entero($v, int $defecto = 0): int
    {
        $t = $this->texto($v);
        return ($t !== null && is_numeric($t)) ? (int) round((float) $t) : $defecto;
    }

    private function decimal($v): ?float
    {
        $t = $this->texto($v);
        return ($t !== null && is_numeric($t)) ? (float) $t : null;
    }

    private function fecha($v): ?CarbonImmutable
    {
        $t = $this->texto($v);
        if ($t === null) { return null; }
        foreach (self::FORMATOS_FECHA as $fmt) {
            $d = CarbonImmutable::createFromFormat('!' . $fmt, $t);
            if ($d !== false && $d->format($fmt) === $t) { return $d; }
        }
        return null;
    }
}
