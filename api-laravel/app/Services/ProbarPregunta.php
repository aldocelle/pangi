<?php

namespace App\Services;

/**
 * Lo que el modal «Enseñarle» (Ajustes → Asistente) muestra ANTES de guardar:
 * qué contestaría el chat con la pregunta frecuente nueva, y si no la usa, por qué.
 *
 * PARA QUÉ
 *
 * Una pregunta frecuente puede guardarse bien y no contestar nunca: porque la
 * palabra clave no aparece en cómo pregunta la gente, porque choca con otra
 * (si calzan dos no se contesta ninguna), porque el mensaje dice «mi perro» y
 * eso siempre va a una persona, o porque el asistente de reservas lo toma
 * antes. Nada de eso se ve en el formulario; se veía recién cuando un cliente
 * seguía escribiendo a WhatsApp.
 *
 * LAS REGLAS NO SE COPIAN
 *
 * Recorre el mismo camino que `ChatbotController::mensaje` —reservas primero,
 * después RespuestaDirecta— con la pregunta nueva agregada a lo guardado. Las
 * explicaciones salen de los mismos métodos (`escalaPor`, `calzan`,
 * `errorPregunta`), así que un cambio de regla cambia también lo que muestra el
 * modal. Nada se guarda acá.
 */
class ProbarPregunta
{
    public function __construct(private AsistenteReservas $reservas, private RespuestaDirecta $directa)
    {
    }

    /**
     * `['errores' => [campo => mensaje], 'pruebas' => [[texto, respuesta, origen,
     * explicacion, choca_con]]]`. `origen`: 'nueva' (contesta con lo que se está
     * escribiendo), 'reservas', 'otra' (otra respuesta del chat) o `null` (deriva).
     */
    public function probar(array $textos, string $claves, string $respuesta): array
    {
        $nueva = ['claves' => $claves, 'respuesta' => $respuesta];
        $errores = [];
        if ($e = RespuestaDirecta::errorPregunta($nueva)) {
            $errores[$e[0]] = $e[1];
        }
        // Sin respuesta escrita todavía igual se prueba si CALZA: se usa un
        // marcador, y la vista previa lo dice en vez de mostrar un globo vacío.
        $prueba = ['claves' => $claves, 'respuesta' => trim($respuesta) !== '' ? $respuesta : '…'];

        $datos = RespuestaDirecta::datos();
        $existentes = array_values($datos['preguntas'] ?? []);
        $datos['preguntas'] = [...$existentes, $prueba];
        $iNueva = count($existentes);

        $pruebas = [];
        foreach (array_slice($textos, 0, 5) as $texto) {
            $pruebas[] = $this->una((string) $texto, $datos, $iNueva);
        }

        return ['errores' => $errores, 'pruebas' => $pruebas];
    }

    private function una(string $texto, array $datos, int $iNueva): array
    {
        $fila = ['texto' => $texto, 'respuesta' => null, 'origen' => null, 'explicacion' => null, 'choca_con' => []];

        if ($r = $this->reservas->resolver($texto)) {
            return [...$fila, 'respuesta' => $r['texto'], 'origen' => 'reservas',
                'explicacion' => 'Este mensaje lo toma el asistente de reservas, que ofrece botones para reservar. '
                    . 'Tu respuesta no se usaría acá.'];
        }

        if ($palabra = RespuestaDirecta::escalaPor($texto)) {
            // Con «mi perro» el chat igual contesta precios y «¿hacen X?»: se
            // muestra lo que contesta, para no hacer creer que queda sin respuesta.
            $r = $this->directa->resolver($texto, $datos);

            return [...$fila, 'respuesta' => $r['texto'] ?? null, 'origen' => $r ? 'otra' : null, 'explicacion' => "El mensaje dice «{$palabra}», y lo que habla de agendar, cancelar, "
                . 'pagar, de la cuenta o la mascota de alguien, o de algo médico, lo atiende una persona. '
                . 'Las preguntas frecuentes no lo contestan.'];
        }

        $calzan = RespuestaDirecta::calzan($texto, $datos['preguntas']);
        $otras = array_values(array_diff($calzan, [$iNueva]));
        if (in_array($iNueva, $calzan, true) && $otras) {
            return [...$fila,
                'explicacion' => 'También calza con otra pregunta frecuente, y cuando calzan dos no se contesta ninguna. '
                    . 'Quita la palabra que comparten, aquí o en la otra.',
                'choca_con' => array_map(fn ($i) => [
                    'claves' => $datos['preguntas'][$i]['claves'],
                    'respuesta' => $datos['preguntas'][$i]['respuesta'],
                ], $otras)];
        }

        $r = $this->directa->resolver($texto, $datos);
        if (in_array($iNueva, $calzan, true)) {
            return [...$fila, 'respuesta' => $r['texto'] ?? null, 'origen' => 'nueva'];
        }

        return [...$fila,
            'respuesta' => $r['texto'] ?? null,
            'origen' => $r ? 'otra' : null,
            'explicacion' => $r
                ? 'Ninguna palabra clave aparece en el mensaje, así que contesta con otra cosa que ya sabía.'
                : 'Ninguna palabra clave aparece en el mensaje. Agrega una palabra que sí esté en la pregunta.'];
    }
}
