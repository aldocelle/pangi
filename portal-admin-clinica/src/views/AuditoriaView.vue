<script setup>
import { ref, onMounted } from 'vue';
import { api, apiError } from '../api.js';
import Paginador from '../components/Paginador.vue';
import BuscadorAvanzado from '../components/BuscadorAvanzado.vue';

const ACCIONES = [
  'crear', 'editar', 'eliminar', 'abrir', 'cerrar', 'cambio_turno',
  'abono', 'egreso', 'reset_password', 'mover', 'vaciar',
];

const areas = ref([]); // [{id, label}] — viene del backend, no se hardcodea
const CAMPOS_BUSQUEDA = ref([
  { key: 'usuario', label: 'Nombre del usuario', placeholder: 'Buscar por nombre…' },
  { key: 'area', label: 'Área', type: 'select', options: [] },
  { key: 'accion', label: 'Acción', type: 'select', options: ACCIONES.map((a) => ({ value: a, label: a })) },
  { key: 'desde', label: 'Desde', type: 'date', autocompletar: false },
  { key: 'hasta', label: 'Hasta', type: 'date', autocompletar: false },
]);

const logs = ref([]);
const loading = ref(true);
const error = ref('');
const filtros = ref({ usuario: '', area: '', accion: '', desde: '', hasta: '' });
const pagina = ref(1);
const paginacion = ref({ total: 0, total_paginas: 1, limit: 30 });
const detalleAbierto = ref(null);

async function cargarAreas() {
  try {
    const { data } = await api.get('/auditoria/areas');
    areas.value = data;
    CAMPOS_BUSQUEDA.value[1].options = data.map((a) => ({ value: a.id, label: a.label }));
  } catch {
    // Sin el listado de áreas el filtro queda vacío, pero la tabla igual carga.
  }
}

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const params = { page: pagina.value, limit: 30 };
    for (const k of ['usuario', 'area', 'accion', 'desde', 'hasta']) {
      if (filtros.value[k]) params[k] = filtros.value[k];
    }
    const { data } = await api.get('/auditoria', { params });
    logs.value = data.items;
    paginacion.value = { total: data.total, total_paginas: data.total_paginas, limit: data.limit };
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar el registro de actividad.');
  } finally {
    loading.value = false;
  }
}

function buscar() {
  pagina.value = 1;
  cargar();
}

function cambiarPagina(p) {
  pagina.value = p;
  cargar();
}

function toggleDetalle(id) {
  detalleAbierto.value = detalleAbierto.value === id ? null : id;
}

function fecha(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' });
}

const ACCION_LABEL = {
  crear: 'Creó', editar: 'Editó', eliminar: 'Eliminó', abrir: 'Abrió',
  cerrar: 'Cerró', cambio_turno: 'Cambio de turno', abono: 'Abono',
  egreso: 'Egreso', reset_password: 'Reseteó clave', mover: 'Movió', vaciar: 'Vació',
};
function accionLabel(a) {
  return ACCION_LABEL[a] || a;
}

onMounted(() => {
  cargarAreas();
  cargar();
});
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Auditoría</h1>
        <p class="page-sub">Quién hizo qué y cuándo, en las áreas con dinero, accesos o datos clínicos.</p>
      </div>
    </div>

    <BuscadorAvanzado v-model="filtros" :campos="CAMPOS_BUSQUEDA" @buscar="buscar" />

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>
    <p v-else-if="!logs.length" class="empty">Sin actividad registrada con estos filtros.</p>

    <div v-else class="tabla card">
      <div class="t-head t-row">
        <span>Cuándo</span><span>Quién</span><span>Qué hizo</span><span>Área</span><span></span>
      </div>
      <template v-for="l in logs" :key="l.id">
        <div class="t-row t-row-link" @click="toggleDetalle(l.id)">
          <span class="cuando">{{ fecha(l.created_at) }}</span>
          <span class="quien">
            <strong>{{ l.usuario_nombre }}</strong>
            <small v-if="l.usuario_rol">{{ l.usuario_rol }}</small>
          </span>
          <span>
            {{ accionLabel(l.accion) }}
            <small v-if="l.entidad">{{ l.entidad }}</small>
          </span>
          <span class="chip chip-info">{{ l.area_label }}</span>
          <span class="flecha" :class="{ abierta: detalleAbierto === l.id }" aria-hidden="true">›</span>
        </div>
        <div v-if="detalleAbierto === l.id && l.detalle" class="detalle">
          <pre>{{ JSON.stringify(l.detalle, null, 2) }}</pre>
        </div>
      </template>
    </div>
    <Paginador
      v-if="!loading && logs.length"
      :page="pagina" :total-paginas="paginacion.total_paginas"
      :total="paginacion.total" :limit="paginacion.limit"
      @cambiar="cambiarPagina"
    />
  </div>
</template>

<style scoped>
.empty { text-align: center; color: var(--ink-soft); padding: 2rem; }
.error-text { color: var(--danger); margin-bottom: 0.8rem; }

/* Solo la maquetación de ESTA tabla; lo demás se hereda de app.css. Acá había
   una copia completa de las reglas compartidas, y los estilos scoped ganan por
   especificidad: esta vista quedaba fuera de cualquier ajuste al archivo
   común. */
.t-row {
  grid-template-columns: 1.1fr 1.2fr 1.6fr 1fr auto;
  min-width: 720px;
}

.cuando { color: var(--ink-soft); font-variant-numeric: tabular-nums; }
.quien { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
.quien strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.quien small { color: var(--ink-soft); font-size: var(--t-micro); text-transform: capitalize; }

.chip { padding: 0.2em 0.7em; border-radius: 999px; font-size: var(--t-micro); font-weight: 600; justify-self: start; }
.chip-info { background: var(--info-tint); color: var(--info); }

.flecha { color: var(--ink-soft); font-size: 1.1rem; transition: transform 0.15s; }
.flecha.abierta { transform: rotate(90deg); }

.detalle {
  padding: 0.4rem 0.4rem 0.8rem 0.4rem;
  border-bottom: 1px solid var(--line);
  background: var(--bg);
}
.detalle pre {
  font-size: var(--t-micro); background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--r-sm); padding: 0.7rem 0.9rem; overflow-x: auto;
  color: var(--ink-soft);
}

@media (max-width: 720px) { .t-row { min-width: 0; } }
</style>
