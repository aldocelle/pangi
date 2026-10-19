<script setup>
/* «Enseñarle» (Ajustes → Asistente): convierte una pregunta que el chat no supo
   contestar en una pregunta frecuente, con todo listo en una sola pantalla.

   Tres pasos a la vista —palabras clave sugeridas, respuesta, y lo que el chat
   contestaría— porque una pregunta frecuente puede guardarse bien y no contestar
   nunca: la palabra clave no está en cómo pregunta la gente, choca con otra, el
   mensaje dice «mi perro» y eso siempre va a una persona, o lo toma antes el
   asistente de reservas. La vista previa sale de POST /chatbot/probar, que
   recorre el camino real del chat: las reglas NO se copian acá.

   Lo único que decide este archivo es qué palabras SUGERIR, y es solo una ayuda:
   la oficina las marca, las quita o escribe otras. */
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { api, apiError } from '../api.js';

const props = defineProps({
  pregunta: { type: Object, required: true },   // { texto, veces }
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' },
  lleno: { type: Boolean, default: false },     // ya hay 30 preguntas frecuentes
});
const emit = defineEmits(['guardar', 'close']);

/* Palabras que no distinguen una pregunta de otra: calzarían con casi todo. */
const VACIAS = new Set(`hola buenas buenos dias tardes noches gracias favor porfa quisiera quiero
  necesito saber consulta consultar pregunta tienen tiene tengo hacen hace puedo pueden podria
  ustedes usted alguien alguna algun algo cuanto cuanta cuantos cuando donde como porque para
  pero esta este esto estan estoy entre desde hasta sobre tambien solo mucho muchas otra otro
  veterinaria clinica perro perros gato gatos mascota mascotas`.split(/\s+/));

