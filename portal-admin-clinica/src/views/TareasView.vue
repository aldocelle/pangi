<script setup>
import { ref, computed, onMounted } from 'vue';
import { api, apiError } from '../api.js';

const tareas = ref([]);
const staff = ref([]);
const loading = ref(true);
const error = ref('');

// Antes solo había pendiente/completada (un checkbox). `en_curso` agrega un
// tercer estado intermedio sin tocar lo que ya filtraba por "pendientes": una
// tarea en curso sigue siendo "no completada", solo se subdivide la vista.
const FILTROS = [
  { id: 'pendientes', label: 'Pendientes' },
  { id: 'en_curso', label: 'En curso' },
  { id: 'completadas', label: 'Completadas' },
  { id: 'todas', label: 'Todas' },
];
const filtro = ref('pendientes');

function estadoDe(t) {
  if (t.completada) return 'completada';
  return t.en_curso ? 'en_curso' : 'pendiente';
}

const tareasFiltradas = computed(() => {
  if (filtro.value === 'todas') return tareas.value;
  if (filtro.value === 'completadas') return tareas.value.filter((t) => t.completada);
  if (filtro.value === 'en_curso') return tareas.value.filter((t) => !t.completada && t.en_curso);
  return tareas.value.filter((t) => !t.completada && !t.en_curso);
});

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const { data } = await api.get('/tareas');
    tareas.value = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar las tareas.');
  } finally {
    loading.value = false;
  }
}

async function cargarStaff() {
  try {
    const { data } = await api.get('/staff');
    staff.value = data.filter((u) => u.es_activo);
  } catch {
    staff.value = [];
  }
}

/* Crear tarea */
const modal = ref(false);
const guardando = ref(false);
const errorModal = ref('');
const PRIORIDADES = [
  { id: 'alta', label: 'Alta' },
  { id: 'media', label: 'Media' },
  { id: 'baja', label: 'Baja' },
];
const vacio = { titulo: '', descripcion: '', asignado_a_id: '', fecha_vencimiento: '', prioridad: 'media', area: '' };
const form = ref({ ...vacio });

function abrirCrear() {
  form.value = { ...vacio };
  errorModal.value = '';
  modal.value = true;
}

async function guardar() {
  guardando.value = true;
  errorModal.value = '';
  try {
    const payload = {
      ...form.value,
      asignado_a_id: form.value.asignado_a_id || null,
      area: form.value.area.trim() || null,
    };
    const { data } = await api.post('/tareas', payload);
    tareas.value.unshift(data);
    modal.value = false;
  } catch (e) {
    errorModal.value = apiError(e, 'No se pudo crear la tarea.');
  } finally {
    guardando.value = false;
  }
}

async function cambiarEstado(t, nuevo) {
  const cambios = nuevo === 'completada'
    ? { completada: true }
    : { completada: false, en_curso: nuevo === 'en_curso' };
  try {
    const { data } = await api.patch(`/tareas/${t.id}`, cambios);
    const i = tareas.value.findIndex((x) => x.id === t.id);
    if (i !== -1) tareas.value[i] = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudo actualizar la tarea.');
  }
}

async function eliminar(t) {
  if (!confirm(`¿Eliminar la tarea "${t.titulo}"?`)) return;
  try {
    await api.delete(`/tareas/${t.id}`);
    tareas.value = tareas.value.filter((x) => x.id !== t.id);
  } catch (e) {
    error.value = apiError(e, 'No se pudo eliminar la tarea.');
  }
}

function vencida(t) {
  return !t.completada && t.fecha_vencimiento && new Date(t.fecha_vencimiento) < new Date(new Date().toDateString());
}

function formatFecha(f) {
  if (!f) return null;
  const [a, m, d] = f.split('-');
  return `${d}-${m}-${a}`;
}

/* Tareas recurrentes — la REGLA ("revisar stock cada semana"), administrada
   aparte: no son tareas del día a día, son la lista de la que algún día
   saldrán (ver comentario en TareasRecurrentesController). Modal propio para
   no mezclar el flujo de "hoy tengo que hacer esto" con "esto se repite". */
