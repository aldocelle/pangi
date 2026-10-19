<?php

namespace App\Console\Commands;

use App\Services\Correo;
use App\Services\EstadoSistema;
use App\Services\Plantilla;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Revisa WhatsApp, Google y la IA, y avisa por correo cuando uno se cae
 * (decisión de producto, 05-oct). Cron cada 15 minutos.
 *
 * CUÁNDO AVISA, y por qué así:
 * - Solo por `error`. Un `aviso` (cupo al 80 %, un envío rechazado) se ve en
 *   Config → Estado del sistema pero no manda correo: una alerta que suena por
 *   todo deja de leerse.
 * - Recién en la SEGUNDA revisión seguida en error (~15 min). Google y Meta
 *   tienen cortes de segundos; un correo por cada uno enseña a ignorarlos.
 * - Si sigue caído, recuerda cada 24 horas.
 * - Avisa también cuando vuelve, para que nadie siga buscando un problema
 *   que ya no existe.
 *
 * Va por correo y no por WhatsApp a propósito: WhatsApp es una de las tres
 * cosas que se vigilan. El correo tiene su propia cascada (Gmail → Resend →
 * SMTP), así que con Google caído igual sale si hay otra vía configurada; si
 * no sale, queda anotado y se ve en el panel.
 */
class EstadoRevisar extends TareaDeFondo
{
    protected $signature = 'estado:revisar';

    protected $description = 'Revisa WhatsApp, Google y la IA, y avisa por correo si alguno se cae';

    /** Lo que se recuerda entre vueltas, por servicio. */
    public const MEMORIA = 'estado-sistema:alertas';

    /** El último correo de alerta, para mostrarlo en el panel. */
    public const ULTIMA_ALERTA = 'estado-sistema:ultima-alerta';

    /** Una fila por revisión, 7 días: de ahí sale la disponibilidad del panel. */
    public const HISTORIAL = 'estado-sistema:historial';

    /** Cuándo salió cada alerta (30 días), para el indicador del panel. */
    public const ENVIADAS = 'estado-sistema:alertas-enviadas';

    private const SEGUIDAS_PARA_AVISAR = 2;

    private const HORAS_RECORDATORIO = 24;

    public function __construct(private EstadoSistema $estado, private Correo $correo)
    {
        parent::__construct();
    }

    protected function ejecutar(): string
    {
        $r = $this->estado->revisar(true);
        self::anotarHistorial($r);
        $memoria = Cache::get(self::MEMORIA) ?: [];
        $ahora = CarbonImmutable::now();
        $caidas = $vueltas = [];
        // Con las alertas apagadas (o nadie que las reciba) no se manda nada,
        // y una caída tampoco queda como «avisada»: si se encienden con algo
        // todavía caído, el correo sale en la revisión siguiente.
        $puedeAvisar = EstadoSistema::destinatarios() !== [];

        foreach ($r['servicios'] as $s) {
            $m = ($memoria[$s['id']] ?? []) + ['estado' => 'ok', 'seguidas' => 0, 'alertado_at' => null];
            if ($s['estado'] === 'error') {
                $m['seguidas'] = $m['estado'] === 'error' ? $m['seguidas'] + 1 : 1;
                $ultima = $m['alertado_at'] ? CarbonImmutable::parse($m['alertado_at']) : null;
                if ($puedeAvisar && $m['seguidas'] >= self::SEGUIDAS_PARA_AVISAR
                    && (! $ultima || $ultima->lt($ahora->subHours(self::HORAS_RECORDATORIO)))) {
                    $caidas[] = $s;
                    $m['alertado_at'] = $ahora->toIso8601String();
                }
            } else {
                // Volvió (o pasó a «aviso» o se apagó a propósito): si se había
                // avisado la caída, se avisa la vuelta.
                if ($m['alertado_at'] && $s['estado'] !== 'aviso') {
                    if ($puedeAvisar) {
                        $vueltas[] = $s;
                    }
                    $m['alertado_at'] = null;
                }
                $m['seguidas'] = 0;
            }
            $m['estado'] = $s['estado'];
            $memoria[$s['id']] = $m;
        }
        Cache::forever(self::MEMORIA, $memoria);

        if ($caidas || $vueltas) {
            $this->avisar($caidas, $vueltas);
        }

        return 'Estado: ' . implode(' · ', array_map(fn ($s) => "{$s['id']} {$s['estado']}", $r['servicios']))
            . ($caidas || $vueltas ? ' · alerta enviada' : '');
    }

