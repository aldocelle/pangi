/* Retratos del equipo, servidos desde public/equipo/ de este frontend.
 *
 * Se leen del frontend y no del backend a propósito: así una foto viaja con
 * cada despliegue y no depende del disco del servidor.
 *
 * La clave es el nombre sin tratamiento ni tildes, para no depender de cómo
 * esté escrito en la base. Vacío = cada especialista muestra sus iniciales.
 * Para agregar uno: copiar la foto a public/equipo/ y sumar la línea, p. ej.
 *   'marta soto': 'marta-soto.jpg',
 */
const RETRATOS = {
  'marta soto': 'marta-soto.webp',
  'tomas fuentes': 'tomas-fuentes.webp',
  'carla rivas': 'carla-rivas.webp',
  'javiera paredes': 'javiera-paredes.webp',
  'ignacio morales': 'ignacio-morales.webp',
  'valentina rojas': 'valentina-rojas.webp',
  'camila herrera': 'camila-herrera.webp',
};

function clave(nombre) {
  return (nombre || '')
    .replace(/^(Dr|Dra)\.?\s+/i, '')
    .normalize('NFD').replace(/[̀-ͯ]/g, '') // quita tildes
    .toLowerCase().trim();
}

/** Ruta del retrato de un especialista, o null si no tenemos foto suya. */
export function retratoDe(especialista) {
  const archivo = RETRATOS[clave(especialista?.nombre)];
  return archivo ? `${import.meta.env.BASE_URL}equipo/${archivo}` : null;
}
