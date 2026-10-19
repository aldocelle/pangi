<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El «próximo control» que el veterinario escribe en la ficha genera su
 * recordatorio, y ese recordatorio se corrige o se retira cuando la nota
 * cambia. Port de `recordatorios_preventivos_service.sincronizar_desde_nota`.
 *
 * NO confundir con los recordatorios de CITA («tu hora es mañana»): aquello es
 * logística de una hora que ya existe, esto es traer de vuelta a un paciente
 * que todavía no pidió hora. Son dos módulos distintos en Python por lo mismo.
 *
 * Acá va SOLO la sincronización. El envío por correo
 * (`enviar_avisos_pendientes`, `loop_avisos_preventivos`) es una tarea de
 * fondo, no una ruta HTTP, y no pertenece a la Fase 3.
 */
class RecordatoriosPreventivos
{
    public const CATEGORIA_CONTROL = 'Control';

    /**
     * Crea, corrige o retira el recordatorio que cuelga de esta nota.
     *
     * **Solo toca el recordatorio PENDIENTE.** Uno ya enviado no se modifica ni
     * se borra: al tutor ya se le avisó, y reescribir ese aviso dejaría al
     * panel diciendo que nunca ocurrió. Si la fecha cambia después de haber
     * avisado, nace uno nuevo para la fecha nueva.
     */
    public function sincronizarDesdeNota(object $nota): ?string
    {
        $vigente = DB::table('recordatorios')
            ->where('nota_id', $nota->id)
            ->where('estado', 'pendiente')
            ->first();

        // Se borró la fecha: el aviso pendiente ya no tiene razón de existir.
        if (empty($nota->proximo_control)) {
            if ($vigente) {
                DB::table('recordatorios')->where('id', $vigente->id)->delete();
            }
            return null;
        }

        $fecha = substr((string) $nota->proximo_control, 0, 10);

        if ($vigente) {
            DB::table('recordatorios')->where('id', $vigente->id)->update([
                'titulo' => $this->tituloDe($nota),
                'fecha_programada' => $fecha,
            ]);
            return $vigente->id;
        }

        // Sin recordatorio pendiente: hay que crearlo. La mascota y el dueño
        // se sacan de la ficha, que es lo único que la nota conoce.
        $ficha = DB::table('fichas_clinicas')->where('id', $nota->ficha_id)->first();
        if (! $ficha) {
            return null;
        }
        $mascota = DB::table('mascotas')->where('id', $ficha->mascota_id)->first();
        if (! $mascota) {
            return null;
        }

        $id = (string) Str::uuid();
        DB::table('recordatorios')->insert([
            'id' => $id,
            'mascota_id' => $mascota->id,
            'usuario_dueno_id' => $mascota->usuario_id,
            'nota_id' => $nota->id,
            'titulo' => $this->tituloDe($nota),
            'categoria' => self::CATEGORIA_CONTROL,
            'fecha_programada' => $fecha,
            'estado' => 'pendiente',
            'creado_por_id' => $nota->veterinario_id,
            // Marca TÉCNICA, en UTC: `created_at` sale de `utcnow()` en
            // Python y nunca se compara contra `fecha_hora` (§9.6). La hora
            // local se reserva para lo que la clínica lee como hora.
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);

        return $id;
    }

    /**
     * Mismo patrón que `sincronizarDesdeNota`, pero distinguido por
     * `protocolo_id` en vez de `nota_id`: busca el recordatorio PENDIENTE de
     * esta mascota para este protocolo y lo corrige a la próxima fecha de la
     * dosis recién registrada; si no existe, lo crea.
     *
     * Un recordatorio ya ENVIADO no se toca — mismo motivo que en la nota:
     * reescribirlo dejaría al panel diciendo que un aviso ya mandado nunca
     * ocurrió.
     *
     * Sin `proxima_fecha` (protocolo sin frecuencia calculable) no hay nada que
     * programar y la función no hace nada.
     */
    public function sincronizarDesdeAplicacion(object $aplicacion, object $protocolo,
                                               object $mascota): ?string
    {
        if (empty($aplicacion->proxima_fecha)) {
            return null;
        }
        $fecha = substr((string) $aplicacion->proxima_fecha, 0, 10);

        $vigente = DB::table('recordatorios')
            ->where('protocolo_id', $protocolo->id)
            ->where('mascota_id', $mascota->id)
            ->where('estado', 'pendiente')
            ->first();

        if ($vigente) {
            DB::table('recordatorios')->where('id', $vigente->id)
                ->update(['fecha_programada' => $fecha]);
            return $vigente->id;
        }

        $id = (string) Str::uuid();
        DB::table('recordatorios')->insert([
            'id' => $id,
            'mascota_id' => $mascota->id,
            'usuario_dueno_id' => $mascota->usuario_id,
            'protocolo_id' => $protocolo->id,
            'titulo' => Str::substr("{$protocolo->nombre} — próxima dosis", 0, 255),
            // El nombre del protocolo sirve de categoría cuando no tiene una:
            // en el listado, «Óctuple» dice más que una celda vacía.
            'categoria' => $protocolo->categoria ?: $protocolo->nombre,
            'fecha_programada' => $fecha,
            'estado' => 'pendiente',
            'creado_por_id' => $aplicacion->registrado_por_id ?? null,
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);

        return $id;
    }

    /**
     * El motivo da contexto en un listado donde, si no, habría veinte filas
     * que dicen "Control" y ninguna dice de qué.
     *
     * El corte a 255 replica el `[:255]` de Python. Se cuenta en CARACTERES,
     * no en bytes: `Str::substr` es multibyte, y un motivo con tildes o ñ
     * cortado con `substr()` a secas partiría un carácter por la mitad.
     */
    private function tituloDe(object $nota): string
    {
        $motivo = trim((string) ($nota->motivo ?? ''));
        if ($motivo === '') {
            return 'Control';
        }
        return Str::substr("Control: {$motivo}", 0, 255);
    }
}
