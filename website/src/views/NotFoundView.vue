<script setup>
import { ref } from 'vue';
import { RESERVA_URL } from '../config.js';
import { useReveal } from '../useReveal.js';

const root = ref(null);
useReveal(root);
</script>

<template>
  <div ref="root" class="pagina">
    <section class="seccion nf-sec">
      <div class="nf-blob" aria-hidden="true"></div>
      <div class="wrap nf-wrap" data-reveal>
        <p class="nf-numero" aria-hidden="true">404</p>
        <h1>Esta página no existe</h1>
        <p class="nf-desc">
          El enlace puede estar roto o la página se movió. Prueba desde acá:
        </p>
        <div class="nf-acciones">
          <RouterLink to="/" class="btn btn-primary">Ir al inicio</RouterLink>
          <RouterLink to="/servicios" class="btn nf-btn-borde">Ver servicios</RouterLink>
          <RouterLink to="/contacto" class="btn nf-btn-borde">Contacto</RouterLink>
        </div>
        <p class="nf-agenda">
          ¿Buscabas agendar una hora?
          <a :href="RESERVA_URL" target="_blank" rel="noopener">Reserva acá</a>.
        </p>
      </div>
    </section>
  </div>
</template>

<style scoped>
.nf-sec {
  position: relative; overflow: hidden;
  padding: clamp(4.5rem, 12vw, 7rem) 0; text-align: center;
}

/* Mismo lenguaje que el blob de PageHero (.pg-hero-blob), pero en tono claro
   para una sección clara en vez de sobre el hero azul. */
.nf-blob {
  position: absolute; top: -160px; right: -140px;
  width: 420px; height: 420px;
  background: radial-gradient(circle at 45% 45%, rgba(var(--acento-rgb), 0.14), rgba(var(--acento-rgb), 0) 70%);
  border-radius: 42% 58% 60% 40% / 48% 42% 58% 52%;
  pointer-events: none;
}

/* Ancho propio en vez de heredar el `max-width` del texto: con solo 46ch la
   fila de tres botones no entraba y "Contacto" quedaba huérfano en una
   segunda línea, descentrado respecto al par de arriba. */
.nf-wrap { position: relative; max-width: 34rem; margin: 0 auto; }

/* Numeral grande como marca de agua: reemplaza el rótulo "Error 404" chico
   por una pieza gráfica, en la línea de los blobs y marcas de agua que ya
   usa el resto del sitio (PageHero, hero de Inicio). Es decorativo — el
   texto real del error lo dice el <h1>. */
.nf-numero {
  font-size: clamp(5rem, 16vw, 7.5rem); font-weight: 800; line-height: 1;
  color: var(--azul-tint); letter-spacing: -0.02em;
  margin: 0 0 -0.5rem;
}
.nf-sec h1 { position: relative; color: var(--azul); font-size: clamp(1.8rem, 4vw, 2.4rem); margin-bottom: 0.8rem; }
.nf-desc { color: var(--ink-soft); margin-bottom: 1.8rem; }
.nf-acciones {
  display: flex; justify-content: center; gap: 0.8rem;
  margin-bottom: 1.6rem;
}
.nf-agenda { color: var(--ink-soft); font-size: 0.95rem; }
.nf-agenda a { color: var(--azul); font-weight: 600; }

/* Variante de botón para fondo claro: `.btn-outline` de app.css asume texto
   blanco sobre un hero azul, aquí se leería invisible. */
.nf-btn-borde {
  color: var(--azul); background: var(--surface);
  box-shadow: inset 0 0 0 1.5px var(--line);
}
.nf-btn-borde:hover {
  background: var(--azul-tint); box-shadow: inset 0 0 0 1.5px var(--azul);
}

/* Bajo ~560px los tres botones ya no entran en una fila legible: se apilan
   a todo el ancho en vez de dejar que floten centrados con distinto ancho
   cada uno, que es lo que se veía descuidado. */
@media (max-width: 560px) {
  .nf-acciones { flex-direction: column; align-items: stretch; }
}

@media (prefers-reduced-motion: reduce) {
  .nf-btn-borde:hover { transform: none; }
}
</style>
