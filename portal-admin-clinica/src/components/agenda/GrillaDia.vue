<script setup>
import { ref, computed } from 'vue';
import { ESTADO_VISIBLE } from '../../colores-citas.js';

/* La vista de día de la Agenda: una columna por especialista y una fila cada
   30 minutos. Lo que se ve, de abajo hacia arriba en cada columna:
     · gris  = fuera de su horario ese día (según sus Horarios);
     · rayado = bloqueado (un trámite suyo, o algo de toda la clínica);
     · las citas, cada una con su color y su estado escrito.
   Clic en un hueco → `hueco` (el padre ofrece cita o bloqueo a esa hora). */
const props = defineProps({
  columnas: { type: Array, required: true },   // [{ id, nombre, especialidad, foto, iniciales }]
  citas: { type: Array, required: true },      // las del día, ya filtradas
  colores: { type: Map, required: true },      // id de cita → { fondo, borde, tinta }
  ventanas: { type: Object, default: () => ({}) }, // especialista → [{ desde, ultimo_inicio }]
  bloqueos: { type: Array, default: () => [] },
  fecha: { type: String, required: true },
  ahoraMin: { type: Number, default: null },   // minutos desde medianoche, solo si es hoy
});
const emit = defineEmits(['cita', 'bloqueo', 'hueco']);

const FILA = 46;     // px por cada 30 minutos
const PASO = 30;
const SIN = '__sin__'; // columna de citas sin especialista

function aMin(hhmm) {
  const [h, m] = hhmm.split(':').map(Number);
  return h * 60 + m;
}
function hhmm(min) {
  return `${String(Math.floor(min / 60)).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`;
}
function minDeFecha(dt) {
  const d = new Date(dt);
  return d.getHours() * 60 + d.getMinutes();
}
const colDe = (c) => c.especialista?.id || SIN;

// Horario de cada uno, en minutos. `ultimo_inicio` es la última hora a la que
// se puede EMPEZAR: la jornada llega hasta media hora después.
const horario = computed(() => {
  const out = {};
  for (const col of props.columnas) {
    out[col.id] = (props.ventanas[col.id] || [])
      .map((v) => [aMin(v.desde), aMin(v.ultimo_inicio) + PASO])
      .sort((a, b) => a[0] - b[0]);
  }
  return out;
});

/* Rango visible: de 9 a 19 como mínimo, ampliado a lo que haya ese día
   (horarios y citas), en horas enteras. Los bloqueos no lo amplían: unas
   vacaciones de día completo estirarían la grilla a 24 horas. */
const rango = computed(() => {
  let ini = 9 * 60;
  let fin = 19 * 60;
  for (const vs of Object.values(horario.value)) {
    for (const [a, b] of vs) { ini = Math.min(ini, a); fin = Math.max(fin, b); }
  }
  for (const c of props.citas) {
    const a = minDeFecha(c.fecha_hora);
    ini = Math.min(ini, a);
    fin = Math.max(fin, a + (c.duracion_min || PASO));
  }
  ini = Math.max(0, Math.floor(ini / 60) * 60);
  fin = Math.min(24 * 60, Math.ceil(fin / 60) * 60);
  return { ini, fin, filas: (fin - ini) / PASO };
});
const y = (min) => ((min - rango.value.ini) / PASO) * FILA;

const etiquetas = computed(() =>
  Array.from({ length: rango.value.filas }, (_, i) => rango.value.ini + i * PASO));

/* Lo que queda fuera del horario, como tramos a pintar en gris. */
function fueraDeHorario(colId) {
  const { ini, fin } = rango.value;
  const vs = horario.value[colId] || [];
  if (colId === SIN) return [];
  if (!vs.length) return [{ a: ini, b: fin, todo: true }];
  const tramos = [];
  let cursor = ini;
  for (const [a, b] of vs) {
    if (a > cursor) tramos.push({ a: cursor, b: Math.min(a, fin) });
    cursor = Math.max(cursor, b);
  }
  if (cursor < fin) tramos.push({ a: cursor, b: fin });
  return tramos.filter((t) => t.b > t.a);
}

