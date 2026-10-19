<script setup>
/* Respaldo por dataset, como la pantalla de ACVet (captura 8): una tarjeta por
   conjunto de datos, cada una se baja en CSV por separado y con su avance.

   Se pide PÁGINA POR PÁGINA y el archivo se arma acá: el hosting compartido
   corta un request largo, y 15.410 ventas de una vez no caben en ese tiempo.
   Los CSV llevan BOM para que Excel lea bien las tildes. */
import { ref, onMounted } from 'vue';
import { api, apiError } from '../api.js';

const props = defineProps({ habilitado: { type: Boolean, default: false } });

const datasets = ref([]);
const estado = ref({});   // clave → { fase: 'bajando'|'listo'|'error', pagina, paginas, msg }
const error = ref('');

onMounted(async () => {
  if (!props.habilitado) return;
  try {
    const { data } = await api.get('/exportacion/datasets');
    datasets.value = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar la lista de datos.');
  }
});

async function descargar(d) {
  estado.value[d.clave] = { fase: 'bajando', pagina: 0, paginas: d.paginas };
  const partes = ['﻿'];
  try {
    for (let p = 1; p <= d.paginas; p++) {
      estado.value[d.clave] = { fase: 'bajando', pagina: p, paginas: d.paginas };
      const { data } = await api.get(`/exportacion/dataset/${d.clave}`, { params: { pagina: p }, responseType: 'text' });
      partes.push(data);
    }
    const blob = new Blob(partes, { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `datos_${d.clave}_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
    estado.value[d.clave] = { fase: 'listo' };
  } catch (e) {
    estado.value[d.clave] = { fase: 'error', msg: apiError(e, 'Falló la descarga.') };
  }
}

function pct(s) {
  return s.paginas ? Math.round((s.pagina / s.paginas) * 100) : 0;
}
</script>

<template>
  <div class="respaldo">
    <strong>Exportar por tipo de dato (CSV)</strong>
    <p v-if="!habilitado" class="nota">Solo un administrador puede exportar.</p>
    <p v-if="error" class="error-text">{{ error }}</p>
    <div v-if="habilitado" class="grid">
      <div v-for="d in datasets" :key="d.clave" class="ds">
        <div class="ds-head">
          <strong>{{ d.titulo }}</strong>
          <span v-if="estado[d.clave]?.fase === 'listo'" class="ok">Exportado ✓</span>
        </div>
        <p class="nota">{{ d.detalle }}</p>
        <p class="nota">{{ d.total === null ? 'No disponible en esta base' : `${d.total.toLocaleString('es-CL')} registros` }}</p>
        <div v-if="estado[d.clave]?.fase === 'bajando'" class="avance" role="status">
          <span class="nota">Exportando {{ d.titulo }}… página {{ estado[d.clave].pagina }} de {{ estado[d.clave].paginas }}</span>
          <div class="barra"><div class="relleno" :style="{ width: pct(estado[d.clave]) + '%' }"></div></div>
        </div>
        <p v-if="estado[d.clave]?.fase === 'error'" class="error-text">{{ estado[d.clave].msg }}</p>
        <button type="button" class="btn btn-ghost btn-sm" :disabled="d.total === null || estado[d.clave]?.fase === 'bajando'"
          @click="descargar(d)">⬇ Descargar CSV</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.respaldo { display: flex; flex-direction: column; gap: 0.6rem; width: 100%; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.7rem; }
.ds { border: 1px solid var(--line); border-radius: var(--r-sm); padding: 0.7rem 0.8rem; display: flex; flex-direction: column; gap: 0.35rem; align-items: flex-start; background: var(--surface); }
.ds-head { display: flex; justify-content: space-between; width: 100%; gap: 0.5rem; }
.ok { color: var(--ok); font-size: var(--t-sm); font-weight: 600; }
.nota { color: var(--ink-soft); font-size: var(--t-sm); }
.avance { width: 100%; display: flex; flex-direction: column; gap: 0.3rem; }
.barra { height: 6px; background: var(--brand-tint); border-radius: 999px; overflow: hidden; }
.relleno { height: 100%; background: var(--brand); transition: width 0.2s; }
</style>
