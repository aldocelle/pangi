<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from './stores/auth.js';
import BuscadorGlobal from './components/BuscadorGlobal.vue';
import { api } from './api.js';
import { CLINICA } from './marca.js';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const menuAbierto = ref(false);

// URL del sitio público. En producción se define con VITE_SITIO_URL al compilar;
// sin ella, "Ver sitio" abría el localhost de quien usara el panel.
const SITIO_URL = import.meta.env.VITE_SITIO_URL || 'http://localhost:3100';

// En el kiosco de fichaje (tablet compartido, sin sesión de staff) no se pide
// /auth/me: un token vencido de alguien que entró antes al panel daría 401 y
// el interceptor mandaría el tablet a /login.
onMounted(() => { if (!route.meta.kiosco) auth.fetchMe(); });

/* Algo caído (WhatsApp, Google, IA): punto rojo en «Config» y el botón lleva
   directo a Estado del sistema. Solo admin, y sin sondear: lee lo último que
   revisó el cron, así que es una consulta liviana. Sin esto, la pantalla de
   estado existe pero nadie entra a mirarla justo el día que hace falta. */
const algoCaido = ref(false);
async function revisarEstado() {
  if (auth.user?.rol !== 'admin') { algoCaido.value = false; return; }
  try {
    const { data } = await api.get('/estado-sistema');
    algoCaido.value = data.peor === 'error';
  } catch { /* un aviso que falla no puede molestar al resto del panel */ }
}
watch(() => auth.user?.rol, revisarEstado, { immediate: true });
// Al volver de Estado del sistema (tras «Revisar ahora»), y cada 10 minutos.
watch(() => route.name, (n, antes) => { if (antes === 'ajustes') revisarEstado(); });
setInterval(revisarEstado, 10 * 60 * 1000);
const destinoConfig = computed(() => (algoCaido.value ? '/ajustes/estado' : '/ajustes'));

/* Con sesión, `/login` no tiene nada que mostrar. El caso real (1-oct-2026):
   al entrar con Google, guardar el token cambia este layout al del menú, eso
   DESMONTA la pantalla de login y su botón de Google, y el `emit('listo')` que
   iba a llevar al inicio lo descarta Vue (no emite desde un componente
   desmontado). Quedaba el menú a la izquierda y el login otra vez en el
   centro, ya logueado. Esto vive acá porque App no se desmonta nunca. */
watch(
  () => auth.isLoggedIn && !!auth.user && route.name === 'login',
  (sobra) => { if (sobra) router.replace({ name: 'reservas' }); },
  { immediate: true },
);

function salir() {
  auth.logout();
  router.push('/login');
}

// Navegación agrupada por secciones. `area` es la clave de permiso granular
// (ver PERMISO_AREAS en backend/app/routes/auth.py y stores/auth.js);
// `adminOnly` es para lo que nunca pasa por el sistema de permisos —
// gestión del equipo y ajustes generales son atribución exclusiva de admin.
const grupos = [
  {
    titulo: 'Operación',
    tono: 'var(--side-operacion)',
    items: [
      { to: '/agenda', label: 'Agenda', icon: 'agenda', area: 'agenda' },
      { to: '/tareas', label: 'Tareas', icon: 'tareas', area: 'tareas' },
      { to: '/recordatorios', label: 'Recordatorios', icon: 'recordatorios', area: 'recordatorios' },
      { to: '/mensajes', label: 'Mensajes', icon: 'mensajes', area: 'recordatorios' },
      { to: '/', label: 'Reservas', icon: 'calendar', area: 'reservas' },
      // Junto a lo que llega de fuera y hay que atender, no en Configuración.
      { to: '/consultas-web', label: 'Consultas desde el sitio web', icon: 'agenda', area: 'consultas_web' },
      // Sin `area`: lo ve TODO el staff, porque marcar la entrada propia no
      // exige permiso. La mitad que sí lo exige —las horas del equipo— se
      // esconde dentro de la pantalla.
      { to: '/asistencia', label: 'Asistencia', icon: 'equipo' },
    ],
  },
  {
    titulo: 'Personas',
    tono: 'var(--side-personas)',
    items: [
      { to: '/clientes', label: 'Tutores', icon: 'clientes', area: 'personas' },
      { to: '/pacientes', label: 'Pacientes', icon: 'pets', area: 'personas' },
    ],
  },
  {
    titulo: 'Ventas',
    tono: 'var(--side-ventas)',
    items: [
      // Primero Caja: es la pantalla del día a día del mostrador, y las
      // otras dos se consultan desde ella.
      { to: '/caja', label: 'Caja', icon: 'caja', area: 'caja' },
      { to: '/ventas', label: 'Ventas', icon: 'ventas', area: 'ventas' },
      { to: '/inventario', label: 'Inventario', icon: 'inventario', area: 'inventario' },
      { to: '/proveedores', label: 'Proveedores', icon: 'proveedores', area: 'proveedores' },
      { to: '/pedidos', label: 'Pedidos', icon: 'pedidos', area: 'compras' },
      { to: '/estadisticas', label: 'Estadísticas', icon: 'estadisticas', area: 'estadisticas' },
    ],
  },
  /* El grupo «Configuración» ya no está: sus seis pantallas se reunieron con
     las cinco pestañas del modal de la tuerca dentro de /ajustes. La
     configuración estaba partida en dos lugares y había que recordar de qué
     lado quedaba cada cosa; ahora el botón Ajustes lleva a todas. */
  /* Sysop: solo quien figura en SYSOP_EMAILS del backend (`es_sysop` en
     /auth/me). No es un área de permiso ni basta con ser admin: lo que hay
     ahí (importar datos, soltar Google) no es para los admins de la clínica. */
  {
    titulo: 'Sistema',
    tono: 'var(--ink-soft)',
    items: [
      { to: '/sysop', label: 'Sysop', icon: 'sysop', sysopOnly: true },
    ],
  },
];