/* Bloqueos de una columna, recortados al día y al rango visible. Uno de toda
   la clínica (especialista NULL) aparece en todas. */
function bloqueosDe(colId) {
  const dia0 = new Date(`${props.fecha}T00:00:00`).getTime();
  return props.bloqueos
    .filter((b) => colId !== SIN && (b.especialista_id === colId || b.especialista_id === null))
    .map((b) => {
      const a = Math.max(rango.value.ini, Math.round((new Date(b.inicio).getTime() - dia0) / 60000));
      const z = Math.min(rango.value.fin, Math.round((new Date(b.fin).getTime() - dia0) / 60000));
      return { ...b, a, z, clinica: b.especialista_id === null };
    })
    .filter((b) => b.z > b.a);
}

/* Citas de una columna con su carril: dos que se cruzan (sobrecupo, o una
   forzada fuera de horario) van lado a lado y no una encima de la otra. */
function citasDe(colId) {
  const lista = props.citas
    .filter((c) => colDe(c) === colId)
    .map((c) => {
      const a = minDeFecha(c.fecha_hora);
      return { c, a, z: a + (c.duracion_min || PASO) };
    })
    .sort((p, q) => p.a - q.a || p.z - q.z);
  const salida = [];
  let grupo = [];
  let finGrupo = -1;
  const cerrar = () => {
    const carriles = [];
    for (const it of grupo) {
      let k = carriles.findIndex((z) => z <= it.a);
      if (k === -1) { k = carriles.length; carriles.push(it.z); } else carriles[k] = it.z;
      it.carril = k;
    }
    for (const it of grupo) salida.push({ ...it, carriles: carriles.length });
  };
  for (const it of lista) {
    if (grupo.length && it.a >= finGrupo) { cerrar(); grupo = []; finGrupo = -1; }
    grupo.push(it);
    finGrupo = Math.max(finGrupo, it.z);
  }
  if (grupo.length) cerrar();
  return salida;
}

const conteo = computed(() => {
  const n = {};
  for (const c of props.citas) if (c.estado !== 'Cancelada' && c.estado !== 'Expirada') n[colDe(c)] = (n[colDe(c)] || 0) + 1;
  return n;
});

function hora(dt) {
  return new Date(dt).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false });
}
function apagada(c) {
  return c.estado === 'Cancelada' || c.estado === 'Expirada';
}
function etiquetaCita(c) {
  const fin = hhmm(minDeFecha(c.fecha_hora) + (c.duracion_min || PASO));
  return `${hora(c.fecha_hora)} a ${fin}, ${c.mascota.nombre}, ${c.servicio}, ${ESTADO_VISIBLE[c.estado]?.texto || c.estado}`;
}

/* ── Huecos ── Un clic en el fondo de una columna cae en la media hora de abajo
   del cursor. Mientras se pasa el mouse se muestra «+ 10:30». */
const fantasma = ref(null);
function minutosEn(e, colEl) {
  const r = colEl.getBoundingClientRect();
  const fila = Math.floor((e.clientY - r.top) / FILA);
  return Math.min(rango.value.fin - PASO, rango.value.ini + Math.max(0, fila) * PASO);
}
function mover(e, col) {
  if (e.target.closest('.cita, .bloqueo')) { fantasma.value = null; return; }
  fantasma.value = { col: col.id, min: minutosEn(e, e.currentTarget) };
}
function clicHueco(e, col) {
  if (e.target.closest('.cita, .bloqueo') || col.id === SIN) return;
  const el = e.currentTarget;
  const min = minutosEn(e, el);
  const r = el.getBoundingClientRect();
  const top = r.top + y(min);
  const dentro = (horario.value[col.id] || []).some(([a, b]) => min >= a && min < b);
  emit('hueco', {
    columna: col, minutos: min, hora: hhmm(min), dentroDeHorario: dentro,
    rect: { left: r.left, right: r.right, top, bottom: top + FILA, width: r.width, height: FILA },
  });
}
function abrirCita(e, c) { emit('cita', c, e.currentTarget.getBoundingClientRect()); }
function abrirBloqueo(e, b) { emit('bloqueo', b, e.currentTarget.getBoundingClientRect()); }

