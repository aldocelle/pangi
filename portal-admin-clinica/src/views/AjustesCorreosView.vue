<script setup>
/* Ajustes → Correos: cada correo que manda el sistema, tal como le llega a la
   persona. El backend los arma con el mismo código que los envía y datos de
   ejemplo (GET /ajustes/correos), así que esta pantalla nunca queda vieja: si
   cambia un correo, cambia acá. No envía nada.

   El correo va en un <iframe srcdoc> y no con v-html: trae sus propios estilos
   en línea pensados para Gmail, y dentro del panel heredaría los del panel.
   `sandbox` sin `allow-scripts`: el HTML de un correo nunca necesita JS.

   Apariencia (bloque de abajo): solo los FONDOS son editables, y solo desde un
   catálogo cerrado (`GET /ajustes/correos/paleta`) — nunca un selector de color
   libre. El texto del correo sigue siempre en los mismos dos tonos oscuros
   (contraste garantizado); lo que cambia es sobre qué fondo se lee. Ver el
   porqué en `Plantilla::colores()` del backend. Guardar es solo de admin
   (`PUT /configuracion/correos`), igual que las reglas de venta e impresión. */
import { ref, computed, onMounted, nextTick } from 'vue';
import { api, apiError } from '../api.js';
import { useAuthStore } from '../stores/auth.js';
import { CLINICA } from '../marca.js';

const auth = useAuthStore();
const esAdmin = computed(() => auth.user?.rol === 'admin');

const correos = ref([]);
const cargando = ref(true);
const error = ref('');
const actual = ref(0);
const modo = ref('html');
const marco = ref(null);

const grupos = computed(() => {
  const m = new Map();
  correos.value.forEach((c, i) => {
    if (!m.has(c.grupo)) m.set(c.grupo, []);
    m.get(c.grupo).push({ ...c, i });
  });
  return [...m.entries()].map(([titulo, items]) => ({ titulo, items }));
});

const elegido = computed(() => correos.value[actual.value] || null);

// Los cuatro fondos editables, cada uno con la lista de la que elige («claros»
// para los que llevan el texto oscuro encima; «oscuros» para el botón, que
// lleva texto blanco). El orden es el que recorre el ojo en un correo real:
// la página primero, después la cabecera de la tarjeta, el botón, y al final
// el aviso, que es lo último que se lee.
const CAMPOS = [
  { clave: 'fondo_pagina', etiqueta: 'Fondo de la página', lista: 'claros' },
  { clave: 'fondo_cabecera', etiqueta: 'Fondo de la tarjeta', lista: 'claros' },
  { clave: 'fondo_boton', etiqueta: 'Color del botón', lista: 'oscuros' },
  { clave: 'fondo_aviso', etiqueta: 'Fondo del aviso', lista: 'claros' },
];

const paleta = ref({ claros: [], oscuros: [] });
const valores = ref(null);
const borrador = ref(null);
const editando = ref(false);
const guardando = ref(false);
const guardadoOk = ref(false);
const errorApariencia = ref('');
const errorGuardar = ref('');

function etiquetaDe(lista, hex) {
  return paleta.value[lista]?.find((o) => o.hex === hex)?.etiqueta || hex;
}

async function cargarApariencia() {
  try {
    const [{ data: p }, { data: v }] = await Promise.all([
      api.get('/ajustes/correos/paleta'),
      api.get('/configuracion/correos'),
    ]);
    paleta.value = p;
    valores.value = v;
    borrador.value = { ...v };
  } catch (e) {
    errorApariencia.value = apiError(e, 'No se pudo leer la apariencia de los correos.');
  }
}

async function guardar() {
  guardando.value = true;
  guardadoOk.value = false;
  errorGuardar.value = '';
  try {
    const { data } = await api.put('/configuracion/correos', borrador.value);
    valores.value = data;
    borrador.value = { ...data };
    guardadoOk.value = true;
    // La vista previa se arma en el servidor con la apariencia guardada: para
    // que se vea el cambio hay que volver a pedirla, no basta con cerrar el
    // formulario.
    const { data: c } = await api.get('/ajustes/correos');
    correos.value = c;
  } catch (e) {
    errorGuardar.value = apiError(e, 'No se pudo guardar.');
  } finally {
    guardando.value = false;
  }
}

function cancelar() {
  borrador.value = { ...valores.value };
  errorGuardar.value = '';
  editando.value = false;
}

onMounted(async () => {
  try {
    const { data } = await api.get('/ajustes/correos');
    correos.value = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudieron generar los correos.');
  } finally {
    cargando.value = false;
  }
  cargarApariencia();
});

