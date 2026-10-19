<script setup>
/* El asistente, en una burbuja. Habla con `POST /chatbot/mensaje`.
 *
 * DISEÑO: no trae colores propios. Usa los tokens de `app.css`, que son los
 * mismos del sitio público (turquesa medio #087c8a, azul #26326e, Poppins para
 * títulos y Open Sans para el texto), así que el día que se copie al `website`
 * —no hay build compartido, §9.5— se ve igual sin tocar una línea.
 *
 * TAPA POCO, Y ESO MANDA EN EL TAMAÑO. Cerrado es un botón de 56px en una
 * esquina. Abierto, en escritorio, es un panel de 400px anclado abajo a la
 * derecha: la página se sigue leyendo al lado. En un teléfono un panel flotante
 * no cabe sin pelearse con el pulgar, así que ahí ocupa el ancho completo y
 * como MUCHO el 86% del alto (`min(86svh, 700px)`), pegado abajo. `svh` y no
 * `vh` a propósito: con `vh`, la barra del navegador móvil deja el campo de
 * texto debajo del borde visible justo al escribir, que es cuando importa.
 *
 * SALUDO CORTO Y OPCIONES (sep-2026). El saludo anterior era un párrafo que
 * explicaba qué sabía hacer el chat; nadie lo lee, y dejaba a la persona frente
 * a un campo vacío adivinando qué escribir. Ahora es una línea y cinco botones.
 * Cada botón MANDA UNA FRASE al mismo endpoint —no trae una respuesta escrita
 * acá—, así que lo que contesta sigue saliendo de un solo lugar
 * (`ChatDeterminista`) y nunca puede quedar desfasado de lo que dice el backend.
 * Solo hay botones para lo que la capa sin IA contesta de verdad: esas frases
 * están en `api-laravel/tests/Fixtures/chat_corpus.php`. Si se agrega una opción,
 * va también ahí; si no, el botón puede terminar en «escríbenos por WhatsApp»,
 * que es justo lo que el botón promete evitar. «¿A qué hora abren?» NO está:
 * el horario nace vacío (§4) y hoy deriva.
 *
 * LO QUE NO SABE, A WHATSAPP. Con `ASISTENTE_IA_ACTIVO=false` (decisión de la
 * clínica, 07-sep-2026) lo que la capa determinista no resuelve se deriva. Por
 * eso el botón de WhatsApp NO está escondido en un menú: está en la cabecera,
 * a un toque, en todo momento.
 *
 * ASISTENTE DE RESERVAS (sep-2026). El backend puede devolver `acciones`:
 * botones con una ruta RELATIVA del portal (`/reservar?servicio=…&hora=…`,
 * `/citas`). Se navega con `location.assign` y no con el router, a propósito:
 * `/` y `/reservar` montan el mismo ReservarView, y reusar la instancia no
 * vuelve a correr `precargarDesdeUrl`, así que el botón no preseleccionaría
 * nada. Una carga completa lo garantiza. Los botones NUNCA reservan: dejan a
 * la persona en el paso de confirmar, o en `/pago/{cita_id}` y `/citas`.
 *
 * LA IA SÍ RESERVA, PERO NO DESDE ACÁ (sep-2026). Con `ASISTENTE_IA_ACTIVO`
 * encendido, `AgenteIA` puede crear la cita por texto, en dos mensajes de la
 * persona: prepara la reserva y muestra el aviso de privacidad, y recién con
 * el «sí» del mensaje siguiente llama a la misma puerta que POST
 * /citas/reservar. Todo eso ocurre en el backend; este componente no tiene
 * ningún camino que reserve, y lo que recibe después son botones más
 * («Pagar abono», «Ir a Mis citas»). Con la IA apagada, nada de esto existe.
 *
 * SE ABRE SOLO AL TOCARLO (12-oct-2026, decisión de producto). Entre el 06-oct y el
 * 12-oct se abría solo al entrar, para ofrecer «Reservar una hora» como camino
 * de entrada; se volvió atrás: abierto de entrada tapa el formulario justo
 * cuando la persona está eligiendo (en un teléfono, hasta el 86% del alto).
 * Ahora aparece cerrado, como el botón de la esquina, y se abre solo con un
 * toque.
 *
 * ASISTE EN TODO, COMO WHATSAPP (oct-2026, decisión de producto). Dos piezas:
 *
 * - **Identidad**: si hay sesión del portal, `api.js` YA manda el
 *   `Authorization: Bearer` en cada request —nada que hacer acá—, y el
 *   backend (`AgenteIA`) la usa igual que el teléfono verificado de WhatsApp
 *   para mis_citas/cambiar/cancelar/pagar/adjuntar orden. Sin sesión, sigue
 *   negándolas y deriva a `/citas` o WhatsApp, como siempre.
 * - **Adjuntar la orden médica**: el clip junto al campo de texto. Mismas
 *   reglas que el resto del sistema (foto o PDF, 10 MB) — las valida el
 *   backend (`AdjuntosWeb`) igual, esto es solo para no hacer esperar una
 *   subida que ya se sabe que va a fallar. El archivo viaja en el MISMO
 *   `POST /chatbot/mensaje` (multipart), junto con el texto si lo hay: no es
 *   una subida aparte con su propio id que haya que acarrear.
 */
