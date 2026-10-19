<script setup>
/* Devolución de parte (o de todo) lo comprado.

   Se elige ítem por ítem y con cantidad, porque la mitad de las devoluciones
   reales son de un solo producto de una boleta con varios. La venta original
   no se toca: lo devuelto se anota aparte, así el historial sigue mostrando
   que el producto fue comprado. */
import { ref, computed } from 'vue';
import { api, apiError, formatCLP } from '../api.js';

const props = defineProps({
  venta: { type: Object, required: true },
});
const emit = defineEmits(['close', 'devuelta']);

const motivo = ref('');
const efectivoDevuelto = ref(0);
const busy = ref(false);
const error = ref('');

/* Solo se puede devolver lo que todavía está en poder del cliente. */
const devolubles = computed(() =>
  props.venta.lineas
    .map((l) => ({ ...l, disponible: (l.cantidad || 0) - (l.cantidad_devuelta || 0) }))
    .filter((l) => l.disponible > 0),
);

const cantidades = ref(Object.fromEntries(devolubles.value.map((l) => [l.id, 0])));

function unitario(linea) {
  // A prorrata, igual que el backend: devolver 1 de 2 unidades con descuento
  // devuelve la mitad del descuento, no el precio de lista.
  if (!linea.cantidad) return 0;
  return linea.subtotal / linea.cantidad;
}

const totalDevuelto = computed(() =>
  devolubles.value.reduce((n, l) => n + unitario(l) * (cantidades.value[l.id] || 0), 0),
);

const hayAlgo = computed(() => Object.values(cantidades.value).some((c) => c > 0));
const listo = computed(() => hayAlgo.value && motivo.value.trim().length >= 3);

const efectivoCobrado = computed(() => props.venta.pagos?.pago_efectivo || 0);

function todo() {
  for (const l of devolubles.value) cantidades.value[l.id] = l.disponible;
  efectivoDevuelto.value = Math.min(efectivoCobrado.value, Math.round(totalDevuelto.value));
}

async function confirmar() {
  busy.value = true;
  error.value = '';
  try {
    const { data } = await api.post(`/transacciones/${props.venta.id}/devolucion`, {
      motivo: motivo.value.trim(),
      lineas: devolubles.value
        .filter((l) => (cantidades.value[l.id] || 0) > 0)
        .map((l) => ({ venta_id: l.id, cantidad: Number(cantidades.value[l.id]) })),
      efectivo_devuelto: Number(efectivoDevuelto.value) || 0,
    });
    emit('devuelta', data);
  } catch (e) {
    error.value = apiError(e, 'No se pudo registrar la devolución.');
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal dv" role="dialog" aria-modal="true" aria-label="Registrar devolución">
      <div class="modal-head">
        <h3>Devolución de la venta #{{ String(venta.numero || 0).padStart(6, '0') }}</h3>
        <button class="modal-close" aria-label="Cerrar" @click="emit('close')">✕</button>
      </div>

      <p v-if="!devolubles.length" class="empty">
        Ya se devolvió todo lo de esta venta.
      </p>

      <template v-else>
        <div class="dv-head-lineas">
          <span>Qué se devuelve</span>
          <button class="btn btn-ghost btn-sm" @click="todo">Devolver todo</button>
        </div>

        <ul class="dv-lineas">
          <li v-for="l in devolubles" :key="l.id">
            <div class="dv-info">
              <strong>{{ l.nombre }}</strong>
              <small>
                {{ l.disponible }} de {{ l.cantidad }} sin devolver ·
                {{ formatCLP(Math.round(unitario(l))) }} c/u
              </small>
            </div>
            <input
              class="input input-sm" type="number" min="0" :max="l.disponible"
              v-model.number="cantidades[l.id]" :aria-label="`Unidades a devolver de ${l.nombre}`"
            />
          </li>
        </ul>

        <div class="dv-total">
          <span>A devolver</span>
          <strong>{{ formatCLP(Math.round(totalDevuelto)) }}</strong>
        </div>

        <div class="field">
          <label for="dv-motivo">Motivo <span class="req">*</span></label>
          <textarea
            id="dv-motivo" class="textarea" v-model="motivo" rows="2"
            placeholder="Por qué se devuelve…"
          ></textarea>
        </div>

        <div class="field">
          <label for="dv-efectivo">Efectivo devuelto del cajón</label>
          <input
            id="dv-efectivo" class="input" type="number" min="0" step="1"
            :max="efectivoCobrado" v-model.number="efectivoDevuelto"
          />
          <small>
            Se registra como egreso del turno abierto. De esta venta entraron
            {{ formatCLP(efectivoCobrado) }} en efectivo; lo pagado con tarjeta no sale del cajón.
          </small>
        </div>

        <p v-if="error" class="error-text">{{ error }}</p>

        <div class="modal-actions">
          <button class="btn btn-ghost" @click="emit('close')">Cancelar</button>
          <button class="btn btn-primary" :disabled="!listo || busy" @click="confirmar">
            {{ busy ? 'Registrando…' : 'Registrar devolución' }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.dv { max-width: 560px; }
.dv-head-lineas { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 0.5rem; }
.dv-head-lineas > span { font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.04em; }

.dv-lineas { list-style: none; margin: 0 0 0.9rem; padding: 0; }
.dv-lineas li {
  display: flex; align-items: center; gap: 0.8rem;
  padding: 0.5rem 0; border-bottom: 1px solid var(--line-tabla);
}
.dv-info { display: flex; flex-direction: column; flex: 1; min-width: 0; }
.dv-info small { font-size: var(--t-micro); color: var(--ink-soft); }
.dv-lineas .input-sm { width: 80px; text-align: right; padding: 0.4em 0.5em; font-size: var(--t-sm); }

.dv-total {
  display: flex; justify-content: space-between; align-items: center;
  padding: 0.7rem 0.9rem; margin-bottom: 1rem;
  background: var(--brand-tint); color: var(--brand-ink);
  border-radius: var(--r-md); font-weight: 600;
}
.field { margin-bottom: 0.9rem; }
.field small { font-size: var(--t-micro); color: var(--ink-soft); }
.req { color: var(--danger); }
</style>
