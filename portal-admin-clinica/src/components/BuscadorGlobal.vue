<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api.js';

const router = useRouter();
const q = ref('');
const resultados = ref(null);
const buscando = ref(false);
const abierto = ref(false);
let debounce = null;

const ETIQUETAS = {
  pacientes: 'Pacientes',
  tutores: 'Tutores',
  productos: 'Productos',
  prestaciones: 'Consultas',
  ventas: 'Ventas',
};

function onInput() {
  clearTimeout(debounce);
  if (q.value.trim().length < 2) {
    resultados.value = null;
    abierto.value = false;
    return;
  }
  debounce = setTimeout(async () => {
    buscando.value = true;
    abierto.value = true;
    try {
      const { data } = await api.get('/buscar', { params: { q: q.value.trim() } });
      resultados.value = data;
    } catch {
      resultados.value = null;
    } finally {
      buscando.value = false;
    }
  }, 300);
}

function hayResultados() {
  return resultados.value && Object.values(resultados.value).some((lista) => lista.length);
}

function ir(item) {
  abierto.value = false;
  q.value = '';
  resultados.value = null;
  router.push(item.ruta);
}

function cerrar() {
  // pequeño delay para que el click en un resultado no se pierda por el blur
  setTimeout(() => { abierto.value = false; }, 150);
}
</script>

<template>
  <div class="buscador-global">
    <input
      class="buscador-input"
      type="search"
      v-model="q"
      placeholder="Buscar pacientes, tutores, productos, ventas…"
      @input="onInput"
      @focus="q.trim().length >= 2 && (abierto = true)"
      @blur="cerrar"
    />
    <div v-if="abierto" class="buscador-resultados">
      <p v-if="buscando" class="buscador-vacio">Buscando…</p>
      <template v-else-if="hayResultados()">
        <div v-for="(lista, cat) in resultados" :key="cat">
          <div v-if="lista.length" class="buscador-grupo">
            <div class="buscador-grupo-titulo">{{ ETIQUETAS[cat] || cat }}</div>
            <button
              v-for="item in lista" :key="item.id"
              type="button" class="buscador-item"
              @mousedown.prevent="ir(item)"
            >
              <span>{{ item.titulo }}</span>
              <span v-if="item.subtitulo" class="muted">{{ item.subtitulo }}</span>
            </button>
          </div>
        </div>
      </template>
      <p v-else class="buscador-vacio">Sin resultados.</p>
    </div>
  </div>
</template>

<style scoped>
.buscador-global { position: relative; flex: 1; max-width: 420px; }
.buscador-input {
  width: 100%; padding: 0.5rem 0.9rem; border-radius: var(--r-full);
  border: 1.5px solid var(--line); font-size: var(--t-base); background: var(--bg);
}
.buscador-input:focus { outline: none; border-color: var(--accent); }
.buscador-resultados {
  position: absolute; top: calc(100% + 0.4rem); left: 0; right: 0;
  background: #fff; border-radius: var(--r-lg);
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.18);
  max-height: 60vh; overflow-y: auto; z-index: 60;
  padding: 0.5rem 0;
}
.buscador-vacio { margin: 0; padding: 0.8rem 1rem; color: var(--ink-soft); font-size: var(--t-sm); }
.buscador-grupo-titulo {
  padding: 0.4rem 1rem; font-size: var(--t-micro); font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.04em; color: var(--ink-soft);
}
.buscador-item {
  display: flex; justify-content: space-between; gap: 0.6rem; width: 100%;
  padding: 0.5rem 1rem; border: none; background: none; text-align: left;
  font-size: var(--t-base); cursor: pointer;
}
.buscador-item:hover { background: var(--accent-hover); }
.buscador-item .muted { color: var(--ink-soft); font-size: var(--t-micro); }
@media (max-width: 720px) { .buscador-global { display: none; } }
</style>
