<script setup>
import { ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useReveal } from '../useReveal.js';
import IconServicio from './IconServicio.vue';

defineProps({
  reservaUrl: { type: String, required: true },
  sinTitulo: { type: Boolean, default: false }, // oculta el título si la página ya tiene uno
});

/* Un servicio se destaca aparte con foto propia — Medicina preventiva, lo
   que trae a la mayoría de los pacientes durante el año — y el resto se lee
   como lista, no como tarjetas: seis tarjetas idénticas con un icono arriba es
   exactamente el patrón de plantilla que el sitio no debe parecer.
   Misma foto y misma bajada que el bloque destacado de ServiciosView, para
   que se lea igual en las dos páginas.
   Cuatro filas y no cinco: con el destacado arriba, el resto entra en el
   límite de ~4 opciones por decisión antes de que sume carga cognitiva. */
const SERVICIOS = [
  { titulo: 'Consultas Generales', desc: 'Enfermedades y problemas de tu mascota.' },
  { titulo: 'Esterilizaciones', desc: 'Cirugía programada, con control post-operatorio.' },
  { titulo: 'Peluquería', desc: 'Baño, corte y limpieza, también para gatos.' },
  { titulo: 'Ecografías y Radiografías', desc: 'Diagnóstico por imágenes en la clínica.' },
];

const root = ref(null);
useReveal(root);
</script>

<template>
  <section id="servicios" ref="root" class="seccion servicios">
    <div class="wrap">
      <div v-if="!sinTitulo" class="seccion-titulo" data-reveal>
        <h2 class="titulo-hero">
          Todo lo que
          <span class="enfasis">tu mascota necesita</span>
        </h2>
        <p class="titulo-hero-sub">
          Una clínica completa en Puente Alto, con especialistas para cada etapa.
        </p>
      </div>

      <div class="composicion">
        <!-- La lista, a la izquierda -->
        <ul class="lista">
          <li v-for="s in SERVICIOS" :key="s.titulo" data-reveal>
            <a :href="reservaUrl" target="_blank" rel="noopener" class="fila" data-glow>
              <span class="fila-ic"><IconServicio :nombre="s.titulo" /></span>
              <span class="fila-txt">
                <span class="fila-tit">{{ s.titulo }}</span>
                <span class="fila-desc">{{ s.desc }}</span>
              </span>
              <svg class="fila-flecha" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12h13M13 6l6 6-6 6" />
              </svg>
            </a>
          </li>
          <li data-reveal>
            <RouterLink to="/servicios" class="fila fila-ver-todos">
              <span class="fila-ic fila-ic-mas" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
              </span>
              <span class="fila-txt">
                <span class="fila-tit">Ver todos los servicios</span>
              </span>
              <svg class="fila-flecha" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12h13M13 6l6 6-6 6" />
              </svg>
            </RouterLink>
          </li>
        </ul>

        <!-- Bloque dominante: Medicina preventiva — misma foto y misma
             bajada que en ServiciosView.vue. -->
        <article class="especialidad" data-reveal>
          <img
            class="esp-foto"
            src="/medicina-preventiva.webp"
            alt="Un veterinario golden revisando las orejas de un beagle sobre la mesa de consulta"
            loading="lazy" decoding="async" width="1200" height="960"
          />
          <div class="esp-cont">
            <p class="esp-marca">Lo más pedido</p>
            <h3>Medicina preventiva</h3>
            <p class="esp-desc">
              Vacunas, desparasitación y controles al día, con un recordatorio
              cuando se acerca la próxima fecha.
            </p>
          </div>
        </article>
      </div>
      <!-- Sin botón de reserva al cierre: la sección desemboca directo en
           CtaAgendar.vue, que ya lo trae. Dos "Reserva de hora" seguidos
           no dan más opciones, solo hacen dudar de si llevan al mismo lado. -->
    </div>
  </section>
</template>

<style scoped>
/* Blanco, igual que "Los amamos, igual que tú": en Inicio las secciones
   corren seguidas sobre el mismo fondo y la separación la da el aire entre
   ellas, no un cambio de color. */
.servicios { background: transparent; }
/* Azul de marca en la primera línea; el énfasis lo pone .titulo-hero .enfasis
   en el acento, igual que el hero de la portada. */
.seccion-titulo h2 { color: var(--azul); }

/* La foto pesa más que la lista: el bloque destacado es lo que el ojo debe
   encontrar primero en esta sección. */
.composicion {
  display: grid;
  grid-template-columns: 1fr 1.08fr;
  gap: clamp(1.4rem, 3vw, 2.6rem);
  align-items: stretch;
}

/* ── Bloque destacado ── */
.especialidad {
  position: relative;
  border-radius: var(--r-lg);
  overflow: hidden;
  min-height: 460px;
  display: flex;
  isolation: isolate;
}
.esp-foto {
  position: absolute; inset: 0;
  width: 100%; height: 100%; object-fit: cover;
  object-position: 62% center; /* mismo encuadre que el bloque de ServiciosView.vue */
  transition: transform var(--dur-slow) var(--ease);
}
.especialidad::after {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(0deg,
    var(--azul-oscuro) 4%,
    rgba(26, 35, 80, 0.82) 34%,
    rgba(26, 35, 80, 0.34) 62%,
    rgba(26, 35, 80, 0.06) 100%);
}
.especialidad:hover .esp-foto { transform: scale(1.04); }

