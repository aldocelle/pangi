<script setup>
/* Medicina preventiva: a quién hay que llamar y para qué.

   La vista está construida como una LISTA DE LLAMADOS, no como un CRUD. Cada
   fila trae el teléfono y el correo del dueño a la vista, porque el trabajo
   que genera un recordatorio es tomar el teléfono — obligar a abrir la ficha
   del cliente en cada uno convierte una tarde de llamados en una tarde de
   clicks.

   Por defecto se muestran los PENDIENTES ordenados de más atrasado a más
   lejano. Es la cola de trabajo: arriba lo que ya debería estar hecho. */
import { ref, computed, onMounted } from 'vue';
import { api, apiError } from '../api.js';
import Paginador from '../components/Paginador.vue';
import NuevoRecordatorioModal from '../components/NuevoRecordatorioModal.vue';
import { CLINICA } from '../marca.js';

const creando = ref(false);
function creado() {
  creando.value = false;
  cargar();
  cargarResumen();
}

const items = ref([]);
const total = ref(0);
const pagina = ref(1);
const totalPaginas = ref(1);
// El backend topa el limit en 20 (LIMITE_MAX): se usa el que devuelve la
// respuesta, no el pedido, o el paginador diría "1-30 de N" mostrando 20.
const limite = ref(20);
const resumen = ref({ vencidos: 0, hoy: 0, proximos_30_dias: 0 });
const loading = ref(true);
const error = ref('');

const filtro = ref('pendiente');   // pendiente | vencidos | enviado | completado | todos
const q = ref('');

const FILTROS = [
  { id: 'pendiente', label: 'Pendientes' },
  { id: 'vencidos', label: 'Vencidos' },
  { id: 'enviado', label: 'Ya avisados' },
  { id: 'completado', label: 'Completados' },
  { id: 'todos', label: 'Todos' },
];

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const params = { page: pagina.value, limit: 30 };
    if (filtro.value === 'vencidos') params.solo_vencidos = true;
    else if (filtro.value !== 'todos') params.estado = filtro.value;
    if (q.value.trim()) params.q = q.value.trim();

    const { data } = await api.get('/recordatorios', { params });
    items.value = data.items;
    total.value = data.total;
    totalPaginas.value = data.total_paginas;
    limite.value = data.limit;
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar los recordatorios.');
  } finally {
    loading.value = false;
  }
}

async function cargarResumen() {
  try {
    const { data } = await api.get('/recordatorios/resumen');
    resumen.value = data;
  } catch { /* el resumen es informativo: si falla, la lista igual sirve */ }
}

function cambiarFiltro(id) {
  filtro.value = id;
  pagina.value = 1;   // sin esto, filtrar desde la página 3 muestra vacío
  cargar();
}

let timer;
function buscar() {
  clearTimeout(timer);
  timer = setTimeout(() => { pagina.value = 1; cargar(); }, 250);
}

function irPagina(p) {
  pagina.value = p;
  cargar();
}

/* Marcar el trabajo hecho. Se actualiza la fila en memoria en vez de recargar
   la lista entera: con el filtro "Pendientes" activo, recargar haría
   desaparecer la fila de golpe y quien está llamando pierde el hilo de dónde
   iba. Desaparece en la próxima recarga, cuando ya no estorba. */
const guardando = ref(null);

async function marcar(r, estado, canal = null) {
  guardando.value = r.id;
  try {
    const { data } = await api.patch(`/recordatorios/${r.id}`, { estado, ...(canal ? { canal } : {}) });
    Object.assign(r, data);
    cargarResumen();
  } catch (e) {
    error.value = apiError(e, 'No se pudo actualizar.');
  } finally {
    guardando.value = null;
  }
}

function fechaLegible(iso) {
  if (!iso) return '';
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('es-CL', { day: 'numeric', month: 'short', year: 'numeric' });
}

function cuando(r) {
  const d = r.dias_restantes;
  if (d === 0) return 'hoy';
  if (d < 0) return `hace ${Math.abs(d)} día${Math.abs(d) === 1 ? '' : 's'}`;
  return `en ${d} día${d === 1 ? '' : 's'}`;
}

const hayVencidos = computed(() => resumen.value.vencidos > 0);

function telHref(t) {
  return `tel:${(t || '').replace(/[^\d+]/g, '')}`;
}
function waHref(r) {
  const t = (r.dueno_telefono || '').replace(/[^\d]/g, '');
  // `mensaje_resuelto` trae las variables ya puestas (@mascota_nombre → Luna):
  // sin eso, al tutor le llegaba la arroba tal cual.
  const texto = encodeURIComponent(
    r.mensaje_resuelto?.trim() || r.mensaje?.trim()
      || `Hola, le escribimos de ${CLINICA} para recordarle que a ${r.mascota_nombre} le corresponde: ${r.titulo}.`
  );
  return `https://wa.me/${t}?text=${texto}`;
}

