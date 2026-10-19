<?php

namespace App\Services;

/**
 * Plantilla HTML compartida de los correos al cliente. Port de
 * `email_plantilla.py`.
 *
 * Traslada al correo la estética del paso 4 del portal: la tarjeta redondeada
 * con cabecera azul tintada, el detalle en filas de ícono + texto, y el aviso
 * destacado sobre fondo cálido al pie.
 *
 * Los tokens son **literales, no variables CSS**: un cliente de correo no lee
 * `:root` ni `var()`. Son exactamente los valores de
 * `portal-reservas/src/assets/app.css` y hay que moverlos a mano si la paleta
 * cambia — misma duplicación deliberada que los tres frontends (§9.5).
 *
 * Todo va en `style=` inline por lo mismo: Gmail descarta `<style>` casi
 * siempre, y `<table>` sigue siendo el único layout que sobrevive en Outlook.
 *
 * ESTÁNDAR DE LOS CORREOS (el de «Reserva confirmada» es la referencia).
 * Todo correo al cliente, o a la clínica, se arma con `pagina()` y SOLO con
 * estos bloques, en este orden:
 *
 *   1. título       — `pagina($titulo)`: corto, centrado; un ✓ si cierra algo.
 *   2. saludo       — `pagina($saludo)`: «Hola Daniel» + una frase de contexto.
 *   3. cuerpo, de arriba abajo:
 *        · `parrafo()`   la explicación, si hace falta más que el saludo.
 *        · `boton()`     LA acción del correo (una sola) + `enlaceAlterno()`.
 *        · `tarjeta()`   el detalle en filas de ícono (`fila()`); su `$aviso`
 *                        cálido al pie es para lo que NO se puede pasar por alto.
 *        · `subtitulo()` + `bloqueQueEsperar()` para listas de indicaciones.
 *   4. pie          — `pagina($pie)`: la letra chica (vencimiento, «si no fuiste
 *                     tú…», cómo contactarnos). Una frase, sin repetir el cuerpo.
 *   5. firma        — la pone `pagina()`; no se escribe a mano: el pie
 *                     institucional (nombre, dirección, WhatsApp, sitio).
 *
 * Arriba de todo, también puesta por `pagina()`, va la cabecera con el logo.
 *
 * Íconos de las filas, para que signifiquen lo mismo en todos los correos:
 * 🐾 paciente · 🩺 servicio/motivo · 🗓️ fecha (`ICONO_FECHA`) · 📍 lugar · 👤 persona ·
 * ✉️ correo · 💲 dinero · 🪪 RUT · 🔖 código · 💬 mensaje.
 *
 * Todo texto que venga de un formulario o de la base va por `e()`: el correo
 * sale con el remitente real de la clínica. Y todo correo con HTML lleva también
 * su versión en texto plano, que Gmail no muestra pero otros clientes sí.
 */
class Plantilla
{
    // Estos cuatro son los defaults de fábrica: lo que el correo pintaba antes
    // de que existiera Ajustes → Correos. `colores()` los reemplaza por lo que
    // la clínica eligió, si eligió algo.
    public const INK = '#26326e';
    public const INK_SOFT = '#4a4a4a';
    public const BRAND_STRONG = '#1a2350';
    public const BRAND_TINT = '#eef1fb';
    public const SURFACE = '#ffffff';
    public const LINE = '#dde5ed';
    public const ACENTO_SUAVE = '#fbeee6';
    public const ACENTO = '#e07a3f';

    /**
     * La fecha va con 🗓️ y no con 📅: en iPhone 📅 se dibuja como una hoja
     * de calendario que dice «JUL 17», y al lado de «Lunes 26 de octubre»
     * parece otra fecha. 🗓️ es un calendario sin número. Lleva el selector de
     * variante (U+FE0F) para que salga en color y no como símbolo de texto.
     */
    public const ICONO_FECHA = "\u{1F5D3}\u{FE0F}";

    public const R_LG = '22px';
    public const R_MD = '14px';

