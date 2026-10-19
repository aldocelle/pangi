<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Services\ConfigClinica;
use App\Services\Correo;
use App\Services\Esquema;
use App\Services\MensajeRecordatorio;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Fechas;

/**
 * Medicina preventiva: qué le toca a cada mascota y cuándo (permiso
 * «recordatorios»).
 *
 * NO confundir con los recordatorios de CITA («tu hora es mañana»), que son
 * logística. Estos son clínicos —la vacuna que vence, el control que sigue— y
 * su resultado esperado es que el dueño *pida* una hora.
 *
 * El listado ordena por fecha ASCENDENTE a propósito: lo vencido arriba, porque
 * es exactamente el trabajo pendiente. Un recordatorio que se pasa no genera
 * ningún error visible; simplemente el animal no vuelve.
 */
class RecordatoriosController extends Controller
{
    private const LIMITE_MAX = 20;

    private const ESTADOS = ['pendiente', 'enviado', 'completado', 'cancelado'];

    /**
     * El importador de ACVet le inventa un correo a quien no lo trae
     * (`legado-<id>@sin-correo.local`, 1.047 usuarios) porque la columna es NOT
     * NULL y única. Son direcciones que no existen: ofrecerlas como `mailto:`
     * hace que alguien escriba al vacío creyendo que avisó.
     */
    private const DOMINIO_SIN_CORREO = '@sin-correo.local';

    /** Campos que el panel ve y el dueño no, en `/recordatorios/mios`. */
    private const SOLO_EQUIPO = ['notas'];

    public function __construct(private Auditoria $auditoria, private Correo $correo)
    {
    }

    /** Envío masivo: tope por clic, para que un error no despache cientos de correos. */
    private const MASIVO_MAX = 50;

    /** GET /recordatorios */
    public function listar(Request $request)
    {
        $q = DB::table('recordatorios as r');

        if ($e = $request->query('estado')) {
            $q->where('r.estado', $e);
        }
        if ($m = $request->query('mascota_id')) {
            $q->where('r.mascota_id', $m);
        }
        if ($d = $request->query('desde')) {
            $q->where('r.fecha_programada', '>=', substr($d, 0, 10));
        }
        if ($h = $request->query('hasta')) {
            $q->where('r.fecha_programada', '<=', substr($h, 0, 10));
        }
        if (filter_var($request->query('solo_vencidos', false), FILTER_VALIDATE_BOOLEAN)) {
            $q->where('r.estado', 'pendiente')
              ->where('r.fecha_programada', '<', $this->hoy());
        }
        if ($c = $request->query('canal')) {
            $q->where('r.canal', $c);
        }
        // Lo que SALIÓ en un período (panel de Mensajes): por `enviado_at`, no
        // por la fecha del control, que puede ser semanas después.
        if ($d = $request->query('enviado_desde')) {
            $q->where('r.enviado_at', '>=', substr($d, 0, 10) . ' 00:00:00');
        }
        if ($h = $request->query('enviado_hasta')) {
            $q->where('r.enviado_at', '<=', substr($h, 0, 10) . ' 23:59:59.999999');
        }
        $porEnvio = $request->query('enviado_desde') || $request->query('enviado_hasta');
        if ($busca = trim((string) $request->query('q', ''))) {
            $patron = '%' . $this->escaparLike($busca) . '%';
            $q->join('mascotas as m', 'm.id', '=', 'r.mascota_id')
              ->leftJoin('usuarios as u', 'u.id', '=', 'r.usuario_dueno_id')
              ->where(function ($w) use ($patron) {
                  $w->where('m.nombre', 'like', $patron)
                    ->orWhere('r.titulo', 'like', $patron)
                    ->orWhere('u.nombre', 'like', $patron);
              });
        }

        // Ascendente: lo más atrasado primero. Es una cola de trabajo, no un
        // feed. 211 de 264 comparten `fecha_programada`, así que el desempate
        // por id no es opcional (§9.14).
        // En el registro de envíos manda el último enviado arriba: ahí se
        // pregunta «¿qué salió esta semana?», no «¿qué hay que hacer?».
        $porEnvio
            ? $q->select('r.*')->orderByDesc('r.enviado_at')->orderBy('r.id')
            : $q->select('r.*')->orderBy('r.fecha_programada')->orderBy('r.id');

        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min((int) $request->query('limit', self::LIMITE_MAX), self::LIMITE_MAX));
        $total = (clone $q)->reorder()->count();
        $filas = $q->offset(($page - 1) * $limit)->limit($limit)->get();

