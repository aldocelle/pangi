<script setup>
import { ref } from 'vue';
import { useReveal } from '../useReveal.js';

defineProps({
  reservaUrl: { type: String, required: true },
  clinica: { type: Object, required: true },
});

/* Tres razones concretas para escribir, no genéricas: son las mismas que ya
   demuestra el resto del sitio (el ciclo de recordatorios, "Hecho con cariño"
   del pie, el equipo con especialidades reales) — aquí solo se nombran. */
const CONFIANZA = [
  {
    texto: 'Respuesta rápida',
    // Reloj: mismo lenguaje monolineal que IconServicio.vue (trazo 1.6, currentColor).
    path: 'M12 7v5l3.2 1.9M20 12a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
  },
  {
    texto: 'Atención cariñosa',
    path: 'M12 20.2s-7.3-4.6-7.3-9.6a4.2 4.2 0 0 1 7.3-2.8 4.2 4.2 0 0 1 7.3 2.8c0 5-7.3 9.6-7.3 9.6Z',
  },
  {
    texto: 'Profesionales de confianza',
    path: 'M12 3.6 19 6v5.4c0 4.4-3 7.2-7 9-4-1.8-7-4.6-7-9V6l7-2.4Z M9.2 12.1l1.9 1.9 3.7-3.9',
  },
];

const root = ref(null);
useReveal(root);
</script>

<template>
  <section id="inicio" ref="root" class="hero">
    <!-- La foto ya no va en una tarjeta dentro de la grilla: cubre la
         sección entera, a sangre, y el panel oscuro se funde hacia ella de
         izquierda a derecha — así lo pide la referencia. El texto vive sobre
         la mitad izquierda, donde el velo sigue opaco. -->
    <img
      class="hero-fondo" src="/portada-contacto.webp" alt="" aria-hidden="true"
      loading="eager"
    />
    <div class="hero-fondo-velo" aria-hidden="true"></div>
    <div class="wrap hero-in">
      <div class="hero-texto">
        <h1 data-reveal>
          ¿Tu mascota
          <span class="hero-enfasis">necesita atención?</span>
        </h1>
        <p class="hero-sub" data-reveal>
          Agenda tu consulta o escríbenos. Estamos aquí para ayudarte.
        </p>
        <div class="hero-acciones" data-reveal>
          <a :href="reservaUrl" target="_blank" rel="noopener" class="btn btn-primary btn-lg">
            Reserva de hora
          </a>
          <a :href="`https://wa.me/${clinica.telefonoPlano}`" target="_blank" rel="noopener" class="btn btn-outline btn-lg">
            Escríbenos por WhatsApp
          </a>
        </div>

        <!-- Tres motivos y una ubicación, no como texto suelto: son la
             misma clase de dato que el resto del sitio ya demuestra en
             detalle (recordatorios, equipo, horario) — aquí se resumen
             para quien solo va a ver esta pantalla antes de escribir. -->
        <ul class="hero-confianza" data-reveal>
          <li v-for="(c, i) in CONFIANZA" :key="c.texto">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path :d="c.path" />
            </svg>
            <span>{{ c.texto }}</span>
          </li>
        </ul>

        <p class="hero-ubicacion" data-reveal>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
               stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" /><circle cx="12" cy="10" r="3" />
          </svg>
          Puente Alto y comunas cercanas
        </p>
      </div>
    </div>
    <div class="hero-onda" aria-hidden="true"></div>
  </section>
</template>

<style scoped>
/* Mismo sistema visual que el hero de Inicio (HeroSection.vue): gradiente,
   blob y onda. Se repite en vez de compartir el componente porque el
   contenido es distinto (esta sección lleva botones, motivos de confianza y
   ubicación, que el hero de Inicio no tiene) — no vale la pena una prop por
   cada diferencia entre las dos. */
.hero {
  position: relative;
  background: var(--azul-oscuro); /* se ve un instante mientras carga la foto */
  color: #fff; overflow: hidden;
  padding: clamp(3rem, 7vw, 5rem) 0 clamp(4rem, 7vw, 5.5rem);
  min-height: 560px; display: flex; align-items: center;
}

.hero-fondo {
  position: absolute; inset: 0;
  width: 100%; height: 100%; object-fit: cover;
  /* 72% horizontal deja al sujeto del lado derecho, donde el velo ya no
     tapa; 28% vertical porque la sección es más ancha que alta y `cover`
     recorta arriba/abajo — a 50% la foto original perdía las orejas. */
  object-position: 72% 28%;
}
/* El panel oscuro se funde hacia la foto, de izquierda a derecha: sólido
   donde vive el texto, transparente del todo pasado el 80%, donde el perro
   tiene que verse a color pleno. Reemplaza al degradé diagonal fijo (mismo
   en todo el sitio) porque aquí el fondo es una foto real, no un color, y
   tapar toda la foto con velo habría anulado el sentido de usarla. */
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