function puedeVer(item) {
  if (item.sysopOnly) return auth.esSysop;
  if (item.adminOnly) return auth.user?.rol === 'admin';
  // Un ítem SIN área es visible para todo el staff, a propósito. Lo usa
  // Asistencia: marcar la entrada propia no exige permiso, y pasarlo por
  // `auth.puede(undefined)` daría false a cualquier veterinario con lista
  // propia — o sea que el permiso de ver las horas AJENAS decidiría si
  // alguien puede fichar las suyas, que es al revés de lo que corresponde.
  if (!item.area) return true;
  return auth.puede(item.area);
}

// Sidebar real: cada grupo filtrado a lo que el usuario puede ver, y el
// grupo entero desaparece si queda vacío (nadie quiere un título de
// sección seguido de nada).
const gruposVisibles = computed(() =>
  grupos
    .map((g) => ({ ...g, items: g.items.filter(puedeVer) }))
    .filter((g) => g.items.length > 0)
);

// Íconos SVG lineales (24x24, stroke).
const ICONS = {
  calendar: 'M8 3v3m8-3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z',
  agenda: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9h.01M12 14h4m-8 4h.01M12 18h4',
  pets: 'M4.5 12.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm5-4a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm5 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm5 4a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM12 13c-2.5 0-5 2-5 4.5C7 19.5 9 21 12 21s5-1.5 5-3.5c0-2.5-2.5-4.5-5-4.5Z',
  clientes: 'M17 20v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm14 10v-2a4 4 0 0 0-3-3.87M16 2.13a4 4 0 0 1 0 7.75',
  equipo: 'M20 21a8 8 0 1 0-16 0M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
  consultas: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M12 11v6m-3-3h6',
  especialistas: 'M8 3.5V2m8 1.5V2M6 8h12M8 8v2a4 4 0 0 0 8 0V8M12 14v3a4 4 0 0 0 4 4 3 3 0 0 0 3-3v-2M19 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z',
  campos: 'M4 6h16M4 12h10M4 18h7',
  inventario: 'M3 7l9-4 9 4-9 4-9-4Zm0 0v10l9 4 9-4V7M3 7l9 4 9-4M12 11v10',
  proveedores: 'M3 9h18M3 9v10a1 1 0 0 0 1 1h5v-6h6v6h5a1 1 0 0 0 1-1V9M3 9l2.5-5h13L21 9',
  pedidos: 'M3 7h11v8H3zM14 10h4l3 3v2h-7z M7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z',
  estadisticas: 'M4 20h16M6 20V10m6 10V4m6 16v-7',
  tareas: 'M9 11l3 3L22 4M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4M12 3H7a2 2 0 0 0-2 2v3',
  recordatorios: 'M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0',
  mensajes: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
  ventas: 'M3 3h2l.4 2M7 13h10l3-8H5.4M7 13 5.4 5M7 13l-2.3 4.6A1 1 0 0 0 5.6 19H17M9 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z',
  caja: 'M3 8.5 12 4l9 4.5M3 8.5V18a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V8.5M3 8.5h18M9 13h6',
  auditoria: 'M9 12h6m-6 4h6M9 8h1M5 21V5a2 2 0 0 1 2-2h7l5 5v13a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2Z',
  gear: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7.4-3a7.4 7.4 0 0 0-.1-1.3l2-1.6-2-3.4-2.4 1a7.3 7.3 0 0 0-2.2-1.3L14.3 2h-4l-.4 2.6a7.3 7.3 0 0 0-2.2 1.3l-2.4-1-2 3.4 2 1.6a7.4 7.4 0 0 0 0 2.6l-2 1.6 2 3.4 2.4-1a7.3 7.3 0 0 0 2.2 1.3l.4 2.6h4l.4-2.6a7.3 7.3 0 0 0 2.2-1.3l2.4 1 2-3.4-2-1.6c.06-.43.1-.86.1-1.3Z',
  eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Zm10 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
  logout: 'M15 12H3m0 0 4-4m-4 4 4 4M11 3h7a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1h-7',
  menu: 'M4 6h16M4 12h16M4 18h16',
  sysop: 'M4 5h16v10H4zM8 19h8M12 15v4M7.5 8.5 9.5 10l-2 1.5M11.5 11.5h4',
};

