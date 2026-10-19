<script setup>
/* Kiosco de fichaje para el tablet compartido de recepción.
 *
 * NO es una pantalla del panel: no hay usuario logueado ni sidebar. Se sirve
 * en /fichaje con `meta.kiosco` (ver router.js y App.vue, que la saca del
 * layout) y se autentica con un token de DISPOSITIVO —no un JWT— guardado en
 * localStorage y enviado en la cabecera X-Fichaje-Token.
 *
 * Por eso usa su propio cliente axios y NO el `api` compartido: ese adjunta
 * el Bearer del staff y, ante un 401, manda a /login — justo lo que no debe
 * pasar en un tablet sin sesión.
 *
 * El servidor decide entrada/salida a partir del estado actual de la persona
 * (POST /asistencia/marcar-kiosco solo lleva `usuario_id`): el kiosco no
 * elige el tipo ni corrige horas.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import { API_URL } from '../api.js';
import GrillaFichaje from '../components/GrillaFichaje.vue';

const CLAVE_TOKEN = 'fichaje_token';

const token = ref(localStorage.getItem(CLAVE_TOKEN) || '');
const tokenInput = ref('');
const personas = ref([]);
const cargando = ref(true);
const error = ref('');
const necesitaToken = computed(() => !token.value || error.value === 'token');

// Confirmación a pantalla completa tras marcar: se lee desde el otro lado de
// la sala, y por eso tapa todo unos segundos en vez de ser un toast chico.
const confirma = ref(null);   // { nombre, tipo, hora }
let confirmaTimer = null;

// Reloj del kiosco. Barato y útil: en la entrada, la hora que se ve es la
// que se va a registrar.
const ahora = ref(new Date());
let relojTimer = null;
let recargaTimer = null;

const clienteFichaje = axios.create({ baseURL: API_URL });
clienteFichaje.interceptors.request.use((config) => {
  if (token.value) config.headers['X-Fichaje-Token'] = token.value;
  return config;
});

const horaTexto = computed(() =>
  ahora.value.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })
);
const fechaTexto = computed(() =>
  ahora.value.toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long' })
);

async function cargarRoster() {
  if (!token.value) { cargando.value = false; return; }
  try {
    const { data } = await clienteFichaje.get('/asistencia/roster');
    personas.value = data.personas;
    error.value = '';
  } catch (err) {
    if (err.response?.status === 401) {
      error.value = 'token';
      localStorage.removeItem(CLAVE_TOKEN);
      token.value = '';
    } else {
      error.value = 'No se pudo cargar la lista. Reintentando…';
    }
  } finally {
    cargando.value = false;
  }
}

const marcando = ref(null);   // usuario_id en curso, para bloquear el doble toque

async function marcar(persona) {
  if (marcando.value) return;
  marcando.value = persona.usuario_id;
  try {
    const { data } = await clienteFichaje.post('/asistencia/marcar-kiosco', {
      usuario_id: persona.usuario_id,
    });
    const hora = new Date(data.momento).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
    confirma.value = { nombre: data.nombre, tipo: data.tipo, hora };
    clearTimeout(confirmaTimer);
    confirmaTimer = setTimeout(() => { confirma.value = null; }, 3500);
    await cargarRoster();
  } catch (err) {
    if (err.response?.status === 401) {
      error.value = 'token';
      localStorage.removeItem(CLAVE_TOKEN);
      token.value = '';
    } else {
      error.value = err.response?.data?.detail || 'No se pudo registrar la marca.';
      setTimeout(() => { error.value = ''; }, 4000);
    }
  } finally {
    marcando.value = null;
  }
}

function guardarToken() {
  const t = tokenInput.value.trim();
  if (!t) return;
  token.value = t;
  localStorage.setItem(CLAVE_TOKEN, t);
  tokenInput.value = '';
  error.value = '';
  cargando.value = true;
  cargarRoster();
}

function olvidarDispositivo() {
  localStorage.removeItem(CLAVE_TOKEN);
  token.value = '';
  personas.value = [];
  error.value = 'token';
}

onMounted(() => {
  cargarRoster();
  relojTimer = setInterval(() => { ahora.value = new Date(); }, 1000 * 20);
  recargaTimer = setInterval(cargarRoster, 1000 * 60);
});
onUnmounted(() => {
  clearInterval(relojTimer);
  clearInterval(recargaTimer);
  clearTimeout(confirmaTimer);
});
</script>

<template>
  <div class="kiosco">
    <header class="k-top">
      <div class="k-marca">
        <img src="/isotipo.webp" alt="" class="k-logo" />
        <strong>Registro de asistencia</strong>
      </div>
      <div class="k-reloj">
        <span class="k-hora">{{ horaTexto }}</span>
        <span class="k-fecha">{{ fechaTexto }}</span>
      </div>
    </header>

    <!-- Sin token de dispositivo: alta única del tablet -->
    <div v-if="necesitaToken" class="k-centro">
      <div class="k-setup">
        <h1>Activar este dispositivo</h1>
        <p>Pega el código de fichaje de la clínica. Queda guardado en este tablet.</p>
        <input
          v-model="tokenInput" type="password" class="k-input"
          placeholder="Código de fichaje" autocomplete="off"
          @keyup.enter="guardarToken"
        />
        <button class="k-btn" :disabled="!tokenInput.trim()" @click="guardarToken">Activar</button>
        <p v-if="error === 'token' && token === ''" class="k-aviso">
          El código no es válido o dejó de funcionar. Pídelo de nuevo a la clínica.
        </p>
      </div>
    </div>

    <template v-else>
      <p v-if="cargando" class="k-centro k-tenue">Cargando…</p>
      <p v-else-if="!personas.length" class="k-centro k-tenue">No hay nadie en la lista.</p>

      <GrillaFichaje v-else :personas="personas" :marcando="marcando" @marcar="marcar" />

      <p v-if="error && error !== 'token'" class="k-error">{{ error }}</p>

      <button class="k-config" title="Ajustes del dispositivo" @click="olvidarDispositivo">⚙</button>
    </template>

    <!-- Confirmación grande, se lee desde lejos -->
    <transition name="fade">
      <div v-if="confirma" class="k-ok" :class="confirma.tipo" @click="confirma = null">
        <span class="k-ok-check">✓</span>
        <strong>{{ confirma.nombre }}</strong>
        <span class="k-ok-linea">
          {{ confirma.tipo === 'entrada' ? 'Entrada registrada' : 'Salida registrada' }} · {{ confirma.hora }}
        </span>
      </div>
    </transition>
  </div>
</template>

<style scoped>
.kiosco {
  position: fixed; inset: 0;
  background: var(--bg); color: var(--ink);
  display: flex; flex-direction: column;
  padding: clamp(1rem, 3vw, 2rem);
  overflow-y: auto;
}

.k-top {
  display: flex; align-items: center; justify-content: space-between;
  gap: 1rem; flex-wrap: wrap; margin-bottom: clamp(1rem, 3vw, 2rem);
}
.k-marca { display: flex; align-items: center; gap: 0.7rem; }
.k-logo { width: 40px; height: 40px; object-fit: contain; background: #fff; border-radius: 10px; padding: 3px; }
.k-marca strong { font-size: var(--t-xl); color: var(--accent-strong); }
.k-reloj { text-align: right; line-height: 1.15; }
.k-hora { display: block; font-size: clamp(2rem, 6vw, 3rem); font-weight: 700; font-variant-numeric: tabular-nums; }
.k-fecha { color: var(--ink-soft); font-size: var(--t-base); text-transform: capitalize; }

.k-centro { flex: 1; display: grid; place-items: center; text-align: center; }
.k-tenue { color: var(--ink-soft); font-size: var(--t-lg); }

.k-setup {
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--r-lg); box-shadow: var(--shadow);
  padding: clamp(1.5rem, 4vw, 2.5rem); max-width: 26rem; width: 100%;
  display: flex; flex-direction: column; gap: 0.9rem;
}
.k-setup h1 { font-size: var(--t-2xl); }
.k-setup p { color: var(--ink-soft); font-size: var(--t-base); }
.k-input {
  width: 100%; padding: 0.9rem 1rem; font-size: var(--t-lg);
  border: 1.5px solid var(--line-strong); border-radius: var(--r-md);
  background: var(--bg); color: var(--ink);
}
.k-input:focus { outline: 2px solid var(--accent); border-color: var(--accent); }
.k-btn {
  padding: 0.9rem 1rem; font-size: var(--t-lg); font-weight: 700;
  background: var(--accent); color: #fff; border-radius: var(--r-md);
}
.k-btn:disabled { opacity: 0.45; }
.k-aviso { color: var(--danger); font-size: var(--t-sm); }

/* La grilla de tarjetas (.gf-*) vive en GrillaFichaje.vue — acá solo el
   contenedor flex para que ocupe el alto disponible del kiosco. */
