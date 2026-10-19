<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Horas trabajadas, derivadas de los marcajes. Port de
 * `backend/app/services/asistencia_service.py`.
 *
 * La tabla guarda el HECHO —entrada o salida, y cuándo—. Las horas se calculan
 * acá emparejando. Guardar el turno ya cerrado obligaría a decidir al marcar
 * qué pasa con quien entra y no sale, y esa decisión hay que poder cambiarla
 * después.
 *
 * CÓMO SE EMPAREJA, Y QUÉ PASA CON LO QUE NO CIERRA
 *
 * Se recorren las marcas de una persona en orden y se abre un tramo con cada
 * `entrada`, que cierra con la primera `salida` posterior. Lo que queda:
 *
 * · Una entrada sin salida NO suma horas y se cuenta aparte, como `sin_cerrar`.
 * · Dos entradas seguidas: la segunda reemplaza a la primera (marcar dos veces
 *   por las dudas es un solo hecho).
 * · Una salida sin entrada previa se ignora y se cuenta en `salidas_huerfanas`.
 *
 * Los tramos se atribuyen al día de la ENTRADA, no al de la salida.
 *
 * POR QUÉ LA CUENTA DE MINUTOS SE HACE EN UTC
 *
 * Python resta dos `datetime` naive: es diferencia de reloj de pared, sin
 * husos. Un turno 22:00 → 02:00 que cruza el cambio de hora son 4 horas de
 * reloj, no 3 de tiempo real. Parseando en `America/Santiago`, `getTimestamp()`
 * daría el tiempo real y divergiría de Python dos noches al año. Parseando en
 * UTC —que no tiene transiciones— la resta vuelve a ser de reloj de pared.
 * Es el mismo criterio que `App\Services\Fechas`.
 */
class Asistencia
{
    private const ENTRADA = 'entrada';

    /**
     * @param  list<object>  $marcas  ordenadas por `momento` ascendente
     * @return array{0: list<array{0: string, 1: string}>, 1: int, 2: int}
     *         [tramos [inicio, fin], entradas sin cerrar (0|1), salidas huérfanas]
     */
    public static function tramos(array $marcas): array
    {
        $tramos = [];
        $abierta = null;
        $huerfanas = 0;

        foreach ($marcas as $m) {
            if ($m->tipo === self::ENTRADA) {
                // Dos entradas seguidas: manda la última.
                $abierta = $m->momento;
            } elseif ($abierta !== null) {
                $tramos[] = [$abierta, $m->momento];
                $abierta = null;
            } else {
                $huerfanas++;
            }
        }

        return [$tramos, $abierta !== null ? 1 : 0, $huerfanas];
    }

    /**
     * Horas por persona en el rango `[$desde, $hasta]` (fechas Y-m-d), con lo
     * que no cierra a la vista. Si `$usuarioId` viene, filtra a esa persona.
     */
    public static function resumen(string $desde, string $hasta, ?string $usuarioId = null): array
    {
        $q = DB::table('marcajes_asistencia')
            ->where('momento', '>=', $desde . ' 00:00:00')
            ->where('momento', '<=', $hasta . ' 23:59:59.999999');
        if ($usuarioId !== null) {
            $q->where('usuario_id', $usuarioId);
        }
        // El orden por `momento` es lo que sostiene el emparejamiento; el
        // desempate por `id` NO es opcional —dos marcas en el mismo segundo se
        // ordenarían al azar y el empate cambia de qué lado cae el tramo (§9.14)—.
        $filas = $q->orderBy('usuario_id')->orderBy('momento')->orderBy('id')->get();

        $porPersona = [];
        foreach ($filas as $m) {
            $porPersona[$m->usuario_id][] = $m;
        }

        $nombres = empty($porPersona)
            ? collect()
            : DB::table('usuarios')->whereIn('id', array_keys($porPersona))->pluck('nombre', 'id');

        $salida = [];
        foreach ($porPersona as $uid => $marcas) {
            [$tramos, $abiertos, $huerfanas] = self::tramos($marcas);

            $minutos = 0;
            $porDia = [];
            foreach ($tramos as [$ini, $fin]) {
                $mins = self::minutos($ini, $fin);
                $minutos += $mins;
                // Al día de la ENTRADA: un turno que cruza la medianoche es del
                // día que empezó.
                $dia = substr($ini, 0, 10);
                $porDia[$dia] = ($porDia[$dia] ?? 0) + $mins;
            }
            ksort($porDia);

            $salida[] = [
                'usuario_id' => (string) $uid,
                'nombre' => $nombres[$uid] ?? '—',
                'minutos' => $minutos,
                'horas' => round($minutos / 60, 2),
                'turnos' => count($tramos),
                // Se muestran, no se esconden: son lo que hay que corregir.
                'sin_cerrar' => $abiertos,
                'salidas_huerfanas' => $huerfanas,
                'por_dia' => array_map(
                    fn ($d, $n) => ['fecha' => $d, 'minutos' => $n],
                    array_keys($porDia),
                    array_values($porDia),
                ),
            ];
        }

        // -minutos, luego nombre, luego id: idéntico a la clave de Python.
        usort($salida, function ($a, $b) {
            return [$b['minutos'], $a['nombre'], $a['usuario_id']]
                <=> [$a['minutos'], $b['nombre'], $b['usuario_id']];
        });

        return $salida;
    }

    /**
     * `entrada` si la persona está dentro, `salida` si no. Lo usa el botón para
     * saber qué ofrecer, en vez de que la pantalla lo adivine.
     */
    public static function estadoActual(string $usuarioId): string
    {
        $ultima = DB::table('marcajes_asistencia')
            ->where('usuario_id', $usuarioId)
            ->orderByDesc('momento')->orderByDesc('id')
            ->first();

        return ($ultima && $ultima->tipo === self::ENTRADA) ? self::ENTRADA : 'salida';
    }

    /**
     * Minutos enteros entre dos marcas, contados como reloj de pared (ver la
     * cabecera de la clase). `intdiv` trunca igual que el `// 60` de Python,
     * y para un intervalo positivo eso es piso.
     */
    private static function minutos(string $inicio, string $fin): int
    {
        $i = CarbonImmutable::parse(substr($inicio, 0, 26), 'UTC')->getTimestamp();
        $f = CarbonImmutable::parse(substr($fin, 0, 26), 'UTC')->getTimestamp();

        return intdiv($f - $i, 60);
    }
}
