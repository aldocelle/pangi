<?php

namespace App\Services;

use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\CitasController;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * El asistente de WhatsApp como INTERMEDIARIO entre un tutor y un especialista
 * que coordina su agenda por fuera (`solo_consulta_whatsapp`), sep-2026.
 *
 * Decisión de producto (30-sep-2026): «intermediario, el tutor y especialista que
 * no hablen directamente». La versión anterior le pasaba al especialista el
 * número del tutor para que lo llamara; ahora ninguno ve el número del otro.
 *
 * A DEMANDA, NUNCA POR INICIATIVA PROPIA
 *
 * A un especialista solo se le escribe cuando un tutor pidió hora con él y
 * confirmó el resumen. No se le pide disponibilidad «por si acaso»: la clínica
 * no quiere falsas alarmas ni expectativas en quien no tiene a nadie esperando.
 *
 * EL FLUJO
 *
 * 1. `abrir()`: le pregunta al especialista qué horarios tiene, con la mascota
 *    y el motivo, y un N° de solicitud. Estado `esperando_especialista`.
 * 2. El especialista responde a este mismo número de WhatsApp.
 *    `recibirDeEspecialista()` reconoce su teléfono, le transmite la respuesta
 *    al tutor y pasa a `esperando_tutor`.
 * 3. El tutor contesta (elige un horario, pregunta algo). El asistente lo
 *    transmite con `enviarDeTutor()` y vuelve a `esperando_especialista`.
 *
 * Se decide por ESTADO y no solo por teléfono a propósito: así un especialista
 * que además es cliente de la clínica —o la prueba del lab, donde tutor y
 * especialista son el mismo número— sigue pudiendo hablar con el asistente
 * como tutor mientras no se le esté esperando una respuesta.
 *
 * Vive en una tabla propia y no en la conversación del chat: la conversación
 * vence a las 3 horas y un especialista puede tardar un día en contestar.
 *
 * Todo lo que se transmite pasa por `sinContacto()`: un número, un correo o un
 * enlace escrito en el mensaje se oculta. Es lo que hace cumplir «que no hablen
 * directamente» aunque uno de los dos escriba su teléfono.
 */
class IntermediarioEspecialista
{
    /** Una solicitud sin movimiento más que esto se da por cerrada. */
    public const DIAS_VIGENCIA = 7;

    /**
     * Lo que se le dice al tutor mientras espera al especialista (decisión de
     * producto, 05-oct). Sin ese aviso la espera se lee como «el bot falló», y de
     * ahí salen los «¿y?» y las llamadas a la oficina. Dicho como posibilidad
     * y sin plazo: no sabemos si está atendiendo ni cuánto va a tardar.
     */
    public const PUEDE_TARDAR = 'Los especialistas contestan entre una atención y otra, así que su respuesta puede tardar un rato.';

    /** La versión corta, para cuando ya se le dijo y pregunta de nuevo. */
    public const PUEDE_ESTAR_ATENDIENDO = 'Puede estar atendiendo; apenas conteste, te llega por este chat';

    /**
     * Botones que acompañan la última respuesta al tutor (el de pagar el
     * abono). `elegirOpcion` devuelve texto porque así lo usan sus dos
     * llamadores; quien manda el mensaje los recoge con `tomarAcciones()`.
     */
    private array $acciones = [];

    public function __construct(private WhatsappEnvio $envio)
    {
    }

    /** Los botones de la última respuesta, y los suelta. */
    public function tomarAcciones(): array
    {
        $a = $this->acciones;
        $this->acciones = [];

        return $a;
    }

