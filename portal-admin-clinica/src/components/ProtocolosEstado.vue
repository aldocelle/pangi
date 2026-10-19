<script setup>
import { ref, onMounted } from 'vue';
import { api, apiError } from '../api.js';

const props = defineProps({
  mascotaId: { type: String, required: true },
});

const protocolos = ref([]);
const loading = ref(true);
const registrando = ref(null); // protocolo elegido para registrar aplicación, o null
const fecha = ref('');
const notas = ref('');
const busy = ref(false);
const error = ref('');

const ETIQUETA_ESTADO = {
  vencido: 'Vencido', proximo: 'Próximo', al_dia: 'Al día', pendiente: 'Sin registrar',
};

function fmtCorta(iso) {
  if (!iso) return '—';
  const [a, m, d] = iso.split('-');
  return `${d}/${m}/${a}`;
}

async function cargar() {
  loading.value = true;
  try {
    const { data } = await api.get(`/protocolos/mascota/${props.mascotaId}/estado`);
    protocolos.value = data;
  } catch {
    protocolos.value = [];
  } finally {
    loading.value = false;
  }
}

function abrirRegistro(p) {
  registrando.value = p;
  fecha.value = new Date().toISOString().slice(0, 10);
  notas.value = '';
  error.value = '';
}

async function guardarAplicacion() {
  busy.value = true;
  error.value = '';
  try {
    await api.post(`/protocolos/mascota/${props.mascotaId}/aplicaciones`, {
      protocolo_id: registrando.value.protocolo_id,
      fecha_aplicacion: fecha.value,
      notas: notas.value.trim() || null,
    });
    registrando.value = null;
    await cargar();
  } catch (e) {
    error.value = apiError(e, 'No se pudo registrar la aplicación.');
  } finally {
    busy.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <div id="sec-protocolos" class="card">
    <div class="section-head">
      <h2 class="section-title">💉 Protocolos preventivos</h2>
    </div>

    <p v-if="loading" class="empty">Cargando…</p>
    <p v-else-if="!protocolos.length" class="empty">
      No hay protocolos configurados para esta especie/edad.
    </p>
    <div v-else class="scroll-x">
      <table class="tabla-datos">
        <thead>
          <tr>
            <th>Protocolo</th>
            <th>Última aplicación</th>
            <th>Próxima fecha</th>
            <th>Estado</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in protocolos" :key="p.protocolo_id">
            <td>{{ p.nombre }}</td>
            <td>{{ fmtCorta(p.ultima_aplicacion) }}</td>
            <td>{{ fmtCorta(p.proxima_fecha) }}</td>
            <td><span class="pp-badge" :class="`pp-${p.estado}`">{{ ETIQUETA_ESTADO[p.estado] || p.estado }}</span></td>
            <td><button class="btn btn-ghost btn-sm" @click="abrirRegistro(p)">Registrar aplicación</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal: registrar aplicación -->
    <div v-if="registrando" class="modal-backdrop" @click.self="registrando = null">
      <div class="modal" role="dialog" aria-modal="true" aria-label="Registrar aplicación">
        <div class="modal-head">
          <h3>Registrar aplicación — {{ registrando.nombre }}</h3>
          <button class="modal-close" aria-label="Cerrar" @click="registrando = null">✕</button>
        </div>
        <form @submit.prevent="guardarAplicacion" class="form">
          <div class="field">
            <label for="pp-fecha">Fecha de aplicación *</label>
            <input id="pp-fecha" class="input" type="date" v-model="fecha" required />
          </div>
          <div class="field">
            <label for="pp-notas">Notas</label>
            <textarea id="pp-notas" class="textarea" v-model="notas" placeholder="Lote, laboratorio, observaciones…"></textarea>
          </div>
          <p v-if="error" class="error-text">{{ error }}</p>
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" @click="registrando = null">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="busy">{{ busy ? 'Guardando…' : 'Guardar' }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Tabla compartida (`.tabla-datos`, app.css): no vuelve a declarar separador
   ni encabezado. Era una tercera copia de las mismas reglas. */
.pp-badge { display: inline-block; padding: 0.2em 0.65em; border-radius: var(--r-full); font-size: var(--t-micro); font-weight: 600; border: 1px solid transparent; }
.pp-vencido { background: var(--danger-tint); color: var(--danger); }
.pp-proximo { background: var(--warn-tint); color: var(--warn-ink); }
.pp-al_dia { background: var(--ok-tint); color: var(--ok); }
.pp-pendiente { background: var(--bg); color: var(--ink-soft); border-color: var(--line); }
.form { display: flex; flex-direction: column; gap: 1rem; }
</style>
