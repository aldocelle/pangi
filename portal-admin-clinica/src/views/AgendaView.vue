<script setup>
import { ref, computed, reactive, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { api, apiError, urlFoto } from '../api.js';
import { retratoDe } from '../retratos.js';
import { ESTADO_VISIBLE } from '../colores-citas.js';
import { MODOS_COLOR, modoColor, fijarCatalogoColores, colorearCitas, claveDeColor } from '../colores-agenda.js';
import NuevaCitaModal from '../components/NuevaCitaModal.vue';
import ReagendarCitaModal from '../components/ReagendarCitaModal.vue';
import BloqueoModal from '../components/BloqueoModal.vue';
import MiniCalendario from '../components/agenda/MiniCalendario.vue';
import GrillaDia from '../components/agenda/GrillaDia.vue';
import Burbuja from '../components/agenda/Burbuja.vue';
import DetalleCita from '../components/agenda/DetalleCita.vue';

/* ── Agenda (rediseño oct-2026) ──
   A la izquierda, los filtros y el calendario del mes; a la derecha, el día en
   columnas por profesional (o la semana, o el mes). La referencia de
   distribución es la de las agendas de clínica que la recepción ya conoce: una
   columna por persona, la hora fija a la izquierda, lo no disponible en gris. */

/* ── Fechas ──
   Todo en strings ISO (YYYY-MM-DD) y Date locales, sin librería, igual que el
   resto del proyecto (ver CLAUDE.md §9.6: todo asume America/Santiago). */
function iso(d) {
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}
function hoy() {
  return iso(new Date());
}
function aDate(isoStr) {
  const [y, m, d] = isoStr.split('-').map(Number);
  return new Date(y, m - 1, d);
}
function sumarDias(isoStr, n) {
  const d = aDate(isoStr);
  d.setDate(d.getDate() + n);
  return iso(d);
}
function sumarMeses(isoStr, n) {
  const d = aDate(isoStr);
  const dia = d.getDate();
  d.setDate(1);
  d.setMonth(d.getMonth() + n);
  // 31 de enero + 1 mes no es "31 de febrero": se recorta al último día real.
  const ultimo = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
  d.setDate(Math.min(dia, ultimo));
  return iso(d);
}
// La semana de la clínica empieza el lunes (getDay() manda domingo=0).
function inicioSemana(isoStr) {
  const d = aDate(isoStr);
  d.setDate(d.getDate() - ((d.getDay() + 6) % 7));
  return iso(d);
}
function diasEntre(a, b) {
  return Math.round((aDate(b) - aDate(a)) / 86400000);
}

const VISTAS = [
  { id: 'dia', label: 'Día' },
  { id: 'semana', label: 'Semana' },
  { id: 'mes', label: 'Mes' },
];
const ESTADOS = ['Agendada', 'Confirmada', 'Completada', 'Cancelada'];
const DIAS_CORTOS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
const SIN = '__sin__';

const route = useRoute();
const router = useRouter();

// La vista y el día viven en la URL: recargar, compartir el enlace o volver
// con el botón "atrás" del navegador devuelve exactamente lo que se estaba
// mirando, no siempre "hoy en vista de día".
const vistaInicial = VISTAS.some((v) => v.id === route.query.vista) ? route.query.vista : 'dia';
const fechaInicial = /^\d{4}-\d{2}-\d{2}$/.test(route.query.fecha || '') ? route.query.fecha : hoy();

const vista = ref(vistaInicial);
const fecha = ref(fechaInicial);       // día ancla: no cambia al cambiar de vista
const citas = ref([]);
const especialistas = ref([]);
const ventanas = ref({});              // especialista → { día → [{ desde, ultimo_inicio }] }
const bloqueos = ref([]);
const loading = ref(true);             // primera carga: no hay nada que mostrar
const refrescando = ref(false);        // recarga con datos viejos todavía en pantalla
const error = ref('');
const sinDisponibilidad = ref(false);  // /agenda/disponibilidad falló: se ve todo como libre

/* Campos dirigidos, no un cajón de sastre: buscar "luna" en un campo único
   devuelve la paciente Luna, la tutora Luna y la Dra. Luna todas mezcladas.
   Separados, cada uno responde una pregunta sola — mismo criterio que los
   buscadores de Pacientes y Tutores. Se combinan con Y. */
const filtros = reactive({ profesional: '', estado: 'activas', paciente: '', tutor: '', telefono: '' });

/* El panel de filtros se recuerda por navegador (es preferencia de quien está
   sentado ahí, no de la clínica). En pantallas angostas parte cerrado. */
function leerPanel() {
  try {
    const v = localStorage.getItem('agenda.panel');
    if (v !== null) return v === '1';
  } catch { /* sin almacenamiento: se usa el ancho */ }
  return window.innerWidth > 900;
}
const panelAbierto = ref(leerPanel());
watch(panelAbierto, (v) => { try { localStorage.setItem('agenda.panel', v ? '1' : '0'); } catch { /* nada */ } });

const servicios = ref([]);

/* ── Rango que cubre cada vista ──
   El día ancla manda: la semana es la suya, el mes es el suyo. Así cambiar de
   vista no mueve al usuario de sitio, solo abre o cierra el zoom.
   En mes se pide la rejilla completa (semanas enteras, 28–42 días) para que
   los días de relleno del mes anterior/siguiente también muestren sus citas. */
const rango = computed(() => {
  if (vista.value === 'semana') {
    const d0 = inicioSemana(fecha.value);
    return { desde: d0, hasta: sumarDias(d0, 6) };
  }
  if (vista.value === 'mes') {
    const d = aDate(fecha.value);
    const primero = iso(new Date(d.getFullYear(), d.getMonth(), 1));
    const ultimo = iso(new Date(d.getFullYear(), d.getMonth() + 1, 0));
    return { desde: inicioSemana(primero), hasta: sumarDias(inicioSemana(ultimo), 6) };
  }
  return { desde: fecha.value, hasta: fecha.value };
});

const mesAncla = computed(() => fecha.value.slice(0, 7));

// Sin tildes y en minúsculas: "nunez" encuentra a "Núñez", que es como se
// escribe un apellido cuando se busca con prisa en el mesón.
function norm(s) {
  return (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}
// Los teléfonos se comparan solo por dígitos: en la base conviven "+56 9 8765
// 4321", "56987654321" y "9 8765 4321" para el mismo número.
function soloDigitos(s) {
  return (s || '').toString().replace(/\D/g, '');
}

// Lo cargado puede ser más ancho que lo que se está mirando (ver `cubierto`):
// la vista siempre se recorta a su propio rango.
const enRango = computed(() => citas.value.filter((c) => {
  const d = c.fecha_hora.slice(0, 10);
  return d >= rango.value.desde && d <= rango.value.hasta;
}));

function pasaEstado(c) {
  if (filtros.estado === 'todas') return true;
  // «Activas» = las que ocupan o ya ocuparon la hora: sin canceladas ni expiradas.
  if (filtros.estado === 'activas') return c.estado !== 'Cancelada' && c.estado !== 'Expirada';
  return c.estado === filtros.estado;
}

const citasFiltradas = computed(() => {
  const paciente = norm(filtros.paciente.trim());
  const tutor = norm(filtros.tutor.trim());
  const telefono = soloDigitos(filtros.telefono);
  return enRango.value.filter((c) => {
    if (filtros.profesional && (c.especialista?.id || SIN) !== filtros.profesional) return false;
    if (!pasaEstado(c)) return false;
    if (paciente && !norm(`${c.mascota?.nombre || ''} ${c.mascota?.especie || ''}`).includes(paciente)) return false;
    if (tutor && !norm(`${c.dueno?.nombre || ''} ${c.dueno?.email || ''}`).includes(tutor)) return false;
    if (telefono && !soloDigitos(c.dueno?.telefono).includes(telefono)) return false;
    return true;
  });
});

const hayBusqueda = computed(() => !!(filtros.paciente.trim() || filtros.tutor.trim() || filtros.telefono.trim()));
const hayFiltro = computed(() => hayBusqueda.value || !!filtros.profesional || filtros.estado !== 'activas');
function limpiarBusqueda() {
  Object.assign(filtros, { paciente: '', tutor: '', telefono: '' });
}

/* Los especialistas del catálogo son la lista estable. Solo los activos: la
   importación del legado (ACVet) dejó 54 inactivos, uno por cada código de
   médico del sistema anterior, que no son personal real. Se les suman los que
   aparezcan en las citas del rango: si una cita histórica quedó a nombre de
   uno de esos códigos, tiene que poder filtrarse y verse. */
const opcionesEspecialista = computed(() => {
  const map = new Map();
  for (const e of especialistas.value) if (e.activo) map.set(e.id, e);
  for (const c of citas.value) {
    if (c.especialista && !map.has(c.especialista.id)) map.set(c.especialista.id, c.especialista);
  }
  return [...map.values()];
});
const activos = computed(() => especialistas.value.filter((e) => e.activo));

function iniciales(nombre) {
  const p = (nombre || '').replace(/^(Dr|Dra)\.?\s+/i, '').split(' ').filter(Boolean);
  return ((p[0]?.[0] || '') + (p[1]?.[0] || '')).toUpperCase() || '?';
}

/* ── Agrupación por día ── (el backend ya devuelve ordenado por fecha_hora) */
const porDia = computed(() => {
  const map = new Map();
  for (const c of citasFiltradas.value) {
    const dia = c.fecha_hora.slice(0, 10);
    if (!map.has(dia)) map.set(dia, []);
    map.get(dia).push(c);
  }
  return map;
});
function citasDe(dia) {
  return porDia.value.get(dia) || [];
}
const citasDelDia = computed(() => citasDe(fecha.value));

/* Columnas del día: los activos en el orden del catálogo (siempre en el mismo
   lugar, para que recepción no tenga que buscarlos), más quien tenga citas ese
   día sin estar activo, más «Sin especialista» si hace falta. */
const columnas = computed(() => {
  const lista = [];
  const vistos = new Set();
  const agregar = (e) => {
    if (vistos.has(e.id)) return;
    vistos.add(e.id);
    lista.push({
      id: e.id, nombre: e.nombre, especialidad: e.especialidad,
      foto: retratoDe(e) || urlFoto(e.foto_url) || null, iniciales: iniciales(e.nombre),
    });
  };
  for (const e of activos.value) if (!filtros.profesional || filtros.profesional === e.id) agregar(e);
  for (const c of citasDelDia.value) {
    if (c.especialista && (!filtros.profesional || filtros.profesional === c.especialista.id)) agregar(c.especialista);
  }
  if (citasDelDia.value.some((c) => !c.especialista) && (!filtros.profesional || filtros.profesional === SIN)) {
    lista.push({ id: SIN, nombre: 'Sin especialista', especialidad: null, foto: null, iniciales: '—' });
  }
  return lista;
});

const ventanasDelDia = computed(() => {
  const out = {};
  for (const [espId, dias] of Object.entries(ventanas.value)) out[espId] = dias[fecha.value] || [];
  return out;
});
function bloqueosEntre(desde, hasta) {
  // Los que pisan [desde 00:00, hasta + 1 día 00:00).
  const ini = `${desde}T00:00:00`;
  const fin = `${sumarDias(hasta, 1)}T00:00:00`;
  return bloqueos.value.filter((b) => b.inicio < fin && b.fin > ini);
}
const bloqueosDelDia = computed(() => bloqueosEntre(fecha.value, fecha.value));

/* Un solo mapa de colores para las tres vistas, calculado sobre TODO lo
   cargado (días completos, sin filtros): el mismo cálculo que hace Reservas,
   para que una cita tenga el mismo color en las dos pantallas y no cambie al
   filtrar ni al pasar de día a semana. Ver colores-agenda.js. */
const colores = computed(() => colorearCitas(citas.value, modoColor.value));

/* Con colores fijos, la leyenda dice qué es cada uno: solo lo que está a la
   vista, en el orden del catálogo. Por cita no hay nada que explicar. */
const leyendaColores = computed(() => {
  if (modoColor.value === 'cita') return [];
  const visibles = vista.value === 'dia' ? citasDelDia.value : citasFiltradas.value;
  const vistos = new Map();
  for (const c of visibles) {
    const k = claveDeColor(c);
    if (!vistos.has(k)) {
      vistos.set(k, { clave: k, nombre: modoColor.value === 'consulta' ? c.servicio : (c.especialista?.nombre || 'Sin especialista'), color: colores.value.get(c.id) });
    }
  }
  const orden = modoColor.value === 'consulta' ? servicios.value.map((x) => x.id) : opcionesEspecialista.value.map((e) => e.id);
  return [...vistos.values()].sort((a, b) => {
    const ia = orden.indexOf(a.clave); const ib = orden.indexOf(b.clave);
    return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
  });
});

const diasDelRango = computed(() => {
  const n = diasEntre(rango.value.desde, rango.value.hasta);
  return Array.from({ length: n + 1 }, (_, i) => sumarDias(rango.value.desde, i));
});

/* ── Carga ──
   Una sola llamada por rango para las citas y otra para horarios y bloqueos.
   Encadenar flechas dispara varias peticiones a la vez y no siempre vuelven en
   orden: solo la última pedida puede escribir en pantalla. */
let ultimaCarga = 0;
const rangoCargado = ref(null);
const actualizadoEn = ref(null);

// Comparación de strings ISO: YYYY-MM-DD ordena igual como texto que como fecha.
function cubierto(r) {
  return !!rangoCargado.value && rangoCargado.value.desde <= r.desde && rangoCargado.value.hasta >= r.hasta;
}

async function cargar() {
  const token = ++ultimaCarga;
  // Solo la primera carga vacía la pantalla; de ahí en adelante lo que hay se
  // atenúa y se reemplaza en su sitio.
  refrescando.value = !loading.value;
  error.value = '';
  const { desde, hasta } = rango.value;
  const params = { fecha: desde, hasta };
  const [rc, rd] = await Promise.allSettled([
    api.get('/citas/agenda', { params }),
    api.get('/agenda/disponibilidad', { params }),
  ]);
  if (token !== ultimaCarga) return;
  if (rc.status === 'fulfilled') {
    citas.value = rc.value.data;
    rangoCargado.value = { desde, hasta };
    actualizadoEn.value = Date.now();
  } else {
    error.value = apiError(rc.reason, 'No se pudo cargar la agenda.');
  }
  /* Horarios y bloqueos fallan ABIERTO: sin ellos la grilla se ve toda libre,
     pero las citas siguen ahí. Una base a la que todavía no se le corrió
     base/cambios/ no puede dejar la Agenda en blanco. */
  if (rd.status === 'fulfilled') {
    ventanas.value = rd.value.data.ventanas || {};
    bloqueos.value = rd.value.data.bloqueos || [];
    sinDisponibilidad.value = false;
  } else {
    ventanas.value = {};
    bloqueos.value = [];
    sinDisponibilidad.value = true;
  }
  loading.value = false;
  refrescando.value = false;
}
function refrescar() {
  rangoCargado.value = null;
  cargar();
}

/* «Actualizado hace N min»: la agenda se recarga sola cada dos minutos
   mientras la pestaña está a la vista, porque las reservas del portal llegan
   sin que nadie toque nada. */
const reloj = ref(Date.now());
const etiquetaActualizado = computed(() => {
  if (!actualizadoEn.value) return '';
  const min = Math.floor((reloj.value - actualizadoEn.value) / 60000);
  return min < 1 ? 'Actualizado recién' : `Actualizado hace ${min} min`;
});
function recargaSilenciosa() {
  if (document.visibilityState !== 'visible' || modalNueva.value || modalBloqueo.value || citaReagendando.value || burbuja.value) return;
  if (Date.now() - (actualizadoEn.value || 0) >= 110000) refrescar();
}
const intervaloReloj = setInterval(() => { reloj.value = Date.now(); recargaSilenciosa(); }, 30000);
document.addEventListener('visibilitychange', recargaSilenciosa);
onUnmounted(() => {
  clearInterval(intervaloReloj);
  document.removeEventListener('visibilitychange', recargaSilenciosa);
});

/* ── «Sin citas hoy» no es lo mismo que «no hay citas» ──
   En vista de día se pide SOLO ese día, así que la pantalla no puede saber que
   hay horas agendadas más adelante. Cuando el día sale vacío se busca la
   próxima, una sola vez y solo en ese caso, para ofrecer saltar hasta ella. */
const proxima = ref(null);
const buscandoProxima = ref(false);
const DIAS_LOOKAHEAD = 60;

async function buscarProxima() {
  if (buscandoProxima.value) return;
  buscandoProxima.value = true;
  proxima.value = null;
  try {
    const desde = sumarDias(fecha.value, 1);
    const { data } = await api.get('/citas/agenda', {
      params: { fecha: desde, hasta: sumarDias(desde, DIAS_LOOKAHEAD - 1) },
    });
    const viva = data.find((c) => c.estado !== 'Cancelada');
    if (viva) proxima.value = { fecha: viva.fecha_hora.slice(0, 10) };
  } catch {
    // Es información de cortesía: si falla, la pantalla queda como antes.
  } finally {
    buscandoProxima.value = false;
  }
}
watch([citasDelDia, loading, hayFiltro], ([lista, cargando, filtro]) => {
  if (vista.value === 'dia' && !cargando && !filtro && !lista.length) buscarProxima();
  else proxima.value = null;
}, { immediate: true });

/* ── Burbujas: detalle de una cita, de un bloqueo, o qué hacer con un hueco ── */
const burbuja = ref(null); // { tipo: 'cita'|'bloqueo'|'hueco', dato, rect }
const confirmandoQuitar = ref(false);
const quitando = ref(false);
function abrirBurbuja(tipo, dato, rect) {
  confirmandoQuitar.value = false;
  burbuja.value = { tipo, dato, rect };
}
const nombreEspecialista = (id) => opcionesEspecialista.value.find((e) => e.id === id)?.nombre || '';

/* Reagendar desde la agenda: es la pantalla donde la recepción pasa el día,
   así que mover una hora tiene que poder hacerse acá y no solo en Reservas. */
const citaReagendando = ref(null);
function puedeReagendar(c) {
  return !!c.servicio_id && !!(c.especialista_id || c.especialista?.id) && c.estado !== 'Cancelada';
}
function reagendar(c) {
  burbuja.value = null;
  citaReagendando.value = c;
}
async function alReagendar() {
  citaReagendando.value = null;
  refrescar();
}

async function cambiarEstado(cita, estado) {
  if (estado === cita.estado) return;
  if (estado === 'Cancelada' && !confirm(`¿Cancelar la cita de ${cita.mascota.nombre} a las ${hora(cita.fecha_hora)}?`)) {
    burbuja.value = { ...burbuja.value };   // vuelve a pintar el select con el estado de antes
    return;
  }
  try {
    await api.patch(`/citas/${cita.id}/estado`, { estado });
    cita.estado = estado;
  } catch (e) {
    error.value = apiError(e, 'No se pudo actualizar el estado.');
  }
}

async function quitarBloqueo(b) {
  quitando.value = true;
  try {
    await api.delete(`/agenda/bloqueos/${b.id}`);
    burbuja.value = null;
    refrescar();
  } catch (e) {
    error.value = apiError(e, 'No se pudo quitar el bloqueo.');
  } finally {
    quitando.value = false;
  }
}

/* ── Crear: cita o bloqueo, desde la cabecera o desde un hueco ── */
const modalNueva = ref(null);   // { fecha, hora, especialistaId }
const modalBloqueo = ref(null); // { fecha, hora, especialistaId }
function nuevaCita(desde = {}) {
  burbuja.value = null;
  modalNueva.value = { fecha: desde.fecha || fecha.value, hora: desde.hora || '', especialistaId: desde.especialistaId || '' };
}
function nuevoBloqueo(desde = {}) {
  burbuja.value = null;
  modalBloqueo.value = {
    fecha: desde.fecha || fecha.value, hora: desde.hora || '',
    especialistaId: desde.especialistaId || (filtros.profesional !== SIN ? filtros.profesional : ''),
  };
}
async function alCrearCita() {
  modalNueva.value = null;
  refrescar();
}
function alCrearBloqueo(_b, { cerrar }) {
  if (cerrar) modalBloqueo.value = null;
  refrescar();
}

/* ── Etiquetas ── */
function hora(dt) {
  return new Date(dt).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false });
}
function labelFecha(isoStr) {
  return aDate(isoStr).toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long' });
}
function numeroDia(isoStr) {
  return aDate(isoStr).getDate();
}
function esDeOtroMes(isoStr) {
  return isoStr.slice(0, 7) !== mesAncla.value;
}
const labelRango = computed(() => {
  if (vista.value === 'dia') return aDate(fecha.value).toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  if (vista.value === 'mes') return aDate(fecha.value).toLocaleDateString('es-CL', { month: 'long', year: 'numeric' });
  const a = aDate(rango.value.desde);
  const b = aDate(rango.value.hasta);
  const mismoMes = a.getMonth() === b.getMonth() && a.getFullYear() === b.getFullYear();
  const ini = mismoMes ? a.getDate() : a.toLocaleDateString('es-CL', { day: 'numeric', month: 'long' });
  const fin = b.toLocaleDateString('es-CL', { day: 'numeric', month: 'long', year: 'numeric' });
  return `${ini} – ${fin}`;
});
const unidad = computed(() => ({ dia: 'día', semana: 'semana', mes: 'mes' }[vista.value]));
const totalVisible = computed(() => (vista.value === 'dia' ? citasDelDia.value : citasFiltradas.value)
  .filter((c) => c.estado !== 'Cancelada' && c.estado !== 'Expirada').length);
