<script setup>
import { ref, computed, onMounted } from 'vue';
import { API_URL, RESERVA_URL, CLINICA } from '../config.js';
import { api } from '../api.js';
import { useReveal } from '../useReveal.js';
import EquipoHero from '../components/EquipoHero.vue';
import CtaAgendar from '../components/CtaAgendar.vue';

const equipo = ref([]);
const cargando = ref(true);
const error = ref('');
const root = ref(null);

// Quienes tienen foto real van primero — se ve más completo de entrada,
// en vez de mezclar fotos e iniciales al azar. Dentro de cada grupo se
// respeta el orden que ya trae el backend (orden/nombre).
// "Hora Telemedicina" es una entrada operativa del catálogo (para agendar
// el servicio de telemedicina en el portal de reservas), no una persona del
// equipo — se excluye de esta vista.
/* Retratos que viven en public/equipo/: viajan con el sitio y no dependen del
   disco del servidor. La clave es el nombre sin tratamiento ni tildes, para no
   depender de cómo esté escrito en la base. Vacío = la foto subida desde el
   panel, o las iniciales. Ejemplo: 'marta soto': 'marta-soto.jpg'. */
const RETRATOS = {
  'marta soto': 'marta-soto.webp',
  'tomas fuentes': 'tomas-fuentes.webp',
  'carla rivas': 'carla-rivas.webp',
  'javiera paredes': 'javiera-paredes.webp',
  'ignacio morales': 'ignacio-morales.webp',
  'valentina rojas': 'valentina-rojas.webp',
  'camila herrera': 'camila-herrera.webp',
};

function clave(nombre) {
  return (nombre || '')
    .replace(/^(Dr|Dra)\.?\s+/i, '')
    .normalize('NFD').replace(/[̀-ͯ]/g, '') // quita tildes
    .toLowerCase().trim();
}

function urlFoto(m) {
  const local = RETRATOS[clave(m.nombre)];
  if (local) return `/equipo/${local}`;
  return m.foto ? `${API_URL}${m.foto}` : null;
}

/* Tres grupos, no uno: destacadas (la directora médica y la dermatóloga) en
   tarjetas grandes con su biografía completa; la peluquera sola justo
   debajo (peluquería, no es un cargo médico como el resto); y el resto del
   equipo abajo, en tarjetas normales y sin biografía aunque la traigan de
   la base — esta vista decide quién la muestra, no el dato en sí. Las
   claves van sin tildes ni tratamiento, igual que RETRATOS, así que
   sobreviven a que se corrija el nombre en el panel. */
const DESTACADOS = ['marta soto', 'carla rivas'];
const SOLA = 'camila herrera';

function buscar(lista, key) {
  return lista.find((m) => clave(m.nombre) === key) || null;
}

const equipoActivo = computed(() =>
  equipo.value.filter((m) => !/telemedicina/i.test(m.nombre) && !/telemedicina/i.test(m.especialidad || ''))
);

const destacados = computed(() =>
  DESTACADOS.map((k) => buscar(equipoActivo.value, k)).filter(Boolean)
);

const fichaSola = computed(() => buscar(equipoActivo.value, SOLA));

/* El resto solo pesa por retrato: sin biografía en esta grilla, ordenar
   también por `descripcion` ya no cambia lo que se ve, solo el orden. */
const resto = computed(() => {
  const excluidos = new Set([...DESTACADOS, SOLA]);
  return [...equipoActivo.value]
    .filter((m) => !excluidos.has(clave(m.nombre)))
    .sort((a, b) => (urlFoto(b) ? 1 : 0) - (urlFoto(a) ? 1 : 0));
});

function iniciales(nombre) {
  const p = nombre.replace(/^(Dr|Dra)\.?\s+/i, '').split(' ').filter(Boolean);
  return ((p[0]?.[0] || '') + (p[1]?.[0] || '')).toUpperCase();
}

useReveal(root);

