<script setup>
import { onMounted, computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useDatosStore, perdioLaHora, msParaPagar } from '../stores/datos.js';
import { api, fechaLarga, horaCorta, fotoEspecie, urlFoto, apiError } from '../api.js';
import BaseIcon from '../components/BaseIcon.vue';
import CuentaPago from '../components/CuentaPago.vue';
import { CLINICA } from '../config.js';

const datos = useDatosStore();
onMounted(() => datos.cargar());

/* Cancelar cita: el backend ya permite que el dueño cancele su propia cita
   (PATCH /citas/{id}/estado con estado=Cancelada); solo faltaba el botón. */
const cancelandoId = ref(null);
const errorCancelar = ref('');

async function cancelarCita(cita) {
  const fecha = fechaLarga(cita.fecha_hora);
  if (!confirm(`¿Cancelar la cita de ${nombreMasc(cita.mascota_id)} el ${fecha}?`)) return;
  cancelandoId.value = cita.id;
  errorCancelar.value = '';
  try {
    await api.patch(`/citas/${cita.id}/estado`, { estado: 'Cancelada' });
    await datos.cargar(true);
  } catch (e) {
    errorCancelar.value = apiError(e, 'No se pudo cancelar la cita.');
  } finally {
    cancelandoId.value = null;
  }
}

/* Reagendar: el endpoint (PATCH /citas/{id}/reagendar) ya permitía que el
   dueño moviera su propia cita, pero no había ningún botón que lo llamara —
   la única acción del portal era Cancelar. El recordatorio por correo ahora
   invita a reagendar y apunta acá, así que la pantalla tiene que poder
   cumplirlo: una hora movida conserva al paciente, una cancelada lo pierde.

   Reusa /citas/slots con el servicio y el especialista YA congelados en la
   cita: reagendar cambia la hora y nada más. Por eso una cita sin
   servicio_id/especialista_id (importada de ACVet, o creada a mano por el
   staff) no puede reagendarse desde acá — el backend la rechaza con 400 y
   acá directamente no se ofrece el botón. */
const citaReagendando = ref(null);
const slots = ref({});
const semana = ref(0);
const cargandoSlots = ref(false);
const guardandoReagenda = ref(false);
const errorReagendar = ref('');
const nuevaFecha = ref('');
const nuevaHora = ref('');

// Mismo tope que el asistente de reserva (MAX_DIAS_ANTICIPACION = 92 en el
// backend): 13 semanas ≈ 3 meses.
const MAX_SEMANAS = 12;

function puedeReagendar(c) {
  return !!c.servicio_id && !!c.especialista_id && c.estado !== 'Cancelada';
}

function isoMasDias(dias) {
  const d = new Date();
  d.setDate(d.getDate() + dias);
  return d.toISOString().slice(0, 10);
}

async function abrirReagendar(cita) {
  citaReagendando.value = cita;
  semana.value = 0;
  nuevaFecha.value = '';
  nuevaHora.value = '';
  errorReagendar.value = '';
  slots.value = {};
  await cargarSlots();
}

function cerrarReagendar() {
  citaReagendando.value = null;
}

async function cargarSlots() {
  const cita = citaReagendando.value;
  if (!cita) return;
  cargandoSlots.value = true;
  try {
    const { data } = await api.get('/citas/slots', {
      params: {
        servicio_id: cita.servicio_id,
        especialista_id: cita.especialista_id,
        desde: isoMasDias(semana.value * 7),
        dias: 7,
      },
    });
    slots.value = data;
  } catch (e) {
    errorReagendar.value = apiError(e, 'No se pudieron cargar los horarios.');
  } finally {
    cargandoSlots.value = false;
  }
}

function moverSemana(delta) {
  const nueva = semana.value + delta;
  if (nueva < 0 || nueva > MAX_SEMANAS) return;
  semana.value = nueva;
  nuevaFecha.value = '';
  nuevaHora.value = '';
  cargarSlots();
}

const diasConSlots = computed(() => Object.keys(slots.value).sort());

function elegirSlot(dia, hora) {
  nuevaFecha.value = dia;
  nuevaHora.value = hora;
}

function etiquetaDia(iso) {
  const [y, m, d] = iso.split('-').map(Number);
  const dt = new Date(y, m - 1, d);
  const dia = dt.toLocaleDateString('es-CL', { weekday: 'short' }).replace('.', '');
  return `${dia} ${d}/${m}`;
}