function rangoBloqueo(b) {
  const f = (s) => s.slice(11, 16);
  return b.inicio.slice(0, 10) === b.fin.slice(0, 10)
    ? `${f(b.inicio)} – ${f(b.fin)}`
    : `${labelFecha(b.inicio.slice(0, 10))}, ${f(b.inicio)} → ${labelFecha(b.fin.slice(0, 10))}, ${f(b.fin)}`;
}

/* ── Navegación ──
   Las flechas se mueven en la unidad que se está viendo. Cambiar de vista
   nunca mueve el día ancla. */
function mover(pasos) {
  if (vista.value === 'mes') fecha.value = sumarMeses(fecha.value, pasos);
  else fecha.value = sumarDias(fecha.value, pasos * (vista.value === 'semana' ? 7 : 1));
}
function irADia(dia) {
  fecha.value = dia;
  vista.value = 'dia';
}
function imprimir() {
  window.print();
}

/* ── «Ahora» ── */
const esHoy = computed(() => fecha.value === hoy());
const ahoraMin = computed(() => {
  if (!esHoy.value) return null;
  const n = new Date(reloj.value);
  return n.getHours() * 60 + n.getMinutes();
});

/* Al llegar a un día, la grilla se desplaza a lo que importa: la hora actual
   si es hoy, si no la primera cita o el primer horario. Solo al cambiar de día
   o de vista: una recarga automática no puede mover a quien está mirando. */