    /**
     * Lo que Ajustes → Correos deja cambiar, y lo que deliberadamente NO deja.
     *
     * **Solo fondos.** El texto (`INK`/`INK_SOFT`) nunca se vuelve editable:
     * es lo que garantiza que cualquier combinación siga leyéndose. Y cada
     * fondo sale de un catálogo cerrado, no de un selector de color libre —
     * `fondo_boton` lleva SIEMPRE texto blanco encima (`boton()`), así que un
     * hex claro ahí lo volvería ilegible; lo mismo al revés con un fondo
     * oscuro detrás de `INK_SOFT` en el aviso. El catálogo (`ConfigClinica::
     * SWATCHES_CLAROS/OSCUROS`) ya separa las dos familias para que esa
     * combinación no pueda pasar — restringir no es una limitación de esta
     * versión, es lo que hace innecesario validar contraste en cada guardado.
     */
    public static function colores(): array
    {
        return ConfigClinica::leer('correos');
    }

    /**
     * Las pilas de fuentes llevan comillas DOBLES adentro porque todos los
     * atributos usan comillas simples. Al revés, `'Open Sans'` cierra el
     * `style='...'` y el correo se rompe — ya pasó.
     */
    public const FONT = '"Open Sans", "Helvetica Neue", Arial, sans-serif';
    public const FONT_TIT = '"Poppins", "Open Sans", "Helvetica Neue", Arial, sans-serif';

    /** Escapa para HTML. Una sola definición en vez de un `$e` por correo. */
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Un párrafo del cuerpo. `suave` es la letra chica, en gris. */
    public static function parrafo(string $html, bool $suave = false): string
    {
        $color = $suave ? self::INK_SOFT : self::INK;
        $tam = $suave ? '13px' : '15px';

        return "<p style='margin:0 0 14px;font-size:{$tam};line-height:1.5;color:{$color}'>{$html}</p>";
    }

    /** El título de una sección dentro del cuerpo («Para tu visita», «¿Necesitas cambiar la hora?»). */
    public static function subtitulo(string $texto): string
    {
        return "<p style='margin:0 0 8px;font-size:15px;font-weight:600;color:" . self::INK . "'>"
            . "{$texto}</p>";
    }

    /**
     * «¿Necesitas cambiar la hora?» + botón. Reagendar va como botón y no como
     * «contáctanos» porque una hora movida conserva al paciente y una cancelada
     * lo pierde. `separado` agrega aire arriba cuando lo anterior no lo trae.
     */
    public static function bloqueReagendar(string $enlace, bool $separado = false): string
    {
        return ($separado ? "<div style='height:20px;line-height:20px'>&nbsp;</div>" : '')
            . self::subtitulo('¿Necesitas cambiar la hora?')
            . self::boton(self::e($enlace), 'Reagendar mi cita');
    }

    /**
     * Bajo un `boton()`: el enlace en claro, para quien su cliente de correo no
     * deja hacer clic en el botón o lo quiere copiar a otro navegador.
     */
    public static function enlaceAlterno(string $enlace, string $intro = 'Si el botón no funciona, copia este enlace en tu navegador:'): string
    {
        return "<p style='margin:0 0 6px;font-size:12px;line-height:1.5;color:" . self::INK_SOFT
            . ";word-break:break-all'>{$intro} " . self::e($enlace) . '</p>';
    }

    /**
     * Una línea del detalle: ícono a la izquierda, texto a la derecha.
     *
     * Es el `.cita-card-item` del paso 4. Reemplaza a la tabla etiqueta/valor
     * donde el nombre de la mascota quedaba pegado a su etiqueta
     * («MascotaDiana») en todo cliente de correo que junta las celdas.
     */
    public static function fila(string $icono, string $texto, bool $suave = false): string
    {
        $color = $suave ? self::INK_SOFT : self::INK;
        $tam = $suave ? '13px' : '15px';

        return '<tr>'
            . "<td style='padding:5px 10px 5px 0;font-size:16px;line-height:1.4;"
            . "vertical-align:top;width:26px'>{$icono}</td>"
            . "<td style='padding:5px 0;font-size:{$tam};line-height:1.45;color:{$color}'>{$texto}</td>"
            . '</tr>';
    }