import { ref, nextTick, computed } from 'vue';
import { api } from '../api.js';
import { CLINICA } from '../config.js';
import { bloques, conMontos, gruposDeAcciones } from './chatFormato.js';

/* `texto` es lo que se ve en el botón y en la burbuja; `mensaje`, lo que viaja
   al backend — escrito como lo escribiría una persona, que es lo que el corpus
   mide. */
const OPCIONES = [
  { texto: 'Reservar una hora', mensaje: 'Quiero reservar una hora' },
  { texto: 'Ver valores', mensaje: '¿Qué valores tienen?' },
  { texto: '¿Qué atienden?', mensaje: '¿Qué servicios tienen?' },
  { texto: '¿Dónde están?', mensaje: '¿Dónde están ubicados?' },
  { texto: 'Cambiar o cancelar mi hora', mensaje: 'Quiero cambiar o cancelar mi hora' },
];

const abierto = ref(false);
const texto = ref('');
const enviando = ref(false);

/* El archivo elegido, pendiente de mandarse con el próximo mensaje (o solo,
   como «Te mando un archivo.»). Mismos tipos y tope que valida el backend
   (AdjuntosWeb): esto es nada más para no hacer esperar una subida que ya se
   sabe que va a fallar. */
const TIPOS_ARCHIVO = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
const ARCHIVO_MAX_BYTES = 10 * 1024 * 1024;
const archivoInput = ref(null);
const archivo = ref(null);
const archivoError = ref('');

function elegirArchivo() {
  archivoError.value = '';
  archivoInput.value?.click();
}

function archivoElegido(e) {
  const f = e.target.files?.[0] || null;
  e.target.value = ''; // si vuelve a elegir el mismo archivo, que dispare @change igual
  if (!f) return;
  if (!TIPOS_ARCHIVO.includes(f.type)) {
    archivo.value = null;
    archivoError.value = 'La orden la puedo recibir como foto o como PDF.';
    return;
  }
  if (f.size > ARCHIVO_MAX_BYTES) {
    archivo.value = null;
    archivoError.value = 'El archivo pesa más de 10 MB.';
    return;
  }
  archivoError.value = '';
  archivo.value = f;
}

function quitarArchivo() {
  archivo.value = null;
  archivoError.value = '';
}
/* El hilo con el backend. Vacío = lo que respondió no continúa ninguna
   conversación (la capa determinista no abre una), y así lo manda de vuelta. */
const conversationId = ref('');
/* Un mensaje del asistente trae ya su forma de dibujarse (chatFormato.js):
   se calcula una vez al llegar, no en cada render. `ancho` suelta el tope de
   85% cuando hay una tabla o una grilla, que en una burbuja angosta se apilan. */
function delBot(textoBot, acciones = []) {
  const b = bloques(textoBot);
  const grupos = gruposDeAcciones(acciones);
  const ancho = b.some((x) => x.tipo === 'precios' || x.tipo === 'lista')
    || grupos.some((g) => g.tipo === 'horas' || g.tipo === 'opciones');
  return { de: 'bot', texto: textoBot, vista: { bloques: b, grupos, ancho } };
}

const mensajes = ref([delBot('¡Hola! ¿Te ayudo a reservar una hora para tu mascota?')]);
const historial = ref(null);

/* Antes del primer mensaje las opciones van bajo el saludo, grandes. Después
   bajan a una fila compacta sobre el campo de texto: siguen a un toque, sin
   repetirse en el hilo después de cada respuesta. */
const empezo = computed(() => mensajes.value.some((m) => m.de === 'yo'));

const waUrl = computed(() => {
  const ultimo = [...mensajes.value].reverse().find((m) => m.de === 'yo');
  const base = `https://wa.me/${CLINICA.telefonoPlano}`;
  return ultimo ? `${base}?text=${encodeURIComponent(ultimo.enviado || ultimo.texto)}` : base;
});

function irA(ruta) {
  // Solo rutas del propio portal: una acción no puede sacar a nadie del sitio.
  if (typeof ruta === 'string' && ruta.startsWith('/') && !ruta.startsWith('//')) {
    window.location.assign(ruta);
  }
}

async function alFondo() {
  await nextTick();
  const el = historial.value;
  if (el) el.scrollTop = el.scrollHeight;
}

/* Tras una respuesta: si cabe entera, al fondo como siempre. Si es más alta que
   el hilo (la lista de valores, doce consultas), se deja ver su COMIENZO junto
   con la pregunta: ir al fondo mostraba solo la cola, y había que subir a
   buscar de qué se trataba. */
