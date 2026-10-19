<script setup>
import { ref, onMounted } from 'vue';
import { api, apiError, API_URL } from '../api.js';

const props = defineProps({
  mascota: { type: Object, required: true },
});
const emit = defineEmits(['close']);

const ficha = ref(null);
const loading = ref(true);
const error = ref('');

function fmt(dt) {
  return new Date(dt).toLocaleDateString('es-CL', { day: 'numeric', month: 'long', year: 'numeric' });
}

const TIPO_LABEL = { examen: 'Examen', rx: 'RX', receta: 'Receta', otro: 'Otro' };
/* El endpoint de descarga exige JWT (`Depends(get_current_user)` en
   `fichas.py`), y un <a href> plano NO manda el header Authorization: el
   navegador navegaba a la URL desnuda y el backend contestaba
   401 {"detail":"Not authenticated"} — que es lo que veía el dueño al abrir un
   examen desde la ficha de su mascota.

   El panel ya tenía este mismo arreglo desde antes; el portal se quedó sin él.
   Es exactamente la trampa del §9.5 del CLAUDE.md: los tres frontends están
   duplicados a propósito y un arreglo hay que replicarlo a mano en cada uno.

   Se pide como blob por axios —que sí adjunta el token— y se abre ese blob. */
const abriendoDoc = ref(null);
const errorDoc = ref('');

async function abrirDocumento(d) {
  errorDoc.value = '';
  abriendoDoc.value = d.id;
  try {
    const { data } = await api.get(`/fichas/documentos/${d.id}/archivo`, { responseType: 'blob' });
    const blobUrl = URL.createObjectURL(data);
    window.open(blobUrl, '_blank', 'noopener');
    setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000);
  } catch (e) {
    errorDoc.value = apiError(e, 'No se pudo abrir el documento.');
  } finally {
    abriendoDoc.value = null;
  }
}

onMounted(async () => {
  try {
    const { data } = await api.get(`/fichas/mascota/${props.mascota.id}`);
    ficha.value = data;
  } catch (e) {
    error.value = apiError(e, 'No pudimos cargar la ficha.');
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal" role="dialog" aria-modal="true" :aria-label="`Historial de ${mascota.nombre}`">
      <div class="modal-head">
        <h3>Historial de {{ mascota.nombre }}</h3>
        <button class="modal-close" aria-label="Cerrar" @click="emit('close')">✕</button>
      </div>

      <p v-if="loading" class="empty">Cargando ficha…</p>
      <p v-else-if="error" class="error-text">{{ error }}</p>

      <template v-else>
        <div class="ficha-datos">
          <div v-if="ficha.antecedentes"><h4>Antecedentes</h4><p>{{ ficha.antecedentes }}</p></div>
          <div v-if="ficha.vacunas"><h4>Vacunas</h4><p>{{ ficha.vacunas }}</p></div>
          <div v-if="ficha.condiciones_cronicas"><h4>Condiciones crónicas</h4><p>{{ ficha.condiciones_cronicas }}</p></div>
        </div>

        <h4 class="notas-title">Atenciones registradas</h4>
        <p v-if="!ficha.notas.length" class="empty">Aún no hay atenciones registradas.</p>
        <ol v-else class="notas-list">
          <li v-for="n in ficha.notas" :key="n.id" class="nota">
            <span class="nota-fecha">{{ fmt(n.created_at) }}</span>
            <strong v-if="n.motivo">{{ n.motivo }}</strong>
            <p v-if="n.diagnostico"><span class="k">Diagnóstico:</span> {{ n.diagnostico }}</p>
            <p v-if="n.tratamiento"><span class="k">Tratamiento:</span> {{ n.tratamiento }}</p>
            <p v-if="n.indicaciones"><span class="k">Indicaciones:</span> {{ n.indicaciones }}</p>
          </li>
        </ol>

        <h4 class="notas-title">Exámenes y documentos</h4>
        <p v-if="!ficha.documentos?.length" class="empty">Aún no hay documentos adjuntos.</p>
        <ul v-else class="doc-list">
          <li v-for="d in ficha.documentos" :key="d.id" class="doc-item">
            <button type="button" class="doc-nombre doc-boton" :disabled="abriendoDoc === d.id" @click="abrirDocumento(d)">
              {{ d.descripcion || d.nombre_original }}
                        </button>
            <span class="doc-meta">{{ TIPO_LABEL[d.tipo] || 'Otro' }} · {{ fmt(d.created_at) }}</span>
          </li>
        </ul>
          <p v-if="errorDoc" class="doc-error">{{ errorDoc }}</p>
      </template>
    </div>
  </div>
</template>

<style scoped>
.ficha-datos { display: flex; flex-direction: column; gap: 0.8rem; margin-bottom: 1.2rem; }
.ficha-datos h4 { font-size: var(--t-sm); color: var(--ink-soft); }
.notas-title { margin-bottom: 0.7rem; }
.notas-list { list-style: none; padding: 0; display: flex; flex-direction: column; gap: 0.8rem; }
.nota { background: var(--bg); border: 1px solid var(--line); border-radius: var(--r-md); padding: 1rem; }
.nota-fecha { display: block; font-size: var(--t-micro); color: var(--ink-soft); margin-bottom: 0.2rem; }
.nota p { font-size: var(--t-base); margin-top: 0.3rem; }
.k { font-weight: 600; }

.doc-list { list-style: none; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
.doc-item { display: flex; flex-direction: column; gap: 0.15rem; border: 1px solid var(--line); border-radius: var(--r-md); padding: 0.7rem 0.9rem; }
.doc-nombre { font-weight: 600; color: var(--brand-ink); }
.doc-nombre:hover { text-decoration: underline; }
/* Era un <a>; ahora es un <button> porque la descarga necesita el token y hay
   que pedirla por axios. Se le quita todo lo que un botón trae de fábrica para
   que siga leyéndose como el enlace que era. */
.doc-boton { background: none; border: 0; padding: 0; font: inherit; text-align: left; cursor: pointer; }
.doc-boton:disabled { opacity: 0.55; cursor: progress; }
.doc-error { color: var(--danger); font-size: var(--t-sm); margin-top: 0.6rem; }
.doc-meta { font-size: var(--t-micro); color: var(--ink-soft); }
</style>
