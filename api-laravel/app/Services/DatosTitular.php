<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Todo lo que la clínica guarda sobre una persona, en un JSON.
 *
 * Port de `backend/app/services/datos_titular.py`. Resuelve DOS derechos de la
 * ley 21.719 con la misma función, porque en la práctica son la misma pregunta
 * hecha con distinta intención: **acceso** («¿qué tienen sobre mí?») y
 * **portabilidad** («démelo en un formato que pueda llevarme»). Por eso la
 * salida es JSON estructurado y no una pantalla: tiene que poder leerlo otra
 * máquina.
 *
 * QUÉ NO SALE DE ACÁ, Y POR QUÉ
 *
 *   · `password_hash` y `password_hash_pendiente`. Un hash de la contraseña de
 *     alguien es un dato sobre esa persona, sí, pero entregarlo no le sirve a
 *     ella y sí a quien intercepte el archivo: es material para atacar fuera de
 *     línea. El derecho de acceso no obliga a exportar credenciales.
 *   · `token_confirmacion`, `token_reset`. Mismo criterio, y además efímeros.
 *   · Los BYTES de los documentos clínicos. Va el inventario —qué hay, de qué
 *     tamaño, de cuándo— y no el contenido: una radiografía son megabytes y
 *     esto viaja por JSON. Para el archivo existe la ruta autenticada.
 *   · Datos de OTRAS personas. Las compras se filtran por las mascotas de este
 *     tutor y no por la transacción entera: una boleta puede mezclar dos
 *     mascotas de dueños distintos.
 *
 * El orden de las claves y el de cada listado siguen a Python al pie de la
 * letra: el arnés compara los cuerpos enteros.
 */
class DatosTitular
{
    /**
     * Con quién se comparten los datos, y cuáles salen de Chile.
     *
     * Va DENTRO de la respuesta y no solo en la política publicada por algo
     * práctico: el derecho de acceso incluye saber a quién se comunicaron los
     * datos, y una lista que vive en un `.vue` se desactualiza sin que nadie lo
     * note. Acá viaja con los datos.
     */
    public const DESTINATARIOS = [
        [
            'quien' => 'Google (Calendar y Gmail)',
            'para_que' => 'Crear el evento de tu cita en la agenda del especialista y enviarte los correos de confirmación.',
            'fuera_de_chile' => true,
        ],
        [
            'quien' => 'Getnet (Santander)',
            'para_que' => 'Cobrar el abono de la reserva cuando la consulta lo exige. Los datos de tu tarjeta nunca pasan por nuestros servidores.',
            'fuera_de_chile' => false,
        ],
        [
            'quien' => 'Proveedor de hosting',
            'para_que' => 'Alojar el sitio web, los portales, la base de datos y las copias de respaldo.',
            'fuera_de_chile' => true,
        ],
        [
            'quien' => 'Resend',
            'para_que' => 'Enviarte correos (confirmaciones, recordatorios) cuando el canal principal no está disponible.',
            'fuera_de_chile' => true,
        ],
        [
            'quien' => 'Meta (WhatsApp)',
            'para_que' => 'Atenderte por WhatsApp, si escribes por ese canal, o contactarte por él para confirmar o coordinar tu hora.',
            'fuera_de_chile' => true,
        ],
        [
            'quien' => 'Anthropic',
            'para_que' => 'Generar la respuesta del asistente con inteligencia artificial, solo si lo usas: el texto de tu mensaje se envía para eso.',
            'fuera_de_chile' => true,
        ],
        [
            'quien' => 'Google reCAPTCHA',
            'para_que' => 'Distinguir personas de robots en los formularios públicos.',
            'fuera_de_chile' => true,
        ],
    ];