async function aLaRespuesta() {
  await nextTick();
  const el = historial.value;
  if (!el) return;
  const turnos = el.querySelectorAll('.chat-turno');
  const ultimo = turnos[turnos.length - 1];
  const pregunta = turnos[turnos.length - 2];
  if (!ultimo || ultimo.offsetHeight <= el.clientHeight - 24) {
    el.scrollTo({ top: el.scrollHeight, behavior: suave() });
    return;
  }
  const desde = pregunta && pregunta.classList.contains('yo') ? pregunta : ultimo;
  el.scrollTo({ top: desde.offsetTop - 12, behavior: suave() });
}

function suave() {
  return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
}

async function abrir() {
  abierto.value = true;
  await alFondo();
}

function cerrar() {
  abierto.value = false;
}

async function preguntar(visible, enviado) {
  const adjunto = archivo.value;
  if ((!enviado && !adjunto) || enviando.value) return;
  // Con archivo y sin texto, la burbuja propia muestra el nombre — igual que
  // por WhatsApp, donde una foto sola igual deja rastro de qué se mandó.
  mensajes.value.push({ de: 'yo', texto: visible || `📎 ${adjunto.name}`, enviado });
  archivo.value = null;
  enviando.value = true;
  await alFondo();
  try {
    let data;
    if (adjunto) {
      const form = new FormData();
      if (enviado) form.append('texto', enviado);
      if (conversationId.value) form.append('conversation_id', conversationId.value);
      form.append('archivo', adjunto);
      ({ data } = await api.post('/chatbot/mensaje', form));
    } else {
      ({ data } = await api.post('/chatbot/mensaje', {
        texto: enviado,
        ...(conversationId.value ? { conversation_id: conversationId.value } : {}),
      }));
    }
    conversationId.value = data.conversation_id || '';
    mensajes.value.push(delBot(data.respuesta, data.acciones || []));
  } catch (e) {
    // El 429 tiene su propio texto: "muchos mensajes seguidos" es accionable
    // —esperar—, y un "no pudimos responder" ahí haría pensar que está roto.
    mensajes.value.push(delBot(e.response?.status === 429
      ? 'Estás escribiendo muy seguido. Espera un momento y vuelve a intentar.'
      : 'No pudimos responder ahora. Escríbenos por WhatsApp y te contestamos al tiro.'));
  } finally {
    enviando.value = false;
    await aLaRespuesta();
  }
}

function enviar() {
  const t = texto.value.trim();
  if ((!t && !archivo.value) || enviando.value) return;
  texto.value = '';
  preguntar(t, t);
}

function elegir(op) {
  preguntar(op.texto, op.mensaje);
}
</script>

