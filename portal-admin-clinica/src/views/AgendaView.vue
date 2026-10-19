<script setup>
import { ref, computed, reactive, onMounted, onUnmounted, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { api, apiError, fotoEspecie, urlFoto } from '../api.js';
import NuevaCitaModal from '../components/NuevaCitaModal.vue';
import ReagendarCitaModal from '../components/ReagendarCitaModal.vue';
import BuscadorAvanzado from '../components/BuscadorAvanzado.vue';

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
const loading = ref(true);             // primera carga: no hay nada que mostrar
const refrescando = ref(false);        // recarga con datos viejos todavía en pantalla
const error = ref('');
const modalNueva = ref(false);
const fechaNueva = ref(fecha.value);   // día que precarga el modal de nueva cita

const filtros = reactive({ paciente: '', tutor: '', telefono: '', consulta: '', especialista: '', estado: '' });

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

/* ── Búsqueda y filtros ──
   Client-side a propósito: los datos del rango ya están en memoria, así que
   filtrar es instantáneo y no manda una consulta por tecla. Alcance = lo que
   se está viendo; si no hay coincidencias se ofrece ampliar el rango. */
// Sin tildes y en minúsculas: "nunez" encuentra a "Núñez", que es como se
// escribe un apellido cuando se busca con prisa en el mesón.
function norm(s) {
  return (s || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}
// Los teléfonos se comparan solo por dígitos: en la base conviven "+56 9 8765
// 4321", "56987654321" y "9 8765 4321" para el mismo número, y nadie los teclea
// igual que como quedaron guardados.
function soloDigitos(s) {
  return (s || '').toString().replace(/\D/g, '');
}

// Lo cargado puede ser más ancho que lo que se está mirando (ver `cubierto`):
// la vista siempre se recorta a su propio rango.
const enRango = computed(() => citas.value.filter((c) => {
  const d = c.fecha_hora.slice(0, 10);
  return d >= rango.value.desde && d <= rango.value.hasta;
}));

/* Campos dirigidos, no un cajón de sastre: buscar "luna" en un campo único
   devuelve la paciente Luna, la tutora Luna y la Dra. Luna todas mezcladas.
   Separados, cada uno responde una pregunta sola — mismo criterio que los
   buscadores de Pacientes y Tutores. Se combinan con Y: los que estén llenos
   tienen que cumplirse todos. */
const citasFiltradas = computed(() => {
  const paciente = norm(filtros.paciente.trim());
  const tutor = norm(filtros.tutor.trim());
  const telefono = soloDigitos(filtros.telefono);
  const consulta = norm(filtros.consulta.trim());

  return enRango.value.filter((c) => {
    if (filtros.especialista && c.especialista?.id !== filtros.especialista) return false;
    if (filtros.estado && c.estado !== filtros.estado) return false;
    if (paciente && !norm(`${c.mascota?.nombre || ''} ${c.mascota?.especie || ''}`).includes(paciente)) return false;
    if (tutor && !norm(`${c.dueno?.nombre || ''} ${c.dueno?.email || ''}`).includes(tutor)) return false;
    if (telefono && !soloDigitos(c.dueno?.telefono).includes(telefono)) return false;
    if (consulta && !norm(`${c.servicio || ''} ${c.notas_pre_cita || ''}`).includes(consulta)) return false;
    return true;
  });
});

const hayFiltro = computed(() => Object.values(filtros).some((v) => v.trim?.() ?? v));

/* Los especialistas del catálogo son la lista estable (no cambia al moverse de
   semana). Solo los activos: la importación del legado (ACVet) dejó 54
   especialistas inactivos, uno por cada código de médico del sistema anterior
   ("Médico #6362 (ACVet)"), que no son personal real y llenarían el desplegable
   — mismo criterio que EspecialistasView. No se pueden borrar: cuelgan de miles
   de notas clínicas que perderían quién atendió.
   Se les suman los que aparezcan en las citas del rango: si una cita histórica
   quedó a nombre de uno de esos códigos, tiene que poder filtrarse mientras
   está en pantalla. */
const opcionesEspecialista = computed(() => {
  const map = new Map();
  for (const e of especialistas.value) if (e.activo) map.set(e.id, e);
  for (const c of citas.value) {
    if (c.especialista && !map.has(c.especialista.id)) map.set(c.especialista.id, c.especialista);
  }
  return [...map.values()];
});

const camposBusqueda = computed(() => [
  // El primero es el campo grande del componente: el paciente es por lejos lo
  // que más se busca en el mesón ("¿a qué hora viene Kuyen?").
  { key: 'paciente', label: 'Paciente', placeholder: 'Buscar por nombre del paciente…' },
  { key: 'tutor', label: 'Tutor', placeholder: 'Nombre o correo del tutor…' },
  // Sin autocompletar, igual que en Tutores: un teléfono no se completa de a
  // poco como un nombre, así que buscar en cada dígito solo estorba.
  { key: 'telefono', label: 'Teléfono', placeholder: '+56 9 …', autocompletar: false },
  { key: 'consulta', label: 'Consulta', placeholder: 'Tipo de consulta o nota…' },
  {
    key: 'especialista', label: 'Especialista', type: 'select',
    options: opcionesEspecialista.value.map((e) => ({ value: e.id, label: `${e.emoji || '🩺'} ${e.nombre}` })),
  },
  { key: 'estado', label: 'Estado', type: 'select', options: ESTADOS.map((e) => ({ value: e, label: e })) },
]);

function setFiltros(v) {
  Object.assign(filtros, v);
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

const diasDelRango = computed(() => {
  const n = diasEntre(rango.value.desde, rango.value.hasta);
  return Array.from({ length: n + 1 }, (_, i) => sumarDias(rango.value.desde, i));
});

/* ── Carga ──
   Una sola llamada por rango: /citas/agenda acepta `hasta` y devuelve el rango
   completo, así que semana y mes no disparan 7 ni 42 peticiones. */
// Encadenar flechas dispara varias peticiones a la vez y no siempre vuelven en
// orden: solo la última pedida puede escribir en pantalla.
let ultimaCarga = 0;
const rangoCargado = ref(null);

// Comparación de strings ISO: YYYY-MM-DD ordena igual como texto que como fecha.
function cubierto(r) {
  return !!rangoCargado.value && rangoCargado.value.desde <= r.desde && rangoCargado.value.hasta >= r.hasta;
}

async function cargar() {
  const token = ++ultimaCarga;
  // Solo la primera carga vacía la pantalla; de ahí en adelante lo que hay se
  // atenúa y se reemplaza en su sitio (incluso si el rango anterior no tenía
  // ninguna cita, la rejilla del calendario sigue siendo información útil).
  refrescando.value = !loading.value;
  error.value = '';
  const { desde, hasta } = rango.value;
  try {
    const { data } = await api.get('/citas/agenda', { params: { fecha: desde, hasta } });
    if (token !== ultimaCarga) return;
    citas.value = data;
    rangoCargado.value = { desde, hasta };
  } catch (e) {
    if (token !== ultimaCarga) return;
    error.value = apiError(e, 'No se pudo cargar la agenda.');
  } finally {
    if (token === ultimaCarga) {
      loading.value = false;
      refrescando.value = false;
    }
  }
}

/* ── «Sin citas hoy» no es lo mismo que «no hay citas» ──
   En vista de día se pide SOLO ese día, así que la pantalla no tiene forma de
   saber que hay horas agendadas más adelante: decía «Sin citas para este día»
   y parecía que las reservas se habían perdido. Cuando el día sale vacío se
   busca la próxima, una sola vez y solo en ese caso, para poder decir cuándo
   es y ofrecer saltar hasta ella. */
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
    // Vienen ordenadas por fecha_hora desde el backend.
    const viva = data.find((c) => c.estado !== 'Cancelada');
    if (viva) proxima.value = { fecha: viva.fecha_hora.slice(0, 10), total: data.length };
  } catch {
    // Es información de cortesía: si falla, la pantalla queda como antes.
  } finally {
    buscandoProxima.value = false;
  }
}

/* Se dispara solo cuando el día quedó vacío y sin filtros, ya terminada la
   carga: en el caso normal —día con citas— no cuesta ninguna petición. */
watch([citasDelDia, loading, hayFiltro], ([lista, cargando, filtro]) => {
  if (vista.value === 'dia' && !cargando && !filtro && !lista.length) buscarProxima();
  else proxima.value = null;
}, { immediate: true });

/* Reagendar desde la agenda: es la pantalla donde la recepción pasa el día,
   así que mover una hora tiene que poder hacerse acá y no solo en Reservas.
   Antes había que cancelar y volver a crear, perdiendo historial y respuestas. */
const citaReagendando = ref(null);

function puedeReagendar(c) {
  return !!c.servicio_id && !!(c.especialista_id || c.especialista?.id) && c.estado !== 'Cancelada';
}

async function alReagendar() {
  citaReagendando.value = null;
  await cargar();
}

async function cambiarEstado(cita, estado) {
  if (estado === 'Cancelada' && !confirm(`¿Cancelar la cita de ${cita.mascota.nombre} a las ${hora(cita.fecha_hora)}?`)) {
    return;
  }
  try {
    await api.patch(`/citas/${cita.id}/estado`, { estado });
    cita.estado = estado;
  } catch (e) {
    error.value = apiError(e, 'No se pudo actualizar el estado.');
  }
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
  if (vista.value === 'dia') return labelFecha(fecha.value);
  if (vista.value === 'mes') {
    return aDate(fecha.value).toLocaleDateString('es-CL', { month: 'long', year: 'numeric' });
  }
  const a = aDate(rango.value.desde);
  const b = aDate(rango.value.hasta);
  const mismoMes = a.getMonth() === b.getMonth() && a.getFullYear() === b.getFullYear();
  const ini = mismoMes ? a.getDate() : a.toLocaleDateString('es-CL', { day: 'numeric', month: 'long' });
  const fin = b.toLocaleDateString('es-CL', { day: 'numeric', month: 'long', year: 'numeric' });
  return `${ini} – ${fin}`;
});

