<script setup>
/* Config → Estado del sistema (decisión de producto, 05-oct).

   WhatsApp, Google y la IA fallan EN SILENCIO: el token vencido deja al bot
   recibiendo y sin poder responder, Google sin acceso detiene la
   sincronización y solo lo dice el log del servidor, y la IA sin cupo o sin
   saldo pasa a respuestas fijas sin que nada se vea roto. Esta pantalla lo
   muestra, y el cron `estado:revisar` (cada 15 min) avisa por correo.

   Al entrar se muestra lo último que revisó el cron —no se sondea en cada
   visita—; «Revisar ahora» le pregunta de verdad a cada servicio. */
import { ref, computed, onMounted } from 'vue';
import { api, apiError } from '../api.js';

const estado = ref(null);
const cargando = ref(true);
const revisando = ref(false);
const error = ref('');

const nuevoCorreo = ref('');
const guardando = ref(false);
const guardadoMsg = ref('');
const errorCorreos = ref('');

const ETIQUETA = { ok: 'Funcionando', aviso: 'Atención', error: 'Caído', apagado: 'Apagado' };

async function cargar(sondear = false) {
  error.value = '';
  try {
    const { data } = await api.get('/estado-sistema', { params: sondear ? { sondear: 1 } : {} });
    estado.value = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudo leer el estado del sistema.');
  }
}

onMounted(async () => {
  await cargar(false);
  // Sin nada revisado todavía (cron recién instalado), se sondea una vez.
  if (estado.value && !estado.value.sondeado && estado.value.servicios.some((s) => s.resumen === 'Sin revisar todavía')) {
    await cargar(true);
  }
  cargando.value = false;
});

async function revisarAhora() {
  revisando.value = true;
  await cargar(true);
  revisando.value = false;
}

/* Cada interruptor guarda al tocarlo: un «Guardar» aparte para un sí/no
   deja cambios a medio hacer cuando alguien se va de la pantalla.
   `cambio` recibe {activo, extra, apagados} ya armados desde lo que se ve. */
async function guardarAlertas(cambio = {}) {
  const a = estado.value.alertas;
  const cuerpo = {
    activo: a.activo,
    extra: a.candidatos.filter((c) => c.origen === 'extra').map((c) => c.correo),
    apagados: a.candidatos.filter((c) => !c.recibe).map((c) => c.correo),
    ...cambio,
  };
  guardando.value = true;
  guardadoMsg.value = '';
  errorCorreos.value = '';
  try {
    const { data } = await api.put('/estado-sistema/alertas', cuerpo);
    estado.value.alertas = { ...a, ...data };
    guardadoMsg.value = 'Guardado.';
    return true;
  } catch (e) {
    errorCorreos.value = apiError(e, 'No se pudo guardar.');
    await cargar(false);   // lo que se ve vuelve a ser lo guardado
    return false;
  } finally {
    guardando.value = false;
  }
}

function cambiarGeneral(ev) {
  guardarAlertas({ activo: ev.target.checked });
}

function cambiarPersona(c, ev) {
  const apagados = estado.value.alertas.candidatos
    .filter((x) => (x.correo === c.correo ? !ev.target.checked : !x.recibe))
    .map((x) => x.correo);
  guardarAlertas({ apagados });
}

async function agregar() {
  const correo = nuevoCorreo.value.trim();
  if (!correo) return;
  const extra = [...estado.value.alertas.candidatos.filter((c) => c.origen === 'extra').map((c) => c.correo), correo];
  if (await guardarAlertas({ extra })) nuevoCorreo.value = '';
}

function quitar(c) {
  guardarAlertas({
    extra: estado.value.alertas.candidatos.filter((x) => x.origen === 'extra' && x.correo !== c.correo).map((x) => x.correo),
    apagados: estado.value.alertas.candidatos.filter((x) => !x.recibe && x.correo !== c.correo).map((x) => x.correo),
  });
}

/* «hace 3 min», «hoy 14:05», «2 oct 09:30»: lo que importa es cuán viejo es. */
function cuando(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  const min = Math.round((Date.now() - d.getTime()) / 60000);
  if (min < 1) return 'recién';
  if (min < 60) return `hace ${min} min`;
  const hora = d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
  if (d.toDateString() === new Date().toDateString()) return `hoy ${hora}`;
  return `${d.toLocaleDateString('es-CL', { day: 'numeric', month: 'short' })} ${hora}`;
}

