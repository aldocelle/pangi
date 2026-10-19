<script setup>
/* Recordatorios del portal del cliente.
 *
 * QUÉ CAMBIÓ Y POR QUÉ (sep-2026)
 *
 * La pantalla anterior armaba sus «recordatorios» con las próximas citas del
 * cliente y los mostraba como avisos de WhatsApp «Programados» para un día
 * antes. Nada de eso era cierto: el portal nunca pedía los recordatorios
 * clínicos que carga la clínica (`/recordatorios/mios`, que existía y nadie
 * usaba), no hay envío por WhatsApp en camino, y los avisos de cita salen por
 * correo en tres etapas (24 h, 2 h, 15 min), y solo si `CORREOS_AUTOMATICOS`
 * está encendido en el servidor.
 *
 * Ahora son dos bloques con trabajos distintos:
 *  - Cuidados: lo que la clínica programó para cada mascota. Es lo que pide
 *    una acción del tutor —pedir hora—, así que va primero.
 *  - Próximas visitas: las citas ya tomadas, en corto, con salida a Citas.
 *
 * La pantalla no dice cuándo ni por dónde se avisa: esa promesa depende de un
 * interruptor del servidor que el portal no puede ver, y fue justo una promesa
 * así la que dejó de ser verdad.
 */
import { onMounted, computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useDatosStore, DIAS_AVISO_CUIDADO, msParaPagar } from '../stores/datos.js';
import { fechaLarga, horaCorta } from '../api.js';
import { CLINICA } from '../config.js';
import BaseIcon from '../components/BaseIcon.vue';
import CuentaPago from '../components/CuentaPago.vue';

const datos = useDatosStore();
onMounted(() => datos.cargar());

const MES_CORTO = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

/* `fecha_programada` llega como 'YYYY-MM-DD'. Leída así, el navegador la toma
   como medianoche UTC —las 20:00 del día anterior en Chile—; el mediodía la
   deja fuera del alcance de cualquier huso (mismo arreglo que FichaView). */
function fechaDia(iso) {
  return iso ? fechaLarga(`${iso}T12:00:00`) : '—';
}

function iconoDe(r) {
  const t = `${r.categoria || ''} ${r.titulo || ''}`.toLowerCase();
  if (/vacun|antirr|óctuple|octuple|triple|séxtuple|sextuple/.test(t)) return 'syringe';
  if (/examen|sangre|perfil|ecograf|radiograf/.test(t)) return 'flask';
  if (/control|revisi/.test(t)) return 'calendar';
  return 'heart';
}

/* Cuándo, dicho como lo diría una persona. El estado se deriva de la fecha,
   igual que en el backend: no hay columna que pueda contradecirla. */
function plazo(r) {
  const d = r.dias_restantes;
  if (r.vencido) {
    const n = Math.abs(d ?? 0);
    return { texto: n === 1 ? 'Venció ayer' : `Venció hace ${n} días`, clase: 'chip-danger', pide: true };
  }
  if (d === 0) return { texto: 'Es hoy', clase: 'chip-warn', pide: true };
  if (d === 1) return { texto: 'Mañana', clase: 'chip-warn', pide: true };
  if (d != null && d <= DIAS_AVISO_CUIDADO) return { texto: `En ${d} días`, clase: 'chip-warn', pide: true };
  return { texto: fechaDia(r.fecha_programada), clase: 'chip-info', pide: false };
}

const cuidados = computed(() =>
  datos.cuidados.map((r) => ({
    ...r,
    icono: iconoDe(r),
    plazo: plazo(r),
    proximaCita: datos.proximaCitaDe(r.mascota_id),
  }))
);

const porAtender = computed(() => datos.cuidadosPorAtender.length);