async function elegir(i) {
  actual.value = i;
  await nextTick();
}

// Alto del iframe según su contenido, para que el correo se lea entero sin una
// segunda barra de desplazamiento adentro.
function ajustarAlto() {
  try {
    const doc = marco.value?.contentDocument;
    if (doc) marco.value.style.height = Math.max(320, doc.documentElement.scrollHeight + 16) + 'px';
  } catch { /* sin acceso al documento: queda el alto por defecto */ }
}
</script>

<template>
  <div class="page">
    <h1 class="page-title">Correos</h1>
    <p class="page-sub">
      Lo que recibe cada persona, generado con el código real del sistema y datos
      de ejemplo (Luna, de Daniela Rojas). Desde aquí no se envía nada.
    </p>

    <section v-if="valores" class="card co-apariencia">
      <div class="co-apariencia-head">
        <h2>Apariencia</h2>
        <button v-if="esAdmin" type="button" class="btn btn-ghost btn-sm" @click="editando = !editando">
          {{ editando ? 'Cerrar' : 'Editar' }}
        </button>
      </div>

      <template v-if="!editando">
        <ul class="co-resumen">
          <li v-for="c in CAMPOS" :key="c.clave">
            <span class="co-dot" :style="{ background: valores[c.clave] }"></span>
            {{ c.etiqueta }}: {{ etiquetaDe(c.lista, valores[c.clave]) }}
          </li>
          <li><span class="co-dot co-dot-texto">Aa</span> Firma del pie: «{{ valores.pie_texto }}»</li>
        </ul>
        <p v-if="!esAdmin" class="co-nota">Solo un administrador puede cambiarlo.</p>
      </template>

      <form v-else class="co-form" @submit.prevent="guardar">
        <div v-for="c in CAMPOS" :key="c.clave" class="co-campo">
          <span class="co-campo-tit">{{ c.etiqueta }}</span>
          <div class="co-swatches" role="group" :aria-label="c.etiqueta">
            <button
              v-for="op in paleta[c.lista]" :key="op.hex" type="button"
              class="co-swatch" :class="{ activo: borrador[c.clave] === op.hex }"
              :style="{ background: op.hex }" :title="op.etiqueta" :aria-label="op.etiqueta"
              :aria-pressed="borrador[c.clave] === op.hex"
              @click="borrador[c.clave] = op.hex"
            ></button>
          </div>
        </div>

        <label class="co-campo">
          <span class="co-campo-tit">Firma del pie</span>
          <input v-model.trim="borrador.pie_texto" type="text" maxlength="120" class="input" :placeholder="CLINICA">
        </label>

        <div class="co-form-acciones">
          <button type="submit" class="btn btn-primary btn-sm" :disabled="guardando">
            {{ guardando ? 'Guardando…' : 'Guardar' }}
          </button>
          <button type="button" class="btn btn-ghost btn-sm" :disabled="guardando" @click="cancelar">Cancelar</button>
          <span v-if="guardadoOk" class="co-ok">Guardado. Se actualizó la vista previa de abajo.</span>
        </div>
        <p v-if="errorGuardar" class="error-text">{{ errorGuardar }}</p>
      </form>
    </section>
    <p v-else-if="errorApariencia" class="error-text">{{ errorApariencia }}</p>

    <p v-if="cargando" class="co-nota">Generando los correos…</p>
    <p v-else-if="error" class="error-text">{{ error }}</p>

    <div v-else class="co-layout">
      <nav class="co-lista" aria-label="Correos">
        <div v-for="g in grupos" :key="g.titulo" class="co-grupo">
          <p class="co-grupo-tit">{{ g.titulo }}</p>
          <button
            v-for="c in g.items" :key="c.i" type="button"
            class="co-item" :class="{ activo: actual === c.i }" @click="elegir(c.i)"
          >
            <span>{{ c.titulo }}</span>
            <span class="co-para">{{ c.para }}</span>
          </button>
        </div>
      </nav>

      <section v-if="elegido" class="co-vista">
        <div class="card co-meta">
          <div class="co-fila"><span class="co-et">Para</span><span>{{ elegido.para }}</span></div>
          <div class="co-fila"><span class="co-et">Asunto</span><strong>{{ elegido.asunto || '—' }}</strong></div>
        </div>

        <p v-if="elegido.error" class="error-text">{{ elegido.error }}</p>
        <template v-else>
          <div class="co-modos" role="group" aria-label="Versión del correo">
            <button type="button" class="btn btn-sm" :class="modo === 'html' ? 'btn-primary' : 'btn-ghost'"
                    :aria-pressed="modo === 'html'" @click="modo = 'html'">Como se ve</button>
            <button type="button" class="btn btn-sm" :class="modo === 'texto' ? 'btn-primary' : 'btn-ghost'"
                    :aria-pressed="modo === 'texto'" @click="modo = 'texto'">Texto plano</button>
          </div>
          <div class="co-marco">
            <iframe
              v-show="modo === 'html'" ref="marco" :key="actual"
              :srcdoc="elegido.html" title="Vista del correo"
              sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
              @load="ajustarAlto"
            ></iframe>
            <pre v-show="modo === 'texto'" class="co-texto">{{ elegido.texto || 'Sin versión de texto.' }}</pre>
          </div>
        </template>
      </section>
    </div>
  </div>