.kiosco :deep(.gf-grid) { flex: 1; }

.k-error {
  position: fixed; left: 50%; bottom: 1.5rem; transform: translateX(-50%);
  background: var(--danger-tint); color: var(--danger);
  padding: 0.7rem 1.2rem; border-radius: var(--r-full); font-size: var(--t-sm);
}
.k-config {
  position: fixed; right: 0.8rem; bottom: 0.8rem;
  width: 2.4rem; height: 2.4rem; border-radius: 50%;
  color: var(--ink-soft); font-size: 1.1rem; opacity: 0.4;
}
.k-config:hover { opacity: 1; background: var(--surface); }

.k-ok {
  position: fixed; inset: 0; z-index: 50;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 0.6rem; text-align: center;
  background: var(--ok-tint); color: var(--ok);
}
.k-ok.salida { background: var(--accent-tint); color: var(--accent-strong); }
.k-ok-check {
  width: clamp(4rem, 14vw, 6rem); height: clamp(4rem, 14vw, 6rem);
  border-radius: 50%; display: grid; place-items: center;
  background: currentColor; color: #fff;
  font-size: clamp(2.4rem, 8vw, 3.6rem); font-weight: 700;
}
.k-ok strong { font-size: clamp(1.6rem, 5vw, 2.6rem); color: var(--ink); }
.k-ok-linea { font-size: var(--t-lg); color: var(--ink-soft); }

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

@media (prefers-reduced-motion: reduce) {
  .k-persona, .fade-enter-active, .fade-leave-active { transition: none; }
}
</style>
