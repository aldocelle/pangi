<script setup>
/* Anular una venta. NO la borra: queda registrada con quién, cuándo y por
   qué, y el historial del cliente y del paciente la siguen mostrando.

   La pregunta del medio —si la plata llegó a entrar— no es un detalle
   administrativo: decide si Caja deja de esperar ese dinero o si registra
   una salida. Elegir mal deja el turno con un faltante o un sobrante que
   nadie va a poder explicar al contar el cajón, así que se pregunta en vez
   de deducirlo. */
import { ref, computed } from 'vue';
import { api, apiError, formatCLP } from '../api.js';

const props = defineProps({
  venta: { type: Object, required: true },
});
const emit = defineEmits(['close', 'anulada']);

const motivo = ref('');
const plataEntro = ref(null);      // null = todavía sin elegir
const efectivoDevuelto = ref(0);
const busy = ref(false);
const error = ref('');

const efectivoCobrado = computed(() => props.venta.pagos?.pago_efectivo || 0);
const huboCobro = computed(() => (props.venta.pagado || 0) > 0);

function elegir(valor) {
  plataEntro.value = valor;
  // Prellenar con lo que entró en efectivo es lo habitual (se devuelve todo),
  // y sigue siendo editable para una devolución parcial del cobro.
  efectivoDevuelto.value = valor ? efectivoCobrado.value : 0;
}

const listo = computed(
  () => motivo.value.trim().length >= 3 && (plataEntro.value !== null || !huboCobro.value),
);

async function confirmar() {
  busy.value = true;
  error.value = '';
  try {
    const { data } = await api.post(`/transacciones/${props.venta.id}/anular`, {
      motivo: motivo.value.trim(),
      // Sin cobro registrado no hay plata que rastrear: la respuesta es "no
      // entró" por definición, y no se le hace elegir al usuario.
      plata_entro: huboCobro.value ? !!plataEntro.value : false,
      efectivo_devuelto: plataEntro.value ? Number(efectivoDevuelto.value) || 0 : 0,
    });
    emit('anulada', data);
  } catch (e) {
    error.value = apiError(e, 'No se pudo anular la venta.');
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal av" role="dialog" aria-modal="true" aria-label="Anular venta">
      <div class="modal-head">
        <h3>Anular la venta #{{ String(venta.numero || 0).padStart(6, '0') }}</h3>
        <button class="modal-close" aria-label="Cerrar" @click="emit('close')">✕</button>
      </div>

      <p class="av-nota">
        La venta <strong>no se borra</strong>: queda registrada como anulada, con tu nombre y el
        motivo, y se sigue viendo en el historial del cliente y del paciente. Las unidades vuelven
        al inventario.
      </p>

      <div class="field">
        <label for="av-motivo">Motivo <span class="req">*</span></label>
        <textarea
          id="av-motivo" class="textarea" v-model="motivo" rows="2"
          placeholder="Por qué se anula esta venta…"
        ></textarea>
      </div>

      <template v-if="huboCobro">
        <fieldset class="av-plata">
          <legend>Esta venta tiene {{ formatCLP(venta.pagado) }} cobrados. ¿Esa plata entró?</legend>
          <button
            type="button" class="av-opcion" :class="{ activo: plataEntro === false }"
            @click="elegir(false)"
          >
            <strong>No, se registró por error</strong>
            <span>Nunca llegó al cajón. Caja deja de esperarla.</span>
          </button>
          <button
            type="button" class="av-opcion" :class="{ activo: plataEntro === true }"
            @click="elegir(true)"
          >
            <strong>Sí, y se le devolvió al cliente</strong>
            <span>Se registra la salida como egreso del turno abierto.</span>
          </button>
        </fieldset>

        <div v-if="plataEntro === true" class="field av-efectivo">
          <label for="av-efectivo">Efectivo devuelto del cajón</label>
          <input
            id="av-efectivo" class="input" type="number" min="0" step="1"
            :max="efectivoCobrado" v-model.number="efectivoDevuelto"
          />
          <small>
            De esta venta entraron {{ formatCLP(efectivoCobrado) }} en efectivo.
            Lo pagado por tarjeta o transferencia no sale del cajón: déjalo fuera de este monto.
          </small>
        </div>
      </template>
      <p v-else class="av-nota av-sin-cobro">
        Esta venta no tiene pagos registrados, así que no hay dinero que devolver.
      </p>

      <p v-if="error" class="error-text">{{ error }}</p>

      <div class="modal-actions">
        <button class="btn btn-ghost" @click="emit('close')">Cancelar</button>
        <button class="btn btn-danger" :disabled="!listo || busy" @click="confirmar">
          {{ busy ? 'Anulando…' : 'Anular venta' }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.av { max-width: 540px; }
.av-nota {
  background: var(--warn-tint); color: var(--warn-ink);
  padding: 0.7rem 0.9rem; border-radius: var(--r-md);
  font-size: var(--t-sm); margin-bottom: 1rem;
}
.av-sin-cobro { background: var(--bg); color: var(--ink-soft); }
.req { color: var(--danger); }

.av-plata { border: 1px solid var(--line); border-radius: var(--r-md); padding: 0.9rem; margin: 1rem 0 0; }
.av-plata legend { font-size: var(--t-sm); font-weight: 600; padding: 0 0.35rem; }
.av-opcion {
  display: flex; flex-direction: column; gap: 0.15rem; width: 100%; text-align: left;
  padding: 0.6rem 0.8rem; margin-top: 0.5rem;
  border: 1.5px solid var(--line-strong); border-radius: var(--r-md);
  transition: border-color 0.15s, background 0.15s;
}
.av-opcion:hover { border-color: var(--accent); background: var(--accent-tint); }
.av-opcion.activo { border-color: var(--accent); background: var(--accent-tint); }
.av-opcion strong { font-size: var(--t-base); }
.av-opcion span { font-size: var(--t-micro); color: var(--ink-soft); }

.av-efectivo { margin-top: 1rem; }
.av-efectivo small { font-size: var(--t-micro); color: var(--ink-soft); }
</style>
