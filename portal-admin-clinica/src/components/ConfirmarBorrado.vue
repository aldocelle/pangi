<script setup>
import { ref, computed, watch, nextTick } from 'vue';

const props = defineProps({
  // Nombre exacto que el usuario debe escribir para habilitar el borrado.
  nombre: { type: String, required: true },
  titulo: { type: String, default: 'Eliminar definitivamente' },
  // Qué se destruye: { mascotas, citas, fichas, notas }
  impacto: { type: Object, default: null },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' },
});
const emit = defineEmits(['confirmar', 'cancelar']);

const texto = ref('');
const input = ref(null);

const coincide = computed(() => texto.value.trim() === (props.nombre || '').trim());

const lineas = computed(() => {
  const i = props.impacto;
  if (!i) return [];
  return [
    ['paciente', 'pacientes', i.mascotas],
    ['ficha clínica', 'fichas clínicas', i.fichas],
    ['nota clínica', 'notas clínicas', i.notas],
    ['cita', 'citas', i.citas],
  ]
    .filter(([, , n]) => n > 0)
    .map(([sing, plur, n]) => `${n} ${n === 1 ? sing : plur}`);
});

watch(
  () => props.nombre,
  async () => {
    texto.value = '';
    await nextTick();
    input.value?.focus();
  },
  { immediate: true }
);
</script>

<template>
  <div class="overlay" @click.self="emit('cancelar')">
    <div class="modal" role="dialog" aria-modal="true">
      <h2 class="titulo">{{ titulo }}</h2>

      <p class="aviso">
        Esta acción <strong>no se puede deshacer</strong> y no queda copia de respaldo.
      </p>

      <div v-if="lineas.length" class="impacto">
        <p class="impacto-head">Se eliminará de forma permanente:</p>
        <ul>
          <li v-for="l in lineas" :key="l">{{ l }}</li>
        </ul>
      </div>
      <p v-else-if="impacto" class="impacto-vacio">No hay registros asociados.</p>

      <label class="lbl" :for="'conf-' + nombre">
        Escribe <strong>{{ nombre }}</strong> para confirmar:
      </label>
      <input
        :id="'conf-' + nombre"
        ref="input"
        class="input"
        v-model="texto"
        autocomplete="off"
        :placeholder="nombre"
        @keyup.enter="coincide && !busy && emit('confirmar', texto)"
      />

      <p v-if="error" class="error-text">{{ error }}</p>

      <div class="acciones">
        <button type="button" class="btn btn-ghost" :disabled="busy" @click="emit('cancelar')">
          Cancelar
        </button>
        <button
          type="button"
          class="btn btn-peligro"
          :disabled="!coincide || busy"
          @click="emit('confirmar', texto)"
        >
          {{ busy ? 'Eliminando…' : 'Eliminar definitivamente' }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed; inset: 0; z-index: 60;
  background: rgba(0, 0, 0, 0.45);
  display: flex; align-items: center; justify-content: center; padding: 1rem;
}
.modal {
  background: #fff; border-radius: var(--r-lg);
  padding: 1.4rem; width: 100%; max-width: 420px;
  display: flex; flex-direction: column; gap: 0.8rem;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
}
.titulo { font-size: var(--t-xl); margin: 0; }
.aviso { font-size: var(--t-base); color: var(--ink-soft); margin: 0; }

.impacto {
  background: #fef2f2; border: 1px solid #fecaca;
  border-radius: var(--r-md); padding: 0.7rem 0.9rem;
}
.impacto-head { font-size: var(--t-sm); font-weight: 600; color: #991b1b; margin: 0 0 0.35rem; }
.impacto ul { margin: 0; padding-left: 1.1rem; font-size: var(--t-base); color: #991b1b; }
.impacto-vacio { font-size: var(--t-base); color: var(--ink-soft); margin: 0; }

.lbl { font-size: var(--t-sm); }
.acciones { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 0.2rem; }
.btn-peligro { background: #dc2626; color: #fff; border: 1px solid #dc2626; }
.btn-peligro:hover:not(:disabled) { background: #b91c1c; }
.btn-peligro:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
