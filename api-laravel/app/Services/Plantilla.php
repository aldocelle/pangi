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
 *   5. firma        — la pone `pagina()`; no se escribe a mano.
 *
 * Íconos de las filas, para que signifiquen lo mismo en todos los correos:
 * 🐾 paciente · 🩺 servicio/motivo · 📅 fecha · 📍 lugar · 👤 persona ·
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

    /** El título de una sección dentro del cuerpo («Qué esperar», «¿Necesitas cambiar la hora?»). */
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
    public static function enlaceAlterno(string $enlace, string $intro = 'Si el botón no funciona, copia este enlace:'): string
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

    /** El recuadro «Qué esperar», con sus filas ya armadas. */
    public static function bloqueQueEsperar(string $items): string
    {
        return self::subtitulo('Qué esperar')
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

    /**
     * El `.panel`: la tarjeta blanca redondeada sobre el fondo azul claro.
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
            . "<h1 style='margin:0 0 4px;font-size:21px;line-height:1.3;color:" . self::INK . ';'
            . 'font-family:' . self::FONT_TIT . ";font-weight:600;text-align:center'>{$titulo}</h1>"
            . "<p style='margin:0 0 20px;font-size:15px;color:" . self::INK_SOFT . ';'
            . "text-align:center'>{$saludo}</p>"
            . $cuerpo
            . $bloquePie
            . "<p style='margin:20px 0 0;padding-top:16px;border-top:1px solid " . self::LINE . ';'
            . 'font-size:13px;color:' . self::INK_SOFT . ";text-align:center'>{$pieTexto}</p>"
            . '</td></tr></table></div>';
    }
}
