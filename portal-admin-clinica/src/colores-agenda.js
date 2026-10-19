import { ref, reactive, watch } from 'vue';
import { coloresPorCita, coloresPorClave, colorDeRespaldo } from './colores-citas.js';

/* El color de cada cita tiene que ser EL MISMO en la Agenda y en Reservas: si
   en el calendario Kuyen es azul, en la lista también. Por eso el cálculo vive
   acá y no en cada pantalla, y depende solo de cosas que las dos ven igual:
     · el modo elegido (compartido: cambiarlo en una pantalla cambia la otra);
     · el catálogo completo de consultas y especialistas, en su orden;
     · en el modo «por cita», TODAS las citas de cada día, sin filtros. Un
       filtro no puede cambiar colores: si no, la misma cita saldría de un
       color con el filtro puesto y de otro sin él. */

export const MODOS_COLOR = [
  { id: 'cita', label: 'Uno distinto por cita' },
  { id: 'consulta', label: 'Por tipo de consulta' },
  { id: 'especialista', label: 'Por especialista' },
];

function leerModo() {
  try {
    const v = localStorage.getItem('agenda.colores');
    if (MODOS_COLOR.some((m) => m.id === v)) return v;
  } catch { /* sin almacenamiento: el modo por omisión */ }
  return 'cita';
}
// Preferencia de quien mira, por navegador: recepción suele querer el día por
// consulta; un veterinario, por especialista.
export const modoColor = ref(leerModo());
watch(modoColor, (v) => { try { localStorage.setItem('agenda.colores', v); } catch { /* nada */ } });

/* Catálogo para los modos fijos. Los especialistas activos van primero: así les
   tocan los tonos más separados entre sí, y los 54 códigos inactivos del
   legado (ACVet) quedan al final. */
export const catalogoColores = reactive({ servicios: [], especialistas: [] });
export function fijarCatalogoColores({ servicios, especialistas }) {
  if (servicios) catalogoColores.servicios = servicios.map((s) => s.id);
  if (especialistas) {
    catalogoColores.especialistas = [
      ...especialistas.filter((e) => e.activo), ...especialistas.filter((e) => !e.activo),
    ].map((e) => e.id);
  }
}

const SIN = '__sin__';
const apagada = (c) => c.estado === 'Cancelada' || c.estado === 'Expirada';
function tramo(c) {
  const a = new Date(c.fecha_hora).getTime();
  return [a, a + (c.duracion_min || 30) * 60000];
}

/** La clave de color de una cita en un modo fijo (consulta o especialista). */
export function claveDeColor(c, modo = modoColor.value) {
  return modo === 'consulta' ? (c.servicio_id || c.servicio || SIN) : (c.especialista?.id || c.especialista_id || SIN);
}

/**
 * Colores de un conjunto de citas que trae DÍAS COMPLETOS (todas las del día,
 * de cualquier estado y sin filtrar). Devuelve id de cita → color.
 */
export function colorearCitas(citas, modo = modoColor.value) {
  if (modo !== 'cita') {
    const tabla = coloresPorClave(modo === 'consulta' ? catalogoColores.servicios : catalogoColores.especialistas);
    return new Map(citas.map((c) => [c.id, tabla.get(claveDeColor(c, modo)) || colorDeRespaldo(claveDeColor(c, modo))]));
  }
  // Por cita: día por día, en orden de hora. Las canceladas se dibujan sin
  // color y no entran en la cuenta de «no repetir el de al lado».
  const porDia = new Map();
  for (const c of citas) {
    const d = c.fecha_hora.slice(0, 10);
    if (!porDia.has(d)) porDia.set(d, []);
    porDia.get(d).push(c);
  }
  const salida = new Map();
  for (const lista of porDia.values()) {
    const vivas = lista.filter((c) => !apagada(c))
      .sort((a, b) => a.fecha_hora.localeCompare(b.fecha_hora) || String(a.id).localeCompare(String(b.id)));
    for (const [id, color] of coloresPorCita(vivas, (c) => c.especialista?.id || c.especialista_id || SIN, tramo)) {
      salida.set(id, color);
    }
    for (const c of lista) if (!salida.has(c.id)) salida.set(c.id, colorDeRespaldo(c.id));
  }
  return salida;
}
