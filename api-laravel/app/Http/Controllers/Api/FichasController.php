<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use App\Services\HistorialVentas;
use App\Services\Auditoria;
use App\Services\RecordatoriosPreventivos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Permisos;

/**
 * Fichas clínicas (EMR).
 *
 * **El control de acceso acá no es un middleware de área**, y es deliberado:
 * estas rutas mezclan dos caminos en la misma URL —el staff con permiso
 * `personas` ve cualquier ficha, y un dueño ve únicamente las de SUS
 * mascotas—, así que el portero tiene que decidir DENTRO del cuerpo, con la
 * mascota ya en la mano. Lo resuelve `puedeVer()`.
 *
 * Lo que sí cambió: antes decía «cualquier staff», y era el rol a secas. Eso
 * abría el historial médico completo a una cuenta restringida a Caja. Ahora
 * pregunta por el permiso (ver `App\Services\Permisos`).
 *
 * Esa segunda mitad es la que importa: el portal del cliente usa estas mismas
 * rutas, así que un fallo acá deja a un tutor leyendo la ficha de otro.
 */
class FichasController extends Controller
{
    public function __construct(
        private HistorialVentas $hv,
        private RecordatoriosPreventivos $recordatorios,
        private Auditoria $auditoria,
    ) {
    }

    public function verFicha(Request $request, string $mascotaId)
    {
        $usuario = $request->attributes->get('usuario');
        if (! $this->puedeVer($mascotaId, $usuario)) {
            return response()->json(['detail' => 'Sin permiso'], 403);
        }

        $ficha = $this->fichaDe($mascotaId);

        // Más reciente primero, con desempate hasta la PK: 172 de 6.688 notas
        // comparten `created_at` (§9.14).
        $notas = DB::table('notas_clinicas')
            ->where('ficha_id', $ficha->id)
            ->orderBy('created_at', 'desc')->orderBy('id', 'desc')
            ->get();

        $documentos = DB::table('documentos_clinicos')
            ->where('ficha_id', $ficha->id)
            ->orderBy('created_at', 'desc')->orderBy('id', 'desc')
            ->get();

        $espIds = $notas->pluck('especialista_id')->filter()->unique();
        $esps = $espIds->isEmpty() ? collect() : DB::table('especialistas')
            ->whereIn('id', $espIds)->pluck('nombre', 'id');

        return [
            'id' => $ficha->id,
            'mascota_id' => $ficha->mascota_id,
            'antecedentes' => $ficha->antecedentes,
            'vacunas' => $ficha->vacunas,
            'condiciones_cronicas' => $ficha->condiciones_cronicas,
            'caracter' => $ficha->caracter,
            'vif' => $ficha->vif,
            'vilef' => $ficha->vilef,
            'created_at' => $this->hv->iso($ficha->created_at),
            'notas' => $notas->map(fn ($n) => $this->notaOut($n, $esps))->values(),
            'documentos' => $documentos->map(fn ($d) => $this->documentoOut($d))->values(),
        ];
    }

