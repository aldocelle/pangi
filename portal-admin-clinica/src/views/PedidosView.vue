<script setup>
import { ref, onMounted } from 'vue';
import { api, apiError, formatCLP } from '../api.js';
import Paginador from '../components/Paginador.vue';
import BuscadorAvanzado from '../components/BuscadorAvanzado.vue';
import NuevaFacturaCompraModal from '../components/NuevaFacturaCompraModal.vue';

const CAMPOS_BUSQUEDA = [
  { key: 'q', label: 'N° de factura', placeholder: 'Buscar por número…' },
  { key: 'estado_pago', label: 'Estado', type: 'select', options: [
    { value: 'no_pagada', label: 'No pagada' },
    { value: 'pagada', label: 'Pagada' },
  ] },
];

const facturas = ref([]);
const loading = ref(true);
const error = ref('');
const filtros = ref({ q: '', estado_pago: '' });
const pagina = ref(1);
const paginacion = ref({ total: 0, total_paginas: 1, limit: 20 });

const resumen = ref({ gasto_total: 0, n_facturas: 0, pendientes: { n: 0, total: 0 }, vencidas: { n: 0, total: 0 } });

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const params = { page: pagina.value, limit: 20 };
    if (filtros.value.q) params.q = filtros.value.q;
    if (filtros.value.estado_pago) params.estado_pago = filtros.value.estado_pago;
    const { data } = await api.get('/pedidos', { params });
    facturas.value = data.items;
    paginacion.value = { total: data.total, total_paginas: data.total_paginas, limit: data.limit };
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar las facturas.');
  } finally {
    loading.value = false;
  }
}

async function cargarResumen() {
  try {
    const { data } = await api.get('/pedidos/resumen');
    resumen.value = data;
  } catch { /* el resumen es informativo: si falla, el listado igual sirve */ }
}

function buscar() {
  pagina.value = 1;
  cargar();
}

function cambiarPagina(p) {
  pagina.value = p;
  cargar();
}

const mostrarNueva = ref(false);

function onFacturaCreada() {
  mostrarNueva.value = false;
  pagina.value = 1;
  cargar();
  cargarResumen();
}

const marcandoId = ref(null);
async function marcarPagada(f) {
  marcandoId.value = f.id;
  try {
    const hoy = new Date().toISOString().slice(0, 10);
    const { data } = await api.patch(`/pedidos/${f.id}/pago`, { estado_pago: 'pagada', fecha_pago: hoy });
    // `data.total` llega como string ("20.00"): a diferencia del listado (que
    // arma el dict a mano y deja pasar el Decimal tal cual, serializado a
    // número), esta respuesta pasa por el schema Pydantic, que por defecto
    // serializa Decimal como string para no perder precisión. `formatCLP`
    // asume número — sin este cast, la fila pasaría de mostrar "20 CLP" a
    // "20.00 CLP" justo al marcarla pagada.
    Object.assign(f, { ...data, total: Number(data.total) });
    cargarResumen();
  } catch (e) {
    error.value = apiError(e, 'No se pudo marcar la factura como pagada.');
  } finally {
    marcandoId.value = null;
  }
}

function fechaLegible(iso) {
  if (!iso) return '—';
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('es-CL', { day: 'numeric', month: 'short', year: 'numeric' });
}

onMounted(() => { cargar(); cargarResumen(); });
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Pedidos y compras</h1>
        <p class="page-sub">Facturas recibidas de proveedores: qué llegó, con qué lote y si ya se pagó.</p>
      </div>
      <button class="btn btn-primary" @click="mostrarNueva = true">+ Nueva factura</button>
    </div>

    <div class="tarjetas">
      <div class="tarjeta">
        <span class="num">{{ formatCLP(resumen.gasto_total) }}</span>
        <span class="etq">Gasto este mes ({{ resumen.n_facturas }} facturas)</span>
      </div>
      <div class="tarjeta">
        <span class="num">{{ formatCLP(resumen.pendientes.total) }}</span>
        <span class="etq">Pendientes de pago ({{ resumen.pendientes.n }})</span>
      </div>
      <div class="tarjeta" :class="{ alerta: resumen.vencidas.n > 0 }">
        <span class="num">{{ formatCLP(resumen.vencidas.total) }}</span>
        <span class="etq">Vencidas ({{ resumen.vencidas.n }})</span>
      </div>
    </div>

    <BuscadorAvanzado v-model="filtros" :campos="CAMPOS_BUSQUEDA" @buscar="buscar" />

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>
    <p v-else-if="!facturas.length" class="empty">Sin facturas registradas.</p>

    <div v-else class="tabla-wrap card">
      <table class="tabla-datos tabla-pedidos">
        <thead>
          <tr>
            <th>N° factura</th>
            <th>Proveedor</th>
            <th>Fecha recibo</th>
            <th>Total</th>
            <th>Estado</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="f in facturas" :key="f.id">
            <td>{{ f.numero_factura || '—' }}</td>
            <td>{{ f.proveedor_nombre || '—' }}</td>
            <td>{{ fechaLegible(f.fecha_recibo) }}</td>
            <td>{{ formatCLP(f.total) }}</td>
            <td>
              <span class="pd-badge" :class="f.estado_pago === 'pagada' ? 'pd-pagada' : 'pd-no-pagada'">
                {{ f.estado_pago === 'pagada' ? 'Pagada' : 'No pagada' }}
              </span>
            </td>
            <td>
              <button
                v-if="f.estado_pago !== 'pagada'" class="btn btn-ghost btn-sm"
                :disabled="marcandoId === f.id" @click="marcarPagada(f)"
              >{{ marcandoId === f.id ? 'Guardando…' : 'Marcar pagada' }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Paginador
      v-if="!loading && facturas.length"
      :page="pagina" :total-paginas="paginacion.total_paginas"
      :total="paginacion.total" :limit="paginacion.limit"
      @cambiar="cambiarPagina"
    />

    <NuevaFacturaCompraModal v-if="mostrarNueva" @close="mostrarNueva = false" @creada="onFacturaCreada" />
  </div>
</template>

<style scoped>
.tarjetas { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.8rem; margin-bottom: 1.2rem; max-width: 720px; }
.tarjeta {
  display: flex; flex-direction: column; align-items: flex-start; gap: 0.1rem;
  padding: 0.8rem 1rem; border: 1px solid var(--line); border-radius: var(--r-md);
  background: var(--surface); text-align: left;
}
.tarjeta.alerta { border-color: var(--danger); background: var(--danger-tint); }
.tarjeta .num { font-size: var(--t-2xl); font-weight: 700; }
.tarjeta.alerta .num { color: var(--danger); }
.tarjeta .etq { font-size: var(--t-micro); color: var(--ink-soft); }

.tabla-wrap { padding: 0; overflow-x: auto; }
/* Hereda de `.tabla-datos` (app.css); acá solo el respiro horizontal propio de
   esta tabla, que tiene columnas anchas de proveedor y número de factura. */
.tabla-pedidos :is(th, td) { padding-left: 0.9rem; padding-right: 0.9rem; }
.pd-badge { display: inline-block; padding: 0.2em 0.65em; border-radius: var(--r-full); font-size: var(--t-micro); font-weight: 600; }
.pd-pagada { background: var(--ok-tint); color: var(--ok); }
.pd-no-pagada { background: var(--warn-tint); color: var(--warn-ink); }

@media (max-width: 720px) {
  .tarjetas { grid-template-columns: 1fr; max-width: none; }
}
</style>