defineExpose({ yDe: (min) => y(min), rango });
</script>

<template>
  <div class="lienzo" :style="{ '--cols': columnas.length, '--fila': FILA + 'px', '--filas': rango.filas }">
    <!-- Encabezado: fijo arriba al hacer scroll -->
    <div class="esquina" aria-hidden="true"></div>
    <div v-for="col in columnas" :key="'h' + col.id" class="cab-col">
      <img v-if="col.foto" :src="col.foto" alt="" class="cab-foto" />
      <span v-else class="cab-foto iniciales" aria-hidden="true">{{ col.iniciales }}</span>
      <span class="cab-texto">
        <strong>{{ col.nombre }}</strong>
        <small>
          <template v-if="!(horario[col.id] || []).length && col.id !== '__sin__' && !conteo[col.id]">No atiende este día</template>
          <template v-else>
            {{ conteo[col.id] || 0 }} {{ (conteo[col.id] || 0) === 1 ? 'cita' : 'citas' }}<template v-if="!(horario[col.id] || []).length && col.id !== '__sin__'"> · sin horario</template>
          </template>
        </small>
      </span>
    </div>

    <!-- Horas: fijas a la izquierda -->
    <div class="horas" aria-hidden="true">
      <!-- La etiqueta que quedaría bajo la de «ahora» se esconde: encimadas no se lee ninguna. -->
      <span v-for="m in etiquetas" :key="m" class="hora-et" :class="{ entera: m % 60 === 0, tapada: ahoraMin !== null && Math.abs(m - ahoraMin) < 12 }" :style="{ top: y(m) + 'px' }">{{ hhmm(m) }}</span>
      <span v-if="ahoraMin !== null && ahoraMin >= rango.ini && ahoraMin < rango.fin" class="ahora-et" :style="{ top: y(ahoraMin) + 'px' }">{{ hhmm(ahoraMin) }}</span>
    </div>

    <!-- Columnas -->
    <div
      v-for="col in columnas" :key="'c' + col.id" class="col" :class="{ 'sin-esp': col.id === '__sin__' }"
      @click="clicHueco($event, col)" @mousemove="mover($event, col)" @mouseleave="fantasma = null"
    >
      <div v-for="(t, i) in fueraDeHorario(col.id)" :key="'f' + i" class="fuera"
        :style="{ top: y(t.a) + 'px', height: y(t.b) - y(t.a) + 'px' }">
        <span v-if="t.todo" class="fuera-texto">Sin horario este día</span>
      </div>

      <div v-if="fantasma && fantasma.col === col.id && col.id !== '__sin__'" class="fantasma" aria-hidden="true"
        :style="{ top: y(fantasma.min) + 'px' }">+ {{ hhmm(fantasma.min) }}</div>

      <button
        v-for="b in bloqueosDe(col.id)" :key="b.id" type="button" class="bloqueo" :class="{ clinica: b.clinica }"
        :style="{ top: y(b.a) + 'px', height: Math.max(y(b.z) - y(b.a) - 2, 20) + 'px' }"
        :aria-label="`Bloqueado de ${hhmm(b.a)} a ${hhmm(b.z)}: ${b.motivo}`"
        @click.stop="abrirBloqueo($event, b)"
      >
        <span class="bloqueo-hora">{{ hhmm(b.a) }} – {{ hhmm(b.z) }}</span>
        <span class="bloqueo-motivo">{{ b.motivo }}<template v-if="b.clinica"> · toda la clínica</template></span>
      </button>

      <button
        v-for="it in citasDe(col.id)" :key="it.c.id" type="button" class="cita" :class="{ apagada: apagada(it.c), corta: it.z - it.a < 30, larga: it.z - it.a >= 60 }"
        :style="{
          top: y(it.a) + 1 + 'px', height: Math.max(y(it.z) - y(it.a) - 3, 22) + 'px',
          left: `calc(${(it.carril / it.carriles) * 100}% + 3px)`, width: `calc(${100 / it.carriles}% - 6px)`,
          '--c-fondo': colores.get(it.c.id)?.fondo, '--c-borde': colores.get(it.c.id)?.borde, '--c-tinta': colores.get(it.c.id)?.tinta,
        }"
        :aria-label="etiquetaCita(it.c)"
        @click.stop="abrirCita($event, it.c)"
      >
        <span class="cita-linea1">
          <span class="cita-hora">{{ hora(it.c.fecha_hora) }}</span>
          <strong class="cita-nombre">{{ it.c.mascota.nombre }}</strong>
          <span v-if="it.c.mascota.alergias" class="cita-alergia" title="Tiene alergias registradas">⚠</span>
          <svg class="cita-estado" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <title>{{ ESTADO_VISIBLE[it.c.estado]?.texto || it.c.estado }}</title>
            <path :d="ESTADO_VISIBLE[it.c.estado]?.icono || ESTADO_VISIBLE.Agendada.icono" />
          </svg>
        </span>
        <span class="cita-servicio">{{ it.c.servicio }}</span>
        <span class="cita-tutor">{{ it.c.dueno.nombre }}</span>
      </button>
    </div>

    <div v-if="ahoraMin !== null && ahoraMin >= rango.ini && ahoraMin < rango.fin" class="ahora" aria-hidden="true"
      :style="{ top: `calc(var(--cab) + ${y(ahoraMin)}px)` }"></div>
  </div>
