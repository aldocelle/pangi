<script setup>
/* Nuevo recordatorio para una mascota. Elegir una plantilla (Ajustes →
   Recordatorios) prellena título, mensaje, la fecha (hoy + su tiempo de
   repetición) y la repetición misma — que es para lo que existen en ACVet:
   «este título aparece en la lista al crear un recordatorio».

   Crear un recordatorio NO manda nada: queda pendiente en la lista de llamados.
   El correo automático tiene sus propios interruptores (y en modo pruebas
   están apagados). */
import { ref, onMounted } from 'vue';
import { api, apiError } from '../api.js';

const emit = defineEmits(['close', 'creado']);

const plantillas = ref([]);
const q = ref('');
const resultados = ref([]);
const buscando = ref(false);
const mascota = ref(null);
const form = ref({ titulo: '', mensaje: '', fecha_programada: hoyMas(30), dias_repeticion: null });
const plantillaId = ref('');
const busy = ref(false);
const error = ref('');

function hoyMas(dias) {
  const d = new Date();
  d.setDate(d.getDate() + dias);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

onMounted(async () => {
  try {
    const { data } = await api.get('/recordatorios/plantillas');
    plantillas.value = data;
  } catch { /* sin plantillas se escribe a mano */ }
});

let t;
function buscar() {
  clearTimeout(t);
  if (q.value.trim().length < 2) { resultados.value = []; return; }
  t = setTimeout(async () => {
    buscando.value = true;
    try {
      const { data } = await api.get('/mascotas/todas', { params: { q: q.value.trim(), limit: 10 } });
      resultados.value = data.items || [];
    } catch { resultados.value = []; } finally { buscando.value = false; }
  }, 300);
}

function usarPlantilla() {
  const p = plantillas.value.find((x) => x.id === plantillaId.value);
  if (!p) return;
  form.value = {
    titulo: p.titulo,
    mensaje: p.mensaje || '',
    fecha_programada: p.dias_repeticion ? hoyMas(p.dias_repeticion) : form.value.fecha_programada,
    dias_repeticion: p.dias_repeticion,
  };
}

async function guardar() {
  busy.value = true;
  error.value = '';
  try {
    const { data } = await api.post('/recordatorios', {
      mascota_id: mascota.value.id,
      titulo: form.value.titulo.trim(),
      mensaje: form.value.mensaje.trim() || null,
      fecha_programada: form.value.fecha_programada,
      dias_repeticion: form.value.dias_repeticion || null,
    });
    emit('creado', data);
  } catch (e) {
    error.value = apiError(e, 'No se pudo crear el recordatorio.');
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Nuevo recordatorio">
      <div class="modal-head">
        <h3>Nuevo recordatorio</h3>
        <button class="modal-close" aria-label="Cerrar" @click="emit('close')">✕</button>
      </div>

      <div v-if="!mascota" class="form">
        <input class="input" v-model="q" placeholder="Buscar mascota o tutor… (mín. 2 letras)" aria-label="Buscar mascota" autofocus @input="buscar" />
        <p v-if="buscando" class="empty">Buscando…</p>
        <button v-for="m in resultados" :key="m.id" type="button" class="opcion" @click="mascota = m">
          <strong>{{ m.nombre }}</strong> <span class="muted">{{ m.dueno_nombre }}</span>
        </button>
      </div>

      <form v-else class="form" @submit.prevent="guardar">
        <p class="elegida">
          <strong>{{ mascota.nombre }}</strong> <span class="muted">· {{ mascota.dueno_nombre }}</span>
          <button type="button" class="btn btn-ghost btn-sm" @click="mascota = null">Cambiar</button>
        </p>
        <div v-if="plantillas.length" class="field">
          <label for="nr-pl">Plantilla</label>
          <select id="nr-pl" class="select" v-model="plantillaId" @change="usarPlantilla">
            <option value="">— Escribir a mano —</option>
            <option v-for="p in plantillas" :key="p.id" :value="p.id">{{ p.titulo }}</option>
          </select>
        </div>
        <div class="field">
          <label for="nr-tit">Título *</label>
          <input id="nr-tit" class="input" v-model="form.titulo" required minlength="2" maxlength="255" />
        </div>
        <div class="field">
          <label for="nr-msg">Mensaje</label>
          <textarea id="nr-msg" class="textarea" v-model="form.mensaje"></textarea>
          <small class="muted">Las variables (@mascota_nombre, @fecha…) se completan solas al avisar.</small>
        </div>
        <div class="grid-2">
          <div class="field">
            <label for="nr-fecha">Fecha *</label>
            <input id="nr-fecha" class="input" type="date" v-model="form.fecha_programada" required />
          </div>
          <div class="field">
            <label for="nr-rep">Repetir cada (días)</label>
            <input id="nr-rep" class="input" type="number" min="1" max="3650" v-model.number="form.dias_repeticion" placeholder="No se repite" />
          </div>
        </div>
        <p v-if="error" class="error-text">{{ error }}</p>
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" @click="emit('close')">Cancelar</button>
          <button class="btn btn-primary" :disabled="busy">{{ busy ? 'Guardando…' : 'Crear recordatorio' }}</button>
        </div>
      </form>
    </div>
  </div>
</template>

<style scoped>
.form { display: flex; flex-direction: column; gap: 0.8rem; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
.muted { color: var(--ink-soft); font-size: var(--t-sm); }
.opcion {
  display: flex; gap: 0.5rem; align-items: baseline; text-align: left; padding: 0.55rem 0.7rem;
  border: 1px solid var(--line); border-radius: var(--r-sm); background: var(--surface);
  font-family: var(--font); font-size: var(--t-base); cursor: pointer;
}
.opcion:hover { background: var(--accent-hover); }
.elegida { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
</style>
