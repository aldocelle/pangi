<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Las variables de los recordatorios (`@mascota_nombre`, `@fecha`…), las mismas
 * que ofrece ACVet en su modal de plantillas, más los alias cortos que traen
 * sus mensajes ya escritos (`@mascota`, `@propietario`): los recordatorios
 * importados los usan y tienen que salir resueltos, no con la arroba a la vista.
 *
 * Se resuelven **al enviar**, no al guardar: el recordatorio guarda la plantilla
 * con sus variables, y así un cambio de teléfono del tutor o de nombre de la
 * clínica llega a lo que todavía no salió.
 */
class MensajeRecordatorio
{
    /** Grupos tal como los muestra el panel al lado del mensaje. */
    public const VARIABLES = [
        'Mascota' => ['@mascota_nombre', '@mascota_especie', '@mascota_raza'],
        'Propietario' => ['@propietario_nombre', '@propietario_telefono', '@propietario_email'],
        'Clínica' => ['@clinica_nombre', '@clinica_telefono'],
        'Fecha' => ['@fecha', '@fecha_actual', '@hora'],
    ];

    /**
     * @param  object|null  $mascota  fila de `mascotas` (nombre, especie, raza)
     * @param  object|null  $dueno  fila de `usuarios` (nombre, telefono, email)
     * @param  string|null  $fecha  `fecha_programada` del recordatorio (Y-m-d)
     */
    public static function resolver(string $texto, ?object $mascota, ?object $dueno, ?string $fecha,
        ?CarbonImmutable $ahora = null): string
    {
        $ahora ??= CarbonImmutable::now('America/Santiago');
        $email = $dueno->email ?? '';
        if (str_ends_with($email, '@sin-correo.local')) {
            $email = '';
        }
        $valores = [
            '@mascota_nombre' => $mascota->nombre ?? '',
            '@mascota_especie' => $mascota->especie ?? '',
            '@mascota_raza' => $mascota->raza ?? '',
            '@propietario_nombre' => $dueno->nombre ?? '',
            '@propietario_telefono' => $dueno->telefono ?? '',
            '@propietario_email' => $email,
            '@clinica_nombre' => self::clinica(),
            '@clinica_telefono' => (string) config('emr.clinica_telefono'),
            '@fecha_actual' => self::fechaCorta($ahora->format('Y-m-d')),
            '@fecha' => $fecha ? self::fechaCorta($fecha) : '',
            '@hora' => $ahora->format('H:i'),
            // Alias de ACVet. Van DESPUÉS de los largos en el orden de
            // reemplazo (ver abajo): `@mascota` no puede comerse el comienzo
            // de `@mascota_nombre`.
            '@mascota' => $mascota->nombre ?? '',
            '@propietario' => $dueno->nombre ?? '',
        ];
        // Más largo primero: `strtr` ya lo hace, pero se deja explícito porque
        // es la única razón de que los alias cortos funcionen.
        uksort($valores, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return strtr($texto, $valores);
    }

    /** Datos de ejemplo para la vista previa del panel. */
    public static function ejemplo(string $texto): string
    {
        return self::resolver($texto,
            (object) ['nombre' => 'Luna', 'especie' => 'Canino', 'raza' => 'Mestiza'],
            (object) ['nombre' => 'María González', 'telefono' => '+56 9 1234 5678', 'email' => 'maria@ejemplo.cl'],
            CarbonImmutable::now('America/Santiago')->addDays(7)->format('Y-m-d'));
    }

    /**
     * Asunto y cuerpo del aviso por correo. Es el texto que antes armaba
     * AvisosPreventivos por su cuenta; vive acá para que la vista previa del
     * panel muestre EXACTAMENTE lo que va a salir.
     *
     * @return array{0: string, 1: string}
     */
    public static function correo(object $dueno, object $mascota, object $r, array $config): array
    {
        $que = $r->titulo ?: ($r->categoria ?: 'un control');
        $que = self::resolver($que, $mascota, $dueno, $r->fecha_programada ?? null);
        $asunto = "{$mascota->nombre} tiene pendiente: {$que}";

        $cuerpo = "Hola {$dueno->nombre},\n\n"
            . 'Te escribimos desde ' . self::clinica() . ' para recordarte que '
            . "{$mascota->nombre} tiene pendiente " . mb_strtolower($que)
            . (! empty($r->fecha_programada) ? ' para el ' . self::fechaCorta($r->fecha_programada) : '') . ".\n";
        if ($r->mensaje) {
            $cuerpo .= "\n" . self::resolver($r->mensaje, $mascota, $dueno, $r->fecha_programada ?? null) . "\n";
        }
        $cuerpo .= "\nPara agendar la hora escríbenos por WhatsApp al "
            . config('emr.clinica_telefono') . ' o entra a '
            . rtrim((string) config('emr.portal_reservas_url'), '/') . ".\n\n"
            . "Si ya lo hiciste, ignora este mensaje.\n\n"
            . (trim($config['firma'] ?? '') !== '' ? trim($config['firma']) . "\n\n" : '')
            . self::clinica();

        return [$asunto, $cuerpo];
    }

    /**
     * La versión HTML del mismo aviso, con la tarjeta de la plantilla estándar.
     * Dice lo mismo que `correo()` —mismas variables resueltas, misma firma— y
     * la vista previa del panel sigue mostrando el texto: el HTML es solo su
     * presentación.
     *
     * El mensaje que la oficina escribió se escapa y conserva sus saltos de
     * línea: es texto libre editable desde el panel.
     */
    public static function html(object $dueno, object $mascota, object $r, array $config): string
    {
        $e = fn ($v) => Plantilla::e($v);
        $que = $r->titulo ?: ($r->categoria ?: 'un control');
        $que = self::resolver($que, $mascota, $dueno, $r->fecha_programada ?? null);

        $filas = Plantilla::fila('🐾', '<strong>' . $e($mascota->nombre) . '</strong>')
            . Plantilla::fila('🩺', $e($que));
        if (! empty($r->fecha_programada)) {
            $filas .= Plantilla::fila('📅', $e(self::fechaCorta($r->fecha_programada)));
        }

        $cuerpo = Plantilla::tarjeta('Pendiente', $filas);
        if ($r->mensaje) {
            $cuerpo .= Plantilla::parrafo(nl2br($e(
                self::resolver($r->mensaje, $mascota, $dueno, $r->fecha_programada ?? null))));
        }
        $enlace = rtrim((string) config('emr.portal_reservas_url'), '/');
        $cuerpo .= Plantilla::subtitulo('¿Quieres agendar la hora?')
            . Plantilla::boton($e($enlace), 'Reservar hora')
            . Plantilla::parrafo('También puedes escribirnos por WhatsApp al '
                . $e(config('emr.clinica_telefono')) . '.', true);

        $firma = trim($config['firma'] ?? '');

        return Plantilla::pagina(
            'Un recordatorio para ' . $e($mascota->nombre),
            'Hola ' . $e($dueno->nombre) . ', te escribimos desde ' . $e(self::clinica()) . '.',
            $cuerpo,
            'Si ya lo hiciste, ignora este mensaje.' . ($firma !== '' ? '<br>' . nl2br($e($firma)) : '')
        );
    }

    private static function clinica(): string
    {
        return Marca::nombre();
    }

    /** «12-03-2026»: numérico a propósito, sin nombres de mes (§9.7). */
    private static function fechaCorta(string $ymd): string
    {
        return CarbonImmutable::parse(substr($ymd, 0, 10))->format('d-m-Y');
    }
}
