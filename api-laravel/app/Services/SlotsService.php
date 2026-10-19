<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Disponibilidad por especialista y servicio. Puerto de
 * `backend/app/services/slots_service.py`, la pieza más intrincada del backend.
 *
 * Cada regla de acá se ganó con un incidente. Las que más cuidado piden:
 *
 * **El límite superior de la ventana es una hora de INICIO válida.**
 * `cursor <= limite`, no `cursor + duracion <= limite`. Es requerimiento
 * explícito de la clínica: `hora_fin` del bloque es la última hora a la que se
 * puede EMPEZAR, no el fin de la atención. Cambiarlo por lo que parece correcto
 * borra una hora reservable de cada bloque.
 *
 * **Los choques se miran por ESPECIALISTA, sin filtrar por servicio.** De ahí
 * sale gratis el bloqueo del box: la misma persona no puede atender dos cosas a
 * la vez. Filtrar también por servicio rompería eso en silencio.
 *
 * **La ventana de pago libera el cupo.** Una reserva con abono pendiente
 * retiene su horario solo 15 minutos (desde que se reservó, o desde que se
 * mandó el enlace si la coordinó la clínica); pasados, vuelve a ofrecerse
 * aunque el barrido todavía no la haya marcado 'Expirada'. Sin esta condición
 * el cupo seguiría tomado hasta que el barrido pasara.
 *
 * **Google falla ABIERTO.** Si la consulta al calendario falla, se sigue con lo
 * local. `/citas/slots` es público: un corte de Google no puede dejar la página
 * de reservas en 500 y sin horarios.
 *
 * **Los bloqueos de la agenda también ocupan** (`BloqueosAgenda`): los del
 * especialista y los de toda la clínica. Fallan abierto igual que Google: una
 * base a la que todavía no se le agregó la tabla sigue ofreciendo horas.
 */
class SlotsService
{
    /**
     * Minutos que una reserva sin pagar retiene su cupo.
     *
     * Público porque el correo al tutor anuncia ESTE plazo (`CitasEmail`):
     * decía «48 horas, la reserva se cancela automáticamente» y no era cierto
     * ni el número ni la consecuencia. Con la constante compartida, el texto no
     * puede volver a separarse del comportamiento.
     */
    public const MINUTOS_VENTANA_PAGO = 15;

    /** Estados que de verdad ocupan un horario. */
    private const ESTADOS_QUE_BLOQUEAN = ['Agendada', 'Confirmada'];

    // `new` como valor por defecto: hay pruebas que arman el servicio a mano
    // con un GoogleCalendar doble, y los bloqueos no necesitan ninguno.
    public function __construct(private GoogleCalendar $google,
                                private BloqueosAgenda $bloqueos = new BloqueosAgenda())
    {
    }

    /**
     * @return array<string, string[]>  'YYYY-MM-DD' => ['09:00', …]
     */
    public function para(object $servicio, object $especialista, CarbonImmutable $desde, int $dias = 14): array
    {
        $dur = (int) $servicio->duracion_min;
        $finRango = $desde->addDays($dias);

        $bloques = $this->ocupados($especialista, $desde, $finRango);

        // Horas de inicio cada 30 min, salvo las consultas de una hora, que la
        // clínica agenda en horas redondas (10:00, 11:00…) y no a y media.
        $paso = $dur === 60 ? 60 : 30;

        $salida = [];
        for ($i = 0; $i < $dias; $i++) {
            $dia = $desde->addDays($i)->startOfDay();
            $horas = [];

            foreach ($this->ventanasDelDia($especialista, $servicio, $dia) as [$ini, $fin]) {
                $cursor = $this->enElDia($dia, $ini);
                $limite = $this->enElDia($dia, $fin);

                // `<=`: la hora de cierre del bloque también se ofrece como
                // inicio, aunque la cita se extienda más allá del cierre
                // nominal. Requerimiento explícito, no un descuido.
                while ($cursor <= $limite) {
                    $finSlot = $cursor->addMinutes($dur);
                    // `> $desde` y no `>=`: no se ofrece una hora que ya pasó,
                    // ni la de este mismo instante.
                    if ($cursor > $desde && ! $this->choca($cursor, $finSlot, $bloques)) {
                        $horas[] = $cursor->format('H:i');
                    }
                    $cursor = $cursor->addMinutes($paso);
                }
            }

            if ($horas) {
                // `unique` + `sort`: dos ventanas del mismo día pueden
                // solaparse y producir la misma hora dos veces.
                $horas = array_values(array_unique($horas));
                sort($horas);
                $salida[$dia->format('Y-m-d')] = $horas;
            }
        }

        return $salida;
    }

    /** ¿El slot pisa algún bloque ocupado? Solapamiento estricto. */
    private function choca(CarbonImmutable $ini, CarbonImmutable $fin, array $bloques): bool
    {
        foreach ($bloques as [$bIni, $bFin]) {
            if ($ini < $bFin && $fin > $bIni) {
                return true;
            }
        }
        return false;
    }