function norm(s) {
  return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

const sugeridas = computed(() => {
  // «[telefono]», «[correo]»: los puso la anonimización, no el cliente.
  const palabras = norm(props.pregunta.texto).replace(/\[[a-z]+\]/g, ' ').split(/[^a-z]+/);
  const vistas = new Set();
  for (const p of palabras) {
    if (p.length < 4 || VACIAS.has(p)) continue;
    // «conejos» → «conejo»: la clave calza por contenido, así sirve para las dos.
    vistas.add(p.length >= 6 && p.endsWith('s') ? p.slice(0, -1) : p);
  }
  return [...vistas];
});

// Arranca con las dos más largas marcadas: suelen ser las que dicen de qué se trata.
const claves = ref([...sugeridas.value].sort((a, b) => b.length - a.length).slice(0, 2));
const otraClave = ref('');
const respuesta = ref('');
const otraFrase = ref('');
const areaRespuesta = ref(null);

function alternar(p) {
  const i = claves.value.indexOf(p);
  if (i >= 0) claves.value.splice(i, 1);
  else claves.value.push(p);
}
function agregarClave() {
  for (const c of otraClave.value.split(',')) {
    const limpia = norm(c).trim();
    if (limpia && !claves.value.includes(limpia)) claves.value.push(limpia);
  }
  otraClave.value = '';
}
function alComa(e) {
  if (e.key === ',') { e.preventDefault(); agregarClave(); }
}
const extras = computed(() => claves.value.filter((c) => !sugeridas.value.includes(c)));

/* ── Vista previa contra el backend ── */
const prueba = ref(null);
const probando = ref(false);
const errorPrueba = ref('');
let temporizador = null;
let turno = 0;

async function probar() {
  const yo = ++turno;
  probando.value = true;
  errorPrueba.value = '';
  try {
    const textos = [props.pregunta.texto, otraFrase.value.trim()].filter(Boolean);
    const { data } = await api.post('/chatbot/probar', {
      textos, claves: claves.value.join(', '), respuesta: respuesta.value,
    });
    if (yo === turno) prueba.value = data;
  } catch (e) {
    if (yo === turno) errorPrueba.value = apiError(e);
  } finally {
    if (yo === turno) probando.value = false;
  }
}
watch([claves, respuesta, otraFrase], () => {
  clearTimeout(temporizador);
  temporizador = setTimeout(probar, 450);
}, { deep: true });

const errores = computed(() => prueba.value?.errores || {});
const original = computed(() => prueba.value?.pruebas?.[0] || null);
const contestaLaOriginal = computed(() => original.value?.origen === 'nueva');
const listo = computed(() =>
  !props.lleno && !probando.value && prueba.value && !Object.keys(errores.value).length,
);

function guardar() {
  if (!listo.value || props.busy) return;
  emit('guardar', { claves: claves.value.join(', '), respuesta: respuesta.value.trim() });
}

const ESTADO = {
  nueva: ['ok', 'Contestaría con tu respuesta'],
  reservas: ['info', 'Lo toma el asistente de reservas'],
  otra: ['warn', 'Contestaría otra cosa'],
  null: ['warn', 'No lo contestaría: iría a WhatsApp'],
};
const estado = (p) => ESTADO[p.origen] || ESTADO.null;

function alTeclado(e) {
  if (e.key === 'Escape' && !props.busy) emit('close');
}
onMounted(async () => {
  document.addEventListener('keydown', alTeclado);
  probar();
  await nextTick();
  areaRespuesta.value?.focus();
});
onBeforeUnmount(() => {
  document.removeEventListener('keydown', alTeclado);
  clearTimeout(temporizador);
});
</script>

<template>
  <div class="modal-backdrop" @click.self="!busy && emit('close')">
    <div class="modal ep" role="dialog" aria-modal="true" aria-labelledby="ep-titulo">
      <div class="modal-head">
        <h3 id="ep-titulo">Enseñarle al asistente</h3>
        <button class="modal-close" aria-label="Cerrar" :disabled="busy" @click="emit('close')">✕</button>
      </div>

      <!-- 1 · Lo que preguntaron -->
      <section class="ep-paso">
        <h4 class="ep-num"><span>1</span> Lo que preguntaron</h4>
        <blockquote class="ep-cita">
          «{{ pregunta.texto }}»
          <span class="ep-veces">{{ pregunta.veces }} {{ pregunta.veces === 1 ? 'vez' : 'veces' }}</span>
        </blockquote>
      </section>

      <!-- 2 · Palabras clave -->
      <section class="ep-paso">
        <h4 class="ep-num"><span>2</span> Palabras clave</h4>
        <p class="ep-ayuda">
          Si un mensaje contiene cualquiera de las marcadas, se envía tu respuesta.
          Marca las que dicen de qué se trata; mínimo 4 letras.
        </p>
        <div class="ep-chips">
          <button
            v-for="p in sugeridas" :key="p" type="button" class="ep-chip"
            :class="{ on: claves.includes(p) }" :aria-pressed="claves.includes(p)"
            @click="alternar(p)"
          >{{ claves.includes(p) ? '✓ ' : '' }}{{ p }}</button>
          <button
            v-for="p in extras" :key="'x' + p" type="button" class="ep-chip on"
            aria-pressed="true" :aria-label="`Quitar ${p}`" @click="alternar(p)"
          >✓ {{ p }} ✕</button>
        </div>
        <div class="ep-agregar">
          <input
            v-model="otraClave" class="input" type="text" maxlength="60"
            placeholder="Otra palabra, por ejemplo «exotico»"
            @keydown.enter.prevent="agregarClave" @keydown="alComa"
          />
          <button type="button" class="btn btn-ghost" :disabled="!otraClave.trim()" @click="agregarClave">Agregar</button>
        </div>
        <p v-if="errores.claves" class="error-text">{{ errores.claves }}</p>
      </section>

      <!-- 3 · Respuesta -->
      <section class="ep-paso">
        <h4 class="ep-num"><span>3</span> Respuesta</h4>
        <textarea
          ref="areaRespuesta" v-model="respuesta" class="textarea" rows="3" maxlength="800"
          placeholder="Tal cual la enviará el chat. Ej: Sí, atendemos conejos y hurones con la Dra. …"
        ></textarea>
        <p class="ep-ayuda ep-cuenta">{{ respuesta.length }}/800 · Se envía literal, sin IA.</p>
        <p v-if="errores.respuesta && respuesta.length" class="error-text">{{ errores.respuesta }}</p>
      </section>

      <!-- 4 · Así contestaría -->
      <section class="ep-paso">
        <h4 class="ep-num"><span>4</span> Así contestaría el chat</h4>
        <p v-if="errorPrueba" class="error-text">No se pudo probar: {{ errorPrueba }}</p>
        <div
          v-for="(p, i) in (prueba?.pruebas || [])" :key="i"
          class="ep-prueba" :class="estado(p)[0]" :aria-busy="probando"
        >
          <p class="ep-pregunta">«{{ p.texto }}»</p>
          <p class="ep-estado">{{ estado(p)[1] }}</p>
          <p v-if="p.origen === 'nueva' && !respuesta.trim()" class="ep-ayuda">
            Calza. Escribe la respuesta para verla acá.
          </p>
          <p v-else-if="p.respuesta" class="ep-globo">{{ p.respuesta }}</p>
          <p v-if="p.explicacion" class="ep-ayuda">{{ p.explicacion }}</p>
          <ul v-if="p.choca_con.length" class="ep-choques">
            <li v-for="(c, j) in p.choca_con" :key="j">
              Choca con <strong>{{ c.claves }}</strong> → «{{ c.respuesta }}»
            </li>
          </ul>
        </div>
        <p v-if="probando && !prueba" class="ep-ayuda">Probando…</p>
        <label class="field ep-otra">
          <span class="ep-ayuda">Prueba otra forma de preguntarlo</span>
          <input
            v-model="otraFrase" class="input" type="text" maxlength="300"
            placeholder="Ej: ¿ven conejitos?"
          />
        </label>
      </section>

      <p v-if="lleno" class="error-text">
        Ya hay 30 preguntas frecuentes, el máximo. Quita una en «Lo que la oficina ya sabe» para agregar esta.
      </p>
      <p v-if="error" class="error-text">{{ error }}</p>

      <div class="modal-actions ep-pie">
        <span class="ep-ayuda">Se guarda al tiro, junto con cualquier otro cambio sin guardar de esta página.</span>
        <button class="btn btn-ghost" :disabled="busy" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" :disabled="!listo || busy" @click="guardar">
          {{ busy ? 'Guardando…' : (contestaLaOriginal ? 'Guardar y enseñarle' : 'Guardar igual') }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ep { max-width: 640px; }
.ep-paso { padding: 0.85rem 0; border-top: 1px solid var(--line); }
.ep-paso:first-of-type { border-top: 0; padding-top: 0; }
.ep-num {
  display: flex; align-items: center; gap: 0.5rem;
  font-size: var(--t-base); font-weight: 600; margin: 0 0 0.5rem;
}
.ep-num span {
  display: inline-grid; place-items: center; width: 1.5rem; height: 1.5rem; flex: none;
  border-radius: 50%; background: var(--accent-tint); color: var(--accent-strong);
  font-size: var(--t-sm);
}
.ep-cita {
  margin: 0; padding: 0.6rem 0.8rem; border-left: 3px solid var(--accent);
  background: var(--bg); border-radius: var(--r-sm); font-size: var(--t-base); line-height: 1.45;
}
.ep-veces { display: block; color: var(--ink-soft); font-size: var(--t-sm); margin-top: 0.2rem; }
.ep-ayuda { color: var(--ink-soft); font-size: var(--t-sm); line-height: 1.45; margin: 0.25rem 0; }
.ep-cuenta { text-align: right; }

.ep-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; margin: 0.5rem 0; }
.ep-chip {
  padding: 0.3rem 0.7rem; border-radius: 999px; font: inherit; font-size: var(--t-sm);
  border: 1.5px solid var(--line-strong); background: var(--surface); color: var(--ink); cursor: pointer;
}
.ep-chip:hover { border-color: var(--accent); background: var(--accent-tint); }
.ep-chip.on { border-color: var(--accent); background: var(--accent-hover); color: var(--accent-strong); font-weight: 600; }
.ep-chip:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }
.ep-agregar { display: flex; gap: 0.5rem; }
.ep-agregar .input { flex: 1; min-width: 0; }

