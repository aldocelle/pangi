<script setup>
const props = defineProps({
  campo: { type: String, required: true },
  ordenActual: { type: String, default: '' }, // ej: 'nombre' o '-nombre' (prefijo - = descendente)
});
const emit = defineEmits(['ordenar']);

function activo() {
  return props.ordenActual === props.campo || props.ordenActual === `-${props.campo}`;
}
function direccion() {
  return props.ordenActual === `-${props.campo}` ? 'desc' : 'asc';
}
function toggle() {
  if (props.ordenActual === props.campo) emit('ordenar', `-${props.campo}`);
  else emit('ordenar', props.campo);
}
</script>

<template>
  <button type="button" class="col-ordenable" :class="{ activa: activo() }" @click="toggle">
    <slot />
    <span class="flecha" :class="{ visible: activo() }">{{ activo() && direccion() === 'desc' ? '▾' : '▴' }}</span>
  </button>
</template>

<style scoped>
.col-ordenable {
  display: inline-flex; align-items: center; gap: 0.25rem;
  font: inherit; font-weight: inherit; color: inherit;
  cursor: pointer; padding: 0; background: none; border: none;
}
.col-ordenable:hover { color: var(--accent-strong); }
.col-ordenable.activa { color: var(--accent-strong); }
.flecha { font-size: var(--t-micro); opacity: 0; transition: opacity 0.12s; }
.flecha.visible { opacity: 1; }
</style>
