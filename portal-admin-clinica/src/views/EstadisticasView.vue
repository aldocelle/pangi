<script setup>
import { ref, computed, onMounted } from 'vue';
import { api, apiError, formatCLP } from '../api.js';
import GraficoBarras from '../components/GraficoBarras.vue';

const hoy = new Date();
const mesSeleccionado = ref(`${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}`);

const data = ref(null);
const loading = ref(true);
const error = ref('');

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const [anio, mes] = mesSeleccionado.value.split('-').map(Number);
    const { data: resp } = await api.get('/estadisticas/resumen', { params: { anio, mes } });
    data.value = resp;
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar las estadísticas.');
  } finally {
    loading.value = false;
  }
  // Después del `finally`: mientras `loading` es true la tarjeta no está en
  // pantalla, y arrancar ahí gastaría parte de la animación a ciegas.
  if (data.value) animarNumeros();
}

function variacion(actual, referencia) {
  if (!referencia) return null;
  return ((actual - referencia) / referencia) * 100;
}

const variacionMesAnterior = computed(() =>
  data.value ? variacion(data.value.comparacion.actual.ingreso, data.value.comparacion.mes_anterior.ingreso) : null,
);
const variacionAnioAnterior = computed(() =>
  data.value ? variacion(data.value.comparacion.actual.ingreso, data.value.comparacion.anio_anterior.ingreso) : null,
);

/* Los cuatro números del encabezado, contando desde 0 hasta su valor.
   Es estado reactivo puro: NO consulta el DOM ni depende de que el canvas
   exista, así que no puede dejar la pantalla a medias como pasó con la
   versión de Chart.js. Si algo falla, el `catch` aterriza los valores
   finales y la tarjeta se ve igual, solo sin animar. */
const animados = ref({ ingreso: 0, mesAnterior: 0, anioAnterior: 0, inventario: 0 });

const DURACION_CONTEO = 900;

function animarNumeros() {
  const destino = {
    ingreso: data.value.comparacion.actual.ingreso || 0,
    mesAnterior: variacionMesAnterior.value ?? 0,
    anioAnterior: variacionAnioAnterior.value ?? 0,
    inventario: data.value.inventario.valorizacion_total || 0,
  };
  const aterrizar = () => { animados.value = { ...destino }; };

  // Quien pidió no animar, ve el número final de una: mismo criterio que la
  // entrada de las tarjetas, que ya respeta esta preferencia por CSS.
  const sinMovimiento = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  if (sinMovimiento || typeof requestAnimationFrame !== 'function') return aterrizar();

  try {
    animados.value = { ingreso: 0, mesAnterior: 0, anioAnterior: 0, inventario: 0 };
    const t0 = performance.now();
    const paso = (ahora) => {
      const p = Math.min(1, (ahora - t0) / DURACION_CONTEO);
      const e = 1 - Math.pow(1 - p, 3);   // easeOutCubic: frena al llegar
      animados.value = {
        ingreso: destino.ingreso * e,
        mesAnterior: destino.mesAnterior * e,
        anioAnterior: destino.anioAnterior * e,
        inventario: destino.inventario * e,
      };
      // El último cuadro se asigna exacto y no interpolado: con `e` a 0.999…
      // el número final quedaría a un peso del real.
      if (p < 1) requestAnimationFrame(paso); else aterrizar();
    };
    requestAnimationFrame(paso);
  } catch {
    aterrizar();
  }
}

function fmtVariacion(v) {
  if (v == null) return 'sin referencia';
  const signo = v >= 0 ? '+' : '';
  return `${signo}${v.toFixed(1)}%`;
}

const labelsTopProductos = computed(() => (data.value?.top_productos || []).map((p) => p.nombre));
const datosTopProductos = computed(() => (data.value?.top_productos || []).map((p) => p.ingreso));
const labelsTopServicios = computed(() => (data.value?.top_servicios || []).map((s) => s.nombre));
const datosTopServicios = computed(() => (data.value?.top_servicios || []).map((s) => s.ingreso));

const inventarioMax = computed(() => Math.max(1, ...((data.value?.inventario.por_tipo || []).map((t) => t.valor))));

// Misma paleta categórica de GraficoBarras.vue: cada tipo de inventario es
// una entidad distinta, no un paso de magnitud — ver ese archivo para el
// porqué del orden fijo.
const PALETA_CATEGORICA = [
  '#2a78d6', '#eb6834', '#1baf7a', '#eda100',
  '#e87ba4', '#008300', '#4a3aa7', '#e34948',
];
function colorTipo(i) {
  return PALETA_CATEGORICA[i % PALETA_CATEGORICA.length];
}

