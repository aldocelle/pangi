<script setup>
/* Historial de ventas línea a línea: una fila por producto o servicio
   vendido. Es la lectura correcta cuando la pregunta no es "qué boleta" sino
   "a quién se le vendió esto" (ficha de un producto o de un servicio) o "qué
   se compró para este paciente".

   Para un PACIENTE importa la distinción: una boleta puede mezclar dos
   mascotas del mismo dueño, y mostrar la venta completa en la ficha de Luna
   le atribuiría el alimento de Max. Por eso acá la unidad es la línea, y
   `agrupar` solo las junta visualmente por venta. */
import { ref, computed, watch, onMounted } from 'vue';
import { api, apiError, formatCLP } from '../api.js';
import Paginador from './Paginador.vue';
import DetalleVentaModal from './DetalleVentaModal.vue';

const props = defineProps({
  /* { item_id } · { servicio_id, servicio_nombre } · { mascota_id } … */
  params: { type: Object, required: true },
  vacio: { type: String, default: 'Sin ventas registradas.' },
  limit: { type: Number, default: 10 },
  /* Junta las líneas de una misma venta bajo una cabecera con su fecha.
     Para la ficha del paciente; para un producto estorba (cada fila es una
     venta distinta). */
  agrupar: { type: Boolean, default: false },
  mostrarCliente: { type: Boolean, default: true },
  mostrarPaciente: { type: Boolean, default: true },
});

const lineas = ref([]);
const resumen = ref(null);
const paginacion = ref({ total: 0, total_paginas: 1, limit: props.limit });
const pagina = ref(1);
const loading = ref(true);
const error = ref('');
const abierta = ref(null);

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const { data } = await api.get('/transacciones/lineas', {
      params: { ...props.params, page: pagina.value, limit: props.limit },
    });
    lineas.value = data.items;
    resumen.value = data.resumen;
    paginacion.value = { total: data.total, total_paginas: data.total_paginas, limit: data.limit };
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar el historial de ventas.');
  } finally {
    loading.value = false;
  }
}

function cambiarPagina(p) {
  pagina.value = p;
  cargar();
}

/* Cambiar de cliente/paciente sin desmontar el componente (el panel de
   detalle reusa la misma instancia) tiene que recargar el historial.

   La clave es el JSON y no el objeto: quien lo monta pasa un literal
   (`:params="{ propietario_id: detalle.id }"`), que es un objeto nuevo en
   cada render del padre — vigilarlo por identidad disparaba una consulta
   cada vez que se editaba cualquier campo de la ficha. */
watch(() => JSON.stringify(props.params), () => { pagina.value = 1; cargar(); });