const scroller = ref(null);
const grilla = ref(null);
let diaDesplazado = '';
async function desplazarAlDia() {
  if (vista.value !== 'dia' || loading.value || diaDesplazado === fecha.value) return;
  await nextTick();
  if (!scroller.value || !grilla.value) return;
  diaDesplazado = fecha.value;
  let objetivo = ahoraMin.value;
  if (objetivo === null) {
    const primeras = [
      ...citasDelDia.value.map((c) => { const d = new Date(c.fecha_hora); return d.getHours() * 60 + d.getMinutes(); }),
      ...Object.values(ventanasDelDia.value).flat().map((v) => { const [h, m] = v.desde.split(':').map(Number); return h * 60 + m; }),
    ];
    objetivo = primeras.length ? Math.min(...primeras) : 9 * 60;
  }
  scroller.value.scrollTop = Math.max(0, grilla.value.yDe(objetivo) - 46);
}
watch([loading, fecha, vista], () => { if (vista.value !== 'dia') diaDesplazado = ''; desplazarAlDia(); });

/* Solo se pide al backend lo que todavía no está en memoria: cerrar el zoom
   (mes → semana → día sobre el mismo día ancla) se resuelve recortando lo ya
   cargado, sin ninguna petición. */
watch(rango, (r) => { if (!cubierto(r)) cargar(); });
watch([vista, fecha], () => {
  burbuja.value = null;
  router.replace({ query: { ...route.query, vista: vista.value, fecha: fecha.value } });
});

