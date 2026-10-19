<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CobroService;
use App\Services\RespuestaDirecta;
use App\Services\UsoAsistente;
use Illuminate\Http\Request;
use App\Services\Auditoria;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Catálogo público: servicios y especialistas.
 *
 * Es lo primero que se porta porque es lo que deja el sitio y el portal VACÍOS
 * si falla — ver el orden de ataque de la Fase 3.
 *
 * Se usa el Query Builder y no Eloquent, a propósito y por dos razones:
 *
 * 1. **Los modelos no aportan nada acá.** Esto es un SELECT y una
 *    serialización; un modelo Eloquent solo agregaría una capa que puede
 *    castear de más.
 *
 * 2. **Eloquent castea las fechas a Carbon**, y al serializarlas les pone
 *    sufijo de zona. Cualquier endpoint cuyo JSON gane una `Z` o un `-03:00`
 *    rompe los frontends aunque el instante sea correcto — es una de las
 *    reglas explícitas de la Fase 3. Este endpoint no devuelve fechas, pero la
 *    costumbre se establece desde el primero.
 */
class CatalogoController extends Controller
{
    /**
     * La regla de cobro vive en un solo sitio y se inyecta: el catálogo, la
     * reserva y /pagos tienen que contestar lo MISMO o el portal muestra una
     * cifra y se cobra otra.
     */
    public function __construct(
        private CobroService $cobro,
        private Auditoria $auditoria,
    ) {
    }

    /**
     * GET /catalogo/servicios — sin token.
     *
     * Contrato exacto de `_svc_out()` + `listar_servicios()` en
     * `backend/app/routes/catalogo.py`. Lo verifica el arnés diferencial
     * comparando este JSON contra el de Python, campo por campo.
     */
    public function servicios()
    {
        // El orden termina en la clave primaria, como TODO `order_by` de este
        // proyecto (§9.14): `paginar()` hace OFFSET/LIMIT sobre este orden, y
        // un grupo de filas empatadas que cruza el borde de una página puede
        // salir repetido o no salir. Acá no hay paginación, pero el orden es
        // contrato para el arnés: se compara por índice.
        $filas = DB::table('servicios')
            ->orderBy('orden')->orderBy('nombre')->orderBy('id')
            ->get();

        // Los ids de especialista se traen de una vez y se agrupan en memoria:
        // una consulta por servicio serían 17 viajes contra un MySQL remoto.
        //
        // **El orden es `especialistas.orden, nombre, id`, no el del id.**
        //
        // Python no ordena esta lista: la relación de SQLAlchemy no declara
        // `order_by`, así que devuelve lo que Postgres saca del join — el
        // ORDEN FÍSICO de las filas (`ctid`). Eso no es un contrato: cambia
        // solo con un VACUUM o un UPDATE que reubique una fila, y no hay forma
        // de reproducirlo en MariaDB.
        //
        // Se comprobó que ningún consumidor depende del orden: los tres
        // frontends usan `.includes()` y `.length` sobre esta lista
        // (AjustesModal.vue, ReservarView.vue), nunca la posición. Así que se
        // le da un orden DETERMINISTA —el mismo que usa el resto del sistema
        // para listar especialistas— que además es estable entre corridas, y
        // se declara la diferencia en el arnés en vez de fingir el ctid.
        $porServicio = DB::table('servicio_especialista as se')
            ->join('especialistas as e', 'e.id', '=', 'se.especialista_id')
            ->select('se.servicio_id', 'se.especialista_id')
            ->orderBy('e.orden')->orderBy('e.nombre')->orderBy('e.id')
            ->get()
            ->groupBy('servicio_id');

        return $filas
            ->filter(fn ($s) => (bool) $s->activo)   // solo activos, como Python
            ->map(fn ($s) => $this->servicioOut($s, $porServicio))
            ->values();
    }

    /**
     * La consulta compartida por `/servicios` y `/servicios/todos`.
     *
     * `$soloActivos` es la única diferencia entre las dos: el público filtra,
     * el del panel no —necesita poder reactivar lo que apagó.
     */
    private function serviciosOrdenados(bool $soloActivos)
    {
        $filas = DB::table('servicios')
            ->orderBy('orden')->orderBy('nombre')->orderBy('id')
            ->get();

        $porServicio = DB::table('servicio_especialista as se')
            ->join('especialistas as e', 'e.id', '=', 'se.especialista_id')
            ->select('se.servicio_id', 'se.especialista_id')
            ->orderBy('e.orden')->orderBy('e.nombre')->orderBy('e.id')
            ->get()
            ->groupBy('servicio_id');

        return $filas
            ->filter(fn ($s) => ! $soloActivos || (bool) $s->activo)
            ->map(fn ($s) => $this->servicioOut($s, $porServicio))
            ->values();
    }

    /**
     * Los defaults de `ajustes`. **Se mezclan con lo guardado, no lo
     * reemplazan**: una clave que nunca se configuró tiene que salir igual que
     * en Python o el portal se queda sin sus textos.
     */
    private const AJUSTES_DEFAULT = [
        // El nombre real lo pone `conDefaults()` desde `Marca` (CLINICA_NOMBRE).
        'nombre' => '',
        'url_slug' => 'clinica',
        'idioma' => 'Español',
        'zona_horaria' => 'America/Santiago',
        // Vacías a propósito: en blanco el panel rotula «Productos» y
        // «Servicios» y todo funciona. Un nombre inventado sería peor, porque
        // nadie lo revisaría.
        'razon_social_productos' => ['nombre' => '', 'rut' => ''],
        'razon_social_servicios' => ['nombre' => '', 'rut' => ''],
        'wording' => [
            'global' => 'Reserve su hora para consultas veterinarias.',
            'consultas' => 'Consultas',
            'especialistas' => 'Especialistas',
            'times' => 'Horarios',
            'client' => 'Información',
            'review' => 'Revisión de información',
            'completed' => 'Gracias por Reservar',
            // Cuándo se paga, según lo que cobre la consulta. Son las frases
            // que el paso 4 tenía escritas en el template de `ReservarView.vue`,
            // y por eso los defaults las repiten palabra por palabra: al
            // migrar, la pantalla no cambia hasta que alguien las edite.
            //
            // Están acá y no en `servicios.precio_detalle` porque no dependen
            // de la consulta sino de su modalidad de pago: escribirlas por
            // servicio obligaba a repetir la misma frase en cada uno y a
            // acordarse de ponerla al crear el siguiente.
            'pago_en_atencion' => 'No se cobra nada ahora: el valor se paga el día de la atención.',
            'pago_total_anticipado' => 'Esta consulta se paga completa antes de la atención.',
            'pago_abono_saldo' => 'Pagas el abono ahora; la diferencia se calcula y se paga en la clínica el día de la atención.',
        ],
    ];

    /**
     * GET /catalogo/ajustes — **público**: el portal de reservas usa el wording
     * configurado.
     *
     * La configuración del ASISTENTE no sale por acá. Ahí van las instrucciones
     * que gobiernan al modelo, y publicarlas en un endpoint sin token es
     * entregarle a cualquiera el mapa para intentar torcerlo. Se sirve aparte,
     * con sesión de staff, en `/catalogo/asistente`.
     */
    public function ajustes()
    {
        $fila = DB::table('ajustes')->where('id', 1)->first();
        $guardado = $fila && $fila->data ? (json_decode($fila->data, true) ?: []) : [];

        $data = $this->conDefaults($guardado);
        unset($data['asistente']);
        // Tampoco la configuración interna de recordatorios, ventas e
        // impresión (ConfigClinica): trae el hash de la clave de descuentos y
        // las reglas de caja, que no son de nadie fuera del equipo.
        foreach (\App\Services\ConfigClinica::SECCIONES as $s) {
            unset($data[$s]);
        }

        return $data;
    }

    /**
     * La fila de ajustes mezclada con los defaults, `wording` **campo a campo**.
     *
     * Lo de `wording` no es prolijidad: la fila guardada trae su propio dict y
     * en un merge plano REEMPLAZA al de los defaults entero, así que una frase
     * nueva le llega vacía a toda clínica que haya guardado ajustes alguna vez
     * —que son todas; la fila de producción tiene exactamente las 7 claves
     * viejas— y el paso 4 del portal sale con la línea en blanco.
     *
     * Existe como método porque la mezcla hace falta en los DOS sitios que
     * devuelven ajustes, y tenerla escrita dos veces ya causó el bug del lado
     * Python: el GET se arregló y el retorno del PUT quedó plano, así que al
     * guardar, la respuesta volvía con las frases vacías y el formulario del
     * panel —que asigna esa respuesta directo a `ajustes.value`— las borraba
     * en pantalla. Un segundo guardado desde ese estado las habría escrito
     * vacías de verdad.
     */
    private function conDefaults(?array $data): array
    {
        $data = $data ?: [];

        return array_merge(self::AJUSTES_DEFAULT, ['nombre' => \App\Services\Marca::nombre()], $data, [
            'wording' => array_merge(
                self::AJUSTES_DEFAULT['wording'], (array) ($data['wording'] ?? [])),
        ]);
    }