onMounted(cargar);
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Estadísticas</h1>
        <p class="page-sub">Ingresos, tendencias y valorización de inventario.</p>
      </div>
      <input type="month" class="input" v-model="mesSeleccionado" @change="cargar" />
    </div>

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>

    <template v-else-if="data">
      <div class="tarjetas">
        <div class="tarjeta t-1">
          <span class="num">{{ formatCLP(Math.round(animados.ingreso)) }}</span>
          <span class="etq">Ingreso este mes ({{ data.comparacion.actual.n_ventas }} ventas)</span>
        </div>
        <div class="tarjeta t-2" :class="{ baja: variacionMesAnterior != null && variacionMesAnterior < 0 }">
          <span class="num">{{ variacionMesAnterior == null ? 'sin referencia' : fmtVariacion(animados.mesAnterior) }}</span>
          <span class="etq">vs. mes anterior</span>
        </div>
        <div class="tarjeta t-3" :class="{ baja: variacionAnioAnterior != null && variacionAnioAnterior < 0 }">
          <span class="num">{{ variacionAnioAnterior == null ? 'sin referencia' : fmtVariacion(animados.anioAnterior) }}</span>
          <span class="etq">vs. mismo mes año pasado</span>
        </div>
        <div class="tarjeta t-4">
          <span class="num">{{ formatCLP(Math.round(animados.inventario)) }}</span>
          <span class="etq">
            Inventario valorizado<template v-if="data.inventario.n_bajo_stock"> · ⚠ {{ data.inventario.n_bajo_stock }} bajo stock</template>
          </span>
        </div>
      </div>

      <div class="grid-2">
        <div class="card grafico-card">
          <h2 class="section-title">Top productos</h2>
          <GraficoBarras v-if="labelsTopProductos.length" :labels="labelsTopProductos" :datos="datosTopProductos" horizontal />
          <p v-else class="empty">Sin ventas de productos este mes.</p>
        </div>
        <div class="card grafico-card">
          <h2 class="section-title">Top servicios</h2>
          <GraficoBarras v-if="labelsTopServicios.length" :labels="labelsTopServicios" :datos="datosTopServicios" horizontal />
          <p v-else class="empty">Sin ventas de servicios este mes.</p>
        </div>
      </div>

      <div class="card">
        <h2 class="section-title">Por especialista</h2>
        <p v-if="!data.por_especialista.length" class="empty">Sin ventas asignadas a un especialista este mes.</p>
        <div v-else class="scroll-x">
          <table class="tabla-datos">
            <thead>
              <tr>
                <th>Especialista</th>
                <th class="num">Atenciones</th>
                <th class="num">Honorarios</th>
                <th class="num">Productos</th>
                <th class="num">Venta de productos</th>
                <th class="num">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="e in data.por_especialista" :key="e.especialista_id">
                <td>{{ e.nombre }}</td>
                <td class="num destacado">{{ e.n_servicios }}</td>
                <td class="num">{{ formatCLP(e.ingreso_servicios) }}</td>
                <td class="num apagado">{{ e.n_productos }}</td>
                <td class="num apagado">{{ formatCLP(e.ingreso_productos) }}</td>
                <td class="num total">{{ formatCLP(e.ingreso) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="nota">
          «Atenciones» cuenta consultas, exámenes, peluquería y cirugías; los productos de
          mostrador van aparte porque vender un saco de alimento no es una atención.
        </p>
      </div>

      <div class="card">
        <div class="inv-head">
          <h2 class="section-title">Valorización de inventario</h2>
          <span class="inv-total">{{ formatCLP(data.inventario.valorizacion_total) }}</span>
        </div>
        <p v-if="data.inventario.n_bajo_stock" class="inv-alerta">
          ⚠ {{ data.inventario.n_bajo_stock }} ítem{{ data.inventario.n_bajo_stock === 1 ? '' : 's' }} bajo su stock mínimo.
        </p>
        <div class="inv-barras">
          <div v-for="(t, i) in data.inventario.por_tipo" :key="t.tipo" class="inv-fila">
            <span class="inv-tipo">{{ t.tipo }}</span>
            <div class="inv-barra-fondo">
              <div class="inv-barra" :style="{ width: `${(t.valor / inventarioMax) * 100}%`, background: colorTipo(i) }"></div>
            </div>
            <span class="inv-valor">{{ formatCLP(t.valor) }} <span class="muted">({{ t.unidades }} u.)</span></span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.tarjetas { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.9rem; margin-bottom: 1.3rem; }

/* Fondo de color con texto blanco, en vez de tarjeta neutra.
   MEDIDO, no elegido a ojo (§13.1). Lo que hay que mirar es el stop MÁS CLARO
   de cada gradiente, que es el peor caso para el blanco encima; contra ese:
   morado 6.08 · verde 4.54 · naranjo 4.53 · rojo 4.98 · gris 4.83. Los cuatro
   pasan AA (4.5). El verde arrancó en 3.90 y el naranjo en 3.73 —los dos
   fallaban— y se oscurecieron hasta el primer valor que pasa: 3.90 es
   exactamente el número que el CLAUDE.md ya registra como fallo real en el
   badge de «Completada», así que no es un umbral teórico. */
.tarjeta {
  display: flex; flex-direction: column; align-items: flex-start; gap: 0.15rem;
  padding: 0.9rem 1.1rem; border-radius: var(--r-md);
  color: #fff; text-align: left;
  box-shadow: 0 3px 12px rgba(0, 0, 0, 0.10);
  animation: kpi-entra 420ms cubic-bezier(0.16, 1, 0.3, 1) both;
}
.tarjeta.t-1 { background: linear-gradient(135deg, #5f52c0, #453a94); }
.tarjeta.t-2 { background: linear-gradient(135deg, #2b837a, #1e5e57); }
.tarjeta.t-3 { background: linear-gradient(135deg, #ae641b, #7d4813); }
.tarjeta.t-4 { background: linear-gradient(135deg, #c04360, #96304a); }

/* Escalonado: entran de a una, de izquierda a derecha. */
.tarjeta.t-1 { animation-delay: 0ms; }
.tarjeta.t-2 { animation-delay: 70ms; }
.tarjeta.t-3 { animation-delay: 140ms; }
.tarjeta.t-4 { animation-delay: 210ms; }

@keyframes kpi-entra {
  from { opacity: 0; transform: translateY(10px) scale(0.98); }
  to   { opacity: 1; transform: none; }
}

/* Quien pidió no animar, no se anima: la tarjeta aparece en su sitio. */
@media (prefers-reduced-motion: reduce) {
  .tarjeta { animation: none; }
}

.tarjeta .num { font-size: var(--t-2xl); font-weight: 800; line-height: 1.15; }
/* En baja NO se pinta el número de rojo —sobre un fondo de color no se leería—:
   la tarjeta entera pasa a gris, que es la misma señal sin perder contraste. */
.tarjeta.baja { background: linear-gradient(135deg, #6b7280, #4b5563); }
.tarjeta .etq { font-size: var(--t-micro); opacity: 0.93; }

.grafico-card { margin-bottom: 1rem; }
.grafico-card .section-title { margin: 0 0 0.8rem; font-size: var(--t-lg); }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }

/* `.tabla-datos` y `.scroll-x` viven en app.css. Acá solo lo propio de esta
   tabla: los números alineados a la derecha y el peso de cada columna. */
.num { text-align: right; font-variant-numeric: tabular-nums; }
.destacado { font-weight: 700; }
.total { font-weight: 700; }
/* Los productos se ven, pero un paso atrás: la pregunta de la clínica es
   cuántas ATENCIONES hizo cada profesional, y el mostrador no debe competir
   con esa columna. Sigue siendo --ink-soft (6.1 de contraste), no un gris
   inventado más claro. */
.apagado { color: var(--ink-soft); }
.nota { font-size: var(--t-micro); color: var(--ink-soft); margin-top: 0.7rem; }

.inv-head { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; margin-bottom: 0.6rem; }
.inv-head .section-title { margin: 0; }
.inv-total { font-size: var(--t-xl); font-weight: 700; }
.inv-alerta { background: var(--warn-tint); color: var(--warn-ink); padding: 0.5rem 0.8rem; border-radius: var(--r-md); font-size: var(--t-sm); margin-bottom: 0.8rem; }
.inv-barras { display: flex; flex-direction: column; gap: 0.6rem; }
.inv-fila { display: grid; grid-template-columns: 110px 1fr 180px; align-items: center; gap: 0.7rem; font-size: var(--t-sm); }
.inv-barra-fondo { background: var(--bg); border-radius: var(--r-full); height: 10px; overflow: hidden; }
.inv-barra { background: var(--accent); height: 100%; border-radius: var(--r-full); }
.inv-valor { text-align: right; }
.muted { color: var(--ink-soft); font-size: var(--t-micro); }

@media (max-width: 1040px) {
  .tarjetas { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 780px) {
  .tarjetas { grid-template-columns: 1fr; }
  .grid-2 { grid-template-columns: 1fr; }
  .inv-fila { grid-template-columns: 90px 1fr; grid-template-areas: "tipo tipo" "barra barra" "valor valor"; row-gap: 0.2rem; }
}
</style>
