<script setup>
import { ref, computed } from 'vue';
import { api, apiError } from '../api.js';

/* Bloquear horas de la agenda: un trámite, vacaciones, una capacitación. Lo
   bloqueado deja de ofrecerse en el portal de reservas y se ve rayado en la
   Agenda. Las citas que ya estaban en ese horario NO se cancelan: el backend
   dice cuántas son y acá se avisa antes de cerrar, para que recepción las
   mueva o llame al tutor. */
const props = defineProps({
  especialistas: { type: Array, required: true }, // activos: [{ id, nombre }]
  fecha: { type: String, required: true },        // YYYY-MM-DD
  hora: { type: String, default: '' },            // HH:MM, si se abrió desde un hueco
  especialistaId: { type: String, default: '' },
});
const emit = defineEmits(['close', 'creado']);

const MOTIVOS = ['Salida por trámites', 'Almuerzo', 'Vacaciones', 'Capacitación', 'Licencia médica'];

function sumarMin(hhmm, n) {
  const [h, m] = hhmm.split(':').map(Number);
  const t = Math.min(23 * 60 + 59, h * 60 + m + n);
  return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
}

const quien = ref(props.especialistaId);
const dia = ref(props.fecha);
const desde = ref(props.hora || '13:00');
const hasta = ref(sumarMin(props.hora || '13:00', 60));
const otroDia = ref(false);
const diaFin = ref(props.fecha);
const motivo = ref('');
const busy = ref(false);
const error = ref('');
const conflictos = ref(null); // número de citas que quedaron adentro, tras crear

// «Vacaciones» casi nunca es de una tarde: al elegirlo se ofrece el día completo.
function elegirMotivo(m) {
  motivo.value = m;
  if (m === 'Vacaciones' && !otroDia.value) {
    desde.value = '00:00';
    hasta.value = '23:59';
  }
}

const valido = computed(() => motivo.value.trim().length >= 3 && dia.value && desde.value && hasta.value);

async function guardar() {
  error.value = '';
  const fin = `${otroDia.value ? diaFin.value : dia.value}T${hasta.value}`;
  const ini = `${dia.value}T${desde.value}`;
  if (fin <= ini) { error.value = 'El bloqueo tiene que terminar después de empezar.'; return; }
  busy.value = true;
  try {
    const { data } = await api.post('/agenda/bloqueos', {
      especialista_id: quien.value || null, inicio: ini, fin, motivo: motivo.value.trim(),
    });
    if (data.citas_en_conflicto > 0) {
      conflictos.value = data.citas_en_conflicto;
      emit('creado', data, { cerrar: false });
    } else {
      emit('creado', data, { cerrar: true });
    }
  } catch (e) {
    error.value = apiError(e, 'No se pudo bloquear el horario.');
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal card bq-modal" role="dialog" aria-modal="true" aria-labelledby="bq-titulo">
      <div class="modal-head">
        <h2 id="bq-titulo">Bloquear horario</h2>
        <button class="modal-close" aria-label="Cerrar" @click="emit('close')">✕</button>
      </div>

      <div v-if="conflictos" class="bq-hecho">
        <p class="bq-aviso">
          Quedó bloqueado. <strong>{{ conflictos }} {{ conflictos === 1 ? 'cita sigue' : 'citas siguen' }}</strong>
          en ese horario: no se cancelaron. Muévelas o avísale al tutor.
        </p>
        <button class="btn btn-primary bq-entendido" @click="emit('close')">Entendido</button>
      </div>

      <form v-else class="bq-form" @submit.prevent="guardar">
        <div class="field">
          <label for="bq-quien">Para</label>
          <select id="bq-quien" v-model="quien" class="select">
            <option value="">Toda la clínica</option>
            <option v-for="e in especialistas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
          </select>
        </div>

        <div class="bq-fila">
          <div class="field">
            <label for="bq-dia">{{ otroDia ? 'Desde el día' : 'Día' }}</label>
            <input id="bq-dia" v-model="dia" type="date" class="input" required />
          </div>
          <div class="field">
            <label for="bq-desde">Desde</label>
            <input id="bq-desde" v-model="desde" type="time" step="900" class="input" required />
          </div>
          <div v-if="otroDia" class="field">
            <label for="bq-dia-fin">Hasta el día</label>
            <input id="bq-dia-fin" v-model="diaFin" type="date" class="input" :min="dia" required />
          </div>
          <div class="field">
            <label for="bq-hasta">Hasta</label>
            <input id="bq-hasta" v-model="hasta" type="time" step="900" class="input" required />
          </div>
        </div>
        <label class="bq-check">
          <input v-model="otroDia" type="checkbox" /> Termina otro día
        </label>

        <div class="field">
          <label for="bq-motivo">Motivo</label>
          <input id="bq-motivo" v-model="motivo" class="input" maxlength="150" placeholder="Por ejemplo: salida por trámites" required />
          <div class="bq-chips" role="group" aria-label="Motivos frecuentes">
            <button v-for="m in MOTIVOS" :key="m" type="button" class="bq-chip" :class="{ activo: motivo === m }" @click="elegirMotivo(m)">{{ m }}</button>
          </div>
        </div>

        <p class="bq-nota">Esas horas dejan de ofrecerse en el portal de reservas.</p>
        <p v-if="error" class="error-text">{{ error }}</p>
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" @click="emit('close')">Cancelar</button>
          <button type="submit" class="btn btn-primary" :disabled="busy || !valido">{{ busy ? 'Bloqueando…' : 'Bloquear horario' }}</button>
        </div>
      </form>
    </div>
  </div>
</template>

<style scoped>
.bq-modal { width: 100%; max-width: 500px; padding: 1.4rem; }
.bq-modal h2 { font-size: var(--t-xl); }
.bq-entendido { width: 100%; }
.bq-form { display: flex; flex-direction: column; gap: 0.75rem; }
.bq-fila { display: grid; grid-template-columns: repeat(auto-fit, minmax(118px, 1fr)); gap: 0.6rem; }
.bq-check { display: inline-flex; align-items: center; gap: 0.45rem; font-size: var(--t-sm); color: var(--ink-soft); margin-top: -0.2rem; }
.bq-chips { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-top: 0.45rem; }
.bq-chip {
  font-size: var(--t-micro); font-weight: 600; padding: 0.3em 0.75em;
  border-radius: var(--r-full); border: 1px solid var(--line-strong); color: var(--ink-soft); background: var(--surface);
}
.bq-chip:hover { border-color: var(--accent); color: var(--accent-strong); background: var(--accent-tint); }
.bq-chip.activo { background: var(--brand); border-color: var(--brand); color: #fff; }
.bq-nota { font-size: var(--t-micro); color: var(--ink-soft); }
.bq-hecho { display: flex; flex-direction: column; gap: 1rem; }
.bq-aviso { background: var(--warn-tint); color: var(--warn-ink); border-radius: var(--r-md); padding: 0.8rem 0.9rem; font-size: var(--t-sm); }
</style>