async function confirmarReagendar() {
  if (!nuevaFecha.value || !nuevaHora.value) return;
  guardandoReagenda.value = true;
  errorReagendar.value = '';
  try {
    // Sin zona horaria a propósito: `Cita.fecha_hora` se guarda en hora de la
    // clínica, no en UTC (ver app/services/tiempo.py). Mandar un ISO con "Z"
    // la correría 4 horas.
    await api.patch(`/citas/${citaReagendando.value.id}/reagendar`, {
      fecha_hora: `${nuevaFecha.value}T${nuevaHora.value}:00`,
    });
    await datos.cargar(true);
    cerrarReagendar();
  } catch (e) {
    // 409 = alguien tomó esa hora mientras el modal estaba abierto; se
    // refrescan los horarios para que la siguiente elección sea real.
    errorReagendar.value = apiError(e, 'No se pudo reagendar la cita.');
    if (e?.response?.status === 409) {
      nuevaHora.value = '';
      await cargarSlots();
    }
  } finally {
    guardandoReagenda.value = false;
  }
}

/* `esteAnio` se fue con la fila de métricas: era su único uso. */
const proximas = computed(() => datos.proximasCitas);
/* Una reserva que perdió la hora por no pagar el abono (ver `perdioLaHora` en
   stores/datos.js) baja a Anteriores con su motivo escrito: «Pendiente» o
   «Agendada» le decían al tutor que la hora seguía siendo suya. */
function etiquetaEstado(c) {
  if (perdioLaHora(c)) return 'Sin abono · hora liberada';
  return c.estado === 'Agendada' ? 'Pendiente' : c.estado;
}
const anteriores = computed(() => datos.citasAnteriores.slice(0, 5));
const esperaPago = (c) => msParaPagar(c, datos.ahora) !== null;

function estadoChip(e) {
  if (e === 'Confirmada') return 'chip-ok';
  if (e === 'Completada') return 'chip-ok';
  if (e === 'Cancelada') return 'chip-danger';
  return 'chip-warn';
}
function nombreMasc(id) { return datos.mascotaPorId(id)?.nombre || 'Mascota'; }
function especieMasc(id) { return datos.mascotaPorId(id)?.especie; }
function fotoMasc(id) { return urlFoto(datos.mascotaPorId(id)?.foto_url) || fotoEspecie(especieMasc(id)); }

