<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from './stores/auth.js';
import { SITIO_URL, CLINICA } from './config.js';
import { urlFoto } from './api.js';
import AppShell from './components/AppShell.vue';
import BaseIcon from './components/BaseIcon.vue';
import ChatAsistente from './components/ChatAsistente.vue';
import AvisoPrivacidad from './components/AvisoPrivacidad.vue';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

onMounted(() => auth.fetchMe());

// Mismo gesto que el header de website: el topbar gana sombra/borde al
// bajar, en vez de tenerlo fijo — queda quieto mientras se lee arriba de todo.
const scrolled = ref(false);
function onScroll() { scrolled.value = window.scrollY > 20; }
onMounted(() => { window.addEventListener('scroll', onScroll, { passive: true }); onScroll(); });
onUnmounted(() => window.removeEventListener('scroll', onScroll));

// Tres envoltorios, según meta.layout del router (ver router.js):
//  - 'portal'  → AppShell con sidebar (portal del cliente, requiere sesión)
//  - 'publica' → sin envoltorio (login/registro/confirmar)
//  - 'reserva' (o cualquier otro) → topbar simple de acá abajo, usada
//    tanto por la reserva pública como por "Nueva cita" ya logueado
const esPortal = computed(() => route.meta.layout === 'portal');
const esPublica = computed(() => route.meta.layout === 'publica');
// Las pantallas de reserva llevan «Volver al sitio web» en su hero azul
// (ReservarView.vue); el resto del layout (/pago) lo sigue teniendo acá.
const conHero = computed(() => ['reservar', 'reservar-directo', 'reservar-especialista', 'nueva-cita'].includes(route.name));

// Un color por enlace, mismo criterio que las pestañas de la ficha
// (FichaView.vue): el ícono lo lleva siempre y el activo se rellena con él.
const ENLACES = [
  { to: '/inicio', icon: 'home', label: 'Mi portal', tono: 'var(--brand)' },
  { to: '/citas', icon: 'calendar', label: 'Mis citas', tono: 'var(--accent)' },
  { to: '/mascotas', icon: 'pets', label: 'Mis mascotas', tono: 'var(--rosa)' },
];

function salir() {
  auth.logout();
  router.push('/');
}
</script>

<template>
  <RouterView v-if="esPublica" />

  <AppShell v-else-if="esPortal">
    <RouterView />
  </AppShell>

  <!-- Solo en el layout de reserva: es donde hay alguien que todavía está
       decidiendo y puede tener una duda de precio o de qué se atiende. En el
       portal del cliente (`AppShell`) sobra —esa gente ya es cliente y tiene
       sus datos a la vista— y en login/registro estorbaría un trámite de dos
       campos. Va acá y no dentro de ReservarView para que también acompañe a
       /pago/:citaId, que es donde aparecen las dudas que cuestan plata. -->
  <div v-else class="shell">
    <header class="topbar" :class="{ scrolled }">
      <!-- El logo lleva al INICIO DE LA RESERVA, no al sitio de marketing.
           Estaba al revés: quien iba por la mitad del formulario y tocaba el
           logo —el gesto universal de «volver al principio»— salía del sistema
           y perdía lo que llevaba escrito. Para ir al sitio está el enlace de
           al lado, que lo dice con todas las letras. -->
      <RouterLink to="/" class="logo">
        <img src="/isotipo.webp" alt="" class="logo-img" />
        <span class="logo-text">Clínica Veterinaria <strong>{{ CLINICA.nombre }}</strong></span>
      </RouterLink>
      <!-- «Volver al sitio web» va DENTRO del mismo carril que los enlaces
           del portal, con su estilo, para que se lea como un bloque de
           navegación y no como dos cosas sueltas. Pero el carril se dibuja
           SIEMPRE, no solo con sesión: si dependiera de `isLoggedIn`, quien
           reserva sin cuenta —que es la mayoría— se quedaría sin salida al
           sitio. Los tres del portal siguen apareciendo solo con sesión. -->
      <nav class="nav" aria-label="Navegación">
        <div v-if="!conHero || auth.isLoggedIn" class="nav-portal">
          <a v-if="!conHero" :href="SITIO_URL" class="nav-sitio">
            <BaseIcon name="chevronLeft" :size="16" />
            <span>Volver al sitio web</span>
          </a>
          <template v-if="auth.isLoggedIn">
            <RouterLink v-for="e in ENLACES" :key="e.to" :to="e.to" class="nav-enlace" :style="{ '--tono': e.tono }">
              <BaseIcon :name="e.icon" :size="17" />{{ e.label }}
            </RouterLink>
          </template>
        </div>
      </nav>
      <div class="user">
        <template v-if="auth.isLoggedIn">
          <RouterLink to="/configuracion" class="user-name" title="Mi cuenta">
            <img v-if="auth.user?.foto" class="avatar avatar-foto" :src="urlFoto(auth.user.foto)" alt="" />
            <span v-else class="avatar">{{ auth.iniciales }}</span>
            <span class="user-nombre">{{ auth.user?.nombre }}</span>
          </RouterLink>
          <button class="btn btn-ghost btn-sm" @click="salir">Salir</button>
        </template>
        <RouterLink v-else to="/login" class="btn btn-primary btn-sm">
          <BaseIcon name="user" :size="16" />
          Ingresar
        </RouterLink>
      </div>
    </header>

    <main>
      <RouterView />
    </main>
      <ChatAsistente />
  </div>

  <!-- Fuera de la cadena de layouts para que acompañe al portal y a la reserva
       con sesión, y no a login/registro, donde la persona está haciendo un
       trámite de dos campos. No bloquea nada: ver AvisoPrivacidad.vue. -->
  <AvisoPrivacidad v-if="auth.user?.privacidad_pendiente && !esPublica" />