onMounted(async () => {
  try {
    const { data } = await api.get('/catalogo/especialistas');
    equipo.value = data;
  } catch (err) {
    error.value = 'No pudimos cargar el equipo en este momento.';
  } finally {
    cargando.value = false;
  }
});
</script>

<template>
  <div ref="root" class="pagina">
    <EquipoHero :reserva-url="RESERVA_URL" :clinica="CLINICA" />

    <p v-if="error" class="carga-error">{{ error }}</p>
    <p v-else-if="cargando" class="carga-msg">Cargando equipo…</p>

    <section v-else class="seccion equipo-sec">
      <div class="wrap">
        <div class="bloque-titulo" data-reveal>
          <h2 class="titulo-hero">
            Estamos al servicio
            <span class="enfasis">de la comunidad</span>
          </h2>
          <p>
            Sabemos que nuestros pacientes son parte de la familia y son, además, nuestros
            vecinos. Por eso, brindamos
            <strong>una atención de primera, servicial y centrada en una buena relación
            con los dueños y dueñas.</strong>
          </p>
        </div>
        <div class="grid-destacados">
          <article v-for="m in destacados" :key="m.id" class="m-card m-card--grande" data-reveal data-glow>
            <div class="m-foto-wrap">
              <img v-if="urlFoto(m)" :src="urlFoto(m)" :alt="`Foto de ${m.nombre}`" class="m-foto" loading="lazy" />
              <div v-else class="m-foto m-foto-ini" aria-hidden="true">{{ iniciales(m.nombre) }}</div>
            </div>
            <h3>{{ m.nombre }}</h3>
            <p v-if="m.especialidad" class="m-cargo">{{ m.especialidad }}</p>
            <p v-if="m.descripcion" class="m-bio">{{ m.descripcion }}</p>
          </article>
        </div>

        <div v-if="fichaSola" class="fila-sola">
          <article class="m-card m-card--grande" data-reveal data-glow>
            <div class="m-foto-wrap">
              <img v-if="urlFoto(fichaSola)" :src="urlFoto(fichaSola)" :alt="`Foto de ${fichaSola.nombre}`" class="m-foto" loading="lazy" />
              <div v-else class="m-foto m-foto-ini" aria-hidden="true">{{ iniciales(fichaSola.nombre) }}</div>
            </div>
            <h3>{{ fichaSola.nombre }}</h3>
            <p v-if="fichaSola.especialidad" class="m-cargo">{{ fichaSola.especialidad }}</p>
            <p v-if="fichaSola.descripcion" class="m-bio">{{ fichaSola.descripcion }}</p>
          </article>
        </div>

        <div class="grid-equipo">
          <article v-for="m in resto" :key="m.id" class="m-card" data-reveal data-glow>
            <div class="m-foto-wrap">
              <img v-if="urlFoto(m)" :src="urlFoto(m)" :alt="`Foto de ${m.nombre}`" class="m-foto" loading="lazy" />
              <div v-else class="m-foto m-foto-ini" aria-hidden="true">{{ iniciales(m.nombre) }}</div>
            </div>
            <h3>{{ m.nombre }}</h3>
            <p v-if="m.especialidad" class="m-cargo">{{ m.especialidad }}</p>
            <!-- Sin biografía a propósito: solo las destacadas de arriba y
                 la peluquera la muestran, aunque el resto la traiga
                 cargada en la base. -->
          </article>
        </div>
      </div>
    </section>

    <CtaAgendar :reserva-url="RESERVA_URL" />
  </div>
</template>

<style scoped>
.carga-msg, .carga-error { text-align: center; padding: 3rem 1rem; color: var(--ink-soft); }
.carga-error { color: var(--acento); }

/* Blanco, como Inicio. Las tarjetas ya se distinguen por su filete: sobre el
   fondo tintado el contorno era casi redundante y la página se leía teñida. */
