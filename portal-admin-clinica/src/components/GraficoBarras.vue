<script setup>
/* Wrapper delgado sobre chart.js — un solo tipo de gráfico (barras) que
   cubre las tres vistas que necesita Estadísticas (comparación de
   períodos, top productos, top servicios). No se agregó un componente de
   líneas aparte: los "3 períodos" a comparar (mes actual / mes anterior /
   mismo mes año pasado) no son puntos consecutivos en el tiempo —año
   anterior no es adyacente a mes anterior—, así que dibujarlos como línea
   sugeriría una tendencia continua que no existe. Barras compara sin
   inventar continuidad. */
import { ref, onMounted, onUnmounted, watch } from 'vue';
import {
  Chart, BarController, BarElement, LinearScale, CategoryScale, Tooltip,
} from 'chart.js';
import { formatCLP } from '../api.js';

Chart.register(BarController, BarElement, LinearScale, CategoryScale, Tooltip);

const props = defineProps({
  labels: { type: Array, required: true },
  datos: { type: Array, required: true },
  horizontal: { type: Boolean, default: false },
});

const canvas = ref(null);
let chart = null;

// Paleta categórica validada (skill de dataviz): cada barra es una entidad
// distinta (un producto, un servicio), no un paso de magnitud, así que el
// color identifica en vez de degradar. Orden fijo —no se reordena ni se
// cicla— porque ese orden es lo que garantiza la separación ante daltonismo.
const PALETA_CATEGORICA = [
  '#2a78d6', '#eb6834', '#1baf7a', '#eda100',
  '#e87ba4', '#008300', '#4a3aa7', '#e34948',
];

function coloresBarras(n) {
  return Array.from({ length: n }, (_, i) => PALETA_CATEGORICA[i % PALETA_CATEGORICA.length]);
}

// En modo horizontal el eje de categorías (y) puede traer nombres largos
// (productos/servicios reales). Chart.js no los envuelve ni reserva más
// ancho del que le cabe al gráfico — un nombre largo se corta contra el
// borde del canvas en vez de mostrarse completo. Se trunca solo el texto
// del eje; el tooltip (`ctx.label`, no lo que devuelve este callback) sigue
// mostrando el nombre completo al pasar el mouse.
function truncar(texto) {
  const s = String(texto ?? '');
  return s.length > 24 ? `${s.slice(0, 24)}…` : s;
}

function crear() {
  if (!canvas.value) return;
  chart = new Chart(canvas.value, {
    type: 'bar',
    data: {
      labels: props.labels,
      datasets: [{ data: props.datos, backgroundColor: coloresBarras(props.datos.length), borderRadius: 4, maxBarThickness: 42 }],
    },
    options: {
      indexAxis: props.horizontal ? 'y' : 'x',
      responsive: true,
      maintainAspectRatio: false,
      // Pedido explícito: Top productos/Top servicios (las dos únicas vistas
      // que usan este componente) entran con una animación de 2 s en vez del
      // segundo por defecto de chart.js.
      animation: { duration: 2000 },
      plugins: {
        tooltip: { callbacks: { label: (ctx) => formatCLP(ctx.parsed[props.horizontal ? 'x' : 'y']) } },
      },
      scales: {
        x: props.horizontal ? { ticks: { callback: (v) => formatCLP(v) } } : { ticks: { autoSkip: false } },
        y: props.horizontal
          ? { ticks: { autoSkip: false, callback: function (v) { return truncar(this.getLabelForValue(v)); } } }
          : { ticks: { callback: (v) => formatCLP(v) } },
      },
    },
  });
}

function actualizar() {
  if (!chart) return;
  chart.data.labels = props.labels;
  chart.data.datasets[0].data = props.datos;
  chart.data.datasets[0].backgroundColor = coloresBarras(props.datos.length);
  chart.update();
}

onMounted(crear);
onUnmounted(() => chart?.destroy());
watch(() => [props.labels, props.datos], actualizar, { deep: true });
</script>

<template>
  <div class="grafico-wrap">
    <canvas ref="canvas"></canvas>
  </div>
</template>

<style scoped>
.grafico-wrap { position: relative; height: 260px; }
</style>