    /** Datos de la mascota + su dueño, para el panel lateral de la ficha. */
    public function verPaciente(Request $request, string $mascotaId)
    {
        $usuario = $request->attributes->get('usuario');
        if (! $this->puedeVer($mascotaId, $usuario)) {
            return response()->json(['detail' => 'Sin permiso'], 403);
        }

        $fila = DB::table('mascotas as m')
            ->join('usuarios as u', 'u.id', '=', 'm.usuario_id')
            ->where('m.id', $mascotaId)
            ->select('m.*', 'u.nombre as u_nombre', 'u.email as u_email',
                     'u.telefono as u_telefono')
            ->first();
        if (! $fila) {
            return response()->json(['detail' => 'Paciente no encontrado'], 404);
        }

        return [
            'id' => $fila->id,
            'nombre' => $fila->nombre,
            'especie' => $fila->especie,
            'raza' => $fila->raza,
            'sexo' => $fila->sexo,
            'fecha_nacimiento' => $fila->fecha_nacimiento
                ? substr($fila->fecha_nacimiento, 0, 10) : null,
            // **String, no float**, y con la escala intacta: `MascotaResponse`
            // declara `peso_kg: Optional[Decimal]`, y Pydantic serializa un
            // Decimal como TEXTO conservando los decimales (`"7.90"`).
            //
            // Ojo con el contraste: en `/inventario` los mismos Decimal salen
            // como número (`10000.0`) porque allá el endpoint devuelve el
            // objeto ORM y Pydantic lo convierte a float. Lo que manda es el
            // tipo DECLARADO en el schema, no la columna. Un `(float)` acá
            // produce 7.9 y rompe el contrato.
            'peso_kg' => $fila->peso_kg === null ? null : (string) $fila->peso_kg,
            'microchip_codigo' => $fila->microchip_codigo,
            // Campo del schema que no es columna del modelo: se declara en
            // `MascotaResponse` y sale siempre, aunque valga null.
            'foto_url' => $fila->foto_url ?? null,
            'esterilizado' => $fila->esterilizado === null ? null : (bool) $fila->esterilizado,
            'alergias' => $fila->alergias,
            'estado' => $fila->estado,
            'usuario_id' => $fila->usuario_id,
            'created_at' => $this->hv->iso($fila->created_at),
            'dueno_nombre' => $fila->u_nombre,
            'dueno_email' => $fila->u_email,
            'dueno_telefono' => $fila->u_telefono,
        ];
    }

    /**
     * GET /fichas/mascota/{id}/historial
     *
     * Las visitas y las compras de esta mascota, en dos listas.
     *
     * **No se cruzan entre sí**: no existe ningún vínculo en la base entre una
     * cita y la transacción que la cobró, así que se listan aparte en vez de
     * inventar una correspondencia que el sistema no puede garantizar.
     */
    public function historial(Request $request, string $mascotaId)
    {
        $usuario = $request->attributes->get('usuario');
        if (! $this->puedeVer($mascotaId, $usuario)) {
            return response()->json(['detail' => 'Sin permiso'], 403);
        }

        $citas = DB::table('citas')
            ->where('mascota_id', $mascotaId)
            ->orderBy('fecha_hora', 'desc')->orderBy('id', 'desc')
            ->get();

        // Los nombres se resuelven en LOTE, no una consulta por cita.
        $espIds = $citas->pluck('especialista_id')->filter()->unique();
        $svcIds = $citas->pluck('servicio_id')->filter()->unique();
        $esps = $espIds->isEmpty() ? collect()
            : DB::table('especialistas')->whereIn('id', $espIds)->pluck('nombre', 'id');
        $svcs = $svcIds->isEmpty() ? collect()
            : DB::table('servicios')->whereIn('id', $svcIds)->pluck('nombre', 'id');

        $ventas = DB::table('ventas')
            ->where('mascota_id', $mascotaId)
            ->orderBy('fecha', 'desc')->orderBy('id', 'desc')
            ->get();

        return [
            'citas' => $citas->map(fn ($c) => [
                'tipo' => 'cita',
                'fecha' => $this->hv->iso($c->fecha_hora),
                'especialista' => $esps[$c->especialista_id] ?? null,
                // Del CATÁLOGO, no de la columna denormalizada: una cita cuyo
                // servicio se borró del catálogo sale con `null` acá, aunque
                // `citas.servicio` conserve el texto. Es lo que hace Python.
                'servicio' => $svcs[$c->servicio_id] ?? null,
                'estado' => $c->estado,
                'payment_status' => $c->payment_status,
            ])->values(),
            'compras' => $ventas->map(fn ($v) => [
                'tipo' => 'compra',
                'fecha' => $this->hv->iso($v->fecha),
                'nombre' => $v->nombre,
                'cantidad' => $v->cantidad === null ? null : (int) $v->cantidad,
                // NÚMERO acá: este endpoint arma los dicts a mano con `float()`.
                'precio_venta' => (float) ($v->precio_venta ?? 0),
            ])->values(),
        ];
    }