.equipo-sec { background: transparent; }
.bloque-titulo { text-align: center; max-width: 62ch; margin: 0 auto 2.6rem; }
.bloque-titulo h2 { color: var(--azul); font-size: clamp(1.6rem, 3.6vw, 2.2rem); margin-bottom: 0.8rem; }
.bloque-titulo p { color: var(--ink-soft); font-size: 1.02rem; line-height: 1.6; }

/* Las dos destacadas: dos columnas como máximo, más
   anchas que las del resto del equipo — son tarjetas para leer una
   biografía completa, no solo un nombre y un cargo. `auto-fit` sí, a
   diferencia de `.grid-equipo`: acá son siempre 1 o 2 tarjetas, nunca una
   fila incompleta que se sienta como un hueco. */
.grid-destacados {
  display: grid; grid-template-columns: minmax(0, 720px);
  justify-content: center; gap: 1.4rem; margin-bottom: 1.4rem;
}
/* La peluquera, sola: mismo tamaño de tarjeta que las destacadas de
   arriba (así no se lee como una rebajada de categoría), centrada en su
   propia fila para no competir por ancho con nadie más. */
.fila-sola { display: flex; justify-content: center; margin-bottom: 2.2rem; }
.fila-sola .m-card { width: 100%; max-width: 720px; }

/* Retrato al costado y no arriba: estas fichas se leen como una presentación,
   con la foto a la izquierda y el texto a su derecha.
   Solo aquí y no en la grilla del resto del equipo: esas tarjetas son nombre y
   cargo dentro de una columna de 260px, y un retrato al costado dejaría dos
   líneas sueltas contra una foto en vez de un bloque que se lee.
   Áreas nombradas y no dos columnas a secas porque los cuatro elementos son
   hermanos en el template: sin `grid-template-areas` caerían uno por celda y
   el nombre terminaría al lado de la foto, el cargo debajo de la foto, y así. */
.m-card.m-card--grande {
  padding: 2.1rem;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  grid-template-rows: auto auto 1fr;
  grid-template-areas:
    "foto nombre"
    "foto cargo"
    "foto bio";
  column-gap: 1.6rem;
  align-items: start;
  text-align: left;
}
.m-card--grande .m-foto-wrap { grid-area: foto; margin-bottom: 0; }
.m-card--grande h3 { grid-area: nombre; font-size: 1.32rem; }
/* `justify-self` y no `align-self`: en una celda de grilla el inline-block se
   estira a todo el ancho de la columna, y la píldora de especialidad pasaba de
   rodear su palabra a ser una barra de borde a borde. */
.m-card--grande .m-cargo { grid-area: cargo; justify-self: start; }
.m-card--grande .m-bio { grid-area: bio; font-size: 0.94rem; }
.m-card--grande .m-foto,
.m-card--grande .m-foto-ini { width: 148px; height: 148px; margin: 0; }
.m-card--grande .m-foto-ini { font-size: 2.4rem; }

/* Bajo ~560px la tarjeta ya no tiene ancho para dos columnas: el texto
   quedaría en una canaleta de menos de 20 caracteres. Vuelve al apilado
   centrado, que es como se veía antes. */
@media (max-width: 560px) {
  /* La clase repetida iguala la especificidad de la regla de arriba: una media
     query no suma especificidad, así que con un solo `.m-card--grande` acá las
     dos columnas de escritorio seguían ganando y el texto quedaba en una
     canaleta de tres palabras por línea. */
  .m-card.m-card--grande {
    grid-template-columns: 1fr;
    grid-template-rows: none;
    grid-template-areas: "foto" "nombre" "cargo" "bio";
    text-align: center;
  }
  .m-card--grande .m-foto-wrap { margin-bottom: 1rem; }
  /* Apilada, la tarjeta vuelve a estar centrada: la píldora tiene que
     acompañar al nombre, no quedarse pegada al borde izquierdo. */
  .m-card--grande .m-cargo { justify-self: center; }
  .m-card--grande .m-foto,
  .m-card--grande .m-foto-ini { margin: 0 auto; }
  .m-card--grande .m-bio { text-align: left; }
}

