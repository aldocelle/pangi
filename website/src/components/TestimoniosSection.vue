<script setup>
import { ref } from 'vue';
import { useReveal } from '../useReveal.js';

defineProps({
  testimonios: { type: Array, required: true },
});

const root = ref(null);
useReveal(root);

// La inicial en vez de una foto: ver la nota en config.js. Se toma del nombre,
// no de un campo aparte, para que agregar una reseña sea escribir dos líneas.
function inicial(nombre) {
  return (nombre || '?').trim().charAt(0).toUpperCase();
}
</script>

<template>
  <section ref="root" class="seccion testimonios">
    <div class="wrap">
      <div class="testimonios-cabezal">
        <h2 class="titulo-hero" data-reveal>
          Lo que dicen
          <span class="enfasis">quienes ya vinieron</span>
        </h2>
      </div>

      <ul class="testimonios-lista">
        <li v-for="t in testimonios" :key="t.autor" class="testimonio" data-reveal>
          <!-- Las estrellas son decorativas: la valoración ya la dice el texto
               de al lado, y cinco caracteres sueltos leídos uno a uno por un
               lector de pantalla solo estorban. De ahí el aria-hidden y el
               texto alternativo en .sr-only. -->
          <p class="estrellas" aria-hidden="true">★★★★★</p>
          <span class="sr-only">Cinco estrellas de cinco</span>

          <blockquote class="testimonio-texto">{{ t.texto }}</blockquote>

          <figcaption class="testimonio-autor">
            <img
              v-if="t.foto"
              class="testimonio-foto"
              :src="t.foto"
              :alt="`Foto de ${t.autor}`"
              loading="lazy"
            />
            <span v-else class="testimonio-inicial" aria-hidden="true">{{ inicial(t.autor) }}</span>
            <span class="testimonio-nombre">{{ t.autor }}</span>
          </figcaption>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
/* Fondo tintado y no blanco: separa el bloque de las secciones vecinas sin
   una línea divisoria, y el turquesa suave es el mismo que ya usa el sitio
   para marcar "aparte" (ver --acento-suave en app.css). */
.testimonios { background: var(--acento-suave); }

.testimonios-cabezal { margin-bottom: clamp(2rem, 5vw, 3rem); }

.testimonios-lista {
  list-style: none; margin: 0; padding: 0;
  display: grid; gap: clamp(1rem, 2.5vw, 1.6rem);
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
}

.testimonio {
  display: flex; flex-direction: column; gap: 0.9rem;
  background: var(--color-white);
  border-radius: var(--r-lg, 18px);
  padding: clamp(1.4rem, 3vw, 1.9rem);
  box-shadow: 0 4px 18px var(--color-shadow);
}

.estrellas {
  margin: 0; letter-spacing: 0.12em; line-height: 1;
  font-size: 1.05rem;
  /* Ámbar y no turquesa: una estrella de valoración se lee como estrella por
     el color tanto como por la forma. */
  color: #f0a202;
}

.testimonio-texto {
  margin: 0; flex: 1;
  color: var(--ink); font-size: 1.02rem; line-height: 1.65;
}

.testimonio-autor {
  display: flex; align-items: center; gap: 0.7rem;
  margin-top: 0.2rem;
}
.testimonio-inicial {
  flex: none; width: 40px; height: 40px;
  display: grid; place-items: center;
  border-radius: 50%;
  background: var(--acento-suave); color: var(--acento-fuerte);
  font-weight: 700; font-size: 1.05rem;
}
.testimonio-foto {
  flex: none; width: 40px; height: 40px;
  border-radius: 50%; object-fit: cover;
}
.testimonio-nombre { font-weight: 600; color: var(--azul); }

.sr-only {
  position: absolute; width: 1px; height: 1px;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap;
}
</style>
