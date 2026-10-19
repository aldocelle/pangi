<script setup>
/* La grilla de tarjetas del kiosco de fichaje (`/fichaje`, FichajeView.vue),
   extraída para que la use también la vista previa dentro del panel
   (AsistenciaView.vue → Ajustes → Asistencia). Es el mismo marcado y el mismo
   CSS: si el kiosco cambia de diseño, la vista previa cambia sola — no hay
   una segunda copia que se pueda desalinear.

   `interactivo=false` (la vista previa) no llama a `marcar-kiosco` ni a nada:
   solo emite `tocar`, y quien la usa decide qué hacer (en la vista previa,
   mostrar el aviso de que no es el kiosco real). */
defineProps({
  personas: { type: Array, required: true },
  marcando: { type: [String, null], default: null },
  interactivo: { type: Boolean, default: true },
});
const emit = defineEmits(['marcar', 'tocar']);

function iniciales(nombre) {
  const p = (nombre || '?').split(' ').filter(Boolean);
  return ((p[0]?.[0] || '') + (p[1]?.[0] || '')).toUpperCase();
}
</script>

<template>
  <div class="gf-grid">
    <button
      v-for="p in personas" :key="p.usuario_id"
      class="gf-persona" :class="{ dentro: p.estado === 'entrada', ocupado: marcando === p.usuario_id }"
      :disabled="interactivo && !!marcando"
      @click="interactivo ? emit('marcar', p) : emit('tocar', p)"
    >
      <span class="gf-avatar">{{ iniciales(p.nombre) }}</span>
      <span class="gf-nombre">{{ p.nombre }}</span>
      <span v-if="p.cargo" class="gf-cargo">{{ p.cargo }}</span>
      <span class="gf-estado">
        <span class="gf-punto"></span>
        {{ p.estado === 'entrada' ? 'Marcar salida' : 'Marcar entrada' }}
      </span>
    </button>
  </div>
</template>

<style scoped>
.gf-grid {
  display: grid; align-content: start;
  grid-template-columns: repeat(auto-fill, minmax(clamp(9rem, 22vw, 12rem), 1fr));
  gap: clamp(0.6rem, 2vw, 1rem);
}
.gf-persona {
  display: flex; flex-direction: column; align-items: center; gap: 0.4rem;
  padding: clamp(1rem, 3vw, 1.6rem) 0.8rem;
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--r-lg); box-shadow: var(--shadow);
  transition: transform 0.12s, box-shadow 0.12s, border-color 0.12s;
  min-height: 11rem; justify-content: center;
}
.gf-persona:active { transform: scale(0.97); }
.gf-persona.ocupado { opacity: 0.5; }
.gf-avatar {
  width: 3.4rem; height: 3.4rem; border-radius: 50%;
  display: grid; place-items: center;
  background: var(--accent-tint); color: var(--accent-strong);
  font-weight: 700; font-size: var(--t-xl);
}
.gf-persona.dentro .gf-avatar { background: var(--ok-tint); color: var(--ok); }
.gf-nombre { font-size: var(--t-lg); font-weight: 600; text-align: center; line-height: 1.2; }
.gf-cargo { font-size: var(--t-micro); color: var(--ink-soft); }
.gf-estado {
  display: inline-flex; align-items: center; gap: 0.4rem;
  margin-top: 0.3rem; font-size: var(--t-sm); font-weight: 600;
  color: var(--accent-strong);
}
.gf-persona.dentro .gf-estado { color: var(--ok); }
.gf-punto { width: 9px; height: 9px; border-radius: 50%; background: var(--line-strong); }
.gf-persona.dentro .gf-punto { background: var(--ok); }

@media (prefers-reduced-motion: reduce) {
  .gf-persona { transition: none; }
}
</style>