<template>
  <!-- Cerrado: solo el botón. -->
  <button
    v-if="!abierto"
    type="button" class="chat-fab"
    aria-label="Abrir el asistente"
    @click="abrir"
  >
    <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true">
      <path
        fill="currentColor"
        d="M12 3C6.5 3 2 6.9 2 11.7c0 2.4 1.1 4.6 3 6.2-.2 1.3-.8 2.5-1.7 3.4-.2.2-.1.6.2.6 1.9 0 3.6-.7 4.8-1.6 1.1.3 2.4.5 3.7.5 5.5 0 10-3.9 10-8.7S17.5 3 12 3zm-4.5 10a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6zm4.5 0a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6zm4.5 0a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6z"
      />
    </svg>
  </button>

  <!-- Abierto: el panel. `role=dialog` y no un modal: no bloquea la página,
       que es justamente la gracia — se puede seguir eligiendo hora detrás. -->
  <Transition name="chat" appear>
    <section v-if="abierto" class="chat-panel" role="dialog" :aria-label="`Asistente de ${CLINICA.nombre}`">
      <header class="chat-head">
        <img class="chat-avatar" src="/isotipo.webp" alt="" width="40" height="40" />
        <div class="chat-head-txt">
          <strong>Asistente {{ CLINICA.nombre }}</strong>
          <span><i class="chat-punto" aria-hidden="true"></i>Responde al instante</span>
        </div>
        <!-- Siempre visible: es la salida real de todo lo que el asistente no resuelve. -->
        <a class="chat-wa" :href="waUrl" target="_blank" rel="noopener" aria-label="Hablar por WhatsApp" title="Hablar por WhatsApp">
          <svg viewBox="0 0 32 32" width="18" height="18" aria-hidden="true">
            <path
              fill="currentColor"
              d="M16 .5C7.4.5.5 7.4.5 16c0 2.8.7 5.4 2 7.8L.5 31.5l7.9-2c2.3 1.2 4.9 1.9 7.6 1.9 8.6 0 15.5-6.9 15.5-15.5S24.6.5 16 .5zm0 28.3c-2.4 0-4.7-.6-6.7-1.8l-.5-.3-4.7 1.2 1.3-4.6-.3-.5c-1.3-2.1-2-4.5-2-7 0-7.2 5.9-13 13.1-13S29 8.8 29 16s-5.9 12.8-13 12.8zm7.2-9.6c-.4-.2-2.3-1.1-2.7-1.3-.4-.1-.6-.2-.9.2s-1 1.3-1.2 1.5c-.2.2-.4.3-.8.1-.4-.2-1.7-.6-3.2-2-1.2-1-2-2.3-2.2-2.7-.2-.4 0-.6.2-.8.2-.2.4-.4.5-.7.2-.2.2-.4.4-.6.1-.3.1-.5 0-.7-.1-.2-.9-2.1-1.2-2.9-.3-.8-.6-.7-.9-.7h-.7c-.2 0-.6.1-.9.5-.3.4-1.2 1.2-1.2 2.9s1.2 3.4 1.4 3.6c.2.2 2.5 3.8 6 5.3.8.4 1.5.6 2 .7.8.3 1.6.2 2.2.1.7-.1 2.3-.9 2.6-1.8.3-.9.3-1.6.2-1.8-.1-.2-.3-.3-.7-.4z"
            />
          </svg>
          <span>WhatsApp</span>
        </a>
        <button type="button" class="chat-cerrar" aria-label="Cerrar el asistente" @click="cerrar">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
            <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" />
          </svg>
        </button>
      </header>

      <div ref="historial" class="chat-hist" aria-live="polite">
        <div
          v-for="(m, i) in mensajes" :key="i"
          class="chat-turno" :class="m.de" :data-turno="i"
        >
          <p v-if="m.de === 'yo'" class="chat-msg yo">{{ m.texto }}</p>

          <div v-else class="chat-msg bot" :class="{ ancho: m.vista.ancho }">
            <template v-for="(b, k) in m.vista.bloques" :key="k">
              <p v-if="b.tipo === 'p'" class="chat-p">
                <template v-for="(t, n) in b.trozos" :key="n"><strong v-if="t.monto" class="chat-monto">{{ t.t }}</strong><template v-else>{{ t.t }}</template></template>
              </p>

              <p v-else-if="b.tipo === 'nota'" class="chat-nota">
                <template v-for="(t, n) in b.trozos" :key="n">{{ t.t }}</template>
              </p>

              <!-- Valores: una fila por consulta, el nombre arriba y la tarifa
                   debajo, alineada; con dos tarifas, cada día en su columna. -->
              <ul v-else-if="b.tipo === 'precios'" class="chat-precios">
                <li v-if="b.items.some((it) => it.dias)" class="chat-precio chat-precio-cab" aria-hidden="true">
                  <span></span><span>Lun a vie</span><span>Sábado</span>
                </li>
                <li v-for="(it, n) in b.items" :key="n" class="chat-precio" :class="{ 'sin-dias': !it.dias }">
                  <span class="chat-precio-nombre">{{ it.nombre }}</span>
                  <span v-if="it.dias" class="chat-precio-dias">
                    <span v-for="d in it.dias" :key="d.dia" class="chat-dia">
                      <span class="chat-dia-etq">{{ d.dia }}</span>
                      <strong class="chat-monto">{{ d.monto }}</strong>
                    </span>
                  </span>
                  <span v-else class="chat-precio-valor">
                    <template v-for="(t, q) in conMontos(it.valor)" :key="q"><strong v-if="t.monto" class="chat-monto">{{ t.t }}</strong><template v-else>{{ t.t }}</template></template>
                  </span>
                </li>
              </ul>

              <template v-else-if="b.tipo === 'lista'">
                <p class="chat-p">{{ b.titulo }}:</p>
                <ul class="chat-lista">
                  <li v-for="(it, n) in b.items" :key="n">{{ it }}</li>
                </ul>
              </template>
            </template>
          </div>

          <!-- Acciones que devuelve el backend, cada grupo con su forma (chatFormato.js). -->
          <div v-if="m.vista && m.vista.grupos.length" class="chat-acciones">
            <template v-for="(g, k) in m.vista.grupos" :key="k">
              <div v-if="g.tipo === 'horas'" class="chat-horas">
                <button
                  v-for="(a, j) in g.items" :key="j"
                  type="button" class="chat-hora" @click="irA(a.ruta)"
                  :aria-label="`Reservar ${a.servicio ? a.servicio + ', ' : ''}${a.dia} a las ${a.hora} con ${a.quien}`"
                >
                  <span class="chat-hora-hora">{{ a.hora }}</span>
                  <span class="chat-hora-dia">{{ a.dia }}<template v-if="a.servicio"> · {{ a.servicio }}</template></span>
                  <span class="chat-hora-quien">{{ a.quien }}</span>
                </button>
              </div>

              <div v-else-if="g.tipo === 'opciones'" class="chat-chips">
                <button
                  v-for="(a, j) in g.items" :key="j"
                  type="button" class="chat-chip" @click="irA(a.ruta)"
                >{{ a.texto }}</button>
              </div>

              <template v-else-if="g.tipo === 'principal'">
                <button
                  v-for="(a, j) in g.items" :key="j"
                  type="button" class="chat-principal" @click="irA(a.ruta)"
                >
                  <span>{{ a.texto }}</span>
                  <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                    <path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                </button>
              </template>

              <div v-else class="chat-ver-mas-fila">
                <button
                  v-for="(a, j) in g.items" :key="j"
                  type="button" class="chat-ver-mas" @click="irA(a.ruta)"
                >{{ a.texto }}</button>
              </div>
            </template>
          </div>

          <!-- Las opciones de bienvenida, solo bajo el saludo y solo hasta el primer mensaje. -->
          <div v-if="i === 0 && !empezo" class="chat-chips chat-bienvenida">
            <button
              v-for="op in OPCIONES" :key="op.texto"
              type="button" class="chat-chip" :disabled="enviando" @click="elegir(op)"
            >{{ op.texto }}</button>
          </div>
        </div>
        <div v-if="enviando" class="chat-msg bot chat-escribiendo" role="status" aria-label="Escribiendo">
          <span></span><span></span><span></span>
        </div>
      </div>

      <div v-if="empezo" class="chat-rapidas" aria-label="Opciones rápidas">
        <button
          v-for="op in OPCIONES" :key="op.texto"
          type="button" class="chat-rapida" :disabled="enviando" @click="elegir(op)"
        >{{ op.texto }}</button>
      </div>

      <p v-if="archivoError" class="chat-archivo-error" role="alert">{{ archivoError }}</p>
      <div v-if="archivo" class="chat-archivo-chip">
        <span class="chat-archivo-nombre">📎 {{ archivo.name }}</span>
        <button type="button" class="chat-archivo-quitar" aria-label="Quitar archivo" @click="quitarArchivo">
          <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
            <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" />
          </svg>
        </button>
      </div>

      <form class="chat-form" @submit.prevent="enviar">
        <input
          ref="archivoInput" type="file" class="chat-archivo-input"
          :accept="TIPOS_ARCHIVO.join(',')" aria-hidden="true" tabindex="-1"
          @change="archivoElegido"
        />
        <button
          type="button" class="chat-clip" :disabled="enviando"
          aria-label="Adjuntar foto o PDF (orden médica)" title="Adjuntar foto o PDF"
          @click="elegirArchivo"
        >
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
            <path
              d="M18.5 6.5l-8 8a3 3 0 1 0 4.24 4.24l7-7a5 5 0 1 0-7.07-7.07l-7.5 7.5a7 7 0 0 0 9.9 9.9"
              fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
            />
          </svg>
        </button>
        <input
          v-model="texto" class="chat-input" type="text" maxlength="1000"
          placeholder="Escribe tu pregunta…" aria-label="Tu mensaje"
        />
        <button type="submit" class="chat-enviar" :disabled="(!texto.trim() && !archivo) || enviando" aria-label="Enviar">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
            <path d="M5 12h13M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
      </form>
    </section>
  </Transition>
