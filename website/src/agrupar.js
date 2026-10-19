/* Consultas que son variantes de la misma prestación.
 *
 * El catálogo trae `servicios.grupo` (ver backend/migrate_grupo_servicios.py).
 * Las que lo comparten son la MISMA cosa para quien mira el sitio —hoy la
 * consulta dermatológica y su control— y aquí se muestran una sola vez, con el
 * nombre del grupo. La diferencia entre ellas es el precio y cuándo
 * corresponde cada una, y eso se decide reservando, no leyendo una lista.
 *
 * La clínica lo pidió explícitamente: el control no se lista en otro sitio.
 *
 * Un grupo de UNA sola consulta no se agrupa: pasaría si se desactivara una de
 * las dos, y entonces esconder su nombre detrás de una etiqueta genérica sería
 * peor que mostrarlo.
 *
 * Duplicado a mano en cada frontend a propósito: no hay build compartido
 * (CLAUDE.md §9.5). Dentro del sitio sí se comparte, y por eso vive acá y no
 * copiado en ServiciosView y TheFooter.
 */
export function agruparServicios(servicios) {
  const salida = [];
  const vistos = new Set();
  for (const s of servicios) {
    if (!s.grupo) { salida.push(s); continue; }
    if (vistos.has(s.grupo)) continue;
    vistos.add(s.grupo);
    const miembros = servicios.filter((x) => x.grupo === s.grupo);
    if (miembros.length < 2) { salida.push(s); continue; }
    // Se conserva el id y la descripción del primero —el de menor `orden`, que
    // es la consulta y no el control— para que la fila siga teniendo su texto
    // y su `key`. Solo cambia el nombre visible.
    salida.push({ ...s, nombre: s.grupo, esGrupo: true });
  }
  return salida;
}