const recurrentesModal = ref(false);
const recurrentes = ref([]);
const recurrentesCargadas = ref(false);
const cargandoRecurrentes = ref(false);
const errorRecurrentes = ref('');
const FRECUENCIAS = [
  { id: 'diaria', label: 'Diaria' },
  { id: 'semanal', label: 'Semanal' },
];
const vacioRecurrente = { titulo: '', frecuencia: 'semanal', area: '' };
const formRecurrente = ref({ ...vacioRecurrente });
const guardandoRecurrente = ref(false);
const errorFormRecurrente = ref('');

async function abrirRecurrentes() {
  recurrentesModal.value = true;
  formRecurrente.value = { ...vacioRecurrente };
  errorFormRecurrente.value = '';
  if (!recurrentesCargadas.value) await cargarRecurrentes();
}

async function cargarRecurrentes() {
  cargandoRecurrentes.value = true;
  errorRecurrentes.value = '';
  try {
    const { data } = await api.get('/tareas-recurrentes');
    recurrentes.value = data;
    recurrentesCargadas.value = true;
  } catch (e) {
    errorRecurrentes.value = apiError(e, 'No se pudieron cargar las tareas recurrentes.');
  } finally {
    cargandoRecurrentes.value = false;
  }
}

async function crearRecurrente() {
  guardandoRecurrente.value = true;
  errorFormRecurrente.value = '';
  try {
    const payload = { ...formRecurrente.value, area: formRecurrente.value.area.trim() || null };
    const { data } = await api.post('/tareas-recurrentes', payload);
    recurrentes.value.unshift(data);
    formRecurrente.value = { ...vacioRecurrente };
  } catch (e) {
    errorFormRecurrente.value = apiError(e, 'No se pudo crear la tarea recurrente.');
  } finally {
    guardandoRecurrente.value = false;
  }
}

async function alternarActiva(r) {
  try {
    const { data } = await api.patch(`/tareas-recurrentes/${r.id}`, { activa: !r.activa });
    const i = recurrentes.value.findIndex((x) => x.id === r.id);
    if (i !== -1) recurrentes.value[i] = data;
  } catch (e) {
    errorRecurrentes.value = apiError(e, 'No se pudo actualizar.');
  }
}

async function eliminarRecurrente(r) {
  if (!confirm(`¿Eliminar la tarea recurrente "${r.titulo}"?`)) return;
  try {
    await api.delete(`/tareas-recurrentes/${r.id}`);
    recurrentes.value = recurrentes.value.filter((x) => x.id !== r.id);
  } catch (e) {
    errorRecurrentes.value = apiError(e, 'No se pudo eliminar.');
  }
}

