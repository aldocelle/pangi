<script setup>
import { computed } from 'vue';
import { formatCLP } from '../../api.js';
import { ESTADO_VISIBLE } from '../../colores-citas.js';

const props = defineProps({
  cita: { type: Object, required: true },
  color: { type: Object, default: null },
  puedeReagendar: { type: Boolean, default: false },
});
const emit = defineEmits(['estado', 'reagendar']);

const ESTADOS = ['Agendada', 'Confirmada', 'Completada', 'Cancelada'];

function hora(d) {
  return d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false });
}
const inicio = computed(() => new Date(props.cita.fecha_hora));
const rango = computed(() => {
  const fin = new Date(inicio.value.getTime() + (props.cita.duracion_min || 30) * 60000);
  return `${hora(inicio.value)} – ${hora(fin)}`;
});
const dia = computed(() => inicio.value.toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long' }));

/* El abono, dicho en palabras: es lo primero que pregunta el mostrador cuando
   alguien llega («¿ya pagó?»). */
const pago = computed(() => {
  const c = props.cita;
  if (!c.monto_abono_clp) return null;
  if (c.payment_status === 'approved') {
    return c.pago_modalidad === 'total'
      ? `Pagó el total (${formatCLP(c.monto_total_clp)})`
      : `Abono pagado (${formatCLP(c.monto_abono_clp)})`;
  }
  return `Abono pendiente (${formatCLP(c.monto_abono_clp)})`;
});
const telefono = computed(() => (props.cita.dueno?.telefono || '').replace(/[^\d+]/g, ''));
</script>

<template>
  <div class="dc">
    <p class="dc-cuando"><span>{{ rango }}</span> · {{ dia }}</p>
    <h3 class="dc-titulo">
      <span v-if="color" class="dc-muestra" :style="{ background: color.fondo, borderColor: color.borde }" aria-hidden="true"></span>
      {{ cita.mascota.nombre }}
      <small>{{ cita.mascota.especie }}</small>
    </h3>
    <p v-if="cita.mascota.alergias" class="dc-alergia">⚠ Alergias: {{ cita.mascota.alergias }}</p>

    <dl class="dc-datos">
      <div><dt>Consulta</dt><dd>{{ cita.servicio }}</dd></div>
      <div v-if="cita.especialista"><dt>Atiende</dt><dd>{{ cita.especialista.nombre }}</dd></div>
      <div>
        <dt>Tutor</dt>
        <dd>
          {{ cita.dueno.nombre }}
          <template v-if="telefono"> · <a :href="`tel:${telefono}`" class="dc-tel">{{ cita.dueno.telefono }}</a></template>
        </dd>
      </div>
      <div v-if="pago"><dt>Pago</dt><dd :class="{ pendiente: cita.payment_status !== 'approved' }">{{ pago }}</dd></div>
      <div v-if="cita.notas_pre_cita"><dt>Nota</dt><dd class="dc-nota">«{{ cita.notas_pre_cita }}»</dd></div>
    </dl>

    <label class="dc-estado">
      <span>Estado</span>
      <select class="select" :value="cita.estado" @change="emit('estado', $event.target.value)">
        <option v-for="e in ESTADOS" :key="e" :value="e">{{ ESTADO_VISIBLE[e].texto }}</option>
        <option v-if="!ESTADOS.includes(cita.estado)" :value="cita.estado" disabled>{{ ESTADO_VISIBLE[cita.estado]?.texto || cita.estado }}</option>
      </select>
    </label>

    <div class="dc-acciones">
      <RouterLink :to="`/ficha/${cita.mascota.id}`" class="btn btn-primary btn-sm">Abrir ficha</RouterLink>
      <button v-if="puedeReagendar" type="button" class="btn btn-ghost btn-sm" @click="emit('reagendar')">Reagendar</button>
    </div>
  </div>
</template>

<style scoped>
.dc { display: flex; flex-direction: column; gap: 0.55rem; padding-right: 1.4rem; }
.dc-cuando { font-size: var(--t-micro); color: var(--ink-soft); }
.dc-cuando span { color: var(--ink); font-weight: 600; font-variant-numeric: tabular-nums; }
.dc-cuando::first-letter { text-transform: uppercase; }
.dc-titulo { display: flex; align-items: baseline; gap: 0.45rem; font-size: var(--t-lg); }
.dc-titulo small { font-size: var(--t-sm); font-weight: 500; color: var(--ink-soft); text-transform: capitalize; }
.dc-muestra { width: 12px; height: 12px; border-radius: 3px; border: 1px solid; flex: none; align-self: center; }
.dc-alergia { font-size: var(--t-micro); font-weight: 600; color: var(--danger); background: var(--danger-tint); border-radius: var(--r-sm); padding: 0.3rem 0.5rem; }
.dc-datos { display: flex; flex-direction: column; gap: 0.3rem; margin: 0.1rem 0; }
.dc-datos div { display: grid; grid-template-columns: 76px 1fr; gap: 0.5rem; font-size: var(--t-sm); }
.dc-datos dt { color: var(--ink-soft); font-size: var(--t-micro); padding-top: 0.12rem; }
.dc-datos dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
.dc-datos dd.pendiente { color: var(--warn-ink); font-weight: 600; }
.dc-tel { color: var(--accent-strong); font-weight: 600; }
.dc-tel:hover { text-decoration: underline; }
.dc-nota { font-style: italic; color: var(--ink-soft); }
.dc-estado { display: grid; grid-template-columns: 76px 1fr; align-items: center; gap: 0.5rem; font-size: var(--t-micro); color: var(--ink-soft); }
.dc-estado .select { padding: 0.4em 0.6em; font-size: var(--t-sm); color: var(--ink); }
.dc-acciones { display: flex; gap: 0.5rem; margin-top: 0.2rem; }
</style>