const num = (n) => (n ?? 0).toLocaleString('es-CL');

/* Los indicadores (decisión de producto, 05-oct). `null` = no se pudo calcular o no
   aplica: se muestra «—», nunca un 0 que diga algo falso. */
const k = computed(() => estado.value?.kpis || {});

const deltaReservas = computed(() => {
  const ahora = k.value.reservas_bot_7d;
  const antes = k.value.reservas_bot_7d_antes;
  if (ahora == null || antes == null) return null;
  const d = ahora - antes;
  return {
    texto: d === 0 ? 'igual que la semana anterior' : `${d > 0 ? '+' : '−'}${num(Math.abs(d))} vs la semana anterior`,
    corto: d === 0 ? '= sem. ant.' : `${d > 0 ? '+' : '−'}${num(Math.abs(d))} vs sem. ant.`,
    sube: d > 0,
  };
});

/* Medidor del cupo: el relleno lleva la severidad (normal → atención →
   agotado) y el texto dice lo mismo, así que el color no es la única señal. */
const cupo = computed(() => {
  const ia = k.value.ia_hoy;
  if (!ia) return null;
  if (!ia.activa) return { apagada: true, ia };
  if (!ia.tope) return { sinTope: true, ia };
  const pct = Math.min(100, Math.round((100 * ia.tokens) / ia.tope));
  const nivel = ia.tokens >= ia.tope ? 'error' : pct >= 80 ? 'aviso' : 'ok';
  return { pct, nivel, ia, texto: { ok: 'normal', aviso: 'casi agotado', error: 'agotado' }[nivel] };
});

function disponibilidad(id) {
  const d = k.value.disponibilidad_7d?.[id];
  if (!d || d.porcentaje == null) return null;
  return d;
}

