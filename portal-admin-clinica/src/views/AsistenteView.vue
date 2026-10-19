<script setup>
/* Setup del asistente de IA.

   WhatsApp y, desde sep-2026, la burbuja de chat del portal de reservas
   (`ChatAsistente.vue`), que llama a `/chatbot/mensaje`. Las dos pasan por la
   misma capa sin IA, así que «Lo que la oficina ya sabe» vale para ambas.


   Lo que se puede tocar acá es CÓMO habla el asistente, no QUÉ tiene
   permitido hacer. Las reglas duras —no inventar horarios ni precios,
   consultar siempre contra el sistema, no mostrar datos de nadie sin sesión
   iniciada— viven fijas en el backend y no se editan desde ninguna pantalla:
   si fueran un textarea más, un borrado accidental las eliminaría y nadie se
   enteraría hasta que un cliente recibiera una hora inventada. El bloque
   "Lo que no se puede cambiar" está en la vista justamente para que eso se
   entienda y nadie lo busque. */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, apiError } from '../api.js';
import EnsenarPreguntaModal from '../components/EnsenarPreguntaModal.vue';
import Paginador from '../components/Paginador.vue';
import BuscadorAvanzado from '../components/BuscadorAvanzado.vue';

const config = ref(null);

/* Pestañas (decisión de producto, 06-oct): eran siete tarjetas seguidas. La
   pestaña vive en la URL (?tab=) para que recargar no devuelva a la primera.
   Todo sigue siendo UN formulario con un solo «Guardar cambios»: pasar de
   pestaña no pierde lo escrito en otra. */
const route = useRoute();
const router = useRouter();
const TABS = [
  { id: 'funcionamiento', label: 'Funcionamiento' },
  { id: 'conocimiento', label: 'Conocimiento' },
  { id: 'habla', label: 'Cómo habla' },
  { id: 'reglas', label: 'Reglas fijas' },
  { id: 'conversaciones', label: 'Conversaciones' },
];
const tab = computed(() => (TABS.some((t) => t.id === route.query.tab) ? route.query.tab : 'funcionamiento'));
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const guardado = ref(false);

/* El backend exige {detalle} en esta frase: lo reemplaza por "no alcancé a
   ver la imagen", "no pude escuchar el audio", etc. Se avisa acá para que el
   error no aparezca recién al guardar. */
const faltaDetalle = computed(
  () => !!config.value && !(config.value.frases.no_texto || '').includes('{detalle}'),
);

/* Cupo diario de la IA. Mismos límites que el backend (TOKENS_DIA_MIN/MAX en
   CatalogoController): sin 0, que sería «sin tope» y solo se abre desde el
   servidor. Se manda SOLO si alguien lo cambió: el valor que llega puede venir
   del .env, y reenviarlo al guardar el tono lo dejaría fijado en la base sin
   que nadie lo decidiera. */
const TOKENS_MIN = 10000;
const TOKENS_MAX = 10000000;
const topeCargado = ref(null);
const topeCambiado = computed(() => !!config.value && config.value.tokens_dia !== topeCargado.value);
const topeInvalido = computed(() => {
  if (!topeCambiado.value) return false;
  const n = config.value.tokens_dia;
  return !Number.isInteger(n) || n < TOKENS_MIN || n > TOKENS_MAX;
});
const bloqueado = computed(() => faltaDetalle.value || topeInvalido.value);

/* Una marca en la pestaña con algo que mirar: el aviso de salud del bot o un
   campo que impide guardar no pueden quedar escondidos en una pestaña que no
   se está viendo. */
const marca = computed(() => ({
  funcionamiento: !!(config.value?.salud?.ultimo_error_envio || config.value?.salud?.ia_cortada || topeInvalido.value),
  habla: faltaDetalle.value,
}));

const miles = (n) => Number(n).toLocaleString('es-CL');
/* Haiku: US$1 por millón de entrada y US$5 de salida; el agente es casi todo
   entrada, así que un millón de tokens queda entre US$1 y US$2. Es un orden de
   magnitud para decidir, no una factura. */
const costoMaximo = computed(() => {
  const n = config.value?.tokens_dia;
  if (!Number.isInteger(n) || n <= 0) return '';
  const f = (x) => x.toLocaleString('es-CL', { maximumFractionDigits: 1 });
  return `US$${f(n / 1e6)} a ${f((2 * n) / 1e6)}`;
});
const porcentajeHoy = computed(() => {
  const c = config.value;
  if (!c?.gasto_hoy || !(topeCargado.value > 0)) return null;
  return Math.round((100 * c.gasto_hoy.tokens) / topeCargado.value);
});

