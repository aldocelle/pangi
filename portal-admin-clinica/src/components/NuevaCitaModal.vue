<script setup>
import { ref, computed, onMounted } from 'vue';
import { api, apiError, formatCLP } from '../api.js';

const props = defineProps({
  fechaInicial: { type: String, default: '' }, // YYYY-MM-DD, precarga la fecha de la Agenda
  /* Desde un hueco de la Agenda: quién y a qué hora. Se usan una sola vez, para
     saltar los pasos que ya se respondieron al tocar la grilla; si esa hora no
     está libre para la consulta elegida, se queda en el paso de horario. */
  especialistaInicial: { type: String, default: '' },
  horaInicial: { type: String, default: '' }, // HH:MM
});
const emit = defineEmits(['close', 'creada']);

/* pasos: 1 mascota · 2 servicio · 3 especialista · 4 hora · 5 confirmar */
const paso = ref(1);
const mascotas = ref([]);
const servicios = ref([]);
const especialistas = ref([]);
const cargandoBase = ref(true);
const errorBase = ref('');

const qMascota = ref('');
const mascota = ref(null);
const servicio = ref(null);
const especialista = ref(null);
const slots = ref({});
const semana = ref(0);
const MAX_SEMANAS = 12;
const fecha = ref('');
const hora = ref('');
const notas = ref('');

const loadingPaso = ref(false);
const error = ref('');
const busy = ref(false);
const forzar = ref(false);
const preferenciaPendiente = ref(!!(props.especialistaInicial || props.horaInicial));
const horaNoLibre = ref(false);

const mascotasFiltradas = computed(() => {
  const t = qMascota.value.trim().toLowerCase();
  if (!t) return mascotas.value.slice(0, 30);
  return mascotas.value.filter(
    (m) => m.nombre.toLowerCase().includes(t) || (m.dueno_nombre || '').toLowerCase().includes(t)
  );
});