        [$mascotas, $duenos] = $this->contexto($filas);

        return [
            'items' => $filas->map(fn ($r) => $this->out($r, $mascotas, $duenos))->values(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_paginas' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /** GET /recordatorios/resumen — los tres números para decidir si actuar hoy. */
    public function resumen()
    {
        $hoy = $this->hoy();
        $base = fn () => DB::table('recordatorios')->where('estado', 'pendiente');

        return [
            'vencidos' => $base()->where('fecha_programada', '<', $hoy)->count(),
            'hoy' => $base()->where('fecha_programada', $hoy)->count(),
            'proximos_30_dias' => $base()
                ->where('fecha_programada', '>', $hoy)
                ->where('fecha_programada', '<=',
                    CarbonImmutable::parse($hoy)->addDays(30)->format('Y-m-d'))
                ->count(),
        ];
    }

    /**
     * GET /recordatorios/mios — lo que el dueño logueado ve en su portal.
     *
     * Solo lo suyo y solo lo que sigue pendiente: lo cancelado y lo completado
     * no le aporta nada. Y **una mascota fallecida no aparece**.
     *
     * **Sin `notas`.** Es el campo de trabajo del equipo («llamé, no contesta»,
     * «cliente molesto por el precio») y salía entero hacia el dueño: el portal
     * no lo pintaba, pero viajaba en la respuesta y se leía con las
     * herramientas del navegador. Se quita acá y no en `out()`, que es también
     * lo que ve el panel, donde esas notas sí son para quien las lee.
     */
    public function mios(Request $request)
    {
        $usuario = $request->attributes->get('usuario');

        $filas = DB::table('recordatorios')
            ->where('usuario_dueno_id', $usuario->id)
            ->whereIn('estado', ['pendiente', 'enviado'])
            ->orderBy('fecha_programada')->orderBy('id')
            ->get();

        [$mascotas, $duenos] = $this->contexto($filas);

        return $filas
            ->filter(fn ($r) => ($mascotas[$r->mascota_id]->estado ?? 'Vivo') !== 'Fallecido')
            ->map(fn ($r) => array_diff_key(
                $this->out($r, $mascotas, $duenos),
                array_flip(self::SOLO_EQUIPO)
            ))
            ->values();
    }

    /** POST /recordatorios */
    public function crear(Request $request)
    {
        $datos = $request->validate([
            'mascota_id' => 'required|uuid',
            'titulo' => 'required|string|min:2|max:255',
            'categoria' => 'nullable|string|max:80',
            'mensaje' => 'nullable|string',
            'fecha_programada' => 'required|date',
            'dias_repeticion' => 'nullable|integer|min:1|max:3650',
        ]);

        $mascota = DB::table('mascotas')->where('id', $datos['mascota_id'])->first();
        if (! $mascota) {
            return response()->json(['detail' => 'No encontramos esa mascota.'], 404);
        }

        $actor = $request->attributes->get('usuario');
        $id = (string) Str::uuid();
        $fecha = substr($datos['fecha_programada'], 0, 10);
        $titulo = trim($datos['titulo']);

        DB::transaction(function () use ($datos, $id, $mascota, $actor, $fecha, $titulo) {
            DB::table('recordatorios')->insert([
                'id' => $id,
                'mascota_id' => $mascota->id,
                // El dueño se toma de la MASCOTA, no del cuerpo: así el aviso
                // no puede quedar apuntando a alguien que no es su tutor.
                'usuario_dueno_id' => $mascota->usuario_id,
                'titulo' => $titulo,
                'categoria' => $this->vacioANull($datos['categoria'] ?? null),
                'mensaje' => $this->vacioANull($datos['mensaje'] ?? null),
                'fecha_programada' => $fecha,
                'estado' => 'pendiente',
                'creado_por_id' => $actor->id,
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ] + (Esquema::columna('recordatorios', 'dias_repeticion')
                ? ['dias_repeticion' => $datos['dias_repeticion'] ?? null] : []));
            $this->auditoria->registrar($actor, 'crear', 'recordatorios', 'recordatorio', $id, [
                'titulo' => $titulo,
                'mascota' => $mascota->nombre,
                'fecha' => $fecha,
            ]);
        });

        return response()->json($this->unoOut($id), 201);
    }

    /** PATCH /recordatorios/{id} */
    public function actualizar(Request $request, string $recordatorioId)
    {
        $r = DB::table('recordatorios')->where('id', $recordatorioId)->first();
        if (! $r) {
            return response()->json(['detail' => 'Recordatorio no encontrado.'], 404);
        }

        $reglas = [
            'titulo' => 'nullable|string|min:2|max:255',
            'categoria' => 'nullable|string|max:80',
            'mensaje' => 'nullable|string',
            'fecha_programada' => 'nullable|date',
            'estado' => 'nullable|string',
            'canal' => 'nullable|string|max:20',
            'notas' => 'nullable|string',
        ];
        $datos = $request->validate($reglas);

        $cambios = [];
        foreach (array_keys($reglas) as $campo) {
            if ($request->exists($campo)) {
                $cambios[$campo] = $datos[$campo] ?? null;
            }
        }

        // El estado se valida a mano y devuelve **400**, no 422: es una regla de
        // negocio del router, no del schema.
        if (array_key_exists('estado', $cambios)
            && ! in_array($cambios['estado'], self::ESTADOS, true)) {
            return response()->json([
                'detail' => 'Estado inválido. Usa uno de: ' . implode(', ', self::ESTADOS) . '.',
            ], 400);
        }

        foreach (['titulo', 'categoria', 'mensaje', 'notas', 'canal'] as $campo) {
            if (array_key_exists($campo, $cambios) && is_string($cambios[$campo])) {
                $cambios[$campo] = $this->vacioANull($cambios[$campo]);
            }
        }
        if (array_key_exists('fecha_programada', $cambios) && $cambios['fecha_programada']) {
            $cambios['fecha_programada'] = substr($cambios['fecha_programada'], 0, 10);
        }

        $aEscribir = $cambios;
        // `enviado_at` lo pone el SISTEMA, no quien llama: es la evidencia de
        // cuándo se avisó y no debe poder fabricarse desde el cliente. Y solo
        // la primera vez — un segundo PATCH a «enviado» no reescribe la fecha.
        if (($cambios['estado'] ?? null) === 'enviado' && empty($r->enviado_at)) {
            // Con microsegundos: `ahora_local()` devuelve el datetime completo y
            // la columna es DATETIME(6). Sin `.u`, la marca sale truncada a
            // segundos y el listado la devuelve distinta — lo destapó el arnés
            // comparando `/recordatorios` y `/mios`.
            //
            // Hora de la CLÍNICA, no UTC: `enviado_at` es la única marca de
            // este módulo que una persona lee («¿cuándo se le avisó?»).
            $aEscribir['enviado_at'] = CarbonImmutable::now()->format('Y-m-d H:i:s.u');
        }

        // «Tiempo de repetición» de la plantilla: cuando la mascota vino, nace
        // el próximo aviso, contado desde HOY y no desde la fecha que tenía
        // (quien vino con un mes de atraso no debe recibir el siguiente un mes
        // antes). Solo en la transición a completado, para que marcarlo dos
        // veces no cree dos.
        $repetir = ($cambios['estado'] ?? null) === 'completado' && $r->estado !== 'completado'
            && Esquema::columna('recordatorios', 'dias_repeticion') && (int) ($r->dias_repeticion ?? 0) > 0;

        $siguienteId = null;
        DB::transaction(function () use ($request, $aEscribir, $cambios, $recordatorioId, $repetir, $r, &$siguienteId) {
            if ($aEscribir) {
                DB::table('recordatorios')->where('id', $recordatorioId)->update($aEscribir);
            }
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'editar', 'recordatorios', 'recordatorio', $recordatorioId, $cambios);
            if ($repetir) {
                $siguienteId = (string) Str::uuid();
                $fecha = CarbonImmutable::parse($this->hoy())->addDays((int) $r->dias_repeticion)->format('Y-m-d');
                DB::table('recordatorios')->insert([
                    'id' => $siguienteId,
                    'mascota_id' => $r->mascota_id,
                    'usuario_dueno_id' => $r->usuario_dueno_id,
                    'titulo' => $r->titulo,
                    'categoria' => $r->categoria,
                    'mensaje' => $r->mensaje,
                    'fecha_programada' => $fecha,
                    'dias_repeticion' => $r->dias_repeticion,
                    'estado' => 'pendiente',
                    'creado_por_id' => $request->attributes->get('usuario')->id,
                    'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                ]);
                $this->auditoria->registrar($request->attributes->get('usuario'),
                    'crear', 'recordatorios', 'recordatorio', $siguienteId,
                    ['titulo' => $r->titulo, 'fecha' => $fecha, 'repite' => $recordatorioId]);
            }
        });

        return $this->unoOut($recordatorioId) + ['siguiente_id' => $siguienteId];
    }

    /**
     * POST /recordatorios/envio-masivo — «Recordatorios masivos · Preventiva
     * vencida» de ACVet: un correo a cada tutor con un recordatorio vencido.
     *
     * Lo dispara una persona con un clic, pero **solo envía con
     * `CORREOS_AUTOMATICOS` encendido** (ver abajo, modo pruebas). Y con frenos:
     *
     *  · `simular=true` primero: el panel muestra cuántos saldrían y cuántos
     *    no (sin correo, fallecidos) y pide confirmar sobre ESE número.
     *  · Tope de 50 por clic, y solo lo vencido de los últimos 90 días
     *    (escribir por una vacuna de hace un año delata el silencio, igual que
     *    en el aviso automático).
     *  · Nunca a una mascota fallecida, nunca a un `@sin-correo.local`.
     *
     * WhatsApp no entra acá: fuera de la ventana de 24 h Meta exige una
     * plantilla aprobada por tipo de aviso, y todavía no hay número productivo.
     */
    public function envioMasivo(Request $request)
    {
        $simular = filter_var($request->input('simular', true), FILTER_VALIDATE_BOOLEAN);
        $hoy = $this->hoy();
        $piso = CarbonImmutable::parse($hoy)->subDays(90)->format('Y-m-d');
        $candidatos = DB::table('recordatorios')->where('estado', 'pendiente')
            ->where('fecha_programada', '<', $hoy)->where('fecha_programada', '>=', $piso)
            ->orderBy('fecha_programada')->orderBy('id')->get();
        [$mascotas, $duenos] = $this->contexto($candidatos);
        $config = ConfigClinica::leer('recordatorios');

        $enviables = [];
        $sinCorreo = 0;
        $fallecidas = 0;
        foreach ($candidatos as $r) {
            $m = $mascotas[$r->mascota_id] ?? null;
            $d = $r->usuario_dueno_id ? ($duenos[$r->usuario_dueno_id] ?? null) : null;
            if (! $m || ($m->estado ?? null) === 'Fallecido') {
                $fallecidas++;
                continue;
            }
            if (! $this->emailReal($d)) {
                $sinCorreo++;
                continue;
            }
            $enviables[] = [$r, $m, $d];
        }
        $resumen = [
            'vencidos' => count($candidatos),
            'enviables' => min(count($enviables), self::MASIVO_MAX),
            'sin_correo' => $sinCorreo,
            'fallecidas' => $fallecidas,
            'tope' => self::MASIVO_MAX,
            'quedan_despues' => max(0, count($enviables) - self::MASIVO_MAX),
        ];
        // MODO PRUEBAS (decisión de producto, 1-oct-2026: «no enviar mensajes de
        // recordatorios, estamos en modo pruebas»). Aunque lo dispare una
        // persona, mientras `CORREOS_AUTOMATICOS` esté apagado el masivo solo
        // CUENTA: la base trae ~2.700 tutores reales importados de ACVet, y un
        // clic de prueba les escribiría de verdad. Se enciende junto con los
        // avisos automáticos, el día que la clínica salga de pruebas.
        $habilitado = (bool) config('emr.correos_automaticos');
        if ($simular || ! $habilitado) {
            return $resumen + ['simulado' => true, 'envio_habilitado' => $habilitado];
        }

        $actor = $request->attributes->get('usuario');
        $enviados = 0;
        foreach (array_slice($enviables, 0, self::MASIVO_MAX) as [$r, $m, $d]) {
            [$asunto, $cuerpo] = MensajeRecordatorio::correo($d, $m, $r, $config);
            if (! $this->correo->enviar($d->email, $asunto, $cuerpo,
                    MensajeRecordatorio::html($d, $m, $r, $config))) {
                continue;   // se reintenta en el próximo envío; no se marca
            }
            DB::table('recordatorios')->where('id', $r->id)->update([
                'estado' => 'enviado', 'canal' => 'email',
                'enviado_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
            ]);
            $enviados++;
        }
        $this->auditoria->registrar($actor, 'enviar', 'recordatorios', 'envio_masivo', null,
            ['enviados' => $enviados, 'sin_correo' => $sinCorreo, 'fallecidas' => $fallecidas]);

        return $resumen + ['simulado' => false, 'enviados' => $enviados];
    }

    /** GET /recordatorios/resumen-envios?desde=&hasta= — conteo por canal (panel de Mensajes). */
    public function resumenEnvios(Request $request)
    {
        $desde = substr((string) $request->query('desde', $this->hoy()), 0, 10);
        $hasta = substr((string) $request->query('hasta', $this->hoy()), 0, 10);
        $filas = DB::table('recordatorios')->whereNotNull('enviado_at')
            ->where('enviado_at', '>=', "{$desde} 00:00:00")
            ->where('enviado_at', '<=', "{$hasta} 23:59:59.999999")
            // Agrupado por la columna sola y el NULL resuelto acá: con
            // `COALESCE(canal, ?)` el placeholder del SELECT y el literal del
            // GROUP BY no son la misma expresión para MariaDB, y con
            // ONLY_FULL_GROUP_BY (el del hosting) respondía 500. SQLite no lo
            // ve; lo encontró la prueba en el lab.
            ->select('canal', DB::raw('COUNT(*) as n'))->groupBy('canal')->get();
        $por = [];
        foreach ($filas as $f) {
            $c = $f->canal ?? 'sin canal';
            $por[$c] = ($por[$c] ?? 0) + (int) $f->n;
        }
        ksort($por);

        return ['desde' => $desde, 'hasta' => $hasta, 'por_canal' => $por, 'total' => array_sum($por)];
    }

    /** DELETE /recordatorios/{id} */
    public function eliminar(Request $request, string $recordatorioId)
    {
        $r = DB::table('recordatorios')->where('id', $recordatorioId)->first();
        if (! $r) {
            return response()->json(['detail' => 'Recordatorio no encontrado.'], 404);
        }

        DB::transaction(function () use ($request, $r) {
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'eliminar', 'recordatorios', 'recordatorio', $r->id,
                ['titulo' => $r->titulo, 'fecha' => substr((string) $r->fecha_programada, 0, 10)]);
            DB::table('recordatorios')->where('id', $r->id)->delete();
        });

        return response()->noContent();
    }

    // ── salida ────────────────────────────────────────────────────────────

    private function unoOut(string $id): array
    {
        $r = DB::table('recordatorios')->where('id', $id)->first();
        [$mascotas, $duenos] = $this->contexto(collect([$r]));

        return $this->out($r, $mascotas, $duenos);
    }

    private function out($r, $mascotas, $duenos): array
    {
        $m = $mascotas[$r->mascota_id] ?? null;
        $d = $r->usuario_dueno_id ? ($duenos[$r->usuario_dueno_id] ?? null) : null;
        // Días de calendario: ver App\Services\Fechas. Restarlos dentro de
        // America/Santiago metía el cambio de hora en la cuenta y devolvía uno
        // menos —el arnés lo cazó acá con Py=7 Lv=6, el 06-sep-2026—.
        $hoy = Fechas::dia($this->hoy());
        $fecha = Fechas::dia($r->fecha_programada);

        return [
            'id' => $r->id,
            'mascota_id' => $r->mascota_id,
            'mascota_nombre' => $m->nombre ?? null,
            'mascota_especie' => $m->especie ?? null,
            // Que la mascota esté fallecida no borra el recordatorio (es
            // historial), pero el panel tiene que poder mostrarlo distinto:
            // llamar al dueño de un animal muerto para vacunarlo es el peor
            // error posible de este módulo.
            'mascota_fallecida' => $m ? ($m->estado ?? null) === 'Fallecido' : false,
            'usuario_dueno_id' => $r->usuario_dueno_id,
            'dueno_nombre' => $d->nombre ?? null,
            // Teléfono y correo van en el LISTADO a propósito: el trabajo que
            // genera un recordatorio es llamar a esa persona, y obligar a abrir
            // la ficha para cada uno convierte una tarde de llamados en una
            // tarde de clicks.
            'dueno_telefono' => $d->telefono ?? null,
            'dueno_email' => $this->emailReal($d),
            'titulo' => $r->titulo,
            'categoria' => $r->categoria,
            'mensaje' => $r->mensaje,
            // Con las variables ya puestas (`@mascota_nombre` → «Luna»): lo usa
            // el botón de WhatsApp del panel para prellenar el mensaje.
            'mensaje_resuelto' => $r->mensaje
                ? MensajeRecordatorio::resolver($r->mensaje, $m, $d, substr((string) $r->fecha_programada, 0, 10))
                : null,
            'dias_repeticion' => isset($r->dias_repeticion) ? (int) $r->dias_repeticion ?: null : null,
            'fecha_programada' => substr((string) $r->fecha_programada, 0, 10),
            'estado' => $r->estado,
            // DERIVADOS, no columnas: el estado no puede contradecir a la fecha.
            'vencido' => $r->estado === 'pendiente' && $fecha->lt($hoy),
            'dias_restantes' => Fechas::diasEntre($hoy, $fecha),
            'enviado_at' => $this->iso($r->enviado_at),
            'canal' => $r->canal,
            'notas' => $r->notas,
            'created_at' => $this->iso($r->created_at),
        ];
    }

    private function contexto($filas): array
    {
        $idsM = $filas->pluck('mascota_id')->filter()->unique();
        $idsU = $filas->pluck('usuario_dueno_id')->filter()->unique();

        return [
            $idsM->isEmpty() ? collect()
                : DB::table('mascotas')->whereIn('id', $idsM)->get()->keyBy('id'),
            $idsU->isEmpty() ? collect()
                : DB::table('usuarios')->whereIn('id', $idsU)->get()->keyBy('id'),
        ];
    }

    private function emailReal($u): ?string
    {
        if (! $u || empty($u->email) || str_ends_with($u->email, self::DOMINIO_SIN_CORREO)) {
            return null;
        }

        return $u->email;
    }

    /** Hoy en hora de la CLÍNICA, no UTC (§9.6). */
    private function hoy(): string
    {
        return CarbonImmutable::now()->format('Y-m-d');
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