const tituloActual = computed(() => {
  for (const g of grupos) {
    const it = g.items.find((i) => i.to === route.path);
    if (it) return it.label;
  }
  // Ajustes ya no está en ningún grupo del sidebar: se llega por el botón de
  // la tuerca, y la barra igual tiene que decir dónde se está parado.
  if (route.name === 'ajustes') return 'Config';
  return '';
});

function iniciales(nombre) {
  const p = (nombre || 'V').split(' ').filter(Boolean);
  return ((p[0]?.[0] || '') + (p[1]?.[0] || '')).toUpperCase();
}
</script>

<template>
  <!-- Kiosco de fichaje: pantalla completa, sin layout del panel -->
  <RouterView v-if="route.meta.kiosco" />

  <div v-else-if="!auth.isLoggedIn">
    <RouterView />
  </div>

  <div v-else class="layout">
    <!-- Sidebar -->
    <aside class="side" :class="{ open: menuAbierto }">
      <div class="side-brand">
        <img src="/isotipo.webp" alt="" class="side-logo" />
        <strong>{{ CLINICA }}</strong>
        <small>Panel de administración</small>
      </div>

      <div class="side-tools">
        <a :href="SITIO_URL" target="_blank" rel="noopener" class="side-tool">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONS.eye" /></svg>
          Ver sitio
        </a>
        <RouterLink :to="destinoConfig" class="side-tool" @click="menuAbierto = false">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONS.gear" /></svg>
          Config
          <span v-if="algoCaido" class="cfg-alerta" title="Hay algo caído: mira Estado del sistema">
            <span class="sr-only">(hay algo caído)</span>
          </span>
        </RouterLink>
      </div>

      <nav class="side-nav" aria-label="Navegación principal">
        <div v-for="g in gruposVisibles" :key="g.titulo" class="side-group" :style="{ '--tono': g.tono }">
          <span class="side-group-title">{{ g.titulo }}</span>
          <RouterLink
            v-for="n in g.items" :key="n.to" :to="n.to" class="side-link"
            @click="menuAbierto = false"
          >
            <svg class="side-ic" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONS[n.icon]" /></svg>
            <span>{{ n.label }}</span>
          </RouterLink>
        </div>
      </nav>

      <div class="side-foot">
        <!-- El bloque del usuario lleva a su propia cuenta: es donde se
             espera encontrar el cambio de contraseña. -->
        <RouterLink to="/cuenta" class="side-yo" title="Mi cuenta">
          <span class="side-avatar">{{ iniciales(auth.user?.nombre) }}</span>
          <div class="side-user">
            <strong>{{ auth.user?.nombre || 'Usuario' }}</strong>
            <small>{{ auth.user?.rol === 'admin' ? 'Administrador' : 'Veterinario' }}</small>
          </div>
        </RouterLink>
        <button class="side-out" @click="salir" aria-label="Cerrar sesión" title="Cerrar sesión">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONS.logout" /></svg>
        </button>
      </div>
    </aside>

    <div v-if="menuAbierto" class="backdrop" @click="menuAbierto = false"></div>

    <!-- Contenido -->
    <div class="main-wrap">
      <header class="topbar">
        <button class="hamburger" @click="menuAbierto = true" aria-label="Abrir menú">
          <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONS.menu" /></svg>
        </button>
        <h1 class="topbar-title">{{ tituloActual }}</h1>
        <BuscadorGlobal />
        <div class="topbar-actions">
          <a :href="SITIO_URL" target="_blank" rel="noopener" class="topbar-btn">Ver sitio ↗</a>
          <RouterLink :to="destinoConfig" class="topbar-btn" :class="{ caido: algoCaido }"
            :title="algoCaido ? 'Hay algo caído: mira Estado del sistema' : undefined">
            Config<span v-if="algoCaido" class="cfg-alerta"><span class="sr-only">(hay algo caído)</span></span>
          </RouterLink>
        </div>
      </header>

      <main class="main">
        <RouterView />
      </main>
    </div>

  </div>