    /**
     * Los rangos ocupados: citas locales + bloqueos de la agenda + Google (los
     * dos últimos fallan abierto).
     *
     * @return array<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function ocupados(object $especialista, CarbonImmutable $desde, CarbonImmutable $finRango): array
    {
        $limiteVentana = CarbonImmutable::now('UTC')
            ->subMinutes(self::MINUTOS_VENTANA_PAGO);

        $citas = DB::table('citas')
            ->where('especialista_id', $especialista->id)
            ->whereIn('estado', self::ESTADOS_QUE_BLOQUEAN)
            ->where('fecha_hora', '>=', $desde->startOfDay()->format('Y-m-d H:i:s'))
            ->where('fecha_hora', '<', $finRango->format('Y-m-d H:i:s'))
            // Retiene el cupo si: no cobra en línea, ya pagó, o su ventana de
            // pago sigue abierta — 15 minutos desde que reservó o, si la
            // coordinó la clínica, desde que se le mandó el enlace (la regla
            // vive en LinkDePago). `created_at` y `pago_enlace_at` están en UTC
            // (marca técnica), a diferencia de `fecha_hora`, en hora local.
            ->where(function ($q) use ($limiteVentana) {
                $q->whereNull('monto_abono_clp')
                  ->orWhere('payment_status', 'approved')
                  ->orWhere(fn ($v) => LinkDePago::whereVentanaAbierta($v, $limiteVentana->format('Y-m-d H:i:s')));
            })
            ->get();

        $bloques = [];
        foreach ($citas as $c) {
            $ini = CarbonImmutable::parse($c->fecha_hora);
            $bloques[] = [$ini, $ini->addMinutes((int) ($c->duracion_min ?: 30))];
        }

        try {
            foreach ($this->bloqueos->entre($desde->startOfDay(), $finRango, $especialista->id) as $b) {
                $bloques[] = [CarbonImmutable::parse($b->inicio), CarbonImmutable::parse($b->fin)];
            }
        } catch (\Throwable $e) {
            Log::warning('Bloqueos de la agenda (disponibilidad): ' . $e->getMessage());
        }

        // ── Google Calendar ──────────────────────────────────────────────
        //
        // **Falla ABIERTO, y es la regla más importante de este método**: si
        // Google no contesta, se devuelve lo local y la página de reservas
        // sigue mostrando horarios. `/citas/slots` es PÚBLICO — un corte de
        // Google que dejara esto en 500 tumba la página entera, que es
        // exactamente lo que pasó una vez en Python con `_token()`.
        //
        // Por eso todo va dentro del `try`, incluida la obtención del token: el
        // bug de allá fue justamente que el refresh quedó FUERA del try.
        try {
            $ajustes = DB::table('ajustes')->where('id', 1)->first();
            $cuenta = ($ajustes->google_account_id ?? null)
                ? DB::table('google_accounts')->where('id', $ajustes->google_account_id)->first()
                : null;

            if ($cuenta) {
                $tz = $especialista->zona_horaria ?: 'America/Santiago';
                $d = $desde->startOfDay()->format('Y-m-d H:i:s');
                $h = $finRango->format('Y-m-d H:i:s');

                foreach ($this->deLaClinica($cuenta, $ajustes->calendario_reservas ?? '',
                                            $especialista, $d, $h, $tz) as $r) {
                    $bloques[] = [CarbonImmutable::parse($r[0]), CarbonImmutable::parse($r[1])];
                }

                // Su calendario de reservas propio, si tiene. Acá **todo** evento
                // bloquea, sin leer nombres: el calendario ya dice de quién es la
                // hora, y una anotada a mano sin nombre es suya. Es la razón de
                // que exista (la regla de nombres de arriba sigue solo para el
                // calendario compartido de la clínica).
                $propio = $especialista->google_calendar_id ?? null;
                if ($propio && $propio !== ($ajustes->calendario_reservas ?? null)) {
                    foreach ($this->google->eventosOcupados($cuenta, $propio, $d, $h, $tz) as $r) {
                        $bloques[] = [CarbonImmutable::parse($r[0]), CarbonImmutable::parse($r[1])];
                    }
                }

                // Los calendarios de conflicto del especialista, si declaró alguno.
                $propios = $especialista->calendarios_conflicto ?? null;
                if (($especialista->disponibilidad_modo ?? null) === 'especificos' && $propios) {
                    $ids = is_array($propios) ? $propios : (json_decode($propios, true) ?: []);
                    foreach ($this->google->ocupadosFreebusy($cuenta, $ids, $d, $h, $tz) as $r) {
                        $bloques[] = [CarbonImmutable::parse($r[0]), CarbonImmutable::parse($r[1])];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Google Calendar (disponibilidad): ' . $e->getMessage());
        }

        return $bloques;
    }

    /**
     * Los bloqueos que salen del calendario compartido de la clínica.
     *
     * Ese calendario es **uno solo** y recibe las citas de todos, pero la
     * clínica tiene **2 box en paralelo**: un evento de otro especialista no
     * puede tapar la agenda de este. Google no dice de quién es cada evento,
     * así que se resuelve por el texto (título + descripción):
     *
     *   · nombra a ESTE especialista        → bloquea;
     *   · nombra a OTRO especialista activo → no bloquea;
     *   · no nombra a nadie                 → **bloquea**.
     *
     * El tercer caso es el que importa y el que no se estaba respetando: una
     * cita escrita a mano en Google —lo más típico— no nombra a nadie, y es un
     * compromiso real de la clínica. Dejarla pasar es ofrecer una hora que ya
     * está tomada.
     *
     * @return array<array{0:string,1:string}>
     */
    private function deLaClinica(object $cuenta, string $calendarioId, object $especialista,
                                 string $desde, string $hasta, string $tz): array
    {
        if (! $calendarioId) {
            return [];
        }
        $eventos = $this->google->eventosOcupados($cuenta, $calendarioId, $desde, $hasta, $tz);
        if (! $eventos) {
            return [];
        }

        $yo = mb_strtolower(trim((string) ($especialista->nombre ?? '')));
        $otros = DB::table('especialistas')->where('activo', true)
            ->pluck('nombre')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))
            ->filter(fn ($n) => $n !== '' && $n !== $yo)
            ->all();

        $bloques = [];
        foreach ($eventos as [$ini, $fin, $texto]) {
            $t = mb_strtolower((string) $texto);
            if ($yo !== '' && str_contains($t, $yo)) {
                $bloques[] = [$ini, $fin];
                continue;
            }
            $deOtro = false;
            foreach ($otros as $o) {
                if (str_contains($t, $o)) {
                    $deOtro = true;
                    break;
                }
            }
            if (! $deOtro) {
                $bloques[] = [$ini, $fin];
            }
        }
        return $bloques;
    }

    /**
     * Rangos (inicio, fin) válidos ese día, según los Horarios del
     * especialista. Cae al esquema legado solo si no hay Horarios definidos.
     *
     * Sin $servicio no filtra por servicio: es lo que pinta la Agenda, que
     * muestra cuándo trabaja cada uno, atienda lo que atienda. `fin` sigue
     * siendo la última hora a la que se puede EMPEZAR (ver arriba).
     *
     * @return array<array{0: string, 1: string}>
     */
    public function ventanasDelDia(object $especialista, ?object $servicio, CarbonImmutable $dia): array
    {
        // `dayOfWeek` de Carbon es 0=domingo; Python usa 0=lunes.
        $dow = (int) $dia->dayOfWeekIso - 1;
        $ventanas = [];

        $horarios = DB::table('horarios')
            ->where('especialista_id', $especialista->id)
            ->orderBy('orden')->orderBy('nombre')->orderBy('id')
            ->get();

        if ($horarios->isNotEmpty()) {
            foreach ($horarios as $h) {
                if ($h->valido_desde && $dia->format('Y-m-d') < substr($h->valido_desde, 0, 10)) {
                    continue;
                }
                if ($h->valido_hasta && $dia->format('Y-m-d') > substr($h->valido_hasta, 0, 10)) {
                    continue;
                }
                if ($h->semana_paridad) {
                    // `isoWeek` para que coincida con `isocalendar()[1]`.
                    $paridad = $dia->isoWeek % 2 === 0 ? 'par' : 'impar';
                    if ($paridad !== $h->semana_paridad) {
                        continue;
                    }
                }
                if ($servicio !== null && ! $h->todos_servicios) {
                    $permitidos = json_decode($h->servicio_ids ?: '[]', true) ?: [];
                    if (! in_array((string) $servicio->id, array_map('strval', $permitidos), true)) {
                        continue;
                    }
                }
                foreach (DB::table('horario_bloques')
                    ->where('horario_id', $h->id)
                    ->orderBy('dia_semana')->orderBy('hora_inicio')->orderBy('id')
                    ->get() as $b) {
                    if ((int) $b->dia_semana === $dow) {
                        $ventanas[] = [$b->hora_inicio, $b->hora_fin];
                    }
                }
            }
        } else {
            foreach (DB::table('disponibilidad_especialistas')
                ->where('especialista_id', $especialista->id)
                ->orderBy('dia_semana')->orderBy('id')
                ->get() as $d) {
                if ((int) $d->dia_semana === $dow) {
                    $ventanas[] = [$d->hora_inicio, $d->hora_fin];
                }
            }
        }

        return $ventanas;
    }

    /** 'HH:MM' sobre un día concreto, sin conversión de zona (§9.6). */
    private function enElDia(CarbonImmutable $dia, string $hhmm): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $dia->setTime($h, $m);
    }
}