onMounted(async () => {
  router.replace({ query: { ...route.query, vista: vista.value, fecha: fecha.value } });
  cargar();
  const [re, rs] = await Promise.allSettled([
    api.get('/catalogo/especialistas/todos'),
    api.get('/catalogo/servicios/todos'),
  ]);
  // Sin catálogo, las columnas salen de los especialistas de las citas, y los
  // colores fijos caen al color de respaldo de cada clave.
  if (re.status === 'fulfilled') especialistas.value = re.value.data;
  if (rs.status === 'fulfilled') servicios.value = rs.value.data;
  fijarCatalogoColores({ servicios: servicios.value, especialistas: especialistas.value });
});
</script>

<template>
  <div class="agenda" :class="{ 'panel-cerrado': !panelAbierto }">
    <!-- ── Filtros ─────────────────────────────────────────────────────── -->
    <div v-if="panelAbierto" class="filtros-fondo" @click="panelAbierto = false"></div>
    <aside id="agenda-filtros" class="filtros" aria-label="Filtros de la agenda">
      <div class="filtros-cab">
        <h1 class="filtros-titulo">Agenda</h1>
        <button type="button" class="ic-btn" aria-label="Ocultar filtros" title="Ocultar filtros" @click="panelAbierto = false">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m11 17-5-5 5-5M18 17l-5-5 5-5" /></svg>
        </button>
      </div>

      <div class="campo">
        <span class="et" id="et-vista">Vista</span>
        <div class="segmentado" role="group" aria-labelledby="et-vista">
          <button v-for="v in VISTAS" :key="v.id" type="button" class="seg" :class="{ activo: vista === v.id }"
            :aria-pressed="vista === v.id" @click="vista = v.id">{{ v.label }}</button>
        </div>
      </div>

      <label class="campo">
        <span class="et">Profesional</span>
        <select v-model="filtros.profesional" class="select">
          <option value="">Todos</option>
          <option v-for="e in opcionesEspecialista" :key="e.id" :value="e.id">{{ e.nombre }}</option>
        </select>
      </label>

      <label class="campo">
        <span class="et">Estado de la reserva</span>
        <select v-model="filtros.estado" class="select">
          <option value="activas">Reservas activas</option>
          <option value="todas">Todas, con canceladas</option>
          <option v-for="e in ESTADOS" :key="e" :value="e">Solo: {{ ESTADO_VISIBLE[e].texto.toLowerCase() }}</option>
        </select>
      </label>

      <div class="campo">
        <span class="et">Buscar en la agenda</span>
        <input v-model="filtros.paciente" type="search" class="input" placeholder="Paciente" aria-label="Buscar por paciente" />
        <input v-model="filtros.tutor" type="search" class="input" placeholder="Tutor o correo" aria-label="Buscar por tutor" />
        <input v-model="filtros.telefono" type="search" inputmode="tel" class="input" placeholder="Teléfono" aria-label="Buscar por teléfono" />
        <button v-if="hayBusqueda" type="button" class="limpiar" @click="limpiarBusqueda">Limpiar búsqueda</button>
      </div>

      <label class="campo">
        <span class="et">Color de las citas</span>
        <select v-model="modoColor" class="select">
          <option v-for="m in MODOS_COLOR" :key="m.id" :value="m.id">{{ m.label }}</option>
        </select>
      </label>
      <ul v-if="leyendaColores.length" class="leyenda-colores" :aria-label="modoColor === 'consulta' ? 'Color de cada consulta' : 'Color de cada especialista'">
        <li v-for="l in leyendaColores" :key="l.clave">
          <span class="muestra" :style="{ background: l.color?.fondo, borderColor: l.color?.borde }" aria-hidden="true"></span>
          {{ l.nombre }}
        </li>
      </ul>

      <MiniCalendario v-model="fecha" class="mini-cal" />

      <div class="leyenda" aria-label="Cómo leer la agenda">
        <span v-for="e in ['Agendada', 'Confirmada', 'Completada', 'Cancelada']" :key="e" class="ley">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="ESTADO_VISIBLE[e].icono" /></svg>
          {{ ESTADO_VISIBLE[e].texto }}
        </span>
        <span class="ley"><span class="muestra rayada" aria-hidden="true"></span>Bloqueado</span>
        <span class="ley"><span class="muestra gris" aria-hidden="true"></span>Fuera de horario</span>
      </div>
    </aside>

    <!-- ── Tablero ─────────────────────────────────────────────────────── -->
    <section class="tablero" aria-labelledby="agenda-fecha">
      <header class="tab-cab">
        <button v-if="!panelAbierto" type="button" class="ic-btn" aria-label="Mostrar filtros" aria-controls="agenda-filtros"
          title="Mostrar filtros" @click="panelAbierto = true">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M7 12h10M10 18h4" /></svg>
        </button>
        <button type="button" class="btn btn-ghost btn-sm" :disabled="esHoy && vista === 'dia'" @click="fecha = hoy()">Hoy</button>
        <div class="flechas">
          <button type="button" class="ic-btn" :aria-label="`${unidad} anterior`" :title="`${unidad} anterior`" @click="mover(-1)">‹</button>
          <button type="button" class="ic-btn" :aria-label="`${unidad} siguiente`" :title="`${unidad} siguiente`" @click="mover(1)">›</button>
        </div>
        <div class="tab-fecha">
          <h2 id="agenda-fecha">{{ labelRango }}</h2>
          <span>{{ totalVisible }} {{ totalVisible === 1 ? 'cita' : 'citas' }}<template v-if="hayFiltro"> con estos filtros</template></span>
        </div>

        <div class="tab-der">
          <span class="actualizado" aria-live="polite">{{ etiquetaActualizado }}</span>
          <button type="button" class="ic-btn" :class="{ girando: refrescando }" aria-label="Actualizar la agenda" title="Actualizar" @click="refrescar">
            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11a8 8 0 0 0-14.3-4.9L4 8m0-4v4h4M4 13a8 8 0 0 0 14.3 4.9L20 16m0 4v-4h-4" /></svg>
          </button>
          <button type="button" class="ic-btn" aria-label="Imprimir la agenda" title="Imprimir" @click="imprimir">
            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M7 9V3h10v6M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2M7 14h10v7H7z" /></svg>
          </button>
          <button type="button" class="btn btn-ghost btn-sm" @click="nuevoBloqueo()">Bloquear horario</button>
          <button type="button" class="btn btn-primary btn-sm" @click="nuevaCita()">+ Nueva cita</button>
        </div>
      </header>

      <p v-if="error" class="aviso error" role="alert">{{ error }}</p>
      <p v-if="sinDisponibilidad && !loading" class="aviso">
        No se pudieron cargar los horarios ni los bloqueos: la grilla muestra todo como disponible.
      </p>
      <p v-if="vista === 'dia' && !loading && !citasDelDia.length" class="aviso suave">
        <template v-if="hayFiltro">Sin coincidencias este día. <button type="button" class="enlace" @click="vista = 'mes'">Buscar en el mes</button></template>
        <template v-else-if="proxima">Sin citas este día. La próxima es el <strong>{{ labelFecha(proxima.fecha) }}</strong>. <button type="button" class="enlace" @click="fecha = proxima.fecha">Ir a ese día</button></template>
        <template v-else>Sin citas este día.</template>
      </p>

      <!-- Esqueleto: la forma de la grilla mientras llega la primera carga. -->
      <div v-if="loading" class="esqueleto" aria-busy="true" aria-label="Cargando la agenda">
        <div v-for="i in 4" :key="i" class="esq-col"><span class="esq-cab"></span><span class="esq-b" style="top: 18%"></span><span class="esq-b" style="top: 46%"></span></div>
      </div>

      <!-- ── DÍA ── -->
      <div v-else-if="vista === 'dia'" ref="scroller" class="scroller" :class="{ atenuado: refrescando }">
        <GrillaDia
          ref="grilla"
          :columnas="columnas" :citas="citasDelDia" :colores="colores"
          :ventanas="ventanasDelDia" :bloqueos="bloqueosDelDia" :fecha="fecha" :ahora-min="ahoraMin"
          @cita="(c, r) => abrirBurbuja('cita', c, r)"
          @bloqueo="(b, r) => abrirBurbuja('bloqueo', b, r)"
          @hueco="(h) => abrirBurbuja('hueco', h, h.rect)"
        />
        <p v-if="!columnas.length" class="vacio-grilla">No hay especialistas activos para mostrar.</p>
      </div>

      <!-- ── SEMANA ── -->
      <div v-else-if="vista === 'semana'" class="pagina" :class="{ atenuado: refrescando }">
        <div class="semana-grid">
          <section v-for="d in diasDelRango" :key="d" class="col-dia" :class="{ hoy: d === hoy() }">
            <header class="col-head">
              <button type="button" class="col-fecha" :title="`Ver ${labelFecha(d)}`" @click="irADia(d)">
                <span class="col-dow">{{ DIAS_CORTOS[(aDate(d).getDay() + 6) % 7] }}</span>
                <span class="col-num">{{ numeroDia(d) }}</span>
              </button>
              <span class="col-conteo">{{ citasDe(d).length }}</span>
              <button type="button" class="col-mas" :aria-label="`Nueva cita el ${labelFecha(d)}`" title="Nueva cita" @click="nuevaCita({ fecha: d })">+</button>
            </header>
            <button v-for="b in bloqueosEntre(d, d)" :key="b.id" type="button" class="chip-bloqueo"
              @click="abrirBurbuja('bloqueo', b, $event.currentTarget.getBoundingClientRect())">
              <span>{{ b.inicio.slice(0, 10) === d ? b.inicio.slice(11, 16) : '00:00' }}–{{ b.fin.slice(0, 10) === d ? b.fin.slice(11, 16) : '24:00' }}</span>
              {{ b.motivo }}<template v-if="b.especialista_id"> · {{ nombreEspecialista(b.especialista_id) }}</template>
            </button>
            <p v-if="!citasDe(d).length" class="col-vacio">—</p>
            <button
              v-for="c in citasDe(d)" :key="c.id" type="button" class="mini" :class="{ apagada: c.estado === 'Cancelada' || c.estado === 'Expirada' }"
              :style="{ '--c-fondo': colores.get(c.id)?.fondo, '--c-borde': colores.get(c.id)?.borde, '--c-tinta': colores.get(c.id)?.tinta }"
              :aria-label="`${hora(c.fecha_hora)}, ${c.mascota.nombre}, ${c.servicio}, ${ESTADO_VISIBLE[c.estado]?.texto || c.estado}`"
              @click="abrirBurbuja('cita', c, $event.currentTarget.getBoundingClientRect())"
            >
              <span class="mini-l1">
                <span class="mini-hora">{{ hora(c.fecha_hora) }}</span>
                <strong class="mini-nombre">{{ c.mascota.nombre }}</strong>
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="ESTADO_VISIBLE[c.estado]?.icono || ESTADO_VISIBLE.Agendada.icono" /></svg>
              </span>
              <span class="mini-detalle">{{ c.servicio }}<template v-if="c.especialista"> · {{ c.especialista.nombre }}</template></span>
            </button>
          </section>
        </div>
        <p v-if="!citasFiltradas.length" class="aviso suave">{{ hayFiltro ? 'Sin coincidencias esta semana.' : 'Sin citas esta semana.' }}</p>
      </div>

      <!-- ── MES ── -->
      <div v-else class="pagina" :class="{ atenuado: refrescando }">
        <div class="mes-dows" aria-hidden="true"><span v-for="d in DIAS_CORTOS" :key="d">{{ d }}</span></div>
        <div class="mes-grid">
          <section v-for="d in diasDelRango" :key="d" class="celda"
            :class="{ hoy: d === hoy(), fuera: esDeOtroMes(d), bloqueado: bloqueosEntre(d, d).length }">
            <header class="celda-head">
              <button type="button" class="celda-num" :title="`Ver ${labelFecha(d)}`" @click="irADia(d)">{{ numeroDia(d) }}</button>
              <span v-if="citasDe(d).length" class="celda-conteo">{{ citasDe(d).length }}</span>
            </header>
            <button
              v-for="c in citasDe(d).slice(0, 3)" :key="c.id" type="button" class="mini compacta"
              :class="{ apagada: c.estado === 'Cancelada' || c.estado === 'Expirada' }"
              :style="{ '--c-fondo': colores.get(c.id)?.fondo, '--c-borde': colores.get(c.id)?.borde, '--c-tinta': colores.get(c.id)?.tinta }"
              :aria-label="`${hora(c.fecha_hora)}, ${c.mascota.nombre}, ${c.servicio}`"
              @click="abrirBurbuja('cita', c, $event.currentTarget.getBoundingClientRect())"
            >
              <span class="mini-hora">{{ hora(c.fecha_hora) }}</span>
              <strong class="mini-nombre">{{ c.mascota.nombre }}</strong>
            </button>
            <button v-if="citasDe(d).length > 3" type="button" class="celda-mas" @click="irADia(d)">+{{ citasDe(d).length - 3 }} más</button>
          </section>
        </div>
        <p v-if="!citasFiltradas.length" class="aviso suave">{{ hayFiltro ? 'Sin coincidencias este mes.' : 'Sin citas este mes.' }}</p>
      </div>
    </section>

    <!-- ── Burbujas ── -->
    <Burbuja v-if="burbuja" :key="burbuja.tipo + (burbuja.dato.id || burbuja.dato.hora)" :ancla="burbuja.rect"
      :etiqueta="burbuja.tipo === 'cita' ? 'Detalle de la cita' : burbuja.tipo === 'bloqueo' ? 'Horario bloqueado' : 'Hora libre'"
      :ancho="burbuja.tipo === 'hueco' ? 280 : 330" @cerrar="burbuja = null">
      <DetalleCita
        v-if="burbuja.tipo === 'cita'" :cita="burbuja.dato"
        :color="colores.get(burbuja.dato.id)"
        :puede-reagendar="puedeReagendar(burbuja.dato)"
        @estado="(e) => cambiarEstado(burbuja.dato, e)" @reagendar="reagendar(burbuja.dato)"
      />

      <div v-else-if="burbuja.tipo === 'bloqueo'" class="bq">
        <p class="bq-cuando">{{ rangoBloqueo(burbuja.dato) }}</p>
        <h3 class="bq-motivo">{{ burbuja.dato.motivo }}</h3>
        <p class="bq-meta">
          {{ burbuja.dato.especialista_id ? nombreEspecialista(burbuja.dato.especialista_id) : 'Toda la clínica' }}
          <template v-if="burbuja.dato.creado_por"> · lo puso {{ burbuja.dato.creado_por }}</template>
        </p>
        <p class="bq-meta">Esas horas no se ofrecen en el portal de reservas.</p>
        <div v-if="!confirmandoQuitar" class="bq-acciones">
          <button type="button" class="btn btn-ghost btn-sm" @click="confirmandoQuitar = true">Quitar bloqueo</button>
        </div>
        <div v-else class="bq-acciones">
          <span class="bq-pregunta">¿Volver a abrir esas horas?</span>
          <button type="button" class="btn btn-danger btn-sm" :disabled="quitando" @click="quitarBloqueo(burbuja.dato)">{{ quitando ? 'Quitando…' : 'Sí, quitar' }}</button>
          <button type="button" class="btn btn-ghost btn-sm" @click="confirmandoQuitar = false">No</button>
        </div>
      </div>

      <div v-else class="hueco">
        <p class="hueco-cuando"><strong>{{ burbuja.dato.hora }}</strong> · {{ burbuja.dato.columna.nombre }}</p>
        <p v-if="!burbuja.dato.dentroDeHorario" class="hueco-nota">Fuera de su horario de atención.</p>
        <button type="button" class="btn btn-primary btn-sm"
          @click="nuevaCita({ fecha, hora: burbuja.dato.hora, especialistaId: burbuja.dato.columna.id })">Nueva cita a las {{ burbuja.dato.hora }}</button>
        <button type="button" class="btn btn-ghost btn-sm"
          @click="nuevoBloqueo({ fecha, hora: burbuja.dato.hora, especialistaId: burbuja.dato.columna.id })">Bloquear desde las {{ burbuja.dato.hora }}</button>
      </div>
    </Burbuja>

    <NuevaCitaModal
      v-if="modalNueva" :fecha-inicial="modalNueva.fecha"
      :especialista-inicial="modalNueva.especialistaId" :hora-inicial="modalNueva.hora"
      @close="modalNueva = null" @creada="alCrearCita"
    />
    <BloqueoModal
      v-if="modalBloqueo" :especialistas="activos" :fecha="modalBloqueo.fecha"
      :hora="modalBloqueo.hora" :especialista-id="modalBloqueo.especialistaId"
      @close="modalBloqueo = null" @creado="alCrearBloqueo"
    />
    <ReagendarCitaModal
      v-if="citaReagendando" :cita="citaReagendando"
      @close="citaReagendando = null" @reagendada="alReagendar"
    />
  </div>
