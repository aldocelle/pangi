<script setup>
import { ref, computed, onMounted } from 'vue';
import { api, apiError } from '../api.js';
import { useAuthStore } from '../stores/auth.js';
import GrillaFichaje from '../components/GrillaFichaje.vue';

const auth = useAuthStore();

/* La pantalla tiene dos mitades y NO piden lo mismo, igual que el backend:
   fichar lo propio no exige permiso de área —si lo exigiera, quien no lo
   tiene se quedaría sin poder marcar, que es lo contrario de para lo que
   existe— y ver las horas del equipo sí. Por eso el ítem del menú es visible
   para todo el staff y lo que se esconde es la tabla de abajo. */
const puedeVerEquipo = computed(() => auth.puede('asistencia'));

const estado = ref('salida');       // 'entrada' = está dentro
const misMarcas = ref([]);
const miResumen = ref(null);
const equipo = ref([]);
const semanaEquipo = ref([]);
const roster = ref([]);       // staff activo + estado actual (mismos datos que el kiosco)
const hoyEquipo = ref([]);    // quién ya marcó algo hoy (para distinguir "aún no llega" de "ya se fue")
const cargando = ref(true);
const marcando = ref(false);
const error = ref('');

function hoyISO(d = new Date()) {
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}
function primeroDelMes() {
  const d = new Date(); d.setDate(1); return hoyISO(d);
}
const desde = ref(primeroDelMes());
const hasta = ref(hoyISO());

// Lunes a domingo de la semana en curso, calculado en fechas locales (no a
// partir del string ISO, que al re-parsear corre un día por el huso).
const diasSemana = computed(() => {
  const hoy = new Date();
  const lunes = new Date(hoy);
  lunes.setDate(hoy.getDate() - ((hoy.getDay() + 6) % 7));
  return ['L', 'M', 'M', 'J', 'V', 'S', 'D'].map((etiqueta, i) => {
    const d = new Date(lunes);
    d.setDate(lunes.getDate() + i);
    return { etiqueta, fecha: hoyISO(d) };
  });
});

// Vista previa del kiosco (mismo patrón que Ajustes → Correos: se arma con el
// código y los datos reales, y de acá no sale ni entra nada). Se pide recién
// al abrirla, no en `onMounted`: a diferencia de los correos —con datos de
// ejemplo— esto son las horas reales del equipo, y no hace falta pedirlas si
// nadie va a mirar la vista previa.
const mostrarPreview = ref(false);
const previewPersonas = ref([]);
const previewCargando = ref(false);
const previewError = ref('');
const previewAviso = ref(false);

async function abrirPreview() {
  mostrarPreview.value = true;
  if (previewPersonas.value.length || previewCargando.value) return;
  previewCargando.value = true;
  previewError.value = '';
  try {
    const { data } = await api.get('/asistencia/vista-previa');
    previewPersonas.value = data.personas;
  } catch (err) {
    previewError.value = apiError(err, 'No se pudo cargar la vista previa.');
  } finally {
    previewCargando.value = false;
  }
}

function tocarPreview() {
  previewAviso.value = true;
  setTimeout(() => { previewAviso.value = false; }, 2200);
}

function horas(min) {
  const h = Math.floor(min / 60);
  const m = min % 60;
  return m ? `${h} h ${m} min` : `${h} h`;
}
function cuando(iso) {
  return new Date(iso).toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' });
}
// `por_dia` ya viene del backend (Asistencia::resumen / asistencia_service):
// no hace falta un endpoint nuevo, solo pedir el rango de la semana aparte
// del rango mensual que ya se pedía.
function minutosDelDia(persona, fecha) {
  return persona.por_dia?.find((d) => d.fecha === fecha)?.minutos ?? 0;
}

// KPIs del día: se derivan de roster() (estado actual de cada activo) y de
// quién ya marcó algo hoy, nunca de una tabla de turnos — acá no existe un
// horario por persona (eso es cosa de los especialistas, §3.1), así que no
// hay forma real de distinguir "pendiente" de "ausente sin aviso": ambos son
// "no ha marcado entrada hoy".
const marcaronHoyIds = computed(() => new Set(hoyEquipo.value.map((p) => p.usuario_id)));
const enClinica = computed(() => roster.value.filter((p) => p.estado === 'entrada'));
const pendientes = computed(() => roster.value.filter((p) => p.estado === 'salida' && !marcaronHoyIds.value.has(p.usuario_id)));
const jornadaCumplida = computed(() => roster.value.filter((p) => p.estado === 'salida' && marcaronHoyIds.value.has(p.usuario_id)));

