<script setup>
import { ref } from 'vue';
import { useReveal } from '../useReveal.js';

defineProps({
  reservaUrl: { type: String, required: true },
  clinica: { type: Object, required: true },
});

const root = ref(null);
useReveal(root);
</script>

<template>
  <section ref="root" class="hero">
    <!-- Mismo formato que ContactoHero.vue. Foto distinta a la del bloque
         destacado más abajo en esta misma página: repetir la misma imagen
         en el hero se leía redundante en la misma pantalla. -->
    <img
      class="hero-fondo" src="/portada-servicios.webp" alt="" aria-hidden="true"
      loading="eager"
    />
    <div class="hero-fondo-velo" aria-hidden="true"></div>
    <div class="wrap hero-in">
      <div class="hero-texto">
        <h1 data-reveal>
          Todo lo que
          <span class="hero-enfasis">tu mascota necesita</span>
        </h1>
        <p class="hero-sub" data-reveal>
          Una clínica veterinaria completa en Puente Alto: consultas, vacunas,
          cirugía, imagenología, laboratorio y peluquería para perros y gatos.
        </p>
        <div class="hero-acciones" data-reveal>
          <a :href="reservaUrl" target="_blank" rel="noopener" class="btn btn-primary btn-lg">
            Reserva de hora
          </a>
          <a :href="`https://wa.me/${clinica.telefonoPlano}`" target="_blank" rel="noopener" class="btn btn-outline btn-lg">
            Escríbenos por WhatsApp
          </a>
        </div>
      </div>
    </div>
    <div class="hero-onda" aria-hidden="true"></div>
  </section>
</template>

<style scoped>
/* Mismo sistema que ContactoHero.vue y EquipoHero.vue. */
.hero {
  position: relative;
  background: var(--azul-oscuro);
  color: #fff; overflow: hidden;
  padding: clamp(3rem, 7vw, 5rem) 0 clamp(4rem, 7vw, 5.5rem);
  min-height: 560px; display: flex; align-items: center;
}

.hero-fondo {
  position: absolute; inset: 0;
  width: 100%; height: 100%;
  object-fit: cover; object-position: right 40%;
}
.hero-fondo-velo {
  position: absolute; inset: 0;
  background: linear-gradient(100deg,
    var(--azul-oscuro) 0%,
    var(--azul-oscuro) 40%,
    rgba(26, 35, 80, 0.86) 52%,
    rgba(26, 35, 80, 0.4) 66%,
    rgba(26, 35, 80, 0) 82%
  );
}

.hero-in { position: relative; z-index: 2; width: 100%; }
.hero-texto { max-width: 620px; }

.hero-texto h1 {
  font-size: clamp(2.1rem, 4.8vw, 3.4rem);
  letter-spacing: -0.025em;
}
.hero-sub {
  font-size: var(--texto-lead); color: rgba(255, 255, 255, 0.88);
  margin-top: 1.1rem; max-width: 50ch; line-height: var(--lead-alto);
}
.hero-acciones { display: flex; flex-wrap: wrap; gap: 0.9rem; margin-top: 1.9rem; }
.btn-lg { padding: 0.95em 1.9em; font-size: 1.02rem; }

.hero-onda {
  position: absolute; bottom: -1px; left: 0; right: 0; height: 60px;
  background: var(--surface);
  clip-path: ellipse(75% 100% at 50% 100%);
  overflow: hidden;
}
/* Ver EquipoHero.vue: mismo arreglo para que la onda no corte en seco la
   textura de mascotas del resto del sitio. */
.hero-onda::before {
  content: ''; position: absolute; inset: 0;
  background-image: url('/bg-patron.png');
  background-size: 190px 190px;
  opacity: 0.12;
}

@media (max-width: 860px) {
  .hero { min-height: 480px; text-align: center; }
  .hero-fondo-velo {
    background: linear-gradient(180deg,
      var(--azul-oscuro) 0%, rgba(26, 35, 80, 0.93) 55%, rgba(26, 35, 80, 0.8) 100%);
  }
  .hero-texto { max-width: 560px; margin-inline: auto; }
  .hero-sub { margin-inline: auto; }
  .hero-acciones { justify-content: center; }
}
</style>
