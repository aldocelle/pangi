<script setup>
defineProps({
  activo: { type: String, required: true }, // 'clientes' | 'pacientes'
});
</script>

<template>
  <div class="toggle-personas" role="tablist" aria-label="Alternar entre clientes y mascotas">
    <RouterLink to="/clientes" class="tp-opcion" :class="{ sel: activo === 'clientes' }" role="tab" :aria-selected="activo === 'clientes'">
      Clientes
    </RouterLink>
    <RouterLink to="/pacientes" class="tp-opcion" :class="{ sel: activo === 'pacientes' }" role="tab" :aria-selected="activo === 'pacientes'">
      Mascotas
    </RouterLink>
  </div>
</template>

<style scoped>
.toggle-personas {
  display: inline-flex; padding: 0.2rem;
  background: var(--bg); border: 1px solid var(--line); border-radius: var(--r-full);
}
.tp-opcion {
  padding: 0.4em 1.1em; border-radius: var(--r-full);
  font-size: var(--t-sm); font-weight: 600; color: var(--ink-soft);
  transition: background 0.15s, color 0.15s;
}
.tp-opcion.sel { background: var(--accent); color: #fff; }
.tp-opcion:not(.sel):hover { color: var(--accent-strong); }
</style>
