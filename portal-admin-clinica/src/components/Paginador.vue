<script setup>
import { computed } from 'vue';

const props = defineProps({
  page: { type: Number, required: true },
  totalPaginas: { type: Number, required: true },
  total: { type: Number, required: true },
  limit: { type: Number, required: true },
});
const emit = defineEmits(['cambiar']);

// Ventana de páginas visibles alrededor de la actual (máx 5 números + extremos).
const paginas = computed(() => {
  const actual = props.page;
  const ultima = props.totalPaginas;
  const ventana = 1;
  const set = new Set([1, ultima]);
  for (let i = actual - ventana; i <= actual + ventana; i++) {
    if (i >= 1 && i <= ultima) set.add(i);
  }
  return [...set].sort((a, b) => a - b);
});

const desde = computed(() => (props.total === 0 ? 0 : (props.page - 1) * props.limit + 1));
const hasta = computed(() => Math.min(props.page * props.limit, props.total));

function ir(p) {
  if (p < 1 || p > props.totalPaginas || p === props.page) return;
  emit('cambiar', p);
}
</script>

<template>
  <nav v-if="totalPaginas > 1" class="paginador" aria-label="Paginación">
    <span class="rango">{{ desde }}–{{ hasta }} de {{ total }}</span>
    <div class="botones">
      <button class="pg-btn" :disabled="page === 1" @click="ir(page - 1)" aria-label="Página anterior">‹</button>
      <template v-for="(p, i) in paginas" :key="p">
        <span v-if="i > 0 && p - paginas[i - 1] > 1" class="pg-gap">…</span>
        <button class="pg-btn" :class="{ activo: p === page }" @click="ir(p)" :aria-current="p === page ? 'page' : undefined">
          {{ p }}
        </button>
      </template>
      <button class="pg-btn" :disabled="page === totalPaginas" @click="ir(page + 1)" aria-label="Página siguiente">›</button>
    </div>
  </nav>
  <p v-else-if="total > 0" class="rango rango-sola">{{ total }} resultado{{ total === 1 ? '' : 's' }}</p>
</template>

<style scoped>
.paginador {
  display: flex; align-items: center; justify-content: space-between;
  gap: 1rem; flex-wrap: wrap;
  padding: 0.9rem 0.4rem 0.2rem;
  font-size: var(--t-sm);
}
.rango { color: var(--ink-soft); }
.rango-sola { padding: 0.9rem 0.4rem 0.2rem; }
.botones { display: flex; align-items: center; gap: 0.25rem; }
.pg-btn {
  min-width: 32px; height: 32px; padding: 0 0.5em;
  border-radius: var(--r-md); font-weight: 600; font-size: var(--t-sm);
  color: var(--ink-soft); transition: background 0.15s, color 0.15s;
}
.pg-btn:hover:not(:disabled) { background: var(--accent-tint); color: var(--accent-strong); }
.pg-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.pg-btn.activo { background: var(--accent); color: #fff; }
.pg-gap { color: var(--ink-soft); padding: 0 0.2rem; }
</style>