function fechaBloque(iso) {
  const d = new Date(iso);
  return { dia: d.getDate(), mes: d.toLocaleDateString('es-CL', { month: 'short' }).toUpperCase().replace('.', '') };
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Citas</h1>
        <p class="muted">Gestiona las citas de tus mascotas en {{ CLINICA.nombre }}.</p>
      </div>
      <RouterLink to="/citas/nueva" class="btn btn-primary">
        <BaseIcon name="plus" :size="18" /> Nueva cita
      </RouterLink>
    </header>

    <!-- Se fue la fila de cuatro métricas (próximas / completadas / por
         confirmar / canceladas). Era pensamiento de panel administrativo en la
         vista de un cliente: a un dueño no le sirve saber que tiene dos citas
         canceladas, no hay nada que haga con ese número. El espacio se lo
         quedan las citas mismas. -->
    <div class="cols">
      <!-- Próximas -->
      <section class="card card-pad">
        <div class="section-head"><h2>Próximas citas</h2></div>
        <p v-if="errorCancelar" class="empty-state" style="color: var(--danger)">{{ errorCancelar }}</p>
        <p v-if="!proximas.length" class="empty-state">No tienes citas próximas. ¡Reserva una nueva!</p>
        <ul v-else class="cita-list">
          <li v-for="c in proximas" :key="c.id" class="cita-row" :class="{ 'con-pago': esperaPago(c) }">
            <div class="cita-fecha-bloque">
              <span class="cfb-dia">{{ fechaBloque(c.fecha_hora).dia }}</span>
              <span class="cfb-mes">{{ fechaBloque(c.fecha_hora).mes }}</span>
            </div>
            <img class="cita-foto" :src="fotoMasc(c.mascota_id)" alt="" />
            <div class="cita-info">
              <strong>{{ nombreMasc(c.mascota_id) }}</strong>
              <span class="chip chip-brand">{{ c.servicio }}</span>
            </div>
            <div class="cita-hora">
              <span><BaseIcon name="clock" :size="14" /> {{ horaCorta(c.fecha_hora) }}</span>
              <span class="muted">{{ c.duracion_min }} min</span>
            </div>
            <span class="chip" :class="estadoChip(c.estado)">{{ esperaPago(c) ? 'Falta el abono' : c.estado }}</span>
            <div class="cita-acciones">
              <!-- Reagendar primero: mover la hora conserva al paciente,
                   cancelarla lo pierde. -->
              <button
                v-if="puedeReagendar(c)"
                type="button"
                class="btn btn-ghost btn-sm reagendar-btn"
                @click="abrirReagendar(c)"
              >
                Reagendar
              </button>
              <button
                type="button"
                class="btn btn-ghost btn-sm cancelar-btn"
                :disabled="cancelandoId === c.id"
                @click="cancelarCita(c)"
              >
                {{ cancelandoId === c.id ? 'Cancelando…' : 'Cancelar' }}
              </button>
            </div>
            <!-- A lo ancho, bajo la fila: el plazo es lo más urgente de la cita
                 y apretado entre la hora y los botones no se leía. -->
            <CuentaPago v-if="esperaPago(c)" :cita="c" class="cita-pago" />
          </li>
        </ul>
      </section>

      <!-- Info lateral -->
      <!-- Se fue la tarjeta lateral «¿Necesitas agendar una nueva cita?». Era
           el TERCER llamado a agendar en la misma pantalla —ya está el botón
           de la cabecera y el del estado vacío— y tres botones para un mismo
           acto compiten en vez de sumar. Lo único que aportaba de verdad eran
           dos datos prácticos, que ahora van abajo como nota tranquila. -->
    </div>

    <p class="nota-practica">
      <BaseIcon name="clock" :size="18" class="nota-ic" />
      Llega 10 minutos antes de tu hora. Si no puedes asistir, avísanos con 24 horas
      de anticipación: reagendar conserva tu hora, cancelar la libera.
    </p>

    <!-- Anteriores -->
    <section class="card card-pad">
      <div class="section-head"><h2>Citas anteriores</h2></div>
      <p v-if="!anteriores.length" class="empty-state">No hay citas anteriores.</p>
      <ul v-else class="cita-list">
        <li v-for="c in anteriores" :key="c.id" class="cita-row past">
          <div class="cita-fecha-bloque">
            <span class="cfb-dia">{{ fechaBloque(c.fecha_hora).dia }}</span>
            <span class="cfb-mes">{{ fechaBloque(c.fecha_hora).mes }}</span>
          </div>
          <img class="cita-foto" :src="fotoMasc(c.mascota_id)" alt="" />
          <div class="cita-info">
            <strong>{{ nombreMasc(c.mascota_id) }}</strong>
            <span class="chip chip-muted">{{ c.servicio }}</span>
          </div>
          <div class="cita-hora"><span><BaseIcon name="clock" :size="14" /> {{ horaCorta(c.fecha_hora) }}</span></div>
          <span class="chip" :class="perdioLaHora(c) ? 'chip-muted' : estadoChip(c.estado)">{{ etiquetaEstado(c) }}</span>
        </li>
      </ul>
    </section>

    <!-- Reagendar: mismo contrato que el paso 3 del asistente de reserva
         (/citas/slots una semana a la vez), pero con el servicio y el
         especialista ya fijos: acá solo se cambia la hora. -->
    <div v-if="citaReagendando" class="modal-fondo" @click.self="cerrarReagendar">
      <div class="modal-caja" role="dialog" aria-modal="true" aria-labelledby="reag-titulo">
        <header class="modal-cab">
          <h3 id="reag-titulo">Reagendar cita</h3>
          <button type="button" class="modal-x" aria-label="Cerrar" @click="cerrarReagendar">×</button>
        </header>

        <p class="muted modal-sub">
          {{ nombreMasc(citaReagendando.mascota_id) }} · {{ citaReagendando.servicio }}<br />
          Hora actual: {{ fechaLarga(citaReagendando.fecha_hora) }}, {{ horaCorta(citaReagendando.fecha_hora) }}
        </p>

        <div class="sem-nav">
          <button type="button" class="btn btn-ghost btn-sm" :disabled="semana === 0" @click="moverSemana(-1)">← Antes</button>
          <span class="muted">{{ semana === 0 ? 'Esta semana' : `Semana +${semana}` }}</span>
          <button type="button" class="btn btn-ghost btn-sm" :disabled="semana >= MAX_SEMANAS" @click="moverSemana(1)">Después →</button>
        </div>

        <p v-if="cargandoSlots" class="empty-state">Cargando horarios…</p>
        <p v-else-if="!diasConSlots.length" class="empty-state">
          No hay horas disponibles esta semana. Prueba la siguiente.
        </p>
        <div v-else class="dias-grid">
          <div v-for="dia in diasConSlots" :key="dia" class="dia-col">
            <span class="dia-lbl">{{ etiquetaDia(dia) }}</span>
            <button
              v-for="h in slots[dia]" :key="h"
              type="button"
              class="slot-btn"
              :class="{ 'slot-sel': nuevaFecha === dia && nuevaHora === h }"
              @click="elegirSlot(dia, h)"
            >{{ h }}</button>
          </div>
        </div>

        <p v-if="errorReagendar" class="empty-state" style="color: var(--danger)">{{ errorReagendar }}</p>

        <footer class="modal-pie">
          <button type="button" class="btn btn-ghost" @click="cerrarReagendar">Cancelar</button>
          <button
            type="button"
            class="btn btn-primary"
            :disabled="!nuevaFecha || !nuevaHora || guardandoReagenda"
            @click="confirmarReagendar"
          >
            {{ guardandoReagenda ? 'Guardando…' : 'Confirmar nueva hora' }}
          </button>
        </footer>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.6rem; flex-wrap: wrap; }