/* El botón abre WhatsApp con el mensaje escrito; quien lo manda es una persona.
   Antes se marcaba «avisado» al hacer CLIC, y si se cerraba WhatsApp sin
   enviar, el recordatorio quedaba como avisado igual. Ahora se marca recién
   cuando se confirma, al volver a esta pestaña. */
const waPendiente = ref(null);
function abrirWhatsapp(r) {
  waPendiente.value = r;
}
function confirmarWhatsapp(enviado) {
  const r = waPendiente.value;
  waPendiente.value = null;
  if (enviado && r) marcar(r, 'enviado', 'whatsapp');
}

onMounted(() => { cargar(); cargarResumen(); });
</script>

<template>
  <div class="vista">
    <header class="cabecera">
      <div>
        <h1>Recordatorios</h1>
        <p class="sub">Vacunas y controles que le tocan a cada paciente.</p>
      </div>
      <button class="btn btn-primary" @click="creando = true">+ Nuevo recordatorio</button>
    </header>

    <!-- Los tres números que deciden si hay que actuar hoy -->
    <div class="tarjetas">
      <button class="tarjeta" :class="{ alerta: hayVencidos }" @click="cambiarFiltro('vencidos')">
        <span class="num">{{ resumen.vencidos }}</span>
        <span class="etq">Vencidos</span>
      </button>
      <button class="tarjeta" @click="cambiarFiltro('pendiente')">
        <span class="num">{{ resumen.hoy }}</span>
        <span class="etq">Para hoy</span>
      </button>
      <button class="tarjeta" @click="cambiarFiltro('pendiente')">
        <span class="num">{{ resumen.proximos_30_dias }}</span>
        <span class="etq">Próximos 30 días</span>
      </button>
    </div>

    <div class="controles">
      <div class="filtros">
        <button
          v-for="f in FILTROS" :key="f.id"
          class="chip" :class="{ activo: filtro === f.id }"
          @click="cambiarFiltro(f.id)"
        >{{ f.label }}</button>
      </div>
      <input class="input buscador" v-model="q" placeholder="Buscar por mascota o vacuna…" @input="buscar" />
    </div>

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>
    <p v-else-if="!items.length" class="empty">No hay recordatorios que mostrar con este filtro.</p>

    <ul v-else class="lista">
      <li v-for="r in items" :key="r.id" class="fila" :class="{ vencida: r.vencido, fallecida: r.mascota_fallecida }">
        <div class="col-cuando">
          <strong :class="{ rojo: r.vencido }">{{ cuando(r) }}</strong>
          <small>{{ fechaLegible(r.fecha_programada) }}</small>
        </div>

        <div class="col-que">
          <strong>{{ r.titulo }}</strong>
          <small>
            {{ r.mascota_nombre }}<template v-if="r.dueno_nombre"> · {{ r.dueno_nombre }}</template>
          </small>
          <!-- Lo más importante de la vista: llamar al dueño de un animal
               muerto para vacunarlo es el peor error posible acá. -->
          <span v-if="r.mascota_fallecida" class="aviso-fallecida">
            ⚠ Esta mascota está registrada como fallecida — no llamar
          </span>
        </div>

        <div class="col-contacto">
          <a v-if="r.dueno_telefono" :href="telHref(r.dueno_telefono)" class="enlace">{{ r.dueno_telefono }}</a>
          <span v-else class="muted">sin teléfono</span>
          <a v-if="r.dueno_email" :href="`mailto:${r.dueno_email}`" class="enlace muted">{{ r.dueno_email }}</a>
        </div>

        <div class="col-acciones">
          <template v-if="r.estado === 'pendiente'">
            <a
              v-if="r.dueno_telefono && !r.mascota_fallecida"
              class="btn btn-sm btn-wa" :href="waHref(r)" target="_blank" rel="noopener"
              @click="abrirWhatsapp(r)"
            >WhatsApp</a>
            <button class="btn btn-sm btn-ghost" :disabled="guardando === r.id" @click="marcar(r, 'enviado', 'telefono')">
              Avisado
            </button>
          </template>
          <span v-else-if="r.estado === 'enviado'" class="badge badge-ok">Avisado</span>
          <span v-else-if="r.estado === 'completado'" class="badge badge-ok">Completado ✓</span>
          <span v-else class="badge">Cancelado</span>

          <button
            v-if="r.estado !== 'completado' && r.estado !== 'cancelado'"
            class="btn btn-sm btn-primary" :disabled="guardando === r.id"
            @click="marcar(r, 'completado')"
          >Ya vino</button>
          <button
            v-if="r.estado === 'pendiente'"
            class="btn btn-sm btn-ghost descartar" :disabled="guardando === r.id"
            title="Ya no aplica" @click="marcar(r, 'cancelado')"
          >✕</button>
        </div>
      </li>
    </ul>

    <NuevoRecordatorioModal v-if="creando" @close="creando = false" @creado="creado" />

    <div v-if="waPendiente" class="modal-backdrop">
      <div class="modal" role="dialog" aria-modal="true" aria-label="¿Se envió el WhatsApp?">
        <div class="modal-head"><h3>¿Enviaste el WhatsApp?</h3></div>
        <p>Se abrió WhatsApp con el mensaje para {{ waPendiente.dueno_nombre || 'el tutor' }} ({{ waPendiente.mascota_nombre }}).
          Márcalo como avisado solo si lo mandaste.</p>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="confirmarWhatsapp(false)">No lo envié</button>
          <button class="btn btn-primary" @click="confirmarWhatsapp(true)">Sí, quedó enviado</button>
        </div>
      </div>
    </div>

    <Paginador
      v-if="totalPaginas > 1"
      :page="pagina" :total-paginas="totalPaginas" :total="total" :limit="limite"
      @cambiar="irPagina"
    />
  </div>