    /**
     * Quién puede leer esta ficha.
     *
     * Staff: todas. Dueño: solo las de sus mascotas. Cualquier otro rol, no.
     */
    private function puedeVer(string $mascotaId, $user): bool
    {
        // El PERMISO, no el rol. `personas` es el mismo que ya exigían la
        // escritura de notas y la subida de documentos de este mismo
        // controlador: leer la ficha no puede pedir menos que escribirla.
        if (in_array($user->rol, ['veterinario', 'admin'], true)) {
            return Permisos::tiene($user, 'personas');
        }
        $m = DB::table('mascotas')->where('id', $mascotaId)->first();
        return $m && $m->usuario_id === $user->id;
    }

    /**
     * La ficha de una mascota, **creándola si no existe**.
     *
     * Sí: un GET que escribe. Es lo que hace Python (`_get_or_create_ficha`) y
     * hay que replicarlo o la primera visita a un paciente nuevo devolvería 404
     * en Laravel y una ficha vacía en Python. La ficha es el contenedor del
     * historial, no un dato en sí: crearla al mirarla no inventa información.
     */
    public function fichaDe(string $mascotaId)
    {
        $ficha = DB::table('fichas_clinicas')->where('mascota_id', $mascotaId)->first();
        if ($ficha) {
            return $ficha;
        }
        $id = (string) Str::uuid();
        DB::table('fichas_clinicas')->insert([
            'id' => $id,
            'mascota_id' => $mascotaId,
            'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
        return DB::table('fichas_clinicas')->where('id', $id)->first();
    }

    private function notaOut($n, $esps): array
    {
        return [
            'motivo' => $n->motivo,
            'examen' => $n->examen,
            'conciencia' => $n->conciencia,
            'condicion_corporal' => $n->condicion_corporal,
            'mucosas' => $n->mucosas,
            'hidratacion' => $n->hidratacion,
            // `null` significa «no se midió», y es lo que la ficha del tutor
            // usa para decidir qué mostrarle: un 0 diría otra cosa.
            'frecuencia_cardiaca' => $n->frecuencia_cardiaca === null
                ? null : (int) $n->frecuencia_cardiaca,
            'frecuencia_respiratoria' => $n->frecuencia_respiratoria === null
                ? null : (int) $n->frecuencia_respiratoria,
            // **String, no float.** `NotaCreate` declara `temperatura:
            // Optional[Decimal]` y Pydantic serializa un Decimal como TEXTO
            // conservando la escala: `"38.5"`, no `38.5`. Mismo caso que
            // `peso_kg` en `verPaciente` — lo que manda es el tipo DECLARADO
            // en el schema, no la columna. Un `(float)` acá rompe el contrato,
            // y lo encontró el arnés en las tres respuestas de nota.
            'temperatura' => $n->temperatura === null ? null : (string) $n->temperatura,
            'dolor_escala' => $n->dolor_escala,
            'dolor_origen' => $n->dolor_origen,
            'periodontal' => $n->periodontal,
            'prediagnosticos' => $n->prediagnosticos,
            'examenes_complementarios' => $n->examenes_complementarios,
            'diagnostico' => $n->diagnostico,
            'tratamiento' => $n->tratamiento,
            'indicaciones' => $n->indicaciones,
            'sanitario' => $n->sanitario,
            'proximo_control' => $n->proximo_control
                ? substr($n->proximo_control, 0, 10) : null,
            'cita_id' => $n->cita_id,
            'especialista_id' => $n->especialista_id,
            'id' => $n->id,
            'ficha_id' => $n->ficha_id,
            'veterinario_id' => $n->veterinario_id,
            'created_at' => $this->hv->iso($n->created_at),
            // Quién atendió, cuando no es quien escribe la nota
            // (interconsulta con un especialista externo).
            'atendido_por' => $esps[$n->especialista_id] ?? null,
        ];
    }

    /**
     * Los campos de una nota, con sus topes.
     *
     * Los rangos NO son decoración: atajan el cero de más al tipear —una
     * frecuencia de 1200, una temperatura de 385— antes de que quede guardado
     * como si fuera un hallazgo clínico. Vienen de `NotaCreate` y son parte
     * del contrato: Python responde 422 y acá tiene que responder lo mismo.
     */
    private function reglasNota(): array
    {
        return [
            'motivo' => 'nullable|string',
            'examen' => 'nullable|string',
            'conciencia' => 'nullable|string|max:30',
            'condicion_corporal' => 'nullable|string|max:20',
            'mucosas' => 'nullable|string|max:30',
            'hidratacion' => 'nullable|string|max:30',
            'frecuencia_cardiaca' => 'nullable|integer|min:0|max:400',
            'frecuencia_respiratoria' => 'nullable|integer|min:0|max:300',
            'temperatura' => 'nullable|numeric|min:20|max:45',
            'dolor_escala' => 'nullable|string|max:20',
            'dolor_origen' => 'nullable|string|max:150',
            'periodontal' => 'nullable|string|max:20',
            'prediagnosticos' => 'nullable|string',
            'examenes_complementarios' => 'nullable|string',
            'diagnostico' => 'nullable|string',
            'tratamiento' => 'nullable|string',
            'indicaciones' => 'nullable|string',
            'sanitario' => 'nullable|string',
            'proximo_control' => 'nullable|date',
            'cita_id' => 'nullable|uuid',
            'especialista_id' => 'nullable|uuid',
        ];
    }

    /**
     * Un id de especialista inexistente rompía la FK recién en el commit: 500 y
     * «error del servidor», sin ninguna pista de que el problema era ese campo.
     */
    private function validarEspecialista($especialistaId): bool
    {
        if (empty($especialistaId)) {
            return true;
        }
        return DB::table('especialistas')->where('id', $especialistaId)->exists();
    }

    /** La nota recién guardada, con «Atendido por» ya resuelto. */
    private function notaFresca(string $notaId)
    {
        $n = DB::table('notas_clinicas')->where('id', $notaId)->first();
        $esps = $n->especialista_id
            ? DB::table('especialistas')->where('id', $n->especialista_id)->pluck('nombre', 'id')
            : collect();

        return $this->notaOut($n, $esps);
    }

    /**
     * POST /fichas/mascota/{id}/notas — la nota SOAP.
     *
     * La nota y su recordatorio entran **en la misma transacción**: una nota
     * con próximo control cuyo aviso no se creó es justo el silencio que este
     * mecanismo viene a cerrar. En Python es `flush()` + `commit()`; acá,
     * `DB::transaction`.
     */
    public function crearNota(Request $request, string $mascotaId)
    {
        $datos = $request->validate($this->reglasNota());
        $staff = $request->attributes->get('usuario');

        if (! $this->validarEspecialista($datos['especialista_id'] ?? null)) {
            return response()->json(['detail' => 'No existe el especialista indicado.'], 400);
        }

        $ficha = $this->fichaDe($mascotaId);
        $id = (string) Str::uuid();

        DB::transaction(function () use ($datos, $ficha, $staff, $id) {
            $fila = [];
            foreach (array_keys($this->reglasNota()) as $campo) {
                // `?? null` y no `$datos[$campo]`: `validate()` NO devuelve las
                // claves ausentes del cuerpo, y leerlas directo es un 500.
                $fila[$campo] = $datos[$campo] ?? null;
            }
            $fila['proximo_control'] = $fila['proximo_control']
                ? substr($fila['proximo_control'], 0, 10) : null;
            $fila['id'] = $id;
            $fila['ficha_id'] = $ficha->id;
            $fila['veterinario_id'] = $staff->id;
            $fila['created_at'] = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

            DB::table('notas_clinicas')->insert($fila);

            $nota = DB::table('notas_clinicas')->where('id', $id)->first();
            $this->recordatorios->sincronizarDesdeNota($nota);
        });

        return response()->json($this->notaFresca($id), 201);
    }

    /**
     * PATCH /fichas/notas/{id} — corregir una nota.
     *
     * Corregir la fecha corrige el aviso; borrarla lo retira. Ver
     * `sincronizarDesdeNota`: nunca toca uno que ya se envió.
     */
    public function editarNota(Request $request, string $notaId)
    {
        $nota = DB::table('notas_clinicas')->where('id', $notaId)->first();
        if (! $nota) {
            return response()->json(['detail' => 'Nota no encontrada'], 404);
        }

        $datos = $request->validate($this->reglasNota());

        // `exclude_unset=True`: solo los campos QUE VINIERON en el cuerpo. Un
        // PATCH con dos campos no puede borrar los otros dieciocho.
        $cambios = [];
        foreach (array_keys($this->reglasNota()) as $campo) {
            if ($request->exists($campo)) {
                $cambios[$campo] = $datos[$campo] ?? null;
            }
        }

        if (array_key_exists('especialista_id', $cambios)
            && ! $this->validarEspecialista($cambios['especialista_id'])) {
            return response()->json(['detail' => 'No existe el especialista indicado.'], 400);
        }

        if (array_key_exists('proximo_control', $cambios) && $cambios['proximo_control']) {
            $cambios['proximo_control'] = substr($cambios['proximo_control'], 0, 10);
        }

        DB::transaction(function () use ($cambios, $notaId) {
            if ($cambios) {
                DB::table('notas_clinicas')->where('id', $notaId)->update($cambios);
            }
            $fresca = DB::table('notas_clinicas')->where('id', $notaId)->first();
            $this->recordatorios->sincronizarDesdeNota($fresca);
        });

        return $this->notaFresca($notaId);
    }

    /**
     * DELETE /fichas/notas/{id} — borrado definitivo. **Solo admin**, a
     * diferencia de crear y editar, que le bastan a cualquier staff con
     * permiso `personas`.
     */
    public function eliminarNota(Request $request, string $notaId)
    {
        $nota = DB::table('notas_clinicas')->where('id', $notaId)->first();
        if (! $nota) {
            return response()->json(['detail' => 'Nota no encontrada'], 404);
        }
        $actor = $request->attributes->get('usuario');

        DB::transaction(function () use ($nota, $actor) {
            $this->auditoria->registrar($actor, 'eliminar', 'personas', 'nota_clinica',
                $nota->id, ['ficha_id' => (string) $nota->ficha_id]);

            // Los avisos que generó esta nota se sueltan ANTES de borrarla: la
            // clave foránea impediría el borrado, y los ya enviados son
            // historial que no corresponde eliminar junto con la nota.
            DB::table('recordatorios')->where('nota_id', $nota->id)
                ->update(['nota_id' => null]);

            DB::table('notas_clinicas')->where('id', $nota->id)->delete();
        });

        return response()->noContent();
    }

    /**
     * PATCH /fichas/mascota/{id} — los datos permanentes de la ficha
     * (antecedentes, vacunas, crónicas, carácter, VIF/VILEF).
     *
     * Solo los campos QUE VINIERON: `exclude_unset=True`. Un PATCH con
     * «antecedentes» no puede vaciar las condiciones crónicas.
     */
    public function actualizarFicha(Request $request, string $mascotaId)
    {
        $campos = ['antecedentes', 'vacunas', 'condiciones_cronicas',
                   'caracter', 'vif', 'vilef'];
        $datos = $request->validate(array_fill_keys($campos, 'nullable|string'));

        $ficha = $this->fichaDe($mascotaId);

        $cambios = [];
        foreach ($campos as $campo) {
            if ($request->exists($campo)) {
                $cambios[$campo] = $datos[$campo] ?? null;
            }
        }
        if ($cambios) {
            DB::table('fichas_clinicas')->where('id', $ficha->id)->update($cambios);
        }

        return $this->verFicha($request, $mascotaId);
    }

    /**
     * DELETE /fichas/mascota/{id} — vacía la ficha entera.
     *
     * **El paciente NO se borra**: se borra su historial. La ficha se recrea
     * vacía la próxima vez que alguien la abra (`fichaDe` la crea al vuelo).
     *
     * Pide escribir el nombre exacto del paciente en `?confirmar=`. No es
     * ceremonia: esto borra notas clínicas y documentos SIN vuelta atrás, y el
     * archivo se va del disco además de la fila.
     */
    public function vaciarFicha(Request $request, string $mascotaId)
    {
        $mascota = DB::table('mascotas')->where('id', $mascotaId)->first();
        if (! $mascota) {
            return response()->json(['detail' => 'Paciente no encontrado'], 404);
        }

        $confirmar = trim((string) $request->query('confirmar', ''));
        if ($confirmar !== trim((string) ($mascota->nombre ?? ''))) {
            return response()->json(
                ['detail' => 'Debes escribir el nombre exacto del paciente para confirmar.'], 400);
        }

        $ficha = DB::table('fichas_clinicas')->where('mascota_id', $mascotaId)->first();

        // Sin ficha no hay nada que vaciar, y Python igual responde 204: el
        // resultado pedido —que no quede historial— ya se cumple.
        if ($ficha) {
            $actor = $request->attributes->get('usuario');
            $docs = DB::table('documentos_clinicos')->where('ficha_id', $ficha->id)->get();

            DB::transaction(function () use ($ficha, $actor, $mascota) {
                $this->auditoria->registrar($actor, 'vaciar', 'personas', 'ficha_clinica',
                    $ficha->id, ['mascota' => $mascota->nombre]);

                // Los avisos que generó cada nota se sueltan ANTES de
                // borrarlas, igual que en `eliminarNota`: `recordatorios.nota_id`
                // es una FK sin cascada y borrar la nota con un aviso colgando
                // revienta.
                //
                // El arnés encontró acá dos cosas de una: que Python tenía este
                // mismo bug (500 al vaciar) y que MariaDB no tenía NINGUNA de
                // las 47 claves foráneas, así que el port «pasaba» por no tener
                // nada que violar. Se arreglaron las dos.
                $notasIds = DB::table('notas_clinicas')
                    ->where('ficha_id', $ficha->id)->pluck('id');
                if ($notasIds->isNotEmpty()) {
                    DB::table('recordatorios')->whereIn('nota_id', $notasIds)
                        ->update(['nota_id' => null]);
                }
                DB::table('notas_clinicas')->where('ficha_id', $ficha->id)->delete();
                DB::table('documentos_clinicos')->where('ficha_id', $ficha->id)->delete();
                DB::table('fichas_clinicas')->where('id', $ficha->id)->delete();
            });

            // Los archivos se borran DESPUÉS de que la transacción cerró: un
            // archivo borrado no vuelve con un rollback, así que primero se
            // confirma la base y recién entonces se toca el disco.
            foreach ($docs as $d) {
                $ruta = $this->rutaDocumento($d->nombre_archivo);
                if ($ruta && is_file($ruta)) {
                    @unlink($ruta);
                }
            }
        }

        return response()->noContent();
    }

    /**
     * Dónde está este documento hoy: en el directorio privado si ya se movió, y
     * si no, en el antiguo. `documentos_clinicos/` y `uploads/` son hermanos y
     * SEPARADOS a propósito (§9.16): el segundo se sirve estático sin
     * autenticación, y juntarlos volvería descargable una radiografía sin token.
     */
    public function rutaDocumento(?string $nombreArchivo): ?string
    {
        if (! $nombreArchivo) {
            return null;
        }
        $nueva = rtrim(config('emr.documentos_dir'), '/') . '/' . $nombreArchivo;
        if (is_file($nueva)) {
            return $nueva;
        }
        $antigua = rtrim(config('emr.uploads_dir'), '/') . '/' . $nombreArchivo;
        return is_file($antigua) ? $antigua : $nueva;
    }

    /**
     * Extensiones aceptadas, con el MIME que se guarda en la fila.
     *
     * Lista BLANCA, nunca negra: enumerar lo prohibido deja pasar todo lo que
     * no se pensó.
     */
    public const EXTENSIONES = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public const TAMANO_MAX_BYTES = 10485760; // 10 MB

    private const TIPOS = ['examen', 'rx', 'receta', 'otro'];

    /**
     * POST /fichas/mascota/{id}/documentos — exámenes, RX, informes.
     *
     * Sube **staff** con permiso `personas`. El archivo va a
     * `documentos_clinicos/`, que no lo monta nadie: la única puerta de lectura
     * es `descargarDocumento`, que valida permiso. Escribirlo en `uploads/`
     * lo volvería descargable sin token (§9.16).
     */
    public function subirDocumento(Request $request, string $mascotaId)
    {
        $tipo = (string) $request->input('tipo', 'otro');
        if (! in_array($tipo, self::TIPOS, true)) {
            return response()->json(
                ['detail' => 'Tipo inválido (usa examen, rx, receta u otro).'], 400);
        }

        $archivo = $request->file('archivo');
        if (! $archivo || ! $archivo->isValid()) {
            return response()->json(['detail' => 'Falta el archivo.'], 400);
        }

        $ext = strtolower($archivo->getClientOriginalExtension());
        if (! isset(self::EXTENSIONES[$ext])) {
            return response()->json(
                ['detail' => 'Formato no soportado (PDF, DOC/DOCX, TXT, JPG, PNG o WebP).'], 400);
        }
        if ($archivo->getSize() > self::TAMANO_MAX_BYTES) {
            return response()->json(['detail' => 'El archivo no puede superar 10 MB.'], 400);
        }

        $ficha = $this->fichaDe($mascotaId);

        // La nota, si se indicó, tiene que ser DE ESTA ficha: sin esa condición
        // un documento podría colgarse de la nota de otro paciente.
        $notaId = $request->input('nota_id');
        if ($notaId) {
            $existe = DB::table('notas_clinicas')
                ->where('id', $notaId)->where('ficha_id', $ficha->id)->exists();
            if (! $existe) {
                return response()->json(['detail' => 'Nota no encontrada en esta ficha.'], 404);
            }
        }

        $nombreArchivo = 'doc-' . $ficha->id . '-'
            . substr(bin2hex(random_bytes(8)), 0, 10) . '.' . $ext;

        $destino = rtrim(config('emr.documentos_dir'), '/');
        if (! is_dir($destino)) {
            // Obligatorio: un volumen recién creado se monta vacío (§9.16).
            mkdir($destino, 0775, true);
        }
        $archivo->move($destino, $nombreArchivo);

        $staff = $request->attributes->get('usuario');
        $id = (string) Str::uuid();
        DB::table('documentos_clinicos')->insert([
            'id' => $id,
            'ficha_id' => $ficha->id,
            'nota_id' => $notaId ?: null,
            'subido_por_id' => $staff->id,
            'tipo' => $tipo,
            'descripcion' => $request->input('descripcion'),
            'nombre_original' => $archivo->getClientOriginalName() ?: $nombreArchivo,
            'nombre_archivo' => $nombreArchivo,
            'mime_type' => self::EXTENSIONES[$ext],
            'tamano_bytes' => filesize($destino . '/' . $nombreArchivo),
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);

        $d = DB::table('documentos_clinicos')->where('id', $id)->first();

        return response()->json($this->documentoOut($d), 201);
    }

    /**
     * GET /fichas/mis-documentos — los documentos de TODAS las mascotas de quien
     * pregunta, para la sección Documentos del portal del cliente.
     *
     * Existe para no pedir `/fichas/mascota/{id}` una vez por mascota: esa ruta
     * trae además las notas clínicas enteras, y **crea la ficha si no existe**
     * (ver `fichaDe()`), así que recorrerla solo para listar archivos escribiría
     * fichas vacías de paso. Acá se lee y nada más: una mascota sin ficha
     * simplemente no aporta documentos.
     *
     * Filtra por `mascotas.usuario_id` del usuario del token, sea quien sea: un
     * miembro del equipo recibe los de sus propias mascotas, no los de la
     * clínica. Para ver los de un paciente, el panel ya tiene la ficha.
     *
     * Incluye las mascotas fallecidas, igual que `/mascotas/`: un examen sigue
     * siendo del tutor aunque el animal ya no esté.
     */
    public function misDocumentos(Request $request)
    {
        $usuario = $request->attributes->get('usuario');

        $filas = DB::table('documentos_clinicos as d')
            ->join('fichas_clinicas as f', 'f.id', '=', 'd.ficha_id')
            ->join('mascotas as m', 'm.id', '=', 'f.mascota_id')
            ->where('m.usuario_id', $usuario->id)
            ->select('d.*', 'm.id as m_id', 'm.nombre as m_nombre', 'm.especie as m_especie')
            // Desempate hasta la PK (§9.14).
            ->orderBy('d.created_at', 'desc')->orderBy('d.id', 'desc')
            ->get();

        return $filas->map(fn ($d) => $this->documentoOut($d) + [
            'mascota_id' => $d->m_id,
            'mascota_nombre' => $d->m_nombre,
            'mascota_especie' => $d->m_especie,
        ])->values();
    }

    /**
     * GET /fichas/documentos/{id}/archivo — **la única puerta al archivo**.
     *
     * `auth` a secas y `puedeVer()`, igual que la ficha: el tutor baja los
     * documentos de SUS mascotas desde el portal, y el staff los de cualquiera.
     * Subir es solo staff; LEER es de los dos, y por eso el permiso se decide
     * acá y no en la ruta.
     */
    public function descargarDocumento(Request $request, string $documentoId)
    {
        $doc = DB::table('documentos_clinicos')->where('id', $documentoId)->first();
        if (! $doc) {
            return response()->json(['detail' => 'Documento no encontrado'], 404);
        }

        $ficha = DB::table('fichas_clinicas')->where('id', $doc->ficha_id)->first();
        $usuario = $request->attributes->get('usuario');
        if (! $ficha || ! $this->puedeVer($ficha->mascota_id, $usuario)) {
            return response()->json(['detail' => 'Sin permiso'], 403);
        }

        $ruta = $this->rutaDocumento($doc->nombre_archivo);
        if (! $ruta || ! is_file($ruta)) {
            return response()->json(['detail' => 'El archivo ya no está disponible.'], 404);
        }

        return response()->download($ruta, $doc->nombre_original,
            ['Content-Type' => $doc->mime_type]);
    }

    /** DELETE /fichas/documentos/{id} — borra la fila y el archivo. */
    public function eliminarDocumento(Request $request, string $documentoId)
    {
        $doc = DB::table('documentos_clinicos')->where('id', $documentoId)->first();
        if (! $doc) {
            return response()->json(['detail' => 'Documento no encontrado'], 404);
        }

        $ruta = $this->rutaDocumento($doc->nombre_archivo);
        DB::table('documentos_clinicos')->where('id', $doc->id)->delete();
        // El archivo, después de la fila: un unlink no se revierte.
        if ($ruta && is_file($ruta)) {
            @unlink($ruta);
        }

        return response()->noContent();
    }

    private function documentoOut($d): array
    {
        return [
            'id' => $d->id,
            'ficha_id' => $d->ficha_id,
            'nota_id' => $d->nota_id,
            'tipo' => $d->tipo,
            'descripcion' => $d->descripcion,
            'nombre_original' => $d->nombre_original,
            'mime_type' => $d->mime_type,
            'tamano_bytes' => $d->tamano_bytes === null ? null : (int) $d->tamano_bytes,
            'created_at' => $this->hv->iso($d->created_at),
            // La URL se arma acá; no es columna del modelo. El archivo NO se
            // sirve estático: `documentos_clinicos/` no lo monta nadie, a
            // diferencia de `uploads/` (§9.16). Una radiografía no puede ser
            // descargable sin token.
            'url' => "/fichas/documentos/{$d->id}/archivo",
        ];
    }
}
