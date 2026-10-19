<script setup>
import { ref, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';

defineProps({
  reservaUrl: { type: String, required: true },
  clinica: { type: Object, required: true },
});

// Navegación: cada ítem es una página propia.
const NAV = [
  { label: 'Inicio', to: '/' },
  { label: 'Equipo', to: '/equipo' },
  { label: 'Servicios', to: '/servicios' },
  { label: 'Contacto', to: '/contacto' },
];

const route = useRoute();
const router = useRouter();
const scrolled = ref(false);
const menuAbierto = ref(false);
const progreso = ref(0);
const navRef = ref(null);

/* ── La pastilla que sigue al cursor ──
   Una sola forma que se desliza entre los enlaces en vez de un fondo que
   aparece y desaparece en cada uno: el movimiento conecta de dónde viene la
   vista con a dónde va. En reposo se queda en la página actual, así que el
   mismo elemento que responde al cursor es el que dice dónde estás.
   Se mide en píxeles con getBoundingClientRect y no se deduce del CSS: los
   enlaces tienen anchos distintos ("Inicio" contra "Servicios") y sus
   posiciones cambian al reflujo. */
const marca = ref({ x: 0, y: 0, w: 0, h: 0, on: false });

/* Es una pastilla horizontal: en el desplegable de móvil, que es una columna,
   no significa nada. Ahí se apaga y vuelve el fondo por enlace. */
const ESCRITORIO = '(min-width: 1041px)';
const esEscritorio = ref(true);
let mq = null;

function moverA(el) {
  if (!esEscritorio.value || !el || !navRef.value) return;
  const nav = navRef.value.getBoundingClientRect();
  const r = el.getBoundingClientRect();
  marca.value = { x: r.left - nav.left, y: r.top - nav.top, w: r.width, h: r.height, on: true };
}

/* Reposo: el enlace de la ruta actual. Si ninguno calza — o estamos en el
   menú de móvil — se apaga donde está en vez de saltar al origen, que se
   vería como un barrido de un extremo al otro. */
function volverAlActivo() {
  if (!esEscritorio.value || !navRef.value) {
    marca.value = { ...marca.value, on: false };
    return;
  }
  const activo = navRef.value.querySelector('.nav-link.router-link-exact-active');
  if (activo) moverA(activo);
  else marca.value = { ...marca.value, on: false };
}

function onScroll() {
  const y = window.scrollY;
  scrolled.value = y > 20;
  const recorrido = document.documentElement.scrollHeight - window.innerHeight;
  progreso.value = recorrido > 0 ? Math.min(y / recorrido, 1) : 0;
}

function onCambioAncho(e) {
  esEscritorio.value = e.matches;
  volverAlActivo();
}

function cerrarMenu() {
  menuAbierto.value = false;
}

onMounted(() => {
  mq = window.matchMedia(ESCRITORIO);
  esEscritorio.value = mq.matches;
  mq.addEventListener('change', onCambioAncho);
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', volverAlActivo);
  onScroll();
  nextTick(volverAlActivo);
  /* Segunda medición cuando terminan de cargar las fuentes: Poppins y Open
     Sans llegan después del montaje y cambian el ancho de los enlaces, así
     que la primera medida deja la pastilla corrida unos píxeles. */
  document.fonts?.ready?.then(volverAlActivo);
  /* La navegación inicial del router es asíncrona (createWebHashHistory):
     en la primera carga, `nextTick` y `fonts.ready` pueden resolver ANTES
     de que Vue Router termine de marcar el enlace activo, así que la
     pastilla se queda en opacity:0 hasta el primer hover o resize. Sin
     esto, "dónde estoy parado" no se contesta al entrar al sitio, que es
     la pregunta que la pastilla existe para responder. */
  router.isReady().then(() => nextTick(volverAlActivo));
});

onUnmounted(() => {
  mq?.removeEventListener('change', onCambioAncho);
  window.removeEventListener('scroll', onScroll);
  window.removeEventListener('resize', volverAlActivo);
});

watch(() => route.path, () => nextTick(volverAlActivo));
</script>

<template>
  <header class="header" :class="{ scrolled }">
    <div class="wrap header-in">
      <RouterLink to="/" class="brand" @click="cerrarMenu">
        <img src="/isotipo.webp" alt="" class="brand-logo" />
        <span class="brand-nombre">{{ clinica.nombre }}</span>
      </RouterLink>

      <nav
        ref="navRef" class="nav" :class="{ open: menuAbierto }" aria-label="Principal"
        @mouseleave="volverAlActivo"
      >
        <span
          class="nav-marca" aria-hidden="true"
          :style="{
            transform: `translate(${marca.x}px, ${marca.y}px)`,
            width: `${marca.w}px`, height: `${marca.h}px`,
            opacity: marca.on ? 1 : 0,
          }"
        ></span>
        <RouterLink
          v-for="n in NAV" :key="n.to" :to="n.to" class="nav-link"
          @click="cerrarMenu"
          @mouseenter="moverA($event.currentTarget)"
          @focus="moverA($event.currentTarget)"
        >{{ n.label }}</RouterLink>
        <a :href="reservaUrl" target="_blank" rel="noopener" class="btn btn-primary nav-cta" @click="cerrarMenu">
          Reserva de hora
        </a>
      </nav>

      <button
        class="hamburguesa" :class="{ activa: menuAbierto }"
        :aria-expanded="menuAbierto" aria-label="Menú"
        @click="menuAbierto = !menuAbierto"
      >
        <span></span><span></span><span></span>
      </button>
    </div>

    <span class="header-progreso" aria-hidden="true" :style="{ transform: `scaleX(${progreso})` }"></span>
  </header>
