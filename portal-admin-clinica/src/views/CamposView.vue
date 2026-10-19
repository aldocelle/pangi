<script setup>
import { ref, onMounted } from 'vue';
import { api, apiError } from '../api.js';

const campos = ref([]);
const loading = ref(true);
const error = ref('');
const editando = ref(null);
const busy = ref(false);

const TIPOS = { text: 'Text Field', choice: 'Single Choice Field', checkbox: 'Checkbox Field' };

function nuevo() {
  editando.value = {
    etiqueta: '', tipo: 'text', opciones: [], requerido: false, oculto: false,
    orden: campos.value.length, opcionesTexto: '',
  };
}

function editar(f) {
  editando.value = { ...f, opciones: [...f.opciones], opcionesTexto: f.opciones.join('\n') };
}

async function guardar() {
  busy.value = true;
  error.value = '';
  try {
    const payload = { ...editando.value };
    payload.opciones = payload.tipo === 'text'
      ? []
      : payload.opcionesTexto.split('\n').map((s) => s.trim()).filter(Boolean);
    delete payload.opcionesTexto;
    if (payload.id) {
      await api.put(`/catalogo/form-fields/${payload.id}`, payload);
    } else {
      await api.post('/catalogo/form-fields', payload);
    }
    editando.value = null;
    await cargar();
  } catch (e) {
    error.value = apiError(e, 'No se pudo guardar el campo.');
  } finally {
    busy.value = false;
  }
}

async function borrar(f) {
  if (!confirm(`¿Eliminar el campo "${f.etiqueta}"?`)) return;
  await api.delete(`/catalogo/form-fields/${f.id}`);
  await cargar();
}

async function cargar() {
  loading.value = true;
  try {
    const { data } = await api.get('/catalogo/form-fields/todos');
    campos.value = data;
  } finally {
    loading.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Campos de formulario</h1>
        <p class="page-sub">Lo que se le pregunta al cliente al reservar una hora.</p>
      </div>
      <button class="btn btn-primary" @click="nuevo">+ Nuevo campo</button>
    </div>
    <div class="card fijo">
      <strong>Correo Electrónico</strong>
      <span class="tag req">Necesario</span>
      <span class="fijo-note">Recogido con cada cita (cuenta del cliente).</span>
    </div>

    <p v-if="loading" class="empty">Cargando…</p>
    <div v-else class="lista">
      <article v-for="f in campos" :key="f.id" class="card campo">
        <div class="campo-main">
          <strong>{{ f.etiqueta }}</strong>
          <div class="campo-tags">
            <span class="tag">{{ TIPOS[f.tipo] || f.tipo }}</span>
            <span v-if="f.requerido" class="tag req">Necesario</span>
            <span v-if="f.oculto" class="tag hid">Oculto</span>
          </div>
          <p v-if="f.opciones.length" class="campo-ops">{{ f.opciones.join(' · ') }}</p>
        </div>
        <div class="campo-actions">
          <button class="btn btn-ghost btn-sm" @click="editar(f)">Editar</button>
          <button class="btn btn-danger btn-sm" @click="borrar(f)">Eliminar</button>
        </div>
      </article>
    </div>

    <!-- Modal -->
    <div v-if="editando" class="modal-backdrop" @click.self="editando = null">
      <div class="modal" role="dialog" aria-modal="true" aria-label="Editar campo">
        <div class="modal-head">
          <h3>{{ editando.id ? 'Editar campo' : 'Nuevo campo' }}</h3>
          <button class="modal-close" aria-label="Cerrar" @click="editando = null">✕</button>
        </div>

        <form @submit.prevent="guardar" class="form">
          <div class="field">
            <label for="f-etiqueta">Etiqueta *</label>
            <input id="f-etiqueta" class="input" v-model="editando.etiqueta" required placeholder="Ej: Forma de Pago" />
          </div>
          <div class="field">
            <label for="f-tipo">Tipo</label>
            <select id="f-tipo" class="select" v-model="editando.tipo">
              <option value="text">Texto</option>
              <option value="choice">Selección única</option>
              <option value="checkbox">Selección múltiple</option>
            </select>
          </div>
          <div v-if="editando.tipo !== 'text'" class="field">
            <label for="f-ops">Opciones (una por línea)</label>
            <textarea id="f-ops" class="textarea" v-model="editando.opcionesTexto" placeholder="Efectivo&#10;Débito&#10;Crédito"></textarea>
          </div>
          <div class="flags">
            <label class="check"><input type="checkbox" v-model="editando.requerido" /> Necesario</label>
            <label class="check"><input type="checkbox" v-model="editando.oculto" /> Oculto (no se muestra al reservar)</label>
          </div>

          <p v-if="error" class="error-text">{{ error }}</p>
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" @click="editando = null">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="busy">{{ busy ? 'Guardando…' : 'Guardar' }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
.fijo {
  display: flex; align-items: center; gap: 0.7rem; flex-wrap: wrap;
  background: var(--warn-tint); border-color: transparent;
  margin-bottom: 1rem; padding: 1rem 1.2rem;
}
.fijo-note { margin-left: auto; font-size: var(--t-sm); color: var(--warn-ink); }
.lista { display: flex; flex-direction: column; gap: 0.7rem; }
.campo { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.2rem; }
.campo-main { flex: 1; min-width: 0; }
.campo-main strong { color: var(--brand-ink); }
.campo-tags { display: flex; gap: 0.4rem; margin-top: 0.3rem; flex-wrap: wrap; }
.campo-ops { font-size: var(--t-sm); color: var(--ink-soft); margin-top: 0.35rem; }
.campo-actions { display: flex; gap: 0.5rem; flex: none; }
.form { display: flex; flex-direction: column; gap: 1rem; }
.flags { display: flex; gap: 1.4rem; flex-wrap: wrap; }
.check { display: flex; align-items: center; gap: 0.5rem; font-size: var(--t-base); cursor: pointer; }
</style>