const visitas = computed(() => datos.proximasCitas.slice(0, 5));
const esperaPago = (c) => msParaPagar(c, datos.ahora) !== null;
const nombreMascota = (id) => datos.mascotaPorId(id)?.nombre || 'Tu mascota';
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1><span class="head-ic"><BaseIcon name="bell" :size="22" /></span> Recordatorios</h1>
        <p class="muted">Los cuidados que la clínica programó para tus mascotas y tus próximas visitas.</p>
      </div>
    </header>

    <p v-if="datos.cargando && !datos.cargado" class="empty-state">Cargando…</p>

    <template v-else>
      <!-- Cuidados -->
      <section class="card bloque">
        <div class="bloque-head">
          <h2>Cuidados de tus mascotas</h2>
          <span v-if="porAtender" class="chip chip-warn">
            {{ porAtender === 1 ? '1 necesita hora' : `${porAtender} necesitan hora` }}
          </span>
        </div>

        <div v-if="!cuidados.length" class="vacio">
          <span class="vacio-ic"><BaseIcon name="syringe" :size="26" /></span>
          <strong>No hay cuidados pendientes</strong>
          <p class="muted">
            Cuando la clínica programe una vacuna o un control para alguna de tus
            mascotas, lo verás aquí con su fecha.
          </p>
        </div>

        <ul v-else class="lista">
          <li
            v-for="r in cuidados" :key="r.id"
            class="cuidado" :class="{ urgente: r.vencido }"
          >
            <span class="cuidado-ic"><BaseIcon :name="r.icono" :size="20" /></span>

            <div class="cuidado-txt">
              <div class="cuidado-linea">
                <strong>{{ r.titulo }}</strong>
                <span class="chip" :class="r.plazo.clase">{{ r.plazo.texto }}</span>
              </div>
              <span class="muted cuidado-meta">
                {{ r.mascota_nombre || nombreMascota(r.mascota_id) }}
                <template v-if="r.plazo.pide"> · {{ fechaDia(r.fecha_programada) }}</template>
              </span>
              <p v-if="r.mensaje" class="cuidado-msg">{{ r.mensaje }}</p>
            </div>

            <div class="cuidado-accion">
              <!-- Con hora ya tomada no se ofrece otra: la visita es la
                   oportunidad de resolverlo, y la campana ya no lo cuenta. -->
              <RouterLink v-if="r.proximaCita" to="/citas" class="ya-hora">
                <BaseIcon name="check" :size="16" />
                Tiene hora el {{ fechaLarga(r.proximaCita.fecha_hora) }}
              </RouterLink>
              <RouterLink
                v-else to="/citas/nueva"
                class="btn btn-sm" :class="r.plazo.pide ? 'btn-primary' : 'btn-ghost'"
              >
                Pedir hora
              </RouterLink>
            </div>
          </li>
        </ul>
      </section>

      <!-- Próximas visitas -->
      <section class="card bloque">
        <div class="bloque-head">
          <h2>Próximas visitas</h2>
          <RouterLink v-if="visitas.length" to="/citas" class="link-accent">Ver todas</RouterLink>
        </div>

        <div v-if="!visitas.length" class="vacio">
          <span class="vacio-ic"><BaseIcon name="calendar" :size="26" /></span>
          <strong>No tienes horas tomadas</strong>
          <p class="muted">Cuando reserves, tu visita aparecerá aquí.</p>
          <RouterLink to="/citas/nueva" class="btn btn-ghost btn-sm">Reservar una hora</RouterLink>
        </div>

        <ul v-else class="lista">
          <li v-for="c in visitas" :key="c.id" class="visita">
            <span class="visita-fecha" aria-hidden="true">
              <strong>{{ new Date(c.fecha_hora).getDate() }}</strong>
              <span>{{ MES_CORTO[new Date(c.fecha_hora).getMonth()] }}</span>
            </span>
            <!-- El estado va junto al título, como el plazo en Cuidados: a la
                 derecha le quitaba ancho al texto y en un teléfono partía la
                 fecha en cuatro líneas. -->
            <div class="cuidado-txt">
              <div class="cuidado-linea">
                <strong>{{ c.servicio }}</strong>
                <CuentaPago v-if="esperaPago(c)" :cita="c" compacto />
                <span v-else class="chip" :class="c.estado === 'Confirmada' ? 'chip-ok' : 'chip-warn'">{{ c.estado }}</span>
              </div>
              <span class="muted cuidado-meta">
                {{ nombreMascota(c.mascota_id) }} · {{ fechaLarga(c.fecha_hora) }}, {{ horaCorta(c.fecha_hora) }}
              </span>
            </div>
          </li>
        </ul>
      </section>

      <p class="pie muted">
        ¿Una fecha no calza con lo que te indicaron?
        <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener">Escríbenos por WhatsApp</a>.
      </p>
    </template>
  </div>