const resumenGeneral = computed(() => {
  const p = estado.value?.peor;
  if (p === 'error') return { clase: 'error', texto: 'Hay algo caído. Abajo está qué pasa y qué hacer.' };
  if (p === 'aviso') return { clase: 'aviso', texto: 'Todo funciona, con algo que conviene mirar.' };
  return { clase: 'ok', texto: 'Todo funcionando.' };
});
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Estado del sistema</h1>
        <p class="page-sub">
          WhatsApp, Google y el asistente IA pueden dejar de funcionar sin que nada se vea roto.
          Acá se ve si andan, y si alguno se cae llega un correo.
        </p>
      </div>
    </div>

    <p v-if="cargando" class="muted">Revisando…</p>
    <p v-else-if="error" class="es-banda error" role="alert">{{ error }}</p>

    <template v-else-if="estado">
      <div class="es-banda" :class="resumenGeneral.clase" role="status">
        <strong>{{ resumenGeneral.texto }}</strong>
        <span class="es-cuando">Revisado {{ cuando(estado.revisado_at) }}</span>
        <button class="btn btn-ghost btn-sm" :disabled="revisando" @click="revisarAhora">
          {{ revisando ? 'Revisando…' : 'Revisar ahora' }}
        </button>
      </div>

      <!-- Indicadores: números sueltos, no gráficos (una cifra es su propio gráfico). -->
      <div class="es-kpis-caja"><div class="es-kpis">
        <div class="card es-kpi">
          <p class="es-kpi-lbl">WhatsApp hoy</p>
          <p class="es-kpi-val">{{ k.conversaciones_hoy ?? '—' }}</p>
          <p class="es-kpi-ctx">Conversaciones con el bot</p>
        </div>
        <div class="card es-kpi">
          <p class="es-kpi-lbl">Reservas del bot</p>
          <p class="es-kpi-val">{{ k.reservas_bot_7d ?? '—' }}</p>
          <p class="es-kpi-ctx">
            7 días<template v-if="deltaReservas"> ·
              <span :class="{ 'es-sube': deltaReservas.sube }" :title="deltaReservas.texto">{{ deltaReservas.corto }}</span></template>
          </p>
        </div>
        <div class="card es-kpi">
          <p class="es-kpi-lbl">Especialistas</p>
          <p class="es-kpi-val">{{ k.especialistas_esperando ?? '—' }}</p>
          <p class="es-kpi-ctx">Consultas en curso</p>
        </div>
        <div class="card es-kpi" :class="{ 'es-kpi-alerta': k.fuera_de_google?.sin_evento > 0 }">
          <p class="es-kpi-lbl">Fuera de Google</p>
          <p class="es-kpi-val">
            {{ k.fuera_de_google ? k.fuera_de_google.sin_evento : '—' }}
            <span v-if="k.fuera_de_google?.sin_evento > 0" class="es-chip aviso">⚠ revisar</span>
          </p>
          <p class="es-kpi-ctx">
            <template v-if="k.fuera_de_google">de {{ num(k.fuera_de_google.proximas) }} horas próximas</template>
            <template v-else>Sin calendario conectado</template>
          </p>
        </div>
        <div class="card es-kpi">
          <p class="es-kpi-lbl">Cupo IA hoy</p>
          <template v-if="cupo && !cupo.apagada && !cupo.sinTope">
            <p class="es-kpi-val">{{ cupo.pct }} %</p>
            <div class="es-meter" :class="cupo.nivel" role="meter" :aria-valuenow="cupo.pct" aria-valuemin="0" aria-valuemax="100"
              :aria-label="`Cupo de la IA usado: ${cupo.pct} %, ${cupo.texto}`">
              <span :style="{ width: cupo.pct + '%' }"></span>
            </div>
            <p class="es-kpi-ctx" :title="`${num(cupo.ia.tokens)} de ${num(cupo.ia.tope)} tokens · ${num(cupo.ia.llamadas)} llamadas`">{{ cupo.texto }} · {{ num(cupo.ia.llamadas) }} llamadas</p>
          </template>
          <template v-else>
            <p class="es-kpi-val">{{ cupo?.apagada ? 'Apagada' : cupo?.sinTope ? 'Sin tope' : '—' }}</p>
            <p class="es-kpi-ctx" v-if="cupo">{{ num(cupo.ia.llamadas) }} llamadas hoy</p>
          </template>
        </div>
        <div class="card es-kpi">
          <p class="es-kpi-lbl">Alertas</p>
          <p class="es-kpi-val">{{ k.alertas_7d ?? '—' }}</p>
          <p class="es-kpi-ctx">Enviadas en 7 días</p>
        </div>
      </div></div>

      <div class="es-grid">
        <article v-for="s in estado.servicios" :key="s.id" class="card es-pieza" :class="s.estado">
          <header class="es-cab">
            <h2 class="es-nombre">{{ s.nombre }}</h2>
            <span class="es-chip" :class="s.estado">{{ ETIQUETA[s.estado] || s.estado }}</span>
          </header>
          <p class="es-resumen">{{ s.resumen }}</p>
          <p class="es-detalle">{{ s.detalle }}</p>
          <p v-if="s.que_hacer" class="es-hacer"><strong>Qué hacer:</strong> {{ s.que_hacer }}</p>
          <p v-if="s.desde" class="es-cuando">Desde {{ cuando(s.desde) }}</p>
          <p class="es-dispo">
            <template v-if="disponibilidad(s.id)">
              Últimos 7 días: <strong>{{ disponibilidad(s.id).porcentaje.toLocaleString('es-CL') }} %</strong> funcionando
              <span class="es-cuando">({{ num(disponibilidad(s.id).revisiones) }} revisiones desde {{ cuando(disponibilidad(s.id).desde) }})</span>
            </template>
            <span v-else class="es-cuando">Sin historial todavía: se arma con la revisión de cada 15 minutos.</span>
          </p>
        </article>
      </div>

      <section class="card es-alertas">
        <div class="es-cab">
          <h2 class="es-nombre">Alertas por correo</h2>
          <label class="es-switch">
            <input type="checkbox" role="switch" :checked="estado.alertas.activo" :disabled="guardando"
              @change="cambiarGeneral" />
            <span class="es-switch-pista" aria-hidden="true"></span>
            <span class="es-switch-txt">{{ estado.alertas.activo ? 'Encendidas' : 'Apagadas' }}</span>
          </label>
        </div>
        <p class="es-detalle">
          Se manda un correo cuando algo queda <strong>caído en dos revisiones seguidas</strong> (unos 15 minutos;
          así un corte de segundos no llena la bandeja), un recordatorio cada 24 horas si sigue igual, y otro
          cuando vuelve a funcionar. Va por correo y no por WhatsApp porque WhatsApp es una de las cosas que se vigilan.
        </p>
        <p v-if="!estado.alertas.activo" class="es-banda aviso" role="status">
          Apagadas: si algo se cae no le llega correo a nadie. Esta pantalla y el punto rojo de «Config» siguen funcionando.
        </p>
        <p v-else-if="!estado.alertas.destinatarios.length" class="es-banda aviso" role="status">
          Encendidas, pero nadie las recibe: enciende al menos a una persona.
        </p>

        <ul class="es-personas" :class="{ apagadas: !estado.alertas.activo }">
          <li v-for="c in estado.alertas.candidatos" :key="c.correo" class="es-persona">
            <div class="es-quien">
              <strong>{{ c.correo }}</strong>
              <span class="es-cuando">{{ c.origen === 'admin' ? (c.nombre ? `${c.nombre} · administrador` : 'administrador') : 'agregado a mano' }}</span>
            </div>
            <button v-if="c.origen === 'extra'" class="btn btn-ghost btn-sm" :disabled="guardando" @click="quitar(c)">Quitar</button>
            <label class="es-switch">
              <input type="checkbox" role="switch" :checked="c.recibe" :disabled="guardando || !estado.alertas.activo"
                :aria-label="`Recibe alertas: ${c.correo}`" @change="cambiarPersona(c, $event)" />
              <span class="es-switch-pista" aria-hidden="true"></span>
              <span class="es-switch-txt">{{ c.recibe ? 'Recibe' : 'No recibe' }}</span>
            </label>
          </li>
        </ul>

        <form class="es-acciones" @submit.prevent="agregar">
          <label class="es-label" for="es-nuevo">Agregar otro correo</label>
          <input id="es-nuevo" v-model="nuevoCorreo" class="input es-input" type="email" placeholder="contacto@clinica.cl" />
          <button class="btn btn-primary btn-sm" :disabled="guardando || !nuevoCorreo.trim()">Agregar</button>
          <span v-if="guardadoMsg" class="es-ok" role="status">{{ guardadoMsg }}</span>
          <span v-if="errorCorreos" class="es-err" role="alert">{{ errorCorreos }}</span>
        </form>

        <p v-if="estado.alertas.ultima" class="es-cuando">
          Última alerta: «{{ estado.alertas.ultima.asunto }}», {{ cuando(estado.alertas.ultima.at) }} —
          <template v-if="estado.alertas.ultima.enviados">enviada a {{ estado.alertas.ultima.enviados }} de {{ estado.alertas.ultima.destinatarios }}.</template>
          <strong v-else class="es-err">no le llegó a nadie (revisa el correo de salida).</strong>
        </p>
      </section>
    </template>
  </div>