</template>

<style scoped>
/* z-index 200: sobre la barra superior del portal (sticky, 100), que en un
   teléfono bajo tapaba la cabecera del chat y con ella el botón de cerrar; y
   bajo los modales de app.css (300/310), que sí tienen que quedar encima. */
.chat-fab {
  position: fixed; right: 1rem; bottom: 1rem; z-index: 200;
  width: 56px; height: 56px; border-radius: var(--r-full);
  display: grid; place-items: center;
  background: var(--accent); color: #fff; border: 0; cursor: pointer;
  box-shadow: 0 8px 24px rgba(var(--accent-rgb), 0.38);
  transition: transform var(--dur-fast) var(--ease), background var(--dur-fast);
}
.chat-fab:hover { background: var(--accent-strong); transform: translateY(-2px); }
.chat-fab:focus-visible { outline: 3px solid var(--accent-claro); outline-offset: 3px; }

.chat-panel {
  position: fixed; z-index: 200; right: 1rem; bottom: 1rem;
  width: 400px; max-width: calc(100vw - 2rem);
  height: min(80svh, 660px);
  display: flex; flex-direction: column;
  background: var(--bg); color: var(--ink);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow-lg), 0 2px 6px rgba(38, 50, 110, 0.08);
  overflow: hidden;
  transform-origin: bottom right;
}

/* ── Cabecera ─────────────────────────────────────────────────────────── */
.chat-head {
  display: flex; align-items: center; gap: 0.65rem;
  padding: 0.8rem 0.75rem 0.8rem 0.9rem;
  background: linear-gradient(135deg, var(--accent) 0%, var(--accent-strong) 100%);
  color: #fff;
}
.chat-avatar {
  flex: none; width: 40px; height: 40px; border-radius: var(--r-full);
  background: #fff; object-fit: contain; padding: 4px;
}
.chat-head-txt { flex: 1; min-width: 0; display: flex; flex-direction: column; line-height: 1.25; }
.chat-head-txt strong { font-family: var(--font-display); font-size: var(--t-base); font-weight: 600; }
.chat-head-txt span { display: flex; align-items: center; gap: 0.35rem; font-size: var(--t-micro); opacity: 0.92; white-space: nowrap; }
.chat-punto { flex: none; width: 7px; height: 7px; border-radius: var(--r-full); background: var(--wa); box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.35); }