.hero-in {
  position: relative; z-index: 2;
  width: 100%;
}
.hero-texto { max-width: 620px; }

.hero-texto h1 {
  font-size: clamp(2.1rem, 4.8vw, 3.4rem);
  letter-spacing: -0.025em;
}
.hero-sub {
  font-size: var(--texto-lead); color: rgba(255, 255, 255, 0.88);
  margin-top: 1.1rem; max-width: 46ch; line-height: var(--lead-alto);
}
.hero-acciones { display: flex; flex-wrap: wrap; gap: 0.9rem; margin-top: 1.9rem; }
.btn-lg { padding: 0.95em 1.9em; font-size: 1.02rem; }

/* Tres motivos en fila, separados por un filete y no por comas: cada uno
   pesa lo mismo, así que el ojo los cuenta de un vistazo. `flex-wrap` y no
   una grilla de tres columnas fijas: en una columna angosta se apilan sin
   dejar una tercera celda huérfana a mitad de fila. */
.hero-confianza {
  list-style: none; margin: 1.7rem 0 0; padding: 0;
  display: flex; flex-wrap: wrap; row-gap: 0.6rem;
}
.hero-confianza li { display: flex; align-items: center; gap: 0.42rem; }
/* El filete va en `padding-left` + `::before` del elemento que sigue, no en
   `padding-right` del anterior sumado a un `column-gap` aparte: eso
   duplicaba el espacio entre ítems (gap + padding a la vez) y era lo que
   hacía que el tercer motivo no entrara en la fila, en una columna de solo
   531px a 1440px de pantalla. Así el espacio entre dos ítems es un solo
   número, no dos sumados. */
.hero-confianza li:not(:first-child) { position: relative; padding-left: 0.9rem; margin-left: 0.15rem; }
.hero-confianza li:not(:first-child)::before {
  content: ''; position: absolute; left: 0; top: 50%; translate: 0 -50%;
  width: 1px; height: 1.1em; background: rgba(255, 255, 255, 0.22);
}
.hero-confianza svg { width: 17px; height: 17px; flex: none; color: var(--acento-claro); }
.hero-confianza span { font-size: 0.84rem; color: rgba(255, 255, 255, 0.85); white-space: nowrap; }

.hero-ubicacion {
  display: flex; align-items: center; gap: 0.5rem;
  margin-top: 1.3rem; font-size: 0.9rem; color: rgba(255, 255, 255, 0.75);
}
.hero-ubicacion svg { width: 17px; height: 17px; flex: none; color: var(--acento-claro); }

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

/* Bajo los ~860px el texto ya no cabe al lado del perro sin pisarlo: pasa a
   ocupar todo el ancho, centrado, sobre un velo que ya no se funde de
   izquierda a derecha (no hay "derecha" que reservarle a la foto) sino que
   cubre parejo — si no, el texto de la mitad derecha caería sobre el perro
   a color pleno y se volvería ilegible. */
@media (max-width: 860px) {
  .hero { min-height: 480px; text-align: center; }
  .hero-fondo-velo {
    background: linear-gradient(180deg,
      var(--azul-oscuro) 0%, rgba(26, 35, 80, 0.93) 55%, rgba(26, 35, 80, 0.8) 100%);
  }
  .hero-texto { max-width: 560px; margin-inline: auto; }
  .hero-sub { margin-inline: auto; }
  .hero-acciones { justify-content: center; }
  .hero-confianza { justify-content: center; }
  .hero-ubicacion { justify-content: center; }
}

/* Bajo los ~480px los tres motivos ya no entran en una fila (los nombres
   son largos: "Profesionales de confianza"), y una fila que se parte deja
   un filete divisor huérfano colgando antes del que quedó solo en la línea
   siguiente — se ve como un error, no como diseño. Apilados en columna y
   sin filete es lo que se lee bien cuando de verdad no entran. */
@media (max-width: 480px) {
  .hero-confianza { flex-direction: column; align-items: center; row-gap: 0.55rem; }
  .hero-confianza li:not(:first-child) { padding-left: 0; margin-left: 0; }
  .hero-confianza li:not(:first-child)::before { content: none; }
}
</style>
