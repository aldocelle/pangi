<script setup>
import { ref, computed, watch, nextTick } from 'vue';

/* Calendario del mes para saltar de día en la Agenda. Un solo día recibe el
   foco con Tab (el elegido) y las flechas se mueven por la rejilla: 42 botones
   en el orden de tabulación harían imposible salir del calendario. */
const props = defineProps({
  modelValue: { type: String, required: true }, // YYYY-MM-DD
});
const emit = defineEmits(['update:modelValue']);

const DOW = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];

function iso(d) {
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}
function aDate(s) {
  const [y, m, d] = s.split('-').map(Number);
  return new Date(y, m - 1, d);
}
const hoy = iso(new Date());

// El mes que se mira puede no ser el del día elegido (al hojear con las flechas).
const mes = ref(props.modelValue.slice(0, 7));
watch(() => props.modelValue, (v) => { mes.value = v.slice(0, 7); });

const titulo = computed(() =>
  aDate(`${mes.value}-01`).toLocaleDateString('es-CL', { month: 'long', year: 'numeric' }));

const dias = computed(() => {
  const primero = aDate(`${mes.value}-01`);
  const inicio = new Date(primero);
  inicio.setDate(1 - ((primero.getDay() + 6) % 7)); // la semana parte el lunes
  return Array.from({ length: 42 }, (_, i) => {
    const d = new Date(inicio);
    d.setDate(inicio.getDate() + i);
    const s = iso(d);
    return { iso: s, num: d.getDate(), fuera: s.slice(0, 7) !== mes.value };
  });
});

function moverMes(n) {
  const d = aDate(`${mes.value}-01`);
  d.setMonth(d.getMonth() + n);
  mes.value = iso(d).slice(0, 7);
}

const rejilla = ref(null);
async function teclear(e, diaIso) {
  const salto = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
  if (salto === undefined) return;
  e.preventDefault();
  const d = aDate(diaIso);
  d.setDate(d.getDate() + salto);
  emit('update:modelValue', iso(d));
  await nextTick();
  rejilla.value?.querySelector('[tabindex="0"]')?.focus();
}
const enfocable = computed(() =>
  (dias.value.some((d) => d.iso === props.modelValue) ? props.modelValue : dias.value.find((d) => !d.fuera)?.iso));
</script>

<template>
  <div class="mini-cal">
    <div class="mc-cab">
      <button type="button" class="mc-flecha" aria-label="Mes anterior" @click="moverMes(-1)">‹</button>
      <span class="mc-titulo">{{ titulo }}</span>
      <button type="button" class="mc-flecha" aria-label="Mes siguiente" @click="moverMes(1)">›</button>
    </div>
    <div class="mc-dow" aria-hidden="true"><span v-for="(d, i) in DOW" :key="i">{{ d }}</span></div>
    <div ref="rejilla" class="mc-dias" role="group" :aria-label="titulo">
      <button
        v-for="d in dias" :key="d.iso" type="button" class="mc-dia"
        :class="{ fuera: d.fuera, hoy: d.iso === hoy, elegido: d.iso === modelValue }"
        :tabindex="d.iso === enfocable ? 0 : -1"
        :aria-pressed="d.iso === modelValue"
        :aria-label="new Date(d.iso + 'T12:00').toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long' })"
        @click="emit('update:modelValue', d.iso)" @keydown="teclear($event, d.iso)"
      >{{ d.num }}</button>
    </div>
  </div>
</template>

<style scoped>
.mc-cab { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.35rem; }
.mc-titulo { font-size: var(--t-sm); font-weight: 600; }
.mc-titulo::first-letter { text-transform: uppercase; }
.mc-flecha { width: 28px; height: 28px; border-radius: var(--r-sm); font-size: 1.1rem; color: var(--ink-soft); }
.mc-flecha:hover { background: var(--accent-hover); color: var(--ink); }
.mc-dow, .mc-dias { display: grid; grid-template-columns: repeat(7, 1fr); }
.mc-dow span { text-align: center; font-size: var(--t-micro); color: var(--ink-soft); font-weight: 600; padding: 0.15rem 0; }
.mc-dia {
  aspect-ratio: 1; border-radius: 50%; font-size: var(--t-micro); font-weight: 500;
  display: grid; place-items: center; transition: background 0.12s ease-out;
}
.mc-dia:hover { background: var(--accent-hover); }
.mc-dia.fuera { color: var(--ink-soft); opacity: 0.55; }
.mc-dia.hoy { box-shadow: inset 0 0 0 1.5px var(--brand); font-weight: 700; }
.mc-dia.elegido { background: var(--brand); color: #fff; font-weight: 700; opacity: 1; }
@media (prefers-reduced-motion: reduce) { .mc-dia { transition: none; } }
</style>