/* Sobre el verde de WhatsApp el texto va en azul oscuro, nunca blanco (app.css). */
.chat-wa {
  flex: none; display: inline-flex; align-items: center; gap: 0.3rem;
  padding: 0.35rem 0.6rem; border-radius: var(--r-full);
  background: var(--wa); color: var(--brand-strong);
  font-size: var(--t-micro); font-weight: 700; text-decoration: none;
}
.chat-wa:hover { background: var(--wa-hover); }
.chat-wa:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
.chat-cerrar {
  flex: none; display: grid; place-items: center;
  width: 32px; height: 32px; border-radius: var(--r-full);
  background: transparent; border: 0; color: #fff; cursor: pointer;
}
.chat-cerrar:hover { background: rgba(255, 255, 255, 0.16); }
.chat-cerrar:focus-visible { outline: 2px solid #fff; outline-offset: 1px; }

/* ── Hilo ─────────────────────────────────────────────────────────────── */
.chat-hist {
  position: relative; /* referencia de offsetTop para aLaRespuesta() */
  flex: 1; overflow-y: auto; overscroll-behavior: contain;
  padding: 1rem 0.9rem 0.75rem;
  display: flex; flex-direction: column; gap: 0.9rem;
}
.chat-turno { display: flex; flex-direction: column; align-items: flex-start; gap: 0.45rem; animation: chat-entra var(--dur-fast) var(--ease) both; }
.chat-turno.yo { align-items: flex-end; }
@keyframes chat-entra { from { opacity: 0; transform: translateY(6px); } }

.chat-msg {
  max-width: 85%; margin: 0; padding: 0.65rem 0.9rem;
  font-size: var(--t-base); line-height: 1.5; white-space: pre-line;
  overflow-wrap: break-word;
}
/* El hilo va sobre --bg: la burbuja blanca del asistente se separa sola, sin
   depender de un tinte que contra blanco no se ve (§12). */
.chat-msg.bot {
  background: var(--surface); color: var(--ink);
  border-radius: 4px var(--r-md) var(--r-md) var(--r-md);
  box-shadow: 0 1px 2px rgba(38, 50, 110, 0.08);
}
/* Una tabla o una lista larga en el 85% se parte en renglones de tres palabras:
   esas respuestas toman el ancho entero del hilo. `container-type` SOLO acá:
   en una burbuja que se ajusta a su texto, la contención de ancho la deja sin
   ancho propio y el saludo salía con una letra por renglón. */
.chat-msg.bot.ancho { max-width: 100%; align-self: stretch; container-type: inline-size; }
.chat-msg.yo {
  background: var(--accent); color: #fff;
  border-radius: var(--r-md) 4px var(--r-md) var(--r-md);
}

.chat-p { margin: 0; text-wrap: pretty; }
.chat-p + .chat-p { margin-top: 0.5em; }
.chat-monto { font-weight: 700; color: var(--brand); font-variant-numeric: tabular-nums; white-space: nowrap; }
.chat-nota { margin: 0.6rem 0 0; font-size: var(--t-micro); color: var(--ink-soft); }

/* Valores. Angosto: nombre y, debajo, cada día con su etiqueta. Con espacio
   (lo normal: el panel mide 400px y en teléfono va a lo ancho): una tabla de
   tres columnas con los días en la cabecera, UNA vez, en lugar de repetir
   «Lunes a viernes / Sábado» en cada fila, que era la mitad del ruido. */
.chat-precios { list-style: none; margin: 0.55rem -0.9rem -0.65rem; padding: 0; }
.chat-precio {
  display: grid; gap: 0.1rem 0.75rem; padding: 0.5rem 0.9rem;
  border-top: 1px solid var(--line); font-size: var(--t-sm); line-height: 1.4;
}
.chat-precio-cab { display: none; }
.chat-precio-nombre { font-weight: 600; color: var(--ink); }
.chat-precio-dias { display: flex; flex-wrap: wrap; gap: 0.1rem 1rem; }
.chat-dia { display: inline-flex; gap: 0.35rem; align-items: baseline; }
.chat-dia-etq { font-size: var(--t-micro); color: var(--ink-soft); }
.chat-precio-valor { color: var(--ink-soft); }

@container (min-width: 320px) {
  .chat-precio { grid-template-columns: minmax(0, 1fr) 4.6rem 4.6rem; align-items: baseline; }
  .chat-precio-cab {
    display: grid; padding-block: 0.4rem; border-top: 0;
    font-size: var(--t-micro); color: var(--ink-soft); background: var(--brand-tint);
  }
  .chat-precio-cab span:not(:first-child), .chat-dia { justify-content: flex-end; text-align: right; }
  .chat-precio-dias { display: contents; }
  /* La etiqueta sigue para el lector de pantalla; la vista ya la tiene arriba. */
  .chat-dia-etq {
    position: absolute; width: 1px; height: 1px; overflow: hidden;
    clip-path: inset(50%); white-space: nowrap;
  }
  .sin-dias .chat-precio-nombre, .chat-precio-valor { grid-column: 1 / -1; }
}

.chat-lista {
  margin: 0.4rem 0 0; padding: 0; list-style: none;
  display: grid; gap: 0.3rem 1rem; font-size: var(--t-sm); line-height: 1.35;
}
.chat-lista li { position: relative; padding-left: 0.85rem; }
.chat-lista li::before {
  content: ''; position: absolute; left: 0; top: 0.5em;
  width: 5px; height: 5px; border-radius: var(--r-full); background: var(--accent);
}
@container (min-width: 320px) { .chat-lista { grid-template-columns: 1fr 1fr; } }

/* ── Acciones ─────────────────────────────────────────────────────────── */
.chat-acciones { display: flex; flex-direction: column; align-items: flex-start; gap: 0.5rem; align-self: stretch; }

/* Elegir entre varias (consultas, y las opciones del saludo): fluyen en líneas
   en vez de apilarse una por renglón. */
.chat-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.chat-chip {
  min-height: 38px; padding: 0.4rem 0.85rem; border-radius: var(--r-full);
  border: 1px solid var(--line-strong); background: var(--surface); color: var(--accent-strong);
  font: inherit; font-size: var(--t-sm); font-weight: 600; line-height: 1.25; text-align: left; cursor: pointer;
  transition: border-color var(--dur-fast), background var(--dur-fast);
}
.chat-chip:hover:not(:disabled) { border-color: var(--accent); background: var(--accent-hover); }
.chat-chip:active:not(:disabled) { background: var(--accent-tint); }
.chat-chip:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
.chat-chip:disabled { opacity: 0.55; cursor: default; }

/* Una acción directa («Reservar Radiología», «Ir a Mis citas»). */
.chat-principal {
  display: inline-flex; align-items: center; gap: 0.4rem;
  min-height: 40px; padding: 0.5rem 0.8rem 0.5rem 1rem; border-radius: var(--r-full);
  border: 0; background: var(--accent); color: #fff;
  font: inherit; font-size: var(--t-sm); font-weight: 600; cursor: pointer;
  box-shadow: 0 4px 12px rgba(var(--accent-rgb), 0.22);
  transition: background var(--dur-fast);
}
.chat-principal svg { flex: none; }
.chat-principal:hover { background: var(--accent-strong); }
.chat-principal:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

/* Horas libres: la hora es lo que se busca con la vista, así que manda. */
.chat-horas { display: grid; grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr)); gap: 0.45rem; align-self: stretch; }
.chat-hora {
  display: grid; gap: 0.05rem; min-width: 0; text-align: left;
  padding: 0.5rem 0.7rem; border-radius: var(--r-md);
  border: 1px solid var(--line-strong); background: var(--surface); color: var(--ink);
  font: inherit; cursor: pointer;
  transition: border-color var(--dur-fast), background var(--dur-fast);
}
.chat-hora:hover { border-color: var(--accent); background: var(--accent-hover); }
.chat-hora:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
.chat-hora-hora { font-size: var(--t-lg); font-weight: 700; color: var(--accent-strong); font-variant-numeric: tabular-nums; line-height: 1.2; }
.chat-hora-dia { font-size: var(--t-micro); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-hora-dia::first-letter { text-transform: uppercase; }
.chat-hora-quien { font-size: var(--t-micro); color: var(--ink-soft); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.chat-ver-mas-fila { display: flex; flex-wrap: wrap; gap: 0.1rem 1rem; }
.chat-ver-mas {
  padding: 0.25rem 0.1rem; border: 0; background: none; cursor: pointer;
  font: inherit; font-size: var(--t-sm); font-weight: 600; color: var(--accent-strong);
  text-decoration: underline; text-decoration-color: rgba(var(--accent-rgb), 0.35); text-underline-offset: 3px;
}
.chat-ver-mas:hover { text-decoration-color: currentColor; }
.chat-ver-mas:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 4px; }

.chat-escribiendo { display: flex; gap: 0.3rem; align-items: center; padding: 0.8rem 0.9rem; }
.chat-escribiendo span {
  width: 7px; height: 7px; border-radius: var(--r-full); background: var(--line-tabla);
  animation: chat-rebote 1.1s infinite ease-in-out;
}
.chat-escribiendo span:nth-child(2) { animation-delay: 0.15s; }
.chat-escribiendo span:nth-child(3) { animation-delay: 0.3s; }
@keyframes chat-rebote {
  0%, 70%, 100% { transform: translateY(0); background: var(--line-tabla); }
  35% { transform: translateY(-4px); background: var(--accent); }
}

/* ── Opciones rápidas y campo ─────────────────────────────────────────── */
.chat-rapidas {
  display: flex; gap: 0.4rem; overflow-x: auto; padding: 0.55rem 0.9rem 0.1rem;
  scrollbar-width: none;
}
.chat-rapidas::-webkit-scrollbar { display: none; }
.chat-rapida {
  flex: none; padding: 0.35rem 0.75rem; border-radius: var(--r-full);
  border: 1px solid var(--line-strong); background: var(--surface); color: var(--accent-strong);
  font: inherit; font-size: var(--t-micro); font-weight: 600; white-space: nowrap; cursor: pointer;
}
.chat-rapida:hover:not(:disabled) { border-color: var(--accent); background: var(--accent-hover); }
.chat-rapida:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }
.chat-rapida:disabled { opacity: 0.6; cursor: default; }