const unidad = computed(() => ({ dia: 'día', semana: 'semana', mes: 'mes' }[vista.value]));

/* ── Navegación ──
   Las flechas se mueven en la unidad que se está viendo: un día, una semana o
   un mes. Cambiar de vista nunca mueve el día ancla. */
function mover(pasos) {
  if (vista.value === 'mes') fecha.value = sumarMeses(fecha.value, pasos);
  else fecha.value = sumarDias(fecha.value, pasos * (vista.value === 'semana' ? 7 : 1));
}
function irADia(dia) {
  fecha.value = dia;
  vista.value = 'dia';
}
function abrirNueva(dia) {
  fechaNueva.value = dia || fecha.value;
  modalNueva.value = true;
}
async function alCrearCita() {
  modalNueva.value = false;
  await cargar();
}

/* ── Indicador de "ahora" (solo tiene sentido en la vista de día, viendo hoy) ── */
const esHoy = computed(() => fecha.value === hoy());
const horaActualMin = ref(minutosDeAhora());
function minutosDeAhora() {
  const n = new Date();
  return n.getHours() * 60 + n.getMinutes();
}
function minutosDeCita(dt) {
  const d = new Date(dt);
  return d.getHours() * 60 + d.getMinutes();
}
const intervaloAhora = setInterval(() => { horaActualMin.value = minutosDeAhora(); }, 60000);
onUnmounted(() => clearInterval(intervaloAhora));

