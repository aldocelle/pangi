<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bloqueos de la agenda (oct-2026): horas en que un especialista —o toda la
 * clínica, con `especialista_id` NULL— no atiende. Un trámite, vacaciones, una
 * capacitación. Antes eso solo se podía marcar en Google Calendar, y sin Google
 * conectado no había forma de sacar una hora suelta del portal de reservas.
 *
 * `inicio` y `fin` van en hora local, igual que `citas.fecha_hora` (§9.6); las
 * marcas técnicas (`created_at`, `anulado_at`) en UTC, como el resto.
 *
 * **Un bloqueo no se borra: se anula.** Deja de contar, pero queda quién lo
 * puso y quién lo sacó, y la auditoría apunta a una fila que sigue existiendo.
 */
class BloqueosAgenda
{
    /** Tope de un bloqueo: unas vacaciones largas caben, un año entero no. */
    public const MAX_DIAS = 62;

    /**
     * Los vigentes que pisan [$desde, $hasta), ordenados. Con $especialistaId,
     * los suyos más los de toda la clínica; sin él, todos.
     */
    public function entre(CarbonImmutable $desde, CarbonImmutable $hasta, ?string $especialistaId = null): Collection
    {
        $q = DB::table('bloqueos_agenda')
            ->whereNull('anulado_at')
            ->where('inicio', '<', $hasta->format('Y-m-d H:i:s'))
            ->where('fin', '>', $desde->format('Y-m-d H:i:s'))
            // El desempate por id es obligatorio (§9.14).
            ->orderBy('inicio')->orderBy('id');
        if ($especialistaId !== null) {
            $q->where(fn ($w) => $w->where('especialista_id', $especialistaId)->orWhereNull('especialista_id'));
        }

        return $q->get();
    }

    /**
     * Citas vivas que caen dentro del bloqueo. No se cancelan solas: quien
     * bloquea decide qué hacer con cada una, pero tiene que saber que existen.
     */
    public function citasQueChocan(CarbonImmutable $inicio, CarbonImmutable $fin, ?string $especialistaId): int
    {
        $q = DB::table('citas')
            ->whereIn('estado', ['Agendada', 'Confirmada'])
            // Una cita que empezó antes del bloqueo y sigue adentro también choca:
            // se mira el inicio con el margen de la cita más larga posible (un día).
            ->where('fecha_hora', '<', $fin->format('Y-m-d H:i:s'))
            ->where('fecha_hora', '>=', $inicio->subDay()->format('Y-m-d H:i:s'));
        if ($especialistaId !== null) {
            $q->where('especialista_id', $especialistaId);
        }

        return $q->get(['fecha_hora', 'duracion_min'])
            ->filter(function ($c) use ($inicio) {
                $fin = CarbonImmutable::parse($c->fecha_hora)->addMinutes((int) ($c->duracion_min ?: 30));
                return $fin > $inicio;
            })
            ->count();
    }

    /** Lo que ve el panel de un bloqueo. */
    public function salida(object $b, array $nombres = []): array
    {
        return [
            'id' => $b->id,
            'especialista_id' => $b->especialista_id,
            'inicio' => CarbonImmutable::parse($b->inicio)->format('Y-m-d\TH:i:s'),
            'fin' => CarbonImmutable::parse($b->fin)->format('Y-m-d\TH:i:s'),
            'motivo' => $b->motivo,
            'creado_por' => $b->creado_por_id ? ($nombres[$b->creado_por_id] ?? null) : null,
        ];
    }
}