// ── Registro de hoy: mismo roster, con las horas de hoy (hoyEquipo) y un
// filtro/búsqueda en memoria — igual que el buscador de la Agenda, no hace
// falta un endpoint nuevo para filtrar seis o diez personas. ─────────────
const registroFiltro = ref('todos');
const registroBusqueda = ref('');
const registroFilas = computed(() => {
  const minutosHoy = new Map(hoyEquipo.value.map((p) => [p.usuario_id, p.minutos]));
  let filas = roster.value.map((p) => ({
    ...p,
    minutosHoy: minutosHoy.get(p.usuario_id) ?? 0,
    marcoHoy: marcaronHoyIds.value.has(p.usuario_id),
  }));
  if (registroFiltro.value === 'en_clinica') filas = filas.filter((p) => p.estado === 'entrada');
  else if (registroFiltro.value === 'finalizados') filas = filas.filter((p) => p.estado === 'salida' && p.marcoHoy);
  const q = registroBusqueda.value.trim().toLowerCase();
  if (q) filas = filas.filter((p) => p.nombre.toLowerCase().includes(q) || (p.cargo || '').toLowerCase().includes(q));
  return filas;
});

async function cargar() {
  cargando.value = true;
  error.value = '';
  try {
    const peticiones = [api.get('/asistencia/mi-estado'), api.get('/asistencia/mias')];
    if (puedeVerEquipo.value) {
      peticiones.push(api.get('/asistencia/resumen', { params: { desde: desde.value, hasta: hasta.value } }));
      peticiones.push(api.get('/asistencia/resumen', {
        params: { desde: diasSemana.value[0].fecha, hasta: diasSemana.value[6].fecha },
      }));
      // /asistencia/roster es del kiosco (token de dispositivo, sin JWT);
      // desde el panel va por /asistencia/vista-previa, mismo roster() pero
      // autenticado con el staff — igual que ya hacía abrirPreview().
      peticiones.push(api.get('/asistencia/vista-previa'));
      peticiones.push(api.get('/asistencia/resumen', { params: { desde: hoyISO(), hasta: hoyISO() } }));
    }
    const [e, m, r, s, ros, h] = await Promise.all(peticiones);
    estado.value = e.data.estado;
    misMarcas.value = m.data.marcas;
    miResumen.value = m.data.resumen[0] || null;
    if (r) equipo.value = r.data.personas;
    if (s) semanaEquipo.value = s.data.personas;
    if (ros) roster.value = ros.data.personas;
    if (h) hoyEquipo.value = h.data.personas;
  } catch (err) {
    error.value = apiError(err, 'No se pudo cargar la asistencia.');
  } finally {
    cargando.value = false;
  }
}

// ── Marcar asistencia de otra persona (permiso `asistencia`) ──────────────
// El backend ya lo soporta en el mismo POST /asistencia/marcar, con
// `usuario_id` ajeno: queda auditado y con `registrado_por_id`, porque es
// una CORRECCIÓN, no la propia marca de quien la hace (ver el controlador).
const relojTexto = ref('');
function actualizarReloj() {
  relojTexto.value = new Date().toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
}
const personaElegidaId = ref('');
const personaElegida = computed(() => roster.value.find((p) => p.usuario_id === personaElegidaId.value) || null);
const marcandoOtro = ref(false);
const errorOtro = ref('');

async function marcarOtro(tipo) {
  if (!personaElegidaId.value) return;
  marcandoOtro.value = true;
  errorOtro.value = '';
  try {
    await api.post('/asistencia/marcar', { usuario_id: personaElegidaId.value, tipo });
    await cargar();
  } catch (err) {
    errorOtro.value = apiError(err, 'No se pudo registrar la marca.');
  } finally {
    marcandoOtro.value = false;
  }
}

// Lo que queda por revisar: entradas sin salida o salidas sueltas del
// equipo en el período mensual ya cargado abajo — el mismo dato de "Horas
// del equipo", no uno inventado para esta tarjeta.
const porRevisar = computed(() =>
  equipo.value.reduce((n, p) => n + (p.sin_cerrar || 0) + (p.salidas_huerfanas || 0), 0));