</template>

<style scoped>
.lienzo {
  --cab: 58px;
  --ancho-horas: 62px;
  position: relative;
  display: grid;
  grid-template-columns: var(--ancho-horas) repeat(var(--cols), minmax(168px, 1fr));
  grid-template-rows: var(--cab) calc(var(--filas) * var(--fila));
  min-width: max-content;
}

/* ── Encabezado ── */
.esquina, .cab-col {
  position: sticky; top: 0; z-index: 3;
  background: var(--surface); border-bottom: 1px solid var(--line-strong);
}
.esquina { left: 0; z-index: 4; }
.cab-col {
  display: flex; align-items: center; gap: 0.55rem; min-width: 0;
  padding: 0 0.7rem; border-left: 1px solid var(--line);
}
.cab-foto { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; flex: none; background: var(--bg); }
.cab-foto.iniciales { display: grid; place-items: center; background: var(--brand-tint); color: var(--brand-ink); font-weight: 700; font-size: var(--t-micro); }
.cab-texto { display: flex; flex-direction: column; min-width: 0; line-height: 1.25; }
.cab-texto strong { font-size: var(--t-sm); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cab-texto small { font-size: var(--t-micro); color: var(--ink-soft); }

/* ── Horas ── */
.horas {
  position: sticky; left: 0; z-index: 2;
  grid-row: 2; grid-column: 1;
  background: var(--surface); border-right: 1px solid var(--line-strong);
}
.hora-et {
  position: absolute; right: 0.5rem; transform: translateY(-50%);
  font-size: var(--t-micro); color: var(--ink-soft); font-variant-numeric: tabular-nums;
}
.hora-et:first-child { transform: translateY(2px); }
.hora-et.entera { color: var(--ink); font-weight: 600; }
.hora-et.tapada { visibility: hidden; }
.ahora-et {
  position: absolute; right: 0.25rem; transform: translateY(-50%); z-index: 1;
  font-size: var(--t-micro); font-weight: 700; color: #fff; background: var(--danger);
  border-radius: var(--r-full); padding: 0 0.4em; font-variant-numeric: tabular-nums;
}

/* ── Columnas ── */
.col {
  position: relative; grid-row: 2;
  border-left: 1px solid var(--line);
  /* Una raya fina cada media hora y una más marcada cada hora. */
  background:
    repeating-linear-gradient(to bottom, transparent 0 calc(var(--fila) * 2 - 1px), var(--line-tabla) calc(var(--fila) * 2 - 1px) calc(var(--fila) * 2)),
    repeating-linear-gradient(to bottom, transparent 0 calc(var(--fila) - 1px), var(--line) calc(var(--fila) - 1px) var(--fila)),
    var(--surface);
  cursor: cell;
}
.col.sin-esp { cursor: default; }
.fuera {
  position: absolute; left: 0; right: 0;
  background: color-mix(in oklab, var(--ink) 5%, transparent);
  pointer-events: none;
}
.fuera-texto { position: sticky; top: calc(var(--cab) + 0.5rem); display: block; padding: 0.5rem 0.7rem; font-size: var(--t-micro); color: var(--ink-soft); }

.fantasma {
  position: absolute; left: 3px; right: 3px; height: calc(var(--fila) - 3px); margin-top: 1px;
  border: 1.5px dashed var(--accent); border-radius: var(--r-sm);
  background: var(--accent-tint); color: var(--accent-strong);
  font-size: var(--t-micro); font-weight: 700; padding: 0.2rem 0.45rem;
  pointer-events: none;
}

.bloqueo {
  position: absolute; left: 3px; right: 3px; z-index: 1;
  display: flex; flex-direction: column; align-items: flex-start; gap: 0.05rem; text-align: left;
  padding: 0.3rem 0.5rem; overflow: hidden; border-radius: var(--r-sm);
  border: 1px solid var(--line-strong); color: var(--ink-soft);
  background: repeating-linear-gradient(135deg, var(--bg) 0 7px, color-mix(in oklab, var(--ink) 9%, var(--surface)) 7px 9px);
}
.bloqueo:hover { border-color: var(--ink-soft); color: var(--ink); }
.bloqueo-hora { font-size: var(--t-micro); font-variant-numeric: tabular-nums; }
.bloqueo-motivo { font-size: var(--t-micro); font-weight: 600; color: var(--ink); background: var(--surface); border-radius: 4px; padding: 0 0.3em; }

.cita {
  position: absolute; z-index: 2;
  display: flex; flex-direction: column; align-items: stretch; gap: 0.05rem; text-align: left;
  padding: 0.25rem 0.45rem; overflow: hidden;
  background: var(--c-fondo); color: var(--c-tinta);
  border: 1px solid var(--c-borde); border-radius: var(--r-sm);
  font-size: var(--t-micro); line-height: 1.3;
  transition: box-shadow 0.15s ease-out, transform 0.15s ease-out;
}
.cita:hover { box-shadow: 0 4px 12px rgba(16, 26, 61, 0.16); z-index: 3; }
.cita:focus-visible { outline: 2.5px solid var(--brand); outline-offset: 1px; z-index: 3; }
.cita-linea1 { display: flex; align-items: center; gap: 0.3rem; min-width: 0; }
.cita-hora { font-variant-numeric: tabular-nums; flex: none; }
.cita-nombre { font-size: var(--t-micro); font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.cita-alergia { flex: none; }
.cita-estado { flex: none; margin-left: auto; }
.cita-servicio, .cita-tutor { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cita-tutor { display: none; opacity: 0.85; }
.cita.larga .cita-tutor { display: block; }
.cita.corta .cita-servicio { display: none; }
.cita.apagada {
  background: var(--surface); color: var(--ink-soft);
  border: 1px dashed var(--line-strong);
}
.cita.apagada .cita-nombre { text-decoration: line-through; }

.ahora {
  position: absolute; left: var(--ancho-horas); right: 0; height: 0; z-index: 3;
  border-top: 2px solid var(--danger); pointer-events: none;
}

@media (prefers-reduced-motion: reduce) { .cita { transition: none; } }
@media print {
  .esquina, .cab-col, .horas { position: static; }
  .fantasma, .ahora, .ahora-et { display: none; }
  .cita { box-shadow: none; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .bloqueo, .fuera { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
