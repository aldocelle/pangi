<?php

namespace App\Services;

use App\Console\Commands\RecordatoriosCitas;
use App\Http\Controllers\Api\ContactoController;

/**
 * Ajustes → Correos: cada correo que manda el sistema, armado con el MISMO
 * código que lo envía y datos de ejemplo. Sirve para mostrarle a la clínica qué
 * recibe cada persona sin tener que provocar una reserva, un pago o un reset.
 *
 * **No envía nada.** Cada servicio se construye a mano con `CorreoCaptura`,
 * que solo guarda lo que se iba a mandar. No se toca el contenedor: un
 * `app()->instance(Correo::class, …)` cambiaría el transporte de toda la
 * petición, y cualquier cosa que corriera después en ella dejaría de enviar.
 *
 * Los datos son inventados (Luna, de Daniela Rojas): ni un nombre ni un correo
 * de la base. Lo único real es la configuración de la clínica —URLs del portal,
 * teléfono, firma de los recordatorios—, que es justo lo que hay que revisar.
 */
class VistaPreviaCorreos
{
    /** @return list<array{grupo:string,titulo:string,para:string,asunto:?string,texto:?string,html:?string,error?:string}> */
    public function todos(): array
    {
        $captura = new CorreoCaptura();
        $citas = new CitasEmail($captura);
        $tokens = new TokensCorreo($captura);
        $recordatorios = new RecordatoriosCitas($citas, $captura);

        [$u, $m, $esp] = $this->personas();
        $cita = $this->cita();
        $citaAbono = $this->cita(['servicio' => 'Consulta Dermatológica', 'estado' => 'Agendada',
            'monto_abono_clp' => 22500, 'monto_total_clp' => 45000,
            'payment_status' => 'pending', 'pago_modalidad_requerida' => 'abono']);
        $citaPagada = $this->cita(['servicio' => 'Consulta Dermatológica',
            'monto_abono_clp' => 22500, 'monto_total_clp' => 45000,
            'payment_status' => 'approved', 'pago_modalidad_requerida' => 'abono']);

        $s = [];
        $r = function (string $grupo, string $titulo, string $para, callable $fn) use (&$s, $captura) {
            $s[] = $this->capturar($captura, $grupo, $titulo, $para, $fn);
        };

        $g = 'Reservas';
        $r($g, 'Reserva confirmada (se paga en la clínica)', 'Tutor', fn () => $citas->notificarReservaCreada($u, $m, $cita, $esp));
        $r($g, 'Reserva con abono pendiente', 'Tutor', fn () => $citas->notificarReservaCreada($u, $m, $citaAbono, $esp));
        $r($g, 'Pago recibido', 'Tutor', fn () => $citas->notificarPagoRecibido($u, $m, $citaPagada, $esp, 22500));
        $r($g, 'Tu cita cambió de hora', 'Tutor', fn () => $citas->notificarCitaReagendada($u, $m,
            $this->cita(['fecha_hora' => $this->fecha(9, '15:00')]), $esp));
        $r($g, 'Cita confirmada', 'Tutor', fn () => $citas->notificarCambioEstado($u, $m, $cita));
        $r($g, 'Cita cancelada', 'Tutor', fn () => $citas->notificarCambioEstado($u, $m, $this->cita(['estado' => 'Cancelada'])));
        $r($g, 'Atención realizada', 'Tutor', fn () => $citas->notificarCambioEstado($u, $m, $this->cita(['estado' => 'Completada'])));

        $g = 'Recordatorios';
        $r($g, 'Recordatorio de cita (el día antes)', 'Tutor', function () use ($recordatorios, $u, $m, $cita) {
            [$asunto, $texto, $html] = $recordatorios->cuerpo($u, $m, $cita, 'mañana');

            return compact('asunto', 'texto', 'html');
        });
        $r($g, 'Medicina preventiva', 'Tutor', function () use ($u, $m) {
            $rec = (object) ['titulo' => 'Vacuna antirrábica', 'categoria' => 'Vacuna',
                'fecha_programada' => $this->fecha(13), 'mensaje' => "Ya se cumple un año desde su última dosis.\nTrae su carnet de vacunas."];
            try {
                $config = ConfigClinica::leer('recordatorios');
            } catch (\Throwable) {
                $config = [];
            }
            [$asunto, $texto] = MensajeRecordatorio::correo($u, $m, $rec, $config);
            $html = MensajeRecordatorio::html($u, $m, $rec, $config);

            return compact('asunto', 'texto', 'html');
        });

        $g = 'Cuenta del portal';
        $r($g, 'Confirma tu correo (cuenta nueva)', 'Tutor', fn () => $tokens->enviarConfirmacion($u, 'ejemplo'));
        $r($g, 'Activa tu acceso (ya tenía ficha en la clínica)', 'Tutor', fn () => $tokens->enviarConfirmacion($u, 'ejemplo', true));
        $r($g, 'Restablece tu contraseña', 'Tutor', fn () => $tokens->enviarReset($u, 'ejemplo'));
        $r($g, 'Ya tienes una cuenta', 'Tutor', fn () => $tokens->enviarCuentaExistente($u));

        $g = 'Para la clínica y el equipo';
        $consulta = ['nombre' => 'Daniela Rojas', 'email' => 'daniela@ejemplo.cl', 'asunto' => 'Horario de sábado',
            'mensaje' => "¿Atienden los sábados?\nLuna tiene una alergia en la piel."];
        $r($g, 'Nueva consulta desde el sitio', 'Clínica', fn () => [
            'asunto' => ContactoController::asuntoAviso($consulta),
            'texto' => ContactoController::textoAviso($consulta),
            'html' => ContactoController::htmlAviso($consulta),
        ]);
        $r($g, 'Nueva hora en tu agenda', 'Especialista', fn () => $citas->notificarEspecialistaReservaCreada($esp, $u, $m, $citaAbono));
        $r($g, 'Una de tus horas cambió', 'Especialista', fn () => $citas->notificarEspecialistaReagendada($esp, $u, $m, $cita));
        $r($g, 'Se canceló una hora', 'Especialista', fn () => $citas->notificarEspecialistaCancelada($esp, $u, $m,
            $this->cita(['estado' => 'Cancelada'])));

        return $s;
    }