</template>

<style scoped>
.vista { padding: 0 0 3rem; }
.cabecera { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.2rem; }
.cabecera h1 { font-size: var(--t-2xl); }
.sub { color: var(--ink-soft); font-size: var(--t-base); margin-top: 0.2rem; }

.tarjetas { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.8rem; margin-bottom: 1.2rem; max-width: 520px; }
.tarjeta {
  display: flex; flex-direction: column; align-items: flex-start; gap: 0.1rem;
  padding: 0.8rem 1rem; border: 1px solid var(--line); border-radius: var(--r-md);
  background: var(--surface); text-align: left;
}
.tarjeta:hover { border-color: var(--accent); background: var(--accent-tint); }
.tarjeta.alerta { border-color: var(--danger); background: var(--danger-tint); }
.tarjeta .num { font-size: var(--t-2xl); font-weight: 700; }
.tarjeta.alerta .num { color: var(--danger); }
.tarjeta .etq { font-size: var(--t-micro); color: var(--ink-soft); }

.controles { display: flex; flex-wrap: wrap; gap: 0.8rem; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
.filtros { display: flex; gap: 0.35rem; flex-wrap: wrap; }
.chip {
  font-size: var(--t-micro); font-weight: 600; padding: 0.4em 0.85em; border-radius: var(--r-full);
  border: 1px solid var(--line); color: var(--ink-soft); background: var(--surface);
}
.chip:hover { border-color: var(--accent); background: var(--accent-tint); }
.chip.activo { background: var(--brand); border-color: var(--brand); color: #fff; }
.buscador { max-width: 280px; }

.lista { list-style: none; display: flex; flex-direction: column; gap: 0.5rem; }
.fila {
  display: grid; grid-template-columns: 130px minmax(0, 1fr) 190px auto;
  gap: 1rem; align-items: center;
  padding: 0.75rem 1rem; border: 1px solid var(--line); border-radius: var(--r-md);
  background: var(--surface);
}
/* Borde completo y fondo teñido, no una franja lateral: una franja de color
   pegada al borde izquierdo es decoración que se lee como "esta fila es
   distinta" sin decir por qué. El estado vencido ya se explica con el "hace N
   días" en rojo; esto solo lo refuerza a nivel de fila. */
.fila.vencida {
  border-color: color-mix(in oklab, var(--danger) 35%, var(--line));
  background: color-mix(in oklab, var(--danger-tint) 55%, var(--surface));
}
.fila.fallecida { opacity: 0.72; }

.col-cuando, .col-que, .col-contacto { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
.col-cuando strong { font-size: var(--t-base); }
.col-cuando .rojo { color: var(--danger); }
.fila small { font-size: var(--t-micro); color: var(--ink-soft); }
.col-que strong { font-size: var(--t-base); }
.aviso-fallecida { font-size: var(--t-micro); color: var(--danger); font-weight: 600; margin-top: 0.15rem; }

.col-contacto { font-size: var(--t-micro); }
.enlace { color: var(--brand-ink); }
.enlace:hover { text-decoration: underline; }
.muted { color: var(--ink-soft); font-size: var(--t-micro); }

.col-acciones { display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
.btn-sm { font-size: var(--t-micro); padding: 0.4em 0.8em; }
.btn-wa { background: #25d366; color: #fff; border: 0; }
.btn-wa:hover { filter: brightness(0.94); }
.descartar { color: var(--ink-soft); padding: 0.4em 0.6em; }
.descartar:hover { color: var(--danger); background: var(--danger-tint); }
.badge { font-size: var(--t-micro); font-weight: 600; padding: 0.3em 0.7em; border-radius: var(--r-full); background: var(--bg); color: var(--ink-soft); }
.badge-ok { background: var(--ok-tint); color: var(--ok); }

.empty { color: var(--ink-soft); padding: 2rem 0; text-align: center; }
.error-text { color: var(--danger); margin-bottom: 0.8rem; }

@media (max-width: 860px) {
  .fila { grid-template-columns: 1fr; gap: 0.5rem; }
  .col-acciones { justify-content: flex-start; }
  .tarjetas { grid-template-columns: 1fr; max-width: none; }
}
</style>
