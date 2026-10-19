<script setup>
import { ref } from 'vue';
import { useReveal } from '../useReveal.js';
import { CLINICA } from '../config.js';

defineProps({
  reservaUrl: { type: String, required: true },
});

const root = ref(null);
useReveal(root);
</script>

<template>
  <section id="nosotros" ref="root" class="seccion nosotros">
    <div class="wrap nosotros-in">
      <div class="nosotros-figura" data-reveal>
        <img
          src="/quienes-somos.webp"
          alt="La veterinaria puma de la clínica revisando con el estetoscopio a un cavalier en la consulta"
          loading="lazy" width="1086" height="1448"
        />
      </div>

      <div class="nosotros-texto">
        <h2 class="titulo-hero" data-reveal>
          Un equipo,
          <span class="enfasis">una sola ficha</span>
        </h2>
        <p data-reveal>
          En {{ CLINICA.nombre }} cada paciente tiene su historia clínica completa: vacunas,
          controles, exámenes y recetas, a mano del equipo en cada atención.
          <strong>Nadie tiene que volver a contar lo que ya pasó.</strong>
        </p>
        <p data-reveal>
          Te avisamos cuando toca la próxima vacuna o el control, y desde el
          portal del cliente ves tus citas, tus pagos y los documentos de tu mascota.
        </p>
        <!-- btn-primary y no btn-azul: es la misma acción de reservar que el
             resto del sitio (header, hero, servicios), que siempre va en
             turquesa. Dos colores para el mismo botón leía como dos acciones
             distintas. -->
        <a :href="reservaUrl" target="_blank" rel="noopener" class="btn btn-primary" data-reveal>Agenda una hora</a>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* Transparente y no blanco sólido: deja ver la textura de mascotas que pinta
   body::before (ver app.css). El blanco de fondo lo sigue poniendo el body. */
.nosotros { background: transparent; }
.nosotros-in {
  display: grid; grid-template-columns: 1fr 1fr; gap: clamp(2rem, 5vw, 4rem);
  align-items: center;
}
.nosotros-figura { position: relative; }
/* `transform` y no `width`: agranda la foto un 10% sin cambiar su caja en
   el grid, así no empuja al texto ni arriesga overflow horizontal en
   mobile, donde esta figura ya va a `max-width: 520px`. */
/* height:auto explícito: sin esto, el `height="1448"` del HTML (puesto para
   reservar espacio y evitar salto de layout) se aplicaba como alto literal
   en píxeles y no como proporción — la foto quedaba gigante y estirada. */
.nosotros-figura img { height: auto; border-radius: var(--r-lg); box-shadow: var(--shadow); transform: scale(1.1); }

.nosotros-texto h2 { color: var(--azul); margin-bottom: 1.2rem; }
.nosotros-texto p {
  color: var(--ink-soft); margin-bottom: 1rem;
  font-size: var(--texto-lead); line-height: var(--lead-alto);
}
/* La frase que cierra la idea: peso y color de tinta plena para que resalte
   sobre el gris del párrafo, no solo un poco más gruesa. */
.nosotros-texto strong { font-weight: 700; color: var(--azul); }
.nosotros-texto .btn { margin-top: 0.8rem; }

@media (max-width: 820px) {
  .nosotros-in { grid-template-columns: 1fr; }
  .nosotros-figura { max-width: 520px; margin: 0 auto 1.5rem; }
}
</style>