</template>

<style scoped>
.co-nota { color: var(--ink-soft); }
.co-apariencia { margin-bottom: 1.2rem; display: grid; gap: 0.7rem; }
.co-apariencia-head { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; }
.co-apariencia-head h2 { font-size: var(--t-lg); margin: 0; }
.co-resumen { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 0.5rem 1.3rem; font-size: var(--t-sm); color: var(--ink); }
.co-resumen li { display: flex; align-items: center; gap: 0.4rem; }
.co-dot {
  display: inline-flex; align-items: center; justify-content: center;
  width: 1.1rem; height: 1.1rem; border-radius: var(--r-full); border: 1px solid var(--line);
  flex: none;
}
.co-dot-texto { font-size: var(--t-micro); font-weight: 700; color: var(--ink-soft); background: var(--bg); }
.co-form { display: grid; gap: 0.9rem; max-width: 30rem; }
.co-campo { display: grid; gap: 0.35rem; }
.co-campo-tit { font-size: var(--t-sm); font-weight: 600; color: var(--ink); }
.co-swatches { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.co-swatch {
  width: 2rem; height: 2rem; border-radius: var(--r-md); cursor: pointer;
  border: 2px solid var(--line); padding: 0;
}
.co-swatch:hover { border-color: var(--ink-soft); }
.co-swatch.activo { border-color: var(--brand); box-shadow: 0 0 0 2px var(--accent-tint); }
.co-form-acciones { display: flex; align-items: center; gap: 0.7rem; flex-wrap: wrap; }
.co-ok { color: var(--ok); font-size: var(--t-sm); }
.co-layout { display: grid; grid-template-columns: 17rem minmax(0, 1fr); gap: 1.4rem; align-items: start; }
.co-lista { display: flex; flex-direction: column; gap: 1rem; position: sticky; top: 1rem; }
.co-grupo { display: flex; flex-direction: column; gap: 0.15rem; }
.co-grupo-tit {
  font-size: var(--t-micro); font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase;
  color: var(--ink-soft); margin: 0 0 0.3rem 0.15rem;
}
.co-item {
  display: flex; flex-direction: column; align-items: flex-start; gap: 0.05rem;
  text-align: left; border: 0; background: none; color: var(--ink); font: inherit;
  font-size: var(--t-base); padding: 0.45em 0.7em; border-radius: var(--r-md); cursor: pointer;
}
.co-item:hover { background: var(--accent-hover); }
.co-item.activo { background: var(--brand); color: white; }
.co-item.activo .co-para { color: inherit; opacity: 0.85; }
.co-para { font-size: var(--t-micro); color: var(--ink-soft); }
.co-vista { display: grid; gap: 0.8rem; min-width: 0; max-width: 680px; }
.co-meta { display: grid; gap: 0.3rem; }
.co-fila { display: grid; grid-template-columns: 4.5rem minmax(0, 1fr); gap: 0.6rem; word-break: break-word; }
.co-et { color: var(--ink-soft); }
.co-modos { display: flex; gap: 0.4rem; }
/* El fondo del marco es blanco fijo: es el lienzo del correo, no del panel. */
.co-marco { border: 1px solid var(--line); border-radius: var(--r-md); overflow: hidden; background: #fff; }
.co-marco iframe { display: block; width: 100%; height: 640px; border: 0; }
.co-texto {
  margin: 0; padding: 1rem; white-space: pre-wrap; word-break: break-word;
  font-size: var(--t-sm); line-height: 1.5; color: #1f2937;
}
@media (max-width: 820px) {
  .co-layout { grid-template-columns: minmax(0, 1fr); }
  .co-lista { position: static; }
}
</style>
