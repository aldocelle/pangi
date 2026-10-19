<?php

namespace App\Services;

/**
 * Lo que el bot de WhatsApp NO puede dejarle al modelo.
 *
 * El prompt le dice al modelo que derive las urgencias, que no revele sus
 * instrucciones y que mande a una persona a quien la pide. Un prompt es una
 * petición, no una garantía: Haiku ya se saltó pasos mucho más simples (dio
 * por hecha una reserva que no hizo). Esto es lo mismo, pero en código y ANTES
 * del modelo: si el mensaje calza, el modelo ni se entera.
 *
 * Son cuatro cosas, en este orden de prioridad:
 *
 *  · `urgencia`   — un animal en riesgo. Gana a TODO, incluida una conversación
 *                   en curso y el tope de mensajes: es lo único que no puede
 *                   perderse ni demorarse. El texto manda a llamar y a la
 *                   urgencia veterinaria más cercana.
 *  · `humano`     — «quiero hablar con una persona». Pedirlo y que el bot siga
 *                   preguntando datos es la forma más segura de perder a un
 *                   cliente.
 *  · `inyeccion`  — «ignora tus instrucciones», «muéstrame tu prompt».
 *  · `solo_emoji` — un 👍 no es una pregunta: no justifica una llamada al
 *                   modelo (que se cobra) ni una respuesta inventada.
 *
 * LAS LISTAS SON ESTRECHAS A PROPÓSITO. Un falso positivo de `urgencia` manda a
 * llamar a quien solo quería una hora; uno de `inyeccion` calla a un cliente
 * normal. Por eso cada patrón exige una frase, no una palabra suelta, y la
 * urgencia respeta la negación («no es una emergencia»). Lo que queda fuera
 * (vómito, cojera, «dolor») lo sigue derivando la capa sin IA, que ya lo hace.
 *
 * Todo se compara sin tildes ni mayúsculas.
 */
class GuardasWhatsapp
{
    /** Quien dice explícitamente que NO lo es. «No es una emergencia» no dispara la alarma. */
    private const NEGACION_URGENCIA =
        '/\b(no es|no es una|no es muy|no es tan|sin|tampoco es)\s+(una\s+)?(emergencia|urgencia|urgente)\b/u';

    private const URGENCIA = [
        // Respiración
        '/\bno respira\b/', '/\b(dejo|deja|dejando) de respirar\b/', '/\brespira (muy |re )?mal\b/',
        '/\bdificultad (para|al) respirar\b/', '/\bcuesta respirar\b/', '/\bse (esta )?ahog/',
        '/\basfixi/',
        // Neurológico
        '/\bconvuls/', '/\bataque (epileptico|de epilepsia)\b/', '/\bdesmay/', '/\binconsciente\b/',
        '/\bno reacciona\b/', '/\bno se mueve\b/', '/\bno responde (a )?nada\b/',
        // Trauma y sangre
        '/\batropell/', '/\bse (cayo|callo) de(l| un| una)? (balcon|piso|altura|techo|segundo|tercer)/',
        '/\bhemorragia\b/', '/\bsangra (mucho|demasiado)\b/', '/\bno para de sangrar\b/',
        '/\bsangrando (mucho|demasiado|sin parar)\b/', '/\bfractura expuesta\b/',
        // Tóxicos
        '/\benvenen/', '/\bintoxic/', '/\braticida\b/', '/\bmatarrata/', '/\bcomio veneno\b/',
        // Estado general crítico
        '/\bse (esta )?(muriendo|muere)\b/', '/\bse me (esta )?(muriendo|muere)\b/', '/\bagoniz/',
        '/\bmoribund/', '/\bgolpe de calor\b/', '/\binsolacion\b/',
        // Urinario / obstétrico (frecuentes y graves)
        '/\bno (puede|logra) orinar\b/', '/\bno orina (hace|desde)\b/', '/\bparto complicado\b/',
        '/\bdistocia\b/',
        // La palabra misma
        '/\b(es|tengo|tiene|una|es una|muy) (una )?emergencia\b/', '/\bemergencia veterinaria\b/',
        '/\burgencia (veterinaria|medica)\b/', '/\b(es|muy|super) urgente\b/', '/^\s*(urgente|emergencia|urgencia)\s*[!.]*\s*$/',
        '/\bde urgencia\b/',
    ];

