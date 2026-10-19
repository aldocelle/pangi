<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
  // { key, label, placeholder?, type?: 'text'|'select'|'date', options?: [{value,label}],
  //   autocompletar?: bool (default true) — false = no busca mientras se escribe,
  //   solo al salir del campo (usado en RUT/teléfono/documento/lote, que no se
  //   completan de a poco como un nombre) }
  campos: { type: Array, required: true },
  modelValue: { type: Object, required: true },
  /* Cuántos campos quedan siempre a la vista (contando el principal). El
     resto se pliega bajo «Más filtros». 0 = todos a la vista, que es como lo
     usan las vistas que no lo piden. Ventas lo usa porque nueve campos
     ocupaban dos filas y cortaban los textos de ayuda. */
  principales: { type: Number, default: 0 },
});
const emit = defineEmits(['update:modelValue', 'buscar']);

const MIN_CHARS = 2;

const activos = () => Object.values(props.modelValue).filter((v) => v !== '' && v != null).length;

function set(key, value) {
  emit('update:modelValue', { ...props.modelValue, [key]: value });
}

// Autocompletar (mientras se escribe): solo dispara si el campo llegó al
// mínimo de caracteres, o si se vació del todo (para volver a traer el listado
// completo apenas se borra el filtro).
function setAutocompletar(campo, value) {
  set(campo.key, value);
  const len = value.trim().length;
  if (len === 0 || len >= MIN_CHARS) emit('buscar');
}

// Campos sin autocompletar (RUT, teléfono, etc.): solo buscan al confirmar
// (blur / Enter / change), sin importar la longitud.
function setYBuscar(key, value) {
  set(key, value);
  emit('buscar');
}

const plegables = computed(() => (props.principales ? props.campos.slice(props.principales) : []));
const nPlegadosActivos = computed(
  () => plegables.value.filter((c) => props.modelValue[c.key] !== '' && props.modelValue[c.key] != null).length,
);
const abierto = ref(nPlegadosActivos.value > 0);
// Si un filtro plegado se enciende desde afuera (ej. «Ver borradores» pone el
// estado), se abre: un filtro que actúa sin verse deja el listado recortado
// sin ninguna señal de por qué.
watch(nPlegadosActivos, (n, antes) => { if (n > antes) abierto.value = true; });

const visibles = computed(() => {
  if (!props.principales || abierto.value) return props.campos.slice(1);
  return props.campos.slice(1, props.principales);
});

function limpiar() {
  const vacio = Object.fromEntries(props.campos.map((c) => [c.key, '']));
  emit('update:modelValue', vacio);
  emit('buscar');
}
</script>

<template>
  <div class="buscador-avanzado">
    <input
      v-if="campos[0]"
      class="input principal"
      :value="modelValue[campos[0].key]"
      @input="setAutocompletar(campos[0], $event.target.value)"
      :placeholder="campos[0].placeholder || campos[0].label"
      type="search"
      :title="campos[0].placeholder || campos[0].label"
    />

    <div v-for="c in visibles" :key="c.key" class="campo">
      <label :for="`fa-${c.key}`" class="sr-only">{{ c.label }}</label>
      <select
        v-if="c.type === 'select'"
        :id="`fa-${c.key}`" class="select"
        :value="modelValue[c.key]"
        @change="setYBuscar(c.key, $event.target.value)"
      >
        <option value="">{{ c.label }}: todos</option>
        <option v-for="o in c.options" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <input
        v-else-if="c.autocompletar === false"
        :id="`fa-${c.key}`" class="input"
        :type="c.type || 'text'"
        :value="modelValue[c.key]"
        @input="set(c.key, $event.target.value)"
        @change="emit('buscar')"
        @keyup.enter="emit('buscar')"
        :placeholder="c.placeholder || c.label"
        :title="c.placeholder || c.label"
      />
      <input
        v-else
        :id="`fa-${c.key}`" class="input"
        :type="c.type || 'text'"
        :value="modelValue[c.key]"
        @input="setAutocompletar(c, $event.target.value)"
        :placeholder="c.placeholder || c.label"
        :title="c.placeholder || c.label"
      />
    </div>

    <button
      v-if="plegables.length" type="button" class="btn btn-ghost btn-sm"
      :aria-expanded="abierto" @click="abierto = !abierto"
    >
      {{ abierto ? 'Menos filtros' : 'Más filtros' }}<template v-if="!abierto && nPlegadosActivos"> · {{ nPlegadosActivos }}</template>
    </button>
    <button v-if="activos()" type="button" class="btn btn-ghost btn-sm" @click="limpiar">Limpiar</button>
  </div>
</template>

<style scoped>
.buscador-avanzado { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 1.2rem; }
.principal { flex: 1 1 260px; min-width: 220px; max-width: 380px; }
.campo { flex: 1 1 200px; min-width: 200px; max-width: 260px; }
.campo .input, .campo .select { width: 100%; text-overflow: ellipsis; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
</style>
