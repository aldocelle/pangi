<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Lo que se contesta SIN llamar al modelo.
 *
 * Puerto de `backend/app/services/respuesta_directa.py`.
 *
 * ACÁ ESTA CAPA NO ES UNA OPTIMIZACIÓN: ES EL CHATBOT
 *
 * En Python nació para ahorrar llamadas al modelo —buena parte de lo que se le
 * pregunta a una veterinaria es cerrado y está en la base—. Pero la clínica
 * decidió el 07-sep-2026 no usar la IA por ahora (`ASISTENTE_IA_ACTIVO=false`,
 * §4), así que lo que esto no resuelve se deriva a WhatsApp y nada más. O sea
 * que es TODO lo que el chat sabe contestar, no un atajo antes del que sabe.
 *
 * Por eso se porta ahora y no en la Fase 5 con el resto del agente: sin esto,
 * el día del cambio el chat pasaría de responder precios a devolver 503 a
 * todo. Se comprobó contra los dos backends el 07-sep — producción daba
 * «Consulta Dermatológica: Lunes a viernes: $45.000 · Sábado: $55.000» y el
 * laboratorio un 503.
 *
 * LA REGLA QUE LA HACE SEGURA: ANTE LA DUDA, NO CONTESTA
 *
 * Una respuesta determinista equivocada es peor que derivar, porque sale con
 * el tono de un dato y nadie la revisa. Todo lo que no encaje con certeza
 * devuelve `null`. En particular:
 *
 * · Si el mensaje huele a agendar, cancelar, o a datos de una cuenta, se
 *   deriva SIN MIRAR NADA MÁS — aunque además pregunte el teléfono. «Quiero
 *   cambiar mi hora, ¿cuál es su número?» no se responde acá.
 * · Un precio solo se da si el mensaje nombra una consulta que existe en el
 *   catálogo. Sin eso no se adivina cuál es «la consulta normal».
 *
 * LO QUE SABE PORQUE LA OFICINA SE LO ESCRIBIÓ (sep-2026)
 *
 * Todo lo que esto no contesta termina en un WhatsApp que alguien de la
 * oficina responde a mano. Así que cada dato de acá es carga que sale de la
 * oficina. Viven en Ajustes → Asistente (`asistente.datos`) y los escribe la
 * clínica; ninguno se deduce:
 *
 * **La dirección.** Antes no existía como campo —estaba incrustada en
 * `wording.global` y sacarla con una expresión regular era adivinar—. Ahora es
 * un dato propio. Vacía, se deriva.
 *
 * **El horario.** Las agendas son POR ESPECIALISTA y resumirlas sería fabricar
 * un dato, así que es un TEXTO que escribe la oficina, y nace vacío.
 *
 * **Preguntas frecuentes.** Palabras clave → respuesta literal. Si un mensaje
 * calza con dos, no se contesta ninguna.
 *
 * Nada de esto le gana a ESCALAR_SIEMPRE.
 */
class RespuestaDirecta
{
    /**
     * Cualquiera de estas manda la conversación a WhatsApp, sin excepción y
     * antes de mirar el resto: implican una acción sobre una cuenta o una
     * agenda, y ahí hace falta identidad y contexto de la charla.
     */
    private const ESCALAR_SIEMPRE = [
        'agendar', 'agenda', 'reservar', 'reserva', 'pedir hora', 'tomar hora',
        'cancelar', 'anular', 'cambiar', 'mover', 'reagendar', 'posponer',
        'mi cita', 'mis citas', 'mi hora', 'mis horas',
        'mi cuenta', 'mi ficha', 'mi historial',
        'pagar', 'pague', 'abono', 'boleta', 'deuda', 'debo', 'cobro',
    ];

    /**
     * Lo médico va SIEMPRE a una persona, y va antes que todo: «¿cuánto cuesta
     * la consulta? mi perro está vomitando» no se contesta con un precio.
     * Subcadenas, sin tildes («vomit» cubre vomita, vomitando, vómitos).
     */
    private const MEDICO = [
        'vomit', 'diarrea', 'fiebre', 'no come', 'no quiere comer', 'cojea', 'cojera', 'herid', 'convuls',
        'urgen', 'emergencia', 'envenen', 'intoxic', 'atropell', 'respira', 'decaid', 'dolor', 'hinchad',
        'sangra', 'que le doy', 'puedo darle', 'le puedo dar', 'medicamento', 'remedio', 'dosis',
        'paracetamol', 'resultado', 'pulga', 'garrapata',
    ];

