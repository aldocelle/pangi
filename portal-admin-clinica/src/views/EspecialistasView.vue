<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { api, apiError, DIAS } from '../api.js';
import { retratoDe } from '../retratos.js';

const router = useRouter();
const especialistas = ref([]);
const loading = ref(true);
const error = ref('');
const busy = ref(false);

/* La importación del legado (ACVet) dejó decenas de especialistas
   inactivos, uno por cada médico del sistema anterior — no son personal
   real y no tiene sentido verlos en el día a día, pero tampoco se pueden
   borrar: quedan enlazados a miles de notas clínicas y ventas históricas
   que perderían a qué especialista pertenecen. Ocultos por defecto, pero
   accesibles con el toggle para el caso raro de necesitar revisar uno. */
const mostrarInactivos = ref(false);
const especialistasVisibles = computed(() =>
  mostrarInactivos.value ? especialistas.value : especialistas.value.filter((e) => e.activo)
);
const inactivosOcultos = computed(() => especialistas.value.filter((e) => !e.activo).length);

/* Los retratos se sirven desde public/ de este frontend, no desde el backend:
   ver src/retratos.js. Quien no tiene retrato muestra su emoji. */

async function nuevo() {
  busy.value = true;
  try {
    const { data } = await api.post('/catalogo/especialistas', {
      nombre: 'Nuevo especialista',
      especialidad: '',
      orden: especialistas.value.length,
      horarios: [{
        nombre: 'Horario 1', todos_servicios: true, servicio_ids: [],
        bloques: [
          ...Array.from({ length: 5 }, (_, i) => ({ dia_semana: i, hora_inicio: '10:00', hora_fin: '13:00' })),
          ...Array.from({ length: 5 }, (_, i) => ({ dia_semana: i, hora_inicio: '15:00', hora_fin: '17:00' })),
          { dia_semana: 5, hora_inicio: '10:00', hora_fin: '13:00' },
        ],
      }],
    });
    router.push(`/especialistas/${data.id}`);
  } catch (e) {
    error.value = apiError(e, 'No se pudo crear el especialista.');
  } finally {
    busy.value = false;
  }
}

function resumenDisp(e) {
  const bloques = e.horarios?.length ? e.horarios.flatMap((h) => h.bloques) : e.disponibilidad;
  if (!bloques?.length) return 'Sin horario configurado';
  const dias = [...new Set(bloques.map((d) => d.dia_semana))].sort();
  return dias.map((d) => DIAS[d].slice(0, 3)).join(', ');
}

async function toggleActivo(e) {
  await api.put(`/catalogo/especialistas/${e.id}`, { nombre: e.nombre, especialidad: e.especialidad, emoji: e.emoji, orden: e.orden, activo: !e.activo });
  await cargar();
}

async function eliminar(e) {
  let impacto;
  try {
    ({ data: impacto } = await api.get(`/catalogo/especialistas/${e.id}/impacto`));
  } catch (err) {
    alert(apiError(err, 'No se pudo revisar el impacto.'));
    return;
  }
  const { citas, notas_clinicas, ventas, transacciones } = impacto;
  const total = citas + notas_clinicas + ventas + transacciones;
  const detalle = total
    ? `\n\nQuedarán sin especialista asociado (no se borran):\n` +
      [
        citas && `• ${citas} citas`,
        notas_clinicas && `• ${notas_clinicas} notas clínicas`,
        ventas && `• ${ventas} ventas`,
        transacciones && `• ${transacciones} transacciones`,
      ].filter(Boolean).join('\n')
    : '';
  if (!confirm(`¿Eliminar a ${e.nombre}? No se puede deshacer.${detalle}`)) return;
  try {
    await api.delete(`/catalogo/especialistas/${e.id}`);
    await cargar();
  } catch (err) {
    alert(apiError(err, 'No se pudo eliminar el especialista.'));
  }
}

async function cargar() {
  loading.value = true;
  try {
    const { data } = await api.get('/catalogo/especialistas/todos');
    especialistas.value = data;
  } finally {
    loading.value = false;
  }
}

const RESERVA_URL = import.meta.env.VITE_RESERVA_URL || 'http://localhost:3101';

function copiar(e) {
  navigator.clipboard?.writeText(`${RESERVA_URL}/b/${e.slug}`);
}