// Índice de la primera cita cuya hora ya pasó el "ahora" — el separador va justo antes.
const indiceAhora = computed(() => {
  if (!esHoy.value || vista.value !== 'dia') return -1;
  const lista = citasDelDia.value;
  for (let i = 0; i < lista.length; i++) {
    if (minutosDeCita(lista[i].fecha_hora) >= horaActualMin.value) return i;
  }
  return lista.length; // "ahora" es después de la última cita
});

/* Solo se pide al backend lo que todavía no está en memoria: cerrar el zoom
   (mes → semana → día sobre el mismo día ancla) se resuelve recortando lo ya
   cargado, sin ninguna petición. Ampliarlo o cambiarse de semana/mes sí la
   dispara. */
watch(rango, (r) => { if (!cubierto(r)) cargar(); });

watch([vista, fecha], () => {
  router.replace({ query: { ...route.query, vista: vista.value, fecha: fecha.value } });
});

onMounted(async () => {
  router.replace({ query: { ...route.query, vista: vista.value, fecha: fecha.value } });
  cargar();
  try {
    const { data } = await api.get('/catalogo/especialistas/todos');
    especialistas.value = data;
  } catch {
    // Sin catálogo el filtro igual funciona con los especialistas de las citas.
  }
});
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Agenda</h1>
        <p class="page-sub label-rango">
          {{ labelRango }}
          <span class="conteo">· {{ citasFiltradas.length }} {{ citasFiltradas.length === 1 ? 'cita' : 'citas' }}</span>
        </p>
      </div>
      <div class="head-actions">
        <div class="segmented" role="group" aria-label="Alcance de la agenda">
          <button
            v-for="v in VISTAS" :key="v.id"
            class="seg-btn" :class="{ active: vista === v.id }"
            :aria-pressed="vista === v.id"
            @click="vista = v.id"
          >{{ v.label }}</button>
        </div>
        <div class="date-nav">
          <button class="btn btn-ghost btn-sm" @click="mover(-1)" :aria-label="`${unidad} anterior`" :title="`${unidad} anterior`">←</button>
          <input type="date" class="input date-input" v-model="fecha" aria-label="Fecha de la agenda" />
          <button class="btn btn-ghost btn-sm" @click="mover(1)" :aria-label="`${unidad} siguiente`" :title="`${unidad} siguiente`">→</button>
          <button class="btn btn-ghost btn-sm" @click="fecha = hoy()">Hoy</button>
        </div>
        <button class="btn btn-primary btn-sm" @click="abrirNueva()">+ Nueva cita</button>
      </div>
    </div>

    <BuscadorAvanzado :campos="camposBusqueda" :model-value="filtros" @update:model-value="setFiltros" />

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando agenda…</p>

    <div v-else class="vista-wrap" :class="{ atenuado: refrescando }">
      <!-- ── DÍA ─────────────────────────────────────────────────────── -->
      <template v-if="vista === 'dia'">
        <div v-if="!citasDelDia.length" class="empty">
          {{ hayFiltro ? 'Sin coincidencias en este día.' : 'Sin citas para este día.' }}
          <button v-if="hayFiltro" class="btn btn-ghost btn-sm ampliar" @click="vista = 'mes'">Buscar en el mes</button>
          <p v-else-if="proxima" class="proxima-nota">
            La próxima es el <strong>{{ labelFecha(proxima.fecha) }}</strong>.
            <button class="btn btn-ghost btn-sm ampliar" @click="fecha = proxima.fecha">Ir a ese día</button>
            <button class="btn btn-ghost btn-sm ampliar" @click="vista = 'mes'">Ver el mes</button>
          </p>
        </div>

        <div v-else class="agenda-list">
          <template v-for="(c, i) in citasDelDia" :key="c.id">
            <div v-if="i === indiceAhora" class="ahora-linea">
              <span class="ahora-punto"></span><span class="ahora-texto">Ahora</span><span class="ahora-raya"></span>
            </div>

            <article class="card agenda-item" :class="{ off: c.estado === 'Cancelada' }">
              <div class="ag-hora">{{ hora(c.fecha_hora) }}</div>
              <img class="ag-especie-ic" :src="urlFoto(c.mascota.foto_url) || fotoEspecie(c.mascota.especie)" :alt="c.mascota.especie" />
              <div class="ag-main">
                <div class="ag-top">
                  <strong>{{ c.mascota.nombre }}</strong>
                  <span class="ag-especie">{{ c.mascota.especie }}</span>
                  <span v-if="c.mascota.alergias" class="badge alergia" :title="c.mascota.alergias">⚠ {{ c.mascota.alergias }}</span>
                  <span class="badge" :class="c.estado">{{ c.estado }}</span>
                </div>
                <p class="ag-servicio">
                  {{ c.servicio }}
                  <template v-if="c.especialista"> · {{ c.especialista.emoji }} {{ c.especialista.nombre }}</template>
                </p>
                <p class="ag-dueno">
                  {{ c.dueno.nombre }}
                  <template v-if="c.dueno.telefono"> · {{ c.dueno.telefono }}</template>
                </p>
                <p v-if="c.notas_pre_cita" class="ag-notas">"{{ c.notas_pre_cita }}"</p>
              </div>
              <div class="ag-actions">
                <select
                  class="select estado-select"
                  :value="c.estado"
                  @change="cambiarEstado(c, $event.target.value)"
                  :aria-label="`Estado de la cita de ${c.mascota.nombre}`"
                >
                  <option v-for="e in ESTADOS" :key="e">{{ e }}</option>
                </select>
                <button
                  v-if="puedeReagendar(c)"
                  type="button"
                  class="btn btn-ghost btn-sm"
                  @click="citaReagendando = c"
                >Reagendar</button>
                <RouterLink :to="`/ficha/${c.mascota.id}`" class="btn btn-primary btn-sm">Abrir ficha</RouterLink>
              </div>
            </article>
          </template>

          <div v-if="indiceAhora === citasDelDia.length" class="ahora-linea">
            <span class="ahora-punto"></span><span class="ahora-texto">Ahora</span><span class="ahora-raya"></span>
          </div>
        </div>
      </template>

      <!-- ── SEMANA ──────────────────────────────────────────────────── -->
      <div v-else-if="vista === 'semana'" class="semana-grid">
        <section
          v-for="d in diasDelRango" :key="d"
          class="col-dia card" :class="{ hoy: d === hoy() }"
        >
          <header class="col-head">
            <button class="col-fecha" @click="irADia(d)" :title="`Ver ${labelFecha(d)}`">
              <span class="col-dow">{{ DIAS_CORTOS[(aDate(d).getDay() + 6) % 7] }}</span>
              <span class="col-num">{{ numeroDia(d) }}</span>
            </button>
            <span class="col-conteo">{{ citasDe(d).length }}</span>
            <button class="col-mas" @click="abrirNueva(d)" :aria-label="`Nueva cita el ${labelFecha(d)}`" title="Nueva cita">+</button>
          </header>

          <p v-if="!citasDe(d).length" class="col-vacio">—</p>
          <RouterLink
            v-for="c in citasDe(d)" :key="c.id"
            :to="`/ficha/${c.mascota.id}`"
            class="mini" :class="c.estado"
            :title="`${hora(c.fecha_hora)} · ${c.mascota.nombre} · ${c.servicio}${c.especialista ? ' · ' + c.especialista.nombre : ''}`"
          >
            <span class="mini-hora">{{ hora(c.fecha_hora) }}</span>
            <strong class="mini-nombre">{{ c.mascota.nombre }}</strong>
            <span class="mini-detalle">
              <template v-if="c.especialista">{{ c.especialista.emoji }} </template>{{ c.servicio }}
            </span>
          </RouterLink>
        </section>
      </div>

      <!-- ── MES ─────────────────────────────────────────────────────── -->
      <div v-else class="mes">
        <div class="mes-dows">
          <span v-for="d in DIAS_CORTOS" :key="d">{{ d }}</span>
        </div>
        <div class="mes-grid">
          <section
            v-for="d in diasDelRango" :key="d"
            class="celda" :class="{ hoy: d === hoy(), fuera: esDeOtroMes(d), 'con-citas': citasDe(d).length }"
          >
            <header class="celda-head">
              <button class="celda-num" @click="irADia(d)" :title="`Ver ${labelFecha(d)}`">{{ numeroDia(d) }}</button>
              <span v-if="citasDe(d).length" class="celda-conteo">{{ citasDe(d).length }}</span>
            </header>
            <RouterLink
              v-for="c in citasDe(d).slice(0, 3)" :key="c.id"
              :to="`/ficha/${c.mascota.id}`"
              class="mini compacta" :class="c.estado"
              :title="`${hora(c.fecha_hora)} · ${c.mascota.nombre} · ${c.servicio}`"
            >
              <span class="mini-hora">{{ hora(c.fecha_hora) }}</span>
              <strong class="mini-nombre">{{ c.mascota.nombre }}</strong>
            </RouterLink>
            <button v-if="citasDe(d).length > 3" class="celda-mas" @click="irADia(d)">
              +{{ citasDe(d).length - 3 }} más
            </button>
          </section>
        </div>
      </div>

      <p v-if="vista !== 'dia' && !citasFiltradas.length" class="empty">
        {{ hayFiltro ? `Sin coincidencias en ${vista === 'semana' ? 'esta semana' : 'este mes'}.` : `Sin citas en ${vista === 'semana' ? 'esta semana' : 'este mes'}.` }}
      </p>
    </div>

    <NuevaCitaModal
      v-if="modalNueva"
      :fecha-inicial="fechaNueva"
      @close="modalNueva = false"
      @creada="alCrearCita"
    />
  </div>

  <ReagendarCitaModal
    v-if="citaReagendando"
    :cita="citaReagendando"
    @close="citaReagendando = null"
    @reagendada="alReagendar"
  />