onMounted(() => {
  cargar();
  cargarStaff();
});
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Tareas</h1>
        <p class="page-sub">Pendientes internos del equipo, con responsable y fecha.</p>
      </div>
      <div class="head-acciones">
        <button class="btn btn-ghost" @click="abrirRecurrentes">Tareas recurrentes</button>
        <button class="btn btn-primary" @click="abrirCrear">+ Nueva tarea</button>
      </div>
    </div>

    <div class="barra-filtros">
      <button
        v-for="f in FILTROS" :key="f.id"
        class="chip-filtro" :class="{ activo: filtro === f.id }"
        @click="filtro = f.id"
      >{{ f.label }}</button>
    </div>

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>
    <p v-else-if="!tareasFiltradas.length" class="empty">Sin tareas en este filtro.</p>

    <ul v-else class="lista">
      <li v-for="t in tareasFiltradas" :key="t.id" class="card tarea-card" :class="{ hecha: t.completada }">
        <div class="tarea-cuerpo">
          <div class="tarea-titulo-fila">
            <span class="tarea-titulo">{{ t.titulo }}</span>
            <span v-if="t.prioridad && t.prioridad !== 'media'" class="badge-prioridad" :class="t.prioridad">
              {{ t.prioridad === 'alta' ? 'Alta' : 'Baja' }}
            </span>
            <span v-if="t.area" class="badge-area">{{ t.area }}</span>
          </div>
          <p v-if="t.descripcion" class="tarea-desc">{{ t.descripcion }}</p>
          <div class="tarea-meta">
            <span v-if="t.asignado_a_nombre">👤 {{ t.asignado_a_nombre }}</span>
            <span v-if="t.fecha_vencimiento" :class="{ 'tarea-vencida': vencida(t) }">
              📅 {{ formatFecha(t.fecha_vencimiento) }}
            </span>
          </div>
        </div>
        <select class="input select-estado" :value="estadoDe(t)" @change="cambiarEstado(t, $event.target.value)">
          <option value="pendiente">Pendiente</option>
          <option value="en_curso">En curso</option>
          <option value="completada">Completada</option>
        </select>
        <button class="btn btn-ghost btn-sm peligro" @click="eliminar(t)">Eliminar</button>
      </li>
    </ul>

    <div v-if="modal" class="overlay" @click.self="modal = false">
      <div class="modal-box">
        <h2>Nueva tarea</h2>
        <form @submit.prevent="guardar">
          <div class="field">
            <label for="t-titulo">Título</label>
            <input id="t-titulo" class="input" v-model="form.titulo" required />
          </div>
          <div class="field">
            <label for="t-desc">Descripción</label>
            <textarea id="t-desc" class="textarea" v-model="form.descripcion"></textarea>
          </div>
          <div class="grid-2">
            <div class="field">
              <label for="t-asignado">Responsable</label>
              <select id="t-asignado" class="input" v-model="form.asignado_a_id">
                <option value="">Sin asignar</option>
                <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.cargo || u.nombre }} — {{ u.nombre }}</option>
              </select>
            </div>
            <div class="field">
              <label for="t-fecha">Fecha de cumplimiento</label>
              <input id="t-fecha" class="input" type="date" v-model="form.fecha_vencimiento" />
            </div>
          </div>
          <div class="grid-2">
            <div class="field">
              <label for="t-prioridad">Prioridad</label>
              <select id="t-prioridad" class="input" v-model="form.prioridad">
                <option v-for="p in PRIORIDADES" :key="p.id" :value="p.id">{{ p.label }}</option>
              </select>
            </div>
            <div class="field">
              <label for="t-area">Área</label>
              <input id="t-area" class="input" v-model="form.area" placeholder="Recepción, Inventario…" />
            </div>
          </div>
          <p v-if="errorModal" class="error-text">{{ errorModal }}</p>
          <div class="modal-acciones">
            <button type="button" class="btn btn-ghost" @click="modal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="guardando">
              {{ guardando ? 'Guardando…' : 'Guardar' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="recurrentesModal" class="overlay" @click.self="recurrentesModal = false">
      <div class="modal-box modal-box-lg">
        <h2>Tareas recurrentes</h2>
        <p class="page-sub sub-modal">
          La regla, no la tarea de hoy — el equipo las administra a mano y marca cuándo se hicieron.
        </p>

        <form class="form-recurrente" @submit.prevent="crearRecurrente">
          <input class="input" v-model="formRecurrente.titulo" placeholder="Título (ej: Revisar stock mínimo)" required />
          <select class="input" v-model="formRecurrente.frecuencia">
            <option v-for="f in FRECUENCIAS" :key="f.id" :value="f.id">{{ f.label }}</option>
          </select>
          <input class="input" v-model="formRecurrente.area" placeholder="Área (opcional)" />
          <button type="submit" class="btn btn-primary" :disabled="guardandoRecurrente">
            {{ guardandoRecurrente ? 'Agregando…' : '+ Agregar' }}
          </button>
        </form>
        <p v-if="errorFormRecurrente" class="error-text">{{ errorFormRecurrente }}</p>

        <p v-if="cargandoRecurrentes" class="empty">Cargando…</p>
        <p v-else-if="errorRecurrentes" class="error-text">{{ errorRecurrentes }}</p>
        <p v-else-if="!recurrentes.length" class="empty">Sin tareas recurrentes todavía.</p>
        <ul v-else class="lista-recurrentes">
          <li v-for="r in recurrentes" :key="r.id" :class="{ inactiva: !r.activa }">
            <div class="rec-info">
              <span class="rec-titulo">{{ r.titulo }}</span>
              <span class="rec-meta">{{ r.frecuencia === 'diaria' ? 'Diaria' : 'Semanal' }}<template v-if="r.area"> · {{ r.area }}</template></span>
            </div>
            <div class="rec-acciones">
              <label class="chk">
                <input type="checkbox" :checked="r.activa" @change="alternarActiva(r)" />
                Activa
              </label>
              <button class="btn btn-ghost btn-sm peligro" @click="eliminarRecurrente(r)">Eliminar</button>
            </div>
          </li>
        </ul>

        <div class="modal-acciones">
          <button type="button" class="btn btn-ghost" @click="recurrentesModal = false">Cerrar</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.head-acciones { display: flex; gap: 0.6rem; }
.barra-filtros { display: flex; gap: 0.5rem; margin-bottom: 1.2rem; flex-wrap: wrap; }
.chip-filtro {
  border: 1px solid var(--line); background: var(--surface); color: var(--ink-soft);
  border-radius: var(--r-full); padding: 0.35rem 0.9rem; font-size: var(--t-sm); cursor: pointer;
}
.chip-filtro.activo { background: var(--accent-tint); border-color: var(--accent); color: var(--accent-strong); font-weight: 600; }
.chk { display: flex; align-items: center; gap: 0.4rem; font-size: var(--t-base); color: var(--ink-soft); }
.lista { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.6rem; }
.tarea-card { display: flex; align-items: flex-start; gap: 0.8rem; padding: 0.9rem 1.1rem; }
.tarea-card.hecha { opacity: 0.55; }
.tarea-card.hecha .tarea-titulo { text-decoration: line-through; }
.tarea-cuerpo { flex: 1; min-width: 0; }
.tarea-titulo-fila { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.tarea-titulo { font-weight: 600; }
.badge-prioridad {
  font-size: var(--t-micro); font-weight: 700; border-radius: var(--r-full); padding: 0.1rem 0.6rem;
}
.badge-prioridad.alta { background: var(--danger-tint); color: var(--danger); }
.badge-prioridad.baja { background: var(--ok-tint); color: var(--ok); }
.badge-area {
  font-size: var(--t-micro); color: var(--ink-soft); background: var(--brand-tint);
  border-radius: var(--r-full); padding: 0.1rem 0.6rem;
}
.tarea-desc { margin: 0.2rem 0 0; color: var(--ink-soft); font-size: var(--t-base); }
.tarea-meta { display: flex; gap: 1rem; margin-top: 0.4rem; font-size: var(--t-sm); color: var(--ink-soft); flex-wrap: wrap; }
.tarea-vencida { color: var(--danger); font-weight: 600; }
.select-estado { flex: none; width: auto; align-self: center; }
/* Solo lo propio: el color y el hover se heredan de .peligro en app.css. */
.peligro { flex: none; }

.overlay {
  position: fixed; inset: 0; z-index: 50;
  background: rgba(0, 0, 0, 0.45);
  display: flex; align-items: center; justify-content: center; padding: 1rem;
}
.modal-box {
  background: #fff; border-radius: var(--r-lg);
  padding: 1.4rem; width: 100%; max-width: 480px;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
  max-height: 90vh; overflow-y: auto;
}
.modal-box-lg { max-width: 620px; }
.modal-box h2 { font-size: var(--t-xl); margin: 0 0 1rem; }
.sub-modal { margin-top: -0.6rem; margin-bottom: 1rem; }
.modal-box form { display: flex; flex-direction: column; gap: 0.8rem; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
.modal-acciones { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 0.3rem; }
@media (max-width: 560px) { .grid-2 { grid-template-columns: 1fr; } }

.form-recurrente { flex-direction: row !important; align-items: flex-start; flex-wrap: wrap; margin-bottom: 1rem; }
.form-recurrente input { flex: 1 1 180px; }
.form-recurrente select { flex: 0 0 120px; }
.lista-recurrentes { list-style: none; margin: 0 0 1rem; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
.lista-recurrentes li {
  display: flex; align-items: center; justify-content: space-between; gap: 0.8rem;
  padding: 0.6rem 0.8rem; border: 1px solid var(--line); border-radius: var(--r-md);
}
.lista-recurrentes li.inactiva { opacity: 0.55; }
.rec-info { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; }
.rec-titulo { font-weight: 600; }
.rec-meta { font-size: var(--t-sm); color: var(--ink-soft); }
.rec-acciones { display: flex; align-items: center; gap: 0.8rem; flex: none; }
</style>