</template>

<style scoped>
.agenda {
  --ancho-filtros: 264px;
  display: grid; grid-template-columns: var(--ancho-filtros) minmax(0, 1fr);
  height: calc(100dvh - var(--alto-barra));
  background: var(--surface);
}
.agenda.panel-cerrado { grid-template-columns: 0 minmax(0, 1fr); }
.agenda.panel-cerrado .filtros { visibility: hidden; }

/* ── Filtros ── */
.filtros {
  display: flex; flex-direction: column; gap: 0.95rem;
  padding: 0.9rem 0.95rem 1.2rem; overflow-y: auto;
  background: var(--bg); border-right: 1px solid var(--line);
}
.filtros-fondo { display: none; }
.filtros-cab { display: flex; align-items: center; justify-content: space-between; }
.filtros-titulo { font-size: var(--t-xl); font-weight: 700; color: var(--brand-ink); }
.campo { display: flex; flex-direction: column; gap: 0.35rem; }
.et { font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft); }
.campo .select, .campo .input { width: 100%; padding: 0.45em 0.65em; font-size: var(--t-sm); background: var(--surface); }
.segmentado {
  display: grid; grid-template-columns: repeat(3, 1fr); padding: 3px; gap: 2px;
  background: var(--surface); border: 1px solid var(--line-strong); border-radius: var(--r-sm);
}
.seg { font-size: var(--t-sm); font-weight: 600; color: var(--ink-soft); padding: 0.32em 0; border-radius: 6px; transition: background 0.15s ease-out, color 0.15s ease-out; }
.seg:hover { background: var(--accent-hover); color: var(--ink); }
.seg.activo { background: var(--brand); color: #fff; }
.limpiar { align-self: flex-start; font-size: var(--t-micro); font-weight: 600; color: var(--accent-strong); }
.limpiar:hover { text-decoration: underline; }
.mini-cal { padding-top: 0.2rem; border-top: 1px solid var(--line); }
.leyenda { display: flex; flex-wrap: wrap; gap: 0.35rem 0.8rem; padding-top: 0.7rem; border-top: 1px solid var(--line); }
.ley { display: inline-flex; align-items: center; gap: 0.3rem; font-size: var(--t-micro); color: var(--ink-soft); }
.muestra { width: 13px; height: 13px; border-radius: 3px; border: 1px solid var(--line-strong); }
.muestra.rayada { background: repeating-linear-gradient(135deg, var(--bg) 0 3px, color-mix(in oklab, var(--ink) 14%, var(--surface)) 3px 4px); }
.muestra.gris { background: color-mix(in oklab, var(--ink) 7%, var(--surface)); }
.leyenda-colores { list-style: none; padding: 0; margin: -0.35rem 0 0; display: flex; flex-direction: column; gap: 0.3rem; }
.leyenda-colores li { display: flex; align-items: center; gap: 0.45rem; font-size: var(--t-micro); color: var(--ink); min-width: 0; }
.leyenda-colores .muestra { flex: none; }

/* ── Tablero ── */
.tablero { display: flex; flex-direction: column; min-width: 0; min-height: 0; }
.tab-cab {
  display: flex; align-items: center; gap: 0.55rem; flex-wrap: wrap;
  padding: 0.6rem 1rem; border-bottom: 1px solid var(--line);
}
.flechas { display: flex; }
.tab-fecha { display: flex; flex-direction: column; line-height: 1.25; margin-left: 0.25rem; min-width: 0; }
.tab-fecha h2 { font-size: var(--t-lg); font-weight: 700; }
.tab-fecha h2::first-letter { text-transform: uppercase; }
.tab-fecha span { font-size: var(--t-micro); color: var(--ink-soft); }
.tab-der { display: flex; align-items: center; gap: 0.4rem; margin-left: auto; flex-wrap: wrap; }
.actualizado { font-size: var(--t-micro); color: var(--ink-soft); margin-right: 0.2rem; }
.ic-btn {
  display: inline-grid; place-items: center; width: 34px; height: 34px; flex: none;
  border-radius: var(--r-sm); color: var(--ink-soft); font-size: 1.25rem; line-height: 1;
  transition: background 0.15s ease-out, color 0.15s ease-out;
}
.ic-btn:hover { background: var(--accent-hover); color: var(--ink); }
.ic-btn.girando svg { animation: girar 0.9s linear infinite; }
@keyframes girar { to { transform: rotate(360deg); } }

.aviso { margin: 0.6rem 1rem 0; font-size: var(--t-sm); background: var(--warn-tint); color: var(--warn-ink); border-radius: var(--r-sm); padding: 0.45rem 0.7rem; }
.aviso.error { background: var(--danger-tint); color: var(--danger); }
.aviso.suave { background: var(--bg); color: var(--ink-soft); }
.enlace { color: var(--accent-strong); font-weight: 600; }
.enlace:hover { text-decoration: underline; }

.scroller { flex: 1; min-height: 0; overflow: auto; margin-top: 0.6rem; border-top: 1px solid var(--line); transition: opacity 0.18s ease-out; }
.pagina { flex: 1; min-height: 0; overflow: auto; padding: 0.9rem 1rem 1.4rem; transition: opacity 0.18s ease-out; }
.atenuado { opacity: 0.55; }
.vacio-grilla { padding: 2rem; text-align: center; color: var(--ink-soft); }

.esqueleto { flex: 1; display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; margin-top: 0.6rem; background: var(--line); border-top: 1px solid var(--line); }
.esq-col { position: relative; background: var(--surface); }
.esq-cab { position: absolute; top: 14px; left: 14px; width: 55%; height: 14px; border-radius: 4px; background: var(--bg); }
.esq-b { position: absolute; left: 8px; right: 8px; height: 64px; border-radius: var(--r-sm); background: var(--bg); }

/* ── Burbujas ── */
.bq, .hueco { display: flex; flex-direction: column; gap: 0.45rem; padding-right: 1.4rem; }
.bq-cuando { font-size: var(--t-micro); color: var(--ink-soft); }
.bq-cuando::first-letter { text-transform: uppercase; }
.bq-motivo { font-size: var(--t-lg); }
.bq-meta { font-size: var(--t-sm); color: var(--ink-soft); }
.bq-acciones { display: flex; align-items: center; flex-wrap: wrap; gap: 0.45rem; margin-top: 0.2rem; }
.bq-pregunta { font-size: var(--t-sm); font-weight: 600; width: 100%; }
.hueco { padding-right: 1.4rem; }
.hueco-cuando { font-size: var(--t-sm); }
.hueco-cuando strong { font-size: var(--t-lg); font-variant-numeric: tabular-nums; }
.hueco-nota { font-size: var(--t-micro); color: var(--warn-ink); background: var(--warn-tint); border-radius: var(--r-sm); padding: 0.25rem 0.5rem; }
.hueco .btn { justify-content: flex-start; }

/* ── Tarjeta compacta de cita (semana y mes) ── */
.mini {
  display: flex; flex-direction: column; width: 100%; text-align: left;
  padding: 0.35rem 0.5rem; margin-bottom: 0.3rem;
  border-radius: var(--r-sm); border: 1px solid var(--c-borde, var(--line));
  background: var(--c-fondo, var(--bg)); color: var(--c-tinta, var(--ink));
  font-size: var(--t-micro); line-height: 1.3;
  transition: box-shadow 0.15s ease-out;
}
.mini:hover { box-shadow: 0 3px 10px rgba(16, 26, 61, 0.14); }
.mini.apagada { background: var(--surface); border-style: dashed; border-color: var(--line-strong); color: var(--ink-soft); }
.mini.apagada .mini-nombre { text-decoration: line-through; }
.mini-l1 { display: flex; align-items: center; gap: 0.3rem; }
.mini-l1 svg { margin-left: auto; flex: none; }
.mini-hora { font-variant-numeric: tabular-nums; margin-right: 0.15rem; }
.mini-nombre { font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mini-detalle { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: 0.9; }
.chip-bloqueo {
  display: block; width: 100%; text-align: left; margin-bottom: 0.3rem; padding: 0.3rem 0.5rem;
  font-size: var(--t-micro); color: var(--ink-soft); border: 1px solid var(--line-strong); border-radius: var(--r-sm);
  background: repeating-linear-gradient(135deg, var(--bg) 0 6px, color-mix(in oklab, var(--ink) 9%, var(--surface)) 6px 8px);
}
.chip-bloqueo span { font-variant-numeric: tabular-nums; font-weight: 600; color: var(--ink); margin-right: 0.25rem; }

/* ── Semana ── */
.semana-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.55rem; }
.col-dia { padding: 0.55rem; min-height: 160px; border: 1px solid var(--line); border-radius: var(--r-md); background: var(--surface); }
.col-dia.hoy { border-color: var(--brand); box-shadow: inset 0 0 0 1px var(--brand); }
.col-head { display: flex; align-items: center; gap: 0.35rem; padding-bottom: 0.45rem; margin-bottom: 0.45rem; border-bottom: 1px solid var(--line); }
.col-fecha { display: flex; align-items: baseline; gap: 0.35rem; flex: 1; min-width: 0; padding: 0.1rem 0.25rem; border-radius: var(--r-sm); }
.col-fecha:hover { background: var(--accent-hover); }
.col-dow { font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft); }
.col-num { font-size: var(--t-lg); font-weight: 700; }
.col-dia.hoy .col-num { color: var(--brand-ink); }
.col-conteo { font-size: var(--t-micro); font-weight: 700; color: var(--ink-soft); background: var(--bg); border-radius: var(--r-full); padding: 0.1em 0.5em; }
.col-mas { font-size: 1rem; font-weight: 700; line-height: 1; color: var(--ink-soft); width: 24px; height: 24px; border-radius: 50%; flex: none; }
.col-mas:hover { background: var(--accent-tint); color: var(--accent-strong); }
.col-vacio { color: var(--line-strong); text-align: center; padding: 0.8rem 0; }

