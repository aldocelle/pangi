<script setup>
/* Envoltorio para consultar el historial de ventas de un producto o de un
   servicio sin salir de su listado. No tiene lógica propia: solo enmarca a
   HistorialLineasVenta, que es el que sabe leer /transacciones/lineas.

   Va en un modal y no dentro del formulario de edición porque son dos
   tareas distintas: corregir el precio de un ítem y revisar a quién se le
   vendió no se hacen a la vez, y meterlas en la misma pantalla alarga un
   formulario que ya es largo. */
import HistorialLineasVenta from './HistorialLineasVenta.vue';

defineProps({
  titulo: { type: String, required: true },
  subtitulo: { type: String, default: '' },
  params: { type: Object, required: true },
  vacio: { type: String, default: 'Todavía no se ha vendido.' },
});
const emit = defineEmits(['close']);
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal hvm" role="dialog" aria-modal="true" aria-label="Historial de ventas">
      <div class="modal-head">
        <div>
          <h3>{{ titulo }}</h3>
          <p v-if="subtitulo" class="hvm-sub">{{ subtitulo }}</p>
        </div>
        <button class="modal-close" aria-label="Cerrar" @click="emit('close')">✕</button>
      </div>
      <HistorialLineasVenta :params="params" :vacio="vacio" :limit="10" />
    </div>
  </div>
</template>

<style scoped>
.hvm { max-width: 720px; }
.hvm-sub { color: var(--ink-soft); font-size: var(--t-sm); margin-top: 0.15rem; }
</style>
