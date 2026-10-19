<script setup>
import { ref, computed, onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import { api, apiError, DIAS } from '../api.js';
import { retratoDe } from '../retratos.js';

const props = defineProps({
  especialistaId: { type: String, required: true },
});

const esp = ref(null);
const servicios = ref([]);
const google = ref({ configurado: false, conectado: false, cuenta: null, instrucciones: '' });
const calendarios = ref([]);
const horarioActivo = ref(0);
const loading = ref(true);
const busy = ref(false);
const okMsg = ref('');
const error = ref('');

const RESERVA_URL = import.meta.env.VITE_RESERVA_URL || 'http://localhost:3101';
const permalink = computed(() => `${RESERVA_URL}/b/${esp.value?.slug || ''}`);
const tieneCorreo = computed(() => !!(esp.value?.email || '').trim());

/* Calendario de reservas propio. */
const compartirCal = ref(true);
const creandoCal = ref(false);
const calMsg = ref('');
// El correo GUARDADO: crear el calendario lo comparte con el que tiene la
// base, no con uno escrito arriba y todavía sin guardar.
const emailGuardado = ref('');
// Solo donde la cuenta de la clínica puede crear eventos (el backend lo vuelve
// a comprobar al guardar); sin el general, que es la opción «de la clínica».
// `rol` ausente = API anterior: se ofrecen todos, como antes.
const calendariosReserva = computed(() => calendarios.value.filter((c) =>
  (!c.rol || c.rol === 'owner' || c.rol === 'writer') && c.id !== google.value.calendario_reservas));
// El asignado, aunque no venga en la lista (no cargó, o se lo dejaron de
// compartir): sin esto el select lo mostraría vacío y guardar lo borraría.
const calAsignadoFuera = computed(() => {
  const id = esp.value?.google_calendar_id;
  return id && !calendariosReserva.value.some((c) => c.id === id) ? id : null;
});

function horarioVacio(n) {
  return {
    nombre: `Horario ${n}`, orden: n - 1,
    todos_servicios: true, servicio_ids: [],
    valido_desde: null, valido_hasta: null,
    bloques: [],
    // estado UI para "válida desde/hasta"
    _desdeModo: 'ahora', _hastaModo: 'nunca',
  };
}

function prepararUI(e) {
  for (const h of e.horarios) {
    h._desdeModo = h.valido_desde ? 'fecha' : 'ahora';
    h._hastaModo = h.valido_hasta ? 'fecha' : 'nunca';
  }
  if (!e.horarios.length) e.horarios.push(horarioVacio(1));
  // Contra una API anterior a `especialistas.publico` el campo no viene: sin
  // esto la casilla aparecería desmarcada y diría que no sale en el sitio.
  if (e.publico === undefined) e.publico = true;
  if (e.google_calendar_id === undefined) e.google_calendar_id = null;
  emailGuardado.value = (e.email || '').trim();
}

async function cargar() {
  loading.value = true;
  try {
    const [e, s, g] = await Promise.all([
      api.get(`/catalogo/especialistas/${props.especialistaId}`),
      api.get('/catalogo/servicios/todos'),
      api.get('/google/status'),
    ]);
    esp.value = e.data;
    prepararUI(esp.value);
    servicios.value = s.data;
    google.value = g.data;
    if (google.value.conectado) await cargarCalendarios();
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar el especialista.');
  } finally {
    loading.value = false;
  }
}

async function cargarCalendarios() {
  if (!google.value.conectado) { calendarios.value = []; return; }
  try {
    const { data } = await api.get('/google/calendars');
    calendarios.value = data;
  } catch {
    calendarios.value = [];
  }
}

async function crearCalendario() {
  creandoCal.value = true;
  calMsg.value = '';
  error.value = '';
  try {
    const { data } = await api.post(`/google/calendario-especialista/${props.especialistaId}`, {
      compartir: compartirCal.value && !!emailGuardado.value,
    });
    esp.value.google_calendar_id = data.google_calendar_id;
    await cargarCalendarios();
    calMsg.value = data.compartido_con
      ? `Listo: «${data.nombre}» creado y compartido con ${data.compartido_con}.`
      : data.compartido_con === false
        ? `«${data.nombre}» creado, pero Google no lo compartió: compártelo a mano desde Google Calendar.`
        : `Listo: «${data.nombre}» creado.`;
  } catch (e) {
    error.value = apiError(e, 'No se pudo crear el calendario.');
  } finally {
    creandoCal.value = false;
  }
}

/* Bloques */
function bloquesDeDia(h, dia) {
  return h.bloques
    .map((b, i) => ({ ...b, _i: i }))
    .filter((b) => b.dia_semana === dia)
    .sort((a, b) => a.hora_inicio.localeCompare(b.hora_inicio));
}
function agregarBloque(h, dia) {
  h.bloques.push({ dia_semana: dia, hora_inicio: '09:00', hora_fin: '18:00' });
}
function quitarBloque(h, i) {
  h.bloques.splice(i, 1);
}
function agregarHorario() {
  esp.value.horarios.push(horarioVacio(esp.value.horarios.length + 1));
  horarioActivo.value = esp.value.horarios.length - 1;
}
function quitarHorario(i) {
  if (!confirm(`¿Eliminar "${esp.value.horarios[i].nombre}"?`)) return;
  esp.value.horarios.splice(i, 1);
  if (!esp.value.horarios.length) esp.value.horarios.push(horarioVacio(1));
  horarioActivo.value = 0;
}

async function guardar() {
  busy.value = true;
  okMsg.value = '';
  error.value = '';
  try {
    const payload = JSON.parse(JSON.stringify(esp.value));
    for (const h of payload.horarios) {
      if (h._desdeModo === 'ahora') h.valido_desde = null;
      if (h._hastaModo === 'nunca') h.valido_hasta = null;
      delete h._desdeModo;
      delete h._hastaModo;
    }
    delete payload.foto;
    delete payload.disponibilidad;
    // Un input de texto vaciado deja '' , no null, y EmailStr lo rechaza con
    // un 422: sin esto, borrarle el correo a alguien —o sea, dar de baja sus
    // avisos— es justo lo que no se puede hacer.
    for (const k of ['email', 'telefono']) {
      if (!String(payload[k] ?? '').trim()) payload[k] = null;
    }
    // Sin correo no hay a quién avisar; el backend además lo rechaza con 400.
    if (!payload.email) payload.recibe_avisos = false;
    const { data } = await api.put(`/catalogo/especialistas/${props.especialistaId}`, payload);
    esp.value = { ...data };
    prepararUI(esp.value);
    okMsg.value = 'Cambios guardados.';
    setTimeout(() => (okMsg.value = ''), 2500);
  } catch (e) {
    error.value = apiError(e, 'No se pudieron guardar los cambios.');
  } finally {
    busy.value = false;
  }
}

function copiarLink() {
  navigator.clipboard?.writeText(permalink.value);
  okMsg.value = 'Enlace copiado.';
  setTimeout(() => (okMsg.value = ''), 2000);
}

onMounted(cargar);
</script>

<template>
  <div class="page">
    <nav class="miga" aria-label="Ruta">
      <RouterLink to="/especialistas">Especialistas</RouterLink>
      <span aria-hidden="true">»</span>
      <strong v-if="esp">{{ esp.nombre }}<template v-if="esp.especialidad"> - {{ esp.especialidad }}</template></strong>
    </nav>

    <p v-if="loading" class="empty">Cargando…</p>
    <p v-else-if="error && !esp" class="error-text">{{ error }}</p>

    <template v-else-if="esp">
      <!-- ══ General ══ -->
      <section class="card sec">
        <h2 class="section-title">📝 General</h2>
        <div class="sec-body">
          <div class="row-2">
            <div class="field">
              <label for="e-nombre">Nombre</label>
              <input id="e-nombre" class="input" v-model="esp.nombre" />
            </div>
            <div class="field">
              <label for="e-espec">Especialidad</label>
              <input id="e-espec" class="input" v-model="esp.especialidad" />
            </div>
          </div>

          <div class="field">
            <label for="e-slug">Enlace permanente</label>
            <div class="slug-row">
              <span class="slug-fix">{{ RESERVA_URL }}/b/</span>
              <input id="e-slug" class="input" v-model="esp.slug" />
              <button type="button" class="btn btn-ghost btn-sm" @click="copiarLink">Copiar</button>
              <a :href="permalink" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">Abrir</a>
            </div>
          </div>

          <div class="row-2">
            <div class="field">
              <label for="e-tz">Zona horaria</label>
              <select id="e-tz" class="select" v-model="esp.zona_horaria">
                <option>America/Santiago</option>
              </select>
            </div>
            <div class="field">
              <label for="e-emoji">Ícono (si no hay imagen)</label>
              <select id="e-emoji" class="select" v-model="esp.emoji">
                <option v-for="em in ['🩺','💬','✂','🫀','🐱','🐶','🦷','🩻','🦎','🪡','🧠','🌿','💪','🔬']" :key="em" :value="em">{{ em }}</option>
              </select>
            </div>
          </div>

          <div class="field">
            <label for="e-desc">Descripción <small>(opcional)</small></label>
            <textarea id="e-desc" class="textarea" v-model="esp.descripcion"
              placeholder="Breve descripción que aparecerá en el planificador debajo del nombre."></textarea>
          </div>

          <div class="field">
            <label>Retrato</label>
            <div class="foto-row">
              <img v-if="retratoDe(esp)" :src="retratoDe(esp)" class="foto" alt="Retrato del especialista" />
              <span v-else class="foto foto-ph" aria-hidden="true">{{ esp.emoji }}</span>
            </div>
            <span class="hint">
              Los retratos se publican con el sitio, no se suben desde aquí: el
              servidor borra los archivos subidos en cada actualización. Para
              agregar o cambiar uno, pídeselo a quien mantiene el proyecto.
            </span>
          </div>
        </div>
      </section>

      <!-- ══ Cómo se le piden horas ══ -->
      <section class="card sec">
        <h2 class="section-title">🔓 Reserva por la web</h2>
        <div class="sec-body">
          <div class="field">
            <label class="check">
              <input type="checkbox" v-model="esp.reservable" />
              <span>El cliente puede pedirle hora directamente por la página</span>
            </label>
            <span class="hint">
              <template v-if="esp.reservable">
                Aparece en el equipo del sitio y en el selector de la reserva.
              </template>
              <template v-else>
                <strong>Sigue apareciendo en el equipo del sitio</strong>, pero no
                en el selector de la reserva, y nadie puede agendarle desde la web.
                La clínica sí puede darle hora desde la agenda del panel — es para
                quienes se coordinan por dentro, como anestesia.
              </template>
            </span>
          </div>
          <div class="field">
            <label class="check">
              <input type="checkbox" v-model="esp.solo_consulta_whatsapp" />
              <span>Coordina su hora directo con el cliente, por WhatsApp</span>
            </label>
            <span class="hint">
              <template v-if="esp.solo_consulta_whatsapp">
                En la reserva sale con un botón de WhatsApp en vez de calendario, y
                el asistente de WhatsApp le pasa los datos de quien quiere hora
                (nombre, número, mascota y motivo) para que lo contacte.
                <strong v-if="!String(esp.telefono ?? '').trim()">Necesita un teléfono
                abajo, o el asistente no tiene a quién escribirle.</strong>
              </template>
              <template v-else>La hora se toma con su calendario.</template>
            </span>
          </div>
          <div class="field">
            <label class="check">
              <input type="checkbox" v-model="esp.publico" />
              <span>Aparece en el sitio web y en el portal de reservas</span>
            </label>
            <span class="hint" v-if="!esp.publico">
              <strong>No sale en ningún lugar público</strong>: ni en el equipo del
              sitio, ni en la reserva, ni tiene página propia. El panel y el
              asistente de WhatsApp sí lo ven — es para especialistas externos
              con quienes el asistente coordina.
            </span>
          </div>
        </div>
      </section>

      <!-- ══ Avisos ══ -->
      <section class="card sec">
        <h2 class="section-title">✉ Avisos al especialista</h2>
        <div class="sec-body">
          <div class="row-2">
            <div class="field">
              <label for="e-email">Correo</label>
              <input id="e-email" class="input" type="email" v-model="esp.email"
                placeholder="nombre@correo.cl" />
            </div>
            <div class="field">
              <label for="e-tel">Teléfono</label>
              <input id="e-tel" class="input" v-model="esp.telefono" placeholder="+56 9 …" />
            </div>
          </div>

          <div class="field">
            <label class="check" :class="{ 'check-off': !tieneCorreo }">
              <input type="checkbox" v-model="esp.recibe_avisos" :disabled="!tieneCorreo" />
              <span>Avisarle por correo cuando le tomen, muevan o cancelen una hora</span>
            </label>
            <span class="hint">
              Recibe el paciente, el motivo, el día y hora, y el teléfono del
              tutor. No lleva enlace de pago ni datos de cobro.
              <template v-if="!tieneCorreo"><br />Primero hay que cargarle un correo.</template>
              <template v-else-if="!esp.recibe_avisos"><br />Está apagado: tener el
              correo cargado no es lo mismo que haber acordado con esa persona
              que el sistema le escriba.</template>
              <template v-else><br />Si el calendario de la clínica está conectado a
              Google, además le llega la invitación con su propio recordatorio.</template>
            </span>
          </div>
        </div>
      </section>

      <!-- ══ Calendario de reservas (dónde caen SUS citas) ══ -->
      <section class="card sec">
        <h2 class="section-title">📅 Calendario de reservas</h2>
        <div class="sec-body">
          <p class="hint">
            En qué calendario de Google aparecen las citas de este especialista. Con uno
            propio, sus horas se ven separadas de las del resto, y todo lo que se anote ahí
            le bloquea la agenda. Las citas que ya tiene agendadas se quedan donde están.
          </p>

          <div v-if="!google.conectado" class="aviso">
            <strong>Aún no hay una cuenta de Google conectada para la clínica.</strong>
            <p>Conéctala primero en Config → Integraciones.</p>
          </div>

          <template v-else>
            <div class="field">
              <label for="e-cal">Sus reservas van a</label>
              <div class="cuenta-row">
                <select id="e-cal" class="select" v-model="esp.google_calendar_id">
                  <option :value="null">El calendario de la clínica (compartido)</option>
                  <option v-for="c in calendariosReserva" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                  <option v-if="calAsignadoFuera" :value="calAsignadoFuera">{{ calAsignadoFuera }}</option>
                </select>
              </div>
              <p class="hint">Solo aparecen los calendarios donde la cuenta de la clínica puede crear eventos. Se aplica al guardar.</p>
            </div>

            <div v-if="!esp.google_calendar_id" class="field">
              <label v-if="emailGuardado" class="check">
                <input type="checkbox" v-model="compartirCal" />
                Compartírselo a {{ emailGuardado }} (solo para ver: los cambios se hacen desde el panel)
              </label>
              <p v-else class="hint">Para compartírselo, guárdale primero un correo en «Avisos al especialista».</p>
              <div>
                <button type="button" class="btn btn-ghost btn-sm" :disabled="creandoCal" @click="crearCalendario">
                  {{ creandoCal ? 'Creando…' : 'Crearle su propio calendario' }}
                </button>
              </div>
              <p class="hint">Se crea en la cuenta de la clínica y queda asignado al instante, sin tener que guardar.</p>
            </div>
            <p v-if="calMsg" class="ok-msg">{{ calMsg }}</p>
          </template>
        </div>
      </section>

      <!-- ══ Disponibilidad en Google (personal, no el calendario de reservas) ══ -->
      <section class="card sec">
        <h2 class="section-title">🗓 Disponibilidad en Google</h2>
        <div class="sec-body">
          <p class="hint">
            Calendarios personales de este especialista (ej. su agenda privada) que el
            sistema revisa para no ofrecer horas donde ya tiene un compromiso. Solo se
            leen: aquí no se escribe ninguna cita.
          </p>

          <div v-if="!google.conectado" class="aviso">
            <strong>Aún no hay una cuenta de Google conectada para la clínica.</strong>
            <p>Conéctala primero en Config → Integraciones para poder elegir calendarios aquí.</p>
          </div>

          <template v-else>
            <div class="field">
              <label>Disponibilidad</label>
              <label class="radio">
                <input type="radio" value="todos" v-model="esp.disponibilidad_modo" />
                <span class="radio-txt"><strong>Sin restricciones</strong> — no revisa ningún calendario personal</span>
              </label>
              <label class="radio">
                <input type="radio" value="especificos" v-model="esp.disponibilidad_modo" />
                <span class="radio-txt"><strong>Calendarios específicos</strong> — comprueba solo los siguientes:</span>
              </label>
              <div v-if="esp.disponibilidad_modo === 'especificos'" class="cal-lista">
                <label v-for="c in calendarios" :key="c.id" class="check">
                  <input type="checkbox" :value="c.id" v-model="esp.calendarios_conflicto" />
                  {{ c.nombre }}
                </label>
                <p v-if="!calendarios.length" class="hint">No se pudo cargar la lista de calendarios.</p>
              </div>
            </div>
          </template>
        </div>
      </section>

      <!-- ══ Horarios ══ -->
      <section class="card sec">
        <h2 class="section-title">🕐 Horarios y consultas</h2>
        <div class="sec-body">
          <div class="h-tabs" role="tablist">
            <button
              v-for="(h, i) in esp.horarios" :key="i"
              role="tab" :aria-selected="horarioActivo === i"
              class="h-tab" :class="{ active: horarioActivo === i }"
              @click="horarioActivo = i"
            >
              {{ h.nombre }}
              <span v-if="esp.horarios.length > 1" class="h-x" @click.stop="quitarHorario(i)" aria-label="Eliminar horario">✕</span>
            </button>
            <button class="h-add" @click="agregarHorario" aria-label="Agregar horario">＋</button>
          </div>

          <template v-if="esp.horarios[horarioActivo]">
            <div class="field" style="max-width: 240px">
              <label>Nombre del horario</label>
              <input class="input" v-model="esp.horarios[horarioActivo].nombre" />
            </div>

            <div class="field">
              <label>Horas disponibles</label>
              <div class="dias">
                <div v-for="(dia, di) in DIAS" :key="di" class="dia-row">
                  <span class="dia-nombre">{{ dia.toLowerCase() }}</span>
                  <div class="dia-bloques">
                    <span v-for="b in bloquesDeDia(esp.horarios[horarioActivo], di)" :key="b._i" class="bloque-chip">
                      <input type="time" v-model="esp.horarios[horarioActivo].bloques[b._i].hora_inicio" :aria-label="`Inicio ${dia}`" />
                      –
                      <input type="time" v-model="esp.horarios[horarioActivo].bloques[b._i].hora_fin" :aria-label="`Fin ${dia}`" />
                      <button type="button" class="chip-x" @click="quitarBloque(esp.horarios[horarioActivo], b._i)" :aria-label="`Quitar bloque de ${dia}`">✕</button>
                    </span>
                  </div>
                  <button type="button" class="dia-add" @click="agregarBloque(esp.horarios[horarioActivo], di)" :aria-label="`Agregar bloque a ${dia}`">＋</button>
                </div>
              </div>
            </div>

            <div class="field">
              <label>Consultas</label>
              <span class="hint">Determina qué consultas se pueden reservar en los tiempos anteriores.</span>
              <label class="radio">
                <input type="radio" :value="true" v-model="esp.horarios[horarioActivo].todos_servicios" />
                <span class="radio-txt"><strong>Todos</strong> — cualquier consulta se puede reservar</span>
              </label>
              <label class="radio">
                <input type="radio" :value="false" v-model="esp.horarios[horarioActivo].todos_servicios" />
                <span class="radio-txt"><strong>Algunos</strong> — ciertas consultas se pueden reservar</span>
              </label>
              <div v-if="!esp.horarios[horarioActivo].todos_servicios" class="cal-lista">
                <label v-for="s in servicios" :key="s.id" class="check">
                  <input type="checkbox" :value="s.id" v-model="esp.horarios[horarioActivo].servicio_ids" />
                  {{ s.emoji }} {{ s.nombre }}
                </label>
              </div>
            </div>

            <div class="row-2">
              <div class="field">
                <label>Válida desde</label>
                <label class="radio">
                  <input type="radio" value="ahora" v-model="esp.horarios[horarioActivo]._desdeModo" />
                  <span class="radio-txt"><strong>Ahora</strong> — válida desde este momento</span>
                </label>
                <label class="radio">
                  <input type="radio" value="fecha" v-model="esp.horarios[horarioActivo]._desdeModo" />
                  <strong>Comienza en la fecha:</strong>
                </label>
                <input
                  v-if="esp.horarios[horarioActivo]._desdeModo === 'fecha'"
                  type="date" class="input" v-model="esp.horarios[horarioActivo].valido_desde"
                />
              </div>
              <div class="field">
                <label>Válido hasta</label>
                <label class="radio">
                  <input type="radio" value="nunca" v-model="esp.horarios[horarioActivo]._hastaModo" />
                  <span class="radio-txt"><strong>Nunca termina</strong> — válida para siempre</span>
                </label>
                <label class="radio">
                  <input type="radio" value="fecha" v-model="esp.horarios[horarioActivo]._hastaModo" />
                  <strong>Termina en la fecha:</strong>
                </label>
                <input
                  v-if="esp.horarios[horarioActivo]._hastaModo === 'fecha'"
                  type="date" class="input" v-model="esp.horarios[horarioActivo].valido_hasta"
                />
              </div>
            </div>
          </template>
        </div>
      </section>

      <div class="guardar-bar">
        <span v-if="okMsg" class="ok-msg">{{ okMsg }}</span>
        <span v-if="error" class="error-text">{{ error }}</span>
        <button class="btn btn-primary" :disabled="busy" @click="guardar">
          {{ busy ? 'Guardando…' : 'Guardar cambios' }}
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.miga { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 1.2rem; color: var(--ink-soft); }
.miga a { color: var(--brand-ink); font-weight: 600; }
.miga a:hover { text-decoration: underline; }

.sec { padding: 0; margin-bottom: 1.2rem; overflow: hidden; }
/* `.section-title` (app.css) trae margen negativo para comerse el padding de
   un `.card` normal. Acá la tarjeta no tiene padding, así que ese margen lo
   sacaba 17 px por arriba y 18 px por los lados, y el `overflow: hidden` de
   `.sec` cortaba el título y el borde derecho de todo lo de abajo. */
.sec > .section-title { margin: 0; border-radius: 0; }
.sec-head {
  font-size: var(--t-lg); padding: 0.9rem 1.4rem;
  background: var(--bg); border-bottom: 1px solid var(--line);
}
.sec-body { padding: 1.4rem; display: flex; flex-direction: column; gap: 1.1rem; }
.row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 640px) { .row-2 { grid-template-columns: 1fr; } }
/* En celular el bloque de horas (dos campos de hora, ~210 px) no cabe al lado
   del nombre del día y sacaba el «+» de la tarjeta: el día pasa arriba. */
