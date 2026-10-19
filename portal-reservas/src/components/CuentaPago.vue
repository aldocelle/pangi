<script setup>
/* Cuenta regresiva de una reserva que espera el abono.
 *
 * Existe porque el plazo de 15 minutos era invisible en el portal: la cita
 * aparecía como «Agendada» igual que cualquier otra, y al vencer se iba a
 * Anteriores sin que la persona supiera que tenía un reloj corriendo. El correo
 * lo dice, pero el portal es donde se mira la hora.
 *
 * El botón lleva a /pago/{id}, que es el mismo enlace del correo. Solo aparece
 * MIENTRAS la ventana está abierta: pasado el plazo la hora puede ser de otro,
 * y ofrecer pagar ahí es justo el caso de cobro sin hora que se quiere evitar.
 *
 * `datos.ahora` lo mueve el reloj del store (stores/datos.js), no un intervalo
 * propio: con tres tarjetas en pantalla serían tres relojes desfasados. */
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useDatosStore, msParaPagar } from '../stores/datos.js';
import BaseIcon from './BaseIcon.vue';

const props = defineProps({
  cita: { type: Object, required: true },
  compacto: { type: Boolean, default: false },
});

const datos = useDatosStore();
const ms = computed(() => msParaPagar(props.cita, datos.ahora));

const reloj = computed(() => {
  const total = Math.max(0, Math.ceil((ms.value ?? 0) / 1000));
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
});

/* Los últimos 3 minutos en rojo: antes de eso, apurar a alguien que está
   buscando la tarjeta solo le mete presión sin cambiar nada. */
const urgente = computed(() => ms.value !== null && ms.value < 3 * 60_000);

const monto = computed(() =>
  props.cita.monto_abono_clp != null ? `$${Number(props.cita.monto_abono_clp).toLocaleString('es-CL')}` : '');

/* Para el lector de pantalla: minutos, no segundos. Anunciar cada segundo lo
   vuelve inutilizable; el `role="timer"` no se anuncia solo. */
const minutos = computed(() => Math.max(1, Math.ceil((ms.value ?? 0) / 60_000)));
</script>

<template>
  <!-- Sin <template v-if> envolviendo: con él la raíz es un fragmento y la
       clase que pone quien lo usa (`cita-pago`, que lo ubica en la rejilla de
       Mis citas en teléfono) no llega a ningún elemento. -->
  <span v-if="ms !== null && compacto" class="chip cuenta-chip" :class="urgente ? 'chip-danger' : 'chip-warn'" role="timer">
    <BaseIcon name="timer" :size="14" />
    <span aria-hidden="true">{{ reloj }} para pagar</span>
    <span class="sr-only">Quedan {{ minutos }} minutos para pagar el abono</span>
  </span>

  <div v-else-if="ms !== null" class="cuenta" :class="{ urgente }">
    <span class="cuenta-ic"><BaseIcon name="timer" :size="20" /></span>
    <p class="cuenta-txt">
      <strong class="cuenta-reloj" role="timer" aria-hidden="true">{{ reloj }}</strong>
      <span>
        para pagar el abono<template v-if="monto"> de {{ monto }}</template>.
        Después, la hora puede tomarla otra persona.
      </span>
      <span class="sr-only">Quedan {{ minutos }} minutos.</span>
    </p>
    <RouterLink :to="`/pago/${cita.id}`" class="btn btn-primary btn-sm cuenta-btn">Pagar abono</RouterLink>
  </div>
</template>

<style scoped>
.cuenta {
  display: flex; align-items: center; gap: 0.8rem; flex-wrap: wrap;
  padding: 0.75rem 1rem; border-radius: var(--r-md);
  background: var(--warn-tint); border: 1px solid var(--line);
}
.cuenta.urgente { background: var(--danger-tint); border-color: var(--danger); }
.cuenta-ic {
  width: 36px; height: 36px; border-radius: 10px; flex: none;
  display: grid; place-items: center; background: var(--surface); color: var(--accent-strong);
}
.cuenta.urgente .cuenta-ic { color: var(--danger); }
.cuenta-txt { flex: 1; min-width: 12rem; margin: 0; font-size: var(--t-sm); color: var(--ink); line-height: 1.45; }
/* Cifras tabulares: sin esto el texto de al lado baila un píxel cada segundo. */
.cuenta-reloj {
  font-size: var(--t-lg); font-variant-numeric: tabular-nums; margin-right: 0.35rem;
  color: var(--accent-strong);
}
.cuenta.urgente .cuenta-reloj { color: var(--danger); }
.cuenta-btn { flex: none; }
.cuenta-chip { display: inline-flex; align-items: center; gap: 0.3rem; font-variant-numeric: tabular-nums; }
.sr-only {
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
}
@media (max-width: 520px) {
  .cuenta-btn { width: 100%; justify-content: center; }
}
</style>