    /**
     * PUT /catalogo/ajustes — solo admin.
     *
     * Los campos no enviados se **conservan**; los desconocidos se rechazan.
     * `wording` se mezcla campo a campo y no se reemplaza entero: el panel
     * manda solo la sección que se está editando, y sustituir el objeto
     * completo borraría los textos que no venían en ese PUT.
     */
    public function guardarAjustes(Request $request)
    {
        $fila = DB::table('ajustes')->where('id', 1)->first();
        if (! $fila) {
            DB::table('ajustes')->insert(['id' => 1, 'data' => json_encode([])]);
            $fila = DB::table('ajustes')->where('id', 1)->first();
        }
        $actual = $fila->data ? (json_decode($fila->data, true) ?: []) : [];

        // Solo las claves conocidas: `extra="forbid"` del schema de Python.
        $permitidas = array_keys(self::AJUSTES_DEFAULT);
        $enviado = $request->all();
        $desconocidas = array_diff(array_keys($enviado), $permitidas);
        if ($desconocidas) {
            // Se lanza como ValidationException y no como un `detail` de texto
            // para que el 422 diga **qué campo** sobra, igual que Pydantic: el
            // frontend usa ese `loc` para marcar el input, y un mensaje suelto
            // deja el error flotando sin señalar nada. Mismo caso que el tipo
            // de movimiento en Caja; lo encontró `modo_b_config`.
            throw \Illuminate\Validation\ValidationException::withMessages(
                array_fill_keys(array_values($desconocidas), 'Campo no reconocido.'));
        }

        // Las claves de ADENTRO de `wording`, como `WordingIn` en Python
        // (`extra="forbid"` + `max_length` por campo). Hasta sep-2026 el port
        // solo miraba el primer nivel y aceptaba —y GUARDABA— dos cuerpos que
        // Python rechaza con 422: una clave inventada y un texto sobre el
        // máximo. Medido contra los dos backends; la fila salía después por el
        // GET público. Sin Python delante tras el corte, esto es la única guarda.
        if (($enviado['wording'] ?? null) !== null) {
            if ($error = $this->errorWording($enviado['wording'])) {
                return response()->json(['detail' => [$error]], 422);
            }
        }

        // `exclude_none`: mandar null NO borra un valor guardado, lo deja como
        // estaba — es lo que hace que un PUT parcial sea seguro.
        $cambios = array_filter($enviado, fn ($v) => $v !== null);

        if (isset($cambios['wording']) && is_array($cambios['wording'])) {
            // `exclude_none` también adentro, como el `model_dump` de Python.
            $actual['wording'] = array_merge(
                (array) ($actual['wording'] ?? []),
                array_filter($cambios['wording'], fn ($v) => $v !== null));
            unset($cambios['wording']);
        }
        $actual = array_merge($actual, $cambios);

        DB::table('ajustes')->where('id', 1)->update(['data' => json_encode($actual)]);

        // Mismo merge que el GET, y por eso sale del mismo método: el formulario
        // del panel asigna esta respuesta directo a `ajustes.value`, así que un
        // `wording` incompleto acá le vacía los campos en pantalla justo después
        // de guardar —y un segundo guardado desde ese estado los escribiría
        // vacíos de verdad—.
        //
        // La respuesta SÍ trae `asistente` si estaba guardado: a diferencia del
        // GET público, acá ya se validó que quien llama es admin.
        return $this->conDefaults($actual);
    }

    /**
     * Largo máximo de cada texto de `wording`: los mismos `max_length` de
     * `WordingIn` en Python. Un título de paso es un rótulo (120), el
     * encabezado es un párrafo (500) y las frases de pago son oraciones (200).
     */
    private const WORDING_MAX = [
        'global' => 500,
        'consultas' => 120, 'especialistas' => 120, 'times' => 120,
        'client' => 120, 'review' => 120, 'completed' => 120,
        'pago_en_atencion' => 200, 'pago_total_anticipado' => 200, 'pago_abono_saldo' => 200,
    ];

    /** El primer error de `wording` con la forma de Pydantic, o null si está bien. */
    private function errorWording($wording): ?array
    {
        if (! is_array($wording) || (array_is_list($wording) && $wording !== [])) {
            return ['type' => 'model_type', 'loc' => ['body', 'wording'],
                'msg' => 'Input should be a valid dictionary or instance of WordingIn'];
        }
        foreach ($wording as $clave => $valor) {
            if (! array_key_exists($clave, self::WORDING_MAX)) {
                return ['type' => 'extra_forbidden', 'loc' => ['body', 'wording', $clave],
                    'msg' => 'Extra inputs are not permitted'];
            }
            if ($valor === null) {
                continue;
            }
            if (! is_string($valor)) {
                return ['type' => 'string_type', 'loc' => ['body', 'wording', $clave],
                    'msg' => 'Input should be a valid string'];
            }
            $max = self::WORDING_MAX[$clave];
            if (mb_strlen($valor) > $max) {
                return ['type' => 'string_too_long', 'loc' => ['body', 'wording', $clave],
                    'msg' => "String should have at most {$max} characters"];
            }
        }

        return null;
    }

    /**
     * GET /catalogo/especialistas/{id} — con token de staff, **con contacto**.
     *
     * Va después de `/todos` y de `/slug/{slug}` en el archivo de rutas: una
     * ruta con parámetro se come a las literales que vengan detrás.
     */
    public function verEspecialista(string $especialistaId)
    {
        $e = DB::table('especialistas')->where('id', $especialistaId)->first();
        if (! $e) {
            return response()->json(['detail' => 'Especialista no encontrado'], 404);
        }
        return $this->especialistaOutStaff($e);
    }

    /** GET /catalogo/equipo — (Ajustes → Team Access) los usuarios de staff. */
    public function equipo()
    {
        return DB::table('usuarios')
            ->whereIn('rol', ['veterinario', 'admin'])
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->map(fn ($u) => ['email' => $u->email, 'nombre' => $u->nombre, 'rol' => $u->rol])
            ->values();
    }

    /**
     * GET /catalogo/servicios/todos — con token: incluye los INACTIVOS.
     *
     * El público (`/servicios`) filtra por `activo`; este no, porque el panel
     * necesita poder reactivar lo que apagó.
     */
    public function serviciosTodos()
    {
        return $this->serviciosOrdenados(false);
    }

    /**
     * GET /catalogo/especialistas/todos — con token: los inactivos incluidos y
     * **con los datos de contacto** (`_esp_out_staff`).
     */
    public function especialistasTodos()
    {
        $filas = DB::table('especialistas')
            ->orderBy('orden')->orderBy('nombre')->orderBy('id')->get();

        return $filas->map(fn ($e) => $this->especialistaOutStaff($e))->values();
    }

    /**
     * GET /catalogo/especialistas/slug/{slug} — **público**: la página del
     * enlace permanente `/b/{slug}`, que consume además el sitio de marketing.
     * Sin contacto.
     */
    public function especialistaPorSlug(string $slug)
    {
        $e = DB::table('especialistas')->where('slug', $slug)->where('activo', true)->first();
        if (! $e || ! self::esPublico($e)) {
            return response()->json(['detail' => 'Especialista no encontrado'], 404);
        }

        return $this->especialistaOut($e);
    }

    /**
     * GET /catalogo/form-fields — **público**: los campos visibles del
     * formulario. Los ocultos se filtran EN LA SALIDA, no en la consulta, igual
     * que Python.
     */
    public function formFields()
    {
        return DB::table('form_fields')->orderBy('orden')->orderBy('id')->get()
            ->filter(fn ($f) => ! $f->oculto)
            ->map(fn ($f) => $this->campoOut($f))
            ->values();
    }

    /** GET /catalogo/form-fields/todos — con token: incluye los ocultos. */
    public function formFieldsTodos()
    {
        return DB::table('form_fields')->orderBy('orden')->orderBy('id')->get()
            ->map(fn ($f) => $this->campoOut($f))
            ->values();
    }

    // ── Servicios (Consultas) ─────────────────────────────────────────────

    private function reglasServicio(): array
    {
        return [
            'nombre' => 'required|string',
            'descripcion' => 'nullable|string',
            'emoji' => 'nullable|string',
            'duracion_min' => 'nullable|integer',
            'duracion_visible_min' => 'nullable|integer|min:1',
            'precio_clp' => 'nullable|integer',
            'precio_sabado_clp' => 'nullable|integer|min:0',
            'precio_detalle' => 'nullable|string|max:300',
            'modalidad_pago' => 'nullable|in:nada,abono,total',
            'tarifa_por_especialista' => 'nullable|boolean',
            'abono_fijo_clp' => 'nullable|integer|min:0',
            'requiere_orden_examen' => 'nullable|boolean',
            'coordinar_whatsapp' => 'nullable|boolean',
            'activo' => 'nullable|boolean',
            'orden' => 'nullable|integer',
            'especialista_ids' => 'nullable|array',
        ];
    }