    /**
     * Corre `$fn` y devuelve lo que capturó. Las funciones de envío se tragan
     * sus errores (los anotan en el log y siguen), así que «no se capturó
     * nada» es la señal de que algo falló, y se muestra en la pantalla.
     */
    private function capturar(CorreoCaptura $c, string $grupo, string $titulo, string $para, callable $fn): array
    {
        $base = compact('grupo', 'titulo', 'para') + ['asunto' => null, 'texto' => null, 'html' => null];
        $antes = count($c->enviados);
        try {
            $r = $fn();
        } catch (\Throwable $e) {
            return $base + ['error' => $e->getMessage()];
        }
        if (is_array($r) && array_key_exists('html', $r)) {
            return array_merge($base, $r);
        }
        $m = $c->enviados[$antes] ?? null;

        return $m ? array_merge($base, $m) : $base + ['error' => 'No se generó el correo; el detalle está en el log del servidor.'];
    }

    private function personas(): array
    {
        return [
            (object) ['id' => 'ejemplo-tutor', 'nombre' => 'Daniela Rojas', 'email' => 'daniela@ejemplo.cl',
                'telefono' => '+56 9 8765 4321'],
            (object) ['id' => 'ejemplo-mascota', 'nombre' => 'Luna', 'especie' => 'Perro'],
            (object) ['id' => 'ejemplo-esp', 'nombre' => 'Dra. Ejemplo', 'email' => 'especialista@ejemplo.cl',
                'recibe_avisos' => true],
        ];
    }

    private function cita(array $x = []): object
    {
        return (object) ($x + [
            'id' => '00000000-0000-4000-8000-000000000000', 'servicio' => 'Consulta General',
            'fecha_hora' => $this->fecha(7, '10:30'), 'duracion_min' => 30, 'estado' => 'Confirmada',
            'respuestas' => null, 'notas_pre_cita' => 'Picazón en las orejas hace una semana',
            'monto_abono_clp' => null, 'monto_total_clp' => null, 'payment_status' => null,
            'pago_modalidad_requerida' => null, 'pago_referencia' => 'VID-EJEMPLO', 'pago_autorizacion' => '000000',
            'mascota_id' => 'ejemplo-mascota', 'usuario_id' => 'ejemplo-tutor',
        ]);
    }

    /** Una fecha de la semana que viene, para que el ejemplo no hable del pasado. */
    private function fecha(int $dias, ?string $hora = null): string
    {
        $d = \Carbon\CarbonImmutable::now()->addDays($dias)->format('Y-m-d');

        return $hora ? "{$d} {$hora}:00" : $d;
    }
}
