<script setup>
/* La política de devolución y cambio de hora, escrita UNA vez.

   Estaba solo dentro del bloque de las interconsultas, que es donde se exige
   aceptarla. En el resto de las consultas con pago en línea no aparecía en
   ninguna parte, y la clínica lo pidió como un enlace desplegable (sep-2026):
   «para conocer nuestras políticas de devolución de dinero, haz clic aquí».

   Un componente y no el texto copiado en cada sitio porque son las mismas
   reglas de plata en tres pantallas —confirmar, pagar y la vuelta del pago—, y
   una que quede atrás le promete al cliente una devolución distinta de la que
   se le va a dar.

   Solo lleva lo que la clínica ya fijó (24 h de aviso, 60%). Si algún día
   define un plazo propio para CAMBIAR la hora, va acá, no inventado antes.

   `desplegada`: sin el <details>, para el bloque de interconsultas, donde la
   persona tiene que leerla antes de marcar que la acepta. */
import { CLINICA } from '../config.js';
import BaseIcon from './BaseIcon.vue';

defineProps({
  desplegada: { type: Boolean, default: false },
});
</script>

<template>
  <div v-if="desplegada" class="politica-lista">
    <p>⏳ Si ya pagaste y por fuerza mayor no puedes asistir, avisa con 24 horas de anticipación: la devolución corresponde al 60% del valor pagado. Con menos de 24 horas de aviso no procede el reembolso.</p>
    <p>
      <BaseIcon name="calendar" :size="16" /> Para cambiar tu hora o pedir una devolución,
      <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener">escríbenos por WhatsApp</a>.
    </p>
  </div>
  <details v-else class="politica">
    <summary>Para conocer nuestras políticas de cambio de hora y devolución de dinero, haz clic aquí</summary>
    <div class="politica-lista">
      <p>⏳ Si ya pagaste y por fuerza mayor no puedes asistir, avisa con 24 horas de anticipación: la devolución corresponde al 60% del valor pagado. Con menos de 24 horas de aviso no procede el reembolso.</p>
      <p>
        <BaseIcon name="calendar" :size="16" /> Para cambiar tu hora o pedir una devolución,
        <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener">escríbenos por WhatsApp</a>.
      </p>
    </div>
  </details>
</template>

<style scoped>
.politica {
  max-width: 52ch; margin: 1rem auto 0; text-align: left;
  font-size: var(--t-sm); color: var(--ink-soft);
}
/* Parece un enlace porque eso es lo que la clínica pidió que fuera; el
   triángulo nativo del <details> se conserva como pista de que se despliega. */
.politica summary {
  cursor: pointer; text-align: center;
  color: var(--accent-strong); text-decoration: underline; text-underline-offset: 2px;
}
.politica summary:hover { color: var(--ink); }
.politica[open] summary { margin-bottom: 0.6rem; }
.politica-lista {
  display: flex; flex-direction: column; gap: 0.6rem;
  text-align: left; line-height: 1.55;
}
.politica .politica-lista {
  padding: 0.8rem 0.9rem; border-radius: var(--r-md);
  background: var(--accent-tint); color: var(--ink-soft);
}
.politica-lista a { color: var(--accent-strong); text-decoration: underline; }
</style>
