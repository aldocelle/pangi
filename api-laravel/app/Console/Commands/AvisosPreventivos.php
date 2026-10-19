<?php

namespace App\Console\Commands;

use App\Services\ConfigClinica;
use App\Services\Correo;
use App\Services\MensajeRecordatorio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Avisos de medicina preventiva: la vacuna o el control que vence.
 *
 * **Este módulo escribe a clientes reales por su cuenta**, así que sale con
 * cuatro frenos puestos, y los cuatro están a propósito:
 *
 *  · Un interruptor (`RECORDATORIOS_AVISO_AUTOMATICO`) que por defecto está
 *    APAGADO. La clínica lo enciende cuando quiere, no el despliegue.
 *  · Una franja horaria (9 a 19, hora de la clínica): un aviso a las 3 de la
 *    mañana se lee como spam.
 *  · Un tope por ciclo, para que encenderlo no dispare cientos de correos de
 *    golpe el primer día.
 *  · Un piso de antigüedad: lo que lleva más de 30 días vencido no se avisa
 *    solo. Escribirle a alguien por una vacuna de hace ocho meses no ayuda, y
 *    delata que el sistema estuvo callado todo ese tiempo.
 *
 * Cron sugerido (cada hora; la franja la decide el CÓDIGO, no la expresión —
 * el cron de hPanel corre en UTC, §9.6):
 *
 *     0 * * * * /usr/bin/php /home/.../api-laravel/artisan avisos:preventivos
 */
class AvisosPreventivos extends TareaDeFondo
{
    protected $signature = 'avisos:preventivos {--forzar : salta el interruptor y la franja horaria}';

    protected $description = 'Envía los avisos de medicina preventiva que corresponden hoy';

    private const HORA_DESDE = 9;

    private const HORA_HASTA = 19;

    /** Correos que el importador de ACVet inventó: no existen. */
    private const DOMINIO_SIN_CORREO = '@sin-correo.local';

    public function __construct(private Correo $correo)
    {
        parent::__construct();
    }

    protected function ejecutar(): string
    {
        $forzar = (bool) $this->option('forzar');
        // Dos interruptores en AND, y hacen falta los dos en `true`. Este
        // (`RECORDATORIOS_AVISO_AUTOMATICO`) es específico de este caso: la base
        // trae 264 recordatorios de ACVet y el primer ciclo despacharía un
        // centenar de golpe. El general (`CORREOS_AUTOMATICOS`) apaga de una vez
        // todo lo que sale solo, y existe porque el recordatorio de cita no
        // tenía ninguno. `--forzar` sigue saltándose los dos: es la vía de
        // probarlo a mano sin encender el envío para todos.
        //
        // `filter_var` y no `(string) env(...) === 'true'`, que es como estaba:
        // **`env()` de Laravel castea solo**, así que con
        // `RECORDATORIOS_AVISO_AUTOMATICO=true` en el `.env` devuelve el
        // booleano `true` y `(string) true` es `"1"`, que nunca iguala a
        // `"true"`. Con ese idiom el interruptor quedaba apagado para siempre:
        // la clínica podía ponerlo en `true` y no pasaba nada. No lo detectó
        // nadie porque el arnés ejercita este comando con `--forzar`, que se
        // salta justo esta línea.
        $automatico = filter_var(env('RECORDATORIOS_AVISO_AUTOMATICO', false), FILTER_VALIDATE_BOOLEAN)
            && config('emr.correos_automaticos');

        if (! $automatico && ! $forzar) {
            return '';
        }
        // `--forzar` salta el interruptor y la franja, NO los demás frenos:
        // existe para poder probarlo a mano sin encender el envío para todos.
        if (! $forzar && ! $this->enHorario((int) CarbonImmutable::now()->format('G'))) {
            return '';
        }

        $maxPorCiclo = (int) (env('RECORDATORIOS_MAX_POR_CICLO') ?: 20);
        $antiguedad = (int) (env('RECORDATORIOS_ANTIGUEDAD_MAX_DIAS') ?: 30);

        // «Días de anticipación al envío» (Ajustes → Recordatorios, como en
        // ACVet): con 3, un control del viernes se avisa el martes. 0 por
        // defecto, que es lo que hacía este comando antes del ajuste.
        $config = ConfigClinica::leer('recordatorios');
        $limite = CarbonImmutable::now()->addDays((int) $config['dias_anticipacion'])->format('Y-m-d');
        $piso = CarbonImmutable::now()->subDays($antiguedad)->format('Y-m-d');

        // Se piden MÁS candidatos que el tope porque varios se van a descartar
        // (sin correo, mascota fallecida) y esos no deben consumir el cupo del
        // ciclo: si no, un bloque de veinte fichas sin correo dejaría el envío
        // detenido para siempre.
        $candidatos = DB::table('recordatorios')
            ->where('estado', 'pendiente')
            ->where('fecha_programada', '<=', $limite)
            ->where('fecha_programada', '>=', $piso)
            ->orderBy('fecha_programada')->orderBy('id')
            ->limit($maxPorCiclo * 5)
            ->get();

        $enviados = 0;
        foreach ($candidatos as $r) {
            if ($enviados >= $maxPorCiclo) {
                break;
            }

            $mascota = DB::table('mascotas')->where('id', $r->mascota_id)->first();
            if (! $mascota) {
                continue;
            }
            // El peor error posible de este módulo: escribirle a alguien para
            // que traiga a vacunar a un animal que se murió. Se salta sin tocar
            // el registro, para que una PERSONA decida qué hacer con él.
            if ($mascota->estado === 'Fallecido') {
                continue;
            }

            $dueno = $r->usuario_dueno_id
                ? DB::table('usuarios')->where('id', $r->usuario_dueno_id)->first()
                : null;
            $destino = $this->correoReal($dueno);
            if (! $destino) {
                continue;
            }

            [$asunto, $cuerpo] = MensajeRecordatorio::correo($dueno, $mascota, $r, $config);
            if ($this->correo->enviar($destino, $asunto, $cuerpo,
                MensajeRecordatorio::html($dueno, $mascota, $r, $config))) {
                DB::table('recordatorios')->where('id', $r->id)->update([
                    'estado' => 'enviado',
                    // Hora de la CLÍNICA: es cuándo se le escribió al cliente,
                    // un dato que una persona lee en el panel (§9.6).
                    'enviado_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
                    'canal' => 'email',
                ]);
                $enviados++;
            }
            // Si el envío falla no se marca nada: se reintenta en el próximo
            // ciclo. Mismo criterio que los recordatorios de cita.
        }

        return $enviados ? "Avisos preventivos enviados: {$enviados}" : '';
    }

    private function enHorario(int $hora): bool
    {
        return $hora >= self::HORA_DESDE && $hora < self::HORA_HASTA;
    }

    private function correoReal(?object $u): ?string
    {
        if (! $u || ! $u->email || str_ends_with($u->email, self::DOMINIO_SIN_CORREO)) {
            return null;
        }
        return $u->email;
    }
}