function cuandoFue(iso) {
  if (!iso) return 'recién';
  const min = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
  if (min < 2) return 'hace un momento';
  if (min < 60) return `hace ${min} minutos`;
  const h = Math.round(min / 60);
  return h < 36 ? `hace ${h} hora${h === 1 ? '' : 's'}` : `hace ${Math.round(h / 24)} días`;
}

function recibir(data) {
  config.value = data;
  topeCargado.value = data.tokens_dia;
}

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const { data } = await api.get('/catalogo/asistente');
    recibir(data);
  } catch (e) {
    error.value = apiError(e);
  } finally {
    loading.value = false;
  }
}

/* Devuelve si se guardó: el modal «Enseñarle» decide con eso si se cierra. */
async function guardar() {
  if (bloqueado.value) return false;
  busy.value = true;
  error.value = '';
  guardado.value = false;
  try {
    const { data } = await api.put('/catalogo/asistente', {
      activo_whatsapp: config.value.activo_whatsapp,
      telefono: config.value.telefono,
      tono: config.value.tono,
      instrucciones_extra: config.value.instrucciones_extra,
      frases: config.value.frases,
      datos: {
        direccion: config.value.datos.direccion,
        horario: config.value.datos.horario,
        // Las filas a medio escribir no se mandan: el backend las rechazaría y
        // se perdería todo el guardado por una fila vacía que nadie quiso.
        preguntas: config.value.datos.preguntas.filter((p) => p.claves.trim() && p.respuesta.trim()),
      },
      // undefined = axios no lo manda y el backend conserva lo que había.
      tokens_dia: topeCambiado.value ? config.value.tokens_dia : undefined,
    });
    recibir(data);
    guardado.value = true;
    setTimeout(() => { guardado.value = false; }, 2500);
    return true;
  } catch (e) {
    error.value = apiError(e);
    return false;
  } finally {
    busy.value = false;
  }
}

/* Lo que el chat no supo contestar, para convertirlo en preguntas frecuentes.
   Se carga aparte y no rompe la pantalla si falla: es ayuda, no configuración. */
const preguntas = ref(null);
async function cargarPreguntas() {
  try {
    const { data } = await api.get('/chatbot/preguntas', { params: { dias: 30 } });
    preguntas.value = data;
  } catch {
    preguntas.value = null;
  }
}

/* «Enseñarle» abre un modal con todo listo: palabras sugeridas, respuesta y lo
   que el chat contestaría. Guardar persiste en el acto —no deja una fila más
   esperando el botón de arriba— y recarga la lista, de la que la pregunta sale
   porque ya tiene respuesta. */
const ensenando = ref(null);
const guardandoEnsenar = ref(false);
const errorEnsenar = ref('');
const preguntasLlenas = computed(() => (config.value?.datos.preguntas.length || 0) >= 30);

function abrirEnsenar(p) {
  errorEnsenar.value = '';
  ensenando.value = p;
}
async function guardarEnsenanza(nueva) {
  if (faltaDetalle.value) {
    errorEnsenar.value = 'Antes hay que corregir el aviso de mensaje no leído (le falta {detalle}), más abajo en esta página.';
    return;
  }
  if (topeInvalido.value) {
    errorEnsenar.value = 'Antes hay que corregir el cupo diario de la IA, más arriba en esta página.';
    return;
  }
  guardandoEnsenar.value = true;
  errorEnsenar.value = '';
  config.value.datos.preguntas.push(nueva);
  const ok = await guardar();
  guardandoEnsenar.value = false;
  if (!ok) {
    config.value.datos.preguntas.pop();
    errorEnsenar.value = error.value;
    return;
  }
  ensenando.value = null;
  cargarPreguntas();
}

function agregarPregunta() {
  config.value.datos.preguntas.push({ claves: '', respuesta: '' });
}
function quitarPregunta(i) {
  config.value.datos.preguntas.splice(i, 1);
}

/* Conversaciones — para depurar y analizar el asistente: qué dijo, en qué
   conversación se trabó, qué le preguntaron de verdad. Lista aparte y
   solo-lectura (no es parte del "Guardar cambios" general), se carga recién
   al entrar a la pestaña, no al montar la vista — nadie revisa esto en cada
   visita a Ajustes → Asistente. */
