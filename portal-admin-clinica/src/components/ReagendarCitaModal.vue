<script setup>
/* Mover una cita de hora, sin perder nada de lo que ya tiene.

   Hasta ahora el panel solo sabía cambiar el ESTADO de una cita (incluido
   cancelarla), así que reagendar por teléfono —lo que la recepción hace todos
   los días— obligaba a cancelar y crear una nueva. Eso pierde el historial y
   las `respuestas` del formulario original, y deja dos filas donde hubo una
   sola atención. `PATCH /citas/{id}/reagendar` existía desde hace tiempo y ya
   aceptaba staff; lo que faltaba era esto.

   Reusa `/citas/slots` con el servicio y el especialista YA congelados en la
   cita: reagendar cambia la hora y nada más. Una cita sin
   servicio_id/especialista_id (importada de ACVet, o creada a mano) no se
   puede mover desde acá — el backend responde 400 y quien llama a este modal
   no debería ofrecer el botón. */
import { ref, computed, onMounted } from 'vue';
import { api, apiError } from '../api.js';

const props = defineProps({
  cita: { type: Object, required: true },
});
const emit = defineEmits(['close', 'reagendada']);

const slots = ref({});
const semana = ref(0);
const cargando = ref(false);
const guardando = ref(false);
const error = ref('');
const nuevaFecha = ref('');
const nuevaHora = ref('');

// Mismo tope que el asistente de reserva (MAX_DIAS_ANTICIPACION = 92 en el
// backend): 13 semanas ≈ 3 meses.
const MAX_SEMANAS = 12;

/* La agenda (`_reserva_out`) devuelve el especialista anidado, y el portal del
   cliente (`CitaResponse`) lo devuelve plano como `especialista_id`. Este modal
   se usa desde las dos, así que acepta las dos formas en vez de obligar a que
   una de ellas cambie de contrato. */
const especialistaId = computed(
  () => props.cita.especialista_id || props.cita.especialista?.id || null,
);

function isoMasDias(dias) {
  const d = new Date();
  d.setDate(d.getDate() + dias);
  return d.toISOString().slice(0, 10);
}

async function cargarSlots() {
  cargando.value = true;
  error.value = '';
  try {
    const { data } = await api.get('/citas/slots', {
      params: {
        servicio_id: props.cita.servicio_id,
        especialista_id: especialistaId.value,
        desde: isoMasDias(semana.value * 7),
        dias: 7,
      },
    });
    slots.value = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar los horarios.');
  } finally {
    cargando.value = false;
  }
}

function moverSemana(delta) {
  const nueva = semana.value + delta;
  if (nueva < 0 || nueva > MAX_SEMANAS) return;
  semana.value = nueva;
  nuevaFecha.value = '';
  nuevaHora.value = '';
  cargarSlots();
}

const dias = computed(() => Object.keys(slots.value).sort());

function elegir(dia, hora) {
  nuevaFecha.value = dia;
  nuevaHora.value = hora;
}

function etiquetaDia(iso) {
  const [y, m, d] = iso.split('-').map(Number);
  const dt = new Date(y, m - 1, d);
  const dia = dt.toLocaleDateString('es-CL', { weekday: 'short' }).replace('.', '');
  return `${dia} ${d}/${m}`;
}

function horaActual() {
  const d = new Date(props.cita.fecha_hora);
  return d.toLocaleString('es-CL', {
    weekday: 'long', day: 'numeric', month: 'long',
    hour: '2-digit', minute: '2-digit',
  });
}

async function confirmar() {
  if (!nuevaFecha.value || !nuevaHora.value) return;
  guardando.value = true;
  error.value = '';
  try {
    // Sin zona horaria a propósito: `Cita.fecha_hora` se guarda en hora de la
    // clínica, no en UTC (ver app/services/tiempo.py). Un ISO con "Z" la
    // correría 4 horas.
    await api.patch(`/citas/${props.cita.id}/reagendar`, {
      fecha_hora: `${nuevaFecha.value}T${nuevaHora.value}:00`,
    });
    emit('reagendada');
  } catch (e) {
    // 409 = alguien tomó esa hora mientras el modal estaba abierto; se
    // refrescan los horarios para que la siguiente elección sea real.
    error.value = apiError(e, 'No se pudo reagendar la cita.');
    if (e?.response?.status === 409) {
      nuevaHora.value = '';
      await cargarSlots();
    }
  } finally {
    guardando.value = false;
  }
}