function isoAddDays(base, n) {
  const [y, m, d] = base.split('-').map(Number);
  const dt = new Date(y, m - 1, d + n);
  return new Date(dt.getTime() - dt.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

const semanaCompleta = computed(() => {
  const base = props.fechaInicial || isoAddDays(new Date().toISOString().slice(0, 10), 0);
  const hoyISO = new Date().toISOString().slice(0, 10);
  const out = [];
  for (let i = 0; i < 7; i++) {
    const iso = isoAddDays(base, semana.value * 7 + i);
    const [y, m, d] = iso.split('-').map(Number);
    const dt = new Date(y, m - 1, d);
    const horas = slots.value[iso] || [];
    out.push({
      iso,
      diaNum: d,
      mes: dt.toLocaleDateString('es-CL', { month: 'short' }).replace('.', ''),
      diaCorto: dt.toLocaleDateString('es-CL', { weekday: 'short' }).replace('.', ''),
      esHoy: iso === hoyISO,
      total: horas.length,
      horas,
    });
  }
  return out;
});
const totalSemana = computed(() => semanaCompleta.value.reduce((n, d) => n + d.total, 0));

function labelFechaLarga(iso) {
  if (!iso) return '';
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}
function durLabel(min) {
  return min >= 60 ? `${min / 60} hora${min > 60 ? 's' : ''}` : `${min} minutos`;
}

function elegirMascota(m) {
  mascota.value = m;
  paso.value = 2;
}

async function elegirServicio(s) {
  servicio.value = s;
  especialista.value = null;
  loadingPaso.value = true;
  paso.value = 3;
  try {
    const { data } = await api.get('/catalogo/especialistas', { params: { servicio_id: s.id } });
    especialistas.value = data;
    const preferido = preferenciaPendiente.value && data.find((e) => e.id === props.especialistaInicial);
    if (preferido) {
      loadingPaso.value = false;
      await elegirEspecialista(preferido);
      return;
    }
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar los especialistas.');
  } finally {
    loadingPaso.value = false;
  }
}

async function elegirEspecialista(e) {
  especialista.value = e;
  fecha.value = '';
  hora.value = '';
  semana.value = 0;
  paso.value = 4;
  await cargarSemana();
}

async function cargarSemana() {
  loadingPaso.value = true;
  error.value = '';
  try {
    const { data } = await api.get('/citas/slots', {
      params: {
        servicio_id: servicio.value.id,
        especialista_id: especialista.value.id,
        desde: semanaCompleta.value[0]?.iso || props.fechaInicial,
        dias: 7,
      },
    });
    slots.value = data;
    if (preferenciaPendiente.value && props.horaInicial && props.fechaInicial && semana.value === 0) {
      preferenciaPendiente.value = false;
      if ((data[props.fechaInicial] || []).includes(props.horaInicial)) {
        elegirHora(props.fechaInicial, props.horaInicial);
      } else {
        horaNoLibre.value = true;
      }
    }
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar los horarios.');
  } finally {
    loadingPaso.value = false;
  }
}

function moverSemana(delta) {
  const nueva = semana.value + delta;
  if (nueva < 0 || nueva > MAX_SEMANAS) return;
  semana.value = nueva;
  cargarSemana();
}

function elegirHora(dia, h) {
  fecha.value = dia;
  hora.value = h;
  forzar.value = false;
  paso.value = 5;
}

function abrirForzado() {
  // Caso excepcional: el staff agenda igual aunque el sistema no muestre el
  // horario como disponible (ej. el veterinario confirmó verbalmente).
  const hoyISO = new Date().toISOString().slice(0, 10);
  fecha.value = props.fechaInicial || hoyISO;
  hora.value = props.horaInicial || '09:00';
  forzar.value = true;
  paso.value = 5;
}

async function confirmar() {
  busy.value = true;
  error.value = '';
  try {
    const { data } = await api.post('/citas/crear-staff', {
      mascota_id: mascota.value.id,
      servicio_id: servicio.value.id,
      especialista_id: especialista.value.id,
      fecha_hora: `${fecha.value}T${hora.value}:00`,
      notas_pre_cita: notas.value.trim() || null,
      forzar: forzar.value,
    });
    emit('creada', data);
  } catch (e) {
    error.value = apiError(e, 'No se pudo crear la cita.');
    if (e.response?.status === 409) {
      forzar.value = false;
      await elegirEspecialista(especialista.value);
    }
  } finally {
    busy.value = false;
  }
}

onMounted(async () => {
  try {
    const [m, s] = await Promise.all([
      api.get('/mascotas/todas'),
      api.get('/catalogo/servicios/todos'),
    ]);
    mascotas.value = m.data;
    servicios.value = s.data.filter((x) => x.activo);
  } catch (e) {
    errorBase.value = apiError(e, 'No se pudo cargar la información base.');
  } finally {
    cargandoBase.value = false;
  }
});
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal card nc-modal" role="dialog" aria-modal="true" aria-label="Nueva cita">
      <div class="nc-head">
        <h2>Nueva cita</h2>
        <button class="modal-close" aria-label="Cerrar" @click="emit('close')">✕</button>
      </div>

      <nav v-if="mascota" class="migas">
        <button :class="{ done: paso > 1, current: paso === 1 }" @click="paso = 1">{{ mascota.nombre }}</button>
        <button :class="{ done: paso > 2, current: paso === 2 }" :disabled="paso < 2" @click="servicio && (paso = 2)">Servicio</button>
        <button :class="{ done: paso > 3, current: paso === 3 }" :disabled="paso < 3" @click="especialista && (paso = 3)">Especialista</button>
        <button :class="{ done: paso > 4, current: paso === 4 }" :disabled="paso < 4">Horario</button>
        <button :class="{ current: paso === 5 }" :disabled="paso < 5">Confirmar</button>
      </nav>

      <p v-if="cargandoBase" class="empty">Cargando…</p>
      <p v-else-if="errorBase" class="error-text">{{ errorBase }}</p>

      <div v-else class="nc-body">
        <!-- Paso 1: mascota (busca por nombre de mascota o de dueño) -->
        <section v-if="paso === 1">
          <input class="input" v-model="qMascota" placeholder="Buscar por mascota o dueño…" autofocus />
          <div class="opciones-list">
            <button v-for="m in mascotasFiltradas" :key="m.id" class="opcion-row" @click="elegirMascota(m)">
              <strong>{{ m.nombre }}</strong>
              <span class="muted cap">{{ m.especie }}<template v-if="m.raza"> · {{ m.raza }}</template></span>
              <span class="muted">{{ m.dueno_nombre }}</span>
              <span class="opcion-go">→</span>
            </button>
            <p v-if="!mascotasFiltradas.length" class="empty">Sin resultados.</p>
          </div>
        </section>

        <!-- Paso 2: servicio -->
        <section v-else-if="paso === 2">
          <button class="volver-paso" @click="paso = 1">← {{ mascota.nombre }}</button>
          <div class="opciones-list">
            <button v-for="s in servicios" :key="s.id" class="opcion-row" @click="elegirServicio(s)">
              <span class="opcion-emoji">{{ s.emoji }}</span>
              <span class="opcion-info">
                <strong>{{ s.nombre }}</strong>
                <span class="muted">{{ durLabel(s.duracion_min) }}<template v-if="s.precio_clp"> · {{ formatCLP(s.precio_clp) }}</template></span>
              </span>
              <span class="opcion-go">→</span>
            </button>
          </div>
        </section>

        <!-- Paso 3: especialista -->
        <section v-else-if="paso === 3">
          <button class="volver-paso" @click="paso = 2">← {{ servicio.nombre }}</button>
          <p v-if="loadingPaso" class="empty">Cargando…</p>
          <div v-else class="opciones-list">
            <button v-for="e in especialistas" :key="e.id" class="opcion-row" @click="elegirEspecialista(e)">
              <span class="opcion-emoji">{{ e.emoji }}</span>
              <span class="opcion-info">
                <strong>{{ e.nombre }}</strong>
                <span v-if="e.especialidad" class="muted">{{ e.especialidad }}</span>
              </span>
              <span class="opcion-go">→</span>
            </button>
            <p v-if="!especialistas.length" class="empty">Sin especialistas disponibles para este servicio.</p>
          </div>
        </section>

        <!-- Paso 4: horario -->
        <section v-else-if="paso === 4">
          <button class="volver-paso" @click="paso = 3">← {{ especialista.nombre }}</button>

          <div class="sem-nav">
            <button type="button" class="sem-flecha" :disabled="semana === 0" @click="moverSemana(-1)">‹</button>
            <span class="sem-rango">Semana {{ semana + 1 }}</span>
            <button type="button" class="sem-flecha" :disabled="semana >= MAX_SEMANAS" @click="moverSemana(1)">›</button>
          </div>

          <p v-if="loadingPaso" class="empty">Buscando horarios…</p>
          <template v-else>
            <p v-if="horaNoLibre" class="aviso-forzado">
              No hay hora libre a las {{ horaInicial }} para esta consulta. Elige otra o agéndala fuera de disponibilidad.
            </p>
            <div class="dias-scroll">
              <div v-for="d in semanaCompleta" :key="d.iso" class="dia-col" :class="{ hoy: d.esHoy }">
                <div class="dia-cab">
                  <span class="dia-nombre">{{ d.diaCorto }}</span>
                  <span class="dia-num">{{ d.diaNum }} {{ d.mes }}</span>
                </div>
                <div v-if="d.total" class="dia-horas">
                  <button v-for="h in d.horas" :key="h" type="button" class="hora-chip" @click="elegirHora(d.iso, h)">{{ h }}</button>
                </div>
                <div v-else class="dia-cerrado">Sin horas</div>
              </div>
            </div>
            <p v-if="!totalSemana" class="empty">Sin horas disponibles esta semana.</p>
            <button type="button" class="btn btn-ghost btn-sm forzar-btn" @click="abrirForzado">
              Agendar en un horario fuera de disponibilidad
            </button>
          </template>
        </section>

        <!-- Paso 5: confirmar -->
        <section v-else-if="paso === 5">
          <button class="volver-paso" @click="paso = 4">← {{ labelFechaLarga(fecha) }} · {{ hora }} h</button>
          <div class="resumen">
            <div class="res-row"><span>Mascota</span><strong>{{ mascota.nombre }} ({{ mascota.dueno_nombre }})</strong></div>
            <div class="res-row"><span>Servicio</span><strong>{{ servicio.nombre }}</strong></div>
            <div class="res-row"><span>Especialista</span><strong>{{ especialista.nombre }}</strong></div>
            <div class="res-row"><span>Fecha</span><strong class="cap">{{ labelFechaLarga(fecha) }}</strong></div>
            <div class="res-row"><span>Hora</span><strong>{{ hora }} h</strong></div>
          </div>
          <p v-if="forzar" class="aviso-forzado">
            ⚠ Este horario no aparece como disponible en el sistema. Se agendará igual, sin verificar conflictos.
          </p>
          <div class="field">
            <label for="ncm-notas">Notas (opcional)</label>
            <textarea id="ncm-notas" class="input" v-model="notas" rows="2"></textarea>
          </div>
          <p v-if="error" class="error-text">{{ error }}</p>
          <button class="btn btn-primary btn-block" :disabled="busy" @click="confirmar">
            {{ busy ? 'Creando…' : 'Crear cita' }}
          </button>
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped>
.nc-modal { width: 100%; max-width: 480px; max-height: 88vh; overflow-y: auto; padding: 1.4rem; }
.nc-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem; }
.nc-head h2 { font-size: var(--t-xl); }

.migas { display: flex; gap: 0.3rem; flex-wrap: wrap; margin-bottom: 1rem; }
.migas button { font-size: var(--t-micro); font-weight: 600; padding: 0.35em 0.7em; border-radius: var(--r-full); color: var(--ink-soft); background: var(--bg); border: 1px solid var(--line); }
.migas button.current { background: var(--brand); border-color: var(--brand); color: white; }
.migas button.done { color: var(--brand-ink); border-color: var(--brand-tint); background: var(--brand-tint); }
.migas button:disabled { cursor: default; }

.opciones-list { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.8rem; }
.opcion-row { display: flex; align-items: center; gap: 0.8rem; text-align: left; padding: 0.7rem 0.9rem; border: 1.5px solid var(--line); border-radius: var(--r-md); }
.opcion-row:hover { border-color: var(--accent); background: var(--accent-tint); }
.opcion-emoji { width: 38px; height: 38px; flex: none; display: grid; place-items: center; background: var(--brand-tint); border-radius: var(--r-md); font-size: 1.15rem; }
.opcion-info { flex: 1; display: flex; flex-direction: column; gap: 0.15rem; }
.opcion-go { color: var(--brand-ink); font-weight: 700; }
.cap { text-transform: capitalize; }

.volver-paso { display: block; color: var(--ink-soft); font-weight: 500; font-size: var(--t-sm); margin-bottom: 0.7rem; }
.volver-paso:hover { color: var(--accent-strong); }

.sem-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.8rem; }
.sem-flecha { width: 34px; height: 34px; border-radius: var(--r-sm); border: 1.5px solid var(--line-strong); background: var(--surface); font-size: 1.2rem; font-weight: 700; }
.sem-flecha:disabled { opacity: 0.3; }
.sem-rango { font-weight: 600; font-size: var(--t-sm); }

