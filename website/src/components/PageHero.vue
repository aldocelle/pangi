<script setup>
import { ref } from 'vue';
import { useReveal } from '../useReveal.js';

defineProps({
  titulo: { type: String, required: true },
  sub: { type: String, default: '' },
  foto: { type: String, default: '' }, // foto real junto al texto, opcional
  marcaAgua: { type: String, default: '' }, // logo/emblema como textura de fondo, opcional
  fondo: { type: String, default: '' }, // foto real de fondo atenuada, como el hero de Inicio
  espejo: { type: Boolean, default: false }, // alterna hacia dónde mira el sujeto de la foto
});

const root = ref(null);
useReveal(root);
</script>

<template>
  <header ref="root" class="pg-hero" :class="{ 'con-foto': foto }">
    <template v-if="fondo">
      <img :src="fondo" alt="" aria-hidden="true" class="pg-hero-fondo" :class="{ espejo }" />
      <div class="pg-hero-velo" aria-hidden="true"></div>
    </template>
    <img v-if="marcaAgua" :src="marcaAgua" alt="" aria-hidden="true" class="pg-hero-marca-agua" />
    <div class="pg-hero-blob" aria-hidden="true"></div>
    <div class="wrap" :class="{ 'pg-hero-in': foto }">
      <div class="pg-hero-texto">
        <h1 data-reveal><slot name="titulo">{{ titulo }}</slot></h1>
        <!-- El slot permite resaltar partes del subtítulo desde la página que
             lo usa; el prop `sub` sigue sirviendo para texto plano. -->
        <p v-if="sub || $slots.sub" class="pg-hero-sub" data-reveal>
          <slot name="sub">{{ sub }}</slot>
        </p>
      </div>
      <div v-if="foto" class="pg-hero-figura" data-reveal>
        <img :src="foto" alt="" loading="eager" />
      </div>
    </div>
    <!-- Cierre curvo, la firma del hero de Inicio. Sin esto el bloque azul
         termina en un corte recto y las páginas interiores se sienten de
         otro sitio. -->
    <div class="pg-hero-onda" aria-hidden="true"></div>
  </header>
</template>

<style scoped>
.pg-hero {
  position: relative; overflow: hidden;
  background: linear-gradient(150deg, var(--azul) 0%, var(--azul-oscuro) 100%);
  color: #fff; text-align: center;
  padding: clamp(3.5rem, 8vw, 5.5rem) 0 calc(clamp(3.5rem, 8vw, 5.5rem) + 30px);
}
/* Mismos valores que .hero-onda de Inicio: si cambia allá, cambia aquí. */
.pg-hero-onda {
  position: absolute; bottom: -1px; left: 0; right: 0; height: 60px;
  background: var(--surface);
  clip-path: ellipse(75% 100% at 50% 100%);
}
.pg-hero-blob {
  position: absolute; top: -140px; left: -120px;
  width: 380px; height: 380px;
  background: radial-gradient(circle at 40% 40%, rgba(var(--acento-rgb), 0.18), rgba(var(--acento-rgb), 0) 70%);
  border-radius: 42% 58% 60% 40% / 48% 42% 58% 52%;
}
/* Foto real de fondo atenuada bajo el gradiente — mismo tratamiento que
   el hero de Inicio (HeroSection.vue). */
.pg-hero-fondo {
  position: absolute; inset: 0; width: 100%; height: 100%;
  object-fit: cover; opacity: 0.4;
}
.pg-hero-fondo.espejo { transform: scaleX(-1); }
.pg-hero-velo {
  position: absolute; inset: 0;
  background: linear-gradient(150deg, var(--azul) 15%, rgba(26, 35, 80, 0.55) 55%, var(--azul-oscuro) 100%);
}
/* Marca de agua: el logo real de la clínica como textura decorativa muy
   sutil detrás del texto, no como foto de fondo — su círculo blanco se
   funde con el azul del hero gracias a la opacidad baja. */
.pg-hero-marca-agua {
  position: absolute; top: 50%; right: -6%; transform: translateY(-50%);
  width: clamp(360px, 42vw, 620px); height: auto;
  opacity: 0.08; filter: brightness(0) invert(1);
  pointer-events: none;
}
.pg-hero .wrap { position: relative; z-index: 2; }
.pg-hero h1 { font-size: clamp(2rem, 4.8vw, 3rem); margin: 0 auto 1rem; max-width: 22ch; }
.pg-hero-sub { color: rgba(255, 255, 255, 0.88); max-width: 62ch; margin: 0 auto; font-size: 1.08rem; line-height: 1.6; }

/* Con foto: layout de dos columnas (texto + imagen real), no texto centrado
   sobre una foto de fondo atenuada — la foto se ve nítida, a un costado. */
.pg-hero.con-foto { text-align: left; }
.pg-hero-in {
  display: grid; grid-template-columns: 1.1fr 0.9fr; gap: clamp(2rem, 5vw, 3.5rem);
  align-items: center;
}
.pg-hero-in .pg-hero-sub, .pg-hero-in h1 { margin-left: 0; }
.pg-hero-figura img {
  width: 100%; border-radius: var(--r-xl);
  box-shadow: var(--shadow-lg);
  border: 5px solid rgba(255, 255, 255, 0.14);
}

@media (max-width: 860px) {
  .pg-hero-in { grid-template-columns: 1fr; text-align: center; }
  .pg-hero-in h1, .pg-hero-in .pg-hero-sub { margin-inline: auto; }
  .pg-hero-figura { order: -1; max-width: 440px; margin: 0 auto 1.5rem; }
}
</style>
