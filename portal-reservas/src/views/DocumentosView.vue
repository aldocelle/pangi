<script setup>
/* Documentos: los exámenes, radiografías, recetas y demás adjuntos de TODAS
 * las mascotas del tutor, en un solo lugar.
 *
 * Cada ficha ya tenía su pestaña Documentos; esto responde otra pregunta,
 * «¿dónde quedó el examen de sangre?», sin obligar a recordar de qué mascota
 * era ni entrar a cada ficha. Sale de `GET /fichas/mis-documentos`, que solo
 * lee: recorrer las fichas una por una creaba fichas vacías de paso.
 */
import { ref, computed, onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import { api, apiError, fechaLarga } from '../api.js';
import { CLINICA } from '../config.js';
import BaseIcon from '../components/BaseIcon.vue';

const documentos = ref([]);
const cargando = ref(true);
const error = ref('');

const TIPOS = {
  examen: { label: 'Examen', plural: 'Exámenes', icono: 'flask' },
  rx: { label: 'Radiografía', plural: 'Radiografías', icono: 'image' },
  receta: { label: 'Receta', plural: 'Recetas', icono: 'doc' },
  otro: { label: 'Otro', plural: 'Otros', icono: 'folder' },
};
const tipoDe = (d) => TIPOS[d.tipo] || TIPOS.otro;

const filtroMascota = ref('');
const filtroTipo = ref('');

async function cargar() {
  cargando.value = true;
  error.value = '';
  try {
    const { data } = await api.get('/fichas/mis-documentos');
    documentos.value = data;
  } catch (e) {
    error.value = apiError(e, 'No pudimos cargar tus documentos.');
  } finally {
    cargando.value = false;
  }
}
onMounted(cargar);

/* Los filtros solo ofrecen lo que existe: un chip de «Recetas» que siempre da
   cero enseña a no usar los filtros. Y con una sola mascota no hay nada que
   elegir, así que esa fila ni aparece. */
const mascotas = computed(() => {
  const vistos = new Map();
  for (const d of documentos.value) {
    if (!vistos.has(d.mascota_id)) vistos.set(d.mascota_id, { id: d.mascota_id, nombre: d.mascota_nombre, n: 0 });
    vistos.get(d.mascota_id).n++;
  }
  return [...vistos.values()].sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'));
});
const tipos = computed(() => {
  const base = filtroMascota.value
    ? documentos.value.filter((d) => d.mascota_id === filtroMascota.value)
    : documentos.value;
  return Object.entries(TIPOS)
    .map(([id, t]) => ({ id, ...t, n: base.filter((d) => (TIPOS[d.tipo] ? d.tipo : 'otro') === id).length }))
    .filter((t) => t.n > 0);
});

/* Búsqueda sin tildes ni mayúsculas: «radiografia» tiene que encontrar
   «Radiografía de tórax». */
const busqueda = ref('');
const plano = (t) => (t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const visibles = computed(() => {
  const q = plano(busqueda.value.trim());
  return documentos.value.filter((d) =>
    (!filtroMascota.value || d.mascota_id === filtroMascota.value)
    && (!filtroTipo.value || (TIPOS[d.tipo] ? d.tipo : 'otro') === filtroTipo.value)
    && (!q || plano(`${d.descripcion} ${d.nombre_original} ${d.mascota_nombre} ${tipoDe(d).label}`).includes(q)));
});

const hayFiltro = computed(() => !!(filtroMascota.value || filtroTipo.value || busqueda.value.trim()));
function limpiarFiltros() {
  filtroMascota.value = '';
  filtroTipo.value = '';
  busqueda.value = '';
}

/* Agrupados por mes, lo más reciente arriba: «¿dónde quedó el examen?» casi
   siempre se recuerda por cuándo fue, no por cómo se llamaba el archivo. */
const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

const grupos = computed(() => {
  const orden = [...visibles.value].sort((a, b) =>
    (b.created_at || '').localeCompare(a.created_at || '') || String(a.id).localeCompare(String(b.id)));
  const porMes = new Map();
  for (const d of orden) {
    const f = d.created_at ? new Date(d.created_at) : null;
    const clave = f ? `${f.getFullYear()}-${f.getMonth()}` : 'sin-fecha';
    if (!porMes.has(clave)) {
      porMes.set(clave, { clave, titulo: f ? `${MESES[f.getMonth()]} ${f.getFullYear()}` : 'Sin fecha', docs: [] });
    }
    porMes.get(clave).docs.push(d);
  }
  return [...porMes.values()];
});

function diaCorto(iso) {
  if (!iso) return '';
  const f = new Date(iso);
  return `${f.getDate()} ${MESES_CORTOS[f.getMonth()]}`;
}

/* La extensión se muestra porque dice qué va a pasar al abrirlo: un PDF se lee
   en el navegador, un DICOM o un ZIP no. */
function extension(d) {
  const m = /\.([a-z0-9]{1,5})$/i.exec(d.nombre_original || '');
  return m ? m[1].toUpperCase() : '';
}

function elegirMascota(id) {
  filtroMascota.value = id;
  // Si el tipo elegido no existe para esta mascota, no dejar la lista vacía
  // por un filtro que ya no se ve.
  if (filtroTipo.value && !tipos.value.some((t) => t.id === filtroTipo.value)) filtroTipo.value = '';
}

function tamano(bytes) {
  if (!bytes) return '';
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}

/* El archivo exige el token, así que un <a href> no sirve: se pide como blob
   por axios (mismo motivo que en FichaView).

   La pestaña se abre ANTES de la petición. Abrirla después del `await` ya no
   cuenta como respuesta al clic, y Safari (el de todo iPhone) la bloquea en
   silencio: el tutor toca y no pasa nada. Si aun así el navegador no da ventana, se descarga. */
const trabajando = ref(null);
const errorDoc = ref('');

async function traer(d) {
  const { data } = await api.get(`/fichas/documentos/${d.id}/archivo`, { responseType: 'blob' });
  return URL.createObjectURL(data);
}

function descargarUrl(url, nombre) {
  const a = document.createElement('a');
  a.href = url;
  a.download = nombre || 'documento';
  document.body.appendChild(a);
  a.click();
  a.remove();
}

async function abrir(d) {
  errorDoc.value = '';
  trabajando.value = d.id;
  const ventana = window.open('', '_blank');
  try {
    const url = await traer(d);
    if (ventana) {
      ventana.opener = null;
      ventana.location.href = url;
    } else {
      descargarUrl(url, d.nombre_original);
    }
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch (e) {
    ventana?.close();
    errorDoc.value = apiError(e, 'No se pudo abrir el documento.');
  } finally {
    trabajando.value = null;
  }
}

async function descargar(d) {
  errorDoc.value = '';
  trabajando.value = d.id;
  try {
    const url = await traer(d);
    descargarUrl(url, d.nombre_original);
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch (e) {
    errorDoc.value = apiError(e, 'No se pudo descargar el documento.');
  } finally {
    trabajando.value = null;
  }
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div class="head-txt">
        <h1><span class="head-ic"><BaseIcon name="folder" :size="22" /></span> Documentos</h1>
        <p class="muted">
          <template v-if="!cargando && documentos.length">
            {{ documentos.length }} {{ documentos.length === 1 ? 'documento' : 'documentos' }}
            <template v-if="mascotas.length > 1">de {{ mascotas.length }} mascotas</template>
            · exámenes, radiografías y recetas
          </template>
          <template v-else>Exámenes, radiografías y recetas de tus mascotas.</template>
        </p>
      </div>
      <label v-if="!cargando && documentos.length > 3" class="buscador">
        <span class="sr-only">Buscar documento</span>
        <svg class="buscador-ic" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
        <input v-model="busqueda" type="search" placeholder="Buscar por nombre o mascota" autocomplete="off" />
      </label>
    </header>

    <!-- Esqueleto con la forma de lo que viene: un «Cargando…» suelto hace
         saltar la página entera cuando llegan las tarjetas. -->
    <ul v-if="cargando" class="grilla" aria-busy="true" aria-label="Cargando documentos">
      <li v-for="n in 6" :key="n" class="doc doc-esqueleto" aria-hidden="true">
        <span class="sk sk-tile"></span>
        <span class="sk sk-linea" style="width: 40%"></span>
        <span class="sk sk-linea" style="width: 85%"></span>
        <span class="sk sk-linea" style="width: 60%"></span>
      </li>
    </ul>

    <div v-else-if="error" class="card card-pad aviso-error">
      <p>{{ error }}</p>
      <button type="button" class="btn btn-ghost btn-sm" @click="cargar">
        <BaseIcon name="refresh" :size="16" /> Reintentar
      </button>
    </div>

    <section v-else-if="!documentos.length" class="card vacio">
      <span class="vacio-ic"><BaseIcon name="folder" :size="30" /></span>
      <strong>Todavía no hay documentos</strong>
      <p class="muted">
        Cuando la clínica cargue un examen, una radiografía o una receta en la ficha
        de alguna de tus mascotas, lo encontrarás aquí.
      </p>
      <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">
        <BaseIcon name="wa" :size="16" /> ¿Esperas un resultado? Pregúntanos
      </a>
    </section>

    <template v-else>
      <div v-if="mascotas.length > 1 || tipos.length > 1" class="filtros">
        <div v-if="mascotas.length > 1" class="fila-filtro" role="group" aria-label="Filtrar por mascota">
          <button type="button" class="filtro" :class="{ on: !filtroMascota }" :aria-pressed="!filtroMascota" @click="elegirMascota('')">
            <span class="filtro-av"><BaseIcon name="pets" :size="14" /></span>
            Todas <span class="n">{{ documentos.length }}</span>
          </button>
          <button
            v-for="m in mascotas" :key="m.id" type="button"
            class="filtro" :class="{ on: filtroMascota === m.id }" :aria-pressed="filtroMascota === m.id"
            @click="elegirMascota(m.id)"
          >
            <span class="filtro-av" aria-hidden="true">{{ m.nombre.charAt(0).toUpperCase() }}</span>
            {{ m.nombre }} <span class="n">{{ m.n }}</span>
          </button>
        </div>
        <div v-if="tipos.length > 1" class="fila-filtro fila-tipos" role="group" aria-label="Filtrar por tipo">
          <button type="button" class="tipo" :class="{ on: !filtroTipo }" :aria-pressed="!filtroTipo" @click="filtroTipo = ''">
            Todo
          </button>
          <button
            v-for="t in tipos" :key="t.id" type="button"
            class="tipo" :class="[`t-${t.id}`, { on: filtroTipo === t.id }]" :aria-pressed="filtroTipo === t.id"
            @click="filtroTipo = t.id"
          ><BaseIcon :name="t.icono" :size="15" /> {{ t.plural }} <span class="n">{{ t.n }}</span></button>
        </div>
      </div>

      <p v-if="errorDoc" class="form-error" role="alert">{{ errorDoc }}</p>

      <section v-if="!visibles.length" class="card sin-resultados">
        <strong>No hay documentos con esos filtros</strong>
        <p class="muted">Prueba con otra búsqueda o muestra todo.</p>
        <button type="button" class="btn btn-ghost btn-sm" @click="limpiarFiltros">Ver todos</button>
      </section>

      <section v-for="g in grupos" :key="g.clave" class="grupo" :aria-label="g.titulo">
        <h2 class="grupo-titulo">{{ g.titulo }} <span class="n">{{ g.docs.length }}</span></h2>
        <ul class="grilla">
          <li v-for="d in g.docs" :key="d.id" class="doc" :class="[`t-${TIPOS[d.tipo] ? d.tipo : 'otro'}`, { ocupado: trabajando === d.id }]">
            <button type="button" class="doc-cuerpo" :disabled="trabajando === d.id" @click="abrir(d)">
              <span class="doc-tile">
                <BaseIcon :name="tipoDe(d).icono" :size="24" />
                <span v-if="extension(d)" class="doc-ext">{{ extension(d) }}</span>
              </span>
              <span class="doc-txt">
                <span class="doc-tipo">{{ tipoDe(d).label }}</span>
                <span class="doc-nombre">{{ d.descripcion || d.nombre_original }}</span>
                <span class="doc-cuando" :title="fechaLarga(d.created_at)">
                  {{ diaCorto(d.created_at) }}<template v-if="tamano(d.tamano_bytes)"> · {{ tamano(d.tamano_bytes) }}</template>
                </span>
              </span>
            </button>

            <div class="doc-pie">
              <RouterLink :to="`/mascotas/${d.mascota_id}`" class="doc-mascota" :title="`Ver la ficha de ${d.mascota_nombre}`">
                <span class="doc-mascota-av" aria-hidden="true">{{ (d.mascota_nombre || '?').charAt(0).toUpperCase() }}</span>
                {{ d.mascota_nombre }}
              </RouterLink>
              <div class="doc-acciones">
                <button type="button" class="accion" :disabled="trabajando === d.id" @click="abrir(d)">
                  <BaseIcon name="eye" :size="16" /> {{ trabajando === d.id ? 'Abriendo…' : 'Ver' }}
                </button>
                <button
                  type="button" class="accion accion-icono" :disabled="trabajando === d.id"
                  :aria-label="`Descargar ${d.descripcion || d.nombre_original}`" title="Descargar"
                  @click="descargar(d)"
                ><BaseIcon name="download" :size="17" /></button>
              </div>
            </div>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<style scoped>
/* ── Cabecera ── */
.page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem 1.5rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.page-head h1 { font-size: var(--t-2xl); color: var(--brand); display: flex; align-items: center; gap: 0.6rem; }
.head-ic { width: 42px; height: 42px; border-radius: 13px; background: var(--brand); color: #fff; display: grid; place-items: center; box-shadow: 0 6px 16px rgba(38, 50, 110, 0.22); }
.page-head .muted { margin-top: 0.35rem; font-size: var(--t-sm); }

.buscador { position: relative; flex: 0 1 300px; min-width: 220px; }
.buscador-ic { position: absolute; left: 0.9rem; top: 50%; transform: translateY(-50%); color: var(--ink-soft); pointer-events: none; }
.buscador input {
  width: 100%; padding: 0.62rem 1rem 0.62rem 2.5rem;
  border: 1.5px solid var(--line-strong); border-radius: var(--r-full);
  background: var(--surface); font-size: var(--t-sm);
  transition: border-color var(--dur-fast) var(--ease), box-shadow var(--dur-fast) var(--ease);
}
.buscador input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 4px rgba(var(--accent-rgb), 0.14); }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

/* ── Avisos ── */
.aviso-error { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.aviso-error p { margin: 0; color: var(--danger); }
.form-error { background: var(--danger-tint); color: var(--danger); padding: 0.7rem 1rem; border-radius: var(--r-md); font-size: var(--t-sm); margin-bottom: 1rem; }

.vacio, .sin-resultados { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 0.5rem; padding: 3rem 1.4rem; }
.vacio-ic { width: 72px; height: 72px; border-radius: 22px; background: linear-gradient(135deg, var(--brand-tint), var(--accent-tint)); color: var(--brand); display: grid; place-items: center; margin-bottom: 0.4rem; }
.vacio strong, .sin-resultados strong { font-size: var(--t-lg); color: var(--brand); }
.vacio p, .sin-resultados p { margin: 0 0 0.6rem; max-width: 46ch; font-size: var(--t-sm); }
.sin-resultados { padding: 2.2rem 1.4rem; }

/* ── Filtros ── */
.filtros { display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.6rem; }
.fila-filtro { display: flex; gap: 0.45rem; flex-wrap: wrap; }
.filtro {
  display: inline-flex; align-items: center; gap: 0.45rem;
  padding: 0.3rem 0.85rem 0.3rem 0.3rem; border-radius: var(--r-full);
  border: 1.5px solid var(--line-strong); background: var(--surface);
  font-size: var(--t-sm); font-weight: 600; color: var(--ink-soft);
  transition: border-color var(--dur-fast) var(--ease), background var(--dur-fast) var(--ease), color var(--dur-fast) var(--ease);
}
.filtro-av {
  width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center;
  background: var(--brand-tint); color: var(--brand); font-size: var(--t-micro); font-weight: 700;
}
.filtro:hover { border-color: var(--accent); color: var(--accent-strong); }
.filtro.on { background: var(--brand); border-color: var(--brand); color: #fff; }
.filtro.on .filtro-av { background: rgba(255, 255, 255, 0.18); color: #fff; }
.n { font-size: var(--t-micro); font-weight: 700; opacity: 0.7; }

/* Los tipos van como pestañas suaves, un escalón por debajo de las mascotas:
   son un filtro fino dentro de la elección de arriba. */
.fila-tipos { gap: 0.35rem; }
.tipo {
  display: inline-flex; align-items: center; gap: 0.4rem;
  padding: 0.38rem 0.8rem; border-radius: var(--r-sm);
  font-size: var(--t-sm); font-weight: 600; color: var(--ink-soft);
  transition: background var(--dur-fast) var(--ease), color var(--dur-fast) var(--ease);
}
.tipo:hover { background: var(--accent-hover); color: var(--accent-strong); }
.tipo.on { background: var(--surface); color: var(--brand); box-shadow: 0 1px 0 var(--line), 0 4px 12px rgba(38, 50, 110, 0.08); }

/* ── Grupos por mes ── */
.grupo + .grupo { margin-top: 1.8rem; }
.grupo-titulo {
  display: flex; align-items: center; gap: 0.6rem;
  font-size: var(--t-sm); font-weight: 700; color: var(--ink-soft);
  font-family: var(--font); letter-spacing: 0; margin-bottom: 0.8rem;
}
.grupo-titulo::after { content: ''; flex: 1; height: 1px; background: var(--line-strong); }

/* ── Tarjetas ── */
.grilla { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem; }

.doc {
  position: relative; display: flex; flex-direction: column;
  background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg);
  overflow: hidden;
  transition: transform var(--dur-fast) var(--ease), box-shadow var(--dur-fast) var(--ease), border-color var(--dur-fast) var(--ease);
}
.doc:not(.doc-esqueleto):hover { transform: translateY(-2px); box-shadow: var(--shadow); border-color: var(--line-strong); }
.doc.ocupado { opacity: 0.7; }

/* Toda la parte de arriba abre el documento: es el blanco grande que se
   toca con el pulgar. El enlace a la mascota queda fuera, en el pie, para no
   anidar un enlace dentro de un botón. */
.doc-cuerpo {
  display: flex; flex-direction: column; align-items: flex-start; gap: 0.9rem;
  padding: 1.1rem 1.15rem 0.9rem; text-align: left; width: 100%; flex: 1;
}
.doc-cuerpo:disabled { cursor: progress; }
.doc-tile {
  position: relative; width: 52px; height: 52px; border-radius: 15px; flex: none;
  display: grid; place-items: center; background: var(--brand-tint); color: var(--brand);
}
.doc-ext {
  position: absolute; right: -8px; bottom: -6px;
  padding: 0.05rem 0.4rem; border-radius: 6px;
  background: var(--surface); border: 1px solid var(--line-strong);
  font-size: var(--t-micro); font-weight: 700; color: var(--ink-soft); letter-spacing: 0.02em; line-height: 1.4;
}
.doc-txt { display: flex; flex-direction: column; gap: 0.2rem; min-width: 0; width: 100%; }
.doc-tipo { font-size: var(--t-micro); font-weight: 700; color: var(--brand); }

/* Color por tipo: el mismo par tinta/tinte en el ícono, la etiqueta y la
   pestaña del filtro, para que se reconozca sin leer. */
.t-examen .doc-tile { background: var(--accent-tint); color: var(--accent); }
.t-examen .doc-tipo, .tipo.on.t-examen { color: var(--accent); }
.t-receta .doc-tile { background: var(--ok-tint); color: var(--ok); }
.t-receta .doc-tipo, .tipo.on.t-receta { color: var(--ok); }
.t-otro .doc-tile { background: var(--purple-tint); color: var(--purple); }
.t-otro .doc-tipo, .tipo.on.t-otro { color: var(--purple); }
.doc-nombre {
  font-size: var(--t-base); font-weight: 600; color: var(--ink); line-height: 1.35;
  display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical;
  overflow: hidden; overflow-wrap: anywhere;
}
.doc-cuerpo:hover .doc-nombre { color: var(--accent-strong); }
.doc-cuando { font-size: var(--t-micro); color: var(--ink-soft); margin-top: 0.15rem; }

.doc-pie {
  display: flex; align-items: center; justify-content: space-between; gap: 0.6rem;
  padding: 0.65rem 0.8rem 0.65rem 1.15rem; border-top: 1px solid var(--line); background: #fafbfe;
}
.doc-mascota {
  display: inline-flex; align-items: center; gap: 0.4rem; min-width: 0;
  font-size: var(--t-sm); font-weight: 600; color: var(--brand);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.doc-mascota:hover { color: var(--accent-strong); text-decoration: underline; }
.doc-mascota-av {
  width: 22px; height: 22px; border-radius: 50%; flex: none; display: grid; place-items: center;
  background: var(--brand); color: #fff; font-size: var(--t-micro); font-weight: 700;
}

.doc-acciones { flex: none; display: flex; align-items: center; gap: 0.3rem; }
.accion {
  display: inline-flex; align-items: center; gap: 0.35rem; height: 34px; padding: 0 0.8rem;
  border-radius: var(--r-full); font-size: var(--t-sm); font-weight: 600; color: var(--accent);
  transition: background var(--dur-fast) var(--ease), color var(--dur-fast) var(--ease);
}
.accion:hover:not(:disabled) { background: var(--accent-hover); color: var(--accent-strong); }
.accion:disabled { opacity: 0.5; cursor: progress; }
.accion-icono { width: 34px; padding: 0; justify-content: center; color: var(--ink-soft); }

/* ── Esqueleto ── */
.doc-esqueleto { padding: 1.1rem 1.15rem 1.3rem; gap: 0.6rem; }
.sk { display: block; border-radius: 8px; background: linear-gradient(90deg, var(--bg) 0%, #f7f8fd 50%, var(--bg) 100%); background-size: 200% 100%; animation: brillo 1.3s ease-in-out infinite; }
.sk-tile { width: 52px; height: 52px; border-radius: 15px; margin-bottom: 0.4rem; }
.sk-linea { height: 12px; }
@keyframes brillo { from { background-position: 100% 0; } to { background-position: -100% 0; } }

@media (prefers-reduced-motion: reduce) {
  .sk { animation: none; }
  .doc, .doc:not(.doc-esqueleto):hover { transition: none; transform: none; }
}

/* En el teléfono la tarjeta se acuesta: ícono a la izquierda y texto al lado,
   para que quepan varias en pantalla sin desplazarse tanto. */
@media (max-width: 640px) {
  .page-head { align-items: stretch; }
  .buscador { flex-basis: 100%; }
  .grilla { grid-template-columns: 1fr; gap: 0.75rem; }
  .doc-cuerpo { flex-direction: row; align-items: center; gap: 1rem; padding: 0.95rem 1rem 0.8rem; }
  .doc-tile { width: 46px; height: 46px; border-radius: 13px; }
  .doc-pie { padding-left: 1rem; }
  .fila-filtro { flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; margin: 0 -1rem; padding: 0 1rem 0.15rem; }
  .fila-filtro::-webkit-scrollbar { display: none; }
  .filtro, .tipo { flex: none; }
}
</style>