</template>

<style scoped>
.page-head { margin-bottom: 1.4rem; }
.page-head h1 { font-size: var(--t-2xl); color: var(--brand); display: flex; align-items: center; gap: 0.6rem; }
.head-ic { width: 40px; height: 40px; border-radius: 12px; background: var(--brand-tint); color: var(--brand); display: grid; place-items: center; }
.page-head .muted { margin-top: 0.2rem; }

.bloque { padding: 0; margin-bottom: 1.2rem; overflow: hidden; }
.bloque-head {
  display: flex; align-items: center; justify-content: space-between; gap: 0.8rem; flex-wrap: wrap;
  padding: 1.1rem 1.4rem; border-bottom: 1px solid var(--line);
}
.bloque-head h2 { font-size: var(--t-lg); color: var(--brand); margin: 0; }

.lista { list-style: none; margin: 0; padding: 0; }
.cuidado, .visita {
  display: flex; align-items: center; gap: 1rem;
  padding: 1rem 1.4rem; border-top: 1px solid var(--line-tabla);
}
.lista li:first-child { border-top: 0; }
.cuidado.urgente { box-shadow: inset 4px 0 0 var(--danger); }

.cuidado-ic {
  width: 40px; height: 40px; border-radius: 12px; flex: none;
  background: var(--accent-tint); color: var(--accent); display: grid; place-items: center;
}
.cuidado.urgente .cuidado-ic { background: var(--danger-tint); color: var(--danger); }

.cuidado-txt { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 0.2rem; }
.cuidado-linea { display: flex; align-items: center; gap: 0.5rem 0.7rem; flex-wrap: wrap; }
.cuidado-linea strong, .visita strong { font-size: var(--t-base); color: var(--ink); }
.cuidado-meta { font-size: var(--t-sm); }
.cuidado-msg { margin: 0.25rem 0 0; font-size: var(--t-sm); color: var(--ink-soft); max-width: 70ch; }

.cuidado-accion { flex: none; }
.ya-hora {
  display: inline-flex; align-items: center; gap: 0.35rem;
  font-size: var(--t-sm); font-weight: 600; color: var(--ok);
}
.ya-hora:hover { text-decoration: underline; }

.visita-fecha {
  width: 48px; flex: none; text-align: center; line-height: 1.1;
  border-radius: 12px; background: var(--brand-tint); color: var(--brand); padding: 0.4rem 0;
  display: flex; flex-direction: column;
}
.visita-fecha strong { font-size: var(--t-xl); color: var(--brand); }
.visita-fecha span { font-size: var(--t-micro); text-transform: uppercase; font-weight: 600; }

.vacio {
  display: flex; flex-direction: column; align-items: center; text-align: center; gap: 0.4rem;
  padding: 2rem 1.4rem;
}
.vacio-ic {
  width: 56px; height: 56px; border-radius: 50%; margin-bottom: 0.3rem;
  background: var(--brand-tint); color: var(--brand); display: grid; place-items: center;
}
.vacio p { margin: 0 0 0.4rem; max-width: 44ch; font-size: var(--t-sm); }

.pie { font-size: var(--t-sm); text-align: center; margin-top: 0.4rem; }
.pie a { color: var(--accent); font-weight: 600; }

@media (max-width: 640px) {
  .cuidado { flex-wrap: wrap; align-items: flex-start; }
  .cuidado-accion { width: 100%; padding-left: calc(40px + 1rem); }
  .cuidado-accion .btn { width: 100%; justify-content: center; }
  .visita { align-items: flex-start; }
}
</style>