    public function exportar($usuario): array
    {
        $mascotas = DB::table('mascotas')
            ->where('usuario_id', $usuario->id)
            ->orderBy('created_at')->orderBy('id')
            ->get();
        $ids = $mascotas->pluck('id')->all();

        return [
            'generado_el' => $this->iso(now('UTC')->format('Y-m-d H:i:s.u')),
            'sobre_este_archivo' => 'Todo lo que ' . Marca::nombre() . ' guarda asociado a tu cuenta. '
                . 'No incluye tu contraseña (se guarda cifrada y no es recuperable) '
                . 'ni el contenido de los archivos adjuntos, que se descargan uno a uno.',
            'cuenta' => $this->cuenta($usuario),
            'consentimientos' => $this->consentimientos($usuario),
            'mascotas' => $this->mascotas($mascotas),
            'fichas_clinicas' => $this->fichas($mascotas),
            'citas' => $this->citas($usuario),
            'compras' => $this->compras($ids),
            'recordatorios' => $this->recordatorios($ids),
            'solicitudes_sobre_tus_datos' => $this->solicitudes($usuario),
            'con_quien_se_comparten' => self::DESTINATARIOS,
        ];
    }

    private function cuenta($u): array
    {
        return [
            'id' => $u->id,
            'email' => $u->email,
            'nombre' => $u->nombre,
            'telefono' => $u->telefono,
            'rut' => $u->rut,
            'direccion' => $u->direccion,
            'ciudad' => $u->ciudad,
            'region' => $u->region,
            'rol' => $u->rol,
            'cuenta_activa' => (bool) $u->es_activo,
            'email_verificado' => (bool) $u->email_verificado,
            'tiene_acceso_con_google' => (bool) $u->google_id,
            'puntos_fidelizacion' => $u->puntos_fidelizacion === null
                ? null : (int) $u->puntos_fidelizacion,
            'creada_el' => $this->iso($u->created_at),
            // Explica de dónde salió la ficha de quien nunca se registró: la
            // clínica importó su historial de ACVet, y tiene derecho a saberlo.
            'importado_del_sistema_anterior' => (bool) $u->id_legado,
        ];
    }

    private function consentimientos($u): array
    {
        return [
            'terminos' => [
                'aceptado' => (bool) $u->terms_accepted,
                'fecha' => $this->iso($u->terms_accepted_at),
                'version' => $u->terms_version,
            ],
            'politica_de_privacidad' => [
                'aceptado' => (bool) $u->privacy_accepted,
                'fecha' => $this->iso($u->privacy_accepted_at),
                'version' => $u->privacy_version,
            ],
            'comunicaciones_comerciales' => [
                'aceptado' => (bool) $u->marketing_consent,
                'fecha' => $this->iso($u->marketing_consent_at),
                'retirado_el' => $this->iso($u->marketing_consent_retirado_at),
            ],
        ];
    }

    private function mascotas($filas): array
    {
        return $filas->map(fn ($m) => [
            'id' => $m->id,
            'nombre' => $m->nombre,
            'especie' => $m->especie,
            'raza' => $m->raza,
            'sexo' => $m->sexo,
            'fecha_nacimiento' => $m->fecha_nacimiento
                ? substr((string) $m->fecha_nacimiento, 0, 10) : null,
            'peso_kg' => $m->peso_kg === null ? null : (float) $m->peso_kg,
            'alergias' => $m->alergias,
            'microchip' => $m->microchip_codigo,
            'esterilizado' => $m->esterilizado === null ? null : (bool) $m->esterilizado,
            'estado' => $m->estado,
            'registrada_el' => $this->iso($m->created_at),
        ])->values()->all();
    }