/* La orden médica: chip de lo elegido (antes de enviar) y su error, igual de
   angostos que el resto del panel. */
.chat-archivo-error {
  margin: 0 0.9rem; font-size: var(--t-micro); color: var(--danger);
}
.chat-archivo-chip {
  display: flex; align-items: center; gap: 0.4rem;
  margin: 0.4rem 0.9rem 0; padding: 0.3rem 0.3rem 0.3rem 0.7rem;
  background: var(--accent-tint); border-radius: var(--r-md);
  font-size: var(--t-micro); color: var(--ink);
}
.chat-archivo-nombre { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chat-archivo-quitar {
  flex: none; display: grid; place-items: center; width: 22px; height: 22px;
  border: 0; border-radius: var(--r-full); background: transparent; color: var(--ink-soft); cursor: pointer;
}
.chat-archivo-quitar:hover { background: var(--surface); color: var(--ink); }
.chat-archivo-quitar:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }

.chat-archivo-input {
  position: absolute; width: 1px; height: 1px; overflow: hidden;
  clip-path: inset(50%); white-space: nowrap;
}

.chat-form {
  display: flex; align-items: center; gap: 0.4rem;
  margin: 0.55rem 0.75rem 0.75rem; padding: 0.3rem 0.3rem 0.3rem 0.5rem;
  background: var(--surface); border: 1px solid var(--line-strong); border-radius: var(--r-full);
}
.chat-form:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(var(--accent-rgb), 0.15); }
.chat-clip {
  flex: none; display: grid; place-items: center;
  width: 34px; height: 34px; border: 0; border-radius: var(--r-full);
  background: transparent; color: var(--ink-soft); cursor: pointer;
  transition: background var(--dur-fast), color var(--dur-fast);
}
.chat-clip:hover:not(:disabled) { background: var(--accent-hover); color: var(--accent-strong); }
.chat-clip:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
.chat-clip:disabled { opacity: 0.4; cursor: default; }
.chat-input {
  flex: 1; min-width: 0; padding: 0.4rem 0; border: 0; outline: 0;
  font: inherit; font-size: var(--t-sm); color: var(--ink); background: transparent;
}
.chat-enviar {
  flex: none; display: grid; place-items: center;
  width: 36px; height: 36px; border: 0; border-radius: var(--r-full);
  background: var(--accent); color: #fff; cursor: pointer;
  transition: background var(--dur-fast), opacity var(--dur-fast);
}
.chat-enviar:disabled { opacity: 0.4; cursor: default; }
.chat-enviar:not(:disabled):hover { background: var(--accent-strong); }
.chat-enviar:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

