<?php

namespace App\Services;

/**
 * Qué consulta del catálogo nombra un mensaje, y qué queda del mensaje que el
 * catálogo NO explica. Lo usan el asistente de reservas y RespuestaDirecta: dos
 * copias de «cómo le dice la gente a la radiología» es cómo una capa entiende
 * «radiografía» y la otra no (pasaba: reservas sí, precios no).
 */
class CatalogoNombres
{
    /** Palabras de los nombres que no distinguen una consulta de otra. */
    public const GENERICAS = ['consulta', 'control', 'general', 'examenes', 'especialistas'];

    /** Cómo le dice la gente a una consulta cuando no usa su nombre. */
    public const ALIAS = [
        // Nunca «unas» a secas: «reservar unas horas» no es un corte de uñas.
        'las unas' => 'Corte de uñas',
        'sus unas' => 'Corte de uñas',
        'de unas' => 'Corte de uñas',
        'radiograf' => 'Radiología',
        'rayos' => 'Radiología',
        'eco ' => 'Ecografía',
        'online' => 'Telemedicina general',
        'videollamada' => 'Telemedicina general',
    ];

    /**
     * Palabras que pueden acompañar a una pregunta de precio o de «¿hacen X?»
     * sin cambiar de qué se trata. Lo que NO esté acá ni en el nombre de la
     * consulta es contenido que el catálogo no explica —«esterilizar», «vacuna»,
     * «tortuga», «maipú»— y ahí no se contesta: la respuesta saldría con cara
     * de dato sobre algo que no dice.
     */
    public const NEUTRAS = [
        'que', 'cual', 'cuales', 'cuanto', 'cuanta', 'cuantos', 'cuesta', 'cuestan', 'costo', 'costos',
        'sale', 'salen', 'vale', 'valen', 'valor', 'valores', 'precio', 'precios', 'tarifa', 'tarifas',
        'cobran', 'cobra', 'cobrar', 'tienen', 'tiene', 'hacen', 'hace', 'realizan', 'realiza', 'ofrecen',
        'ofrece', 'para', 'como', 'esta', 'este', 'estos', 'estas', 'ustedes', 'usted', 'hola', 'buenas',
        'buenos', 'dias', 'tardes', 'noches', 'porfa', 'favor', 'gracias', 'quisiera', 'quiero', 'queria',
        'necesito', 'saber', 'consulta', 'consultas', 'control', 'controles', 'general', 'atencion',
        'atenciones', 'servicio', 'servicios', 'examen', 'examenes', 'informacion', 'info', 'aprox',
        'aproximado', 'aproximadamente', 'perro', 'perra', 'perros', 'perras', 'perrito', 'perrita',
        'gato', 'gata', 'gatos', 'gatas', 'gatito', 'gatita', 'mascota', 'mascotas', 'clinica',
        'veterinaria', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'sabados',
        'domingo', 'domingos', 'semana', 'unos', 'unas', 'algo', 'alguna', 'algun', 'cada', 'tambien',
        'cortar', 'cortarle', 'hacer', 'hacerle', 'todos', 'todas', 'especialista', 'especialistas',
        'doctor', 'doctora', 'sobre', 'desde', 'hasta', 'mismo', 'misma', 'ahora', 'dime', 'decir',
        'podrian', 'pueden', 'puede', 'seria', 'serian',
    ];

    /** Minúsculas, sin tildes ni signos, con un espacio a cada lado. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        return ' ' . trim(preg_replace('/[^a-z0-9]+/', ' ', $s)) . ' ';
    }

    /**
     * Las consultas que el mensaje nombra. Gana la que calza con MÁS palabras
     * distintivas: «telemedicina dermatológica» es esa y no cualquier
     * dermatología, y «consulta general a domicilio» le gana a «consulta
     * general» porque «domicilio» suma una más.
     *
     * @param  iterable<object>  $activos  filas de `servicios`
     * @return array<object>
     */
    public static function nombrados(string $mensaje, iterable $activos): array
    {
        $t = self::norm($mensaje);
        $puntaje = [];
        foreach ($activos as $s) {
            $nombre = trim(self::norm($s->nombre));
            $p = str_contains($t, " {$nombre} ") ? 100 : 0;
            foreach (self::distintivas($s->nombre) as $palabra) {
                if (str_contains($t, substr($palabra, 0, 6))) {
                    $p++;
                }
            }
            foreach (self::ALIAS as $alias => $destino) {
                if ($s->nombre === $destino && str_contains($t, $alias)) {
                    $p++;
                }
            }
            if ($p > 0) {
                $puntaje[$s->id] = [$p, $s];
            }
        }
        if (! $puntaje) {
            return [];
        }
        $max = max(array_column($puntaje, 0));

        return array_values(array_map(fn ($x) => $x[1], array_filter($puntaje, fn ($x) => $x[0] === $max)));
    }

    /**
     * Las palabras del mensaje (4+ letras) que no son neutras ni pertenecen a
     * las consultas nombradas. Vacío = el mensaje no dice nada más que eso.
     *
     * @param  array<object>  $servicios
     * @return array<string>
     */
    public static function sobrante(string $mensaje, array $servicios): array
    {
        $explicadas = [];
        foreach ($servicios as $s) {
            foreach (self::distintivas($s->nombre) as $p) {
                $explicadas[] = substr($p, 0, 6);
            }
            foreach (self::ALIAS as $alias => $destino) {
                if ($s->nombre === $destino) {
                    $explicadas[] = trim(str_replace(['las ', 'sus ', 'de '], '', $alias));
                }
            }
        }

        $sobra = [];
        foreach (preg_split('/\s+/', trim(self::norm($mensaje))) as $w) {
            if (strlen($w) < 4 || in_array($w, self::NEUTRAS, true)) {
                continue;
            }
            foreach ($explicadas as $e) {
                if ($e !== '' && (str_starts_with($w, $e) || str_contains($w, $e))) {
                    continue 2;
                }
            }
            $sobra[] = $w;
        }

        return $sobra;
    }

    /** Palabras del nombre (5+ letras, no genéricas), normalizadas. */
    private static function distintivas(string $nombre): array
    {
        return array_values(array_filter(
            preg_split('/\s+/', trim(self::norm($nombre))),
            fn ($p) => strlen($p) >= 5 && ! in_array($p, self::GENERICAS, true),
        ));
    }
}
