<script setup>
import { ref, computed, onMounted } from 'vue';
import { api, apiError, formatCLP } from '../api.js';

const emit = defineEmits(['close', 'creada']);

/* ── Cabecera ── */
const proveedores = ref([]);
const proveedorId = ref('');
const numeroFactura = ref('');
const fechaRecibo = ref(new Date().toISOString().slice(0, 10));
const fechaVencimientoPago = ref('');
const notas = ref('');

onMounted(async () => {
  try {
    const { data } = await api.get('/proveedores', { params: { limit: 30 } });
    proveedores.value = data.items;
  } catch { /* el select queda vacío; se puede recargar el modal */ }
});

/* ── Líneas: mismo patrón de búsqueda que NuevaVentaModal.vue ── */
const qItem = ref('');
const itemsFiltrados = ref([]);
const buscandoItem = ref(false);
// { item_id, nombre, cantidad, costo_unitario, lote, fecha_vencimiento }
// `nombre` sirve para las dos cosas: si viene de inventario queda de solo
// lectura con el nombre del ítem; si es una línea manual, es editable y ESE
// texto es lo que se manda como `item_nombre_libre` al guardar.
const lineas = ref([]);

let debounceItem;
function buscarItem() {
  clearTimeout(debounceItem);
  if (qItem.value.trim().length < 2) {
    itemsFiltrados.value = [];
    return;
  }
  debounceItem = setTimeout(async () => {
    buscandoItem.value = true;
    try {
      const { data } = await api.get('/inventario', { params: { q: qItem.value.trim(), limit: 12 } });
      itemsFiltrados.value = data.items || [];
    } catch {
      itemsFiltrados.value = [];
    } finally {
      buscandoItem.value = false;
    }
  }, 300);
}

function agregarDesdeInventario(it) {
  lineas.value.push({
    item_id: it.id, nombre: it.nombre,
    cantidad: 1, costo_unitario: Number(it.precio_costo) || 0,
    lote: '', fecha_vencimiento: '',
  });
  qItem.value = '';
  itemsFiltrados.value = [];
}

function agregarLineaManual() {
  lineas.value.push({
    item_id: null, nombre: '',
    cantidad: 1, costo_unitario: 0, lote: '', fecha_vencimiento: '',
  });
}

function quitarLinea(i) {
  lineas.value.splice(i, 1);
}

function subtotalLinea(l) {
  return Math.max(0, (l.costo_unitario || 0) * (l.cantidad || 0));
}

const totalLineas = computed(() => lineas.value.reduce((n, l) => n + subtotalLinea(l), 0));

const lineasValidas = computed(
  () => lineas.value.length > 0
    && lineas.value.every((l) => l.cantidad > 0 && (l.item_id || (l.nombre || '').trim())),
);

const busy = ref(false);
const error = ref('');