    /** La `.cita-card`: cabecera tintada + detalle + aviso opcional al pie. */
    public static function tarjeta(string $cabecera, string $filas, string $aviso = ''): string
    {
        $colores = self::colores();
        $fondoAviso = $colores['fondo_aviso'] ?? self::ACENTO_SUAVE;
        $fondoCabecera = $colores['fondo_cabecera'] ?? self::BRAND_TINT;

        $bloqueAviso = $aviso
            ? "<tr><td style='padding:14px 20px;background:{$fondoAviso};"
              . 'border-top:1px solid ' . self::LINE . ";font-size:13px;line-height:1.5;"
              . 'color:' . self::INK_SOFT . "'>{$aviso}</td></tr>"
            : '';

        return "<table role='presentation' cellpadding='0' cellspacing='0' border='0' "
            . "style='width:100%;max-width:480px;border-collapse:separate;"
            . 'border:1px solid ' . self::LINE . ';border-radius:' . self::R_MD . ';'
            . 'overflow:hidden;background:' . self::SURFACE . ";margin:0 0 20px'>"
            . "<tr><td style='padding:16px 20px;background:{$fondoCabecera};"
            . 'color:' . self::INK . ";font-size:15px;font-weight:600'>{$cabecera}</td></tr>"
            . "<tr><td style='padding:16px 20px'>"
            . "<table role='presentation' cellpadding='0' cellspacing='0' border='0' "
            . "style='width:100%;border-collapse:collapse'>{$filas}</table>"
            . '</td></tr>'
            . $bloqueAviso
            . '</table>';
    }

    public static function boton(string $href, string $texto): string
    {
        $fondo = self::colores()['fondo_boton'] ?? self::BRAND_STRONG;

        return "<table role='presentation' cellpadding='0' cellspacing='0' border='0' "
            . "style='margin:4px 0 14px'><tr><td style='border-radius:" . self::R_MD . ';'
            . "background:{$fondo}'>"
            . "<a href='{$href}' style='display:inline-block;padding:13px 26px;color:#ffffff;"
            . 'text-decoration:none;font-weight:600;font-size:15px;font-family:' . self::FONT . "'>"
            . "{$texto}</a></td></tr></table>";
    }

    /** El recuadro «Para tu visita» (las indicaciones), con sus filas ya armadas. */
    public static function bloqueQueEsperar(string $items): string
    {
        return self::subtitulo('Para tu visita')
            . "<table role='presentation' cellpadding='0' cellspacing='0' border='0' "
            . "style='width:100%;max-width:480px;border-collapse:separate;"
            . 'border:1px solid ' . self::LINE . ';border-radius:' . self::R_MD . ';'
            . 'background:' . self::SURFACE . ";margin:0'>"
            . "<tr><td style='padding:14px 20px'>"
            . "<table role='presentation' cellpadding='0' cellspacing='0' border='0' "
            . "style='width:100%;border-collapse:collapse'>"
            . $items
            . '</table></td></tr></table>';
    }