</template>

<style scoped>
.header {
  position: sticky; top: 0; z-index: 50;
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(10px);
  border-bottom: 1px solid transparent;
  transition: box-shadow 0.25s, border-color 0.25s;
  /* `isolate` encierra el z-index negativo del patrón dentro del encabezado,
     igual que en las tarjetas de Equipo (.m-card en EquipoView.vue). */
  isolation: isolate;
}
/* Patrón de mascotas como textura, no como estampado: al 12% de opacidad se
   nota como papel ilustrado y deja que el texto del menú siga mandando. A
   opacidad plena los enlaces caían encima de los dibujos y el navbar se
   leía como ruido.
   Va en un pseudo-elemento y no en `background` del propio encabezado para
   poder bajarle la opacidad sin arrastrar al logo ni a los enlaces, que son
   hijos suyos. Mismo recurso que la textura de huellas de las tarjetas. */
.header::before {
  content: ''; position: absolute; inset: 0; z-index: -1;
  background-image: url('/bg-patron.png');
  background-size: 190px 190px;
  opacity: 0.12;
  pointer-events: none;
}
.header.scrolled { box-shadow: 0 4px 24px rgba(38, 50, 110, 0.08); border-bottom-color: var(--line); }
.header-in {
  display: flex; align-items: center; justify-content: space-between;
  padding-block: 0.5rem; gap: 1rem;
  transition: padding-block var(--dur) var(--ease);
}
.header.scrolled .header-in { padding-block: 0.25rem; }

/* ── Avance de lectura ──
   Filete de 2px en el borde inferior del encabezado que crece con el scroll.
   Es el movimiento que acompaña todo el rato, y a diferencia de un bucle
   decorativo dice algo: cuánto queda de página. Se dibuja con `scaleX` sobre
   una barra ya presente, así que no recalcula layout en cada evento. */
.header-progreso {
  position: absolute; left: 0; right: 0; bottom: -1px; height: 2px;
  background: linear-gradient(90deg, var(--acento-fuerte), var(--acento-claro));
  transform: scaleX(0); transform-origin: 0 50%;
  pointer-events: none;
}

.brand { display: flex; align-items: center; gap: 0.7rem; }
/* El logo se encoge al bajar. En reposo manda a tamaño completo; una vez que
   la persona está leyendo, el encabezado le devuelve unos 50px de pantalla y
   deja de competir con el contenido. */
.brand-logo {
  width: 72px; height: 72px; object-fit: contain;
  transition: width var(--dur) var(--ease), height var(--dur) var(--ease);
}
.header.scrolled .brand-logo { width: 52px; height: 52px; }
/* El isotipo no trae texto: el nombre va al lado, en la tipografía de los títulos. */
.brand-nombre {
  font-family: 'Poppins', 'Open Sans', system-ui, sans-serif; font-weight: 800;
  font-size: 1.7rem; letter-spacing: 0.08em; color: var(--azul);
}

.nav { display: flex; align-items: center; gap: 0.15rem; position: relative; }
/* La pastilla vive detrás de los enlaces (z-index 0 contra 1) y se coloca
   entera por `transform`, que se anima en la GPU y no obliga a recalcular
   layout mientras se desliza. */
