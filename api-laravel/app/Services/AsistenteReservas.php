<?php

namespace App\Services;

use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\CitasController;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El chatbot como ASISTENTE DE RESERVAS, sin IA (sep-2026).
 *
 * PARA QUÉ
 *
 * El chat vive dentro del portal de reservas. Hasta acá, todo lo que olía a
 * «quiero una hora» se derivaba a WhatsApp — o sea, a la oficina —, justo en la
 * página donde la persona ya podía reservar sola. Esto lo lleva de vuelta al
 * portal, a un clic de confirmar.
 *
 * LO QUE NUNCA HACE: RESERVAR
 *
 * Mismo principio que «El agente lleva al stepper en vez de reservar» (0bddbc2):
 * devuelve ENLACES a `/reservar?servicio=…&especialista=…&fecha=…&hora=…`, que el
 * portal ya sabe preseleccionar (`precargarDesdeUrl` en ReservarView.vue). La
 * cita se crea en un único lugar —POST /citas/reservar, con su recálculo de
 * disponibilidad justo antes de insertar, los montos congelados y el 409 si la
 * hora se tomó—. Las horas que ofrece NO se retienen; el texto no dice «te la
 * reservé».
 *
 * CUATRO CASOS
 *   · pide cambiar o cancelar su hora  → «Mis citas» (pide sesión; nunca se
 *     cancela desde el chat)
 *   · pide hora sin decir cuál consulta → una acción por consulta
 *   · nombra la consulta                → acción directa a esa consulta
 *   · nombra la consulta y un CUÁNDO    → las próximas horas libres reales
 *
 * ANTE LA DUDA, NO CONTESTA: si no reconoce la intención de reservar devuelve
 * `null` y el mensaje sigue a `RespuestaDirecta` como siempre.
 */
class AsistenteReservas
{
    /** Intención de reservar. Frases con el «qué» adentro: «hora» a secas también es «¿a qué hora abren?». */
    private const RESERVAR = [
        'agendar', 'agenda', 'reservar', 'reserva', 'pedir hora', 'tomar hora', 'sacar hora',
        'una hora', 'hora para', 'hora pa ', 'quiero hora', 'necesito hora', 'hay hora',
        'tienen hora', 'tiene hora',
    ];

    /**
     * Solo cuentan si además nombra una consulta: la clínica tiene farmacia y pet
     * shop, y «¿tienen disponible Apoquel?» habla de stock, no de agenda.
     */
    private const RESERVAR_SI_NOMBRA = ['disponib', 'cupo'];

    /**
     * Habla de una hora que YA tiene. Con plural: «¿tengo reservas?» (en vivo,
     * 05-oct) caía en «reserva» de RESERVAR y le ofrecía el catálogo para pedir
     * otra.
     */
    private const SU_PROPIA = '/\b(mis?|tengo|tenia) (una |alguna |algunas )?(hora|cita|reserva)s?\b/';

    /** …salvo que la esté pidiendo: «quiero agendar mi hora de control» es reservar. */
    private const PIDE_NUEVA = ['agendar', 'reservar', 'pedir', 'sacar', 'tomar'];

    /** Cancelar siempre es de la propia cita; cambiar/mover solo si habla de su hora o cita. */
    private const CANCELAR = ['cancelar', 'cancelo', 'cancela', 'anular', 'reagendar', 'posponer'];

    /** En Chile «cancelar» también es PAGAR: «¿cancelo en efectivo?» no quiere anular nada. */
    private const PAGO = ['efectivo', 'tarjeta', 'transferencia', 'abono', 'pagar', 'pago', 'debito', 'credito', 'monto', 'cobro'];

    /** Verbos de pedir una hora nueva: con ellos, una palabra de pago no saca el mensaje de acá. */
    private const PIDE_HORA = ['agendar', 'reservar', 'pedir hora', 'tomar hora', 'sacar hora', 'quiero hora',
        'necesito hora', 'una hora', 'hora para', 'hay hora', 'tienen hora'];

    private const CAMBIAR = ['cambiar', 'cambio', 'mover', 'muevo'];

    private const SU_CITA = ['hora', 'cita'];