</template>

<style scoped>
.layout { display: grid; grid-template-columns: 264px 1fr; min-height: 100dvh; }

/* ── Sidebar ── */
/* Mismo ancho de columna (264px, ver .layout): achicarlo también habría hecho
   envolver etiquetas que ya rozan el límite («Consultas desde el sitio web»).
   Lo compacto viene del padding y los tamaños internos, no del ancho. */
.side {
  background: linear-gradient(180deg, var(--side-bg) 0%, var(--side-bg-2) 100%);
  color: var(--side-ink);
  display: flex; flex-direction: column;
  padding: 1rem 0.7rem;
  position: sticky; top: 0; height: 100dvh;
}
.side-brand {
  display: flex; flex-direction: column; align-items: center; text-align: center;
  gap: 0.4rem; padding: 0.2rem 0.5rem 0.9rem; margin-bottom: 0.2rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}
.side-logo { width: 46px; height: 46px; object-fit: contain; background: #fff; border-radius: 12px; padding: 4px; }
.side-brand strong { color: #fff; font-size: var(--t-base); font-weight: 700; line-height: 1.25; }
.side-brand small {
  font-size: var(--t-micro); font-weight: 600; color: var(--accent-sobre-oscuro);
  text-transform: uppercase; letter-spacing: 0.06em;
}

.side-tools { display: flex; gap: 0.35rem; padding: 0 0.2rem 0.7rem; }
.side-tool {
  display: inline-flex; align-items: center; gap: 0.3rem;
  font-size: var(--t-micro); font-weight: 600; color: var(--side-ink);
  padding: 0.38em 0.65em; border-radius: var(--r-full);
  border: 1px solid rgba(255, 255, 255, 0.14);
  transition: background 0.18s, border-color 0.18s, color 0.18s;
}
.side-tool:hover { background: rgba(255, 255, 255, 0.08); border-color: transparent; color: #fff; }

.side-nav { display: flex; flex-direction: column; gap: 0.8rem; flex: 1; overflow-y: auto; }
.side-group { display: flex; flex-direction: column; gap: 0.1rem; }
.side-group-title {
  font-size: var(--t-micro); font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em;
  /* El tono del grupo, con respaldo al gris de antes si algún grupo no lo trae. */
  color: var(--tono, var(--side-ink-soft)); padding: 0 0.7rem 0.3rem;
}
.side-link {
  display: flex; align-items: center; gap: 0.65rem;
  padding: 0.5rem 0.7rem; border-radius: var(--r-md);
  font-size: var(--t-base); font-weight: 500; color: var(--side-ink);
  transition: background 0.18s, color 0.18s;
}
/* El hover se tiñe del tono del grupo en vez del blanco plano de antes: al
   recorrer el menú, el color confirma en qué sección estás antes de soltar. */
.side-link:hover { background: color-mix(in oklab, var(--tono, #fff) 16%, transparent); color: #fff; }
.side-link:hover .side-ic { opacity: 1; }

/* El activo NO se tiñe del grupo: se queda con el turquesa de siempre. Es la
   respuesta a "dónde estoy", y tiene que ganarle a cualquier otra señal de
   color de la barra. Por eso ningún tono de grupo se le parece. */
.side-link.router-link-exact-active {
  background: var(--accent); color: #fff; font-weight: 600;
  box-shadow: 0 6px 16px rgba(var(--accent-rgb), 0.3);
}
.side-link.router-link-exact-active .side-ic { color: #fff; opacity: 1; }

/* El ícono lleva el tono; la etiqueta se queda en el gris legible de siempre.
   Teñir también el texto habría bajado el contraste de 16 etiquetas para
   ganar una señal que el ícono ya da. */
.side-ic { flex: none; color: var(--tono, var(--side-ink-soft)); opacity: 0.82; transition: opacity 0.18s; }

@media (prefers-reduced-motion: reduce) {
  .side-link, .side-ic { transition: none; }
}

.side-foot {
  display: flex; align-items: center; gap: 0.5rem;
  padding: 0.7rem 0.5rem 0.1rem; margin-top: 0.4rem;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
}
.side-avatar {
  width: 32px; height: 32px; border-radius: 50%; flex: none;
  background: var(--brand); color: #fff; font-weight: 700; font-size: var(--t-micro);
  display: grid; place-items: center;
}
/* Envuelve avatar y nombre en un solo destino clicable, y deja el botón de
   salir fuera para que no se dispare al querer abrir la cuenta.
   min-width: 0 para que el nombre largo siga cortándose con puntos. */
.side-yo {
  flex: 1; min-width: 0;
  display: flex; align-items: center; gap: 0.6rem;
  padding: 0.3rem; margin: -0.3rem; border-radius: var(--r-sm);
  transition: background 0.15s ease;
}
.side-yo:hover, .side-yo:focus-visible { background: rgba(255, 255, 255, 0.08); }
.side-yo:focus-visible { outline: 2px solid var(--brand); outline-offset: 1px; }
.side-user { flex: 1; min-width: 0; display: flex; flex-direction: column; line-height: 1.2; }
.side-user strong { color: #fff; font-size: var(--t-sm); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.side-user small { color: var(--side-ink-soft); font-size: var(--t-micro); }
.side-out {
  flex: none; width: 30px; height: 30px; border-radius: var(--r-sm);
  display: grid; place-items: center; color: var(--side-ink-soft);
  transition: background 0.18s, color 0.18s;
}
.side-out:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }

/* ── Contenido ── */
.main-wrap { min-width: 0; display: flex; flex-direction: column; }
.topbar {
  position: sticky; top: 0; z-index: 20;
  display: flex; align-items: center; gap: 0.9rem;
  padding: 0.65rem clamp(1rem, 3vw, 1.6rem);
  background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(10px);
  border-bottom: 1px solid var(--line);
}
.hamburger { display: none; color: var(--brand); }
.topbar-title { font-size: var(--t-lg); color: var(--brand); font-weight: 700; }
.topbar-actions { margin-left: auto; display: flex; gap: 0.45rem; }
.topbar-btn {
  font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft);
  padding: 0.4em 0.8em; border-radius: var(--r-full);
  border: 1.5px solid var(--line-strong); background: var(--surface);
  transition: border-color 0.2s, color 0.2s, background 0.2s;
}
.topbar-btn:hover { border-color: var(--accent); color: var(--accent-strong); background: var(--accent-tint); }
/* Algo caído: borde y punto rojos. El texto del title y el sr-only dicen lo
   mismo, así que el color no es la única señal. */
.topbar-btn.caido { border-color: var(--danger); color: var(--danger); }
.cfg-alerta {
  display: inline-block; width: 0.55rem; height: 0.55rem; margin-left: 0.4rem;
  border-radius: 50%; background: var(--danger); vertical-align: middle;
}
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

/* clamp más chico: 2rem de aire a cada lado era mucho para pantallas que ya
   vienen con poco espacio, y era el padding que más se notaba al comparar
   con el resto del panel, ahora más denso. */
.main { min-width: 0; padding: clamp(0.9rem, 2vw, 1.4rem); flex: 1; }

.backdrop { display: none; }

@media (max-width: 900px) {
  .layout { grid-template-columns: 1fr; }
  .side {
    position: fixed; z-index: 60; left: 0; top: 0; width: 264px;
    transform: translateX(-100%); transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
  }
  .side.open { transform: translateX(0); }
  .hamburger { display: grid; place-items: center; }
  .backdrop { display: block; position: fixed; inset: 0; background: rgba(16, 26, 61, 0.5); z-index: 50; }
  .topbar-actions { display: none; }
}
</style>