</template>

<style scoped>
.head-row { align-items: flex-end; margin-bottom: 1rem; }
/* Solo la inicial: `capitalize` deja "10 De Agosto De 2026", que no es como
   se escribe una fecha en español. */
.label-rango { margin-bottom: 0; }
.label-rango::first-letter { text-transform: uppercase; }
.conteo { color: var(--ink-soft); font-size: var(--t-base); }
.head-actions { display: flex; align-items: center; gap: 0.7rem; flex-wrap: wrap; }
.date-nav { display: flex; gap: 0.4rem; align-items: center; }
.date-input { width: auto; padding: 0.45em 0.7em; }

/* Selector de alcance: un solo control, en el sitio donde se está mirando la
   agenda — cambiar de día a semana o mes no navega a otra pantalla. */
.segmented {
  display: inline-flex; padding: 3px; gap: 2px;
  background: var(--bg); border: 1.5px solid var(--line-strong);
  border-radius: var(--r-full);
}
.seg-btn {
  font-size: var(--t-sm); font-weight: 600; color: var(--ink-soft);
  padding: 0.35em 0.95em; border-radius: var(--r-full);
  transition: background 0.18s, color 0.18s;
}
.seg-btn:hover { background: var(--accent-tint); color: var(--accent-strong); }
.seg-btn.active { background: var(--brand); color: #fff; }

/* La recarga no vacía la pantalla: los datos viejos se atenúan y son
   reemplazados en su sitio, para que el cambio de alcance se lea como un
   zoom y no como una pantalla nueva. */
.vista-wrap { transition: opacity 0.18s; }
.vista-wrap.atenuado { opacity: 0.45; }
.ampliar { margin-left: 0.6rem; }

/* ── Día ── */
.ahora-linea { display: flex; align-items: center; gap: 0.5rem; margin: 0.3rem 0; }
.ahora-punto { width: 9px; height: 9px; border-radius: 50%; background: #dc2626; flex: none; }
.ahora-texto { font-size: var(--t-micro); font-weight: 700; color: #dc2626; text-transform: uppercase; letter-spacing: 0.03em; }
.ahora-raya { flex: 1; height: 2px; background: #dc2626; opacity: 0.35; }

.agenda-list { display: flex; flex-direction: column; gap: 0.8rem; }
.agenda-item { display: flex; gap: 1.2rem; align-items: flex-start; }
.agenda-item.off { opacity: 0.55; }
.ag-hora {
  font-weight: 700; font-size: var(--t-lg); color: var(--brand-ink);
  background: var(--brand-tint); border-radius: var(--r-md);
  padding: 0.4em 0.7em; flex: none;
}
.ag-especie-ic {
  flex: none; align-self: center;
  width: 42px; height: 42px; object-fit: cover;
  background: var(--bg); border-radius: 50%;
}
.ag-main { flex: 1; min-width: 0; }
.ag-top { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
.ag-top strong { font-size: var(--t-lg); }
.ag-especie { color: var(--ink-soft); font-size: var(--t-sm); text-transform: capitalize; }
.ag-servicio { font-size: var(--t-base); margin-top: 0.15rem; }
.ag-dueno { color: var(--ink-soft); font-size: var(--t-sm); margin-top: 0.1rem; }
.ag-notas { color: var(--ink-soft); font-style: italic; font-size: var(--t-sm); margin-top: 0.3rem; }
.ag-actions { display: flex; flex-direction: column; gap: 0.5rem; align-items: stretch; flex: none; }
.estado-select { padding: 0.45em 0.7em; font-size: var(--t-sm); }

.badge.alergia {
  background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
  font-size: var(--t-micro); font-weight: 600; padding: 0.15em 0.6em; border-radius: var(--r-full);
}

/* ── Tarjeta compacta de cita (semana y mes) ── */
.mini {
  display: block; padding: 0.4rem 0.55rem; margin-bottom: 0.35rem;
  border-radius: var(--r-sm); border-left: 3px solid var(--line-strong);
  background: var(--bg); font-size: var(--t-micro); line-height: 1.3;
  transition: background 0.15s, border-color 0.15s;
}
.mini:hover { background: var(--accent-hover); }
.mini.Agendada { border-left-color: var(--warn-ink); }
.mini.Confirmada { border-left-color: var(--brand); }
.mini.Completada { border-left-color: var(--ok); }
.mini.Cancelada { border-left-color: var(--danger); opacity: 0.5; }
.mini.Cancelada .mini-nombre { text-decoration: line-through; }
.mini-hora { font-weight: 700; color: var(--brand-ink); margin-right: 0.3rem; }
.mini-nombre { font-weight: 600; }
.mini-detalle {
  display: block; color: var(--ink-soft); font-size: var(--t-micro);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

/* ── Semana ── */
.semana-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.6rem; }
.col-dia { padding: 0.6rem; min-height: 160px; }
.col-dia.hoy { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-tint); }
.col-head {
  display: flex; align-items: center; gap: 0.35rem;
  padding-bottom: 0.5rem; margin-bottom: 0.5rem; border-bottom: 1px solid var(--line);
}
.col-fecha {
  display: flex; align-items: baseline; gap: 0.35rem; flex: 1; min-width: 0;
  padding: 0.1rem 0.25rem; border-radius: var(--r-sm);
}
.col-fecha:hover { background: var(--accent-hover); }
.col-dow { font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.03em; }
.col-num { font-size: var(--t-lg); font-weight: 700; }
.col-dia.hoy .col-num { color: var(--accent-strong); }
.col-conteo {
  font-size: var(--t-micro); font-weight: 700; color: var(--ink-soft);
  background: var(--bg); border-radius: var(--r-full); padding: 0.1em 0.5em;
}
.col-mas {
  font-size: 1rem; font-weight: 700; line-height: 1; color: var(--ink-soft);
  width: 22px; height: 22px; border-radius: 50%; flex: none;
  opacity: 0; transition: opacity 0.15s, background 0.15s;
}
.col-dia:hover .col-mas, .col-mas:focus-visible { opacity: 1; }
.col-mas:hover { background: var(--accent-tint); color: var(--accent-strong); }
.col-vacio { color: var(--line-strong); text-align: center; padding: 0.8rem 0; }
.proxima-nota { margin-top: 0.6rem; display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; justify-content: center; }

/* ── Mes ── */
.mes-dows {
  display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.5rem;
  margin-bottom: 0.4rem; padding: 0 0.2rem;
}
.mes-dows span {
  font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft);
  text-transform: uppercase; letter-spacing: 0.04em;
}
.mes-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.5rem; }
.celda {
  background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-md);
  padding: 0.45rem; min-height: 108px;
}
.celda.fuera { background: var(--bg); }
.celda.fuera .celda-num { color: var(--ink-soft); opacity: 0.6; }
.celda.hoy { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-tint); }
.celda-head { display: flex; align-items: center; justify-content: space-between; gap: 0.3rem; margin-bottom: 0.35rem; }
.celda-num {
  font-size: var(--t-sm); font-weight: 700;
  min-width: 24px; height: 24px; border-radius: 50%;
  transition: background 0.15s, color 0.15s;
}
.celda-num:hover { background: var(--accent-tint); color: var(--accent-strong); }
.celda.hoy .celda-num { background: var(--accent); color: #fff; }
.celda-conteo { font-size: var(--t-micro); font-weight: 700; color: var(--ink-soft); }
.celda-mas {
  font-size: var(--t-micro); font-weight: 600; color: var(--accent-strong);
  padding: 0.1rem 0.25rem; border-radius: var(--r-sm);
}
.celda-mas:hover { background: var(--accent-hover); }
.mini.compacta { padding: 0.25rem 0.4rem; margin-bottom: 0.25rem; font-size: var(--t-micro); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mini.compacta .mini-hora { margin-right: 0.25rem; }

@media (max-width: 1100px) {
  .semana-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  .celda { min-height: 92px; }
}
@media (max-width: 760px) {
  .semana-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .col-dia { min-height: 0; }
  .col-mas { opacity: 1; }
  /* En el mes, a este ancho, las tarjetas no caben legibles: queda la rejilla
     con el número y el conteo, que es lo que se lee de un vistazo. */
  .mini.compacta, .celda-mas { display: none; }
  .celda { min-height: 58px; padding: 0.3rem 0.15rem; }
  /* La celda mide ~46px: número arriba y conteo debajo, no lado a lado. */
  .celda-head { flex-direction: column; align-items: center; gap: 0.05rem; margin-bottom: 0; }
  .celda-conteo {
    background: var(--brand-tint); color: var(--brand-ink);
    border-radius: var(--r-full); padding: 0 0.4em; font-size: var(--t-micro);
  }
  .mes-grid, .mes-dows { gap: 0.3rem; }
  .mes-dows { text-align: center; }
}
@media (max-width: 640px) {
  .semana-grid { grid-template-columns: 1fr; }
  .agenda-item { flex-direction: column; gap: 0.7rem; }
  .ag-actions { flex-direction: row; width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
  .vista-wrap, .seg-btn, .mini, .col-mas, .celda-num { transition: none; }
}
</style>