    /** Los campos del servicio con sus defaults, que viven en el SCHEMA. */
    private function camposServicio(array $d): array
    {
        return [
            'nombre' => $d['nombre'],
            'descripcion' => $d['descripcion'] ?? null,
            'emoji' => $d['emoji'] ?? '🐾',
            'duracion_min' => $d['duracion_min'] ?? 30,
            'duracion_visible_min' => $d['duracion_visible_min'] ?? null,
            'precio_clp' => $d['precio_clp'] ?? null,
            'precio_sabado_clp' => $d['precio_sabado_clp'] ?? null,
            'precio_detalle' => $d['precio_detalle'] ?? null,
            'modalidad_pago' => $d['modalidad_pago'] ?? 'abono',
            'tarifa_por_especialista' => $d['tarifa_por_especialista'] ?? false,
            'abono_fijo_clp' => $d['abono_fijo_clp'] ?? null,
            'requiere_orden_examen' => $d['requiere_orden_examen'] ?? false,
            'activo' => $d['activo'] ?? true,
            'orden' => $d['orden'] ?? 0,
        ] + (array_key_exists('coordinar_whatsapp', $d) && Schema::hasColumn('servicios', 'coordinar_whatsapp')
            // Solo si viene y si la columna existe: un panel nuevo contra una
            // base sin el ALTER no puede convertir cada guardado en un 500, y un
            // panel viejo que no manda el campo no puede desmarcarlo sin querer.
            ? ['coordinar_whatsapp' => (bool) $d['coordinar_whatsapp']]
            : []);
    }

    /** POST /catalogo/servicios */
    public function crearServicio(Request $request)
    {
        $datos = $request->validate($this->reglasServicio());
        $id = (string) Str::uuid();

        DB::transaction(function () use ($request, $datos, $id) {
            DB::table('servicios')->insert([
                'id' => $id,
                ...$this->camposServicio($datos),
            ]);
            $this->vincularEspecialistas($id, $datos['especialista_ids'] ?? null);
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'crear', 'config_consultas', 'servicio', null, ['nombre' => $datos['nombre']]);
        });

