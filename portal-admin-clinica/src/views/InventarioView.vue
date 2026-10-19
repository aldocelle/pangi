<script setup>
import { ref, computed, onMounted } from 'vue';
import { TIPOS_ITEM } from '../tiposItem.js';
import { api, apiError, formatCLP } from '../api.js';
import { useAuthStore } from '../stores/auth.js';
import Paginador from '../components/Paginador.vue';
import BuscadorAvanzado from '../components/BuscadorAvanzado.vue';
import HistorialVentasModal from '../components/HistorialVentasModal.vue';

const auth = useAuthStore();
const esAdmin = computed(() => auth.user?.rol === 'admin');
/* El historial de ventas de un ítem sale de /transacciones, que exige el
   permiso "ventas": quien administra el inventario no necesariamente
   puede ver la plata, y el botón no debe prometer algo que dará 403. */
const puedeVerVentas = computed(() => auth.puede('ventas'));

/* Ítem cuyo historial de ventas se está mirando (§9: abrir un producto y
   ver a quién se le vendió). Usa las MISMAS ventas del módulo Ventas, no
   un registro paralelo del inventario. */
const historialDe = ref(null);

// El catálogo de ACVet (con Hemocomponente) más «Otro», que es como quedaron
// los importados sin tipo reconocible.
const TIPOS = [...TIPOS_ITEM, 'Otro'];

/* Bodegas habilitadas en Ajustes → Inventario y ventas. Sin la configuración
   (o sin la migración), el campo no se muestra y el ítem queda donde estaba. */
const bodegas = ref([]);
onMounted(async () => {
  try {
    const { data } = await api.get('/configuracion/ventas');
    bodegas.value = data.bodegas || [];
  } catch { bodegas.value = []; }
});

const proveedores = ref([]); // opciones reales, cargadas desde /inventario/proveedores

const CAMPOS_BUSQUEDA = computed(() => [
  { key: 'q', label: 'Nombre o descripción', placeholder: 'Buscar por nombre o descripción…' },
  { key: 'tipo', label: 'Tipo', type: 'select', options: TIPOS.map((t) => ({ value: t, label: t })) },
  { key: 'proveedor', label: 'Proveedor', type: 'select', options: proveedores.value.map((p) => ({ value: p, label: p })) },
  { key: 'lote', label: 'Lote', placeholder: 'Número de lote' },
  ...(bodegas.value.length > 1
    ? [{ key: 'bodega', label: 'Bodega', type: 'select', options: bodegas.value.map((b) => ({ value: b, label: b })) }]
    : []),
]);

/* ── Alertas de bodega ─────────────────────────────────────────────────────
   Panel aparte del listado y FUERA de sus filtros, mismo criterio que
   «Abonos pendientes» en Reservas: un producto que vence en dos semanas hay
   que verlo aunque en ese momento se esté buscando otra cosa.

   Se abre solo si hay algo YA VENCIDO. Lo vencido es trabajo de hoy —retirarlo
   de la estantería—; lo que vence en dos meses no, y un panel desplegado con
   todo en plazo estorba hasta que se deja de mirar. El contador se ve siempre,
   así que nada queda escondido.

   `GET /inventario/alertas` ya devuelve lo vencido y lo por vencer separados y
   ordenados por fecha, con los días calculados: acá no se rehace esa
   aritmética para no arriesgar otro resultado que el del backend. */
const alertas = ref({ vencidos: [], por_vencer: [], bajo_stock: [], total: 0 });
const alertasAbierto = ref(false);

async function cargarAlertas() {
  try {
    const { data } = await api.get('/inventario/alertas');
    alertas.value = data;
    alertasAbierto.value = data.vencidos.length > 0;
  } catch {
    // Falla en silencio: es un aviso lateral, y si el endpoint no responde no
    // tiene por qué impedir trabajar con el inventario.
    alertas.value = { vencidos: [], por_vencer: [], bajo_stock: [], total: 0 };
  }
}

