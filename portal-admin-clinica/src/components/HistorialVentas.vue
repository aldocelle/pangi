<script setup>
/* Historial de compras a nivel de venta: una fila por cobro, con su total y
   su estado de pago. Es la lectura correcta para un CLIENTE, porque la venta
   completa es suya.

   Para un paciente o un producto la unidad no es la venta sino la línea (una
   boleta puede mezclar dos mascotas) — ese caso lo cubre
   HistorialLineasVenta.vue. */
import { ref, computed, watch, onMounted } from 'vue';
import { api, apiError, formatCLP } from '../api.js';
import Paginador from './Paginador.vue';
import DetalleVentaModal from './DetalleVentaModal.vue';

const props = defineProps({
  /* Filtros que definen el historial: { propietario_id }, { mascota_id }… */
  params: { type: Object, required: true },
  vacio: { type: String, default: 'Sin compras registradas.' },
  limit: { type: Number, default: 5 },
});

const ventas = ref([]);
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
    /* Listado y totales en paralelo: el encabezado ("12 compras por
       $512.000") se calcula sobre TODAS las coincidencias, no sobre la
       página visible, así que son dos consultas y no una suma del cliente. */
    const [lista, agg] = await Promise.all([
      api.get('/transacciones', { params: { ...props.params, page: pagina.value, limit: props.limit } }),
      api.get('/transacciones/resumen', { params: props.params }),
    ]);
    ventas.value = lista.data.items;
    paginacion.value = {
      total: lista.data.total, total_paginas: lista.data.total_paginas, limit: lista.data.limit,
    };
    resumen.value = agg.data;
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar el historial de compras.');
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

function pacientesDe(v) {
  return v.pacientes.map((p) => p.nombre).join(', ') || '—';
}

const hayPendiente = computed(() => (resumen.value?.pendiente || 0) > 0);

onMounted(cargar);
</script>

<template>
  <div class="hv">
    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-else-if="loading" class="muted small">Cargando compras…</p>
    <p v-else-if="!ventas.length" class="muted small">{{ vacio }}</p>

    <template v-else>
      <p class="hv-resumen">
        <strong>{{ resumen.n_ventas }}</strong> compra{{ resumen.n_ventas === 1 ? '' : 's' }}
        por <strong>{{ formatCLP(resumen.total) }}</strong>
        <span v-if="hayPendiente" class="hv-pendiente">
          · {{ formatCLP(resumen.pendiente) }} pendiente
        </span>
      </p>

      <ul class="hv-lista">
        <li v-for="v in ventas" :key="v.id">
          <button class="t-row t-row-link hv-fila" :class="{ 'hv-anulada': v.estado_venta === 'anulada' }" @click="abierta = v">
            <span class="hv-fecha">{{ fmt(v.fecha) }}</span>
            <span class="hv-num">#{{ String(v.numero || 0).padStart(6, '0') }}</span>
            <span class="hv-paciente">{{ pacientesDe(v) }}</span>
            <span class="hv-detalle">{{ v.lineas.map((l) => l.nombre).join(' · ') || v.detalle }}</span>
            <span class="hv-total">{{ formatCLP(v.total) }}</span>
            <span class="estado" :class="`estado-${v.estado}`">{{ v.estado_label }}</span>
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
      v-if="abierta"
      :venta-id="abierta.id" :precargada="abierta"
      @close="abierta = null" @actualizada="cargar"
    />
  </div>
</template>

<style scoped>
/* Consulta de CONTENEDOR y no de viewport: este historial vive tanto en el
   panel lateral de Clientes (~300 px) como a ancho completo. Con un
   `@media` la fila seguía en modo tabla dentro del panel angosto y el estado
   se cortaba fuera del borde — la pantalla era ancha aunque el hueco no. */
.hv { container-type: inline-size; }

.hv-resumen { font-size: var(--t-sm); color: var(--ink-soft); margin-bottom: 0.5rem; }
.hv-resumen strong { color: var(--ink); }
.hv-pendiente { color: var(--danger); font-weight: 600; }

.hv-lista { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
/* Era una tabla mas, reimplementada con otro nombre de clase: por eso quedaba
   fuera de todo lo que se arreglara en las tablas del panel. Ahora hereda
   separador, alto, tamano y hover de .t-row/.t-row-link, y de paso gana el
   apilado en movil que no tenia. Aca no aplica la franja alterna: la fila es
   un <button> dentro de un <li>, y nth-of-type cuenta hermanos del mismo tipo,
   asi que cada boton es siempre el primero de su li. No se fuerza — son unas
   pocas filas dentro de una ficha, no un listado paginado. */
.hv-fila { grid-template-columns: 84px 76px 1fr 1.4fr auto auto; width: 100%; text-align: left; }
.hv-fecha, .hv-num, .hv-total { font-variant-numeric: tabular-nums; }
.hv-num { color: var(--accent-strong); font-weight: 600; }
.hv-paciente { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hv-detalle { color: var(--ink-soft); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hv-total { font-weight: 600; text-align: right; }
/* Igual que en el listado: una anulada se sigue viendo, pero no puede
   leerse como plata que el cliente gastó. */
.hv-anulada { opacity: 0.62; }
.hv-anulada .hv-total { text-decoration: line-through; }

.estado { display: inline-flex; padding: 0.15em 0.6em; border-radius: var(--r-full); font-size: var(--t-micro); font-weight: 600; white-space: nowrap; }
.estado-pagada { background: var(--ok-tint); color: var(--ok); }
.estado-parcial { background: var(--warn-tint); color: var(--warn-ink); }
.estado-pendiente { background: var(--danger-tint); color: var(--danger); }
.estado-anulada { background: var(--danger-tint); color: var(--danger); }
.estado-borrador { background: var(--brand-tint); color: var(--brand-ink); }
.estado-devuelta, .estado-devuelta_parcial { background: var(--warn-tint); color: var(--warn-ink); }

.muted { color: var(--ink-soft); }
.small { font-size: var(--t-sm); }

/* Nombrar las celdas solo en el modo angosto: en el ancho la rejilla es una
   tabla y las áreas estorbarían. */
@container (max-width: 620px) {
  .hv-fila {
    grid-template-columns: 1fr auto;
    grid-template-areas:
      "num total"
      "fecha estado"
      "paciente paciente"
      "detalle detalle";
    gap: 0.1rem 0.6rem; padding: 0.6rem 0.4rem;
  }
  .hv-num { grid-area: num; }
  .hv-total { grid-area: total; }
  .hv-fecha { grid-area: fecha; color: var(--ink-soft); font-size: var(--t-micro); }
  .estado { grid-area: estado; justify-self: end; }
  .hv-paciente { grid-area: paciente; white-space: normal; }
  .hv-detalle { grid-area: detalle; white-space: normal; font-size: var(--t-micro); }
}
</style>