/* ── Entrada ──────────────────────────────────────────────────────────── */
.chat-enter-active, .chat-leave-active { transition: opacity var(--dur-fast) ease, transform var(--dur) var(--ease); }
.chat-enter-from, .chat-leave-to { opacity: 0; transform: translateY(12px) scale(0.97); }

@media (prefers-reduced-motion: reduce) {
  .chat-enter-active, .chat-leave-active, .chat-fab { transition: none; }
  .chat-escribiendo span, .chat-turno { animation: none; }
}

/* Teléfono: ancho completo, pegado abajo, y sin esquinas inferiores —
   flotar en una pantalla de 360px es perder ancho por decoración. */
@media (max-width: 620px) {
  .chat-panel {
    right: 0; left: 0; bottom: 0; width: auto; max-width: none;
    height: min(86svh, 700px);
    border-radius: var(--r-lg) var(--r-lg) 0 0;
  }
  /* Encima del borde inferior en iOS, donde la barra se come ~1rem. */
  .chat-fab { bottom: max(1rem, env(safe-area-inset-bottom)); }
  .chat-form { margin-bottom: max(0.75rem, env(safe-area-inset-bottom)); }
  /* En angosto el rótulo sobra: queda el ícono, que se reconoce solo. */
  .chat-wa span { display: none; }
  .chat-wa { padding: 0.45rem; }
}
</style>