onMounted(cargar);
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Especialistas</h1>
        <p class="page-sub">El equipo que atiende y su disponibilidad semanal.</p>
      </div>
      <button class="btn btn-primary" @click="nuevo">+ Nuevo especialista</button>
    </div>

    <label v-if="inactivosOcultos" class="toggle-inactivos">
      <input type="checkbox" v-model="mostrarInactivos" />
      Mostrar inactivos ({{ inactivosOcultos }} ocultos)
    </label>

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>
    <div v-else class="tabla card">
      <div class="t-head t-row">
        <span>Especialista</span>
        <span>Disponibilidad</span>
        <span>Estado</span>
        <span></span>
      </div>
      <div
        v-for="e in especialistasVisibles" :key="e.id"
        class="t-row" :class="{ off: !e.activo }"
      >
        <span class="fila-nombre">
          <img v-if="retratoDe(e)" :src="retratoDe(e)" class="esp-foto" alt="" />
          <span v-else class="fila-ic" aria-hidden="true">{{ e.emoji }}</span>
          <RouterLink :to="`/especialistas/${e.id}`" class="esp-link">
            <strong>{{ e.nombre }}</strong>
            <small v-if="e.especialidad">{{ e.especialidad }}</small>
          </RouterLink>
        </span>
        <span class="esp-disp">{{ resumenDisp(e) }}</span>
        <span>
          <span class="tag" :class="e.activo ? 'tag-ok' : 'hid'">{{ e.activo ? 'Activo' : 'Inactivo' }}</span>
          <span v-if="e.slug" class="tag">/b/{{ e.slug }}</span>
        </span>
        <span class="acciones">
          <RouterLink :to="`/especialistas/${e.id}`" class="btn btn-ghost btn-sm">Editar</RouterLink>
          <button class="btn btn-ghost btn-sm" @click="copiar(e)">Compartir</button>
          <button class="btn btn-ghost btn-sm" @click="toggleActivo(e)">{{ e.activo ? 'Desactivar' : 'Activar' }}</button>
          <button class="btn btn-ghost btn-sm peligro" @click="eliminar(e)">Eliminar</button>
        </span>
      </div>
    </div>

  </div>
</template>

<style scoped>
/* El patrón de listado vive en app.css; aquí solo lo propio de la vista. */
.toggle-inactivos {
  display: flex; align-items: center; gap: 0.5rem;
  font-size: var(--t-base); color: var(--ink-soft);
  margin-bottom: 0.9rem; cursor: pointer;
}
.t-row { grid-template-columns: 1.5fr 1.1fr 0.9fr auto; min-width: 860px; }
.t-row.off { opacity: 0.55; }
.esp-foto { width: 32px; height: 32px; flex: none; border-radius: 50%; object-fit: cover; }
.esp-link { display: flex; flex-direction: column; min-width: 0; line-height: 1.25; }
.esp-link strong { color: var(--brand-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.esp-link small { color: var(--ink-soft); font-size: var(--t-micro); }
.esp-link:hover strong { text-decoration: underline; }
.esp-disp { color: var(--ink-soft); }
.tag-ok { color: #067647; background: #ecfdf3; }

@media (max-width: 720px) {
  .t-row { min-width: 0; }
}

.form { display: flex; flex-direction: column; gap: 1rem; }
.row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
@media (max-width: 520px) { .row-2 { grid-template-columns: 1fr; } }
.bloq-field { border: 1px solid var(--line); border-radius: var(--r-md); padding: 0.9rem 1rem; }
.bloq-field legend { font-weight: 600; font-size: var(--t-sm); padding: 0 0.3rem; }
.bloque {
  display: grid; grid-template-columns: 1.2fr 1fr auto 1fr auto;
  gap: 0.5rem; align-items: center; margin-bottom: 0.5rem;
}
.bloque .select, .bloque .input { padding: 0.45em 0.6em; font-size: var(--t-base); }
.quitar { color: var(--danger); font-weight: 700; padding: 0.3em 0.5em; border-radius: var(--r-sm); }
.quitar:hover { background: var(--danger-tint); }
.check { display: flex; align-items: center; gap: 0.5rem; padding: 0.25rem 0; font-size: var(--t-base); cursor: pointer; }
</style>
