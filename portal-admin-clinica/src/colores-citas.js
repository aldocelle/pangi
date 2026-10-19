/* Colores de las citas en la Agenda (oct-2026). Tres modos, a elección de quien
   mira (se recuerda por navegador):
     · por cita: un color distinto en cada una, que dos horas seguidas nunca se
       vean iguales; el color no significa nada;
     · por consulta: cada tipo de consulta con su color fijo;
     · por especialista: cada profesional con el suyo.
   En los tres el estado va ESCRITO en la cita: el color nunca lo distingue.

   Ocho tonos a 45° uno de otro, todos con la misma luz para que ninguno grite
   más que otro. Ocho y no más: con once, los vecinos (verde y menta, dos
   azules) se confundían a simple vista. Mientras atiendan menos de ocho
   especialistas a la vez, dos citas a la misma hora nunca comparten color
   (ver `tramoDe` abajo). El texto es del mismo matiz y muy oscuro: rinde 9,1:1 o más
   sobre su fondo en los ocho (medido el 19-oct-2026 convirtiendo OKLCH a
   sRGB, ya recortado a la gama como lo hace el navegador). No hay rojo puro:
   el rojo es de la línea de «ahora» y de los avisos. */
const TONOS = [240, 150, 20, 195, 285, 65, 330, 110];

/* Misma luz y croma para cualquier tono: el contraste del texto casi no
   depende del matiz (9,1:1 en el peor de los ocho medidos), así que también
   vale para los tonos de los modos fijos de abajo. */
export function colorDeTono(h) {
  return {
    fondo: `oklch(0.93 0.05 ${h})`,
    borde: `oklch(0.80 0.08 ${h})`,
    tinta: `oklch(0.34 0.08 ${h})`,
  };
}

export const COLORES = TONOS.map(colorDeTono);

/* Modos fijos (consulta, especialista): un tono por clave en el orden del
   catálogo, separados por el ángulo áureo. Con ocho tonos fijos, la novena
   consulta repetiría el color de la primera; así cada una cae lo más lejos
   posible de las anteriores, haya las que haya. El orden del catálogo hace que
   «Vacunación» sea del mismo color todos los días. */
const ANGULO_AUREO = 137.508;
export function coloresPorClave(claves) {
  const salida = new Map();
  claves.forEach((k, i) => salida.set(k, colorDeTono(Math.round((240 + i * ANGULO_AUREO) % 360))));
  return salida;
}
export function colorDeRespaldo(clave) {
  return COLORES[indiceBase(clave || '')];
}

// Hash estable del id: la misma cita conserva su color al recargar la agenda.
function indiceBase(id) {
  let h = 0;
  for (const ch of String(id)) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
  return h % COLORES.length;
}

/**
 * Colores para una lista de citas ordenada por hora, agrupada por columna
 * (especialista o día). Una cita no repite el color de la anterior de su
 * columna, ni —si se pasa `tramoDe`— el de ninguna otra que coincida con ella
 * en el tiempo en otra columna: dos «Tom» a las 13:00 con distinto
 * especialista no pueden parecer la misma hora.
 * @param tramoDe (c) => [inicioMs, finMs], o null para no mirar cruces
 * @returns {Map<string, {fondo, borde, tinta}>} id de cita → color
 */
export function coloresPorCita(citas, columnaDe, tramoDe = null) {
  const ultimo = new Map();
  const salida = new Map();
  const asignadas = []; // { a, z, i } de las ya pintadas, para ver cruces
  for (const c of citas) {
    const col = columnaDe(c);
    const [a, z] = tramoDe ? tramoDe(c) : [0, 0];
    const evitar = new Set([ultimo.get(col)]);
    if (tramoDe) for (const o of asignadas) if (o.a < z && o.z > a) evitar.add(o.i);
    const base = indiceBase(c.id);
    let i = base;
    for (let k = 0; k < COLORES.length; k++) {
      const prueba = (base + k) % COLORES.length;
      if (!evitar.has(prueba)) { i = prueba; break; }
    }
    ultimo.set(col, i);
    asignadas.push({ a, z, i });
    salida.set(c.id, COLORES[i]);
  }
  return salida;
}

/* Estados escritos, no solo pintados: el color de la cita ya no los distingue. */
export const ESTADO_VISIBLE = {
  Agendada: { texto: 'Por confirmar', icono: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z' },
  Confirmada: { texto: 'Confirmada', icono: 'M20 6 9 17l-5-5' },
  Completada: { texto: 'Atendida', icono: 'M2 13l4 4L16 7M10 17l1 1L22 7' },
  Cancelada: { texto: 'Cancelada', icono: 'M18 6 6 18M6 6l12 12' },
  Expirada: { texto: 'Expirada', icono: 'M12 7v5l3 2M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z' },
};