onMounted(cargarSlots);
</script>

<template>
  <div class="modal-fondo" @click.self="emit('close')">
    <div class="modal-caja" role="dialog" aria-modal="true" aria-labelledby="reag-tit">
      <header class="modal-cab">
        <h3 id="reag-tit">Reagendar cita</h3>
        <button type="button" class="modal-x" aria-label="Cerrar" @click="emit('close')">×</button>
      </header>

      <p class="sub">
        <strong>{{ cita.mascota?.nombre || cita.mascota_nombre || 'Paciente' }}</strong>
        · {{ cita.servicio }}<br />
        <span class="muted">Hora actual: {{ horaActual() }}</span>
      </p>

      <div class="sem-nav">
        <button type="button" class="btn btn-ghost btn-sm" :disabled="semana === 0" @click="moverSemana(-1)">← Antes</button>
        <span class="muted">{{ semana === 0 ? 'Esta semana' : `Semana +${semana}` }}</span>
        <button type="button" class="btn btn-ghost btn-sm" :disabled="semana >= MAX_SEMANAS" @click="moverSemana(1)">Después →</button>
      </div>

      <p v-if="cargando" class="vacio">Cargando horarios…</p>
      <p v-else-if="!dias.length" class="vacio">No hay horas disponibles esta semana. Prueba la siguiente.</p>
      <div v-else class="dias-grid">
        <div v-for="d in dias" :key="d" class="dia-col">
          <span class="dia-lbl">{{ etiquetaDia(d) }}</span>
          <button
            v-for="h in slots[d]" :key="h"
            type="button"
            class="slot"
            :class="{ sel: nuevaFecha === d && nuevaHora === h }"
            @click="elegir(d, h)"
          >{{ h }}</button>
        </div>
      </div>

      <p v-if="error" class="err">{{ error }}</p>

      <footer class="modal-pie">
        <button type="button" class="btn btn-ghost" @click="emit('close')">Cancelar</button>
        <button
          type="button"
          class="btn btn-primary"
          :disabled="!nuevaFecha || !nuevaHora || guardando"
          @click="confirmar"
        >
          {{ guardando ? 'Guardando…' : 'Confirmar nueva hora' }}
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.modal-fondo {
  position: fixed; inset: 0; z-index: 60;
  background: rgba(20, 30, 60, 0.45);
  display: flex; align-items: center; justify-content: center;
  padding: 1rem;
}
.modal-caja {
  background: #fff; border-radius: 14px;
  box-shadow: 0 16px 50px rgba(20, 30, 60, 0.18);
  width: 100%; max-width: 620px;
  max-height: 90vh; overflow-y: auto;
  padding: 1.4rem;
}
.modal-cab { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.modal-cab h3 { margin: 0; }
.modal-x {
  border: 0; background: none; cursor: pointer;
  font-size: 1.6rem; line-height: 1; padding: 0 0.25rem; border-radius: 8px;
}
.modal-x:hover { background: var(--accent-tint); color: var(--accent-strong); }
.sub { margin: 0.6rem 0 1rem; }
.muted { color: #6b7280; }
.sem-nav {
  display: flex; align-items: center; justify-content: space-between;
  gap: 0.5rem; margin-bottom: 0.9rem;
}
.vacio { text-align: center; color: #6b7280; padding: 1.2rem 0; }
.err { color: #c0392b; margin: 0.6rem 0 0; }
.dias-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(84px, 1fr));
  gap: 0.6rem;
}
.dia-col { display: flex; flex-direction: column; gap: 0.35rem; }
.dia-lbl {
  font-size: var(--t-micro); font-weight: 600; text-transform: capitalize;
  color: #6b7280; text-align: center; padding-bottom: 0.15rem;
  border-bottom: 1px solid #e5e7eb;
}
.slot {
  border: 1px solid #d1d5db; background: #fff;
  border-radius: 8px; padding: 0.4rem 0.2rem;
  font-size: var(--t-sm); cursor: pointer;
  transition: background 0.15s, border-color 0.15s;
}
.slot:hover { background: var(--accent-tint); border-color: var(--accent); }
.sel { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
.sel:hover { color: #fff; }
.modal-pie { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 1.2rem; }

@media (max-width: 560px) {
  .dias-grid { grid-template-columns: repeat(auto-fit, minmax(72px, 1fr)); }
}
</style>
