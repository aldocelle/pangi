<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\EstadoRevisar;
use App\Http\Controllers\Controller;
use App\Services\EstadoSistema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Config → Estado del sistema: WhatsApp, Google y la IA en una pantalla, y a
 * quién le llegan las alertas (decisión de producto, 05-oct). Solo admin: muestra
 * la cuenta de Google conectada y el gasto de la IA.
 */
class EstadoSistemaController extends Controller
{
    /** GET /estado-sistema[?sondear=1]. Sin sondear, lee lo último que revisó el cron. */
    public function ver(Request $request, EstadoSistema $estado)
    {
        return $estado->revisar($request->boolean('sondear')) + ['alertas' => self::alertas(), 'kpis' => $estado->kpis()];
    }

    /**
     * PUT /estado-sistema/alertas {activo, extra: [...], apagados: [...]}.
     * `activo` es el interruptor general; `apagados`, quién no las recibe;
     * `extra`, correos que no son de un admin.
     */
    public function guardarAlertas(Request $request)
    {
        // Pegados de otro lado traen espacios: se limpian ANTES de validar.
        foreach (['extra', 'apagados'] as $k) {
            if (is_array($request->input($k))) {
                $request->merge([$k => array_map(fn ($c) => is_string($c) ? trim($c) : $c, $request->input($k))]);
            }
        }
        $datos = $request->validate([
            'activo' => 'required|boolean',
            'extra' => 'present|array|max:10',
            'extra.*' => 'email:rfc|max:254',
            'apagados' => 'present|array|max:50',
            'apagados.*' => 'string|max:254',
        ], [
            'extra.*.email' => 'Uno de los correos agregados no es válido.',
            'extra.max' => 'Como máximo 10 correos agregados.',
        ]);
        $norm = fn ($l) => array_values(array_unique(array_map(fn ($c) => mb_strtolower($c), $l)));
        $alertas = ['activo' => (bool) $datos['activo'], 'extra' => $norm($datos['extra']), 'apagados' => $norm($datos['apagados'])];

        DB::transaction(function () use ($alertas) {
            $fila = DB::table('ajustes')->where('id', 1)->lockForUpdate()->first();
            $data = json_decode((string) ($fila->data ?? ''), true) ?: [];
            $data['alertas'] = $alertas;   // reemplaza también la lista vieja `correos`
            DB::table('ajustes')->where('id', 1)->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
        });

        return self::alertas();
    }

    private static function alertas(): array
    {
        return [
            'activo' => EstadoSistema::configAlertas()['activo'],
            'candidatos' => EstadoSistema::candidatos(),
            'destinatarios' => EstadoSistema::destinatarios(),
            'ultima' => Cache::get(EstadoRevisar::ULTIMA_ALERTA),
        ];
    }
}