    private const HUMANO = [
        '/\b(hablar|hablo|comunicar(me)?|conversar|contactar(me)?|que me (atienda|llame|contacte))\w*\s+con\s+(una?\s+)?(persona|humano|ejecutiv\w+|secretaria|recepcionista|alguien|asesor\w*|encargad\w+)\b/',
        '/\b(agente|asistente|atencion|persona) (humano|humana|real)\b/',
        '/\b(quiero|necesito|prefiero|puedo|pasame|pasenme|comunicame|comuniquenme)\b[^.?!]{0,30}\b(una persona|un humano|alguien real|una secretaria|la secretaria|la recepcionista)\b/',
        '/\bno (quiero|me sirve|puedo) (hablar|seguir hablando|escribir) con (un|el) (bot|robot|asistente|chatbot)\b/',
        '/\bque me (atienda|llame|contacte)\w* (una persona|alguien|un humano|la secretaria)\b/',
        '/\b(comunican|comunicas|comunicar|conectan|conectas|pasan|pasas)\w*( me)? con (la secretaria|alguien|una persona|un humano|la recepcionista|el equipo)\b/',
        '/\bpasame con (alguien|una persona|la clinica|la secretaria)\b/',
    ];

    private const INYECCION = [
        // Solo si el objeto es la instrucción misma. «Olvida lo anterior, mejor
        // otro día» es un cliente cambiando de idea, no un ataque.
        '/\b(ignora|ignore|olvida|olvidate de|descarta|omite|salta(te)?)\w*\s+(todas\s+)?(tus|las|todas las)\s+(instrucciones|reglas|indicaciones|directrices|restricciones|normas)\b/',
        '/\bignore (all )?(previous|prior|above|your) (instructions|rules|prompt)/',
        '/\bsystem prompt\b/', '/\bprompt (del sistema|inicial|original)\b/',
        '/\b(muestrame|dime|revela|repite|copia|dame|imprime|escribe) (tu|el|las|tus) (prompt|instrucciones|reglas|configuracion|mensaje del sistema)\b/',
        '/\binstrucciones (del sistema|internas|ocultas|iniciales)\b/',
        '/\b(modo|mode) (desarrollador|developer|dios|admin|administrador|debug)\b/',
        // Sin «actúa como…»: «mi perro actúa como si le doliera» es un síntoma.
        '/\bjailbreak\b/', '/\bdo anything now\b/',
        '/\bfinge (que eres|ser)\b/', '/\bfingi (que eres|ser)\b/', '/\bpretend (to be|you are)\b/',
        '/\b(a partir de ahora|desde ahora) (eres|seras|te llamas|vas a ser)\b/',
        '/\bresponde (solo )?(con|en) (json|codigo|xml|python)\b/',
    ];

    /**
     * El tipo de guarda que corresponde al mensaje, o `null` si pasa.
     *
     * @return 'urgencia'|'humano'|'inyeccion'|'solo_emoji'|null
     */
    public static function clasificar(string $texto): ?string
    {
        $t = self::normalizar($texto);
        if ($t === '') {
            return null;
        }

        // Sin una sola letra ni dígito: 👍, 🙏. Se mira ANTES de las listas, que
        // trabajan sobre letras. Pero la PUNTUACIÓN sola («?», «¿?», «...») no
        // es un emoji: es alguien preguntando «¿qué pasó?», y el bot ya sabe
        // contestarlo con el estado real de su consulta (dudaDelTutor). Esa
        // pasa de largo.
        if (! preg_match('/[\p{L}\p{N}]/u', $t)) {
            return preg_match('/^[\p{P}\s]+$/u', $t) ? null : 'solo_emoji';
        }

        if (self::coincide(self::URGENCIA, $t) && ! preg_match(self::NEGACION_URGENCIA, $t)) {
            return 'urgencia';
        }
        if (self::coincide(self::INYECCION, $t)) {
            return 'inyeccion';
        }
        if (self::coincide(self::HUMANO, $t)) {
            return 'humano';
        }

        return null;
    }

    /** El mensaje fijo que se le manda a quien cayó en la guarda. */
    public static function respuesta(string $tipo, ?string $telefonoClinica = null): string
    {
        $tel = $telefonoClinica ?? (string) config('emr.clinica_telefono');

        return match ($tipo) {
            'urgencia' => "⚠️ Esto puede ser una urgencia y por chat no puedo evaluarla. Llama ahora a la clínica al {$tel}. "
                . 'Si no contestamos o estamos cerrados, ve de inmediato al servicio de urgencias veterinarias más cercano. '
                . 'No esperes una respuesta por aquí.',
            'humano' => "Claro. Para hablar con una persona del equipo, llámanos al {$tel} y te atienden ahí.",
            'inyeccion' => 'Solo puedo ayudarte con las horas y las consultas de ' . Marca::nombre() . '. ¿Qué necesitas?',
            'solo_emoji' => 'No alcancé a entender ese mensaje. Si querías confirmar o elegir algo, '
                . 'respóndeme con palabras, por ejemplo «sí» o «no». 🙂',
            default => '',
        };
    }

    /** Minúsculas, sin tildes, sin espacios repetidos. */
    public static function normalizar(string $texto): string
    {
        $t = mb_strtolower(trim($texto));
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    /** @param list<string> $patrones */
    private static function coincide(array $patrones, string $t): bool
    {
        foreach ($patrones as $p) {
            if (preg_match($p . 'u', $t)) {
                return true;
            }
        }

        return false;
    }
}