    private function fichas($mascotas): array
    {
        $salida = [];
        foreach ($mascotas as $m) {
            $ficha = DB::table('fichas_clinicas')->where('mascota_id', $m->id)->first();
            if (! $ficha) {
                continue;
            }
            $notas = DB::table('notas_clinicas')->where('ficha_id', $ficha->id)
                ->orderBy('created_at')->orderBy('id')->get();
            $docs = DB::table('documentos_clinicos')->where('ficha_id', $ficha->id)
                ->orderBy('created_at')->orderBy('id')->get();

            $salida[] = [
                'mascota' => $m->nombre,
                'mascota_id' => $m->id,
                'antecedentes' => $ficha->antecedentes,
                'vacunas' => $ficha->vacunas,
                'condiciones_cronicas' => $ficha->condiciones_cronicas,
                // Sin `veterinario_id`: quién la escribió es dato del
                // profesional, no del titular.
                'atenciones' => $notas->map(fn ($n) => [
                    'fecha' => $this->iso($n->created_at),
                    'motivo' => $n->motivo,
                    'diagnostico' => $n->diagnostico,
                    'tratamiento' => $n->tratamiento,
                    'indicaciones' => $n->indicaciones,
                    'proximo_control' => $n->proximo_control
                        ? substr((string) $n->proximo_control, 0, 10) : null,
                ])->values()->all(),
                'documentos_adjuntos' => $docs->map(fn ($d) => [
                    'nombre' => $d->nombre_original,
                    'tipo' => $d->tipo,
                    'tamano_bytes' => $d->tamano_bytes === null ? null : (int) $d->tamano_bytes,
                    'fecha' => $this->iso($d->created_at),
                    'descargar_en' => "/fichas/documentos/{$d->id}/archivo",
                ])->values()->all(),
            ];
        }
        return $salida;
    }

    private function citas($u): array
    {
        return DB::table('citas')->where('usuario_dueno_id', $u->id)
            ->orderBy('fecha_hora')->orderBy('id')->get()
            ->map(fn ($c) => [
                'fecha_hora' => $this->iso($c->fecha_hora),
                'servicio' => $c->servicio,
                'estado' => $c->estado,
                'notas_que_dejaste' => $c->notas_pre_cita,
                // El formulario completo: es JSON libre y ahí termina buena
                // parte de lo que la persona escribió. Sin esto, el «acceso»
                // dejaría fuera justo lo que ella misma tipeó.
                'formulario_de_reserva' => $c->respuestas
                    ? (json_decode($c->respuestas, true) ?: (object) []) : (object) [],
                'monto_abono_clp' => $c->monto_abono_clp === null ? null : (int) $c->monto_abono_clp,
                'monto_total_clp' => $c->monto_total_clp === null ? null : (int) $c->monto_total_clp,
                'estado_del_pago' => $c->payment_status,
                'reservada_el' => $this->iso($c->created_at),
            ])->values()->all();
    }

    private function compras(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        // Solo las LÍNEAS de las mascotas de esta persona, no la venta entera:
        // una boleta puede mezclar dos mascotas de dueños distintos.
        return DB::table('ventas')->whereIn('mascota_id', $ids)
            ->orderBy('fecha')->orderBy('id')->get()
            ->map(fn ($v) => [
                'fecha' => $this->iso($v->fecha),
                'detalle' => $v->nombre,
                'cantidad' => $v->cantidad === null ? null : (int) $v->cantidad,
                'precio_clp' => (float) ($v->precio_venta ?? 0),
            ])->values()->all();
    }

    private function recordatorios(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        return DB::table('recordatorios')->whereIn('mascota_id', $ids)
            ->orderBy('fecha_programada')->orderBy('id')->get()
            ->map(fn ($r) => [
                'fecha_programada' => substr((string) $r->fecha_programada, 0, 10),
                'titulo' => $r->titulo,
                'categoria' => $r->categoria,
                'mensaje' => $r->mensaje,
                'estado' => $r->estado,
            ])->values()->all();
    }

    private function solicitudes($u): array
    {
        return DB::table('solicitudes_titular')->where('usuario_id', $u->id)
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($s) => [
                'tipo' => $s->tipo,
                'detalle' => $s->detalle,
                'estado' => $s->estado,
                'respuesta' => $s->respuesta,
                'solicitada_el' => $this->iso($s->created_at),
                'resuelta_el' => $this->iso($s->resuelta_at),
            ])->values()->all();
    }

    /** El mismo ISO que escribe Python: espacio → T, sin zona. */
    private function iso(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return str_replace(' ', 'T', rtrim(substr($v, 0, 26)));
    }
}