    /**
     * «Mi perro», «mi gato»: hablan de UN animal, y lo que se diga de él lo dice
     * una persona. Pero no tapan lo que es igual para todos —cuánto vale una
     * consulta, si se hace una ecografía—: hasta sep-2026 estaban en
     * ESCALAR_SIEMPRE y «¿cuánto cuesta la consulta para mi perro?» iba a
     * WhatsApp, que es de lo más común que se pregunta.
     */
    private const PROPIO = ['mi mascota', 'mis mascotas', 'mi perro', 'mi perra', 'mi gato', 'mi gata',
        'mi perrit', 'mi gatit'];

    /** «¿Hacen ecografías?», «¿tienen peluquería?»: con una consulta nombrada, es de catálogo. */
    private const SERVICIO_PREGUNTA = '/\b(hacen|tienen|realizan|ofrecen|hay)\b/';

    /** Mensajes que son SOLO saludo o pedido de ayuda: se contesta con lo que se puede hacer. */
    private const SALUDO = ['hola', 'holi', 'hello', 'alo', 'buenas', 'buenos', 'buen', 'dia', 'dias',
        'tardes', 'tarde', 'noches', 'noche', 'ayuda', 'ayudame', 'ayudar', 'ayudarme', 'necesito',
        'info', 'informacion', 'menu', 'opciones', 'como', 'estan', 'esta', 'que', 'tal', 'por', 'favor',
        'porfa', 'me', 'puedes', 'pueden', 'hay', 'alguien'];

    private const CORTESIA = ['gracias', 'muchas', 'mil', 'ok', 'oka', 'okey', 'okay', 'vale', 'listo',
        'perfecto', 'genial', 'bueno', 'dale', 'chao', 'adios', 'saludos', 'excelente', 'super', 'entendido',
        'muy', 'amable'];

    private const TELEFONO = ['telefono', 'fono', 'numero', 'whatsapp', 'wsp', 'contacto', 'llamar'];

    /**
     * Ojo con «atienden» a secas: estuvo un momento en esta lista y hacía que
     * «¿atienden los domingos?» devolviera el catálogo de servicios — una
     * respuesta con cara de dato, sobre otra pregunta. Lo cazó su propia
     * prueba. Las claves tienen que llevar el «qué» dentro; el «cuándo» y el
     * «dónde» no se contestan acá (ver arriba).
     */
    private const CATALOGO = ['que servicios', 'que consultas', 'que atienden', 'que hacen',
        'servicios tienen', 'consultas tienen', 'que ofrecen'];

    /**
     * Cuándo y dónde. Se contestan solo con el dato que escribió la oficina
     * (`asistente.datos`); sin él se deriva aunque la frase traiga además una
     * palabra de las de arriba.
     */
    private const TIEMPO = [
        'hora abren', 'horario', 'que dias', 'abren', 'cierran', 'abierto',
        'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo',
    ];

    private const LUGAR = ['donde', 'direccion', 'ubicad', 'como llego', 'llegar'];

    /**
     * Lo que la oficina le escribe al asistente (Ajustes → Asistente), mismo
     * contenido que `ASISTENTE_DEFAULTS["datos"]` en Python. Vive acá y no en
     * el controlador porque lo usan los dos: el controlador para servirlo y
     * editarlo, este servicio para contestar. Dos copias es cómo nació el bug
     * del merge de `wording`.
     *
     * - `direccion`: sale de los dos textos que ya publica la clínica (el
     *   config del sitio y el encabezado de la reserva, que agrega «local 2»).
     * - `horario`: VACÍO a propósito. Las agendas son por especialista y nadie
     *   escribió uno de la clínica; mientras esté vacío, se deriva.
     * - `preguntas`: `[['claves' => 'a, b', 'respuesta' => '...']]`.
     */
    public const DATOS_DEFAULT = [
        'direccion' => 'Av. Principal 1234, local 5, Puente Alto',
        'horario' => '',
        'preguntas' => [],
    ];

    /**
     * `asistente.datos` guardado, mezclado CAMPO A CAMPO con los defaults: una
     * fila guardada antes de que existiera `datos` no lo trae.
     */
    public static function datos(): array
    {
        $fila = DB::table('ajustes')->where('id', 1)->first();
        $data = $fila && $fila->data ? (json_decode($fila->data, true) ?: []) : [];

        return array_merge(self::DATOS_DEFAULT, $data['asistente']['datos'] ?? []);
    }

    private const PRECIO = ['precio', 'valor', 'cuanto', 'cuesta', 'sale', 'tarifa', 'cobran'];