@media (max-width: 480px) {
  .dia-row { flex-wrap: wrap; row-gap: 0.4rem; }
  .dia-nombre { width: auto; flex: 1 0 100%; }
}
.hint { font-size: var(--t-micro); color: var(--ink-soft); }

.slug-row { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.slug-fix { font-size: var(--t-sm); color: var(--ink-soft); white-space: nowrap; }
.slug-row .input { max-width: 240px; }

.foto-row { display: flex; align-items: center; gap: 0.8rem; }
.foto { width: 72px; height: 72px; border-radius: var(--r-md); object-fit: cover; }
.foto-ph {
  display: grid; place-items: center; font-size: var(--t-2xl);
  background: var(--brand-tint);
}
.sr-only-file { position: absolute; width: 1px; height: 1px; opacity: 0; }

.aviso {
  background: var(--warn-tint); color: var(--warn-ink);
  border-radius: var(--r-md); padding: 0.9rem 1.1rem; font-size: var(--t-base);
}
.cuenta-row { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.cuenta-row .select { max-width: 340px; }
.radio { display: flex; gap: 0.55rem; align-items: baseline; font-size: var(--t-base); padding: 0.2rem 0; cursor: pointer; }
/* El texto de la opción va en UN solo bloque: suelto, cada trozo («Sin
   restricciones», «— no revisa…») era su propio ítem flex y en pantallas
   angostas se partían en dos columnas. */
.radio > .radio-txt { flex: 1; min-width: 0; }
.cal-lista {
  border: 1px solid var(--line); border-radius: var(--r-md);
  padding: 0.7rem 1rem; margin-top: 0.4rem;
  display: flex; flex-direction: column; gap: 0.3rem;
  max-height: 260px; overflow-y: auto;
}
.check { display: flex; align-items: center; gap: 0.5rem; font-size: var(--t-base); cursor: pointer; }
.check-off { opacity: 0.55; cursor: not-allowed; }

.h-tabs { display: flex; gap: 0.4rem; border-bottom: 1px solid var(--line); padding-bottom: 0.6rem; flex-wrap: wrap; }
.h-tab {
  display: inline-flex; align-items: center; gap: 0.5rem;
  padding: 0.45em 1em; border-radius: var(--r-md);
  border: 1.5px solid var(--line-strong); font-weight: 600; font-size: var(--t-base);
}
.h-tab.active { border-color: var(--brand); background: var(--brand-tint); color: var(--brand-ink); }
.h-x { color: var(--danger); font-size: var(--t-micro); }
.h-add {
  width: 34px; height: 34px; border-radius: 50%;
  border: 1.5px dashed var(--line-strong); color: var(--ink-soft); font-size: 1.1rem;
}
.h-add:hover { border-color: var(--accent); color: var(--accent-strong); background: var(--accent-tint); }

.dias { display: flex; flex-direction: column; }
.dia-row {
  display: flex; align-items: center; gap: 0.8rem;
  padding: 0.5rem 0; border-bottom: 1px solid var(--line-tabla);
}
.dia-nombre { width: 84px; flex: none; font-size: var(--t-base); color: var(--ink-soft); }
.dia-bloques { flex: 1; display: flex; gap: 0.5rem; flex-wrap: wrap; }
.bloque-chip {
  display: inline-flex; align-items: center; gap: 0.3rem;
  border: 1.5px solid var(--brand-tint); background: var(--brand-tint);
  color: var(--brand-ink);
  border-radius: var(--r-sm); padding: 0.2rem 0.5rem; font-size: var(--t-sm); font-weight: 600;
}
.bloque-chip input[type='time'] { border: none; background: transparent; font: inherit; color: inherit; width: 76px; }
.chip-x { color: var(--danger); font-size: var(--t-micro); padding: 0 0.2rem; }
.dia-add {
  width: 30px; height: 30px; border-radius: 50%; flex: none;
  border: 1.5px dashed var(--line-strong); color: var(--ink-soft);
}
.dia-add:hover { border-color: var(--accent); color: var(--accent-strong); background: var(--accent-tint); }

.guardar-bar {
  position: sticky; bottom: 0;
  display: flex; justify-content: flex-end; align-items: center; gap: 1rem;
  padding: 0.9rem 1.2rem;
  background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg);
  box-shadow: var(--shadow-lg);
}
.ok-msg { color: var(--ok); font-weight: 500; font-size: var(--t-base); }
</style>
