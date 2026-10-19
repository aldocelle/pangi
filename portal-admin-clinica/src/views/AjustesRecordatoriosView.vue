<script setup>
/* Ajustes → Recordatorios: lo que en ACVet es «Recordatorios predefinidos»
   + «Configuración del recordatorio» + «Ejemplo de recordatorio».

   La vista previa la arma el BACKEND con el mismo código que envía el correo
   (`MensajeRecordatorio::correo`): si el panel armara su propia versión, las
   dos se separarían en la primera edición y la vista previa mentiría. */
import { ref, computed, onMounted } from 'vue';
import { api, apiError } from '../api.js';
import { useAuthStore } from '../stores/auth.js';

const auth = useAuthStore();
const puedeEditar = computed(() => auth.puede('recordatorios'));

const plantillas = ref([]);
const variables = ref({});
const config = ref({ dias_anticipacion: 0, firma: '' });
const loading = ref(true);
const error = ref('');
const aviso = ref('');

const editando = ref(null);
const busy = ref(false);
const mensajeRef = ref(null);

const previa = ref(null);
const previaDe = ref('ejemplo');   // qué se está previsualizando

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const [p, v, c] = await Promise.all([
      api.get('/recordatorios/plantillas'),
      api.get('/recordatorios/variables'),
      api.get('/configuracion/recordatorios'),
    ]);
    plantillas.value = p.data;
    variables.value = v.data;
    config.value = c.data;
    await verPrevia();
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar los recordatorios.');
  } finally {
    loading.value = false;
  }
}

async function verPrevia(p = null) {
  previaDe.value = p ? p.titulo : 'ejemplo';
  try {
    const { data } = await api.post('/recordatorios/vista-previa', {
      titulo: p?.titulo || 'Vacuna antirrábica',
      mensaje: p?.mensaje || 'Le recordamos que @mascota_nombre tiene programado su control de vacunación para el @fecha.',
      firma: config.value.firma,
    });
    previa.value = data;
  } catch { previa.value = null; }
}

async function guardarConfig() {
  busy.value = true;
  error.value = '';
  aviso.value = '';
  try {
    const { data } = await api.put('/configuracion/recordatorios', {
      dias_anticipacion: Number(config.value.dias_anticipacion) || 0,
      firma: config.value.firma || '',
    });
    config.value = data;
    aviso.value = 'Guardado.';
    await verPrevia();
  } catch (e) {
    error.value = apiError(e, 'No se pudo guardar.');
  } finally {
    busy.value = false;
  }
}

function nueva() {
  editando.value = { titulo: '', mensaje: '', dias_repeticion: 365 };
}
function editar(p) {
  editando.value = { ...p };
}

/* Clic en una variable: se inserta donde está el cursor, como en ACVet. */
function insertar(v) {
  const el = mensajeRef.value;
  const t = editando.value.mensaje || '';
  const ini = el ? el.selectionStart : t.length;
  const fin = el ? el.selectionEnd : t.length;
  editando.value.mensaje = t.slice(0, ini) + v + t.slice(fin);
  requestAnimationFrame(() => {
    if (!el) return;
    el.focus();
    el.selectionStart = el.selectionEnd = ini + v.length;
  });
}

async function guardarPlantilla() {
  busy.value = true;
  error.value = '';
  try {
    const cuerpo = {
      titulo: editando.value.titulo.trim(),
      mensaje: (editando.value.mensaje || '').trim() || null,
      dias_repeticion: editando.value.dias_repeticion ? Number(editando.value.dias_repeticion) : null,
    };
    if (editando.value.id) await api.patch(`/recordatorios/plantillas/${editando.value.id}`, cuerpo);
    else await api.post('/recordatorios/plantillas', cuerpo);
    editando.value = null;
    const { data } = await api.get('/recordatorios/plantillas');
    plantillas.value = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudo guardar la plantilla.');
  } finally {
    busy.value = false;
  }
}