.ep-prueba { border: 1px solid var(--line); border-left-width: 4px; border-radius: var(--r-md); padding: 0.6rem 0.8rem; margin-bottom: 0.5rem; }
.ep-prueba.ok { border-left-color: var(--ok); }
.ep-prueba.info { border-left-color: var(--info); }
.ep-prueba.warn { border-left-color: var(--warn-ink); }
.ep-prueba[aria-busy='true'] { opacity: 0.6; }
.ep-pregunta { margin: 0; color: var(--ink-soft); font-size: var(--t-sm); }
.ep-estado { margin: 0.15rem 0 0.35rem; font-weight: 600; font-size: var(--t-base); }
.ep-prueba.ok .ep-estado { color: var(--ok); }
.ep-prueba.warn .ep-estado { color: var(--warn-ink); }
.ep-globo {
  margin: 0.2rem 0; padding: 0.5rem 0.75rem; background: var(--accent-tint); color: var(--ink);
  border-radius: var(--r-md) var(--r-md) var(--r-md) 0.2rem; font-size: var(--t-base);
  white-space: pre-wrap; display: inline-block; max-width: 100%;
}
.ep-choques { margin: 0.3rem 0 0; padding-left: 1.1rem; font-size: var(--t-sm); }
.ep-otra { margin-top: 0.6rem; }

/* Fijo al pie: el modal es largo y hace scroll, y Guardar no puede quedar
   escondido debajo de la vista previa. -1.2rem = el padding de `.modal`. */
.ep-pie {
  flex-wrap: wrap; align-items: center;
  position: sticky; bottom: -1.2rem; margin: 0.4rem -1.2rem -1.2rem;
  padding: 0.75rem 1.2rem; background: var(--surface); border-top: 1px solid var(--line);
}
.ep-pie .ep-ayuda { flex: 1 1 14rem; margin: 0; }
</style>