.page-head h1 { font-size: var(--t-2xl); color: var(--brand); }
.page-head .muted { margin-top: 0.2rem; }

/* Nota práctica al pie: lo único que valía la pena de la tarjeta lateral que
   se retiró. Va tranquila, en el tono del aviso del día del paso 4. */
.nota-practica {
  display: flex; gap: 0.55rem; align-items: flex-start;
  margin-top: 1.4rem; padding: 0.8rem 1rem;
  background: var(--accent-tint); border-radius: var(--r-md);
  font-size: var(--t-sm); line-height: 1.5; color: var(--ink);
  max-width: 62ch;
}
/* El ícono no se estira ni se hunde: queda a la altura de la primera línea. */
.nota-ic { flex: none; margin-top: 0.1em; color: var(--accent-strong); }

/* Una sola columna. La segunda la ocupaba la tarjeta lateral que se retiró, y
   mientras estuvo ahí le quitaba 320px a las filas de citas — que es
   justamente donde se apretaban seis elementos en una línea. */
.cols { display: grid; grid-template-columns: 1fr; gap: 1.4rem; margin-bottom: 1.4rem; }

.cita-list { list-style: none; padding: 0; display: flex; flex-direction: column; gap: 0.8rem; }
.cita-row { display: flex; align-items: center; gap: 1rem; padding: 0.9rem; border: 1px solid var(--line); border-radius: var(--r-md); }
.cita-row.past { opacity: 0.92; }
.cita-fecha-bloque { width: 52px; text-align: center; flex: none; }
.cfb-dia { display: block; font-size: var(--t-2xl); font-weight: 700; color: var(--brand); line-height: 1; }
.cfb-mes { font-size: var(--t-micro); font-weight: 600; color: var(--accent-strong); }
.cita-foto { width: 46px; height: 46px; border-radius: 50%; background: var(--brand-tint); object-fit: cover; flex: none; }
.cita-info { flex: 1; display: flex; flex-direction: column; gap: 0.35rem; align-items: flex-start; }
.cita-info strong { font-size: var(--t-base); }
.cita-hora { display: flex; flex-direction: column; align-items: flex-end; gap: 0.15rem; font-size: var(--t-micro); }
.cita-hora span { display: flex; align-items: center; gap: 0.3rem; }
.cita-hora .muted { font-size: var(--t-micro); }
.cita-acciones { display: flex; gap: 0.35rem; flex: none; }
.reagendar-btn { flex: none; color: var(--accent-strong); }
.reagendar-btn:hover:not(:disabled) { background: var(--accent-hover); }
.cita-row.con-pago { flex-wrap: wrap; }
.cita-pago { flex-basis: 100%; }
.cancelar-btn { flex: none; color: var(--danger); }
.cancelar-btn:hover:not(:disabled) { background: var(--danger-tint); }
.cancelar-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* En pantalla angosta la fila dejaba de ser fila: seis elementos con
   `flex-wrap` caen donde alcanzan y el resultado es un revoltijo distinto según
   el largo del nombre de la mascota. Pasa a rejilla con posiciones dichas, para
   que el orden sea siempre el mismo: quién y qué arriba, cuándo debajo, y los
   botones abajo de todo ocupando el ancho. */