const CAMPOS_CONV = [
  { key: 'usuario', label: 'Nombre del usuario', placeholder: 'Buscar por nombre…' },
  { key: 'telefono', label: 'Teléfono', placeholder: 'Buscar por teléfono…' },
  { key: 'canal', label: 'Canal', type: 'select', options: [{ value: 'web', label: 'Chat web' }, { value: 'whatsapp', label: 'WhatsApp' }] },
  { key: 'desde', label: 'Desde', type: 'date', autocompletar: false },
  { key: 'hasta', label: 'Hasta', type: 'date', autocompletar: false },
];
const conversaciones = ref([]);
const conversacionesCargadas = ref(false);
const cargandoConv = ref(false);
const errorConv = ref('');
const filtrosConv = ref({ usuario: '', telefono: '', canal: '', desde: '', hasta: '' });
const paginaConv = ref(1);
const paginacionConv = ref({ total: 0, total_paginas: 1, limit: 30 });
const abierta = ref(null);
const detalle = ref(null);
const cargandoDetalle = ref(false);
const errorDetalle = ref('');

async function cargarConversaciones() {
  cargandoConv.value = true;
  errorConv.value = '';
  try {
    const params = { page: paginaConv.value, limit: 30 };
    for (const k of ['usuario', 'telefono', 'canal', 'desde', 'hasta']) {
      if (filtrosConv.value[k]) params[k] = filtrosConv.value[k];
    }
    const { data } = await api.get('/chatbot/admin/conversaciones', { params });
    conversaciones.value = data.items;
    paginacionConv.value = { total: data.total, total_paginas: data.total_paginas, limit: data.limit };
  } catch (e) {
    errorConv.value = apiError(e, 'No se pudo cargar el historial de conversaciones.');
  } finally {
    cargandoConv.value = false;
    conversacionesCargadas.value = true;
  }
}

function buscarConv() {
  paginaConv.value = 1;
  cargarConversaciones();
}

function cambiarPaginaConv(p) {
  paginaConv.value = p;
  cargarConversaciones();
}

async function abrirConversacion(id) {
  if (abierta.value === id) {
    abierta.value = null;
    return;
  }
  abierta.value = id;
  detalle.value = null;
  errorDetalle.value = '';
  cargandoDetalle.value = true;
  try {
    const { data } = await api.get(`/chatbot/admin/conversaciones/${id}`);
    detalle.value = data;
  } catch (e) {
    errorDetalle.value = apiError(e, 'No se pudo abrir la conversación.');
  } finally {
    cargandoDetalle.value = false;
  }
}

function fechaConv(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' });
}

/* Se carga la primera vez que alguien entra a la pestaña, no antes. */
function irA(id) {
  router.replace({ query: { ...route.query, tab: id } });
  if (id === 'conversaciones' && !conversacionesCargadas.value) cargarConversaciones();
}