        return response()->json($this->unServicio($id), 201);
    }

    /**
     * PUT /catalogo/servicios/{id}
     *
     * **PUT y no PATCH**: `ServicioIn` no usa `exclude_unset`, así que un campo
     * ausente vuelve a su default del schema. El panel manda el objeto entero.
     */
    public function editarServicio(Request $request, string $servicioId)
    {
        if (! DB::table('servicios')->where('id', $servicioId)->exists()) {
            return response()->json(['detail' => 'Servicio no encontrado'], 404);
        }
        $datos = $request->validate($this->reglasServicio());

        DB::transaction(function () use ($request, $datos, $servicioId) {
            DB::table('servicios')->where('id', $servicioId)
                ->update($this->camposServicio($datos));
            // `especialista_ids` ausente NO toca los vínculos; presente los
            // reemplaza por completo (`is not None` en Python).
            if (array_key_exists('especialista_ids', $datos)) {
                $this->vincularEspecialistas($servicioId, $datos['especialista_ids']);
            }
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'editar', 'config_consultas', 'servicio', $servicioId,
                ['nombre' => $datos['nombre']]);
        });

        return $this->unServicio($servicioId);
    }

    /**
     * DELETE /catalogo/servicios/{id} — borrado REAL.
     *
     * Las citas ya reservadas guardan el nombre del servicio aparte (columna
     * `servicio`, denormalizada), así que no desaparecen ni quedan huérfanas:
     * solo se suelta la referencia al tipo de consulta.
     */
    public function eliminarServicio(Request $request, string $servicioId)
    {
        $svc = DB::table('servicios')->where('id', $servicioId)->first();
        if (! $svc) {
            return response()->json(['detail' => 'Servicio no encontrado'], 404);
        }

        DB::transaction(function () use ($request, $svc) {
            DB::table('citas')->where('servicio_id', $svc->id)->update(['servicio_id' => null]);
            DB::table('servicio_especialista')->where('servicio_id', $svc->id)->delete();
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'eliminar', 'config_consultas', 'servicio', $svc->id, ['nombre' => $svc->nombre]);
            DB::table('servicios')->where('id', $svc->id)->delete();
        });

        return response()->noContent();
    }

    /** Reemplaza los especialistas vinculados a un servicio. */
    private function vincularEspecialistas(string $servicioId, ?array $ids): void
    {
        if ($ids === null) {
            return;
        }
        DB::table('servicio_especialista')->where('servicio_id', $servicioId)->delete();
        // Solo los que EXISTEN: Python filtra con un `IN`, así que un id
        // inventado se ignora en silencio en vez de reventar contra la FK.
        $validos = DB::table('especialistas')->whereIn('id', $ids)->pluck('id');
        foreach ($validos as $eid) {
            DB::table('servicio_especialista')->insert([
                'servicio_id' => $servicioId, 'especialista_id' => $eid,
            ]);
        }
    }

    private function unServicio(string $id)
    {
        $s = DB::table('servicios')->where('id', $id)->first();
        $porServicio = DB::table('servicio_especialista as se')
            ->join('especialistas as e', 'e.id', '=', 'se.especialista_id')
            ->where('se.servicio_id', $id)
            ->select('se.servicio_id', 'se.especialista_id')
            ->orderBy('e.orden')->orderBy('e.nombre')->orderBy('e.id')
            ->get()->groupBy('servicio_id');

        return $this->servicioOut($s, $porServicio);
    }

    // ── Campos del formulario ─────────────────────────────────────────────

    private function reglasCampo(): array
    {
        return [
            'etiqueta' => 'required|string',
            'tipo' => 'nullable|string',
            'opciones' => 'nullable|array',
            'requerido' => 'nullable|boolean',
            'oculto' => 'nullable|boolean',
            'orden' => 'nullable|integer',
        ];
    }

    private function camposCampo(array $d): array
    {
        return [
            'etiqueta' => $d['etiqueta'],
            'tipo' => $d['tipo'] ?? 'text',
            'opciones' => json_encode(array_values($d['opciones'] ?? [])),
            'requerido' => $d['requerido'] ?? false,
            'oculto' => $d['oculto'] ?? false,
            'orden' => $d['orden'] ?? 0,
        ];
    }

    /** POST /catalogo/form-fields */
    public function crearCampo(Request $request)
    {
        $datos = $request->validate($this->reglasCampo());
        $id = (string) Str::uuid();

        DB::transaction(function () use ($request, $datos, $id) {
            DB::table('form_fields')->insert(['id' => $id, ...$this->camposCampo($datos)]);
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'crear', 'config_campos', 'form_field', null, ['etiqueta' => $datos['etiqueta']]);
        });

        return response()->json(
            $this->campoOut(DB::table('form_fields')->where('id', $id)->first()), 201);
    }

    /** PUT /catalogo/form-fields/{id} */
    public function editarCampo(Request $request, string $campoId)
    {
        if (! DB::table('form_fields')->where('id', $campoId)->exists()) {
            return response()->json(['detail' => 'Campo no encontrado'], 404);
        }
        $datos = $request->validate($this->reglasCampo());

        DB::transaction(function () use ($request, $datos, $campoId) {
            DB::table('form_fields')->where('id', $campoId)->update($this->camposCampo($datos));
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'editar', 'config_campos', 'form_field', $campoId,
                ['etiqueta' => $datos['etiqueta']]);
        });

        return $this->campoOut(DB::table('form_fields')->where('id', $campoId)->first());
    }

    /**
     * DELETE /catalogo/form-fields/{id}
     *
     * **204 aunque no exista**, a diferencia de los demás borrados de este
     * router: Python entra en el `if f:` y sale sin error. Es idempotente por
     * accidente, pero es el contrato y hay que replicarlo.
     */
    public function eliminarCampo(Request $request, string $campoId)
    {
        $f = DB::table('form_fields')->where('id', $campoId)->first();
        if ($f) {
            DB::transaction(function () use ($request, $f) {
                $this->auditoria->registrar($request->attributes->get('usuario'),
                    'eliminar', 'config_campos', 'form_field', $f->id,
                    ['etiqueta' => $f->etiqueta]);
                DB::table('form_fields')->where('id', $f->id)->delete();
            });
        }

        return response()->noContent();
    }

    // ── Especialistas ─────────────────────────────────────────────────────

    /** POST /catalogo/especialistas */
    public function crearEspecialista(Request $request)
    {
        $datos = $request->validate($this->reglasEspecialista());
        $id = (string) Str::uuid();

        $error = null;
        // La excepción es de CONTROL: revierte la transacción cuando la
        // validación falla a mitad de camino. El error real viaja en `$error`,
        // y dejarla escapar convertiría un 400 legítimo en un 500.
        try {
            DB::transaction(function () use ($request, $datos, $id, &$error) {
                // La fila nace con los DEFAULTS del schema, no con los de la
                // columna: `EspecialistaIn` declara `emoji='🩺'`, `activo=True`,
                // `reservable=True`, etc., y un POST que no los manda igual los
                // guarda. `aplicarEspecialista` después pisa lo que sí vino.
                DB::table('especialistas')->insert([
                    'id' => $id,
                    'nombre' => $datos['nombre'],
                    'emoji' => '🩺',
                    'zona_horaria' => 'America/Santiago',
                    'activo' => true,
                    'orden' => 0,
                    'disponibilidad_modo' => 'todos',
                    'solo_consulta_whatsapp' => false,
                    'reservable' => true,
                    'recibe_avisos' => false,
                ]);
                $error = $this->aplicarEspecialista($id, $datos, $request);
                if ($error) {
                    throw new \RuntimeException('validacion');
                }
                $this->auditoria->registrar($request->attributes->get('usuario'),
                    'crear', 'config_especialistas', 'especialista', null,
                    ['nombre' => $datos['nombre']]);
            }, 1);
        } catch (\RuntimeException $ex) {
            if (! $error) {
                throw $ex;
            }
        }

        if ($error) {
            return $error;
        }

        return response()->json(
            $this->especialistaOutStaff(DB::table('especialistas')->where('id', $id)->first()), 201);
    }

    /**
     * PUT /catalogo/especialistas/{id}
     *
     * La auditoría registra cuántos horarios y bloques había ANTES y después.
     * No es adorno: sin ese dato, cuando una especialista se quedó con un solo
     * bloque no hubo forma de saber en qué edición ocurrió.
     */
    public function editarEspecialista(Request $request, string $especialistaId)
    {
        if (! DB::table('especialistas')->where('id', $especialistaId)->exists()) {
            return response()->json(['detail' => 'Especialista no encontrado'], 404);
        }
        $datos = $request->validate($this->reglasEspecialista());
        $antes = $this->resumenHorarios($especialistaId);

        $error = null;
        try {
            DB::transaction(function () use ($request, $datos, $especialistaId, $antes, &$error) {
                $error = $this->aplicarEspecialista($especialistaId, $datos, $request);
                if ($error) {
                    throw new \RuntimeException('validacion');
                }
                $this->auditoria->registrar($request->attributes->get('usuario'),
                    'editar', 'config_especialistas', 'especialista', $especialistaId, [
                        'nombre' => $datos['nombre'] ?? null,
                        'horarios_antes' => $antes,
                        'horarios_despues' => $this->resumenHorarios($especialistaId),
                    ]);
            }, 1);
        } catch (\RuntimeException $ex) {
            if (! $error) {
                throw $ex;
            }
        }

        if ($error) {
            return $error;
        }

        return $this->especialistaOutStaff(
            DB::table('especialistas')->where('id', $especialistaId)->first());
    }

    /** DELETE /catalogo/especialistas/{id} */
    public function eliminarEspecialista(Request $request, string $especialistaId)
    {
        $e = DB::table('especialistas')->where('id', $especialistaId)->first();
        if (! $e) {
            return response()->json(['detail' => 'Especialista no encontrado'], 404);
        }

        DB::transaction(function () use ($request, $e) {
            // **Cuatro tablas, no una.** Citas, notas clínicas, ventas y
            // transacciones no se borran: cada una documenta una atención o un
            // cobro REAL y debe seguir existiendo aunque el catálogo cambie.
            // Solo se suelta la referencia.
            //
            // Con las 47 claves foráneas puestas, olvidar cualquiera de las
            // tres últimas hace fallar el borrado entero.
            foreach (['citas', 'notas_clinicas', 'ventas', 'transacciones'] as $tabla) {
                DB::table($tabla)->where('especialista_id', $e->id)
                    ->update(['especialista_id' => null]);
            }
            DB::table('servicio_especialista')->where('especialista_id', $e->id)->delete();
            $this->borrarHorarios($e->id);
            DB::table('disponibilidad_especialistas')->where('especialista_id', $e->id)->delete();
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'eliminar', 'config_especialistas', 'especialista', $e->id,
                ['nombre' => $e->nombre]);
            DB::table('especialistas')->where('id', $e->id)->delete();
        });

        return response()->noContent();
    }

    private function reglasEspecialista(): array
    {
        return [
            'nombre' => 'required|string',
            'especialidad' => 'nullable|string',
            'emoji' => 'nullable|string',
            'slug' => 'nullable|string',
            'descripcion' => 'nullable|string',
            'zona_horaria' => 'nullable|string',
            'activo' => 'nullable|boolean',
            'orden' => 'nullable|integer',
            'disponibilidad_modo' => 'nullable|string',
            'calendarios_conflicto' => 'nullable|array',
            'google_calendar_id' => 'nullable|string|max:255',
            'solo_consulta_whatsapp' => 'nullable|boolean',
            'reservable' => 'nullable|boolean',
            'precio_clp' => 'nullable|integer|min:0',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string|max:30',
            'recibe_avisos' => 'nullable|boolean',
            'publico' => 'nullable|boolean',
            'disponibilidad' => 'nullable|array',
            'horarios' => 'nullable|array',
            'servicio_ids' => 'nullable|array',
        ];
    }

    /**
     * Aplica el payload sobre un especialista. Devuelve una respuesta de error
     * o `null` si todo fue bien.
     *
     * **`exclude_unset`**: un PUT parcial no resetea los campos que no vienen.
     */
    private function aplicarEspecialista(string $id, array $datos, Request $request)
    {
        $escalares = ['nombre', 'especialidad', 'emoji', 'descripcion', 'zona_horaria',
                      'activo', 'orden', 'disponibilidad_modo', 'solo_consulta_whatsapp',
                      'reservable', 'precio_clp', 'email', 'telefono', 'recibe_avisos'];
        // Solo si la columna existe: un panel nuevo contra una base sin la
        // migración mandaría `publico` y el UPDATE sería un 500 al guardar
        // CUALQUIER cambio del especialista, no solo ese.
        if ($request->exists('publico') && Schema::hasColumn('especialistas', 'publico')) {
            $escalares[] = 'publico';
        }
        $set = [];
        foreach ($escalares as $campo) {
            if ($request->exists($campo)) {
                $v = $datos[$campo] ?? null;
                // Un input de texto vaciado llega como '' y el schema lo pasa a
                // null: sin esto, `EmailStr` lo rechazaría y el panel no podría
                // borrar un correo.
                if (in_array($campo, ['email', 'telefono'], true) && trim((string) $v) === '') {
                    $v = null;
                }
                $set[$campo] = $v;
            }
        }
        if ($request->exists('calendarios_conflicto')) {
            $set['calendarios_conflicto'] = json_encode(
                array_values($datos['calendarios_conflicto'] ?? []));
        }
        if ($request->exists('google_calendar_id') && Schema::hasColumn('especialistas', 'google_calendar_id')) {
            $nuevo = trim((string) ($datos['google_calendar_id'] ?? '')) ?: null;
            $error = $this->validarCalendarioPropio($id, $nuevo);
            if ($error) {
                return $error;
            }
            $set['google_calendar_id'] = $nuevo;
        }
        if ($request->exists('slug') && ($datos['slug'] ?? null) !== null) {
            $set['slug'] = $this->slugUnico(
                $datos['slug'] ?: ($datos['especialidad'] ?? null) ?: $datos['nombre'], $id);
        }
        if ($set) {
            DB::table('especialistas')->where('id', $id)->update($set);
        }

        $e = DB::table('especialistas')->where('id', $id)->first();

        // **No se pueden encender los avisos sin correo al que mandarlos.** La
        // regla va acá y no en la validación porque el panel manda PUT
        // parciales: un payload que solo trae `recibe_avisos` no lleva el
        // correo, y la validación —que solo ve el payload— no puede saber si ya
        // hay uno guardado. Acá se mira el objeto YA actualizado.
        if ($e->recibe_avisos && trim((string) ($e->email ?? '')) === '') {
            return response()->json([
                'detail' => 'Para activar los avisos hay que cargarle un correo al especialista.',
            ], 400);
        }

        if ($request->exists('disponibilidad') && $datos['disponibilidad'] !== null) {
            DB::table('disponibilidad_especialistas')->where('especialista_id', $id)->delete();
            foreach ($datos['disponibilidad'] as $d) {
                DB::table('disponibilidad_especialistas')->insert([
                    'id' => (string) Str::uuid(),
                    'especialista_id' => $id,
                    'dia_semana' => $d['dia_semana'],
                    'hora_inicio' => $d['hora_inicio'],
                    'hora_fin' => $d['hora_fin'],
                ]);
            }
        }

        if ($request->exists('horarios') && $datos['horarios'] !== null) {
            // **Esto REEMPLAZA la lista completa**: los horarios que no vengan
            // en el payload se BORRAN, con sus bloques. Es correcto para el
            // panel, que siempre manda la lista entera que cargó; es una trampa
            // para un script que solo quiere tocar un día.
            //
            // Ya pasó: al cargarle el lunes nuevo a una especialista se mandó un
            // solo horario y se llevó por delante los otros dos, incluido el
            // sábado por medio. Quedó reservable dos horas a la semana y nadie
            // se enteró hasta que ella lo mencionó por correo.
            $this->borrarHorarios($id);
            $ordenados = $datos['horarios'];
            usort($ordenados, fn ($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));
            foreach ($ordenados as $h) {
                $hid = (string) Str::uuid();
                DB::table('horarios')->insert([
                    'id' => $hid,
                    'especialista_id' => $id,
                    'nombre' => $h['nombre'] ?? 'Horario 1',
                    'orden' => $h['orden'] ?? 0,
                    'todos_servicios' => $h['todos_servicios'] ?? true,
                    'servicio_ids' => json_encode(array_map('strval', $h['servicio_ids'] ?? [])),
                    'valido_desde' => isset($h['valido_desde'])
                        ? substr((string) $h['valido_desde'], 0, 10) : null,
                    'valido_hasta' => isset($h['valido_hasta'])
                        ? substr((string) $h['valido_hasta'], 0, 10) : null,
                    'semana_paridad' => $h['semana_paridad'] ?? null,
                ]);
                foreach ($h['bloques'] ?? [] as $b) {
                    DB::table('horario_bloques')->insert([
                        'id' => (string) Str::uuid(),
                        'horario_id' => $hid,
                        'dia_semana' => $b['dia_semana'],
                        'hora_inicio' => $b['hora_inicio'],
                        'hora_fin' => $b['hora_fin'],
                    ]);
                }
            }
        }

        if ($request->exists('servicio_ids') && $datos['servicio_ids'] !== null) {
            DB::table('servicio_especialista')->where('especialista_id', $id)->delete();
            $validos = DB::table('servicios')->whereIn('id', $datos['servicio_ids'])->pluck('id');
            foreach ($validos as $sid) {
                DB::table('servicio_especialista')->insert([
                    'servicio_id' => $sid, 'especialista_id' => $id,
                ]);
            }
        }

        return null;
    }

    /** Los bloques van antes que su horario: la FK no perdona (§ las 47). */
    /**
     * Antes de asignarle a alguien un calendario de reservas propio, que la
     * cuenta de la clínica pueda ESCRIBIR en él. Uno compartido en solo lectura
     * se ve en la lista igual, y asignarlo dejaría cada reserva nueva sin
     * evento, sin ningún error a la vista. Solo se pregunta si cambió: reenviar
     * el mismo valor al guardar otra cosa no llama a Google.
     */
    private function validarCalendarioPropio(string $especialistaId, ?string $nuevo)
    {
        $actual = DB::table('especialistas')->where('id', $especialistaId)->value('google_calendar_id');
        if ($nuevo === null || $nuevo === $actual) {
            return null;
        }
        $google = app(\App\Services\GoogleCalendar::class);
        $cuenta = $google->cuentaClinica();
        if (! $cuenta) {
            return response()->json(['detail' =>
                'Conecta primero la cuenta de Google de la clínica (Ajustes → Integraciones).'], 400);
        }
        $puede = $google->puedeEscribir($cuenta, $nuevo);
        if ($puede === null) {
            return response()->json(['detail' =>
                'No se pudo comprobar el calendario con Google. Intenta de nuevo en un momento.'], 502);
        }
        if (! $puede) {
            return response()->json(['detail' => 'La cuenta ' . ($cuenta->email ?? 'de la clínica')
                . ' no puede crear eventos en ese calendario. Elige uno suyo, o pide que se lo '
                . 'compartan con permiso para «hacer cambios en eventos».'], 400);
        }

        return null;
    }

    private function borrarHorarios(string $especialistaId): void
    {
        $ids = DB::table('horarios')->where('especialista_id', $especialistaId)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('horario_bloques')->whereIn('horario_id', $ids)->delete();
            DB::table('horarios')->whereIn('id', $ids)->delete();
        }
    }

    /**
     * Cuántos horarios y bloques tiene, para la auditoría.
     *
     * Sin esto un «editar» solo registra el nombre: si alguien pierde sus horas
     * no hay forma de saber cuándo ni desde qué edición.
     */
    private function resumenHorarios(string $especialistaId): array
    {
        $ids = DB::table('horarios')->where('especialista_id', $especialistaId)->pluck('id');

        return [
            'horarios' => $ids->count(),
            'bloques' => $ids->isEmpty() ? 0
                : DB::table('horario_bloques')->whereIn('horario_id', $ids)->count(),
        ];
    }

    /** El slug del enlace permanente, con sufijo numérico si ya existe. */
    private function slugUnico(string $texto, string $idPropio): string
    {
        // NFKD + quitar lo no-ASCII: «Dermatología» -> «dermatologia».
        $base = preg_replace('/[^a-zA-Z0-9]+/', '-',
            (string) iconv('UTF-8', 'ASCII//TRANSLIT', $texto));
        $base = strtolower(trim((string) $base, '-')) ?: 'especialista';

        $slug = $base;
        $n = 2;
        while (DB::table('especialistas')->where('slug', $slug)
                ->where('id', '!=', $idPropio)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    /**
     * GET /catalogo/especialistas/{id}/impacto
     *
     * Cuántos registros quedarían SIN especialista si se borra — no se
     * destruyen, solo pierden esa referencia. Es lo que la pantalla de
     * confirmación necesita para que nadie borre a ciegas.
     */
    public function impactoEspecialista(string $especialistaId)
    {
        $e = DB::table('especialistas')->where('id', $especialistaId)->first();
        if (! $e) {
            return response()->json(['detail' => 'Especialista no encontrado'], 404);
        }

        return [
            'nombre' => $e->nombre,
            'citas' => DB::table('citas')->where('especialista_id', $especialistaId)->count(),
            'notas_clinicas' => DB::table('notas_clinicas')
                ->where('especialista_id', $especialistaId)->count(),
            'ventas' => DB::table('ventas')->where('especialista_id', $especialistaId)->count(),
            'transacciones' => DB::table('transacciones')
                ->where('especialista_id', $especialistaId)->count(),
        ];
    }

    /**
     * POST /catalogo/especialistas/{id}/foto
     *
     * La foto va a `uploads/`, que **sí** se sirve estático y sin
     * autenticación: es la imagen que muestra el sitio público. Al revés que
     * los documentos clínicos, que nunca pueden caer ahí (§9.16).
     */
    public function subirFoto(Request $request, string $especialistaId)
    {
        $e = DB::table('especialistas')->where('id', $especialistaId)->first();
        if (! $e) {
            return response()->json(['detail' => 'Especialista no encontrado'], 404);
        }

        $archivo = $request->file('archivo');
        if (! $archivo || ! $archivo->isValid()) {
            return response()->json(['detail' => 'Falta el archivo.'], 400);
        }

        $ext = strtolower($archivo->getClientOriginalExtension());
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return response()->json(
                ['detail' => 'Formato no soportado (usa JPG, PNG o WebP).'], 400);
        }
        if ($archivo->getSize() > 4 * 1024 * 1024) {
            return response()->json(['detail' => 'La imagen no puede superar 4 MB.'], 400);
        }

        $nombre = "esp-{$especialistaId}-" . substr(bin2hex(random_bytes(4)), 0, 8) . ".{$ext}";
        $destino = rtrim(config('emr.uploads_dir'), '/');
        if (! is_dir($destino)) {
            // Obligatorio: un volumen recién creado se monta vacío (§9.16).
            mkdir($destino, 0775, true);
        }
        $archivo->move($destino, $nombre);

        // La foto ANTERIOR no se borra, igual que en Python: si dos ediciones
        // se cruzan, borrar la vieja dejaría al sitio sin imagen. El costo es
        // algún archivo huérfano en un directorio de 7 fotos.
        DB::table('especialistas')->where('id', $especialistaId)->update(['foto' => $nombre]);

        return ['foto' => "/uploads/{$nombre}"];
    }

    /**
     * DELETE /catalogo/especialistas/{id}/foto
     *
     * **204 aunque el especialista no exista**, igual que el borrado de un
     * campo de formulario: Python entra en el `if e:` y sale sin error.
     */
    /**
     * POST /catalogo/servicios/{id}/imagen
     *
     * Gemelo de `subirFoto`, para los tipos de consulta. Se escribe aparte en
     * vez de factorizar el de especialistas: ese está cubierto por el arnés
     * diferencial contra el backend Python, y tocarlo para compartir cuatro
     * líneas cambiaría una ruta verificada por una que habría que volver a
     * verificar. La duplicación acá es más barata que ese riesgo.
     */
    public function subirImagenServicio(Request $request, string $servicioId)
    {
        $s = DB::table('servicios')->where('id', $servicioId)->first();
        if (! $s) {
            return response()->json(['detail' => 'Servicio no encontrado'], 404);
        }

        $archivo = $request->file('archivo');
        if (! $archivo || ! $archivo->isValid()) {
            return response()->json(['detail' => 'Falta el archivo.'], 400);
        }

        $ext = strtolower($archivo->getClientOriginalExtension());
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return response()->json(
                ['detail' => 'Formato no soportado (usa JPG, PNG o WebP).'], 400);
        }
        if ($archivo->getSize() > 4 * 1024 * 1024) {
            return response()->json(['detail' => 'La imagen no puede superar 4 MB.'], 400);
        }

        $nombre = "svc-{$servicioId}-" . substr(bin2hex(random_bytes(4)), 0, 8) . ".{$ext}";
        $destino = rtrim(config('emr.uploads_dir'), '/');
        if (! is_dir($destino)) {
            // Obligatorio: un volumen recién creado se monta vacío (§9.16).
            mkdir($destino, 0775, true);
        }
        $archivo->move($destino, $nombre);

        // La imagen anterior no se borra, mismo criterio que la foto del
        // especialista: si dos ediciones se cruzan, borrarla dejaría la tarjeta
        // sin foto. El costo es algún archivo huérfano.
        DB::table('servicios')->where('id', $servicioId)->update(['imagen' => $nombre]);

        return ['imagen' => "/uploads/{$nombre}"];
    }

    /**
     * DELETE /catalogo/servicios/{id}/imagen
     *
     * 204 aunque el servicio no exista, igual que `quitarFoto`. Quitar la
     * imagen NO deja la tarjeta vacía: vuelve a mostrarse el emoji.
     */
    public function quitarImagenServicio(string $servicioId)
    {
        DB::table('servicios')->where('id', $servicioId)->update(['imagen' => null]);

        return response()->noContent();
    }

    public function quitarFoto(string $especialistaId)
    {
        DB::table('especialistas')->where('id', $especialistaId)->update(['foto' => null]);

        return response()->noContent();
    }

    /**
     * Los defaults del asistente. **Se mezclan por campo, no de golpe**: si
     * mañana se agrega una frase nueva, las clínicas que ya guardaron su
     * configuración la reciben igual en vez de quedarse con un texto vacío.
     */
    private const ASISTENTE_DEFAULT = [
        // En false, WhatsApp responde el aviso fijo y NO se llama al modelo:
        // no gasta tokens ni toca los datos del cliente.
        'activo_whatsapp' => true,
        // Vacío = usa CLINICA_TELEFONO. Está acá para que la clínica pueda
        // cambiarlo sin tocar variables de entorno ni redesplegar.
        'telefono' => '',
        'menu' => [
            'activo' => true,
            // Vacío = las primeras que devuelva el catálogo, ya ordenadas por
            // el campo `orden` que la clínica administra. Así el menú funciona
            // el primer día sin configurar nada.
            'servicios' => [],
        ],
        'tono' => 'Sé breve y cálido, como alguien del mostrador que conoce a los '
            . 'clientes. Trata de tú. No uses markdown ni emojis.',
        // Para lo que cambia seguido: promociones, convenios, avisos.
        'instrucciones_extra' => '',
        // Lo que el asistente contesta sin modelo. Una sola definición, en el
        // servicio que la usa para contestar (ver RespuestaDirecta::DATOS_DEFAULT).
        'datos' => RespuestaDirecta::DATOS_DEFAULT,
        'frases' => [
            // Instrucción para el modelo, no un texto que se copie tal cual.
            'derivar' => 'Si te preguntan por síntomas, urgencias o cualquier tema médico, '
                . 'no opines: dile que llame a la clínica para que lo vea alguien del equipo.',
            // Estas dos SÍ se envían literales, sin pasar por el modelo.
            'no_texto' => 'Por ahora solo puedo leer mensajes de texto, así que {detalle}. '
                . 'Cuéntame en pocas palabras qué necesitas y te ayudo a agendar una hora.',
            'error_tecnico' => 'Disculpa, tuvimos un problema técnico. Intenta de nuevo en unos minutos.',
            'apagado' => 'Por ahora no estoy disponible por este medio. Escríbenos más tarde '
                . 'o llámanos y te atendemos.',
        ],
    ];

    /**
     * GET /catalogo/asistente — **solo staff**: acá van las instrucciones que
     * gobiernan al modelo, y por eso no salen por `/catalogo/ajustes`, que es
     * público.
     */
    public function verAsistente()
    {
        return self::conGastoDeHoy($this->asistenteConfig());
    }

    /**
     * Lo gastado hoy va solo a la pantalla, no a `asistenteConfig()`: esa la
     * lee también cada mensaje de WhatsApp, que no necesita la consulta de más.
     * Con guarda (§13.13): en una base sin `uso_asistente` la pantalla carga
     * igual, sin esa línea, en vez de responder 500.
     */
    private static function conGastoDeHoy(array $config): array
    {
        try {
            $gasto = UsoAsistente::gastoDeHoy();
        } catch (\Throwable) {
            $gasto = null;
        }

        // Lo que nadie vería nunca mirando la pantalla: el bot puede estar
        // «encendido» y mudo (token vencido) o con la IA cortada por fallos.
        try {
            $salud = [
                'ultimo_error_envio' => \Illuminate\Support\Facades\Cache::get(\App\Services\WhatsappEnvio::CLAVE_ERROR),
                'ia_cortada' => \Illuminate\Support\Facades\Cache::has('whatsapp:ia-caida'),
            ];
        } catch (\Throwable) {
            $salud = ['ultimo_error_envio' => null, 'ia_cortada' => false];
        }

        return $config + ['gasto_hoy' => $gasto, 'salud' => $salud];
    }

    /**
     * El cupo diario que se acepta desde el panel. Sin 0 («sin tope») a
     * propósito: `/chatbot/mensaje` es público y el límite por IP no frena a
     * quien cambia de IP, así que abrir el gasto sin techo tiene que seguir
     * exigiendo tocar el servidor. El máximo, 10 millones, son unos US$10–20
     * al día con Haiku en el peor caso.
     */
    private const TOKENS_DIA_MIN = 10000;
    private const TOKENS_DIA_MAX = 10000000;

    /**
     * PUT /catalogo/asistente — **solo admin**. Los campos no enviados se
     * conservan.
     */
    public function guardarAsistente(Request $request)
    {
        // `extra="forbid"` del schema: un campo desconocido es un 422, no algo
        // que se guarde en silencio. `menu` NO se edita por acá.
        $permitidos = ['activo_whatsapp', 'telefono', 'tono', 'instrucciones_extra', 'frases', 'datos', 'tokens_dia'];
        $sobran = array_diff(array_keys($request->all()), $permitidos);
        if ($sobran) {
            return response()->json(['detail' => [[
                'type' => 'extra_forbidden',
                'loc' => ['body', array_values($sobran)[0]],
                'msg' => 'Extra inputs are not permitted',
            ]]], 422);
        }

        $fueraDeRango = 'El cupo diario va de ' . number_format(self::TOKENS_DIA_MIN, 0, ',', '.')
            . ' a ' . number_format(self::TOKENS_DIA_MAX, 0, ',', '.') . ' tokens.';
        $datos = $request->validate([
            'tokens_dia' => 'nullable|integer|min:' . self::TOKENS_DIA_MIN . '|max:' . self::TOKENS_DIA_MAX,
            'activo_whatsapp' => 'nullable|boolean',
            'telefono' => 'nullable|string|max:40',
            'tono' => 'nullable|string|max:1500',
            'instrucciones_extra' => 'nullable|string|max:3000',
            'frases' => 'nullable|array',
            'frases.derivar' => 'nullable|string',
            'frases.no_texto' => 'nullable|string',
            'frases.error_tecnico' => 'nullable|string',
            'frases.apagado' => 'nullable|string',
            'datos' => 'nullable|array',
            'datos.direccion' => 'nullable|string|max:200',
            'datos.horario' => 'nullable|string|max:400',
            'datos.preguntas' => 'nullable|array|max:30',
            'datos.preguntas.*' => 'array',
            'datos.preguntas.*.claves' => 'required|string|max:300',
            'datos.preguntas.*.respuesta' => 'required|string|max:800',
        ], [
            'tokens_dia.integer' => $fueraDeRango,
            'tokens_dia.min' => $fueraDeRango,
            'tokens_dia.max' => $fueraDeRango,
        ]);

        // `extra="forbid"` también ADENTRO de `datos` y de cada pregunta, como
        // DatosIn/PreguntaIn en Python. Sin esto el port acepta y guarda lo que
        // Python rechaza — el mismo hueco que se midió en `wording` (sep-2026).
        if ($request->has('datos') && is_array($request->input('datos'))) {
            $extra = array_diff(array_keys($request->input('datos')), ['direccion', 'horario', 'preguntas']);
            if ($extra) {
                return response()->json(['detail' => [[
                    'type' => 'extra_forbidden', 'loc' => ['body', 'datos', array_values($extra)[0]],
                    'msg' => 'Extra inputs are not permitted',
                ]]], 422);
            }
            foreach (($request->input('datos.preguntas') ?? []) as $i => $p) {
                $extra = array_diff(array_keys((array) $p), ['claves', 'respuesta']);
                if ($extra) {
                    return response()->json(['detail' => [[
                        'type' => 'extra_forbidden', 'loc' => ['body', 'datos', 'preguntas', $i, array_values($extra)[0]],
                        'msg' => 'Extra inputs are not permitted',
                    ]]], 422);
                }
            }
        }
        // Mismas reglas que los validadores de PreguntaIn, en una sola copia
        // (RespuestaDirecta::errorPregunta) que usa también el modal «Enseñarle».
        foreach (($datos['datos']['preguntas'] ?? []) as $i => $p) {
            [$campo, $error] = RespuestaDirecta::errorPregunta($p) ?? [null, null];
            if ($error) {
                return response()->json(['detail' => [[
                    'type' => 'value_error', 'loc' => ['body', 'datos', 'preguntas', $i, $campo],
                    'msg' => 'Value error, ' . $error,
                ]]], 422);
            }
        }

        // Una llave `{...}` no permitida rompería el `.format()` del webhook con
        // un error en plena conversación: se rechaza AL GUARDAR, no cuando un
        // cliente manda una foto un domingo.
        foreach (($datos['frases'] ?? []) as $campo => $valor) {
            if ($valor === null) {
                continue;
            }
            $permitidas = $campo === 'no_texto' ? ['detalle'] : [];
            preg_match_all('/\{(\w*)\}/', $valor, $m);
            $sobran = array_values(array_unique(array_diff($m[1], $permitidas)));
            if ($sobran) {
                sort($sobran);
                return response()->json(['detail' => [[
                    'type' => 'value_error',
                    'loc' => ['body', 'frases', $campo],
                    'msg' => 'Value error, No uses {' . implode(', ', $sobran)
                        . '}: solo se permite {detalle} en el aviso de mensaje no leído.',
                ]]], 422);
            }
        }

        $fila = DB::table('ajustes')->where('id', 1)->first();
        $actual = $fila && $fila->data ? (json_decode($fila->data, true) ?: []) : [];
        $guardado = $actual['asistente'] ?? [];

        // Solo lo que VINO en el cuerpo, y sin los nulos: `exclude_unset` +
        // `exclude_none`. Mandar `telefono: null` no borra el guardado.
        // **Sin recortar**: el `.strip()` de Python vive en el validador de las
        // cuatro FRASES, no en estos campos. `tono` e `instrucciones_extra` se
        // guardan tal cual los escribió la clínica — incluidos sus espacios.
        foreach (['activo_whatsapp', 'telefono', 'tono', 'instrucciones_extra'] as $campo) {
            if ($request->exists($campo) && ($datos[$campo] ?? null) !== null) {
                $guardado[$campo] = $datos[$campo];
            }
        }
        // Como entero SIEMPRE: `UsoAsistente::tope()` ignora lo que no lo sea, y
        // un "1000000" de texto (que `integer` deja pasar) volvería al `.env` sin
        // que el panel lo mostrara.
        if (($datos['tokens_dia'] ?? null) !== null) {
            $guardado['tokens_dia'] = (int) $datos['tokens_dia'];
        }
        if (! empty($datos['frases'])) {
            $frases = array_filter($datos['frases'], fn ($v) => $v !== null);
            // Los textos se recortan, igual que el validador de Python.
            $frases = array_map(fn ($v) => trim($v), $frases);
            $guardado['frases'] = array_merge($guardado['frases'] ?? [], $frases);
        }

        // `datos` campo a campo, y SIN descartar vacíos: vaciar el horario es
        // una decisión («no lo contestes») y tiene que poder guardarse.
        if ($request->has('datos') && is_array($request->input('datos'))) {
            $enviados = [];
            foreach (['direccion', 'horario'] as $campo) {
                if ($request->exists("datos.$campo")) {
                    $v = $datos['datos'][$campo] ?? null;
                    $enviados[$campo] = is_string($v) ? trim($v) : $v;
                }
            }
            if ($request->exists('datos.preguntas')) {
                $enviados['preguntas'] = array_map(fn ($p) => [
                    'claves' => implode(', ', array_values(array_filter(array_map('trim', explode(',', $p['claves'])), fn ($x) => $x !== ''))),
                    'respuesta' => trim($p['respuesta']),
                ], $datos['datos']['preguntas'] ?? []);
            }
            $guardado['datos'] = array_merge($guardado['datos'] ?? [], $enviados);
        }

        $actual['asistente'] = $guardado;
        if ($fila) {
            DB::table('ajustes')->where('id', 1)
                ->update(['data' => json_encode($actual, JSON_UNESCAPED_UNICODE)]);
        } else {
            DB::table('ajustes')->insert([
                'id' => 1, 'data' => json_encode($actual, JSON_UNESCAPED_UNICODE),
            ]);
        }

        return self::conGastoDeHoy($this->asistenteConfig());
    }

    /**
     * Lo guardado mezclado con los defaults, **campo por campo y anidado**:
     * `frases` y `menu` se fusionan uno a uno, no se reemplazan enteros.
     */
    public static function asistenteConfig(): array
    {
        $fila = DB::table('ajustes')->where('id', 1)->first();
        $data = $fila && $fila->data ? (json_decode($fila->data, true) ?: []) : [];
        $guardado = $data['asistente'] ?? [];

        $frases = array_merge(self::ASISTENTE_DEFAULT['frases'], $guardado['frases'] ?? []);
        $menu = array_merge(self::ASISTENTE_DEFAULT['menu'], $guardado['menu'] ?? []);

        // `datos` también campo a campo: una fila vieja no lo trae.
        $datosAsistente = array_merge(self::ASISTENTE_DEFAULT['datos'], $guardado['datos'] ?? []);

        return array_merge(self::ASISTENTE_DEFAULT, $guardado, [
            'frases' => $frases, 'menu' => $menu, 'datos' => $datosAsistente,
            // El vigente, venga del panel o del `.env`: la pantalla muestra lo
            // que de verdad frena a la IA, no solo lo que alguien guardó.
            'tokens_dia' => UsoAsistente::topeDe($guardado),
        ]);
    }

    private function campoOut($f): array
    {
        return [
            'id' => $f->id,
            'etiqueta' => $f->etiqueta,
            'tipo' => $f->tipo,
            'opciones' => $f->opciones ? (json_decode($f->opciones, true) ?: []) : [],
            'requerido' => (bool) $f->requerido,
            'oculto' => (bool) $f->oculto,
            'orden' => (int) $f->orden,
        ];
    }

    /**
     * `especialistaOut` + los datos de contacto. **SOLO para endpoints con
     * token.**
     *
     * Existe como envoltorio y no como un parámetro `incluirContacto` porque de
     * `especialistaOut` cuelgan dos endpoints PÚBLICOS —el listado del portal y
     * la página `/b/{slug}`, que consume el sitio de marketing— y un default
     * mal puesto ahí publica el correo y el teléfono de cada profesional a
     * cualquiera. Separadas, el endpoint público no tiene forma de devolverlos
     * ni por error (§9.13).
     */
    private function especialistaOutStaff($e): array
    {
        return [
            ...$this->especialistaOut($e),
            'email' => $e->email,
            'telefono' => $e->telefono,
            'recibe_avisos' => (bool) $e->recibe_avisos,
            'publico' => self::esPublico($e),
            // Solo staff: es el id de un calendario de la cuenta de la clínica.
            // `?? null` por si la migración no corrió (§13.13).
            'google_calendar_id' => $e->google_calendar_id ?? null,
        ];
    }

    /**
     * ¿Sale en lo que ve el público? (`especialistas.publico`, sep-2026)
     *
     * Tercer eje, distinto de los otros dos (§9.12): `activo=false` lo borra de
     * todo, incluido el asistente; `reservable=false` lo saca del autoservicio
     * pero lo deja en el equipo del sitio. `publico=false` es el que faltaba:
     * alguien con quien el asistente de WhatsApp coordina (un especialista
     * externo, o uno de prueba) y que no tiene que aparecer en el sitio, en el
     * portal ni en su página `/b/{slug}`. El panel y el asistente sí lo ven.
     *
     * `?? true` porque la columna la crea `migracion/mariadb_especialista_publico.sql`
     * y la migración va antes que el código, pero si no corrió, esto está en
     * `/catalogo/especialistas`, que es PÚBLICO: sin la guarda sería un 500 en
     * el sitio y el portal (§13.13). Sin la columna, todos son públicos, que es
     * lo que eran.
     */
    public static function esPublico(object $e): bool
    {
        return (bool) ($e->publico ?? true);
    }

    private function servicioOut($s, $porServicio): array
    {
        return [
            'id' => $s->id,
            'nombre' => $s->nombre,
            'descripcion' => $s->descripcion,
            'emoji' => $s->emoji,
            // Misma forma que `foto` del especialista: la columna guarda el
            // nombre del archivo y acá se arma la URL. `null` cuando no hay
            // foto, que es la señal con la que el portal decide caer al emoji.
            'imagen' => ($s->imagen ?? null) ? "/uploads/{$s->imagen}" : null,
            // (int) y no el valor crudo: PDO devuelve los enteros de MySQL como
            // string, y el arnés compara `30` contra `"30"` como distintos —
            // que es justo lo que debe hacer, porque para Vue tampoco son lo
            // mismo (regla 3 del comparador).
            'duracion_min' => $this->entero($s->duracion_min),
            'duracion_visible_min' => $this->entero($s->duracion_visible_min),
            'precio_clp' => $this->entero($s->precio_clp),
            'precio_sabado_clp' => $this->entero($s->precio_sabado_clp),
            'precio_detalle' => $s->precio_detalle,
            // `?:` y no el valor a secas: las filas anteriores a la migración
            // traen NULL y el portal necesita una regla siempre.
            'modalidad_pago' => $s->modalidad_pago ?: 'abono',
            'tarifa_por_especialista' => (bool) $s->tarifa_por_especialista,
            // Con abono fijo el total es desconocido: el portal muestra solo el
            // abono y no ofrece «pagar el total».
            'abono_fijo_clp' => $this->entero($s->abono_fijo_clp),
            'requiere_orden_examen' => (bool) $s->requiere_orden_examen,
            'coordinar_whatsapp' => CitasController::coordinaPorWhatsapp($s),
            'activo' => (bool) $s->activo,
            'orden' => $this->entero($s->orden),
            // Variantes de la misma prestacion: las que comparten `grupo` se
            // muestran como UNA entrada en el paso 1 de la reserva y se abren
            // en el paso 2, una tarjeta por variante. NULL = suelto.
            //
            // `?? null` y no `$s->grupo` a secas: la columna la crea
            // migrate_grupo_servicios.py y la MIGRACION VA ANTES QUE EL CODIGO
            // (§9.10). Si este codigo llega a una base donde el ALTER todavia
            // no corrio, sin esto seria un 500 en /catalogo/servicios — que es
            // PUBLICO y tumba el portal y el sitio. Con esto, el grupo
            // simplemente no viaja y el paso 1 se ve como siempre.
            'grupo' => $s->grupo ?? null,
            'especialista_ids' => ($porServicio[$s->id] ?? collect())
                ->pluck('especialista_id')->values(),
        ];
    }

    /**
     * GET /catalogo/especialistas?servicio_id=… — sin token.
     *
     * ⚠ **Este endpoint NO devuelve el correo ni el teléfono del especialista.**
     * Es dato privado y de acá cuelgan dos consumidores públicos: el portal de
     * reservas y el sitio de marketing. En Python la protección es estructural
     * —`_esp_out` no los tiene y `_esp_out_staff` es una función aparte— y acá
     * se replica igual: `especialistaOut()` no los conoce, así que no puede
     * publicarlos ni por error. Hay tests que lo cubren; no agregar los campos
     * a este método.
     */
    public function especialistas(Request $request)
    {
        $servicioId = $request->query('servicio_id');

        $esps = DB::table('especialistas')
            ->orderBy('orden')->orderBy('nombre')->orderBy('id')
            ->get()
            ->filter(fn ($e) => (bool) $e->activo)
            // Quien no es público no sale en NINGUNA de las dos listas: ni en el
            // equipo del sitio ni en el paso 2. Ver `esPublico()`.
            ->filter(fn ($e) => self::esPublico($e))
            ->values();

        // Con `servicio_id` esto es el paso «elige profesional» de una reserva;
        // sin él, es el equipo que muestra el sitio web. Necesitan listas
        // distintas: quien no es reservable tiene que salir en la página y no
        // en el selector.
        //
        // `solo_consulta_whatsapp` es la EXCEPCIÓN: para quien la tiene, el
        // portal muestra un botón de WhatsApp en vez del calendario, así que
        // sacarlo del selector no evita ningún calendario vacío — solo lo hace
        // desaparecer sin dejar ni el botón.
        if ($servicioId) {
            $esps = $esps->filter(
                fn ($e) => $e->reservable || $e->solo_consulta_whatsapp
            )->values();
        }

        $svc = $servicioId
            ? DB::table('servicios')->where('id', $servicioId)->first()
            : null;

        // Fila ausente en `servicio_especialista` = «lo atienden todos los
        // activos». Por eso solo se filtra cuando el servicio TIENE filas.
        if ($svc) {
            $ids = DB::table('servicio_especialista')
                ->where('servicio_id', $svc->id)->pluck('especialista_id');
            if ($ids->isNotEmpty()) {
                $esps = $esps->filter(fn ($e) => $ids->contains($e->id))->values();
            }
        }

        $salida = $esps->map(fn ($e) => $this->especialistaOut($e))->values()->all();

        // La consulta entera se coordina por WhatsApp (ecografía, radiología):
        // todos salen con el botón de WhatsApp en vez del calendario.
        if ($svc && CitasController::coordinaPorWhatsapp($svc)) {
            foreach ($salida as $i => $_) {
                $salida[$i]['solo_consulta_whatsapp'] = true;
            }
        }

        // Consulta que exige pago en línea pero de la que no sale ningún monto:
        // el backend rechaza esa reserva. Se marca acá para que el portal lo
        // derive a WhatsApp ANTES de que la persona elija día y hora y se lleve
        // el rechazo al final, con el formulario ya completo.
        if ($svc && ($svc->modalidad_pago ?: 'abono') !== 'nada') {
            foreach ($esps as $i => $e) {
                if ($this->cobro->montoAbono($e, $svc) === null) {
                    $salida[$i]['solo_consulta_whatsapp'] = true;
                }
            }
        }

        return $salida;
    }

    private function especialistaOut($e): array
    {
        // `dia_semana, id` y NO `dia_semana, hora_inicio, id`: es el orden que
        // declara la relación de SQLAlchemy (`models/especialista.py`), y hay
        // filas del mismo día cuyo orden entre sí depende solo del id. Poner
        // `hora_inicio` en medio parece más natural y da otro resultado — lo
        // cazó el arnés con 93 diferencias.
        //
        // Ojo con el contraste: `bloques` SÍ ordena por hora_inicio, porque su
        // relación así lo declara. No hay una regla general; cada relación
        // manda sobre la suya.
        $dispo = DB::table('disponibilidad_especialistas')
            ->where('especialista_id', $e->id)
            ->orderBy('dia_semana')->orderBy('id')
            ->get();

        $horarios = DB::table('horarios')
            ->where('especialista_id', $e->id)
            ->orderBy('orden')->orderBy('nombre')->orderBy('id')
            ->get();

        return [
            'id' => $e->id,
            'nombre' => $e->nombre,
            'especialidad' => $e->especialidad,
            'emoji' => $e->emoji,
            'slug' => $e->slug,
            'descripcion' => $e->descripcion,
            'foto' => $e->foto ? "/uploads/{$e->foto}" : null,
            'zona_horaria' => $e->zona_horaria ?: 'America/Santiago',
            'activo' => (bool) $e->activo,
            'orden' => $this->entero($e->orden),
            'disponibilidad_modo' => $e->disponibilidad_modo ?: 'todos',
            // JSON en MariaDB es LONGTEXT: llega como string y hay que
            // decodificarlo, o el frontend recibe un texto donde espera lista.
            'calendarios_conflicto' => $this->json($e->calendarios_conflicto) ?: [],
            'solo_consulta_whatsapp' => (bool) $e->solo_consulta_whatsapp,
            'reservable' => (bool) $e->reservable,
            'precio_clp' => $this->entero($e->precio_clp),
            'disponibilidad' => $dispo->map(fn ($d) => [
                'dia_semana' => $this->entero($d->dia_semana),
                'hora_inicio' => $d->hora_inicio,
                'hora_fin' => $d->hora_fin,
            ])->values(),
            'horarios' => $horarios->map(fn ($h) => [
                'nombre' => $h->nombre,
                'orden' => $this->entero($h->orden),
                'todos_servicios' => (bool) $h->todos_servicios,
                'servicio_ids' => $this->json($h->servicio_ids) ?: [],
                'valido_desde' => $this->soloFecha($h->valido_desde),
                'valido_hasta' => $this->soloFecha($h->valido_hasta),
                'semana_paridad' => $h->semana_paridad,
                'bloques' => DB::table('horario_bloques')
                    ->where('horario_id', $h->id)
                    ->orderBy('dia_semana')->orderBy('hora_inicio')->orderBy('id')
                    ->get()
                    ->map(fn ($b) => [
                        'dia_semana' => $this->entero($b->dia_semana),
                        'hora_inicio' => $b->hora_inicio,
                        'hora_fin' => $b->hora_fin,
                    ])->values(),
            ])->values(),
            'servicio_ids' => DB::table('servicio_especialista as se')
                ->join('servicios as s', 's.id', '=', 'se.servicio_id')
                ->where('se.especialista_id', $e->id)
                ->orderBy('s.orden')->orderBy('s.nombre')->orderBy('s.id')
                ->pluck('se.servicio_id')->values(),
        ];
    }

    /** Decodifica una columna JSON de MariaDB (que llega como texto). */
    private function json($v)
    {
        if ($v === null || $v === '') {
            return null;
        }
        return is_string($v) ? json_decode($v, true) : $v;
    }

    /** `2026-09-05 00:00:00` -> `2026-09-05`. Sin tocar la zona (§9.6). */
    private function soloFecha(?string $v): ?string
    {
        return $v === null ? null : substr($v, 0, 10);
    }

    /** NULL se conserva; el resto va como entero, nunca como string. */
    private function entero($v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
