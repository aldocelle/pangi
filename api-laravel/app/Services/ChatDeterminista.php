<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * La capa SIN IA del chat, entera y en su orden: lo único que decide quién
 * contesta un mensaje antes de que exista la opción de un modelo.
 *
 * EL CHAT ES HÍBRIDO, PERO ESTA CAPA VA PRIMERO (sep-2026)
 *
 * Lo que esto devuelve como `null` es EXACTAMENTE lo que hoy se deriva a
 * WhatsApp y lo que mañana vería la IA. Así que cada caso que se resuelve acá
 * es responsabilidad que no carga el modelo: precios, horas libres, catálogo,
 * dirección, lo que la oficina escribió. La IA queda para lo que de verdad no
 * tiene respuesta cerrada, nunca para lo que la base ya sabe.
 *
 * Vive en un servicio y no en el controlador para que el corpus de
 * `ChatCorpusTest` mida lo mismo que corre en /chatbot/mensaje.
 *
 * ORDEN
 *   1. AsistenteReservas — «quiero una hora», cancelar, «mi cita». Primero:
 *      «¿cuánto sale la general y tienen hora mañana?» quiere reservar, y el
 *      precio lo ve al elegir.
 *   2. RespuestaDirecta  — precios, catálogo, dónde, horario, teléfono,
 *      preguntas frecuentes. Con sus derivaciones obligatorias adentro.
 *   3. AsistenteReservas::soloConsulta — el mensaje es solo el nombre de una
 *      consulta («Consulta general a domicilio»): llevarla a reservar. Último,
 *      para no quitarle nada a las dos de arriba.
 */
class ChatDeterminista
{
    public function __construct(private AsistenteReservas $reservas, private RespuestaDirecta $directa)
    {
    }

    /** `['texto', 'motivo', 'acciones'?]` o `null` (no hay respuesta cerrada). */
    public function responder(string $texto, ?array $datos = null, ?CarbonImmutable $ahora = null): ?array
    {
        return $this->reservas->resolver($texto, $ahora) ?? $this->directa->resolver($texto, $datos)
            ?? $this->reservas->soloConsulta($texto);
    }
}