async function fichar() {
  marcando.value = true;
  error.value = '';
  try {
    // El backend decide el momento: la hora del servidor es la que vale, y
    // no la del computador del mostrador, que puede estar corrido.
    await api.post('/asistencia/marcar', { tipo: estado.value === 'entrada' ? 'salida' : 'entrada' });
    await cargar();
  } catch (err) {
    error.value = apiError(err, 'No se pudo registrar la marca.');
  } finally {
    marcando.value = false;
  }
}

onMounted(() => {
  cargar();
  actualizarReloj();
  setInterval(actualizarReloj, 1000);
});
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Asistencia</h1>
        <p class="page-sub">Marca tu entrada y tu salida. Las horas se calculan solas.</p>
      </div>
    </div>

    <p v-if="error" class="error-text">{{ error }}</p>

    <!-- ── KPIs del día: solo con el permiso, mismo criterio que el resto ── -->
    <div v-if="puedeVerEquipo" class="kpi-grid">
      <div class="kpi-card">
        <span class="kpi-icono azul" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </span>
        <div><p class="kpi-label">Equipo de hoy</p><p class="kpi-num">{{ roster.length }}</p></div>
      </div>
      <div class="kpi-card">
        <span class="kpi-icono verde" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/></svg>
        </span>
        <div><p class="kpi-label">En clínica</p><p class="kpi-num">{{ enClinica.length }}</p></div>
      </div>
      <div class="kpi-card">
        <span class="kpi-icono naranja" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </span>
        <div><p class="kpi-label">Pendientes</p><p class="kpi-num">{{ pendientes.length }}</p></div>
      </div>
      <div class="kpi-card">
        <span class="kpi-icono turquesa" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4 12 14.01l-3-3"/></svg>
        </span>
        <!-- No hay horario por persona en este sistema (ver código), así
             que no existe un dato real de "ausencia": lo honesto es contar
             a quien ya cerró su turno hoy, no inventar una falta. -->
        <div><p class="kpi-label">Jornada cumplida</p><p class="kpi-num">{{ jornadaCumplida.length }}</p></div>
      </div>
    </div>

    <!-- ── Fila de dos columnas: mi fichaje + marcar a otra persona ──── -->
    <div class="fila-dos">
      <div class="col-izq">
        <!-- ── Registro de hoy: el mismo roster del kiosco, con filtro y
             buscador — igual criterio que el buscador de la Agenda. ──── -->
        <div v-if="puedeVerEquipo" class="card registro-card">
          <div class="registro-head">
            <h2 class="sub registro-titulo">Registro de hoy</h2>
            <div class="registro-tabs">
              <button
                v-for="f in [['todos','Todos'],['en_clinica','En clínica'],['finalizados','Finalizados']]"
                :key="f[0]" type="button" class="tab" :class="{ activo: registroFiltro === f[0] }"
                @click="registroFiltro = f[0]"
              >{{ f[1] }}</button>
            </div>
            <input class="input registro-buscar" type="search" placeholder="Buscar integrante" v-model="registroBusqueda">
          </div>
          <p v-if="cargando" class="empty">Cargando…</p>
          <p v-else-if="!registroFilas.length" class="empty">Nadie calza con el filtro.</p>
          <div v-else class="scroll-x">
            <table class="tabla-datos">
              <thead>
                <tr><th>Integrante</th><th>Estado</th><th class="num">Horas hoy</th></tr>
              </thead>
              <tbody>
                <tr v-for="p in registroFilas" :key="p.usuario_id">
                  <td>
                    <strong>{{ p.nombre }}</strong>
                    <span v-if="p.cargo" class="registro-cargo">{{ p.cargo }}</span>
                  </td>
                  <td>
                    <span class="badge" :class="p.estado === 'entrada' ? 'entrada' : 'salida'">
                      {{ p.estado === 'entrada' ? 'En clínica' : (p.marcoHoy ? 'Finalizó' : 'Pendiente') }}
                    </span>
                  </td>
                  <td class="num apagado">{{ p.minutosHoy ? horas(p.minutosHoy) : '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card ficha-card">
          <div class="ficha-estado">
            <span class="punto" :class="estado"></span>
            <div>
              <strong>{{ estado === 'entrada' ? 'Estás dentro' : 'Estás fuera' }}</strong>
              <span v-if="miResumen" class="ficha-sub">
                {{ horas(miResumen.minutos) }} este período · {{ miResumen.turnos }} turno(s)
              </span>
            </div>
          </div>
          <button
            class="btn" :class="estado === 'entrada' ? 'btn-ghost' : 'btn-accent'"
            :disabled="marcando || cargando" @click="fichar"
          >{{ estado === 'entrada' ? 'Marcar salida' : 'Marcar entrada' }}</button>
        </div>

        <!-- Lo que no cierra se avisa a quien puede arreglarlo: es su propia
             marca, y verlo al día siguiente es cuando todavía se acuerda. -->
        <p v-if="miResumen && miResumen.sin_cerrar" class="aviso">
          Tienes una entrada sin salida en este período. No suma horas hasta que se corrija.
        </p>

        <details class="card desplegable">
          <summary class="sub sub-desplegable">Mis marcas</summary>
          <p v-if="cargando" class="empty">Cargando…</p>
          <p v-else-if="!misMarcas.length" class="empty">Todavía no has marcado nada.</p>
          <div v-else class="tabla">
            <div class="t-head t-row"><span>Cuándo</span><span>Qué</span><span>Nota</span></div>
            <div v-for="m in misMarcas" :key="m.id" class="t-row">
              <span class="cuando">{{ cuando(m.momento) }}</span>
              <span><span class="badge" :class="m.tipo">{{ m.tipo }}</span></span>
              <span class="nota">
                {{ m.nota || '—' }}
                <!-- Una marca puesta por otra persona es una corrección, y se
                     dice: si no, es indistinguible de una real y el registro
                     deja de servir para lo único que sirve, que es contar
                     horas. -->
                <em v-if="m.registrado_por_id" class="corregida">registrada por otra persona</em>
              </span>
            </div>
          </div>
        </details>
      </div>

      <!-- ── Marcar a otra persona: mismo /asistencia/marcar con
           `usuario_id`, queda auditado como corrección. Solo con el
           permiso que ya pide ver las horas del equipo. ────────────── -->
      <div v-if="puedeVerEquipo" class="col-der card marcar-card">
        <h2 class="sub marcar-titulo">Marcar asistencia</h2>
        <p class="reloj">{{ relojTexto }}</p>
        <p class="page-sub marcar-sub">Selecciona a la persona para registrar</p>
        <select class="input" v-model="personaElegidaId">
          <option value="" disabled>Seleccionar integrante</option>
          <option v-for="p in roster" :key="p.usuario_id" :value="p.usuario_id">
            {{ p.nombre }}{{ p.cargo ? ` · ${p.cargo}` : '' }}
          </option>
        </select>
        <button
          class="btn btn-accent marcar-btn"
          :disabled="!personaElegidaId || marcandoOtro"
          @click="marcarOtro('entrada')"
        >Registrar entrada</button>
        <button
          class="btn btn-ghost marcar-btn"
          :disabled="!personaElegidaId || marcandoOtro"
          @click="marcarOtro('salida')"
        >Registrar salida</button>
        <p v-if="personaElegida" class="marcar-estado">
          {{ personaElegida.nombre }} está {{ personaElegida.estado === 'entrada' ? 'dentro' : 'fuera' }} ahora.
        </p>
        <p v-if="errorOtro" class="error-text">{{ errorOtro }}</p>
        <p v-if="porRevisar" class="aviso marcar-revisar">
          {{ porRevisar }} marcación{{ porRevisar === 1 ? '' : 'es' }} por revisar —
          <a href="#horas-del-equipo">ver abajo</a>
        </p>
      </div>
    </div>

    <!-- ── Las horas del equipo y el kiosco: solo con el permiso ────── -->
    <template v-if="puedeVerEquipo">
      <h2 class="sub">Kiosco de fichaje</h2>
      <div class="card preview-card">
        <div class="preview-head">
          <p class="page-sub preview-intro">
            Así se ve la pantalla del tablet de recepción (<code>/fichaje</code>), con las
            personas y el estado reales. Tocar una tarjeta acá no ficha a nadie.
          </p>
          <button type="button" class="btn btn-ghost btn-sm" @click="abrirPreview">
            {{ mostrarPreview ? 'Actualizar' : 'Ver vista previa' }}
          </button>
        </div>

        <template v-if="mostrarPreview">
          <p v-if="previewCargando" class="empty">Cargando…</p>
          <p v-else-if="previewError" class="error-text">{{ previewError }}</p>
          <p v-else-if="!previewPersonas.length" class="empty">No hay nadie activo para fichar.</p>
          <div v-else class="preview-marco">
            <transition name="fade">
              <p v-if="previewAviso" class="preview-aviso">Es solo una vista previa — no registra nada.</p>
            </transition>
            <GrillaFichaje :personas="previewPersonas" :interactivo="false" @tocar="tocarPreview" />
          </div>
        </template>
      </div>

      <h2 id="horas-del-equipo" class="sub">Horas del equipo</h2>
      <div class="rango card">
        <label class="field"><span>Desde</span><input type="date" class="input" v-model="desde" @change="cargar"></label>
        <label class="field"><span>Hasta</span><input type="date" class="input" v-model="hasta" @change="cargar"></label>
      </div>
      <p v-if="!equipo.length && !cargando" class="empty">Nadie marcó nada en este rango.</p>
      <div v-else-if="equipo.length" class="scroll-x">
        <table class="tabla-datos">
          <thead>
            <tr>
              <th>Persona</th><th class="num">Horas</th><th class="num">Turnos</th><th>Sin cerrar</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in equipo" :key="p.usuario_id">
              <td>{{ p.nombre }}</td>
              <td class="num destacado">{{ horas(p.minutos) }}</td>
              <td class="num apagado">{{ p.turnos }}</td>
              <td>
                <!-- Lo que no cierra va en su propia columna y no escondido en
                     un total: es exactamente lo que hay que corregir ANTES de
                     pagar, y sumarlo o callarlo son las dos formas de que
                     nadie lo mire. -->
                <span v-if="p.sin_cerrar" class="tag req">{{ p.sin_cerrar }} entrada sin salida</span>
                <span v-else-if="p.salidas_huerfanas" class="tag req">{{ p.salidas_huerfanas }} salida suelta</span>
                <span v-else class="apagado">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <h2 class="sub">Asistencia de la semana</h2>
      <p v-if="!semanaEquipo.length && !cargando" class="empty">Nadie marcó nada esta semana.</p>
      <div v-else-if="semanaEquipo.length" class="scroll-x">
        <table class="tabla-datos semana">
          <thead>
            <tr>
              <th>Persona</th>
              <th v-for="d in diasSemana" :key="d.fecha" class="num dia-cab">{{ d.etiqueta }}</th>
              <th class="num">Total</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in semanaEquipo" :key="p.usuario_id">
              <td>{{ p.nombre }}</td>
              <td v-for="d in diasSemana" :key="d.fecha" class="num">
                <span
                  class="dia-punto"
                  :class="{ con: minutosDelDia(p, d.fecha) > 0 }"
                  :title="minutosDelDia(p, d.fecha) > 0 ? horas(minutosDelDia(p, d.fecha)) : 'Sin marca'"
                ></span>
              </td>
              <td class="num destacado">{{ horas(p.minutos) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<style scoped>
.kpi-grid {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;
  margin-bottom: 1.2rem;
}
.kpi-card {
  background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg);
  padding: 1.1rem 1.2rem; display: flex; align-items: center; gap: 0.9rem;
}
.kpi-icono {
  flex: none; width: 44px; height: 44px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
}
.kpi-icono svg { width: 22px; height: 22px; }
.kpi-icono.azul { background: var(--brand-tint); color: var(--brand-ink); }
.kpi-icono.verde { background: var(--ok-tint); color: var(--ok); }
.kpi-icono.naranja { background: var(--warn-tint); color: var(--warn-ink); }
.kpi-icono.turquesa { background: var(--accent-tint); color: var(--accent-strong); }
.kpi-label { margin: 0; font-size: var(--t-sm); color: var(--ink-soft); }
.kpi-num { margin: 0.1rem 0 0; font-size: var(--t-2xl); font-weight: 700; }
@media (max-width: 860px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }

.fila-dos { display: flex; gap: 1rem; align-items: flex-start; flex-wrap: wrap; margin-top: 0.8rem; }
.col-izq { flex: 1 1 420px; min-width: 0; display: flex; flex-direction: column; gap: 0.8rem; }
.col-der { flex: 1 1 320px; min-width: 0; }

.desplegable { padding: 0; }
.desplegable > .tabla { border-top: 1px solid var(--line); }
.desplegable > .empty { padding: 1.2rem; }
.sub-desplegable {
  cursor: pointer; list-style: none; padding: 1rem 1.2rem; margin: 0;
  display: flex; align-items: center; justify-content: space-between;
}
.sub-desplegable::-webkit-details-marker { display: none; }
.sub-desplegable::after { content: '▾'; color: var(--ink-soft); font-size: var(--t-sm); }
details[open] > .sub-desplegable::after { content: '▴'; }

.marcar-card { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-align: center; padding: 1.4rem 1.3rem; }
.marcar-titulo { margin: 0 0 0.4rem; }
.reloj {
  font-variant-numeric: tabular-nums; font-size: 2.4rem; font-weight: 700;
  color: var(--brand-ink); margin: 0 0 0.1rem;
}
.marcar-sub { margin: 0 0 0.4rem; }
.marcar-card select.input { width: 100%; margin-bottom: 0.3rem; }
.marcar-btn { width: 100%; justify-content: center; }
.marcar-estado { margin: 0.2rem 0 0; font-size: var(--t-sm); color: var(--ink-soft); }
.marcar-revisar {
  width: 100%; box-sizing: border-box; text-align: left; margin-top: 0.4rem;
  display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;
}
.marcar-revisar a { color: var(--warn-ink); font-weight: 600; white-space: nowrap; }

.registro-card { display: flex; flex-direction: column; gap: 0.8rem; }
.registro-head { display: flex; flex-wrap: wrap; align-items: center; gap: 0.7rem; }
.registro-titulo { margin: 0; flex: 1 1 auto; }
.registro-tabs { display: flex; gap: 0.3rem; background: var(--bg); border-radius: var(--r-full); padding: 0.2rem; }
.tab {
  border: none; background: transparent; padding: 0.35rem 0.8rem; border-radius: var(--r-full);
  font-size: var(--t-sm); color: var(--ink-soft); cursor: pointer;
}
.tab.activo { background: var(--brand-ink); color: #fff; }
.registro-buscar { width: 11rem; }
.registro-cargo { display: block; font-size: var(--t-micro); color: var(--ink-soft); }

.sub { font-size: var(--t-lg); margin: 1.4rem 0 0.6rem; }
.empty { text-align: center; color: var(--ink-soft); padding: 1.6rem 1rem; }

.ficha-card { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.ficha-estado { display: flex; align-items: center; gap: 0.7rem; }
.ficha-estado strong { display: block; }
.ficha-sub { color: var(--ink-soft); font-size: var(--t-sm); }
/* El punto es la señal que se lee sin leer: verde dentro, apagado fuera.
   Nunca va solo — el texto de al lado dice lo mismo con palabras, porque el
   color no puede ser la única señal. */
.punto { width: 12px; height: 12px; border-radius: 50%; flex: none; background: var(--line-strong); }
.punto.entrada { background: var(--ok); }

.aviso {
  background: var(--warn-tint); color: var(--warn-ink);
  padding: 0.7rem 1rem; border-radius: var(--r-md);
  font-size: var(--t-sm); margin-top: 0.8rem;
}

.t-row { grid-template-columns: 1.1fr 0.7fr 1.6fr; min-width: 520px; }
.cuando { color: var(--ink-soft); font-variant-numeric: tabular-nums; }
.nota { min-width: 0; }
.corregida { display: block; color: var(--ink-soft); font-size: var(--t-micro); font-style: normal; }
.badge.entrada { background: var(--ok-tint); color: var(--ok); }
.badge.salida { background: var(--brand-tint); color: var(--brand-ink); }

.rango { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.8rem; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.destacado { font-weight: 700; }
.apagado { color: var(--ink-soft); }

@media (max-width: 720px) { .t-row { min-width: 0; } }

.preview-card { margin-bottom: 1.2rem; display: flex; flex-direction: column; gap: 0.9rem; }
.preview-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.preview-intro { margin: 0; max-width: 38rem; }
.preview-intro code { background: var(--bg); padding: 0.05em 0.35em; border-radius: var(--r-sm); }
/* Fondo oscuro para que se note que esto es "el tablet metido en una
   ventana" y no una tabla más del panel — mismo criterio que el fondo blanco
   fijo del marco de Correos. */
.preview-marco {
  position: relative; background: var(--bg); border: 1px solid var(--line);
  border-radius: var(--r-lg); padding: clamp(0.8rem, 2vw, 1.4rem);
}
.preview-aviso {
  position: absolute; top: 0.6rem; left: 50%; transform: translateX(-50%); z-index: 1;
  background: var(--ink); color: #fff; padding: 0.45rem 0.9rem;
  border-radius: var(--r-full); font-size: var(--t-sm); white-space: nowrap;
}
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.semana .dia-cab { width: 2.4rem; }
.dia-punto { display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: var(--line-strong); }
.dia-punto.con { background: var(--ok); }
</style>
