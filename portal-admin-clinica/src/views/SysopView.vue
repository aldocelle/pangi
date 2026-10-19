<script setup>
/* Sysop: lo que no es para los admins de la clínica. Entra solo quien figura en
   SYSOP_EMAILS del backend (`es_sysop` en /auth/me); el router y el sidebar lo
   esconden al resto, y el backend responde 403 igual (`auth:sysop`).

   Primera tanda (7-oct-2026): el importador de ACVet y desconectar Google,
   sacados de Ajustes porque escriben datos de toda la clínica o cortan Calendar
   para todos los especialistas, y la clínica casi nunca los necesita.

   Segunda tanda: Tareas programadas. Cuándo corrió cada cron, cuánto tardó y
   cómo terminó (§13.17: los crons corrieron dos semanas contra el lab y nada
   lo mostró). El estado se calcula por AUSENCIA de corridas, así que una tarea
   sin cron en este servidor sale como «sin corridas», no como si nada. */
import { ref, onMounted } from 'vue';
import ImportadorDatasets from '../components/ImportadorDatasets.vue';
import { api, apiError } from '../api.js';

const ESTADOS = {
  ok: { txt: 'Al día', cls: 'est-ok' },
  fallando: { txt: 'Fallando', cls: 'est-mal' },
  atrasada: { txt: 'Atrasada', cls: 'est-mal' },
  sin_corridas: { txt: 'Sin corridas', cls: 'est-aviso' },
  sin_tabla: { txt: 'Falta migración', cls: 'est-aviso' },
};

const tareas = ref(null);
const tareasError = ref('');
const ejecutando = ref('');
const resultadoEjecucion = ref(null);
const abierta = ref('');
const corridas = ref([]);

const google = ref(null);
const googleError = ref('');
const desconectando = ref(false);

onMounted(() => { cargarTareas(); cargarGoogle(); });

async function cargarTareas() {
  tareasError.value = '';
  try {
    const { data } = await api.get('/sysop/tareas');
    tareas.value = data.tareas;
  } catch (e) {
    tareasError.value = apiError(e, 'No se pudo leer el estado de las tareas.');
  }
}

async function verCorridas(id) {
  if (abierta.value === id) { abierta.value = ''; return; }
  abierta.value = id;
  corridas.value = [];
  try {
    const { data } = await api.get(`/sysop/tareas/${id}/corridas`);
    corridas.value = data;
  } catch (e) {
    tareasError.value = apiError(e, 'No se pudieron leer las corridas.');
  }
}

async function ejecutar(t) {
  ejecutando.value = t.id;
  resultadoEjecucion.value = null;
  try {
    const { data } = await api.post(`/sysop/tareas/${t.id}/ejecutar`);
    resultadoEjecucion.value = { id: t.id, ...data };
    await cargarTareas();
    if (abierta.value === t.id) { abierta.value = ''; await verCorridas(t.id); }
  } catch (e) {
    tareasError.value = apiError(e, 'No se pudo ejecutar.');
  } finally {
    ejecutando.value = '';
  }
}

function hace(min) {
  if (min === null || min === undefined) return '—';
  if (min < 1) return 'recién';
  if (min < 60) return `hace ${min} min`;
  const h = Math.floor(min / 60);
  if (h < 48) return `hace ${h} h`;
  return `hace ${Math.floor(h / 24)} días`;
}

function frecuencia(cada) {
  if (cada === 1) return 'cada minuto';
  if (cada < 60) return `cada ${cada} min`;
  if (cada === 60) return 'cada hora';
  return 'diaria';
}

function fecha(iso) {
  return iso ? new Date(iso).toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'medium' }) : '—';
}