@media (max-width: 620px) {
  .cita-row {
    display: grid; align-items: center;
    grid-template-columns: 3.2rem 2.9rem minmax(0, 1fr);
    grid-template-areas:
      "fecha foto info"
      "fecha hora  estado"
      "acc   acc   acc";
    gap: 0.5rem 0.7rem;
  }
  .cita-fecha-bloque { grid-area: fecha; width: auto; }
  .cita-foto { grid-area: foto; }
  .cita-info { grid-area: info; }
  .cita-hora { grid-area: hora; align-items: flex-start; }
  .cita-row > .chip { grid-area: estado; justify-self: end; }
  .cita-acciones { grid-area: acc; }
  .cita-acciones > * { flex: 1; justify-content: center; }
  .cita-row.con-pago {
    grid-template-areas:
      "fecha foto info"
      "fecha hora  estado"
      "pago  pago  pago"
      "acc   acc   acc";
  }
  .cita-pago { grid-area: pago; }
}

@media (max-width: 1000px) { .cols { grid-template-columns: 1fr; } }
@media (max-width: 720px) { .stats { grid-template-columns: 1fr 1fr; } }
@media (max-width: 560px) { .cita-row { flex-wrap: wrap; } }

/* ── Modal de reagendar ── */
.modal-fondo {
  position: fixed; inset: 0; z-index: 50;
  background: rgba(38, 50, 110, 0.45);
  display: flex; align-items: center; justify-content: center;
  padding: 1rem;
}
.modal-caja {
  background: #fff; border-radius: var(--r-lg);
  box-shadow: var(--shadow-lg, 0 16px 50px rgba(38, 50, 110, 0.14));
  width: 100%; max-width: 620px;
  /* `dvh` y no `vh`, mismo patrón que `.cuenta-modal` en ReservarView: en el
     teléfono `100vh` NO descuenta la barra de direcciones, así que el modal se
     dibuja más alto que la pantalla visible y el pie —donde viven los botones—
     queda debajo del borde, sin forma de alcanzarlo. Van las dos líneas a
     propósito: la primera es el respaldo para navegadores sin `dvh`, la
     segunda la pisa donde sí existe. */
  max-height: 90vh;
  max-height: 90dvh;
  overflow-y: auto;
  padding: 1.4rem;
}
.modal-cab { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.modal-cab h3 { margin: 0; font-family: var(--font-display); color: var(--brand-strong); }
.modal-x {
  border: 0; background: none; cursor: pointer;
  font-size: var(--t-3xl); line-height: 1; color: var(--ink-soft);
  padding: 0 0.25rem; border-radius: var(--r-sm);
}
.modal-x:hover { background: var(--accent-tint); color: var(--accent-strong); }
.modal-sub { margin: 0.5rem 0 1rem; }
.sem-nav {
  display: flex; align-items: center; justify-content: space-between;
  gap: 0.5rem; margin-bottom: 0.9rem;
}
.dias-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(84px, 1fr));
  gap: 0.6rem;
}
.dia-col { display: flex; flex-direction: column; gap: 0.35rem; }
.dia-lbl {
  font-size: var(--t-micro); font-weight: 600; text-transform: capitalize;
  color: var(--ink-soft); text-align: center; padding-bottom: 0.15rem;
  border-bottom: 1px solid var(--line);
}
.slot-btn {
  border: 1px solid var(--line-strong); background: #fff;
  border-radius: var(--r-sm); padding: 0.4rem 0.2rem;
  font-size: var(--t-sm); cursor: pointer; color: var(--ink);
  transition: background var(--dur-fast), border-color var(--dur-fast);
}
.slot-btn:hover { background: var(--accent-tint); border-color: var(--accent); }
.slot-sel { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
.slot-sel:hover { background: var(--accent-strong); border-color: var(--accent-strong); color: #fff; }
.modal-pie { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 1.2rem; }

@media (max-width: 560px) {
  .cita-acciones { flex-direction: column; }
  .dias-grid { grid-template-columns: repeat(auto-fit, minmax(72px, 1fr)); }
}
</style>