/* 260px de mínimo da tres columnas dentro del ancho de --wrap (1140px): una
   cuarta no cabe. Se prefiere ese mínimo a fijar 320px, que daría las mismas
   tres en escritorio pero saltaría de dos columnas a una alrededor de los
   700px de viewport, dejando tarjetas de casi 650px de ancho en tablet.
   `auto-fill` y no `auto-fit` a propósito: son varias personas, y con
   `auto-fit` la última fila incompleta se estiraría a todo el ancho. */
.grid-equipo {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1.6rem;
  /* Cada tarjeta mide lo suyo: sin biografía, todas miden prácticamente lo
     mismo, así que estirarlas a la más alta de la fila ya no hace falta. */
  align-items: start;
}
.m-card {
  background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg);
  padding: 1.9rem 1.7rem; text-align: center;
  transition: transform var(--dur-fast) var(--ease), box-shadow var(--dur-fast) var(--ease), border-color var(--dur-fast) var(--ease);
  /* `isolate` encierra el z-index negativo de la textura dentro de la tarjeta:
     sin él, un pseudo-elemento posicionado se pinta por encima del texto. */
  position: relative; isolation: isolate;
}
/* Textura de huellas, en el azul de marca al 4.5%: se nota como papel, no
   como dibujo. Va en un pseudo-elemento y no en `background` de la tarjeta
   para poder desvanecerla hacia arriba, donde están el retrato y el nombre,
   y dejarla respirar solo en la mitad baja. Sobre ella el texto conserva
   5.6:1 de contraste, por encima del 4.5:1 que pide AA.
   Las tres huellas de la baldosa tienen giros y tamaños distintos: repetidas
   en la misma orientación el patrón se lee como cuadrícula, no como rastro. */