.dias-scroll { display: flex; gap: 0.35rem; overflow-x: auto; padding-bottom: 0.3rem; }
.dia-col { flex: 1; min-width: 76px; display: flex; flex-direction: column; border: 1.5px solid transparent; border-radius: var(--r-md); }
.dia-col.hoy { border-color: var(--accent); background: var(--accent-tint); }
.dia-cab { text-align: center; padding: 0.4rem 0.1rem; border-bottom: 2px solid var(--line); margin-bottom: 0.4rem; }
.dia-nombre { display: block; font-weight: 700; font-size: var(--t-micro); color: var(--brand-ink); text-transform: capitalize; }
.dia-num { display: block; font-size: var(--t-micro); color: var(--ink-soft); }
.dia-horas { display: flex; flex-direction: column; gap: 0.25rem; padding: 0 0.15rem 0.3rem; }
.hora-chip { padding: 0.35em 0.15em; border-radius: var(--r-md); border: 1.5px solid var(--line-strong); background: var(--surface); font-weight: 600; font-size: var(--t-micro); }
.hora-chip:hover { background: var(--accent); border-color: var(--accent); color: #fff; }
.dia-cerrado { text-align: center; font-size: var(--t-micro); color: var(--ink-soft); padding: 0.8rem 0.1rem; }

.forzar-btn { width: 100%; margin-top: 0.8rem; color: var(--ink-soft); }

.resumen { border: 1px solid var(--line); border-radius: var(--r-md); padding: 0.2rem 1rem; background: var(--bg); margin: 0.8rem 0; }
.res-row { display: flex; justify-content: space-between; gap: 1rem; padding: 0.55rem 0; }
.res-row:not(:last-child) { border-bottom: 1px solid var(--line); }
.res-row span { color: var(--ink-soft); font-size: var(--t-sm); }

.aviso-forzado { background: var(--warn-tint); color: var(--warn-ink); padding: 0.6rem 0.8rem; border-radius: var(--r-md); font-size: var(--t-micro); margin-bottom: 0.8rem; }
</style>