    /** Lee config sin romper un test unitario puro, que no levanta la aplicación. */
    private static function config(string $clave): string
    {
        try {
            return trim((string) config($clave));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * La cabecera institucional: el logo de la clínica, centrado, sobre el
     * título. Va en TODOS los correos, también en los internos, para que se
     * reconozcan como de la clínica antes de leer nada.
     *
     * `width` y `height` como atributos y no solo en el estilo: Outlook ignora
     * el CSS de las imágenes y, sin ellos, dibuja el PNG a su tamaño real
     * (256 px, el doble, para pantallas retina). Sin logo configurado, el
     * nombre en texto ocupa el mismo lugar.
     */
    private static function cabecera(): string
    {
        $nombre = self::e(Marca::nombre());
        $logo = self::config('emr.correo_logo_url');
        if ($logo === '') {
            return "<p style='margin:0 0 18px;font-family:" . self::FONT_TIT . ';font-size:20px;'
                . 'font-weight:700;letter-spacing:0.06em;color:' . self::INK . ";text-align:center'>{$nombre}</p>";
        }

        return "<table role='presentation' cellpadding='0' cellspacing='0' border='0' style='width:100%'>"
            . "<tr><td align='center' style='padding:0 0 16px'>"
            . "<img src='" . self::e($logo) . "' width='128' height='128' alt='{$nombre}' "
            . "style='display:block;width:128px;height:128px;border:0;outline:none;text-decoration:none'>"
            . '</td></tr></table>';
    }

    /**
     * El pie institucional: el nombre (o el texto que la clínica eligió en
     * Ajustes → Correos), la dirección, el WhatsApp y el sitio. Es lo que
     * permite responder o llegar sin buscar los datos en otra parte.
     */
    private static function pieInstitucional(string $pieTexto): string
    {
        $direccion = self::config('emr.clinica_direccion') ?: CitasEmail::DIRECCION_CLINICA;
        $telefono = self::config('emr.clinica_telefono');
        $sitio = self::config('emr.sitio_url');

        $contacto = array_filter([
            $telefono !== '' ? 'WhatsApp ' . self::e($telefono) : '',
            $sitio !== ''
                ? "<a href='" . self::e($sitio) . "' style='color:" . self::INK . ";text-decoration:underline'>"
                    . self::e(preg_replace('#^https?://(www\.)?#', '', $sitio)) . '</a>'
                : '',
        ]);

        return "<p style='margin:24px 0 0;padding-top:18px;border-top:1px solid " . self::LINE . ';'
            . 'font-family:' . self::FONT_TIT . ';font-size:14px;font-weight:600;color:' . self::INK . ';'
            . "text-align:center'>{$pieTexto}</p>"
            . "<p style='margin:4px 0 0;font-size:12px;line-height:1.6;color:" . self::INK_SOFT . ";text-align:center'>"
            . self::e($direccion) . ($contacto ? '<br>' . implode(' · ', $contacto) : '') . '</p>';
    }

    /**
     * El `.panel`: la tarjeta blanca redondeada sobre el fondo azul claro,
     * con la cabecera del logo arriba y el pie institucional abajo.
     *
     * `titulo` y `saludo` ya vienen escapados por quien llama —el nombre del
     * tutor sale de un formulario público—; `cuerpo` es HTML armado acá.
     */
    public static function pagina(string $titulo, string $saludo,
                                  string $cuerpo, string $pie = ''): string
    {
        $colores = self::colores();
        $fondoPagina = $colores['fondo_pagina'] ?? self::BRAND_TINT;
        $pieTexto = self::e($colores['pie_texto'] ?? Marca::nombre());

        $bloquePie = $pie
            ? "<p style='margin:18px 0 0;font-size:13px;line-height:1.5;"
              . 'color:' . self::INK_SOFT . "'>{$pie}</p>"
            : '';

        return "<div style='background:{$fondoPagina};padding:24px 12px;"
            . 'font-family:' . self::FONT . "'>"
            . "<table role='presentation' cellpadding='0' cellspacing='0' border='0' "
            . "style='width:100%;max-width:520px;margin:0 auto;border-collapse:separate;"
            . 'background:' . self::SURFACE . ';border:1px solid ' . self::LINE . ';'
            . 'border-radius:' . self::R_LG . "'>"
            . "<tr><td style='padding:28px 24px'>"
            . self::cabecera()
            . "<h1 style='margin:0 0 4px;font-size:21px;line-height:1.3;color:" . self::INK . ';'
            . 'font-family:' . self::FONT_TIT . ";font-weight:600;text-align:center'>{$titulo}</h1>"
            . "<p style='margin:0 0 20px;font-size:15px;color:" . self::INK_SOFT . ';'
            . "text-align:center'>{$saludo}</p>"
            . $cuerpo
            . $bloquePie
            . self::pieInstitucional($pieTexto)
            . '</td></tr></table></div>';
    }
}
