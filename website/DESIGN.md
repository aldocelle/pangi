---
name: Sitio público de la clínica
description: Landing cálida y con pulso real para una clínica veterinaria.
colors:
  azul-profundo: "#26326E"
  turquesa-vivo: "#0AA7B8"
  turquesa-medio: "#087C8A"
  turquesa-profundo: "#056773"
  azul-oscuro: "#1A2350"
  fondo-suave: "#E6F7F9"
  blanco: "#FFFFFF"
  texto-secundario: "#4A4A4A"
  borde: "#DDE5ED"
  whatsapp-verde: "#25D366"
typography:
  display:
    fontFamily: "Poppins, 'Open Sans', system-ui, sans-serif"
    fontSize: "clamp(2.05rem, 4.9vw, 3.2rem)"
    fontWeight: 700
    lineHeight: 1.12
    letterSpacing: "-0.025em"
  headline:
    fontFamily: "Poppins, 'Open Sans', system-ui, sans-serif"
    fontSize: "clamp(1.9rem, 4.4vw, 2.7rem)"
    fontWeight: 600
    lineHeight: 1.15
    letterSpacing: "-0.01em"
  body:
    fontFamily: "'Open Sans', system-ui, -apple-system, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: 1.6
  lead:
    fontFamily: "'Open Sans', system-ui, -apple-system, sans-serif"
    fontSize: "clamp(1.05rem, 2vw, 1.22rem)"
    fontWeight: 400
    lineHeight: 1.55
rounded:
  sm: "8px"
  md: "14px"
  lg: "22px"
  xl: "28px"
  full: "999px"
components:
  button-primary:
    backgroundColor: "{colors.turquesa-medio}"
    textColor: "{colors.blanco}"
    rounded: "{rounded.full}"
    padding: "0.8em 1.6em"
  button-primary-hover:
    backgroundColor: "{colors.turquesa-profundo}"
    textColor: "{colors.blanco}"
    rounded: "{rounded.full}"
  cta-card:
    backgroundColor: "{colors.blanco}"
    textColor: "{colors.azul-profundo}"
    rounded: "{rounded.lg}"
    padding: "1.3rem 1.4rem 1.5rem"
  cta-card-icon:
    backgroundColor: "{colors.fondo-suave}"
    textColor: "{colors.azul-profundo}"
    rounded: "{rounded.md}"
    size: "42px"
---

# Design System: sitio público de la clínica

## 1. Overview

**Creative North Star: "Cuidado con Pulso"**

El sitio existe para tranquilizar a alguien que está preocupado por su mascota y convertir a alguien que solo necesita una hora de rutina, y las dos cosas piden lo mismo: sentirse atendido por personas reales, no por una plantilla. Todo el sistema gira sobre dos decisiones tomadas ya en el código, no en la teoría: el color de marca es un azul profundo de confianza con un turquesa clínico como acento, nunca al revés; y el movimiento del sitio se ancla a un latido real de 833ms (72 pulsaciones por minuto), documentado en `app.css` como "la firma del sitio" — todo lo que pulsa (el corazón del pie, el punto de "Abierto ahora", el destello del botón de reserva) comparte ese tempo exacto, porque una clínica veterinaria mide signos vitales de verdad.

El sistema rechaza explícitamente la plantilla de PyME genérica: sin grillas de tarjetas idénticas con ícono arriba, sin eyebrows uppercase-tracked repetidos sobre cada sección (ver Named Rule en Tipografía), sin frialdad corporativa ni blanco-hospital. La calidez se construye con fotos reales a sangre, jerarquía tipográfica marcada y micro-interacciones con propósito — nunca con emojis ni iconografía de stock.

