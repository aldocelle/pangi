/* Cómo se DIBUJA una respuesta del chat. Solo presentación: no decide nada.
 *
 * POR QUÉ EXISTE. El backend contesta texto plano (`RespuestaDirecta.php`,
 * `AsistenteReservas.php`), y pintado tal cual se leía como un muro: la lista
 * de valores eran doce renglones de «Nombre: Lunes a viernes: $X · Sábado: $Y»
 * partidos a media frase en un panel de 370px, y «Reservar una hora» eran doce
 * botones de ancho completo, uno debajo del otro.
 *
 * POR QUÉ ACÁ Y NO UN CAMPO NUEVO EN LA API. Un campo estructurado obligaría a
 * desplegar backend y frontend juntos, y viejo contra nuevo se rompería. Esto
 * lee las formas que el backend YA escribe, y lo que no reconoce lo deja como
 * párrafo: si mañana cambia una frase, el chat se ve como antes, nunca vacío.
 *
 * Nada de v-html: devuelve bloques y trozos, y el template los pinta como texto.
 */

const MONTO = /(\$\d{1,3}(?:\.\d{3})*)/;

/* «$15.000» en negrita dentro de un texto: trozos {t, monto}. */
export function conMontos(s) {
  return String(s)
    .split(MONTO)
    .filter((t) => t !== '')
    .map((t) => ({ t, monto: MONTO.test(t) && /^\$/.test(t) }));
}

/* Las dos tarifas por día, en las tres formas que escribe el backend:
 *   «Lunes a viernes: $15.000 · Sábado: $20.000»   (precio_detalle)
 *   «$15.000 (sábado $20.000)»                     (listaDePrecios sin detalle)
 *   «$15.000 de lunes a viernes y $20.000 el sábado» (cuerpoPrecio sin detalle) */
function tarifasPorDia(v) {
  const m = v.match(/^Lunes a viernes:\s*(\$[\d.]+)\s*·\s*Sábado:\s*(\$[\d.]+)\.?$/i)
    || v.match(/^(\$[\d.]+)\s*\(sábado\s*(\$[\d.]+)\)\.?$/i)
    || v.match(/^(\$[\d.]+) de lunes a viernes y (\$[\d.]+) el sábado\.?$/i);
  return m ? [{ dia: 'Lun a vie', monto: m[1] }, { dia: 'Sábado', monto: m[2] }] : null;
}

/* «Nombre: valor». El nombre no lleva «$» ni pasa de ~6 palabras: así «Estamos
   en Av. Principal: …» o una frase con dos puntos no se confunden con un precio. */
function lineaDePrecio(linea) {
  const l = linea.replace(/^•\s*/, '');
  const i = l.indexOf(': ');
  if (i < 1) return null;
  const nombre = l.slice(0, i).trim();
  const valor = l.slice(i + 2).trim().replace(/\.$/, '');
  if (nombre.includes('$') || nombre.split(/\s+/).length > 6) return null;
  const dias = tarifasPorDia(valor);
  // Sin tarifas por día, solo es precio si el valor habla de plata o de cómo se cobra.
  if (!dias && !/\$|valor|depende|abono|evaluaci/i.test(valor)) return null;
  return { nombre, dias, valor: dias ? null : valor };
}

/* Texto → bloques: {tipo:'p', trozos} · {tipo:'precios', items} · {tipo:'lista', titulo, items} · {tipo:'nota', trozos} */
export function bloques(texto) {
  const lineas = String(texto || '').split('\n').map((l) => l.trim()).filter(Boolean);
  const out = [];

  for (const linea of lineas) {
    // «Atendemos: A, B, C.» — el catálogo en una frase.
    const cat = linea.match(/^(Atendemos):\s*(.+?)\.?$/);
    if (cat && cat[2].split(',').length >= 3) {
      out.push({ tipo: 'lista', titulo: 'Atendemos', items: cat[2].split(',').map((s) => s.trim()).filter(Boolean) });
      continue;
    }

    const esViñeta = linea.startsWith('•');
    const precio = lineaDePrecio(linea);
    // Un renglón suelto de precio solo cuenta si trae tarifas por día; si no,
    // «Radiología: Desde $55.000. Se abonan…» es un párrafo y se lee mejor así.
    if (precio && (esViñeta || precio.dias)) {
      const previo = out[out.length - 1];
      if (previo && previo.tipo === 'precios') previo.items.push(precio);
      else out.push({ tipo: 'precios', items: [precio] });
      continue;
    }

    // El cierre de la lista de valores es una indicación, no contenido.
    if (/^Pregúntame por /.test(linea)) {
      out.push({ tipo: 'nota', trozos: conMontos(linea) });
      continue;
    }

    out.push({ tipo: 'p', trozos: conMontos(esViñeta ? linea.replace(/^•\s*/, '') : linea) });
  }
  return out;
}

/* Acciones → grupos con su forma:
 *   horas    «[Servicio · ]mar 15 · 10:00 · Dra. X» con `hora=` en la ruta → grilla
 *   opciones más de dos destinos sin hora (elegir consulta) → chips que fluyen
 *   principal una o dos acciones directas → botón
 *   ver-mas  «Ver todas las horas de …» → enlace secundario, al final */
export function gruposDeAcciones(acciones) {
  const lista = Array.isArray(acciones) ? acciones : [];
  const horas = [];
  const verMas = [];
  const resto = [];

  for (const a of lista) {
    const partes = String(a.texto || '').split(' · ');
    if (/[?&]hora=/.test(a.ruta || '') && partes.length >= 3) {
      const [dia, hora, quien] = partes.slice(-3);
      horas.push({ ...a, dia, hora, quien, servicio: partes.slice(0, -3).join(' · ') || null });
    } else if (/^Ver todas/i.test(a.texto || '')) {
      verMas.push(a);
    } else {
      resto.push(a);
    }
  }

  const grupos = [];
  if (horas.length) grupos.push({ tipo: 'horas', items: horas });
  if (resto.length > 2) grupos.push({ tipo: 'opciones', items: resto });
  else if (resto.length) grupos.push({ tipo: 'principal', items: resto });
  if (verMas.length) grupos.push({ tipo: 'ver-mas', items: verMas });
  return grupos;
}