onMounted(() => {
  cargar();
  cargarPreguntas();
  if (tab.value === 'conversaciones') cargarConversaciones();
});
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Asistente IA</h1>
        <p class="page-sub">
          El asistente que responde los WhatsApp y el chat del portal de
          reservas. Con la IA apagada contesta solo lo que la oficina le deja
          escrito abajo; todo lo demás lo deriva a WhatsApp.
        </p>
      </div>
      <button
        v-if="config" class="btn btn-primary"
        :disabled="busy || bloqueado" @click="guardar"
      >{{ busy ? 'Guardando…' : 'Guardar cambios' }}</button>
    </div>

    <p v-if="loading" class="page-sub">Cargando…</p>
    <p v-if="error" class="aviso aviso-error">{{ error }}</p>
    <p v-if="guardado" class="aviso aviso-ok">
      Guardado. Los cambios aplican al próximo mensaje que llegue, sin reiniciar nada.
    </p>

    <template v-if="config">
      <div class="as-tabs" role="tablist" aria-label="Secciones del asistente">
        <button
          v-for="t in TABS" :key="t.id" type="button" role="tab"
          :id="`as-tab-${t.id}`" :aria-selected="tab === t.id" :aria-controls="`as-panel-${t.id}`"
          class="as-tab" :class="{ activa: tab === t.id }" @click="irA(t.id)"
        >
          {{ t.label }}
          <span v-if="marca[t.id]" class="as-marca" title="Hay algo que revisar en esta pestaña">!</span>
        </button>
      </div>

      <section v-show="tab === 'funcionamiento'" id="as-panel-funcionamiento" role="tabpanel" aria-labelledby="as-tab-funcionamiento">
        <!-- Salud del bot. Un token de WhatsApp vencido deja al bot «encendido» y
             mudo: recibe, piensa y no entrega nada, sin ningún error a la vista. -->
        <div v-if="config?.salud?.ultimo_error_envio" class="aviso aviso-error" role="alert">
          <strong>WhatsApp no está entregando mensajes.</strong>
          {{ config.salud.ultimo_error_envio.explicacion }}
          <small class="aviso-detalle">Último error: {{ cuandoFue(config.salud.ultimo_error_envio.at) }}.</small>
        </div>
        <div v-if="config?.salud?.ia_cortada" class="aviso aviso-aviso" role="status">
          <strong>La IA se pausó unos minutos</strong> porque falló varias veces seguidas. Mientras tanto el asistente
          responde solo lo que la oficina le enseñó y manda a llamar en lo demás. Se reactiva sola.
        </div>
        <!-- ── Encendido ── -->
        <div class="card">
          <h2 class="card-titulo">Encendido</h2>
          <p class="card-sub">
            Apagado sigue contestando —dejar a alguien sin respuesta es peor que
            decirle que no estamos— pero con el aviso fijo de más abajo, sin
            consultar a la IA y sin tocar los datos del cliente.
          </p>

          <label class="check">
            <input type="checkbox" v-model="config.activo_whatsapp" />
            <span><strong>WhatsApp</strong> — responde los mensajes que llegan al número de la clínica</span>
          </label>
        </div>

        <!-- ── Cupo diario ── -->
        <div class="card">
          <h2 class="card-titulo">Cupo diario de la IA</h2>
          <p class="card-sub">
            Lo máximo que la IA puede gastar en un día. Al llegar al cupo el
            asistente no se queda callado: sigue contestando lo que la oficina le
            dejó escrito y el resto lo deriva a WhatsApp. Se repone solo a
            medianoche.
          </p>

          <p v-if="config.gasto_hoy" class="campo-hint gasto-hoy">
            <template v-if="topeCargado > 0">
              Hoy van <strong>{{ miles(config.gasto_hoy.tokens) }}</strong> tokens en
              {{ miles(config.gasto_hoy.llamadas) }}
              {{ config.gasto_hoy.llamadas === 1 ? 'llamada' : 'llamadas' }} a la IA:
              <strong>{{ porcentajeHoy }}%</strong> del cupo.
            </template>
            <template v-else>
              Hoy van <strong>{{ miles(config.gasto_hoy.tokens) }}</strong> tokens. El
              servidor tiene la IA sin tope; al guardar un cupo acá, pasa a tenerlo.
            </template>
          </p>
          <p v-if="porcentajeHoy !== null && porcentajeHoy >= 100" class="aviso aviso-error">
            Hoy ya se alcanzó el cupo: la IA vuelve a medianoche, o antes si lo subes.
          </p>

          <label class="campo">
            <span class="campo-label">Tokens por día</span>
            <input v-model.number="config.tokens_dia" type="number" inputmode="numeric"
              :min="TOKENS_MIN" :max="TOKENS_MAX" step="100000"
              :class="{ 'con-error': topeInvalido }" />
            <span class="campo-hint">
              <template v-if="Number.isInteger(config.tokens_dia) && config.tokens_dia > 0">
                {{ miles(config.tokens_dia) }} tokens: como mucho {{ costoMaximo }} al día con el modelo actual.
              </template>
              Entre {{ miles(TOKENS_MIN) }} y {{ miles(TOKENS_MAX) }}. Un mensaje de
              WhatsApp atendido por la IA gasta varios miles, porque el asistente
              consulta horarios y datos antes de contestar.
            </span>
            <span v-if="topeInvalido" class="campo-error">
              El cupo va de {{ miles(TOKENS_MIN) }} a {{ miles(TOKENS_MAX) }} tokens.
            </span>
          </label>
        </div>
      </section>

      <section v-show="tab === 'conocimiento'" id="as-panel-conocimiento" role="tabpanel" aria-labelledby="as-tab-conocimiento">
        <!-- ── Lo que la oficina ya sabe ── -->
        <div class="card">
          <h2 class="card-titulo">Lo que la oficina ya sabe</h2>
          <p class="card-sub">
            Cada pregunta que el asistente contesta solo es un WhatsApp menos para
            la oficina. Se responde tal cual lo escribas acá, sin IA. Lo que dejes
            vacío no se inventa: se deriva a WhatsApp.
          </p>

          <label class="campo">
            <span class="campo-label">Dirección</span>
            <input v-model="config.datos.direccion" type="text" maxlength="200" />
            <span class="campo-hint">Responde «¿dónde quedan?», «¿cómo llego?».</span>
          </label>

          <label class="campo">
            <span class="campo-label">Horario de atención</span>
            <textarea v-model="config.datos.horario" rows="2" maxlength="400"
              placeholder="Ej: Lunes a viernes de 10:00 a 19:00 y sábados de 10:00 a 14:00."></textarea>
            <span class="campo-hint">
              Responde «¿a qué hora abren?», «¿atienden los sábados?». Vacío a
              propósito hasta que la oficina lo escriba: las agendas son por
              especialista y no se resumen solas.
            </span>
          </label>

          <div class="campo">
            <span class="campo-label">Preguntas frecuentes</span>
            <span class="campo-hint">
              Si el mensaje contiene alguna de las palabras clave, se envía la
              respuesta. Si calza con dos preguntas a la vez, no se contesta
              ninguna. No sirven para agendar, cancelar ni pagar: eso siempre va a
              una persona.
            </span>
            <div v-for="(p, i) in config.datos.preguntas" :key="i" class="pregunta">
              <label class="campo">
                <span class="campo-label">Palabras clave</span>
                <input v-model="p.claves" type="text" maxlength="300"
                  placeholder="Ej: estacionamiento, estacionar" />
              </label>
              <label class="campo">
                <span class="campo-label">Respuesta</span>
                <textarea v-model="p.respuesta" rows="2" maxlength="800"></textarea>
              </label>
              <button type="button" class="btn btn-ghost peligro" @click="quitarPregunta(i)">Quitar</button>
            </div>
            <button type="button" class="btn btn-ghost" @click="agregarPregunta">+ Agregar pregunta</button>
          </div>
        </div>

        <!-- ── Lo que preguntaron ── -->
        <div v-if="preguntas" class="card">
          <h2 class="card-titulo">Lo que preguntaron en el chat</h2>
          <p class="card-sub">
            Últimos {{ preguntas.dias }} días:
            <strong>{{ preguntas.total }}</strong> preguntas,
            <template v-if="preguntas.porcentaje !== null">
              <strong>{{ preguntas.porcentaje }}%</strong> contestadas sin la oficina.
            </template>
            No se guarda quién preguntó, y se borran teléfonos, correos y RUT.
            Un nombre escrito en el mensaje sí queda.
          </p>
          <p v-if="preguntas.aviso" class="aviso aviso-error">{{ preguntas.aviso }}</p>
          <p v-else-if="!preguntas.sin_respuesta.length" class="campo-hint">
            Todavía no hay preguntas sin contestar.
          </p>
          <p v-else class="campo-hint">
            Lo que el chat todavía no sabe contestar. Al enseñarle una, sale de la lista.
          </p>
          <ul v-if="preguntas.sin_respuesta.length" class="sin-respuesta">
            <li v-for="(p, i) in preguntas.sin_respuesta" :key="i">
              <span class="sr-texto">«{{ p.texto }}»</span>
              <span class="sr-veces">{{ p.veces }} {{ p.veces === 1 ? 'vez' : 'veces' }}</span>
              <button type="button" class="btn btn-ghost" @click="abrirEnsenar(p)">Enseñarle</button>
            </li>
          </ul>
        </div>
      </section>

      <section v-show="tab === 'habla'" id="as-panel-habla" role="tabpanel" aria-labelledby="as-tab-habla">
        <!-- ── Cómo habla ── -->
        <div class="card">
          <h2 class="card-titulo">Cómo habla</h2>

          <label class="campo">
            <span class="campo-label">Tono</span>
            <textarea v-model="config.tono" rows="3"></textarea>
            <span class="campo-hint">
              Cómo se dirige a la gente. Por ejemplo: «Trata de usted, sé formal
              y directo» o «Habla cercano, como en el mostrador».
            </span>
          </label>

          <label class="campo">
            <span class="campo-label">Teléfono que entrega</span>
            <input v-model="config.telefono" type="text" placeholder="+569 0000 0000" />
            <span class="campo-hint">
              El número que da cuando alguien pide hablar con una persona. Si lo
              dejas vacío usa el que está configurado en el sistema.
            </span>
          </label>

          <label class="campo">
            <span class="campo-label">Indicaciones adicionales</span>
            <textarea v-model="config.instrucciones_extra" rows="4"
              placeholder="Ej: En agosto hay 20% de descuento en peluquería. La Dra. Soto no atiende los viernes."></textarea>
            <span class="campo-hint">
              Para lo que cambia seguido: promociones, convenios, avisos de
              temporada. Se suma a lo que el asistente ya sabe.
            </span>
          </label>
        </div>

        <!-- ── Frases ── -->
        <div class="card">
          <h2 class="card-titulo">Frases</h2>
          <p class="card-sub">
            Las tres primeras se envían tal cual, sin pasar por la IA. La última
            es una indicación que el asistente interpreta con sus palabras.
          </p>

          <label class="campo">
            <span class="campo-label">Cuando llega algo que no es texto</span>
            <textarea v-model="config.frases.no_texto" rows="3"
              :class="{ 'con-error': faltaDetalle }"></textarea>
            <span class="campo-hint">
              Se envía cuando alguien manda una foto, un audio o un documento.
              Tiene que incluir <code>{detalle}</code>, que se reemplaza por lo
              que llegó («no alcancé a ver la imagen», «no pude escuchar el audio»).
            </span>
            <span v-if="faltaDetalle" class="campo-error">
              Falta <code>{detalle}</code> — sin eso el cliente no sabe qué fue lo que no se pudo leer.
            </span>
          </label>

          <label class="campo">
            <span class="campo-label">Si hay un problema técnico</span>
            <textarea v-model="config.frases.error_tecnico" rows="2"></textarea>
          </label>

          <label class="campo">
            <span class="campo-label">Si el canal está apagado</span>
            <textarea v-model="config.frases.apagado" rows="2"></textarea>
          </label>

          <label class="campo">
            <span class="campo-label">Cuando le preguntan algo médico</span>
            <textarea v-model="config.frases.derivar" rows="3"></textarea>
            <span class="campo-hint">
              Indicación, no texto literal: el asistente la aplica con sus
              palabras según la conversación.
            </span>
          </label>
        </div>
      </section>

      <section v-show="tab === 'reglas'" id="as-panel-reglas" role="tabpanel" aria-labelledby="as-tab-reglas">
        <!-- ── Límites ── -->
        <div class="card card-fijo">
          <h2 class="card-titulo">Lo que no se puede cambiar</h2>
          <p class="card-sub">
            Estas reglas están fijas a propósito. Son las que evitan que el
            asistente invente datos o hable de más, así que no se editan desde
            acá ni por error.
          </p>
          <ul class="lista-fija">
            <li>Nunca inventa horarios, precios ni datos: siempre los consulta en el sistema.</li>
            <li>Antes de agendar confirma mascota, consulta, especialista y fecha.</li>
            <li>No muestra citas, mascotas ni pagos de nadie que no haya iniciado sesión, aunque le den un correo.</li>
            <li>Por WhatsApp la identidad se da por válida porque el número lo verifica Meta.</li>
          </ul>
        </div>
      </section>

      <section v-show="tab === 'conversaciones'" id="as-panel-conversaciones" role="tabpanel" aria-labelledby="as-tab-conversaciones">
        <!-- ── Historial de conversaciones ── -->
        <div class="card">
          <div class="conv-head">
            <div>
              <h2 class="card-titulo">Conversaciones</h2>
              <p class="card-sub">
                Para depurar y analizar al asistente: qué dijo, en qué conversación
                se trabó, qué le preguntaron de verdad. El texto de la persona ya
                llega anonimizado (sin correo, RUT ni teléfono propio). No se
                actualiza sola: si alguien está chateando ahora, usa «Actualizar»
                para traer lo nuevo.
              </p>
            </div>
            <button type="button" class="btn btn-ghost" :disabled="cargandoConv" @click="cargarConversaciones">
              {{ cargandoConv ? 'Actualizando…' : 'Actualizar' }}
            </button>
          </div>

          <BuscadorAvanzado v-model="filtrosConv" :campos="CAMPOS_CONV" @buscar="buscarConv" />

          <p v-if="errorConv" class="aviso aviso-error">{{ errorConv }}</p>
          <p v-if="cargandoConv" class="campo-hint">Cargando…</p>
          <p v-else-if="!conversaciones.length" class="campo-hint">
            No hay conversaciones con estos filtros.
          </p>

          <div v-else class="tabla-conv">
            <div class="tc-head tc-row">
              <span>Última actividad</span><span>Quién</span><span>Canal</span><span>Intercambios</span><span></span>
            </div>
            <template v-for="c in conversaciones" :key="c.id">
              <div class="tc-row tc-row-link" @click="abrirConversacion(c.id)">
                <span class="tc-cuando">{{ fechaConv(c.updated) }}</span>
                <span class="tc-quien">
                  <strong>{{ c.usuario }}</strong>
                  <small v-if="c.telefono">{{ c.telefono }}</small>
                </span>
                <span class="chip" :class="c.canal === 'whatsapp' ? 'chip-whatsapp' : 'chip-web'">
                  {{ c.canal === 'whatsapp' ? 'WhatsApp' : 'Chat web' }}
                </span>
                <span>{{ c.mensajes }}</span>
                <span class="tc-flecha" :class="{ abierta: abierta === c.id }" aria-hidden="true">›</span>
              </div>
              <div v-if="abierta === c.id" class="tc-detalle">
                <p v-if="cargandoDetalle" class="campo-hint">Cargando conversación…</p>
                <p v-else-if="errorDetalle" class="aviso aviso-error">{{ errorDetalle }}</p>
                <template v-else-if="detalle">
                  <div v-if="detalle.estado" class="tc-estado">
                    <strong>Estado:</strong> <code>{{ JSON.stringify(detalle.estado) }}</code>
                  </div>
                  <p v-if="!detalle.messages.length" class="campo-hint">Sin mensajes.</p>
                  <ul v-else class="tc-mensajes">
                    <li v-for="(m, i) in detalle.messages" :key="i" :class="`tc-msg tc-msg-${m.role}`">
                      <span class="tc-rol">{{ m.role === 'user' ? 'Persona' : 'Asistente' }}</span>
                      <span class="tc-texto">{{ m.content }}</span>
                    </li>
                  </ul>
                </template>
              </div>
            </template>
          </div>
          <Paginador
            v-if="!cargandoConv && conversaciones.length"
            :page="paginaConv" :total-paginas="paginacionConv.total_paginas"
            :total="paginacionConv.total" :limit="paginacionConv.limit"
            @cambiar="cambiarPaginaConv"
          />
        </div>
      </section>

      <!-- Segundo botón: las frases quedan al final de una página larga, y
           obligar a subir hasta arriba para guardarlas es pedir que alguien
           pierda lo que escribió. -->
      <!-- En «Reglas fijas» y «Conversaciones» no hay nada que guardar. -->
      <div v-if="tab !== 'reglas' && tab !== 'conversaciones'" class="pie-acciones">
        <span v-if="faltaDetalle" class="campo-error">
          Revisa el aviso de mensaje no leído: le falta <code>{detalle}</code>.
        </span>
        <span v-if="topeInvalido" class="campo-error">
          Revisa el cupo diario de la IA: va de {{ miles(TOKENS_MIN) }} a {{ miles(TOKENS_MAX) }} tokens.
        </span>
        <button class="btn btn-primary" :disabled="busy || bloqueado" @click="guardar">
          {{ busy ? 'Guardando…' : 'Guardar cambios' }}
        </button>
      </div>
    </template>

    <EnsenarPreguntaModal
      v-if="ensenando" :pregunta="ensenando" :busy="guardandoEnsenar"
      :error="errorEnsenar" :lleno="preguntasLlenas"
      @guardar="guardarEnsenanza" @close="ensenando = null"
    />
  </div>
