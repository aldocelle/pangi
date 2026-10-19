<script setup>
import { ref, computed, onMounted } from 'vue';
import { api, apiError } from '../api.js';

const mensajes = ref([]);
const loading = ref(true);
const error = ref('');
const soloNoLeidos = ref(false);
const abierto = ref(null);

const visibles = computed(() =>
  soloNoLeidos.value ? mensajes.value.filter((m) => !m.leido) : mensajes.value
);
const sinLeer = computed(() => mensajes.value.filter((m) => !m.leido).length);

function fecha(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleDateString('es-CL', { day: '2-digit', month: 'short' })
    + ' · ' + d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
}

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const { data } = await api.get('/contacto/mensajes');
    mensajes.value = data;
  } catch (e) {
    error.value = apiError(e, 'No pudimos cargar las consultas.');
  } finally {
    loading.value = false;
  }
}

/* Abrir una consulta la marca leída: si hubiera que marcarla aparte, la
   bandeja se llenaría de mensajes ya atendidos pero sin marcar. */
async function abrir(m) {
  abierto.value = abierto.value === m.id ? null : m.id;
  if (abierto.value !== m.id || m.leido) return;
  m.leido = true;
  try {
    await api.put(`/contacto/mensajes/${m.id}/leido`);
  } catch {
    m.leido = false; // el servidor manda: si no se pudo, se revierte
  }
}

function responder(m) {
  const asunto = m.asunto ? `Re: ${m.asunto}` : 'Respuesta a tu consulta';
  window.location.href =
    `mailto:${m.email}?subject=${encodeURIComponent(asunto)}`;
}

onMounted(cargar);
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Consultas del sitio</h1>
        <p class="page-sub">
          Mensajes enviados desde el formulario de contacto de la página pública.
        </p>
      </div>
      <label class="chk">
        <input type="checkbox" v-model="soloNoLeidos" />
        Solo sin leer<template v-if="sinLeer"> ({{ sinLeer }})</template>
      </label>
    </div>

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>
    <p v-else-if="!visibles.length" class="empty">
      {{ soloNoLeidos ? 'No hay consultas sin leer.' : 'Todavía no llegan consultas desde el sitio.' }}
    </p>

    <div v-else class="tabla card">
      <div class="t-head t-row">
        <span>De</span>
        <span>Asunto</span>
        <span>Recibida</span>
        <span></span>
      </div>
      <template v-for="m in visibles" :key="m.id">
        <div
          class="t-row t-row-link" :class="{ nuevo: !m.leido }"
          role="button" tabindex="0"
          :aria-expanded="abierto === m.id"
          @click="abrir(m)" @keydown.enter="abrir(m)"
        >
          <span class="fila-nombre">
            <span class="fila-ic">{{ (m.nombre[0] || '?').toUpperCase() }}</span>
            <span class="de">
              <strong>{{ m.nombre }}</strong>
              <small>{{ m.email }}</small>
            </span>
          </span>
          <span class="asunto">
            <span v-if="!m.leido" class="punto" aria-label="Sin leer"></span>
            {{ m.asunto || 'Sin asunto' }}
          </span>
          <span class="cuando">{{ fecha(m.created_at) }}</span>
          <span class="acciones" @click.stop>
            <button class="btn btn-ghost btn-sm" @click="responder(m)">Responder</button>
          </span>
        </div>
        <div v-if="abierto === m.id" class="detalle">
          <p>{{ m.mensaje }}</p>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.t-row { grid-template-columns: 1.4fr 1.5fr 0.8fr auto; min-width: 820px; }
.de { display: flex; flex-direction: column; min-width: 0; line-height: 1.3; }
.de strong { color: var(--brand); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.de small { color: var(--ink-soft); font-size: var(--t-micro); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.asunto { display: flex; align-items: center; gap: 0.5rem; min-width: 0; }
.t-row.nuevo .asunto { font-weight: 600; color: var(--brand); }
/* Punto y negrita a la vez: el color solo no basta para quien no lo distingue. */
.punto { width: 7px; height: 7px; border-radius: 50%; background: var(--accent); flex: none; }

.cuando { color: var(--ink-soft); font-variant-numeric: tabular-nums; }

.detalle {
  padding: 0.2rem 0.4rem 1rem 3.1rem;
  border-bottom: 1px solid var(--line);
}
.detalle p { white-space: pre-wrap; line-height: 1.6; max-width: 70ch; color: var(--ink); }

.chk { display: flex; align-items: center; gap: 0.5rem; font-size: var(--t-base); cursor: pointer; }

@media (max-width: 720px) {
  .t-row { min-width: 0; }
  .detalle { padding-left: 0.4rem; }
}
</style>