async function borrar(p) {
  if (!confirm(`¿Eliminar la plantilla «${p.titulo}»? Los recordatorios ya creados con ella no cambian.`)) return;
  try {
    await api.delete(`/recordatorios/plantillas/${p.id}`);
    plantillas.value = plantillas.value.filter((x) => x.id !== p.id);
  } catch (e) {
    error.value = apiError(e, 'No se pudo eliminar.');
  }
}

/* El ejemplo de ACVet en palabras: con 3 días, un control del viernes se avisa el martes. */
const ejemploAnticipacion = computed(() => {
  const n = Number(config.value.dias_anticipacion) || 0;
  if (!n) return 'El correo sale el mismo día en que vence el recordatorio.';
  return `Un recordatorio programado para el viernes sale ${n} día${n === 1 ? '' : 's'} antes.`;
});

onMounted(cargar);
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Recordatorios</h1>
        <p class="page-sub">Plantillas de aviso, con qué anticipación sale el correo y cómo se firma.</p>
      </div>
      <button v-if="puedeEditar" class="btn btn-primary" @click="nueva">+ Nueva plantilla</button>
    </div>

    <p class="aviso-pruebas">
      Modo pruebas: ningún recordatorio se envía mientras los envíos automáticos estén apagados.
      Lo que se configura acá rige el día que se enciendan.
    </p>

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>

    <div v-else class="rec-grid">
      <section class="card bloque">
        <h2 class="bloque-tit">Recordatorios predefinidos</h2>
        <p v-if="!plantillas.length" class="empty">Todavía no hay plantillas.</p>
        <table v-else class="tabla-datos">
          <thead>
            <tr><th>Título</th><th>Mensaje</th><th>Repite</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="p in plantillas" :key="p.id">
              <td><strong>{{ p.titulo }}</strong></td>
              <td class="msg">{{ p.mensaje || '—' }}</td>
              <td class="nowrap">{{ p.dias_repeticion ? `${p.dias_repeticion} días` : 'no' }}</td>
              <td class="acciones">
                <button class="btn btn-ghost btn-sm" @click="verPrevia(p)">Ver</button>
                <template v-if="puedeEditar">
                  <button class="btn btn-ghost btn-sm" @click="editar(p)">Editar</button>
                  <button class="btn btn-danger btn-sm" @click="borrar(p)">Eliminar</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="card bloque">
        <h2 class="bloque-tit">Configuración del recordatorio</h2>
        <form class="form" @submit.prevent="guardarConfig">
          <div class="field">
            <label for="r-antic">Días de anticipación al envío</label>
            <input id="r-antic" class="input corto" type="number" min="0" max="60"
              v-model.number="config.dias_anticipacion" :disabled="!puedeEditar" />
            <small class="ayuda">{{ ejemploAnticipacion }}</small>
          </div>
          <div class="field">
            <label for="r-firma">Firma del correo</label>
            <textarea id="r-firma" class="textarea" maxlength="300" v-model="config.firma" :disabled="!puedeEditar"
              placeholder="Ej: Para mayor información contacte a su médico veterinario"></textarea>
            <small class="ayuda">Aparece al final de cada correo de recordatorio.</small>
          </div>
          <div v-if="puedeEditar" class="acciones-form">
            <span v-if="aviso" class="ok-text">{{ aviso }}</span>
            <button class="btn btn-primary" :disabled="busy">Guardar</button>
          </div>
        </form>
      </section>

      <section v-if="previa" class="card bloque previa">
        <h2 class="bloque-tit">Ejemplo de recordatorio <small class="muted">· {{ previaDe }}</small></h2>
        <p class="previa-asunto"><span class="muted">Asunto:</span> {{ previa.asunto }}</p>
        <pre class="previa-cuerpo">{{ previa.cuerpo }}</pre>
      </section>
    </div>

    <div v-if="editando" class="modal-backdrop" @click.self="editando = null">
      <div class="modal modal-ancho" role="dialog" aria-modal="true" aria-label="Plantilla de recordatorio">
        <div class="modal-head">
          <h3>{{ editando.id ? 'Editar plantilla' : 'Nueva plantilla de recordatorio' }}</h3>
          <button class="modal-close" aria-label="Cerrar" @click="editando = null">✕</button>
        </div>
        <form class="form" @submit.prevent="guardarPlantilla">
          <div class="field">
            <label for="pl-tit">Título *</label>
            <input id="pl-tit" class="input" v-model="editando.titulo" required maxlength="120"
              placeholder="Ej: Vacuna antirrábica, Desparasitación, Control anual" />
            <small class="ayuda">Aparece en la lista al crear un recordatorio.</small>
          </div>
          <div class="field">
            <label for="pl-msg">Mensaje</label>
            <textarea id="pl-msg" ref="mensajeRef" class="textarea alto" v-model="editando.mensaje" maxlength="2000"
              placeholder="Ej: Le recordamos que @mascota_nombre tiene programado su control de vacunación para el @fecha."></textarea>
          </div>
          <div class="variables">
            <p class="ayuda">Variables disponibles — clic para insertar:</p>
            <div v-for="(lista, grupo) in variables" :key="grupo" class="var-grupo">
              <span class="var-tit">{{ grupo }}</span>
              <button v-for="v in lista" :key="v" type="button" class="var-chip" @click="insertar(v)">{{ v }}</button>
            </div>
          </div>
          <div class="field">
            <label for="pl-rep">Tiempo de repetición (días)</label>
            <input id="pl-rep" class="input corto" type="number" min="1" max="3650" v-model.number="editando.dias_repeticion" />
            <small class="ayuda">Días hasta el próximo control (30 para mensual, 365 para anual). Vacío = no se repite.
              Al marcar «Ya vino», nace el siguiente recordatorio.</small>
          </div>
          <p v-if="error" class="error-text">{{ error }}</p>
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" @click="editando = null">Cancelar</button>
            <button class="btn btn-primary" :disabled="busy">{{ busy ? 'Guardando…' : 'Guardar' }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.aviso-pruebas {
  background: var(--warn-tint); color: var(--warn-ink); border-radius: var(--r-sm);
  padding: 0.6rem 0.8rem; font-size: var(--t-sm); margin-bottom: 1rem;
}
.rec-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
.bloque { padding: 1rem 1.2rem; }
.bloque-tit { font-size: var(--t-lg); margin-bottom: 0.7rem; }
.msg { color: var(--ink-soft); font-size: var(--t-sm); max-width: 28rem; }
.nowrap { white-space: nowrap; }
.acciones { white-space: nowrap; text-align: right; }
.acciones .btn + .btn { margin-left: 0.3rem; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.corto { max-width: 8rem; }
.alto { min-height: 110px; }
.ayuda { color: var(--ink-soft); font-size: var(--t-sm); }
.muted { color: var(--ink-soft); }
.acciones-form { display: flex; justify-content: flex-end; align-items: center; gap: 0.8rem; }
.ok-text { color: var(--ok); font-size: var(--t-sm); }
.previa-asunto { font-size: var(--t-base); margin-bottom: 0.5rem; }
.previa-cuerpo {
  white-space: pre-wrap; font-family: var(--font); font-size: var(--t-base); line-height: 1.5;
  background: var(--bg); border: 1px solid var(--line); border-radius: var(--r-sm); padding: 0.8rem 1rem;
}
.variables { display: flex; flex-direction: column; gap: 0.4rem; }
.var-grupo { display: flex; flex-wrap: wrap; align-items: center; gap: 0.35rem; }
.var-tit { font-size: var(--t-micro); font-weight: 700; text-transform: uppercase; color: var(--ink-soft); min-width: 6.5rem; }
.var-chip {
  font-family: var(--font); font-size: var(--t-sm); border: 1px solid var(--line-strong); background: var(--surface);
  color: var(--brand-ink); border-radius: 999px; padding: 0.15rem 0.6rem; cursor: pointer;
}
.var-chip:hover { background: var(--accent-hover); }
.modal-ancho { width: min(640px, 100%); }
</style>
