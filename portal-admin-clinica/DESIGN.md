# Paleta y tipografía — Panel de la clínica

> Extraído de `CLAUDE.md` §12 — es referencia de diseño, no de arquitectura,
> igual que `website/DESIGN.md`.

## Hover: siempre con el acento, nunca gris neutro

El token es `--accent-hover` cuando el fondo es la ÚNICA señal (fila de tabla,
resultado del buscador global, celda de la Agenda, menú de Ajustes) y
`--accent-tint` cuando ya hay otra —un borde que cambia, un texto que se tiñe.
La diferencia importa: `--accent-tint` da **1.04** de contraste contra la
página y **1.10** contra una tarjeta blanca, o sea que solo funciona de
acompañante; `--accent-hover` da 1.24 y el texto encima conserva 12.2, así que
al apuntar la fila **se lee mejor**. Y hover ≠ activo: si comparten fondo y
color, apuntar cualquier cosa la hace parecer la elegida — le pasaba al menú
de Ajustes.

La regla decía antes `--accento`/`--acento-suave`, de la paleta naranja
anterior: esas variables **no existen desde que el panel pasó al azul**, y las
seis referencias que quedaron estuvieron pintando su respaldo naranja
hardcodeado — incluido el horario seleccionado al reagendar una cita. Lo mismo
pasaba con `--muted` e `--info`. Lo vigila ahora `tests/test_legibilidad_frontends.py`,
que falla si el panel usa una `var(--x)` que `app.css` no declara: un respaldo
es para cuando la variable todavía no llegó, no para reemplazar a una que ya
no existe. La única excepción es `--tono`, que el template asigna en línea por
grupo.

## Escala tipográfica de seis pasos, y un piso que no se cruza

`portal-admin-clinica/src/assets/app.css` define `--t-micro` (0.8rem) …
`--t-2xl` (1.4rem). `--t-micro` es el **piso**: nada que lleve información baja
de ahí. Llegó a haber 30 tamaños distintos inventados vista por vista, 82 de
ellos bajo el piso (el peor, 0.66rem = 10.6px, en el título de grupo del menú).

Los **glifos** —un emoji, un chevron, la inicial de un avatar, la «x» de un
modal— quedan fuera de la escala a propósito: dimensionan una caja, no una
línea de texto. Meterlos en la escala habría hecho saltar íconos sin razón.

El mismo test cubre **el panel y el portal de reservas** —las dos apps
salieron de la misma plantilla y arrastraban los mismos defectos, y sin build
compartido un arreglo en una no llega a la otra— y fija el piso, más que
ninguna vista redecore por su cuenta `.tabla`/`.t-row`/`.t-head`/`.tabla-datos`/
`.peligro`: los estilos scoped ganan por especificidad, así que una copia local
deja a esa vista fuera de todo arreglo hecho en el archivo común. Ya pasó dos
veces.