**Key Characteristics:**
- Azul profundo + turquesa clínico, nunca naranja ni paleta genérica de mascotas.
- Todo lo que "late" se mueve en múltiplos de 833ms; nada de easing rebotado.
- Fotos reales full-bleed en cada hero, no ilustraciones de stock.
- Reveal-on-scroll discreto (10px, nunca un salto), con `[data-glow]` opt-in solo donde hay superficie que lo justifique.
- El turquesa vivo (#0AA7B8) es luz y relleno, jamás texto: falla contraste por diseño y el sistema lo sabe.

## 2. Colors

Dos familias, cada una con un trabajo fijo: azul profundo para autoridad y texto; turquesa clínico, en tres escalones de intensidad, para acción e interacción. El verde de WhatsApp es marca ajena, tolerada solo donde integra con ese canal.

### Primary
- **Azul Profundo** (#26326E): color de marca — encabezados, texto principal, superficies oscuras del hero y del cierre. Hace doble función de "primary" y de "ink": es el mismo tono tanto en títulos como en cuerpo de texto sobre fondo claro.
- **Azul Oscuro** (#1A2350): sombra tonal del azul profundo. Fondos de hero antes de que cargue la foto, veladuras direccionales, y la sombra de las tarjetas que flotan sobre una banda ya azul (ahí una sombra del mismo tono que `--shadow` se funde con el fondo; ésta, más oscura, sí se distingue).

### Secondary
- **Turquesa Vivo** (#0AA7B8): el acento más saturado. Solo relleno y luz — resplandores, halos, destellos de botón. **Nunca lleva texto encima**: mide 2.90:1 sobre blanco, por debajo del 4.5:1 que exige AA.
- **Turquesa Medio** (#087C8A): el turquesa que sí es interactivo — texto de enlace, fondo de botón primario, íconos accionables sobre fondo claro. 4.93:1 sobre blanco.
- **Turquesa Profundo** (#056773): un escalón más oscuro. Hover de superficies sólidas (el turquesa medio al pasar el cursor) y texto sobre el fondo suave tintado.

### Neutral
- **Blanco** (#FFFFFF): superficie base — tarjetas, fondos de formulario, fondo del body.
- **Fondo Suave** (#E6F7F9): franjas alternas entre secciones blancas, y fondo de íconos en reposo (tinte de marca, no gris).
- **Texto Secundario** (#4A4A4A): cuerpo de texto de apoyo, bajadas, descripciones — nunca el titular.
- **Borde** (#DDE5ED): filetes de input, separadores de lista.

### Borrowed brand
- **WhatsApp Verde** (#25D366): no es parte de esta identidad, es la marca de un canal externo. Vive solo en botones/íconos que llevan a WhatsApp de verdad. Ninguno de los dos verdes del sistema (relleno o su hover) admite texto blanco encima (1.98:1 y 2.30:1) — el botón de contacto usa Azul Oscuro sobre el verde, que sí cumple (7.56:1).

### Named Rules
**La Regla del Turquesa Decorativo.** Turquesa Vivo (#0AA7B8) es luz, nunca letra. Si necesitas texto o un botón con letra encima, baja un escalón a Turquesa Medio.

**La Regla de la Sombra que Combina.** Una sombra tintada en azul (`--shadow`) sobre una superficie YA azul del mismo tono se vuelve invisible. Sobre fondos de marca oscuros, la sombra usa Azul Oscuro, no el tono de sombra por defecto.

## 3. Typography

**Display/Headline Font:** Poppins (con 'Open Sans', system-ui como respaldo)
**Body Font:** Open Sans (con system-ui, -apple-system como respaldo)

**Character:** Poppins aporta la calidez geométrica y la personalidad de los titulares; Open Sans es la voz neutra y legible del cuerpo, pensada para lectura larga (FAQ, formularios, descripciones de servicio). El contraste es de peso real entre niveles — nunca "todo en 700".

### Hierarchy
- **Display** (700, `clamp(2.05rem, 4.9vw, 3.2rem)`, interlineado 1.12): titulares de hero y de sección que abren página (`.titulo-hero`). Interletrado cerrado (-0.025em); la segunda línea, en un color de énfasis, es la firma visual de todo hero.
- **Headline** (600, `clamp(1.9rem, 4.4vw, 2.7rem)`, interlineado 1.15): títulos de sección estándar (`.seccion-titulo h2`).
- **Title** (700, ~1.02rem): títulos de componente — nombre de tarjeta, nombre de fila de servicio.
- **Body** (400, 16px, interlineado 1.6): cuerpo general. La bajada de sección usa una escala propia, `clamp(1.05rem, 2vw, 1.22rem)` a 1.55 de interlineado, ligeramente mayor que el cuerpo base — nunca supera 56-65ch de ancho.
- **Label** (600, ~0.9-0.95rem): etiquetas de campo de formulario, nombres cortos en listas de confianza.

### Named Rules
**La Regla del Cero Eyebrow.** Nada de kickers uppercase-tracked repetidos sobre cada sección ("ABOUT", "SERVICIOS", "PROCESO"). El propio código lo marca como "el primer tell de landing genérica" — cada sección se identifica por su titular real, no por una etiqueta decorativa encima.

**La Regla del Negrita-Gancho.** En texto de cuerpo con función de venta (bajadas de tarjeta, subtítulos de hero), la frase que abre en negrita es el beneficio, no la descripción: "**Sin el estrés del traslado:** la veterinaria atiende a tu mascota..." — nunca al revés.

## 4. Elevation

El sistema es mayormente plano: superficies blancas o tintadas sin sombra en reposo. La sombra aparece solo donde hay una superficie real flotando sobre otra — nunca como decoración en cada tarjeta. Las sombras están tintadas en azul de marca (rgba sobre `26, 35, 80` o `38, 50, 110`), nunca en gris o negro neutro — la única excepción es una superficie que ya comparte ese mismo tono azul de fondo (ver Regla de la Sombra que Combina en Colores).

### Shadow Vocabulary
- **Ambient** (`box-shadow: 0 8px 30px rgba(38, 50, 110, 0.08)`): elevación estándar, sutil — mapa de Contacto, tarjeta de formulario, fila de servicio al pasar el cursor.
- **Ambient Lg** (`box-shadow: 0 16px 50px rgba(38, 50, 110, 0.14)`): elevación marcada — fotos destacadas, tarjetas sobre fondo claro que necesitan pesar más.
- **Sobre banda de marca** (`box-shadow: 0 10px 28px color-mix(in oklab, #1A2350 45%, transparent)`, hover `60%`): la variante que sí se distingue cuando la tarjeta blanca flota sobre la propia banda azul del sitio (cierre con tarjetas de varias vías de atención).

### Named Rules
**La Regla del Reposo Plano.** Nada tiene sombra por defecto salvo lo que de verdad está elevado sobre otra superficie. El hover puede sumar o intensificar sombra; el reposo, casi nunca.

## 5. Components

### Buttons
- **Shape:** píldora completa (`border-radius: 999px`).
- **Primary:** fondo Turquesa Medio, texto blanco, sombra de color propio (`0 8px 22px rgba(10,167,184,0.32)`). Padding `0.8em 1.6em`.
- **Hover / Focus:** el fondo pasa a Turquesa Profundo, se eleva 2px (`translateY(-2px)`) y la sombra crece. Foco vía teclado: aro turquesa de 2px, con 3px de separación — nunca el borde por defecto del navegador.
- **Outline (sobre fondo oscuro):** borde blanco de 2px, texto blanco; al hover se rellena de blanco y el texto pasa a Azul Profundo.

### Cards
- **Corner Style:** 22px (`--r-lg`), consistente en toda tarjeta del sitio.
- **Background:** blanco sobre fondo claro o tintado; blanco también sobre la banda azul del cierre (nunca tarjeta translúcida sobre fondo oscuro — ver Regla de la Sombra que Combina).
- **Shadow Strategy:** ver Elevación. En reposo, sombra ambient; al hover, se eleva (`translateY(-3px)` a `-4px`) y la sombra crece un escalón.
- **Internal Padding:** ~1.3rem–1.5rem, con el ícono de la tarjeta en una insignia de 42px con esquinas `--r-md` (14px), tinte de marca en reposo y acento sólido al hover.
- **Regla explícita del sistema:** las tarjetas NO son el recurso por defecto para listar contenido repetido (ver Do's/Don'ts) — se reservan para un número corto de opciones genuinamente distintas (2-3), cada una con su propio contexto, no para listas largas de servicios.

### Fila (fila de lista con ícono) — componente de firma
El patrón por defecto para listar servicios/especialidades: no es una tarjeta, es una fila completa clicable con ícono a la izquierda, título + descripción al centro y una flecha que solo aparece al hover, desplazándose desde -6px hasta su posición. Fondo transparente en reposo; al hover, fondo claro + sombra ambient + el ícono pasa de tinte de marca a acento sólido. Este patrón reemplaza deliberadamente a la grilla de tarjetas idénticas para listas de 4 o más ítems.

### Inputs / Fields
- **Style:** fondo blanco (no tintado — un campo con fondo tintado se leía "deshabilitado"), filete de 1.5px en Borde (#DDE5ED), esquinas `--r-md`.
- **Focus:** el filete pasa a Turquesa Medio y aparece un halo de 4px en el tono suave del acento (`box-shadow: 0 0 0 4px var(--acento-suave)`).
- **Error:** filete en rojo oscuro (#C0392B), mensaje de error en el mismo tono, nunca solo color sin texto.

### Navigation — componente de firma
Pastilla que sigue al cursor entre los enlaces del menú, medida en píxeles reales (no deducida por CSS) porque los enlaces tienen anchos muy distintos. En reposo, la pastilla marca la página activa; al pasar el cursor por otro enlace, se desliza hacia él con la curva de salida compartida del sitio. En el desplegable móvil (columna), el efecto se apaga y vuelve el fondo por ítem individual — la pastilla horizontal no tiene sentido en una lista vertical.

### Social / Ícono circular
Círculo de 44px, tinte de marca en reposo; al hover, un pseudo-elemento circular en el color real de la red (Instagram, Facebook, WhatsApp) crece desde abajo y cubre el ícono, que además escala 1.12x. El color de marca ajena solo aparece en el hover, nunca en reposo.

## 6. Do's and Don'ts

### Do:
- **Do** anclar cualquier animación que "pulse" (puntos de estado, destellos, el corazón del pie) a múltiplos de 833ms — el latido real del sitio, nunca un número de segundos arbitrario.
- **Do** usar Turquesa Medio (#087C8A) para todo texto o botón interactivo; Turquesa Vivo (#0AA7B8) solo como relleno o luz.
- **Do** abrir la bajada de una tarjeta o subtítulo con una frase en negrita que sea el beneficio, no la función.
- **Do** usar fotos reales full-bleed en cada hero — nunca una ilustración o foto de stock.
- **Do** revelar contenido al hacer scroll de forma discreta (10px, nunca oculto si JS no corre).

### Don't:
- **Don't** usar grillas de tarjetas idénticas con ícono arriba como recurso por defecto para listar servicios — es la plantilla de "sitio de PyME hecho en un día" que este proyecto rechaza explícitamente. Usa el patrón de fila (ver Componentes) para 4+ ítems; reserva la tarjeta para 2-3 opciones genuinamente distintas.
- **Don't** poner texto sobre Turquesa Vivo (#0AA7B8) — falla contraste AA (2.90:1) por diseño.
- **Don't** agregar un eyebrow uppercase-tracked repetido sobre cada sección ("ABOUT", "SERVICIOS"...). Es, textualmente, "el primer tell de landing genérica".
- **Don't** usar sombra gris o negra neutra sobre una superficie de marca — tintarla en azul (o en Azul Oscuro si el fondo ya es azul).
- **Don't** dejar el sitio estático o "sin vida": es el diagnóstico explícito que este rediseño vino a corregir.
- **Don't** caer en tono corporativo-frío ni clínico-distante — ni tampoco en genérico de plantilla de agencia. La calidez es de detalle (tipografía, ritmo, micro-interacción), no de cliché (emojis, iconos de stock).
