# Imágenes

Todas las imágenes de la marca y de la clínica son de PANGI, una clínica
ficticia, y están **generadas con IA**: el equipo son veterinarios animales y la
recepción es una visualización. Ninguna muestra personas ni lugares reales.

## Dónde está cada una

| Archivo | Medida | Dónde se ve |
|---|---|---|
| `logo.webp` | 1024 × 1024 | Login del panel y del portal, registro |
| `isotipo.webp`, `favicon.png`, `apple-touch-icon.png` | 512 / 64 / 180 | Encabezado, barras laterales, chat, pestaña del navegador |
| `equipo/<especialista>.webp` | 800 × 800 | Sitio → Equipo · Portal → elegir especialista · Panel → Especialistas |
| `portada-inicio.webp` | 1920 × 940 | Sitio → Inicio (portada) · Portal → reserva (fondo) |
| `portada-servicios.webp` | 1920 × 940 | Sitio → Servicios (portada) |
| `portada-contacto.webp` | 1920 × 940 | Sitio → Contacto (portada) |
| `equipo.webp` | 1920 × 1080 | Sitio → Equipo (portada) |
| `quienes-somos.webp` | 1086 × 1448 | Sitio → Inicio, «Un equipo, una sola ficha» |
| `medicina-preventiva.webp` | 1200 × 960 | Sitio → Inicio y Servicios, bloque destacado |
| `contacto.webp` | 1400 × 1120 | Sitio → Contacto, junto al formulario |
| `recepcion.webp` | 1600 × 1000 | Portal → login y registro (fondo) |
| `og-image.jpg` | 1200 × 630 | Vista previa al compartir el enlace del sitio |

## Retratos del equipo

| Animal | Especialista | Archivo |
|---|---|---|
| Puma (la mascota del logo) | Dra. Marta Soto — directora médica | `marta-soto.webp` |
| Zorro | Dr. Tomás Fuentes — cirugía | `tomas-fuentes.webp` |
| Conejo | Dra. Carla Rivas — dermatología | `carla-rivas.webp` |
| Gata gris | Dra. Javiera Paredes — medicina felina | `javiera-paredes.webp` |
| Lechuza | Dr. Ignacio Morales — imagenología | `ignacio-morales.webp` |
| Golden | Dra. Valentina Rojas — medicina general y laboratorio | `valentina-rojas.webp` |
| Border collie | Camila Herrera — peluquería | `camila-herrera.webp` |

Para cambiar uno: reemplazar el archivo en `public/equipo/` de los tres
frontends. Para sumar un especialista: copiar su foto ahí con el nombre en
minúsculas y sin tildes (`nombre-apellido.webp`) y agregar la línea al mapa
`RETRATOS` de `website/src/views/EquipoView.vue`,
`portal-reservas/src/views/ReservarView.vue` y
`portal-admin-clinica/src/retratos.js`.

El símbolo del logo traía un hueco de transparencia sobre el azul (arriba a la
derecha); en `isotipo.webp` ya está corregido.