</template>

<style scoped>
.shell { min-height: 100dvh; display: flex; flex-direction: column; }
/* Grilla de tres columnas iguales a los lados: el carril de navegación queda
   en el centro de la página y no en el centro del hueco entre el logo y el
   usuario, que miden distinto. */
.topbar {
  position: sticky; top: 0; z-index: 100;
  display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 1.5rem;
  padding: 0.7rem clamp(1rem, 3vw, 2rem);
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(10px);
  transition: box-shadow 0.25s;
  isolation: isolate;
}
/* Misma textura de mascotas que el header de website, tenue como papel
   ilustrado — ver TheHeader.vue para el porqué de la opacidad. */
.topbar::before {
  content: ''; position: absolute; inset: 0; z-index: -1;
  background-image: url('/bg-patron.png');
  background-size: 190px 190px;
  opacity: 0.12;
  pointer-events: none;
}
/* La línea de abajo es una sombra interior y no un borde: no ocupa espacio,
   así que aparecer al hacer scroll no mueve la barra ni un píxel. */
.topbar.scrolled { box-shadow: 0 4px 24px rgba(38, 50, 110, 0.08), inset 0 -1px 0 var(--line); }
.logo { display: flex; align-items: center; gap: 0.7rem; justify-self: start; }
.logo-img { width: 42px; height: 42px; object-fit: contain; }
.logo-text { font-family: var(--font-display); font-weight: 500; font-size: var(--t-lg); color: var(--ink-soft); }
.logo-text strong { color: var(--brand); font-weight: 700; }
.nav { display: flex; align-items: center; gap: 1rem; min-width: 0; }
/* `margin-inline: auto` y no `justify-content: center`: si el carril no cabe
   (teléfono) se desliza desde su borde izquierdo en vez de quedar cortado. */
.nav-portal { margin-inline: auto; }

/* "Volver al sitio web" es una SALIDA del portal, distinta de navegar
   dentro de él — antes vivía en la misma fila que "Mi portal"/"Mis citas"
   con el mismo peso visual y las dos cosas se confundían. Ahora lleva su
   propio ícono y un trazo a la derecha que la separa del grupo. */
