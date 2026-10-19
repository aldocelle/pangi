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
    <!-- Mismo formato que ContactoHero.vue: foto real a sangre, el panel
         oscuro se funde hacia ella de izquierda a derecha. -->
    <img
      class="hero-fondo" src="/equipo.webp" alt="" aria-hidden="true"
      loading="eager"
    />
    <div class="hero-fondo-velo" aria-hidden="true"></div>
    <div class="wrap hero-in">
      <div class="hero-texto">
        <h1 data-reveal>
          Un equipo comprometido
          <span class="hero-enfasis">con la salud animal</span>
        </h1>
        <p class="hero-sub" data-reveal>
          Médicos veterinarios de distintas especialidades y un equipo de apoyo
          que conoce a cada paciente por su ficha, para cuidar su salud en cada
          etapa de su vida.
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
/* Mismo sistema que ContactoHero.vue: foto de fondo a sangre + velo
   direccional + onda. Repetido y no compartido por la misma razón que ya
   quedó documentada allá — el contenido difiere entre páginas y no vale la
   pena una prop por cada diferencia. */
.hero {
  position: relative;
  background: var(--azul-oscuro);
  color: #fff; overflow: hidden;
  padding: clamp(3rem, 7vw, 5rem) 0 clamp(4rem, 7vw, 5.5rem);
  min-height: 560px; display: flex; align-items: center;
}

.hero-fondo {
  position: absolute; inset: 0;
  width: 100%; height: 100%; object-fit: cover;
  /* Foto horizontal (1717×916) donde ella ya sale parada del lado derecho
     del cuadro, con pared vacía a su izquierda — no hizo falta recortar el
     archivo como con la versión anterior (más vertical). 25% vertical la
     deja con cabeza y hombros completos, sin cortar el logo de arriba. */
  object-position: 50% 25%;
}
.hero-fondo-velo {
  position: absolute; inset: 0;
  /* Corrido más a la izquierda que en ContactoHero.vue: da más espacio
     limpio a la derecha para que ella quede claramente visible y no a
     medio desvanecer. */
  background: linear-gradient(100deg,
    var(--azul-oscuro) 0%,
    var(--azul-oscuro) 32%,
    rgba(26, 35, 80, 0.8) 42%,
    rgba(26, 35, 80, 0.35) 54%,
    rgba(26, 35, 80, 0) 68%
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

/* Alto fijo con `overflow:hidden` para que el `::before` de la textura
   quede recortado por el mismo óvalo que ya recorta a `.hero-onda`: un
   `clip-path` en el padre alcanza también a sus pseudo-elementos, así que
   no hace falta repetirlo. */
.hero-onda {
  position: absolute; bottom: -1px; left: 0; right: 0; height: 60px;
  background: var(--surface);
  clip-path: ellipse(75% 100% at 50% 100%);
  overflow: hidden;
}
/* La onda blanca cortaba en seco la textura de mascotas que corre por el
   resto del sitio (ver body::before en app.css): quedaba un tajo liso justo
   donde el hero se junta con el contenido. Mismo patrón, mismo tamaño,
   opacidad muy baja — no compite con el contenido de abajo, solo evita el
   vacío. */
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