function fmt(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/* Agrupación solo dentro de la página cargada: una venta partida entre dos
   páginas se ve como dos grupos, que es preferible a traerse el historial
   entero al navegador para poder juntarla. */
const grupos = computed(() => {
  const mapa = new Map();
  for (const l of lineas.value) {
    const clave = l.transaccion_id || l.id;
    if (!mapa.has(clave)) {
      mapa.set(clave, { clave, transaccion_id: l.transaccion_id, numero: l.numero, fecha: l.fecha, lineas: [] });
    }
    mapa.get(clave).lineas.push(l);
  }
  return [...mapa.values()].map((g) => ({
    ...g,
    total: g.lineas.reduce((n, l) => n + l.total, 0),
  }));
});

function abrir(transaccionId) {
  if (transaccionId) abierta.value = transaccionId;
}

onMounted(cargar);
</script>

<template>
  <div class="hl">
    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-else-if="loading" class="muted small">Cargando…</p>
    <p v-else-if="!lineas.length" class="muted small">{{ vacio }}</p>

    <template v-else>
      <p class="hl-resumen">
        <strong>{{ resumen.n_lineas }}</strong> venta{{ resumen.n_lineas === 1 ? '' : 's' }} ·
        <strong>{{ resumen.n_unidades }}</strong> unidad{{ resumen.n_unidades === 1 ? '' : 'es' }} ·
        <strong>{{ formatCLP(resumen.total) }}</strong>
        <span v-if="resumen.ultima_fecha" class="muted"> · última el {{ fmt(resumen.ultima_fecha) }}</span>
      </p>

      <!-- Agrupado por venta (ficha del paciente) -->
      <div v-if="agrupar" class="hl-grupos">
        <article v-for="g in grupos" :key="g.clave" class="hl-grupo">
          <header class="hl-grupo-head">
            <span class="hl-fecha">{{ fmt(g.fecha) }}</span>
            <button v-if="g.transaccion_id" class="hl-ver-venta" @click="abrir(g.transaccion_id)">
              Venta #{{ String(g.numero || 0).padStart(6, '0') }} →
            </button>
            <span class="hl-grupo-total">{{ formatCLP(g.total) }}</span>
          </header>
          <ul class="hl-grupo-lineas">
            <li v-for="l in g.lineas" :key="l.id">
              <span>{{ l.cantidad }}× {{ l.nombre }}</span>
              <span class="hl-monto">{{ formatCLP(l.total) }}</span>
            </li>
          </ul>
        </article>
      </div>

      <!-- Plano (ficha de un producto o servicio) -->
      <ul v-else class="hl-lista">
        <li v-for="l in lineas" :key="l.id">
          <button class="t-row t-row-link hl-fila" :disabled="!l.transaccion_id" @click="abrir(l.transaccion_id)">
            <span class="hl-fecha">{{ fmt(l.fecha) }}</span>
            <span v-if="mostrarCliente" class="hl-cliente">{{ l.cliente?.nombre || 'Sin cliente' }}</span>
            <span v-if="mostrarPaciente" class="hl-paciente">{{ l.paciente || '—' }}</span>
            <span class="hl-cant">{{ l.cantidad }} {{ l.cantidad === 1 ? 'unidad' : 'unidades' }}</span>
            <span class="hl-monto">{{ formatCLP(l.total) }}</span>
          </button>
        </li>
      </ul>

      <Paginador
        :page="pagina" :total-paginas="paginacion.total_paginas"
        :total="paginacion.total" :limit="paginacion.limit"
        @cambiar="cambiarPagina"
      />
    </template>

    <DetalleVentaModal
      v-if="abierta" :venta-id="abierta"
      @close="abierta = null" @actualizada="cargar"
    />
  </div>
</template>

<style scoped>
/* Mismo motivo que en HistorialVentas.vue: el ancho que manda es el del
   hueco donde se monta (un modal, una tarjeta de la ficha), no el de la
   pantalla. */
.hl { container-type: inline-size; }

.hl-resumen { font-size: var(--t-sm); color: var(--ink-soft); margin-bottom: 0.6rem; }
.hl-resumen strong { color: var(--ink); }

.hl-lista { list-style: none; margin: 0; padding: 0; }
/* Misma historia que .hv-fila: tabla reimplementada. Hereda de .t-row. */
.hl-fila { grid-template-columns: 92px 1.3fr 1fr 90px auto; width: 100%; text-align: left; }
.hl-fila:disabled:hover { background: none; }
.hl-fila:disabled { cursor: default; }
.hl-cliente { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hl-paciente { color: var(--ink-soft); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hl-fecha, .hl-cant, .hl-monto { font-variant-numeric: tabular-nums; }
.hl-cant { color: var(--ink-soft); }
.hl-monto { font-weight: 600; text-align: right; }

.hl-grupos { display: flex; flex-direction: column; gap: 0.7rem; }
.hl-grupo { border: 1px solid var(--line); border-radius: var(--r-md); padding: 0.6rem 0.85rem; }
.hl-grupo-head { display: flex; align-items: baseline; gap: 0.7rem; margin-bottom: 0.35rem; }
.hl-grupo-head .hl-fecha { font-weight: 600; font-size: var(--t-sm); }
.hl-ver-venta { font-size: var(--t-micro); font-weight: 600; color: var(--accent-strong); }
.hl-ver-venta:hover { text-decoration: underline; }
.hl-grupo-total { margin-left: auto; font-weight: 700; font-variant-numeric: tabular-nums; }
.hl-grupo-lineas { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.15rem; }
.hl-grupo-lineas li { display: flex; justify-content: space-between; gap: 1rem; font-size: var(--t-sm); }
.hl-grupo-lineas .hl-monto { color: var(--ink-soft); font-weight: 500; }

.muted { color: var(--ink-soft); }
.small { font-size: var(--t-sm); }

@container (max-width: 560px) {
  .hl-fila {
    grid-template-columns: 1fr auto;
    grid-template-areas:
      "fecha monto"
      "cliente cant"
      "paciente paciente";
    gap: 0.1rem 0.6rem;
  }
  .hl-fecha { grid-area: fecha; }
  .hl-monto { grid-area: monto; }
  .hl-cliente { grid-area: cliente; white-space: normal; }
  .hl-cant { grid-area: cant; text-align: right; }
  .hl-paciente { grid-area: paciente; white-space: normal; }
}
</style>