async function guardar() {
  busy.value = true;
  error.value = '';
  try {
    const { data } = await api.post('/pedidos', {
      proveedor_id: proveedorId.value,
      numero_factura: numeroFactura.value.trim() || null,
      fecha_recibo: fechaRecibo.value,
      fecha_vencimiento_pago: fechaVencimientoPago.value || null,
      notas: notas.value.trim() || null,
      lineas: lineas.value.map((l) => ({
        item_id: l.item_id,
        item_nombre_libre: l.item_id ? null : (l.nombre || '').trim(),
        cantidad: l.cantidad,
        costo_unitario: l.costo_unitario || 0,
        lote: l.lote?.trim() || null,
        fecha_vencimiento: l.fecha_vencimiento || null,
      })),
    });
    emit('creada', data);
  } catch (e) {
    error.value = apiError(e, 'No se pudo registrar la factura.');
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal card nf-modal" role="dialog" aria-modal="true" aria-label="Nueva factura de compra">
      <div class="nf-head">
        <h2>Nueva factura de compra</h2>
        <button class="modal-close" aria-label="Cerrar" @click="emit('close')">✕</button>
      </div>

      <div class="nf-body">
        <div class="grid-2">
          <div class="field">
            <label for="nf-proveedor">Proveedor *</label>
            <select id="nf-proveedor" class="select" v-model="proveedorId" required>
              <option value="" disabled>Selecciona un proveedor</option>
              <option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.nombre }}</option>
            </select>
          </div>
          <div class="field">
            <label for="nf-numero">N° de factura</label>
            <input id="nf-numero" class="input" v-model="numeroFactura" placeholder="Ej: F-1234" />
          </div>
        </div>
        <div class="grid-2">
          <div class="field">
            <label for="nf-fecha-recibo">Fecha de recibo *</label>
            <input id="nf-fecha-recibo" class="input" type="date" v-model="fechaRecibo" required />
          </div>
          <div class="field">
            <label for="nf-fecha-venc">Fecha de vencimiento de pago</label>
            <input id="nf-fecha-venc" class="input" type="date" v-model="fechaVencimientoPago" />
          </div>
        </div>

        <div class="buscador-item">
          <input
            class="input" v-model="qItem" placeholder="Buscar ítem del inventario… (mín. 2 letras)"
            @input="buscarItem"
          />
          <div v-if="buscandoItem || itemsFiltrados.length" class="opciones-list opciones-flotante">
            <p v-if="buscandoItem" class="empty">Buscando…</p>
            <button v-for="it in itemsFiltrados" :key="it.id" class="opcion-row" @click="agregarDesdeInventario(it)">
              <strong>{{ it.nombre }}</strong>
              <span class="muted">costo actual {{ formatCLP(it.precio_costo) }} · stock {{ it.stock_actual }}</span>
              <span class="opcion-go">+</span>
            </button>
          </div>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" @click="agregarLineaManual">+ Línea manual (ítem nuevo)</button>

        <p v-if="!lineas.length" class="empty">Agrega al menos un ítem recibido.</p>

        <ul v-else class="lineas-cabecera-list">
          <li class="lineas-cabecera">
            <span>Ítem</span><span>Cant.</span><span>Costo</span><span>Lote</span><span>Vencimiento</span><span>Subtotal</span><span></span>
          </li>
          <li v-for="(l, i) in lineas" :key="i" class="linea">
            <input
              class="input input-sm linea-nombre" v-model="l.nombre"
              :readonly="!!l.item_id" placeholder="Nombre del ítem"
            />
            <input class="input input-sm linea-num" type="number" min="1" v-model.number="l.cantidad" title="Cantidad" />
            <input class="input input-sm linea-num" type="number" min="0" step="1" v-model.number="l.costo_unitario" title="Costo unitario" />
            <input class="input input-sm" v-model="l.lote" placeholder="Opcional" title="Lote" />
            <input class="input input-sm" type="date" v-model="l.fecha_vencimiento" title="Fecha de vencimiento" />
            <span class="linea-subtotal">{{ formatCLP(subtotalLinea(l)) }}</span>
            <button type="button" class="linea-quitar" aria-label="Quitar línea" @click="quitarLinea(i)">✕</button>
          </li>
        </ul>

        <div v-if="lineas.length" class="nf-total">
          <span>Total</span>
          <strong>{{ formatCLP(totalLineas) }}</strong>
        </div>

        <div class="field">
          <label for="nf-notas">Notas</label>
          <textarea id="nf-notas" class="textarea" v-model="notas" placeholder="Opcional"></textarea>
        </div>

        <p v-if="error" class="error-text">{{ error }}</p>
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" @click="emit('close')">Cancelar</button>
          <button
            type="button" class="btn btn-primary"
            :disabled="busy || !proveedorId || !fechaRecibo || !lineasValidas"
            @click="guardar"
          >{{ busy ? 'Guardando…' : 'Registrar factura' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.nf-modal { width: 100%; max-width: 780px; max-height: 90vh; overflow-y: auto; padding: 1.4rem; }
.nf-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem; }
.nf-head h2 { font-size: var(--t-xl); }
.nf-body { display: flex; flex-direction: column; gap: 0.9rem; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft); }

.buscador-item { position: relative; }
.opciones-list { display: flex; flex-direction: column; gap: 0.5rem; }
.opcion-row { display: flex; align-items: center; gap: 0.8rem; text-align: left; padding: 0.6rem 0.9rem; border: 1.5px solid var(--line); border-radius: var(--r-md); }
.opcion-row:hover { border-color: var(--accent); background: var(--accent-tint); }
.opcion-row strong { flex: none; }
.opcion-go { color: var(--brand-ink); font-weight: 700; margin-left: auto; }
.muted { color: var(--ink-soft); font-size: var(--t-sm); }
.opciones-flotante {
  position: absolute; top: calc(100% + 0.3rem); left: 0; right: 0; z-index: 20;
  background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-md);
  box-shadow: var(--shadow-lg); padding: 0.5rem; max-height: 240px; overflow-y: auto;
}

.lineas-cabecera-list { list-style: none; display: flex; flex-direction: column; gap: 0.4rem; margin: 0; padding: 0; }
.lineas-cabecera, .linea {
  display: grid; grid-template-columns: 1.6fr 60px 90px 110px 130px 90px 24px;
  gap: 0.5rem; align-items: center;
}
.lineas-cabecera { font-size: var(--t-micro); color: var(--ink-soft); font-weight: 600; padding: 0 0.2rem; }
.input-sm { padding: 0.4em 0.5em; font-size: var(--t-sm); }
.linea-nombre[readonly] { background: var(--bg); color: var(--ink); }
.linea-num { text-align: right; }
.linea-subtotal { font-size: var(--t-sm); font-weight: 600; text-align: right; }
.linea-quitar { color: var(--danger); font-size: var(--t-base); padding: 0.2rem; }
.linea-quitar:hover { background: var(--danger-tint); border-radius: var(--r-sm); }

.nf-total {
  display: flex; justify-content: space-between; align-items: center;
  padding: 0.7rem 1rem; border-radius: var(--r-md);
  background: var(--brand-tint); color: var(--brand-ink); font-weight: 600;
}

@media (max-width: 720px) {
  .grid-2 { grid-template-columns: 1fr; }
  .lineas-cabecera { display: none; }
  .linea { grid-template-columns: 1fr 24px; grid-template-areas: "nombre quitar" "cant cant" "costo costo" "lote lote" "venc venc" "subtotal subtotal"; row-gap: 0.3rem; padding-bottom: 0.6rem; border-bottom: 1px solid var(--line-tabla); }
  .linea-nombre { grid-area: nombre; }
  .linea-quitar { grid-area: quitar; }
}
</style>
