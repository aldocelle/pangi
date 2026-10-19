<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';

/* Panel flotante anclado a lo que se tocó en la Agenda (una cita, un bloqueo,
   un hueco libre). Va con `position: fixed` y no dentro de la grilla, porque
   la grilla tiene su propio scroll y lo recortaría. Se acomoda para no salirse
   de la pantalla: a la derecha del ancla si cabe, si no a la izquierda. */
const props = defineProps({
  ancla: { type: Object, required: true }, // DOMRect
  ancho: { type: Number, default: 320 },
  etiqueta: { type: String, default: '' },
});
const emit = defineEmits(['cerrar']);

const caja = ref(null);
const alto = ref(0);
const MARGEN = 10;

const estilo = computed(() => {
  const vw = window.innerWidth;
  const vh = window.innerHeight;
  const w = Math.min(props.ancho, vw - MARGEN * 2);
  const a = props.ancla;
  let left = a.right + 8;
  if (left + w > vw - MARGEN) left = a.left - w - 8;
  if (left < MARGEN) left = Math.min(Math.max(MARGEN, a.left), vw - w - MARGEN);
  let top = a.top;
  if (top + alto.value > vh - MARGEN) top = vh - alto.value - MARGEN;
  top = Math.max(MARGEN + 52, top);
  return { left: `${left}px`, top: `${top}px`, width: `${w}px` };
});

let previo = null;
function fuera(e) {
  if (caja.value && !caja.value.contains(e.target)) emit('cerrar');
}
function tecla(e) {
  if (e.key === 'Escape') { e.stopPropagation(); emit('cerrar'); }
}
onMounted(async () => {
  previo = document.activeElement;
  await nextTick();
  alto.value = caja.value?.offsetHeight || 0;
  // La primera acción del contenido, no la ✕: es lo que se viene a hacer.
  const sel = 'button, a, select, input, textarea';
  (caja.value?.querySelector(`.burbuja-cuerpo :is(${sel})`) || caja.value?.querySelector(sel))?.focus();
  // En el siguiente tick: el mismo clic que abrió la burbuja no debe cerrarla.
  setTimeout(() => document.addEventListener('pointerdown', fuera), 0);
  document.addEventListener('keydown', tecla, true);
});
onUnmounted(() => {
  document.removeEventListener('pointerdown', fuera);
  document.removeEventListener('keydown', tecla, true);
  if (previo && document.contains(previo)) previo.focus?.();
});
</script>

<template>
  <Teleport to="body">
    <div ref="caja" class="burbuja" role="dialog" :aria-label="etiqueta" :style="estilo">
      <button type="button" class="burbuja-cerrar" aria-label="Cerrar" @click="emit('cerrar')">✕</button>
      <div class="burbuja-cuerpo"><slot /></div>
    </div>
  </Teleport>
</template>

<style scoped>
.burbuja {
  position: fixed; z-index: var(--z-desplegable);
  background: var(--surface); color: var(--ink);
  border: 1px solid var(--line); border-radius: var(--r-md); box-shadow: var(--shadow-lg);
  padding: 0.9rem 1rem 1rem;
  max-height: calc(100dvh - 80px); overflow-y: auto;
  animation: entrar 0.14s ease-out;
}
@keyframes entrar { from { opacity: 0; transform: scale(0.98); } to { opacity: 1; transform: none; } }
.burbuja-cerrar {
  position: absolute; top: 0.45rem; right: 0.45rem;
  width: 28px; height: 28px; border-radius: var(--r-sm);
  color: var(--ink-soft); font-size: 0.9rem;
}
.burbuja-cerrar:hover { background: var(--accent-hover); color: var(--ink); }
@media (prefers-reduced-motion: reduce) { .burbuja { animation: none; } }
@media print { .burbuja { display: none; } }
</style>