    /** Sin la tabla (migración sin correr) el intermediario no existe: nadie se cae. */
    public static function disponible(): bool
    {
        try {
            return Schema::hasTable('solicitudes_especialista');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Abre la solicitud y le pregunta al especialista por sus horarios.
     * Devuelve el N° de solicitud, o null si el mensaje no salió.
     *
     * @param array{servicio: string, tutor: string, mascota: string, motivo: string, preferencia: string} $p
     */
    public function abrir(object $esp, string $telTutor, array $p): ?int
    {
        $para = self::numeroWhatsapp($esp->telefono ?? null);
        if (! $para || ! self::disponible()) {
            return null;
        }
        $numero = (int) DB::table('solicitudes_especialista')->where('especialista_id', $esp->id)->max('numero') + 1;
        $orden = is_array($p['orden'] ?? null) ? $p['orden'] : null;
        $motivo = $p['motivo'] . ($orden ? ' (trae orden médica)' : '')
            . ($p['preferencia'] !== '' ? ". Prefiere: {$p['preferencia']}" : '');

        // Con plantilla aprobada por Meta llega siempre; sin ella, un texto
        // libre llega solo si el especialista le escribió a este número en las
        // últimas 24 h (fuera de esa ventana Meta lo acepta y no lo entrega).
        $plantilla = (string) config('emr.whatsapp_plantilla_especialista', '');
        $propuestos = HorariosOfrecidos::propuestos(CarbonImmutable::now('America/Santiago'), $this->ocupadas($esp->id));
        $intro = "Hola {$esp->nombre}. Te escribe el asistente de " . Marca::nombre() . ". Un tutor pide una hora de "
            . "{$p['servicio']} para {$p['mascota']}. Motivo: {$motivo}.";
        $texto = $intro . "\n\n" . self::pedirNumeros($propuestos, $numero, true);
        // Meta no admite saltos de línea en un parámetro de plantilla: la lista
        // va en un segundo mensaje. Ese segundo mensaje es texto libre y solo se
        // entrega dentro de la ventana de 24 h; si el especialista no escribió
        // hace poco, NO le llega. Por eso la plantilla lleva el botón «Ver
        // horarios»: tocarlo abre la ventana y `recibirDeEspecialista` le manda
        // la lista de nuevo (ver migracion/PLANTILLAS_WHATSAPP.md).
        $salio = $plantilla !== ''
            ? $this->envio->plantilla($para, $plantilla, (string) config('emr.whatsapp_plantilla_idioma', 'es'),
                [$esp->nombre, $p['servicio'], $p['mascota'], $motivo, (string) $numero], ["ver_horarios:{$numero}"])
                && ($this->pedirAlEspecialista($para, $numero, '', $propuestos) || true)
            : $this->pedirAlEspecialista($para, $numero, $intro, $propuestos);
        if (! $salio) {
            Log::warning("intermediario: no se pudo escribir a {$esp->nombre}");

            return null;
        }

        $ahora = self::ahora();
        $solicitudId = (string) Str::uuid();
        $primero = ['de' => 'asistente', 'texto' => $texto, 'at' => $ahora,
            'propuestos' => array_map(fn ($c) => $c->format('Y-m-d H:i'), $propuestos)];
        // Una hora que se está moviendo: se cancela cuando la nueva quede agendada.
        if (! empty($p['reemplaza'])) {
            $primero['reemplaza'] = (string) $p['reemplaza'];
        }
        // La orden médica sale de la carpeta de adjuntos —que se limpia a las
        // 48 h— a una propia de solicitudes: el especialista puede tardar días.
        if ($orden && is_file($orden['ruta'])) {
            $dir = self::dirOrdenes();
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $archivo = $solicitudId . '.' . $orden['ext'];
            if (@rename($orden['ruta'], $dir . '/' . $archivo)) {
                $primero['orden'] = ['archivo' => $archivo, 'nombre' => $orden['nombre'], 'ext' => $orden['ext']];
            }
        }
        DB::table('solicitudes_especialista')->insert([
            'id' => $solicitudId,
            'especialista_id' => $esp->id,
            'numero' => $numero,
            'tutor_telefono' => $telTutor,
            'tutor_nombre' => $p['tutor'],
            'mascota' => $p['mascota'],
            'servicio' => $p['servicio'],
            'motivo' => $motivo,
            'estado' => 'esperando_especialista',
            'mensajes' => json_encode([$primero], JSON_UNESCAPED_UNICODE),
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        Log::info("intermediario: solicitud N° {$numero} abierta con {$esp->nombre}");

        return $numero;
    }

    /**
     * Si `$telefono` es de un especialista al que se le espera respuesta, la
     * transmite al tutor y devuelve true. Si no, false: el mensaje sigue su
     * camino normal hacia el asistente.
     */
    public function recibirDeEspecialista(string $telefono, string $texto): bool
    {
        $abiertas = $this->esperandoA($telefono);
        if (! $abiertas) {
            return false;
        }

        $s = $abiertas[0];
        if (count($abiertas) > 1) {
            $s = null;
            // «N° 2: 1, 5»: con varias abiertas, la solicitud va primero y con
            // dos puntos, porque ahora la respuesta misma son números.
            if (preg_match('/^\s*(?:n[°º.]?\s*)?(\d{1,4})\s*[:\-]\s*/iu', $texto, $m)) {
                foreach ($abiertas as $a) {
                    if ((int) $a->numero === (int) $m[1]) {
                        $s = $a;
                        $texto = mb_substr($texto, mb_strlen($m[0]));
                        break;
                    }
                }
            }
            if (! $s) {
                $lista = implode(', ', array_map(fn ($a) => "N° {$a->numero} ({$a->mascota})", $abiertas));
                $this->envio->texto($telefono, "Tengo varias solicitudes esperando tu respuesta: {$lista}. "
                    . "Empieza tu mensaje con el N° de solicitud y dos puntos, por ejemplo «{$abiertas[0]->numero}: 1, 5».");

                return true;
            }
        }

        $limpio = self::sinContacto($texto);
        $ahoraLocal = CarbonImmutable::now('America/Santiago');

        // «Ver horarios»: el botón de la plantilla aprobada. La plantilla es lo
        // único que Meta entrega fuera de la ventana de 24 h; la lista numerada
        // es texto libre y solo llega después de que el especialista escribe.
        // Tocar el botón ES ese mensaje: ahora sí se le puede mandar la lista.
        // Con una sola solicitud abierta el prefijo «N° 7:» que arma el
        // webhook desde el botón no se quitó arriba: se acepta igual.
        if (preg_match('/^\s*(?:n[°º.]?\s*\d{1,4}\s*[:\-]\s*)?ver\s+(los\s+)?horarios\s*[.!]*$/iu', $limpio)) {
            $propuestos = array_map(fn ($c) => CarbonImmutable::parse($c, 'America/Santiago'), self::propuestosVigentes($s));
            $propuestos = array_values(array_filter($propuestos, fn ($c) => $c->gt($ahoraLocal)));
            if (! $propuestos) {
                $propuestos = HorariosOfrecidos::propuestos($ahoraLocal, $this->ocupadas($s->especialista_id));
            }
            $lista = self::pedirNumeros($propuestos, (int) $s->numero, true);
            $this->pedirAlEspecialista($telefono, (int) $s->numero,
                "Solicitud N° {$s->numero}: {$s->servicio} para {$s->mascota} (motivo: {$s->motivo}).", $propuestos);
            // Si ya le había respondido al tutor, sus opciones siguen valiendo
            // hasta que mande una corrección: el estado no cambia.
            $this->anotar($s, 'asistente', $lista, $s->estado, null,
                array_map(fn ($c) => $c->format('Y-m-d H:i'), $propuestos));

            return true;
        }

        // «sí» / «no» escritos a la pregunta de si se le envía su mensaje al tutor.
        $pendiente = Cache::get(self::claveRecado($s));
        if ($pendiente !== null && preg_match('/^\s*(s[ií]|sí,? env[ií]alo|env[ií]alo|enviar|env[ií]a|dale|ok)[\s.!]*$/iu', $limpio)) {
            Cache::forget(self::claveRecado($s));

            return $this->transmitirAlTutor($s, $telefono, $pendiente['texto']);
        }
        if ($pendiente !== null && preg_match('/^\s*(no|no lo env[ií]es|no enviar|cancelar?)[\s.!]*$/iu', $limpio)) {
            Cache::forget(self::claveRecado($s));
            $this->envio->texto($telefono, 'Listo, no se lo envié.');

            return true;
        }

        // Eligió números de la lista propuesta.
        $propuestos = self::propuestosVigentes($s);
        $numeros = $propuestos ? HorariosOfrecidos::numeros($limpio, count($propuestos)) : null;
        if ($numeros !== null) {
            $fuera = array_filter($numeros, fn ($n) => $n < 1 || $n > count($propuestos));
            if ($fuera || ! $numeros) {
                $this->envio->texto($telefono, 'La lista va del 1 al ' . count($propuestos)
                    . '. ¿Me dices de nuevo cuáles le ofrezco? Todavía no le mandé nada al tutor.');

                return true;
            }
            $elegidos = array_values(array_filter(
                array_map(fn ($n) => CarbonImmutable::parse($propuestos[$n - 1], 'America/Santiago'), $numeros),
                fn ($c) => $c->gt($ahoraLocal)));
            if (! $elegidos) {
                $this->envio->texto($telefono, 'Esos horarios ya pasaron. Elige otros o escríbeme días y horas.');

                return true;
            }

            return $this->ofrecerAlTutor($s, $telefono, $limpio, array_slice($elegidos, 0, HorariosOfrecidos::MAX_OPCIONES),
                $s->estado === 'esperando_tutor');
        }

        $leido = HorariosOfrecidos::extraer($limpio, $ahoraLocal);

        // Una fecha que no cuadra no llega al tutor: la aclara quien la escribió.
        if ($leido['problemas']) {
            $this->envio->texto($telefono, implode(' ', $leido['problemas'])
                . ' ¿Me lo escribes de nuevo? Todavía no le mandé nada al tutor.');

            return true;
        }

        if ($leido['opciones']) {
            return $this->ofrecerAlTutor($s, $telefono, $limpio, $leido['opciones'], $s->estado === 'esperando_tutor');
        }

        // Ya respondió y escribe «gracias», «ok»: no es para el tutor.
        if ($s->estado === 'esperando_tutor'
            && preg_match('/^\s*(gracias|muchas gracias|ok|okay|oki|listo|ya|perfecto|dale|bueno|de nada|👍|🙏)[\s.!]*$/iu', $limpio)) {
            $this->envio->texto($telefono, "Perfecto. Te aviso apenas el tutor de {$s->mascota} elija un horario.");

            return true;
        }

        if (self::esDudaDelEspecialista($limpio)) {
            $nuevos = HorariosOfrecidos::propuestos($ahoraLocal, $this->ocupadas($s->especialista_id));
            $texto = "Te escribo por un tutor que pide hora de {$s->servicio} para {$s->mascota} (motivo: {$s->motivo}). "
                . "Yo le paso al tutor los horarios que me indiques; él elige tocando uno y queda agendado. "
                . "Al tutor no le mandé tu pregunta.\n\n" . self::pedirNumeros($nuevos, (int) $s->numero, true);
            $this->pedirAlEspecialista($telefono, (int) $s->numero,
                "Te escribo por un tutor que pide hora de {$s->servicio} para {$s->mascota} (motivo: {$s->motivo}). "
                . 'Yo le paso al tutor los horarios que me indiques; él elige tocando uno y queda agendado. '
                . 'Al tutor no le mandé tu pregunta.', $nuevos);
            $this->anotar($s, 'asistente', $texto, $s->estado, null,
                array_map(fn ($c) => $c->format('Y-m-d H:i'), $nuevos));

            return true;
        }

        // «Más opciones», «otros días»: le pide más horarios AL SISTEMA. En vivo
        // (06-oct) el Dr. Tigre escribió «Maa opciones» y le llegó al tutor.
        if (preg_match('/\b(m[aá]{1,2}s?|otr[oa]s)\s+(opciones|horarios|horas|d[ií]as|fechas)\b/iu', $limpio)) {
            $this->masDias($s, $telefono, Cache::get("intermediario:seleccion:{$s->id}", []));

            return true;
        }

        // Sin horarios reconocibles («¿podrá ser el miércoles?»). Ya NO se le
        // transmite directo al tutor (decisión de producto, 06-oct): se le pregunta
        // al especialista si se lo envía. En vivo, «Quiero ver ek examen» —una
        // nota para el asistente— le llegó al tutor como si fuera para él.
        return $this->confirmarRecado($s, $telefono, $limpio);
    }

    private static function claveRecado(object $s): string
    {
        return "intermediario:recado:{$s->id}";
    }

    /** Lo que escribió el especialista queda pendiente hasta que diga que se lo envíe al tutor. */
    private function confirmarRecado(object $s, string $telefono, string $texto): bool
    {
        Cache::put(self::claveRecado($s), ['texto' => $texto], now()->addDay());
        $v = substr(md5($texto), 0, 6);
        $cuerpo = "¿Le envío este mensaje al tutor de {$s->mascota}?\n«{$texto}»";
        $botones = [
            ['id' => "msg:{$s->numero}:enviar:{$v}", 'titulo' => 'Sí, enviar al tutor'],
            ['id' => "msg:{$s->numero}:no:{$v}", 'titulo' => 'No enviar'],
            ['id' => "hor:{$s->numero}:mas:" . self::marca(self::propuestosVigentes($s)), 'titulo' => 'Elegir horarios'],
        ];
        if (! $this->envio->opciones($telefono, $cuerpo, 'Opciones', $botones)) {
            $this->envio->texto($telefono, $cuerpo . "\n\nResponde «sí» para enviárselo o «no» para dejarlo.");
        }

        return true;
    }

    /** El recado confirmado, al tutor. */
    private function transmitirAlTutor(object $s, string $telefono, string $texto): bool
    {
        $aviso = "Mensaje del {$s->esp_nombre} sobre la hora de {$s->mascota}:\n«{$texto}»\n\nDime qué te acomoda y se lo transmito.";
        if (! $this->envio->texto($s->tutor_telefono, $aviso)) {
            $this->envio->texto($telefono, 'No pude transmitírselo al tutor. Lo reintento más tarde; si es urgente, avísale a la clínica.');

            return true;
        }
        $this->anotar($s, 'especialista', $texto, 'esperando_tutor');
        $this->anotarEnChatDelTutor($s->tutor_telefono, $aviso);
        $this->envio->texto($telefono, "Listo, se lo envié al tutor de {$s->mascota}. Te aviso lo que responda.");

        return true;
    }

    /**
     * Los horarios del especialista, al tutor, como opciones para tocar. El
     * especialista recibe de vuelta lo que se entendió: si algo se leyó mal, lo
     * ve antes de que el tutor elija.
     *
     * @param list<CarbonImmutable> $opciones
     */
    private function ofrecerAlTutor(object $s, string $telEsp, string $original, array $opciones, bool $correccion = false): bool
    {
        $lista = implode("\n", array_map(fn ($c) => '• ' . HorariosOfrecidos::legible($c), $opciones));
        [$cuerpo, $filas] = self::mensajeOpciones($s, $opciones, $correccion
            ? "Corrección: {$s->esp_nombre} cambió los horarios para {$s->mascota}. Valen estos (los anteriores ya no):"
            : "{$s->esp_nombre} puede atender a {$s->mascota} en estos horarios:");

        if (! $this->envio->opciones($s->tutor_telefono, $cuerpo, 'Ver horarios', $filas)) {
            $this->envio->texto($telEsp, 'No pude transmitírselo al tutor. Lo reintento más tarde; si es urgente, avísale a la clínica.');

            return true;
        }
        $this->anotar($s, 'especialista', $original, 'esperando_tutor',
            array_map(fn ($c) => $c->format('Y-m-d H:i'), $opciones));
        $this->anotarEnChatDelTutor($s->tutor_telefono, $cuerpo
            . ' [Son botones: el tutor elige tocando uno. Si lo escribe en texto, pídele que lo toque.]');
        // La corrección ahora sí funciona (antes este mensaje la prometía y el
        // siguiente mensaje del especialista caía en el bot común).
        $this->envio->texto($telEsp, ($correccion ? "Listo, le corregí la lista al tutor de {$s->mascota}:\n"
                : "Gracias. Le ofrecí al tutor de {$s->mascota}:\n") . "{$lista}\n\n"
            . 'Te confirmo apenas elija. Si algo quedó mal, vuelve a elegir en la lista (o escríbeme los horarios) y le corrijo la lista al tutor.');

        return true;
    }

    /**
     * Cuerpo y botones de una lista de horarios para el tutor. Numerados: quien
     * no encuentra el botón escribe «2», y eso también agenda.
     *
     * @param list<CarbonImmutable> $opciones
     * @return array{0: string, 1: list<array{id: string, titulo: string}>}
     */
    private static function mensajeOpciones(object $s, array $opciones, string $cabecera): array
    {
        // La marca de ESTA lista va en cada botón: si el especialista corrige,
        // un botón del mensaje viejo no puede agendar la hora que hoy ocupa su
        // misma posición en la lista nueva.
        $v = self::marca(array_map(fn ($c) => $c->format('Y-m-d H:i'), $opciones));
        $filas = [];
        $lineas = [];
        foreach ($opciones as $i => $c) {
            $filas[] = ['id' => "esp:{$s->id}:{$i}:{$v}", 'titulo' => HorariosOfrecidos::corto($c)];
            $lineas[] = ($i + 1) . ') ' . HorariosOfrecidos::legible($c);
        }
        $filas[] = ['id' => "esp:{$s->id}:no:{$v}", 'titulo' => 'Ninguno me sirve'];

        return ["{$cabecera}\n" . implode("\n", $lineas) . "\n\nToca el que te acomode (o escríbeme su número) y queda agendado.", $filas];
    }

    /**
     * El tutor tocó una opción («esp:<solicitud>:<n>» o «…:no»). Todo lo decide
     * el código: la opción tiene que ser de la ÚLTIMA lista de esa solicitud,
     * de este mismo número, y la hora tiene que seguir libre.
     *
     * @return string lo que se le contesta al tutor
     */
    public function elegirOpcion(string $telTutor, string $id): string
    {
        if (! preg_match('/^esp:([\w-]{1,64}):(no|\d{1,2})(?::([0-9a-f]{6}))?$/', $id, $m) || ! self::disponible()) {
            return 'No reconocí esa opción. Escríbeme qué necesitas.';
        }
        $s = DB::table('solicitudes_especialista as s')
            ->join('especialistas as e', 'e.id', '=', 's.especialista_id')
            ->where('s.id', $m[1])
            ->select('s.*', 'e.nombre as esp_nombre', 'e.telefono as esp_telefono')->first();
        if (! $s || AgenteIA::nacional($s->tutor_telefono) !== AgenteIA::nacional($telTutor)) {
            return 'No reconocí esa opción. Escríbeme qué necesitas.';
        }
        if ($s->estado === 'agendada') {
            return "Esa hora con {$s->esp_nombre} ya quedó agendada. Si quieres cambiarla, escríbeme.";
        }
        $vigentes = self::opcionesVigentes($s);
        if ($s->estado !== 'esperando_tutor' || ! $vigentes) {
            return 'Esa lista ya no está vigente. Espera la respuesta del especialista o escríbeme.';
        }
        // Sin marca: un botón anterior a la marca, o el número escrito (que ya
        // se resolvió contra la lista vigente). Con marca, tiene que ser esta.
        if (! empty($m[3]) && $m[3] !== self::marca($vigentes)) {
            return "Esa lista cambió: {$s->esp_nombre} corrigió los horarios. Toca una opción de la lista más reciente.";
        }

        $para = self::numeroWhatsapp($s->esp_telefono ?? null);
        if ($m[2] === 'no') {
            // Una lista nueva, desde el día siguiente al último que se ofreció.
            $desde = CarbonImmutable::parse(end($vigentes), 'America/Santiago');
            $propuestos = HorariosOfrecidos::propuestos($desde, $this->ocupadas($s->especialista_id));
            $intro = "Sobre la solicitud N° {$s->numero} ({$s->mascota}): ninguno de esos horarios le sirve al tutor.";
            $texto = $intro . "\n\n" . self::pedirNumeros($propuestos, (int) $s->numero, true);
            if (! $para || ! $this->pedirAlEspecialista($para, (int) $s->numero, $intro, $propuestos)) {
                return 'No pude avisarle al especialista. Intenta de nuevo o llama a la clínica.';
            }
            $this->anotar($s, 'tutor', 'Ninguno de esos horarios le sirve.', 'esperando_especialista');
            $s->mensajes = DB::table('solicitudes_especialista')->where('id', $s->id)->value('mensajes');
            $this->anotar($s, 'asistente', $texto, 'esperando_especialista', null,
                array_map(fn ($c) => $c->format('Y-m-d H:i'), $propuestos));

            return "Le pedí otros horarios " . self::a($s->esp_nombre) . ". Si tienes preferencia de día u hora, escríbemela y se la paso.";
        }

        $i = (int) $m[2];
        if (! isset($vigentes[$i])) {
            return 'Esa lista ya no está vigente. Toca una opción de la última que te llegó.';
        }
        $cuando = CarbonImmutable::parse($vigentes[$i], 'America/Santiago');
        if ($cuando->lte(CarbonImmutable::now('America/Santiago'))) {
            return 'Ese horario ya pasó. Elige otro, o toca «Ninguno me sirve» para pedirle más.';
        }
        $tomada = DB::table('citas')->where('especialista_id', $s->especialista_id)
            ->whereIn('estado', ['Agendada', 'Confirmada'])
            ->where('fecha_hora', $cuando->format('Y-m-d H:i:s'))->exists();
        if ($tomada) {
            return 'Ese horario se acaba de tomar. Elige otro, o toca «Ninguno me sirve» para pedirle más.';
        }

        $citaId = $this->crearCita($s, $cuando);
        $anterior = $this->soltarReemplazada($s, $citaId);
        $legible = HorariosOfrecidos::legible($cuando);
        $this->anotar($s, 'tutor', "Elige {$legible}", 'agendada');

        // No hay reserva sin abono (pedido de la clínica, 09-oct): si la
        // consulta cobra en línea, la hora queda RETENIDA y se confirma al
        // pagar, dentro de la misma ventana que el portal. Antes nacía sin
        // monto y sin botón, y el tutor creía tener una hora firme.
        $pago = $this->cobroDe($citaId);
        $min = SlotsService::MINUTOS_VENTANA_PAGO;
        if ($para) {
            $this->envio->texto($para, ($pago
                    ? "El tutor eligió el {$legible} para {$s->mascota} (solicitud N° {$s->numero}, {$s->servicio}). "
                        . "La hora queda retenida y se confirma cuando pague el abono; si no paga en {$min} minutos, se libera y te aviso."
                    : "Listo: quedó agendada la hora de {$s->mascota} el {$legible} (solicitud N° {$s->numero}, {$s->servicio}).")
                . " Motivo: {$s->motivo}.");
        }
        if ($pago) {
            $monto = '$' . number_format($pago['a_pagar_clp'], 0, ',', '.');
            // Corto a propósito (WhatsApp admite 20 caracteres en el botón):
            // el monto ya va en el texto, y así la pregunta queda al final.
            $this->acciones = [['texto' => 'Pagar ' . $monto, 'ruta' => '/pago/' . $citaId]];
            $respuesta = "Te reservé la hora de {$s->mascota} con {$s->esp_nombre} el {$legible}"
                . ($anterior !== null ? " (y cancelé la anterior del {$anterior})" : '') . '. '
                . "Para confirmarla, paga {$monto} con el botón de abajo dentro de {$min} minutos; si no, la hora se libera."
                . "\n\n" . AgenteIA::PREGUNTA_OTRA_HORA;
            $this->anotarEnChatDelTutor($telTutor, "[El tutor tocó {$legible}. Cita {$citaId} retenida esperando el pago.] {$respuesta}");

            return $respuesta;
        }
        $direccion = trim((string) (RespuestaDirecta::datos()['direccion'] ?? ''));
        $respuesta = "Listo, quedó agendada la hora de {$s->mascota} con {$s->esp_nombre} el {$legible}"
            . ($anterior !== null ? " y cancelé la anterior del {$anterior}" : '') . '. '
            . 'Te esperamos en ' . Marca::nombre() . ($direccion !== '' ? ", {$direccion}" : '') . ".\n\n" . AgenteIA::PREGUNTA_OTRA_HORA;
        $this->anotarEnChatDelTutor($telTutor, "[El tutor tocó {$legible}. Cita {$citaId} agendada.] {$respuesta}");

        return $respuesta;
    }

    /** La solicitud abierta más reciente de este tutor, con el nombre del especialista, o null. */
    public function abiertaDelTutor(string $telTutor): ?object
    {
        if (! self::disponible()) {
            return null;
        }

        return $this->abiertas()->where('s.tutor_telefono', $telTutor)
            ->orderByDesc('s.updated_at')->orderByDesc('s.id')->first();
    }

    /**
     * Lo que responde el tutor, al especialista, SIEMPRE con la lista numerada
     * detrás. En vivo (30-sep) el tutor contestó «no» al «¿podrá ser el
     * miércoles?», al Dr. Tigre le llegó un «no» pelado sin nada que hacer con
     * él, respondió «¿Qué hago?»… y eso se le transmitió al tutor como si fuera
     * para él. Con la lista, el especialista siempre tiene un paso siguiente.
     */
    public function enviarDeTutor(object $s, string $texto): bool
    {
        $para = self::numeroWhatsapp($s->esp_telefono ?? null);
        $limpio = self::sinContacto($texto);
        $propuestos = HorariosOfrecidos::propuestos(CarbonImmutable::now('America/Santiago'), $this->ocupadas($s->especialista_id));
        $intro = "Sobre la solicitud N° {$s->numero} ({$s->mascota}), el tutor responde:\n«{$limpio}»";
        if (! $para || ! $this->pedirAlEspecialista($para, (int) $s->numero, $intro, $propuestos)) {
            return false;
        }
        $this->anotar($s, 'tutor', $limpio, 'esperando_especialista', null,
            array_map(fn ($c) => $c->format('Y-m-d H:i'), $propuestos));

        return true;
    }

    /**
     * «¿Qué hago?», «no entiendo»: el especialista le habla al ASISTENTE, no al
     * tutor. Se le explica y se le repite la lista; al tutor no le llega nada.
     */
    public static function esDudaDelEspecialista(string $t): bool
    {
        return (bool) preg_match('/^\s*¿?\s*(qu[eé]\s+(hago|debo\s+hacer|tengo\s+que\s+hacer|hay\s+que\s+hacer|significa(\s+esto)?|es\s+esto)'
            . '|c[oó]mo\s+(funciona|es\s+esto|lo\s+hago|respondo|contesto)|no\s+entiendo|no\s+entend[ií]|\?+)\s*[?!.]*\s*$/iu', $t);
    }

    /**
     * Lo último que le salió AL especialista (del tutor o del asistente), si es
     * lo último del registro: o sea, se le escribió y todavía no contesta.
     * Es la prueba de que un «le transmití…» en pasado es cierto.
     *
     * @return array{de: string, texto: string, at: string}|null
     */
    public static function ultimoEnviado(object $s): ?array
    {
        $log = json_decode($s->mensajes ?: '[]', true) ?: [];
        $m = end($log);

        return $m && in_array($m['de'] ?? '', ['tutor', 'asistente'], true)
            ? ['de' => $m['de'], 'texto' => (string) $m['texto'], 'at' => (string) ($m['at'] ?? $s->updated_at)]
            : null;
    }

    /** Lo último que dijo el especialista en esta solicitud, o null. */
    public static function ultimaDelEspecialista(object $s): ?string
    {
        foreach (array_reverse(json_decode($s->mensajes ?: '[]', true) ?: []) as $m) {
            if (($m['de'] ?? '') === 'especialista') {
                return $m['texto'];
            }
        }

        return null;
    }

    /**
     * El tutor ESCRIBIÓ el horario en vez de tocarlo («el viernes a las 10»).
     * Si calza exactamente con UNA opción de la lista vigente, se agenda igual
     * que con el toque; si no, null y el mensaje sigue al asistente.
     */
    public function eleccionEscrita(string $telTutor, string $texto): ?string
    {
        $s = $this->abiertaDelTutor($telTutor);
        if (! $s || $s->estado !== 'esperando_tutor') {
            return null;
        }
        $vigentes = self::opcionesVigentes($s);
        if (! $vigentes) {
            return null;
        }
        // «2», «la segunda», «el último», «ninguno»: quien no encuentra el
        // botón escribe lo que ve. Solo el mensaje entero, nada alrededor.
        $n = self::posicionEscrita($texto, count($vigentes));
        if ($n !== null) {
            return $this->elegirOpcion($telTutor, "esp:{$s->id}:" . ($n === 0 ? 'no' : $n - 1));
        }
        $leido = HorariosOfrecidos::extraer($texto, CarbonImmutable::now('America/Santiago'))['opciones'];
        if (count($leido) !== 1) {
            return null;
        }
        $i = array_search($leido[0]->format('Y-m-d H:i'), $vigentes, true);

        return $i === false ? null : $this->elegirOpcion($telTutor, "esp:{$s->id}:{$i}");
    }

    /**
     * 1..$total si el mensaje entero es una posición de la lista, 0 si es
     * «ninguno», null si es otra cosa. Un número fuera de rango es null: que lo
     * vea el asistente, no se agenda «el 7» de una lista de 3.
     */
    public static function posicionEscrita(string $texto, int $total): ?int
    {
        $t = trim(preg_replace('/[^\p{L}\d\s]/u', ' ', self::normalizar($texto)));
        $t = preg_replace('/^(?:(?:el|la|opcion|numero|nro|n|quiero|prefiero)\s+)+/u', '', $t);
        $t = preg_replace('/\s+(?:(?:por\s+favor|porfa|gracias|opcion|hora|horario|nomas))+$/u', '', trim($t));
        if (preg_match('/^ningun[oa]?(\s+me\s+sirve)?$/u', $t)) {
            return 0;
        }
        $ordinales = ['primero' => 1, 'primera' => 1, 'primer' => 1, 'segundo' => 2, 'segunda' => 2, 'tercero' => 3,
            'tercera' => 3, 'tercer' => 3, 'cuarto' => 4, 'cuarta' => 4, 'quinto' => 5, 'quinta' => 5,
            'ultimo' => $total, 'ultima' => $total, 'uno' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5];
        $n = preg_match('/^\d{1,2}$/', $t) ? (int) $t : ($ordinales[$t] ?? null);

        return $n !== null && $n >= 1 && $n <= $total ? $n : null;
    }

    /**
     * «¿Y?», «¿qué dijo?», «no entiendo», «¿cómo?»: el tutor pregunta en qué va
     * la cosa. Se le contesta con el ESTADO REAL de la solicitud, sin modelo.
     * En vivo (30-sep), a «que dijo?» el asistente contestó a ciegas, y a
     * «¿Cómo que hace?» interpretó la pregunta del especialista inventándole
     * una intención. Devuelve true si lo atendió.
     */
    public function dudaDelTutor(string $telTutor, string $texto): bool
    {
        if (! preg_match('/^(y|y\s+(ahora|entonces|que)(\s+que)?|que|como|hola|alo|nada|novedades?|alguna\s+(novedad|respuesta)'
            . '|que\s+(dijo|paso|respondio|contesto|te\s+dijo|hago|tengo\s+que\s+hacer|significa(\s+eso)?)'
            . '|(ya\s+|aun\s+no\s+|todavia\s+no\s+)?(respondio|contesto)(\s+algo)?|sigo\s+esperando|no\s+entiendo|no\s+entendi'
            . '|como\s+que\s+\p{L}+|como\s+es\s+eso|en\s+que\s+va(\s+esto)?)$/u',
            trim(preg_replace('/[^\p{L}\s]/u', ' ', self::normalizar($texto))))
            && ! preg_match('/^\s*[?¿.]+\s*$/u', $texto)) {
            return false;
        }
        $s = $this->abiertaDelTutor($telTutor);
        if (! $s) {
            return false;
        }
        $dr = $s->esp_nombre;
        $opciones = $s->estado === 'esperando_tutor' ? self::opcionesVigentes($s) : [];

        if ($opciones) {
            $cs = array_map(fn ($o) => CarbonImmutable::parse($o, 'America/Santiago'), $opciones);
            [$cuerpo, $filas] = self::mensajeOpciones($s, $cs, "Te toca elegir: {$dr} ofreció estos horarios para {$s->mascota}:");

            return $this->envio->opciones($telTutor, $cuerpo, 'Ver horarios', $filas)
                || $this->envio->texto($telTutor, $cuerpo);
        }
        $pregunta = $s->estado === 'esperando_tutor' ? self::ultimaDelEspecialista($s) : null;
        $texto = $pregunta !== null
            ? "Te toca responder a ti. {$dr} escribió sobre la hora de {$s->mascota}: «{$pregunta}». "
                . 'Contéstame aquí mismo y se lo paso tal cual.'
            : "Todavía no responde {$dr}. Le escribí por la hora de {$s->mascota} " . self::hace($s->updated_at)
                . '. ' . self::PUEDE_ESTAR_ATENDIENDO . ' con los horarios para elegir: no tienes que hacer nada más.';
        $this->envio->texto($telTutor, $texto);
        $this->anotarEnChatDelTutor($telTutor, $texto);

        return true;
    }

    /** «hace 20 minutos», «a las 18:31», «el 29/09 a las 18:31». */
    public static function hace(string $utc): string
    {
        $c = CarbonImmutable::parse($utc, 'UTC')->setTimezone('America/Santiago');
        $ahora = CarbonImmutable::now('America/Santiago');
        $min = (int) $c->diffInMinutes($ahora, true);

        return match (true) {
            $min < 60 => $min <= 1 ? 'recién' : "hace {$min} minutos",
            $c->isSameDay($ahora) => 'hoy a las ' . $c->format('H:i'),
            default => 'el ' . $c->format('d/m') . ' a las ' . $c->format('H:i'),
        };
    }

    private static function normalizar(string $t): string
    {
        return strtr(mb_strtolower(trim($t)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    }

    /**
     * El especialista hizo una PREGUNTA («¿podrá ser el miércoles?») y el
     * tutor contesta corto («sí», «no», «dale»): se le reenvía tal cual, sin
     * modelo. En vivo (30-sep) ese «sí» pasó por el asistente, que lo leyó
     * como respuesta a otra cosa y nunca lo envió.
     */
    public function respuestaCorta(string $telTutor, string $texto): ?string
    {
        $s = $this->abiertaDelTutor($telTutor);
        if (! $s || $s->estado !== 'esperando_tutor' || self::opcionesVigentes($s)) {
            return null;
        }
        $pregunta = self::ultimaDelEspecialista($s);
        $corto = trim($texto);
        if ($pregunta === null || ! str_contains($pregunta, '?')
            || ! preg_match('/^(s[ií]|no|ok|dale|ya|bueno|perfecto|claro|de acuerdo|me sirve|no me sirve)[\s.!,]*(gracias)?[\s.!]*$/iu', $corto)) {
            return null;
        }
        if (! $this->enviarDeTutor($s, $corto)) {
            return 'No pude transmitírselo ' . self::a($s->esp_nombre) . '. Intenta de nuevo o llama a la clínica.';
        }
        $respuesta = 'Le respondí ' . self::a($s->esp_nombre) . " «{$corto}» a su pregunta («{$pregunta}»). "
            . self::PUEDE_ESTAR_ATENDIENDO . '.';
        $this->anotarEnChatDelTutor($telTutor, $respuesta);

        return $respuesta;
    }

    /** «al Dr. Tigre», «a la Dra. Rivas», «a Carla»: «le pregunté a Dr. Tigre» se leía mal. */
    public static function a(string $nombre): string
    {
        $n = trim($nombre);

        return match (true) {
            (bool) preg_match('/^dra\b/iu', $n) => "a la {$n}",
            (bool) preg_match('/^dr\b/iu', $n) => "al {$n}",
            default => "a {$n}",
        };
    }

    /**
     * Los horarios de la última lista ofrecida al tutor («Y-m-d H:i»), o []
     * si lo último del especialista fue texto libre.
     */
    public static function propuestosVigentes(object $s): array
    {
        foreach (array_reverse(json_decode($s->mensajes ?: '[]', true) ?: []) as $m) {
            if (isset($m['propuestos'])) {
                return $m['propuestos'];
            }
        }

        return [];
    }

    /** El mensaje que le pide al especialista elegir de la lista numerada. */
    private static function pedirNumeros(array $propuestos, int $numero, bool $conBotones = false): string
    {
        if (! $propuestos) {
            return "¿Qué horarios le puedes ofrecer? Escríbelos con día y hora, por ejemplo «jue 2 10:00, vie 3 15:30» (solicitud N° {$numero}).";
        }

        return "¿Cuáles de estos horarios le puedes ofrecer?\n" . HorariosOfrecidos::listaNumerada($propuestos) . "\n\n"
            . ($conBotones
                ? 'Toca «Elegir horario» y elige uno; después puedes agregar más. También puedes escribir los números (ej. «1, 5, 6»). '
                : 'Respóndeme con los números que te sirvan, uno o varios (ej. «1, 5, 6»), y al tutor le llegan para que elija. ')
            . "Si ninguno te sirve, escríbeme otros días y horas (ej. «lun 5 11:00»). Solicitud N° {$numero}.";
    }

    /**
     * Los días siguientes a la lista vigente, con botones. Lo ya elegido se
     * guarda por fecha y hora (no por posición), así que sobrevive al cambio.
     */
    private function masDias(object $s, string $telEsp, array $sel): void
    {
        $propuestos = self::propuestosVigentes($s);
        $desde = $propuestos ? CarbonImmutable::parse(end($propuestos), 'America/Santiago') : CarbonImmutable::now('America/Santiago');
        $nuevos = HorariosOfrecidos::propuestos($desde, $this->ocupadas($s->especialista_id));
        $this->anotar($s, 'asistente', '(más días)', $s->estado, null, array_map(fn ($c) => $c->format('Y-m-d H:i'), $nuevos));
        $elegidos = implode("\n", array_map(fn ($c) => '• ' . HorariosOfrecidos::legible(CarbonImmutable::parse($c, 'America/Santiago')), $sel));
        $this->pedirAlEspecialista($telEsp, (int) $s->numero,
            $sel ? "Elegidos hasta ahora:\n{$elegidos}\n\nMás días:" : 'Más días:', $nuevos);
    }

    /** Marca corta de una lista de horarios («Y-m-d H:i»): un botón de una lista vieja no vale por la nueva. */
    private static function marca(array $horarios): string
    {
        return substr(md5(implode('|', $horarios)), 0, 6);
    }

    /**
     * Pedirle al especialista que elija, CON BOTONES (decisión de producto, 06-oct:
     * «que se muestren como botones las opciones de horario siempre, para el
     * doctor y para el tutor»). Una lista de Meta tiene 10 filas y cada toque
     * elige una: van los primeros 9 propuestos más «Otro día u hora», y cada
     * toque se anota hasta que toque «Enviar al tutor» (opcionDelEspecialista).
     * El texto sigue trayendo la lista entera numerada: escribir «1, 5» sigue
     * sirviendo. Si Meta rechaza el interactivo, sale el texto.
     *
     * @param list<CarbonImmutable> $propuestos
     */
    private function pedirAlEspecialista(string $para, int $numero, string $intro, array $propuestos): bool
    {
        $cuerpo = trim($intro . "\n\n" . self::pedirNumeros($propuestos, $numero, true));
        if ($propuestos) {
            $v = self::marca(array_map(fn ($c) => $c->format('Y-m-d H:i'), $propuestos));
            $filas = [];
            // 8 horarios + «Más días» + «Otro día u hora»: el tope de Meta es 10 filas.
            foreach (array_slice($propuestos, 0, 8) as $i => $c) {
                $filas[] = ['id' => "hor:{$numero}:{$i}:{$v}", 'titulo' => ($i + 1) . ') ' . HorariosOfrecidos::corto($c)];
            }
            $filas[] = ['id' => "hor:{$numero}:dias:{$v}", 'titulo' => 'Más días'];
            $filas[] = ['id' => "hor:{$numero}:otro:{$v}", 'titulo' => 'Otro día u hora'];
            if ($this->envio->opciones($para, $cuerpo, 'Elegir horario', $filas)) {
                return true;
            }
        }

        return $this->envio->texto($para, $cuerpo);
    }

    /**
     * El especialista tocó algo de su lista («hor:<N° solicitud>:<qué>:<marca>»).
     * Cada horario tocado se anota; con «Enviar al tutor» se le ofrecen todos.
     * Devuelve lo que se le contesta, o '' si ya se le mandó lo que corresponde.
     */
    public function opcionDelEspecialista(string $telEsp, string $id): string
    {
        if (! preg_match('/^(hor|msg):(\d{1,4}):(\d{1,2}|otro|enviar|mas|limpiar|dias|no):([0-9a-f]{6})$/', $id, $r)
            || ! self::disponible()) {
            return 'No reconocí esa opción. Escríbeme los horarios que le puedes ofrecer.';
        }
        // Mismos índices que antes de agregar «msg:»: [1] N°, [2] qué, [3] marca.
        $tipo = $r[1];
        $m = [$r[0], $r[2], $r[3], $r[4]];
        $s = collect($this->esperandoA($telEsp))->first(fn ($x) => (int) $x->numero === (int) $m[1]);
        if (! $s) {
            return "La solicitud N° {$m[1]} ya no espera tu respuesta.";
        }

        // Su mensaje para el tutor: se envía solo si lo confirma.
        if ($tipo === 'msg') {
            $p = Cache::get(self::claveRecado($s));
            if (! $p || substr(md5($p['texto']), 0, 6) !== $m[3]) {
                return 'Ese mensaje ya no está pendiente. Si quieres que le diga algo al tutor, escríbemelo de nuevo.';
            }
            Cache::forget(self::claveRecado($s));
            if ($m[2] === 'enviar') {
                $this->transmitirAlTutor($s, $telEsp, $p['texto']);

                return '';
            }

            return 'Listo, no se lo envié. Si quieres ofrecerle horarios, toca «Elegir horario» en la lista o escríbemelos.';
        }

        $propuestos = self::propuestosVigentes($s);
        // La marca importa solo donde el toque apunta a una POSICIÓN de la lista.
        if (ctype_digit($m[2]) && $m[3] !== self::marca($propuestos)) {
            return 'Esa lista ya no es la vigente. Usa la última que te mandé.';
        }
        $clave = "intermediario:seleccion:{$s->id}";
        $sel = Cache::get($clave, []);
        $aCarbon = fn (array $l) => array_map(fn ($c) => CarbonImmutable::parse($c, 'America/Santiago'), $l);
        $legibles = fn (array $l) => implode("\n", array_map(fn ($c) => '• ' . HorariosOfrecidos::legible($c), $aCarbon($l)));
        $ahora = CarbonImmutable::now('America/Santiago');

        switch ($m[2]) {
            case 'dias':
                $this->masDias($s, $telEsp, $sel);

                return '';
            case 'no':
                return 'No reconocí esa opción.';
            case 'otro':
                return 'Escríbeme el día y la hora que le puedes ofrecer, por ejemplo «jue 9 16:00». Si son varios, sepáralos con coma.';
            case 'limpiar':
                Cache::forget($clave);
                $this->pedirAlEspecialista($telEsp, (int) $s->numero, 'Listo, empecemos de nuevo.', $aCarbon($propuestos));

                return '';
            case 'mas':
                $this->pedirAlEspecialista($telEsp, (int) $s->numero,
                    $sel ? "Elegidos hasta ahora:\n" . $legibles($sel) . "\n\nToca otro:" : 'Toca uno:', $aCarbon($propuestos));

                return '';
            case 'enviar':
                $futuros = array_values(array_filter($aCarbon($sel), fn ($c) => $c->gt($ahora)));
                if (! $futuros) {
                    return 'Todavía no elegiste ningún horario. Toca uno en la lista.';
                }
                Cache::forget($clave);
                $this->ofrecerAlTutor($s, $telEsp, "(elegidos con botones)\n" . $legibles($sel), $futuros,
                    $s->estado === 'esperando_tutor');

                return '';
        }

        $h = $propuestos[(int) $m[2]] ?? null;
        if ($h === null) {
            return 'Esa opción no está en la lista. Usa la última que te mandé.';
        }
        if (CarbonImmutable::parse($h, 'America/Santiago')->lte($ahora)) {
            return 'Ese horario ya pasó. Elige otro.';
        }
        $sel = array_values(array_unique([...$sel, $h]));
        sort($sel);
        $sel = array_slice($sel, 0, HorariosOfrecidos::MAX_OPCIONES);
        Cache::put($clave, $sel, now()->addDay());

        $v = $m[3];
        $botones = [
            ['id' => "hor:{$s->numero}:enviar:{$v}", 'titulo' => 'Enviar al tutor'],
            ['id' => "hor:{$s->numero}:mas:{$v}", 'titulo' => 'Agregar otro'],
            ['id' => "hor:{$s->numero}:limpiar:{$v}", 'titulo' => 'Borrar y empezar'],
        ];
        $cuerpo = 'Anotado' . (count($sel) > 1 ? 's' : '') . " para {$s->mascota}:\n" . $legibles($sel)
            . "\n\n¿Se los mando al tutor o agregas otro?";
        if (! $this->envio->opciones($telEsp, $cuerpo, 'Opciones', $botones)) {
            return $cuerpo . ' Si no ves los botones, escríbeme los números de todos los que le ofreces (ej. «1, 5»).';
        }

        return '';
    }

    /** Las horas que el especialista ya tiene tomadas desde hoy («Y-m-d H:i»): no se le proponen. */
    private function ocupadas(string $especialistaId): array
    {
        try {
            return DB::table('citas')->where('especialista_id', $especialistaId)
                ->whereIn('estado', ['Agendada', 'Confirmada'])
                ->where('fecha_hora', '>=', CarbonImmutable::now('America/Santiago')->format('Y-m-d 00:00:00'))
                ->pluck('fecha_hora')->map(fn ($f) => substr((string) $f, 0, 16))->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function opcionesVigentes(object $s): array
    {
        foreach (array_reverse(json_decode($s->mensajes ?: '[]', true) ?: []) as $m) {
            if (($m['de'] ?? '') === 'especialista') {
                return $m['opciones'] ?? [];
            }
        }

        return [];
    }

    /**
     * Deja en la conversación del asistente con el tutor lo que el sistema le
     * mandó por fuera (la respuesta del especialista, la hora agendada).
     *
     * Sin esto el modelo no lo veía: en la prueba del 30-sep el Dr. Tigre
     * preguntó «¿podrá ser el miércoles?», el tutor contestó «sí», y el
     * asistente lo leyó como respuesta a lo último que ÉL había dicho —el
     * martes— y se lo transmitió al revés. Se suma al último turno del
     * asistente y no como turno nuevo, para no romper la alternancia
     * usuario/asistente que exige la API.
     */
    private function anotarEnChatDelTutor(string $telTutor, string $texto): void
    {
        try {
            $fila = DB::table('chatbot_conversations')->where('telefono', $telTutor)
                ->orderByDesc('updated_at')->orderByDesc('id')->first();
            if (! $fila) {
                return;
            }
            $historial = json_decode($fila->messages ?? '[]', true) ?: [];
            $ultimo = array_key_last($historial);
            if ($ultimo === null || ($historial[$ultimo]['role'] ?? '') !== 'assistant') {
                return;
            }
            $historial[$ultimo]['content'] .= "\n\n[Mensaje que el sistema le envió después:] "
                . ChatbotController::anonimizar($texto);
            DB::table('chatbot_conversations')->where('id', $fila->id)
                ->update(['messages' => json_encode($historial, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $e) {
            Log::warning('intermediario: no se pudo anotar en el chat del tutor: ' . $e->getMessage());
        }
    }

    /**
     * La cita acordada. Como la del mostrador (`crearStaff`): nace «Agendada»,
     * sin monto congelado —el cobro lo coordina la clínica— y sin pasar por los
     * slots, porque este especialista no tiene horario en el sistema: la hora
     * la puso él.
     *
     * El tutor se busca por su número de WhatsApp. Si no existe se crea sin
     * correo real (`wa-<número>@sin-correo.local`, que Correo ya sabe no
     * usar): en este flujo no se pide correo, a propósito.
     */
    private function crearCita(object $s, CarbonImmutable $cuando): string
    {
        $nacional = AgenteIA::nacional($s->tutor_telefono);
        $ahoraUtc = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $servicio = DB::table('servicios')->where('nombre', $s->servicio)->orderBy('id')->first();
        $id = (string) Str::uuid();

        // El cobro se congela al reservar, igual que en el portal, y
        // `pago_modalidad_requerida` la mete en la ventana de pago: el barrido
        // de RecordatoriosCitas la suelta si no se paga a tiempo. Sin precio
        // cargado no hay monto que cobrar y queda como antes, coordinada por
        // la clínica.
        $esp = DB::table('especialistas')->where('id', $s->especialista_id)->first();
        $cobro = app(CobroService::class);
        $abono = ($servicio && $esp) ? $cobro->montoAbono($esp, $servicio, $cuando) : null;
        $total = $abono !== null ? $cobro->montoTotal($esp, $servicio, $cuando) : null;
        if ($abono === null && $servicio && ($servicio->modalidad_pago ?: 'abono') !== 'nada') {
            Log::warning("intermediario: {$servicio->nombre} con {$s->especialista_id} sin precio; la hora queda sin abono");
        }

        DB::transaction(function () use ($s, $cuando, $nacional, $ahoraUtc, $servicio, $id, $abono, $total) {
            $candidatos = DB::table('usuarios')->where('rol', 'dueño')->whereNotNull('telefono')
                ->orderBy('id')->get()
                ->filter(fn ($u) => $nacional !== null && AgenteIA::nacional($u->telefono) === $nacional)->values();
            // Con varias cuentas del mismo número, la que ya tiene a esa mascota.
            $usuario = $candidatos->first(fn ($u) => DB::table('mascotas')->where('usuario_id', $u->id)
                ->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim((string) $s->mascota))])->exists())
                ?? $candidatos->first();
            $correo = 'wa-' . ($nacional ?? preg_replace('/\D/', '', $s->tutor_telefono)) . '@sin-correo.local';
            // El correo es único: si ya se creó antes con otro formato de número, es esa.
            $usuario ??= DB::table('usuarios')->where('email', $correo)->first();
            if (! $usuario) {
                $uid = (string) Str::uuid();
                DB::table('usuarios')->insert([
                    'id' => $uid,
                    'email' => $correo,
                    'nombre' => $s->tutor_nombre ?: 'Tutor por WhatsApp',
                    'telefono' => '+' . preg_replace('/\D/', '', $s->tutor_telefono),
                    'rol' => 'dueño',
                    'password_hash' => password_hash((string) Str::uuid(), PASSWORD_BCRYPT),
                    'email_verificado' => 0,
                    'created_at' => $ahoraUtc,
                ]);
                $usuario = DB::table('usuarios')->where('id', $uid)->first();
            }

            $mascota = DB::table('mascotas')->where('usuario_id', $usuario->id)
                ->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim((string) $s->mascota))])->orderBy('id')->first();
            if (! $mascota) {
                $mid = (string) Str::uuid();
                DB::table('mascotas')->insert(['id' => $mid, 'usuario_id' => $usuario->id,
                    'nombre' => trim((string) $s->mascota) ?: 'Sin nombre', 'created_at' => $ahoraUtc]);
                $mascota = DB::table('mascotas')->where('id', $mid)->first();
            }

            DB::table('citas')->insert([
                'id' => $id,
                'mascota_id' => $mascota->id,
                'usuario_dueno_id' => $usuario->id,
                'fecha_hora' => $cuando->format('Y-m-d H:i:s'),
                'servicio' => $servicio->nombre ?? $s->servicio,
                'servicio_id' => $servicio->id ?? null,
                'especialista_id' => $s->especialista_id,
                'duracion_min' => $servicio->duracion_min ?? 30,
                'notas_pre_cita' => $s->motivo,
                'respuestas' => json_encode(['Canal' => 'WhatsApp (acordada con el especialista)',
                    'WhatsApp' => '+' . preg_replace('/\D/', '', $s->tutor_telefono),
                    'Solicitud' => "N° {$s->numero}"], JSON_UNESCAPED_UNICODE),
                'estado' => 'Agendada',
                'payment_status' => $abono ? 'pending' : null,
                'monto_abono_clp' => $abono,
                'monto_total_clp' => $total,
                'pago_modalidad_requerida' => $abono ? ($servicio->modalidad_pago ?: 'abono') : null,
                'created_at' => $ahoraUtc,
            ]);
        });

        // Google Calendar y correos, igual que cualquier reserva. Fuera de la
        // transacción y sin lanzar: la cita ya existe pase lo que pase.
        app(CitasController::class)->efectosDeReserva($id);
        Log::info("intermediario: solicitud N° {$s->numero} agendada como cita {$id}");
        $this->engancharOrden($s, $id, $servicio);

        return $id;
    }

    /** Lo que hay que pagar para confirmar la cita, o null si no cobra en línea. */
    private function cobroDe(string $citaId): ?array
    {
        $monto = DB::table('citas')->where('id', $citaId)->value('monto_abono_clp');
        if (! $monto) {
            return null;
        }
        $link = app(LinkDePago::class)->generar($citaId);
        if (isset($link['error'])) {
            // Sin enlace (p. ej. reemplaza una hora ya pagada que resuelve una
            // persona) no puede quedar esperando un pago: expiraría sin que el
            // tutor hubiera tenido cómo pagar, y el portal le ofrecería pagar
            // —dos veces— lo que la clínica resuelve a mano. Se le quita el
            // cobro en línea entero, no solo la modalidad: desde el 12-oct
            // toda hora con abono pendiente vence a los 15 minutos
            // (LinkDePago), y es justo lo que el tutor lee aquí abajo: «quedó
            // agendada, te esperamos».
            DB::table('citas')->where('id', $citaId)->update([
                'pago_modalidad_requerida' => null, 'monto_abono_clp' => null,
                'monto_total_clp' => null, 'payment_status' => null,
            ]);
            Log::info("intermediario: cita {$citaId} sin enlace de pago ({$link['error']}); queda para la clínica");

            return null;
        }

        return $link;
    }

    /**
     * Si la solicitud era para MOVER una hora, cancela la vieja —por la misma
     * puerta que usa el dueño en el portal, con su correo y su evento de
     * Google— y devuelve cuándo era. Una hora con abono pagado no se toca:
     * qué pasa con esa plata lo decide una persona.
     *
     * Mientras nadie decida, la nueva cita NO puede cobrarse en línea: sin
     * esto, el tutor podía terminar pagando dos veces la misma atención (la
     * vieja, pagada, y el abono de la nueva) — incidente del 6-oct en el lab,
     * Loki ($45.000 + $22.500 por una sola ecografía). `marcarReemplazoPendiente`
     * deja la constancia que lee `LinkDePago::generar()` para bloquear ese
     * segundo cobro, y queda visible en `respuestas` de la cita nueva, que el
     * panel ya muestra sin necesitar columna ni vista nueva.
     */
    private function soltarReemplazada(object $s, ?string $nuevaCitaId = null): ?string
    {
        $id = collect(json_decode($s->mensajes ?? '[]', true) ?: [])->first(fn ($m) => ! empty($m['reemplaza']))['reemplaza'] ?? null;
        $c = $id ? DB::table('citas')->where('id', $id)->whereIn('estado', ['Agendada', 'Confirmada'])->first() : null;
        if (! $c) {
            return null;
        }
        if (($c->payment_status ?? '') === 'approved') {
            Log::warning("intermediario: la solicitud N° {$s->numero} reemplaza la cita {$c->id}, pagada: no se cancela sola");
            if ($nuevaCitaId) {
                self::marcarReemplazoPendiente($nuevaCitaId, $c->id);
            }

            return null;
        }
        $dueno = DB::table('usuarios')->where('id', $c->usuario_dueno_id)->first();
        try {
            $peticion = \Illuminate\Http\Request::create("/citas/{$c->id}", 'PATCH', ['estado' => 'Cancelada']);
            $peticion->attributes->set('usuario', $dueno);
            $r = app()->call([app(CitasController::class), 'cambiarEstado'], ['request' => $peticion, 'citaId' => $c->id]);
            if ($r instanceof \Symfony\Component\HttpFoundation\Response && $r->getStatusCode() >= 300) {
                Log::warning("intermediario: no se pudo cancelar la cita reemplazada {$c->id}: " . $r->getContent());

                return null;
            }
        } catch (\Throwable $e) {
            Log::warning("intermediario: no se pudo cancelar la cita reemplazada {$c->id}: " . $e->getMessage());

            return null;
        }

        return HorariosOfrecidos::legible(CarbonImmutable::parse($c->fecha_hora, 'America/Santiago'));
    }

    /**
     * Deja escrito en `respuestas` de la cita NUEVA que reemplaza a una vieja
     * ya pagada que no se pudo cancelar sola. `LinkDePago::generar()` lee esta
     * misma clave para negarse a cobrar la nueva mientras la plata de la vieja
     * siga sin resolverse — ese es el candado, no solo una nota para mirar.
     */
    private static function marcarReemplazoPendiente(string $nuevaCitaId, string $citaPagadaId): void
    {
        $nueva = DB::table('citas')->where('id', $nuevaCitaId)->first();
        if (! $nueva) {
            return;
        }
        $respuestas = $nueva->respuestas ? (json_decode($nueva->respuestas, true) ?: []) : [];
        $respuestas['Reemplaza cita pagada sin resolver'] = $citaPagadaId;
        DB::table('citas')->where('id', $nuevaCitaId)
            ->update(['respuestas' => json_encode($respuestas, JSON_UNESCAPED_UNICODE)]);
    }

    /** Donde esperan las órdenes médicas de las solicitudes hasta que hay cita. */
    public static function dirOrdenes(): string
    {
        return rtrim(config('emr.documentos_dir'), '/') . '/whatsapp-solicitudes';
    }

    /**
     * La orden que mandó el tutor, a la ficha de la mascota y a la cita recién
     * creada, por el mismo camino que una orden del portal (OrdenExamen). Sin
     * lanzar: la cita ya existe, y una orden que no se pudo enganchar se ve
     * porque la cita queda sin orden en el panel.
     */
    private function engancharOrden(object $s, string $citaId, ?object $servicio): void
    {
        $mensajes = json_decode($s->mensajes ?? '[]', true) ?: [];
        $orden = collect($mensajes)->first(fn ($m) => is_array($m['orden'] ?? null))['orden'] ?? null;
        $ruta = $orden ? self::dirOrdenes() . '/' . basename((string) $orden['archivo']) : null;
        if (! $orden || ! $servicio || ! is_file($ruta)) {
            return;
        }
        try {
            $cita = DB::table('citas')->where('id', $citaId)->first();
            app(OrdenExamen::class)->guardar($cita, $servicio, (string) $orden['ext'], (string) $orden['nombre'],
                function (string $dir, string $nombre) use ($ruta) {
                    if (! rename($ruta, $dir . '/' . $nombre)) {
                        throw new \RuntimeException('no se pudo mover el archivo');
                    }
                });
        } catch (\Throwable $e) {
            Log::warning("intermediario: no se pudo adjuntar la orden de la solicitud N° {$s->numero}: " . $e->getMessage());
        }
    }

    /**
     * Oculta teléfonos, correos y enlaces. Un número de 8 o más dígitos (con
     * espacios, puntos o guiones en medio) se considera teléfono; una hora
     * («10:30») o una fecha corta no llegan a eso.
     */
    public static function sinContacto(string $t): string
    {
        $t = preg_replace('~https?://\S+|wa\.me/\S+|www\.\S+~iu', '[enlace oculto]', $t);
        $t = preg_replace('/[\w.+-]+@[\w-]+(\.[\w-]+)+/u', '[correo oculto]', $t);
        $t = preg_replace_callback('/\+?\d[\d\s.-]{6,}\d/u',
            fn ($m) => preg_match_all('/\d/', $m[0]) >= 8 ? '[número oculto]' : $m[0], $t);

        return trim(mb_substr((string) $t, 0, 900));
    }

    // ── Internas ──────────────────────────────────────────────────────────

    /** Las que esperan respuesta de ESTE número de especialista, más antigua primero. */
    private function esperandoA(string $telefono): array
    {
        $propio = AgenteIA::nacional($telefono);
        if ($propio === null || ! self::disponible()) {
            return [];
        }

        // También la que ya respondió, mientras el tutor no haya contestado: así
        // puede corregir (en vivo, 06-oct, el Dr. Tigre se equivocó al elegir y
        // su mensaje siguiente lo atendía el bot común, como a un cliente). Si
        // tutor y especialista son el mismo número (pruebas), no: ahí ese número
        // tiene que poder hablar como tutor.
        return $this->abiertas()->whereIn('s.estado', ['esperando_especialista', 'esperando_tutor'])
            ->orderBy('s.created_at')->orderBy('s.id')->get()
            ->filter(fn ($s) => AgenteIA::nacional($s->esp_telefono) === $propio
                && ($s->estado === 'esperando_especialista' || self::puedeCorregir($s)))
            ->values()->all();
    }

    /** Ya respondió y el tutor todavía no contestó nada (ni eligió). */
    private static function puedeCorregir(object $s): bool
    {
        $mensajes = json_decode($s->mensajes ?: '[]', true) ?: [];
        $ultimo = end($mensajes);

        return ($ultimo['de'] ?? '') === 'especialista'
            && AgenteIA::nacional($s->tutor_telefono) !== AgenteIA::nacional($s->esp_telefono);
    }

    private function abiertas()
    {
        return DB::table('solicitudes_especialista as s')
            ->join('especialistas as e', 'e.id', '=', 's.especialista_id')
            ->whereNotIn('s.estado', ['cerrada', 'agendada'])
            ->where('s.updated_at', '>', CarbonImmutable::now('UTC')->subDays(self::DIAS_VIGENCIA)->format('Y-m-d H:i:s'))
            ->select('s.*', 'e.nombre as esp_nombre', 'e.telefono as esp_telefono');
    }

    private function anotar(object $s, string $de, string $texto, string $estado, ?array $opciones = null,
                            ?array $propuestos = null): void
    {
        $mensajes = json_decode($s->mensajes ?: '[]', true) ?: [];
        // Las listas viven en el mismo historial de la solicitud, sin columna
        // nueva: la última entrada que trae `opciones` (al tutor) o
        // `propuestos` (al especialista) es la vigente.
        $mensajes[] = ['de' => $de, 'texto' => $texto, 'at' => self::ahora()]
            + ($opciones !== null ? ['opciones' => $opciones] : [])
            + ($propuestos !== null ? ['propuestos' => $propuestos] : []);
        DB::table('solicitudes_especialista')->where('id', $s->id)->update([
            'mensajes' => json_encode(array_slice($mensajes, -30), JSON_UNESCAPED_UNICODE),
            'estado' => $estado,
            'updated_at' => self::ahora(),
        ]);
    }

    private static function numeroWhatsapp(?string $tel): ?string
    {
        $n = AgenteIA::nacional($tel);

        return $n !== null ? '56' . $n : null;
    }

    private static function ahora(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
    }
}