</template>

<style scoped>
.muted { color: var(--ink-soft); }

/* El estado general, arriba. El color nunca es la única señal: el texto dice
   lo mismo. */
.es-banda {
  display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem 1rem;
  padding: 0.75rem 1rem; border-radius: var(--r-md); margin-bottom: 1rem;
  font-size: var(--t-base);
}
.es-banda.ok { background: var(--ok-tint); color: var(--ok); }
.es-banda.aviso { background: var(--warn-tint); color: var(--warn-ink); }
.es-banda.error { background: var(--danger-tint); color: var(--danger); }
.es-banda .btn { margin-left: auto; }

/* Fila de indicadores. Cifra en la misma sans que todo, con figuras
   proporcionales (tabular-nums solo para columnas que se alinean). */
/* Los seis en una línea (decisión de producto, 05-oct). Se mide el ANCHO DE LA
   CAJA y no el de la ventana: dentro de Config el contenido queda angosto
   (menú del panel + menú de Config), y un @media sobre la ventana dejaría
   seis columnas de 120px. Bajo ~720px de caja pasan a 3, y en celular a 2. */
.es-kpis-caja { container-type: inline-size; margin-bottom: 1rem; }
.es-kpis { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 0.6rem; }
@container (max-width: 720px) { .es-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@container (max-width: 380px) { .es-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.es-kpi { display: flex; flex-direction: column; gap: 0.25rem; padding: 0.75rem 0.8rem; min-width: 0; }
.es-kpi-alerta { border-color: var(--warn-ink); }
.es-kpi-lbl { font-size: var(--t-micro); color: var(--ink-soft); margin: 0; font-weight: 600; }
.es-kpi-val { font-size: var(--t-2xl); font-weight: 650; color: var(--ink); margin: 0; line-height: 1.15;
  display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.es-kpi-ctx { font-size: var(--t-micro); color: var(--ink-soft); margin: 0; line-height: 1.45; }
.es-sube { color: var(--ok); font-weight: 600; }

/* Medidor: la pista es un paso claro del mismo tono que el relleno. */
.es-meter { height: 0.5rem; border-radius: var(--r-full); overflow: hidden; background: var(--ok-tint); }
.es-meter span { display: block; height: 100%; border-radius: var(--r-full); background: var(--ok); }
.es-meter.aviso { background: var(--warn-tint); }
.es-meter.aviso span { background: var(--warn-ink); }
.es-meter.error { background: var(--danger-tint); }
.es-meter.error span { background: var(--danger); }

.es-dispo { font-size: var(--t-sm); margin: 0.2rem 0 0; padding-top: 0.45rem; border-top: 1px solid var(--line); }

.es-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1rem; margin-bottom: 1rem; }
/* Borde izquierdo del color del estado: se ubica cuál está mal sin leer. */
.es-pieza { border-left: 4px solid var(--line-strong); display: flex; flex-direction: column; gap: 0.4rem; }
.es-pieza.ok { border-left-color: var(--ok); }
.es-pieza.aviso { border-left-color: var(--warn-ink); }
.es-pieza.error { border-left-color: var(--danger); }

.es-cab { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.es-nombre { font-size: var(--t-lg); margin: 0; }
.es-chip {
  font-size: var(--t-micro); font-weight: 600; padding: 0.2em 0.65em; border-radius: var(--r-full);
  background: var(--line); color: var(--ink-soft); white-space: nowrap;
}
.es-chip.ok { background: var(--ok-tint); color: var(--ok); }
.es-chip.aviso { background: var(--warn-tint); color: var(--warn-ink); }
.es-chip.error { background: var(--danger-tint); color: var(--danger); }

.es-resumen { font-weight: 600; font-size: var(--t-base); margin: 0; }
.es-detalle { color: var(--ink-soft); font-size: var(--t-sm); margin: 0; line-height: 1.5; }
.es-hacer { font-size: var(--t-sm); margin: 0; line-height: 1.5; }
.es-cuando { color: var(--ink-soft); font-size: var(--t-micro); }

.es-alertas { display: flex; flex-direction: column; gap: 0.6rem; }
.es-label { font-size: var(--t-sm); font-weight: 600; }
.es-acciones { display: flex; align-items: center; gap: 0.6rem 0.8rem; flex-wrap: wrap; }
.es-input { flex: 1 1 14rem; max-width: 22rem; }

.es-personas { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--line); }
/* Con el interruptor general apagado, la lista se atenúa: lo que diga cada
   fila no aplica hasta encenderlas. */
.es-personas.apagadas { opacity: 0.55; }
.es-persona {
  display: flex; align-items: center; gap: 0.8rem; padding: 0.55rem 0;
  border-bottom: 1px solid var(--line);
}
.es-quien { display: flex; flex-direction: column; min-width: 0; flex: 1; font-size: var(--t-sm); }
.es-quien strong { overflow-wrap: anywhere; }

/* Interruptor: una casilla nativa (teclado y lector de pantalla gratis) con
   pista dibujada. El texto al lado dice el estado: el color no es la única señal. */
.es-switch { display: inline-flex; align-items: center; gap: 0.45rem; cursor: pointer; white-space: nowrap; }
.es-switch input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.es-switch-pista {
  position: relative; width: 2.3rem; height: 1.3rem; border-radius: var(--r-full);
  background: var(--line-strong); transition: background 0.2s; flex-shrink: 0;
}
.es-switch-pista::after {
  content: ''; position: absolute; top: 0.15rem; left: 0.15rem; width: 1rem; height: 1rem;
  border-radius: 50%; background: var(--surface); transition: transform 0.2s;
}
.es-switch input:checked + .es-switch-pista { background: var(--ok); }
.es-switch input:checked + .es-switch-pista::after { transform: translateX(1rem); }
.es-switch input:focus-visible + .es-switch-pista { outline: 2px solid var(--accent); outline-offset: 2px; }
.es-switch input:disabled + .es-switch-pista { opacity: 0.6; }
.es-switch-txt { font-size: var(--t-sm); min-width: 5.2rem; }
.es-ok { color: var(--ok); font-size: var(--t-sm); }
.es-err { color: var(--danger); font-size: var(--t-sm); }
</style>