    private const DIAS = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];

    private const DIAS_CORTO = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

    private const MAX_HORAS = 4;

    public function __construct(private SlotsService $slots, private CobroService $cobro)
    {
    }

    /**
     * `['texto' => …, 'motivo' => …, 'acciones' => [['texto' => …, 'ruta' => …]]]`
     * o `null` si el mensaje no es de reservas.
     */
    public function resolver(string $mensaje, ?CarbonImmutable $ahora = null): ?array
    {
        $t = ' ' . self::norm($mensaje) . ' ';
        $ahora ??= CarbonImmutable::now();

        $suPropia = preg_match(self::SU_PROPIA, $t) && ! self::pide($t, self::PIDE_NUEVA);
        $anula = self::pide($t, self::CANCELAR) && ! self::pide($t, self::PAGO);
        if ($suPropia || $anula || (self::pide($t, self::CAMBIAR) && self::pide($t, self::SU_CITA))) {
            return [
                'texto' => 'Puedes ver, cambiar o cancelar tu hora desde Mis citas, iniciando sesión con el '
                    . 'correo con que reservaste. Si no puedes entrar, escríbenos por WhatsApp al '
                    . config('emr.clinica_telefono') . '.',
                'motivo' => 'mis_citas',
                'acciones' => [['texto' => 'Ir a Mis citas', 'ruta' => '/citas']],
            ];
        }

        // «¿cómo pago la reserva?» habla de pagar una que ya existe, no de pedir
        // otra: con una palabra de pago y sin un verbo de pedir hora, no es de acá.
        if (self::pide($t, self::PAGO) && ! self::pide($t, self::PIDE_HORA)) {
            return null;
        }

        $fuerte = self::pide($t, self::RESERVAR);
        if (! $fuerte && ! self::pide($t, self::RESERVAR_SI_NOMBRA)) {
            return null;
        }

        $servicios = $this->nombrados($t);
        if (! $fuerte && ! $servicios) {
            return null;
        }

        if (! $servicios) {
            $todos = DB::table('servicios')->where('activo', true)
                ->orderBy('orden')->orderBy('nombre')->orderBy('id')->get();

            return [
                'texto' => '¡Claro! ¿Qué consulta necesitas? Elige una y te llevo a sus horas.',
                'motivo' => 'reservar:elegir',
                'acciones' => $todos->map(fn ($s) => [
                    'texto' => $s->nombre,
                    'ruta' => '/reservar?' . http_build_query(['servicio' => $s->id]),
                ])->values()->all(),
            ];
        }

        [$desde, $dias, $cuando] = $this->cuando($t, $ahora);

        // Más de dos: buscar horas de algunas y callar las otras escondería
        // opciones. Mejor que elija, y ahí ve las horas de la que eligió.
        if ($desde !== null && count($servicios) > 2) {
            return [
                'texto' => "Hay varias consultas que calzan. Elige la que necesitas y verás sus horas {$cuando}:",
                'motivo' => 'reservar:consulta',
                'acciones' => array_map(fn ($s) => [
                    'texto' => $s->nombre,
                    'ruta' => '/reservar?' . http_build_query(['servicio' => $s->id]),
                ], $servicios),
            ];
        }

        if ($desde === null) {
            return [
                'texto' => count($servicios) === 1
                    ? "Te dejo {$servicios[0]->nombre} lista para elegir especialista y hora."
                    : 'Encontré estas opciones. Elige la que necesitas:',
                'motivo' => 'reservar:consulta',
                'acciones' => array_map(fn ($s) => [
                    'texto' => $s->nombre,
                    'ruta' => '/reservar?' . http_build_query(['servicio' => $s->id]),
                ], $servicios),
            ];
        }

        $horas = [];
        foreach ($servicios as $svc) {
            foreach ($this->especialistasDe($svc) as $esp) {
                foreach ($this->slots->para($svc, $esp, $desde, $dias) as $fecha => $lista) {
                    foreach ($lista as $hora) {
                        $horas[] = [$fecha, $hora, $svc, $esp];
                    }
                }
            }
        }
        usort($horas, fn ($a, $b) => [$a[0], $a[1], $a[3]->nombre] <=> [$b[0], $b[1], $b[3]->nombre]);
        $horas = array_slice($horas, 0, self::MAX_HORAS);

        $verTodas = array_map(fn ($s) => [
            'texto' => 'Ver todas las horas de ' . $s->nombre,
            'ruta' => '/reservar?' . http_build_query(['servicio' => $s->id]),
        ], $servicios);

        if (! $horas) {
            return [
                'texto' => "No encontré horas libres {$cuando}. Puedes ver las siguientes aquí:",
                'motivo' => 'reservar:sin_horas',
                'acciones' => $verTodas,
            ];
        }

        return [
            // «Estas son las próximas» y no «te la reservé»: no se retiene nada.
            'texto' => "Estas son las próximas horas libres {$cuando}. Elige una y confirma tus datos:",
            'motivo' => 'reservar:horas',
            'acciones' => array_merge(array_map(function ($h) use ($servicios) {
                [$fecha, $hora, $svc, $esp] = $h;
                $d = CarbonImmutable::createFromFormat('Y-m-d', $fecha);
                $etiqueta = self::DIAS_CORTO[$d->dayOfWeek] . ' ' . $d->day . ' · ' . $hora . ' · ' . $esp->nombre;
                if (count($servicios) > 1) {
                    $etiqueta = $svc->nombre . ' · ' . $etiqueta;
                }

                return [
                    'texto' => $etiqueta,
                    'ruta' => '/reservar?' . http_build_query([
                        'servicio' => $svc->id, 'especialista' => $esp->id, 'fecha' => $fecha, 'hora' => $hora,
                    ]),
                ];
            }, $horas), $verTodas),
        ];
    }

    /**
     * El mensaje no es más que el nombre de una consulta: «Consulta general a
     * domicilio». Es lo que se escribe después de que el catálogo llegó como
     * lista —por WhatsApp va en texto, sin un botón por consulta— y hasta acá
     * no calzaba en nada: en vivo (lab, 05-oct, IA sin cupo) la persona copió
     * el nombre de la lista y recibió «Todavía no logro entender qué
     * necesitas». Va al FINAL de la capa sin IA (ChatDeterminista), así que
     * no le quita nada a RespuestaDirecta; y si sobra cualquier palabra que
     * el catálogo no explica, no contesta.
     */
    public function soloConsulta(string $mensaje): ?array
    {
        $t = ' ' . self::norm($mensaje) . ' ';
        $servicios = $this->nombrados($t);
        if (! $servicios || count($servicios) > 2 || CatalogoNombres::sobrante($mensaje, $servicios)) {
            return null;
        }

        return [
            'texto' => count($servicios) === 1
                ? "Te dejo {$servicios[0]->nombre} lista para elegir especialista y hora."
                : 'Encontré estas opciones. Elige la que necesitas:',
            'motivo' => 'reservar:consulta',
            'acciones' => array_map(fn ($s) => [
                'texto' => $s->nombre,
                'ruta' => '/reservar?' . http_build_query(['servicio' => $s->id]),
            ], $servicios),
        ];
    }

    /** Las consultas activas que el mensaje nombra (ver CatalogoNombres). */
    private function nombrados(string $t): array
    {
        return CatalogoNombres::nombrados($t, DB::table('servicios')->where('activo', true)
            ->orderBy('orden')->orderBy('nombre')->orderBy('id')->get());
    }

    /**
     * `[desde, días, texto]`, o `[null, 0, '']` si no pidió un cuándo.
     * «mañana» es el día siguiente, salvo «en la mañana» / «por la mañana», que es la hora del día.
     */
    private function cuando(string $t, CarbonImmutable $ahora): array
    {
        $hoy = $ahora->startOfDay();

        if (str_contains($t, 'pasado manana')) {
            return [$hoy->addDays(2), 1, 'para pasado mañana'];
        }
        if (preg_match('/(?<!la )\bmanana\b/', $t)) {
            return [$hoy->addDay(), 1, 'para mañana'];
        }
        if (preg_match('/\bhoy\b/', $t)) {
            return [$ahora, 1, 'para hoy'];
        }
        foreach (self::DIAS as $i => $dia) {
            if (preg_match('/\b' . $dia . '\b/', $t)) {
                $d = $hoy->addDays(($i - $hoy->dayOfWeek + 7) % 7 ?: 7);
                if ($i === $hoy->dayOfWeek && $ahora->hour < 18) {
                    $d = $ahora;   // «el lunes», dicho un lunes temprano: hoy
                }

                return [$d, 1, 'para el ' . str_replace(['sabado', 'miercoles'], ['sábado', 'miércoles'], $dia)];
            }
        }
        foreach (['esta semana', 'proxim', 'pronto', 'lo antes', 'cuando hay', 'disponib', 'hay hora', 'tienen hora', 'tiene hora'] as $k) {
            if (str_contains($t, $k)) {
                return [$ahora, 7, 'en los próximos días'];
            }
        }

        return [null, 0, ''];
    }

    /**
     * Mismo criterio que el paso 2 del portal (`CatalogoController::especialistas`).
     * Pública porque AgenteIA ofrece exactamente a los mismos: si cada uno
     * filtrara por su cuenta, la IA podría ofrecer a alguien que el portal no deja reservar.
     */
    public function especialistasDe(object $svc): array
    {
        // Ecografía, radiología: la consulta entera se confirma por WhatsApp
        // (30-sep-2026). Nadie la ofrece para reservar desde el chat.
        if (CitasController::coordinaPorWhatsapp($svc)) {
            return [];
        }
        $ids = DB::table('servicio_especialista')->where('servicio_id', $svc->id)->pluck('especialista_id');

        return DB::table('especialistas')->where('activo', true)
            ->orderBy('orden')->orderBy('nombre')->orderBy('id')->get()
            ->filter(fn ($e) => (bool) $e->reservable && ! $e->solo_consulta_whatsapp
                && CatalogoController::esPublico($e))
            ->filter(fn ($e) => $ids->isEmpty() || $ids->contains($e->id))
            // Consulta con cobro en línea sin monto para este especialista: el
            // backend rechazaría la reserva al final, así que no se ofrece.
            ->filter(fn ($e) => ($svc->modalidad_pago ?: 'abono') === 'nada'
                || $this->cobro->montoAbono($e, $svc) !== null)
            ->values()->all();
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');

        return strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            '¿' => ' ', '?' => ' ', '¡' => ' ', '!' => ' ', ',' => ' ', '.' => ' ',
        ]);
    }

    private static function pide(string $t, array $claves): bool
    {
        foreach ($claves as $k) {
            if (str_contains($t, $k)) {
                return true;
            }
        }

        return false;
    }
}