.m-card::before {
  content: ''; position: absolute; inset: 0; border-radius: inherit; z-index: -1;
  background-image: url("data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22132%22%20height%3D%22132%22%20viewBox%3D%220%200%20132%20132%22%3E%3Cg%20fill%3D%22%2326326e%22%20fill-opacity%3D%220.03%22%3E%3Cg%20transform%3D%22translate(28%2C30)%20rotate(-22)%20scale(1.0)%22%3E%3Cellipse%20cx%3D%220%22%20cy%3D%226.5%22%20rx%3D%228.2%22%20ry%3D%226.6%22%2F%3E%3Cellipse%20cx%3D%22-9%22%20cy%3D%22-3.6%22%20rx%3D%223.3%22%20ry%3D%224.3%22%2F%3E%3Cellipse%20cx%3D%22-3.1%22%20cy%3D%22-8.6%22%20rx%3D%223.3%22%20ry%3D%224.4%22%2F%3E%3Cellipse%20cx%3D%223.1%22%20cy%3D%22-8.6%22%20rx%3D%223.3%22%20ry%3D%224.4%22%2F%3E%3Cellipse%20cx%3D%229%22%20cy%3D%22-3.6%22%20rx%3D%223.3%22%20ry%3D%224.3%22%2F%3E%3C%2Fg%3E%3Cg%20transform%3D%22translate(94%2C66)%20rotate(14)%20scale(0.86)%22%3E%3Cellipse%20cx%3D%220%22%20cy%3D%226.5%22%20rx%3D%228.2%22%20ry%3D%226.6%22%2F%3E%3Cellipse%20cx%3D%22-9%22%20cy%3D%22-3.6%22%20rx%3D%223.3%22%20ry%3D%224.3%22%2F%3E%3Cellipse%20cx%3D%22-3.1%22%20cy%3D%22-8.6%22%20rx%3D%223.3%22%20ry%3D%224.4%22%2F%3E%3Cellipse%20cx%3D%223.1%22%20cy%3D%22-8.6%22%20rx%3D%223.3%22%20ry%3D%224.4%22%2F%3E%3Cellipse%20cx%3D%229%22%20cy%3D%22-3.6%22%20rx%3D%223.3%22%20ry%3D%224.3%22%2F%3E%3C%2Fg%3E%3Cg%20transform%3D%22translate(52%2C104)%20rotate(-6)%20scale(0.94)%22%3E%3Cellipse%20cx%3D%220%22%20cy%3D%226.5%22%20rx%3D%228.2%22%20ry%3D%226.6%22%2F%3E%3Cellipse%20cx%3D%22-9%22%20cy%3D%22-3.6%22%20rx%3D%223.3%22%20ry%3D%224.3%22%2F%3E%3Cellipse%20cx%3D%22-3.1%22%20cy%3D%22-8.6%22%20rx%3D%223.3%22%20ry%3D%224.4%22%2F%3E%3Cellipse%20cx%3D%223.1%22%20cy%3D%22-8.6%22%20rx%3D%223.3%22%20ry%3D%224.4%22%2F%3E%3Cellipse%20cx%3D%229%22%20cy%3D%22-3.6%22%20rx%3D%223.3%22%20ry%3D%224.3%22%2F%3E%3C%2Fg%3E%3C%2Fg%3E%3C%2Fsvg%3E");
  -webkit-mask-image: linear-gradient(to bottom, transparent 28%, #000 74%);
  mask-image: linear-gradient(to bottom, transparent 28%, #000 74%);
  pointer-events: none;
}
.m-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); border-color: var(--acento); }
.m-card .m-foto-wrap { margin-bottom: 1rem; }
.m-card .m-foto {
  width: 112px; height: 112px; border-radius: 50%; object-fit: cover;
  margin: 0 auto; box-shadow: var(--shadow);
}
/* Iniciales para quien no tiene retrato. En azul sólido eran 9 discos oscuros
   que pesaban más que las fotos reales y convertían la grilla en un muro de
   ausencias; en tinte de marca ceden el protagonismo a los retratos y siguen
   con 10.5:1 de contraste, muy por encima del mínimo. */
.m-card .m-foto-ini {
  width: 112px; height: 112px; border-radius: 50%; margin: 0 auto;
  font-size: 1.9rem; font-weight: 700; letter-spacing: 0.01em;
  background: var(--azul-tint); color: var(--azul);
  display: grid; place-items: center;
}
/* 1.18rem contra los 0.88rem de la biografía: un salto de 1.34, suficiente
   para que el nombre mande. A 1.05rem la jerarquía quedaba plana. */
.m-card h3 { color: var(--azul); font-size: 1.18rem; margin-bottom: 0.4rem; }
.m-cargo {
  display: inline-block; color: var(--acento-fuerte); font-weight: 600; font-size: 0.82rem;
  background: var(--acento-suave); padding: 0.25em 0.8em; border-radius: var(--r-full);
  margin-bottom: 0.7rem;
}
/* La biografía viene de la base y puede traer saltos de línea propios, que
   `pre-line` respeta tal como se escribieron en el panel, sin abrir la puerta
   a HTML.
   Alineada a la izquierda aunque la tarjeta esté centrada: centrar tres o
   cuatro líneas deja los dos bordes irregulares y obliga al ojo a buscar
   dónde empieza cada línea. El nombre y la especialidad siguen centrados
   porque son de una línea, donde centrar sí ordena.
   El filete superior separa quién es la persona de qué hace, y le da a la
   tarjeta un punto de apoyo horizontal que antes no tenía. */
.m-bio {
  color: var(--ink-soft); font-size: 0.88rem; line-height: 1.6;
  white-space: pre-line; text-align: left; text-wrap: pretty;
  border-top: 1px solid var(--line);
  margin-top: 0.35rem; padding-top: 0.95rem;
}
</style>