.nav-marca {
  position: absolute; left: 0; top: 0; z-index: 0;
  border-radius: var(--r-full);
  background: var(--acento-suave);
  pointer-events: none;
  transition: transform var(--dur) var(--ease), width var(--dur) var(--ease),
              height var(--dur) var(--ease), opacity var(--dur-fast) var(--ease);
}
.nav-link {
  position: relative; z-index: 1;
  padding: 0.5em 0.9em; border-radius: var(--r-full);
  font-weight: 500; color: var(--ink); font-size: 0.9rem;
  transition: color var(--dur-fast) var(--ease);
  white-space: nowrap;
}
.nav-link:hover { color: var(--acento-fuerte); }
/* exact-active: solo la ruta exacta se resalta (evita que "Inicio" (/) se
   marque en todas las páginas por ser prefijo común).
   Sin fondo propio: el fondo lo pone la pastilla, que descansa justo aquí. */
.nav-link.router-link-exact-active { color: var(--azul); font-weight: 600; }
.nav-link:focus-visible { outline: 2px solid var(--acento); outline-offset: 2px; }

.nav-cta {
  position: relative; overflow: hidden;
  margin-left: 0.5rem; flex: none;
  padding: 0.6em 1.2em; white-space: nowrap; font-size: 0.9rem;
}
/* Destello que recorre el botón cada seis latidos. Es el mismo gesto que la
   luz de revelado de las tarjetas (ver [data-glow] en app.css), aquí en bucle
   y en blanco, porque el botón ya viene relleno de acento.
   El ciclo se cuenta en --latido y no en segundos sueltos para que comparta
   tempo con el corazón del pie y con la línea del hero: seis latidos son
   justo 5s, pero expresado así no se desalinea si el pulso del sitio cambia.
   Cruza en poco más de un latido y espera los otros cinco: sostenido sin
   pausa sería un brillo permanente, que en un botón fijo cansa la vista. */
.nav-cta::after {
  content: ''; position: absolute; inset: 0; pointer-events: none;
  background: linear-gradient(105deg,
    transparent 35%, rgba(255, 255, 255, 0.38) 50%, transparent 65%);
  transform: translateX(-120%);
  animation: cta-destello calc(var(--latido) * 6) cubic-bezier(0.45, 0.05, 0.55, 0.95) infinite;
}
@keyframes cta-destello {
  0%        { transform: translateX(-120%); }
  22%, 100% { transform: translateX(120%); }
}

.hamburguesa { display: none; flex-direction: column; gap: 5px; padding: 0.5rem; }
.hamburguesa span {
  width: 24px; height: 2.5px; background: var(--azul); border-radius: 2px;
  transition: transform var(--dur-fast) var(--ease), opacity var(--dur-fast) var(--ease);
}
/* Las tres barras se convierten en una X: el botón dice en qué estado está
   sin necesidad de un segundo icono. 7.5px es media barra (2.5) más el hueco
   (5), o sea justo lo que hay del centro de una al centro de la de al lado. */
.hamburguesa.activa span:nth-child(1) { transform: translateY(7.5px) rotate(45deg); }
.hamburguesa.activa span:nth-child(2) { opacity: 0; }
.hamburguesa.activa span:nth-child(3) { transform: translateY(-7.5px) rotate(-45deg); }

@media (max-width: 1040px) {
  .hamburguesa { display: flex; }
  .nav {
    position: absolute; top: 100%; left: 0; right: 0;
    flex-direction: column; align-items: stretch; gap: 0.2rem;
    background: var(--surface); padding: 1rem;
    border-bottom: 1px solid var(--line); box-shadow: var(--shadow);
    transform: translateY(-140%); opacity: 0; pointer-events: none;
    transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.3s;
  }
  .nav.open { transform: translateY(0); opacity: 1; pointer-events: auto; }
  /* Sin pastilla deslizante: en columna, el fondo vuelve a cada enlace. */
  .nav-marca { display: none; }
  .nav-link { padding: 0.8em 1em; text-align: left; }
  .nav-link:hover { background: var(--acento-suave); }
  .nav-link.router-link-exact-active { background: var(--azul-tint); }
  .nav-cta { margin: 0.4rem 0 0; text-align: center; }
  /* El logo no se encoge en móvil: ya es chico y el salto se notaba más que
     el espacio que devolvía. */
  .brand-logo, .header.scrolled .brand-logo { width: 52px; height: 52px; }
  .brand-nombre { font-size: 1.35rem; }
}

@media (prefers-reduced-motion: reduce) {
  .header-in, .brand-logo, .nav-marca, .nav-link, .hamburguesa span { transition: none; }
  /* El destello es movimiento permanente y sin control de quien mira: se va
     entero. La barra de avance se queda: solo se mueve si la persona
     scrollea, y es información, no adorno. */
  .nav-cta::after { animation: none; content: none; }
}
</style>