</template>

<style scoped>
.card + .card { margin-top: 1.1rem; }
.sin-respuesta { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.4rem; }
.sin-respuesta li { display: flex; align-items: center; gap: 0.7rem; flex-wrap: wrap; }
.sr-texto { flex: 1; min-width: 12rem; }
.sr-veces { color: var(--ink-soft); font-size: var(--t-sm); }
.pregunta { border: 1px solid var(--line); border-radius: var(--r-md); padding: 0.8rem; margin: 0.6rem 0; }
.card-titulo { font-size: var(--t-lg); margin: 0 0 0.25rem; }
.card-sub { color: var(--ink-soft); font-size: var(--t-base); margin: 0 0 1.1rem; line-height: 1.5; }

.check { display: flex; align-items: flex-start; gap: 0.6rem; padding: 0.5rem 0; cursor: pointer; }
.check input { margin-top: 0.2rem; flex: none; }
.check span { font-size: var(--t-base); line-height: 1.45; }

.campo { display: block; margin-bottom: 1.3rem; }
.campo:last-child { margin-bottom: 0; }
.campo-label { display: block; font-weight: 600; font-size: var(--t-base); margin-bottom: 0.35rem; }
.campo input, .campo textarea {
  width: 100%; padding: 0.6rem 0.7rem;
  border: 1.5px solid var(--line); border-radius: 8px;
  font: inherit; font-size: var(--t-base); background: #fff;
  resize: vertical;
}
.campo input:focus, .campo textarea:focus {
  outline: none; border-color: var(--accent);
}
.campo textarea.con-error, .campo input.con-error { border-color: #c0392b; }
.campo input[type="number"] { max-width: 14rem; }
.gasto-hoy { font-size: var(--t-base); margin: 0 0 1rem; }
.campo-hint { display: block; color: var(--ink-soft); font-size: var(--t-sm); margin-top: 0.35rem; line-height: 1.45; }
.campo-error { display: block; color: #c0392b; font-size: var(--t-sm); margin-top: 0.3rem; }
code { background: #eef1fb; padding: 0.05em 0.35em; border-radius: 4px; font-size: 0.9em; }

/* Bloque de solo lectura: se distingue del resto para que se lea como
   información y no como un formulario que alguien deba completar. */
.card-fijo { background: #f7f9fc; }

/* Pestañas: mismo aspecto que las de horarios en la ficha del especialista. */
.as-tabs { display: flex; gap: 0.4rem; flex-wrap: wrap; border-bottom: 1px solid var(--line); padding-bottom: 0.6rem; margin-bottom: 1rem; }
.as-tab {
  display: inline-flex; align-items: center; gap: 0.45rem;
  padding: 0.45em 1em; border-radius: var(--r-md);
  border: 1.5px solid var(--line-strong); background: var(--surface);
  font-weight: 600; font-size: var(--t-base); color: var(--ink-soft);
}
.as-tab:hover { border-color: var(--accent); color: var(--accent-strong); background: var(--accent-tint); }
.as-tab.activa { border-color: var(--brand); background: var(--brand-tint); color: var(--brand-ink); }
.as-tab:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
/* «!» y no solo un punto de color: el title dice qué significa. */
.as-marca {
  display: inline-grid; place-items: center; width: 1.15rem; height: 1.15rem; border-radius: 50%;
  background: var(--danger); color: #fff; font-size: var(--t-micro); font-weight: 700; line-height: 1;
}
.lista-fija { margin: 0; padding-left: 1.15rem; }
.lista-fija li { font-size: var(--t-base); line-height: 1.55; margin-bottom: 0.4rem; color: var(--ink-soft); }

.pie-acciones {
  display: flex; align-items: center; justify-content: flex-end;
  gap: 1rem; flex-wrap: wrap; margin-top: 1.1rem;
}

.aviso { padding: 0.7rem 0.9rem; border-radius: 8px; font-size: var(--t-base); margin-bottom: 1rem; }
.aviso-error { background: #fdecea; color: #a5281b; }
.aviso-ok { background: #e6f7f9; color: #056773; }
.aviso-aviso { background: var(--warn-tint); color: var(--warn-ink); }
.aviso-detalle { display: block; margin-top: 0.25rem; opacity: 0.85; }

/* ── Conversaciones ── */
.conv-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.conv-head .card-sub { margin-bottom: 0.8rem; }
.conv-head .btn { flex: none; }
.tabla-conv { border: 1px solid var(--line); border-radius: var(--r-md); overflow: hidden; }
.tc-row {
  display: grid; grid-template-columns: 1.1fr 1.3fr 0.8fr 0.9fr auto;
  gap: 0.6rem; align-items: center; padding: 0.6rem 0.9rem; min-width: 640px;
}
.tc-head { font-size: var(--t-sm); font-weight: 600; color: var(--ink-soft); background: var(--bg); }
.tc-row:not(.tc-head) { border-top: 1px solid var(--line); }
.tc-row-link { cursor: pointer; }
.tc-row-link:hover { background: var(--accent-tint); }
.tc-cuando { color: var(--ink-soft); font-variant-numeric: tabular-nums; }
.tc-quien { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
.tc-quien strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tc-quien small { color: var(--ink-soft); font-size: var(--t-micro); }
.chip { padding: 0.2em 0.7em; border-radius: 999px; font-size: var(--t-micro); font-weight: 600; justify-self: start; }
.chip-whatsapp { background: #def7e5; color: #1b7a3d; }
.chip-web { background: var(--info-tint); color: var(--info); }
.tc-flecha { color: var(--ink-soft); font-size: 1.1rem; transition: transform 0.15s; justify-self: end; }
.tc-flecha.abierta { transform: rotate(90deg); }
.tc-detalle { padding: 0.9rem; border-top: 1px solid var(--line); background: var(--bg); }
.tc-estado { font-size: var(--t-sm); margin-bottom: 0.7rem; color: var(--ink-soft); }
.tc-estado code { word-break: break-all; }
.tc-mensajes { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
.tc-msg { max-width: 34rem; padding: 0.5rem 0.8rem; border-radius: var(--r-md); font-size: var(--t-sm); line-height: 1.45; }
.tc-msg .tc-rol { display: block; font-size: var(--t-micro); font-weight: 600; opacity: 0.7; margin-bottom: 0.15rem; }
.tc-msg .tc-texto { white-space: pre-wrap; }
.tc-msg-user { background: #fff; border: 1px solid var(--line); align-self: flex-start; }
.tc-msg-assistant { background: var(--brand-tint); color: var(--brand-ink); align-self: flex-end; }

@media (max-width: 760px) { .tc-row { min-width: 0; grid-template-columns: 1fr 1fr auto; } .tc-row > span:nth-child(3), .tc-row > span:nth-child(4) { display: none; } }
</style>