    /**
     * Minúsculas y sin tildes: quien escribe por chat no acentúa.
     *
     * Con un mapa explícito y no con `Normalizer` ni `iconv(...ASCII//TRANSLIT)`:
     * el primero exige la extensión `intl`, que en un hosting compartido puede
     * no estar, y el segundo depende del locale del proceso — en un servidor
     * con locale C devuelve «?» por cada acentuada y el texto queda ilegible.
     * Un fallo así no rompe nada visible: solo hace que las claves dejen de
     * calzar y el chat derive todo a WhatsApp sin que nadie sepa por qué.
     */
    private static function norm(?string $s): string
    {
        $s = mb_strtolower($s ?? '', 'UTF-8');

        return strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);
    }

    /**
     * La frase de ESCALAR_SIEMPRE que manda el mensaje a una persona, o `null`.
     * Pública para que el modal «Enseñarle» pueda decir CUÁL fue.
     */
    public static function escalaPor(string $mensaje): ?string
    {
        $t = self::norm($mensaje);
        foreach ([...self::ESCALAR_SIEMPRE, ...self::MEDICO, ...self::PROPIO] as $k) {
            if (str_contains($t, $k)) {
                return $k;
            }
        }

        return null;
    }

    /** Índices de las preguntas frecuentes (con respuesta) cuyas claves aparecen en el mensaje. */
    public static function calzan(string $mensaje, array $preguntas): array
    {
        $t = self::norm($mensaje);
        $indices = [];
        foreach (array_values($preguntas) as $i => $p) {
            if (trim((string) ($p['respuesta'] ?? '')) === '') {
                continue;
            }
            foreach (explode(',', (string) ($p['claves'] ?? '')) as $c) {
                $c = trim(self::norm($c));
                if ($c !== '' && str_contains($t, $c)) {
                    $indices[] = $i;
                    break;
                }
            }
        }

        return $indices;
    }

    /**
     * `[campo, mensaje]` si la pregunta no se puede guardar, o `null`. Una sola
     * copia de la regla para el guardado y para el modal: una clave de menos
     * de 4 letras («la») calza con casi todo, y una respuesta vacía no responde.
     */
    public static function errorPregunta(array $p): ?array
    {
        $partes = array_values(array_filter(array_map('trim', explode(',', (string) ($p['claves'] ?? ''))), fn ($x) => $x !== ''));
        if (! $partes) {
            return ['claves', 'Escribe al menos una palabra clave.'];
        }
        $cortas = array_values(array_filter($partes, fn ($x) => mb_strlen($x) < 4));
        if ($cortas) {
            return ['claves', 'Las palabras clave deben tener al menos 4 letras (revisa: ' . implode(', ', $cortas) . ').'];
        }
        if (trim((string) ($p['respuesta'] ?? '')) === '') {
            return ['respuesta', 'La respuesta no puede quedar vacía.'];
        }

        return null;
    }

    private static function pide(string $texto, array $claves): bool
    {
        foreach ($claves as $k) {
            if (str_contains($texto, $k)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Devuelve `['texto' => ..., 'motivo' => ...]` si es de las que se saben
     * con certeza, o `null`.
     *
     * `null` NO es un fallo: es el camino normal para todo lo que merece una
     * persona. Quien llame a esto tiene que tratarlo así. El `motivo` va al
     * log, nunca al cliente.
     */
    public function resolver(string $mensaje, ?array $datos = null): ?array
    {
        $t = self::norm($mensaje);
        if (trim($t) === '') {
            return null;
        }

        // Primero y sin excepciones: lo que toca una cuenta o una agenda, y lo
        // médico. «Mi perro» no entra acá: ver PROPIO.
        $escala = self::escalaPor($mensaje);
        if ($escala !== null && ! in_array($escala, self::PROPIO, true)) {
            return null;
        }
        $propio = $escala !== null;

        // `$datos` por parámetro: el modal «Enseñarle» prueba una pregunta que
        // todavía no está guardada (ProbarPregunta). Sin él, lo guardado.
        $datos ??= self::datos();

        // ── Preguntas frecuentes de la oficina ───────────────────────────
        // Antes que cuándo/dónde, para que la oficina pueda contestar «¿dónde
        // se estaciona?» con su texto y no con la dirección. Si calza con dos,
        // no se contesta ninguna. No con «mi perro»: la respuesta es genérica
        // y la pregunta es sobre un animal.
        if (! $propio) {
            $calzan = self::calzan($mensaje, $datos['preguntas'] ?? []);
            if (count($calzan) === 1) {
                return ['texto' => trim((string) $datos['preguntas'][$calzan[0]]['respuesta']), 'motivo' => 'pregunta_frecuente'];
            }
            if (count($calzan) > 1) {
                return null;
            }
        }

        // §9.14: el orden termina en la clave primaria.
        $activos = DB::table('servicios')
            ->where('activo', true)
            ->orderBy('orden')->orderBy('nombre')->orderBy('id')
            ->get();

        // ── Precio, y «¿hacen X?» ────────────────────────────────────────
        // Antes que cuándo/dónde: «¿cuánto sale la general el sábado?» es de
        // valor, y el valor del sábado está en la respuesta. Se contesta solo
        // si el mensaje no dice nada que el catálogo no explique
        // (CatalogoNombres::sobrante): «¿cuánto cuesta esterilizar?» no tiene
        // precio cargado, y darle la lista sería contestar otra cosa.
        $pidePrecio = self::pide($t, self::PRECIO);
        $pideServicio = (bool) preg_match(self::SERVICIO_PREGUNTA, $t);
        if ($pidePrecio || $pideServicio) {
            $nombrados = CatalogoNombres::nombrados($mensaje, $activos);
            $limpio = ! CatalogoNombres::sobrante($mensaje, $nombrados);

            if ($nombrados && $limpio) {
                $lineas = array_values(array_filter(array_map(
                    fn ($s) => ($c = self::cuerpoPrecio($s)) === null ? null : "{$s->nombre}: {$c}.", $nombrados)));
                $acciones = array_map(fn ($s) => [
                    'texto' => 'Reservar ' . $s->nombre,
                    'ruta' => '/reservar?' . http_build_query(['servicio' => $s->id]),
                ], $nombrados);

                if (! $pidePrecio) {
                    // Una sola: «Sí, tenemos Ecografía: $45.000…», sin repetir el nombre.
                    $uno = count($nombrados) === 1 ? self::cuerpoPrecio($nombrados[0]) : null;
                    $nombres = implode(', ', array_map(fn ($s) => $s->nombre, $nombrados));
                    $texto = count($nombrados) === 1
                        ? "Sí, tenemos {$nombres}" . ($uno !== null ? ": {$uno}." : '.')
                        : "Sí, tenemos {$nombres}." . ($lineas ? "\n" . implode("\n", $lineas) : '');

                    return ['texto' => $texto,
                        'motivo' => 'servicio:' . $nombrados[0]->nombre, 'acciones' => $acciones];
                }
                // Nombra consultas pero ninguna tiene valor cargado: inventar
                // un número acá es lo peor que puede hacer esta capa.
                if (! $lineas) {
                    return null;
                }

                return ['texto' => implode("\n", $lineas),
                    'motivo' => 'precio:' . (count($nombrados) === 1 ? $nombrados[0]->nombre : 'varios'),
                    'acciones' => $acciones];
            }

            if ($pidePrecio && ! $nombrados && $limpio) {
                return self::listaDePrecios($activos);
            }
            if ($pidePrecio) {
                return null;
            }
        }

        if ($propio) {
            return null;
        }

        // ── Cuándo y dónde, solo con el dato escrito ─────────────────────
        $pideLugar = self::pide($t, self::LUGAR);
        $pideTiempo = self::pide($t, self::TIEMPO);
        if ($pideLugar || $pideTiempo) {
            $direccion = trim((string) ($datos['direccion'] ?? ''));
            $horario = trim((string) ($datos['horario'] ?? ''));

            // Las dos o ninguna: media respuesta hace creer que la otra no se sabe.
            if (($pideLugar && $direccion === '') || ($pideTiempo && $horario === '')) {
                return null;
            }
            $partes = [];
            if ($pideLugar) {
                $partes[] = "Estamos en {$direccion}.";
            }
            if ($pideTiempo) {
                $partes[] = str_ends_with($horario, '.') ? $horario : $horario . '.';
            }
            $motivo = $pideLugar && $pideTiempo ? 'lugar_horario' : ($pideLugar ? 'lugar' : 'horario');

            return ['texto' => implode(' ', $partes), 'motivo' => $motivo];
        }

        // ── Teléfono ─────────────────────────────────────────────────────
        // No si la persona está dando SU número: «mi fono es 9…» recibía el de
        // la clínica, que es contestar otra cosa con cara de dato (sep-2026).
        $daSuNumero = preg_match('/\d[\d\s.-]{6,}\d/', $mensaje)
            || preg_match('/\bmis? (fono|numero|telefono|celular|cel|whatsapp|wsp)\b/', $t);
        if (self::pide($t, self::TELEFONO) && ! $daSuNumero) {
            return [
                'texto' => 'Puedes escribirnos o llamarnos al '
                    . config('emr.clinica_telefono')
                    . '. Por ahí coordinamos horas y resolvemos dudas.',
                'motivo' => 'telefono',
            ];
        }

        // ── Qué se atiende ───────────────────────────────────────────────
        if (self::pide($t, self::CATALOGO)) {
            // Cada consulta por su nombre, también las que comparten `grupo`.
            // Hasta sep-2026 se nombraban una vez («Dermatología»), copiando la
            // tarjeta del paso 1 de la reserva; pero allá el paso 2 la divide y
            // en el chat no hay paso 2, así que Consulta y Control no aparecían
            // en ninguna parte de la respuesta. La clínica pidió verlas
            // separadas, y así ya las mostraban la lista de valores y los
            // botones de reservar.
            $nombres = $activos->pluck('nombre')->all();
            if (! $nombres) {
                return null;
            }

            return ['texto' => 'Atendemos: ' . implode(', ', $nombres) . '.', 'motivo' => 'catalogo'];
        }

        // ── Solo un saludo, o solo un gracias ────────────────────────────
        // TODAS las palabras tienen que ser de saludo: «hola, mi perro vomita»
        // no es un saludo (y además ya derivó arriba).
        $palabras = preg_split('/\s+/', trim(CatalogoNombres::norm($mensaje)));
        if ($palabras !== [''] && ! array_diff($palabras, self::SALUDO)) {
            return [
                'texto' => '¡Hola! Soy el asistente de ' . Marca::nombre() . '. Puedo ayudarte a reservar una hora, '
                    . 'contarte los valores y qué atendemos, o decirte dónde estamos. ¿Qué necesitas?',
                'motivo' => 'saludo',
                'acciones' => [['texto' => 'Reservar una hora', 'ruta' => '/reservar']],
            ];
        }
        if ($palabras !== [''] && ! array_diff($palabras, self::CORTESIA)) {
            return ['texto' => '¡De nada! Si necesitas algo más, aquí estoy.', 'motivo' => 'cortesia'];
        }

        return null;
    }

    /** La lista de valores de todas las consultas activas, o `null` si ninguna tiene uno cargado. */
    public function valores(): ?array
    {
        return self::listaDePrecios(DB::table('servicios')
            ->where('activo', true)
            ->orderBy('orden')->orderBy('nombre')->orderBy('id')
            ->get());
    }

    /** Lo mismo que cuerpoPrecio, para el asistente con IA: una sola redacción del valor. */
    public static function valorDe(object $s): ?string
    {
        return self::cuerpoPrecio($s);
    }

    /** El valor de una consulta como se lo diría la recepción, o `null` si no está cargado. */
    private static function cuerpoPrecio(object $s): ?string
    {
        if (! empty($s->precio_detalle)) {
            // Ya viene redactado y es lo que la clínica quiso decir.
            return rtrim(trim($s->precio_detalle), '.');
        }
        if ($s->precio_clp && $s->precio_sabado_clp) {
            return self::clp($s->precio_clp) . ' de lunes a viernes y ' . self::clp($s->precio_sabado_clp) . ' el sábado';
        }
        if ($s->precio_clp) {
            return self::clp($s->precio_clp);
        }

        // Hay consultas cuyo valor depende del paciente y no está cargado.
        return null;
    }

    /**
     * «¿Qué valores tienen?», sin nombrar ninguna: todas, en corto. La primera
     * frase del detalle que escribió la clínica, y el número solo si no hay
     * detalle. Al revés se perdía la condición que va pegada al número: la
     * domicilio salía «$35.000» a secas, y es «$35.000 en la comuna de la clínica».
     */
    private static function listaDePrecios($activos): ?array
    {
        $lineas = [];
        foreach ($activos as $s) {
            if (! empty($s->precio_detalle)) {
                $v = rtrim(preg_split('/(?<=[.;])\s/u', trim($s->precio_detalle))[0], '.;');
            } elseif ($s->precio_clp) {
                $v = self::clp($s->precio_clp) . ($s->precio_sabado_clp ? ' (sábado ' . self::clp($s->precio_sabado_clp) . ')' : '');
            } else {
                continue;
            }
            $lineas[] = "• {$s->nombre}: {$v}";
        }
        if (! $lineas) {
            return null;
        }

        return [
            'texto' => "Estos son nuestros valores:\n" . implode("\n", $lineas)
                . "\nPregúntame por una consulta para ver el detalle.",
            'motivo' => 'precio:lista',
            'acciones' => [['texto' => 'Reservar una hora', 'ruta' => '/reservar']],
        ];
    }

    private static function clp($n): string
    {
        return '$' . number_format((float) $n, 0, ',', '.');
    }
}