.esp-cont {
  position: relative; z-index: 1;
  margin-top: auto;
  padding: clamp(1.5rem, 3vw, 2.2rem);
  max-width: 46ch;
}
/* Marca de sección en minúsculas con guía de color: el bloque destacado se
   señala una vez, no con un kicker uppercase repetido en cada bloque.
   El texto va blanco y no en el acento: esta etiqueta cae sobre la parte
   ALTA del degradado que cubre la foto, donde el velo es más delgado y el
   fondo puede llegar a rgb(68,74,106). Ahí ni el turquesa vivo alcanza
   (2.97:1 contra el 4.5:1 exigido), porque el fondo depende de la foto y no
   de un color fijo. El acento sigue presente en la guía, que al ser una
   forma y no texto no tiene mínimo que cumplir. */
.esp-marca {
  display: flex; align-items: center; gap: 0.6rem;
  color: #fff;
  font-weight: 600; font-size: 0.9rem;
  margin-bottom: 0.55rem;
}
.esp-marca::before {
  content: ''; width: 26px; height: 2px; border-radius: 2px;
  background: var(--acento-claro);
}
.especialidad h3 {
  color: #fff;
  font-size: clamp(1.6rem, 3.2vw, 2.1rem);
  margin-bottom: 0.5rem;
}
.esp-desc {
  color: rgba(255, 255, 255, 0.9);
  font-size: 1rem;
  line-height: 1.65; /* texto claro sobre oscuro pide algo más de aire */
}

/* ── Lista de servicios ── */
.lista { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.lista li + li { border-top: 1px solid var(--line); }

.fila {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 1rem;
  padding: clamp(0.95rem, 1.8vw, 1.25rem) 0.9rem;
  border-radius: var(--r-md);
  /* transform y no padding-left: desplaza en la GPU sin recalcular layout
     en cada hover de cada fila. */
  transition: background var(--dur-fast) var(--ease), transform var(--dur-fast) var(--ease);
}
.fila-ic {
  width: 42px; height: 42px; padding: 9px;
  border-radius: var(--r-md);
  background: var(--azul-tint);
  color: var(--azul);
  transition: background var(--dur-fast) var(--ease), color var(--dur-fast) var(--ease);
}
.fila-txt { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
.fila-tit {
  font-family: 'Poppins', 'Open Sans', system-ui, sans-serif;
  font-weight: 600; font-size: 1.05rem; color: var(--azul);
  transition: color var(--dur-fast) var(--ease);
}
.fila-desc { color: var(--ink-soft); font-size: 0.9rem; line-height: 1.45; }
.fila-flecha {
  width: 20px; height: 20px; color: var(--acento);
  opacity: 0; transform: translateX(-6px);
  transition: opacity var(--dur-fast) var(--ease), transform var(--dur-fast) var(--ease);
}

.fila:hover, .fila:focus-visible {
  background: var(--surface);
  transform: translateX(0.4rem);
  box-shadow: var(--shadow);
}
.fila:hover .fila-ic, .fila:focus-visible .fila-ic { background: var(--acento-suave); color: var(--acento-fuerte); }
.fila:hover .fila-tit, .fila:focus-visible .fila-tit { color: var(--acento-fuerte); }
.fila:hover .fila-flecha, .fila:focus-visible .fila-flecha { opacity: 1; transform: none; }
.fila:focus-visible { outline: 2px solid var(--acento); outline-offset: 2px; }

/* Fila "Ver todos": misma forma que las de servicio, tratamiento más
   discreto — es la salida de la lista, no un servicio más. Filete
   punteado en vez del ícono relleno, para que se lea como link de cierre. */
.fila-ver-todos .fila-tit { color: var(--ink-soft); font-weight: 600; }
.fila-ver-todos:hover .fila-tit, .fila-ver-todos:focus-visible .fila-tit { color: var(--acento-fuerte); }
.fila-ic-mas {
  background: transparent;
  box-shadow: inset 0 0 0 1.5px var(--line);
  color: var(--ink-soft);
}
.fila-ic-mas svg { width: 18px; height: 18px; }
.fila-ver-todos:hover .fila-ic-mas, .fila-ver-todos:focus-visible .fila-ic-mas {
  background: var(--acento-suave); color: var(--acento-fuerte); box-shadow: none;
}


@media (max-width: 900px) {
  .composicion { grid-template-columns: 1fr; }
  .especialidad { min-height: 440px; }
}
@media (max-width: 540px) {
  /* Alto suficiente para que la foto siga leyéndose por encima del texto:
     apretada, el degradado se come al gato y queda solo un fondo azul. */
  .especialidad { min-height: 430px; }
  .fila { gap: 0.8rem; padding-left: 0.6rem; padding-right: 0.6rem; }
  .fila-ic { width: 38px; height: 38px; padding: 8px; }
}

@media (prefers-reduced-motion: reduce) {
  .esp-foto, .fila, .fila-ic, .fila-tit, .fila-flecha { transition: none; }
  .especialidad:hover .esp-foto { transform: none; }
  .fila:hover, .fila:focus-visible { transform: none; }
  .fila-flecha { opacity: 1; transform: none; }
}
</style>