const items = ref([]);
const loading = ref(true);
const error = ref('');
const filtros = ref({ q: '', tipo: '', proveedor: '', lote: '', bodega: '' });
const soloBajoStock = ref(false);
const soloPorVencer = ref(false);
const soloUsoInterno = ref(false);
const pagina = ref(1);
const paginacion = ref({ total: 0, total_paginas: 1, limit: 30 });

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const params = { page: pagina.value, limit: 30, bajo_stock: soloBajoStock.value || undefined };
    if (filtros.value.q) params.q = filtros.value.q;
    if (filtros.value.tipo) params.tipo = filtros.value.tipo;
    if (filtros.value.proveedor) params.proveedor = filtros.value.proveedor;
    if (filtros.value.lote) params.lote = filtros.value.lote;
    if (filtros.value.bodega) params.bodega = filtros.value.bodega;
    if (soloPorVencer.value) params.por_vencer = true;
    if (soloUsoInterno.value) params.uso_interno = true;
    const { data } = await api.get('/inventario', { params });
    items.value = data.items;
    paginacion.value = { total: data.total, total_paginas: data.total_paginas, limit: data.limit };
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar el inventario.');
  } finally {
    loading.value = false;
  }
}

async function cargarProveedores() {
  try {
    const { data } = await api.get('/inventario/proveedores');
    proveedores.value = data;
  } catch {
    proveedores.value = [];
  }
}

function buscar() {
  pagina.value = 1;
  cargar();
}

function cambiarPagina(p) {
  pagina.value = p;
  cargar();
}

/* Modal crear/editar */
const modal = ref(false);
const editandoId = ref(null);
const guardando = ref(false);
const errorModal = ref('');
const vacio = { nombre: '', descripcion: '', tipo: 'Farmaco', unidad: '', precio_costo: 0, precio_venta: 0, stock_actual: 0, stock_minimo: 0, lote: '', fecha_vencimiento: '', proveedor: '', uso_interno: false, bodega: 'Principal' };
const form = ref({ ...vacio });

function abrirCrear() {
  editandoId.value = null;
  form.value = { ...vacio };
  errorModal.value = '';
  modal.value = true;
}

function abrirEditar(item) {
  editandoId.value = item.id;
  form.value = {
    nombre: item.nombre || '',
    descripcion: item.descripcion || '',
    tipo: item.tipo || 'Otro',
    unidad: item.unidad || '',
    precio_costo: item.precio_costo ?? 0,
    precio_venta: item.precio_venta ?? 0,
    stock_actual: item.stock_actual ?? 0,
    stock_minimo: item.stock_minimo ?? 0,
    lote: item.lote || '',
    fecha_vencimiento: item.fecha_vencimiento || '',
    proveedor: item.proveedor || '',
    uso_interno: item.uso_interno || false,
    bodega: item.bodega || 'Principal',
  };
  errorModal.value = '';
  modal.value = true;
}

async function guardar() {
  guardando.value = true;
  errorModal.value = '';
  try {
    const payload = {
      ...form.value,
      precio_costo: Number(form.value.precio_costo) || 0,
      precio_venta: Number(form.value.precio_venta) || 0,
      stock_actual: Number(form.value.stock_actual) || 0,
      stock_minimo: Number(form.value.stock_minimo) || 0,
      fecha_vencimiento: form.value.fecha_vencimiento || null,
    };
    if (!bodegas.value.length) delete payload.bodega;
    if (editandoId.value) {
      const { data } = await api.patch(`/inventario/${editandoId.value}`, payload);
      const i = items.value.findIndex((x) => x.id === editandoId.value);
      if (i !== -1) items.value[i] = data;
    } else {
      const { data } = await api.post('/inventario', payload);
      items.value.unshift(data);
    }
    modal.value = false;
    cargarProveedores();
  } catch (e) {
    errorModal.value = apiError(e, 'No se pudo guardar el ítem.');
  } finally {
    guardando.value = false;
  }
}

async function eliminar(item) {
  if (!confirm(`¿Dar de baja "${item.nombre}"?`)) return;
  try {
    await api.delete(`/inventario/${item.id}`);
    items.value = items.value.filter((x) => x.id !== item.id);
  } catch (e) {
    error.value = apiError(e, 'No se pudo dar de baja el ítem.');
  }
}

function bajoStock(item) {
  return item.stock_actual <= item.stock_minimo;
}

function formatFecha(f) {
  if (!f) return '—';
  const [a, m, d] = f.split('-');
  return `${d}-${m}-${a}`;
}

// "Menos de 3 meses" en días: evita el desfase de calendario de sumar
// meses (28/30/31) para un umbral que solo necesita ser aproximado.
//
// OJO: este tinte de fila NO usa el mismo criterio que el panel de alertas de
// arriba, y es a propósito. La tabla muestra todo el inventario, así que tiñe
// cualquier ítem por vencer aunque tenga stock 0 — ahí el color es información
// sobre esa fila. El panel, en cambio, es una lista de TRABAJO: incluir un
// vencido sin existencia lo llena de renglones sobre los que no se puede hacer
// nada. Distinto propósito, distinto filtro; no unificarlos sin decidir cuál de
// los dos comportamientos se quiere perder.
const TRES_MESES_DIAS = 92;