.nav-sitio {
  display: flex; align-items: center; gap: 0.35rem;
  padding: 0.45em 0.7em 0.45em 0.5em; border-radius: var(--r-full);
  font-weight: 600; font-size: var(--t-base); color: var(--brand);
  /* Separador del resto del carril, y un respiro a la derecha. */
  border-right: 1px solid var(--line);
  margin-right: 0.25rem;
  transition: background 0.2s var(--ease), transform 0.2s var(--ease);
}
.nav-sitio:hover { background: var(--brand-tint); }
.nav-sitio :deep(svg) { transition: transform 0.2s var(--ease); }
.nav-sitio:hover :deep(svg) { transform: translateX(-2px); }

/* Los tres links del portal van agrupados en su propio "carril" con fondo,
   para leerse como un solo bloque de navegación en vez de tres palabras
   sueltas del mismo color que el fondo. */
.nav-portal {
  display: flex; gap: 0.2rem;
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--r-full); padding: 0.25rem;
}
.nav-portal a {
  display: flex; align-items: center; gap: 0.4rem;
  padding: 0.4em 0.85em; border-radius: var(--r-full);
  font-weight: 600; font-size: var(--t-sm); color: var(--ink-soft);
  transition: background 0.2s var(--ease), color 0.2s var(--ease);
}
.nav-portal a :deep(svg) { color: var(--tono, currentColor); transition: color 0.2s var(--ease); }
.nav-portal a:hover { color: var(--tono, var(--accent-strong)); background: var(--bg); }
.nav-portal a.nav-sitio:hover { background: var(--brand-tint); }
.nav-portal a.router-link-exact-active { background: var(--tono, var(--brand)); color: #fff; box-shadow: 0 3px 10px rgba(38, 50, 110, 0.22); }
.nav-portal a.router-link-exact-active :deep(svg) { color: #fff; stroke: #fff; }
/* Los tres del portal arrancan en azul de marca (11.9 con texto blanco) para
   que se lean como botones; el hover es el de siempre: el color de cada uno
   sobre el fondo claro. */
.nav-portal a.nav-enlace { background: var(--brand); color: #fff; }
.nav-portal a.nav-enlace :deep(svg) { color: #fff; }
.nav-portal a.nav-enlace:hover { color: var(--tono); background: var(--bg); }
.nav-portal a.nav-enlace:hover :deep(svg) { color: var(--tono); }
.nav-portal a.nav-enlace.router-link-exact-active { background: var(--tono); color: #fff; }
.user { display: flex; align-items: center; gap: 0.8rem; justify-self: end; }
.user-name {
  display: flex; align-items: center; gap: 0.55rem;
  font-size: var(--t-lg); font-weight: 700; color: var(--ink);
  padding: 0.2rem 0.6rem 0.2rem 0.2rem; border-radius: var(--r-full);
  transition: background 0.2s var(--ease);
}
.user-name:hover { background: var(--brand-tint); }
/* Mismo avatar que el portal (AppShell.vue): la foto si la subió, si no sus
   iniciales. Sin sesión queda el ícono del botón «Ingresar». */
.avatar {
  width: 36px; height: 36px; border-radius: 50%; flex: none;
  background: linear-gradient(135deg, var(--brand) 0%, #0a5652 100%);
  color: #fff; font-weight: 700; font-size: 0.82rem;
  display: grid; place-items: center;
  box-shadow: 0 2px 8px rgba(38, 50, 110, 0.2);
}
.avatar-foto { object-fit: cover; background: var(--brand-tint); }
main { flex: 1; }

@media (max-width: 640px) {
  /* En el teléfono vuelve a la fila: logo y usuario arriba, el carril abajo. */
  .topbar { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 0.6rem; }
  .nav { order: 3; flex: 0 0 100%; overflow-x: auto; scrollbar-width: none; }
  .nav::-webkit-scrollbar { display: none; }
  /* Con el relleno de escritorio los tres no cabían en 390 px y el carril
     quedaba con una barra de desplazamiento debajo; así entran enteros y
     centrados. Si un teléfono más angosto no alcanza, se desliza con el dedo. */
  .nav-portal a { padding: 0.4em 0.6em; gap: 0.3rem; }
  .nav-sitio, .nav-portal { flex-shrink: 0; }
  .user-nombre { display: none; }
}
</style>