/* ── Mes ── */
.mes-dows { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.45rem; margin-bottom: 0.35rem; padding: 0 0.2rem; }
.mes-dows span { font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft); }
.mes-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.45rem; }
.celda { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-md); padding: 0.4rem; min-height: 108px; }
.celda.fuera { background: var(--bg); }
.celda.fuera .celda-num { color: var(--ink-soft); opacity: 0.6; }
.celda.hoy { border-color: var(--brand); box-shadow: inset 0 0 0 1px var(--brand); }
.celda.bloqueado { background-image: repeating-linear-gradient(135deg, transparent 0 9px, color-mix(in oklab, var(--ink) 5%, transparent) 9px 11px); }
.celda-head { display: flex; align-items: center; justify-content: space-between; gap: 0.3rem; margin-bottom: 0.3rem; }
.celda-num { font-size: var(--t-sm); font-weight: 700; min-width: 26px; height: 26px; border-radius: 50%; }
.celda-num:hover { background: var(--accent-hover); }
.celda.hoy .celda-num { background: var(--brand); color: #fff; }
.celda-conteo { font-size: var(--t-micro); font-weight: 700; color: var(--ink-soft); }
.celda-mas { font-size: var(--t-micro); font-weight: 600; color: var(--accent-strong); padding: 0.1rem 0.25rem; border-radius: var(--r-sm); }
.celda-mas:hover { background: var(--accent-hover); }
.mini.compacta { flex-direction: row; align-items: center; padding: 0.2rem 0.4rem; margin-bottom: 0.22rem; white-space: nowrap; overflow: hidden; }

@media (max-width: 1100px) {
  .semana-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}
/* Angosto: los filtros pasan a un panel que se abre encima, con fondo. */
@media (max-width: 900px) {
  .agenda, .agenda.panel-cerrado { grid-template-columns: minmax(0, 1fr); }
  .filtros {
    position: fixed; z-index: var(--z-modal); top: 0; bottom: 0; left: 0; width: min(300px, 88vw);
    box-shadow: var(--shadow-lg);
  }
  .filtros-fondo { display: block; position: fixed; inset: 0; z-index: var(--z-modal-backdrop); background: rgba(16, 26, 61, 0.45); }
  .agenda.panel-cerrado .filtros { display: none; }
  .actualizado { display: none; }
  .semana-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .mini.compacta, .celda-mas { display: none; }
  .celda { min-height: 58px; padding: 0.3rem 0.15rem; }
  .celda-head { flex-direction: column; align-items: center; gap: 0.05rem; margin-bottom: 0; }
}
@media (max-width: 560px) {
  .semana-grid { grid-template-columns: 1fr; }
  .tab-der .btn-ghost { display: none; }
}
@media (prefers-reduced-motion: reduce) {
  .seg, .ic-btn, .scroller, .pagina, .mini { transition: none; }
  .ic-btn.girando svg { animation: none; }
}
@media print {
  .agenda { display: block; height: auto; }
  .filtros, .filtros-fondo, .tab-der, .flechas, .tab-cab > .btn, .tab-cab > .ic-btn, .aviso { display: none; }
  .scroller, .pagina { overflow: visible; border: none; }
}
</style>