function venceProximo(item) {
  if (!item.fecha_vencimiento) return false;
  const dias = (new Date(item.fecha_vencimiento) - new Date()) / 86400000;
  return dias <= TRES_MESES_DIAS;
}

onMounted(() => {
  cargar();
  cargarAlertas();
  cargarProveedores();
});
</script>

<template>
  <div class="page">
    <!-- La acción principal va arriba a la derecha, como en el resto del
         panel; entre los filtros competía con ellos y cambiaba de sitio
         según la pantalla. -->
    <div class="head-row">
      <div>
        <h1 class="page-title">Inventario</h1>
        <p class="page-sub">Productos e insumos: stock, precios y punto de reorden.</p>
      </div>
      <button class="btn btn-primary" @click="abrirCrear">+ Nuevo ítem</button>
    </div>

    <!-- Alertas de bodega. Va ARRIBA del buscador y fuera de sus filtros: un
         producto que vence en dos semanas hay que verlo aunque en ese momento
         se esté buscando otra cosa. Mismo lugar y misma lógica que «Abonos
         pendientes» en Reservas. -->
    <section v-if="alertas.vencidos.length || alertas.por_vencer.length" class="alertas">
      <button type="button" class="alertas-cab" @click="alertasAbierto = !alertasAbierto">
        <span class="alertas-tit">
          <span aria-hidden="true">⚠</span>
          <template v-if="alertas.vencidos.length">
            {{ alertas.vencidos.length }} vencido{{ alertas.vencidos.length === 1 ? '' : 's' }}
          </template>
          <template v-if="alertas.vencidos.length && alertas.por_vencer.length"> · </template>
          <template v-if="alertas.por_vencer.length">
            {{ alertas.por_vencer.length }} por vencer
          </template>
        </span>
        <span class="alertas-chevron">{{ alertasAbierto ? 'Ocultar' : 'Ver detalle' }}</span>
      </button>

      <div v-if="alertasAbierto" class="alertas-cuerpo">
        <!-- Vencidos y por vencer separados: son dos trabajos distintos. Lo
             vencido se retira de la estantería; lo que está por vencer se usa
             o se rebaja primero. -->
        <div v-if="alertas.vencidos.length" class="alerta-grupo">
          <h3 class="alerta-sub vencido">Ya vencidos — retirar de la estantería</h3>
          <ul class="alerta-lista">
            <li v-for="a in alertas.vencidos" :key="a.id">
              <strong>{{ a.nombre }}</strong>
              <span class="alerta-meta">
                venció el {{ formatFecha(a.fecha_vencimiento) }}
                ({{ Math.abs(a.dias) }} día{{ Math.abs(a.dias) === 1 ? '' : 's' }})
                · quedan {{ a.stock_actual }} {{ a.unidad || 'un' }}
                <template v-if="a.lote"> · lote {{ a.lote }}</template>
              </span>
            </li>
          </ul>
        </div>

        <div v-if="alertas.por_vencer.length" class="alerta-grupo">
          <h3 class="alerta-sub">Por vencer — usar o rebajar primero</h3>
          <ul class="alerta-lista">
            <li v-for="a in alertas.por_vencer" :key="a.id">
              <strong>{{ a.nombre }}</strong>
              <span class="alerta-meta">
                en {{ a.dias }} día{{ a.dias === 1 ? '' : 's' }}
                ({{ formatFecha(a.fecha_vencimiento) }})
                · quedan {{ a.stock_actual }} {{ a.unidad || 'un' }}
                <template v-if="a.lote"> · lote {{ a.lote }}</template>
              </span>
            </li>
          </ul>
        </div>
      </div>
    </section>

    <div class="barra">
      <label class="chk">
        <input type="checkbox" v-model="soloBajoStock" @change="buscar" />
        Solo bajo stock
      </label>
      <label class="chk">
        <input type="checkbox" v-model="soloPorVencer" @change="buscar" />
        Solo por vencer
      </label>
      <label class="chk">
        <input type="checkbox" v-model="soloUsoInterno" @change="buscar" />
        Solo uso interno
      </label>
    </div>

    <BuscadorAvanzado v-model="filtros" :campos="CAMPOS_BUSQUEDA" @buscar="buscar" />

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>
    <p v-else-if="!items.length" class="empty">Sin resultados.</p>

    <div v-else class="tabla card">
      <div class="t-head t-row">
        <span>Ítem</span><span>Tipo</span><span>Proveedor</span><span>Vencimiento</span><span>Stock</span><span>Costo</span><span>Venta</span><span></span>
      </div>
      <div v-for="item in items" :key="item.id" class="t-row t-row-link" @click="abrirEditar(item)">
        <span class="item-col">
          <strong>{{ item.nombre }}</strong>
          <small v-if="item.lote">Lote {{ item.lote }}</small>
          <small v-if="item.uso_interno" class="badge-interno">Uso interno</small>
        </span>
        <span class="cap">{{ item.tipo }}</span>
        <span>{{ item.proveedor || '—' }}</span>
        <span :class="{ 'venc-proximo': venceProximo(item) }">
          {{ formatFecha(item.fecha_vencimiento) }}{{ venceProximo(item) ? ' ⚠' : '' }}
        </span>
        <span :class="{ 'stock-bajo': bajoStock(item) }">
          {{ item.stock_actual }}{{ bajoStock(item) ? ' ⚠' : '' }}
        </span>
        <span>{{ formatCLP(item.precio_costo) }}</span>
        <span>
          {{ formatCLP(item.precio_venta) }}
          <small v-if="item.margen_bajo" class="margen-bajo" :title="`Bajo el mínimo de ${item.margen_minimo}%`">
            margen {{ item.margen_pct }}% ⚠
          </small>
        </span>
        <span class="acciones" @click.stop>
          <button v-if="puedeVerVentas" class="btn btn-ghost btn-sm" @click="historialDe = item">Ventas</button>
          <button v-if="esAdmin" class="btn btn-ghost btn-sm peligro" @click="eliminar(item)">Dar de baja</button>
        </span>
      </div>
    </div>
    <Paginador
      v-if="!loading && items.length"
      :page="pagina" :total-paginas="paginacion.total_paginas"
      :total="paginacion.total" :limit="paginacion.limit"
      @cambiar="cambiarPagina"
    />

    <HistorialVentasModal
      v-if="historialDe"
      :titulo="historialDe.nombre"
      subtitulo="Historial de ventas de este producto"
      :params="{ item_id: historialDe.id }"
      vacio="Este producto todavía no se ha vendido."
      @close="historialDe = null"
    />

    <div v-if="modal" class="overlay" @click.self="modal = false">
      <div class="modal-box">
        <h2>{{ editandoId ? 'Editar ítem' : 'Nuevo ítem' }}</h2>
        <form @submit.prevent="guardar">
          <div class="field">
            <label for="i-nombre">Nombre</label>
            <input id="i-nombre" class="input" v-model="form.nombre" required />
          </div>
          <div class="grid-2">
            <div class="field">
              <label for="i-tipo">Tipo</label>
              <select id="i-tipo" class="input" v-model="form.tipo">
                <option v-for="t in TIPOS" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>
            <div class="field">
              <label for="i-unidad">Unidad</label>
              <input id="i-unidad" class="input" v-model="form.unidad" placeholder="caja, ml, unidad…" />
            </div>
          </div>
          <div class="grid-2">
            <div class="field">
              <label for="i-costo">Precio costo</label>
              <input id="i-costo" class="input" type="number" min="0" v-model="form.precio_costo" />
            </div>
            <div class="field">
              <label for="i-venta">Precio venta</label>
              <input id="i-venta" class="input" type="number" min="0" v-model="form.precio_venta" />
            </div>
          </div>
          <div class="grid-2">
            <div class="field">
              <label for="i-stock">Stock actual</label>
              <input id="i-stock" class="input" type="number" min="0" v-model="form.stock_actual" />
            </div>
            <div class="field">
              <label for="i-minimo">Punto de reorden</label>
              <input id="i-minimo" class="input" type="number" min="0" v-model="form.stock_minimo" />
            </div>
          </div>
          <div class="grid-2">
            <div class="field">
              <label for="i-lote">Lote</label>
              <input id="i-lote" class="input" v-model="form.lote" />
            </div>
            <div class="field">
              <label for="i-venc">Vencimiento</label>
              <input id="i-venc" class="input" type="date" v-model="form.fecha_vencimiento" />
            </div>
          </div>
          <div v-if="bodegas.length" class="field">
            <label for="i-bodega">Bodega</label>
            <select id="i-bodega" class="input" v-model="form.bodega">
              <option v-for="b in bodegas" :key="b" :value="b">{{ b }}</option>
              <option v-if="form.bodega && !bodegas.includes(form.bodega)" :value="form.bodega">{{ form.bodega }} (deshabilitada)</option>
            </select>
          </div>
          <div class="field">
            <label for="i-proveedor">Proveedor</label>
            <input id="i-proveedor" class="input" v-model="form.proveedor" list="proveedores-lista" />
            <datalist id="proveedores-lista">
              <option v-for="p in proveedores" :key="p" :value="p" />
            </datalist>
          </div>
          <div class="field">
            <label for="i-desc">Descripción</label>
            <input id="i-desc" class="input" v-model="form.descripcion" />
          </div>
          <label class="chk">
            <input type="checkbox" v-model="form.uso_interno" />
            Uso interno (la clínica lo consume, no se vende)
          </label>
          <p v-if="errorModal" class="error-text">{{ errorModal }}</p>
          <div class="modal-acciones">
            <button type="button" class="btn btn-ghost" @click="modal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="guardando">
              {{ guardando ? 'Guardando…' : 'Guardar' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* ── Alertas de bodega ── */
.alertas {
  margin-bottom: 1.2rem;
  border: 1px solid var(--line);
  border-radius: var(--r-md);
  background: var(--surface);
  overflow: hidden;
}
.alertas-cab {
  width: 100%; display: flex; align-items: center; justify-content: space-between;
  gap: 1rem; padding: 0.8rem 1rem;
  background: var(--warn-tint); border: none; cursor: pointer;
  font-family: inherit; font-size: var(--t-base); text-align: left;
}
.alertas-tit { display: flex; align-items: center; gap: 0.5rem; font-weight: 700; color: var(--warn-ink); }
.alertas-chevron { font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft); }
.alertas-cab:hover .alertas-chevron { color: var(--accent-strong); }

.alertas-cuerpo { padding: 0.9rem 1rem 1rem; display: flex; flex-direction: column; gap: 1.1rem; }
.alerta-grupo { display: flex; flex-direction: column; gap: 0.4rem; }
.alerta-sub { font-size: var(--t-sm); font-weight: 700; color: var(--ink); }
/* Lo ya vencido se distingue de lo que está por vencer: son dos trabajos, y el
   color es lo que evita tener que leer cada fecha para saber en cuál se está. */
.alerta-sub.vencido { color: var(--danger); }
.alerta-lista { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.35rem; }
.alerta-lista li { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.4rem; font-size: var(--t-sm); }
.alerta-meta { color: var(--ink-soft); font-size: var(--t-micro); }

.barra { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.2rem; flex-wrap: wrap; }
.chk { display: flex; align-items: center; gap: 0.4rem; font-size: var(--t-base); color: var(--ink-soft); }
/* Solo la maquetación de ESTA tabla. El resto —tamaño, alto de fila,
   separador, franja alterna, hover— vive en app.css y se hereda.
   Acá había una copia completa de esas reglas, y como los estilos scoped ganan
   por especificidad, esta vista se quedaba fuera de cualquier ajuste que se
   hiciera en el archivo compartido: era el motivo de que el panel se viera
   disparejo de una pantalla a otra. */
.t-row {
  grid-template-columns: 1.4fr 1fr 1fr 0.9fr 0.7fr 0.8fr 0.8fr auto;
  min-width: 960px;
}
.item-col { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; }
.item-col small { color: var(--ink-soft); }
.cap { text-transform: capitalize; }
.stock-bajo { color: var(--warn-ink); font-weight: 600; }
.venc-proximo { color: var(--warn-ink); font-weight: 600; }
.badge-interno { color: var(--ink-soft); font-style: italic; }

.acciones { display: flex; gap: 0.4rem; justify-content: flex-end; }
/* .peligro vive en app.css; era una copia que ganaba por especificidad. */

.overlay {
  position: fixed; inset: 0; z-index: 50;
  background: rgba(0, 0, 0, 0.45);
  display: flex; align-items: center; justify-content: center; padding: 1rem;
}
.modal-box {
  background: #fff; border-radius: var(--r-lg);
  padding: 1.4rem; width: 100%; max-width: 480px;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
  max-height: 90vh; overflow-y: auto;
}
.modal-box h2 { font-size: var(--t-xl); margin: 0 0 1rem; }
.modal-box form { display: flex; flex-direction: column; gap: 0.8rem; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
.modal-acciones { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 0.3rem; }
@media (max-width: 560px) { .grid-2 { grid-template-columns: 1fr; } }
.margen-bajo { display: block; color: var(--warn-ink); font-size: var(--t-micro); }
</style>