function duracion(ms) {
  return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`;
}

async function cargarGoogle() {
  googleError.value = '';
  try {
    const { data } = await api.get('/google/status');
    google.value = data;
  } catch (e) {
    googleError.value = apiError(e, 'No se pudo leer el estado de Google.');
  }
}

async function desconectarGoogle() {
  if (!confirm('¿Desconectar la cuenta de Google de la clínica? Se deja de crear y sincronizar eventos para TODOS los especialistas. Las citas ya creadas mantienen su evento hasta que se cancelen o reagenden.')) return;
  googleError.value = '';
  desconectando.value = true;
  try {
    await api.delete('/google/desconectar');
    await cargarGoogle();
  } catch (e) {
    googleError.value = apiError(e, 'No se pudo desconectar.');
  } finally {
    desconectando.value = false;
  }
}
</script>

<template>
  <div class="page">
    <h1 class="page-title">Sysop</h1>
    <p class="page-sub">
      Acciones de soporte técnico que afectan a toda la clínica. Todo lo que se
      hace acá queda en Auditoría.
    </p>

    <section class="card sys-card">
      <div class="sys-fila">
        <h2>Tareas programadas</h2>
        <button type="button" class="btn btn-ghost btn-sm" @click="cargarTareas">Actualizar</button>
      </div>
      <p class="sys-nota">
        Las corre el cron de hPanel en ESTE servidor. Una tarea que no anota
        corridas aquí no está corriendo contra esta base, aunque su cron exista.
      </p>
      <p v-if="!tareas && !tareasError" class="sys-nota">Cargando…</p>
      <div v-else-if="tareas" class="scroll-x">
        <table class="tabla-datos">
          <thead>
            <tr><th>Tarea</th><th>Estado</th><th>Última corrida</th><th>Últimas 24 h</th><th></th></tr>
          </thead>
          <tbody>
            <template v-for="t in tareas" :key="t.id">
              <tr>
                <td>
                  <strong>{{ t.nombre }}</strong>
                  <div class="sys-sub"><code>{{ t.id }}</code> · {{ frecuencia(t.cada_min) }}</div>
                </td>
                <td><span class="badge" :class="ESTADOS[t.estado]?.cls">{{ ESTADOS[t.estado]?.txt || t.estado }}</span></td>
                <td>
                  <template v-if="t.ultima">
                    {{ hace(t.minutos_desde_ultima) }}
                    <div class="sys-sub">{{ t.ultima.resultado }} · {{ duracion(t.ultima.duracion_ms) }}<template v-if="t.ultima.origen === 'panel'"> · desde el panel</template></div>
                    <div v-if="t.ultima.resumen" class="sys-sub sys-resumen">{{ t.ultima.resumen }}</div>
                  </template>
                  <span v-else class="sys-sub">Nunca en 7 días</span>
                </td>
                <td>
                  {{ t.corridas_24h }}
                  <span v-if="t.fallos_24h" class="sys-fallos">· {{ t.fallos_24h }} con fallo</span>
                </td>
                <td class="sys-acciones">
                  <button type="button" class="btn btn-ghost btn-sm" :disabled="t.estado === 'sin_tabla'" @click="verCorridas(t.id)">
                    {{ abierta === t.id ? 'Ocultar' : 'Corridas' }}
                  </button>
                  <button v-if="t.panel" type="button" class="btn btn-primary btn-sm"
                          :disabled="!!ejecutando || t.estado === 'sin_tabla'" @click="ejecutar(t)">
                    {{ ejecutando === t.id ? 'Ejecutando…' : 'Ejecutar ahora' }}
                  </button>
                </td>
              </tr>
              <tr v-if="resultadoEjecucion?.id === t.id">
                <td colspan="5" :class="resultadoEjecucion.ok ? 'sys-ok' : 'error-text'">
                  {{ resultadoEjecucion.ok ? 'Terminó bien' : 'Terminó con fallo' }} en {{ duracion(resultadoEjecucion.duracion_ms) }}.
                  <span v-if="resultadoEjecucion.salida" class="sys-resumen">{{ resultadoEjecucion.salida }}</span>
                </td>
              </tr>
              <tr v-if="abierta === t.id">
                <td colspan="5">
                  <p v-if="!corridas.length" class="sys-sub">Sin corridas registradas.</p>
                  <ul v-else class="sys-corridas">
                    <li v-for="(c, i) in corridas" :key="i">
                      <span class="badge" :class="c.resultado === 'fallo' ? 'est-mal' : c.resultado === 'saltada' ? 'est-aviso' : 'est-ok'">{{ c.resultado }}</span>
                      {{ fecha(c.inicio) }} · {{ duracion(c.duracion_ms) }}<template v-if="c.origen === 'panel'"> · panel</template>
                      <span v-if="c.resumen" class="sys-resumen">— {{ c.resumen }}</span>
                    </li>
                  </ul>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      <p v-if="tareasError" class="error-text">{{ tareasError }}</p>
    </section>

    <section class="card sys-card">
      <h2>Google Calendar de la clínica</h2>
      <p v-if="!google && !googleError" class="sys-nota">Cargando…</p>
      <template v-else-if="google">
        <p v-if="!google.configurado" class="sys-nota">{{ google.instrucciones }}</p>
        <div v-else-if="google.conectado" class="sys-fila">
          <span>Conectada: <strong>{{ google.cuenta?.email }}</strong></span>
          <button type="button" class="btn btn-danger btn-sm" :disabled="desconectando" @click="desconectarGoogle">
            {{ desconectando ? 'Desconectando…' : 'Desconectar' }}
          </button>
        </div>
        <p v-else class="sys-nota">
          Sin cuenta conectada. Se conecta desde Ajustes → Integraciones.
        </p>
      </template>
      <p v-if="googleError" class="error-text">{{ googleError }}</p>
    </section>

    <section class="card sys-card">
      <h2>Importar datos de ACVet</h2>
      <p class="sys-nota">
        Escribe fichas, ventas y datos personales de los tutores. Úsalo solo en
        una migración o una restauración, y siempre con un respaldo reciente.
      </p>
      <ImportadorDatasets :habilitado="true" />
    </section>
  </div>
</template>

<style scoped>
.sys-card { margin-bottom: 1.2rem; }
.sys-card h2 { font-size: var(--t-lg); margin-bottom: 0.6rem; }
.sys-nota { color: var(--ink-soft); font-size: var(--t-sm); margin-bottom: 0.8rem; }
.sys-fila { display: flex; flex-wrap: wrap; align-items: center; gap: 0.8rem; justify-content: space-between; }
.sys-sub { color: var(--ink-soft); font-size: var(--t-sm); }
.sys-resumen { color: var(--ink-soft); font-size: var(--t-sm); word-break: break-word; }
.sys-fallos { color: var(--danger); font-size: var(--t-sm); font-weight: 600; }
.sys-ok { color: var(--ok); }
.sys-acciones { white-space: nowrap; text-align: right; }
.sys-acciones .btn + .btn { margin-left: 0.4rem; }
.sys-corridas { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.35rem; font-size: var(--t-sm); }
.badge.est-ok { background: var(--ok-tint); color: var(--ok); }
.badge.est-mal { background: var(--danger-tint); color: var(--danger); }
.badge.est-aviso { background: var(--warn-tint); color: var(--warn-ink); }
</style>