    /** Agrega esta revisión al historial y suelta lo de más de 7 días. */
    private static function anotarHistorial(array $r): void
    {
        try {
            $fila = ['at' => $r['revisado_at']] + array_column($r['servicios'], 'estado', 'id');
            $hace7 = now()->subDays(7)->toIso8601String();
            Cache::forever(self::HISTORIAL, array_values(array_filter(
                [...(Cache::get(self::HISTORIAL) ?: []), $fila], fn ($f) => ($f['at'] ?? '') >= $hace7)));
        } catch (\Throwable) {
            // El historial es para mirar: que falle no frena la alerta.
        }
    }

    private function avisar(array $caidas, array $vueltas): void
    {
        $para = EstadoSistema::destinatarios();
        $asunto = $caidas
            ? '⚠ ' . \App\Services\Marca::nombre() . ': ' . implode(' y ', array_map(fn ($s) => "{$s['nombre']} — {$s['resumen']}", $caidas))
            : '✓ ' . \App\Services\Marca::nombre() . ': ' . implode(' y ', array_column($vueltas, 'nombre')) . ' volvió a funcionar';

        $texto = '';
        $filas = '';
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        foreach ($caidas as $s) {
            $texto .= "✗ {$s['nombre']}: {$s['resumen']}\n  {$s['detalle']}\n"
                . ($s['que_hacer'] ? "  Qué hacer: {$s['que_hacer']}\n" : '') . "\n";
            $filas .= Plantilla::fila('⚠', '<strong>' . $e($s['nombre']) . ': ' . $e($s['resumen']) . '</strong><br>'
                . $e($s['detalle']) . ($s['que_hacer'] ? '<br><em>Qué hacer:</em> ' . $e($s['que_hacer']) : ''));
        }
        foreach ($vueltas as $s) {
            $texto .= "✓ {$s['nombre']}: volvió a funcionar. {$s['resumen']}\n\n";
            $filas .= Plantilla::fila('✓', '<strong>' . $e($s['nombre']) . '</strong>: volvió a funcionar. ' . $e($s['resumen']));
        }
        $pie = 'Lo ves en vivo en el panel: Config → Estado del sistema.';
        $html = Plantilla::pagina($caidas ? 'Algo dejó de funcionar' : 'Todo volvió a funcionar',
            'Aviso automático del sistema de la clínica.', Plantilla::tarjeta('Estado del sistema', $filas), $e($pie));

        $enviados = 0;
        foreach ($para as $correo) {
            try {
                if ($this->correo->enviar($correo, $asunto, $texto . $pie, $html)) {
                    $enviados++;
                }
            } catch (\Throwable $ex) {
                Log::warning("estado del sistema: no se pudo enviar la alerta a {$correo}: " . $ex->getMessage());
            }
        }
        if (! $enviados) {
            Log::error("estado del sistema: la alerta «{$asunto}» no le llegó a nadie (destinatarios: " . count($para) . ')');
        }
        if ($enviados) {
            $hace30 = now()->subDays(30)->toIso8601String();
            Cache::forever(self::ENVIADAS, array_values(array_filter(
                [...(Cache::get(self::ENVIADAS) ?: []), now()->toIso8601String()], fn ($at) => $at >= $hace30)));
        }
        Cache::forever(self::ULTIMA_ALERTA, [
            'at' => now()->toIso8601String(), 'asunto' => $asunto,
            'destinatarios' => count($para), 'enviados' => $enviados,
        ]);
    }
}
