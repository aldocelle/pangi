<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth.js';
import BuscadorGlobal from './BuscadorGlobal.vue';
import { CLINICA } from '../marca.js';
import { ICONOS } from '../iconos.js';
import { SECCIONES_AJUSTES, puedeVerSeccion } from '../secciones-ajustes.js';

/* Navegación del panel en una barra superior con menús desplegables (oct-2026).
   Reemplaza al menú lateral: la Agenda en columnas por profesional necesita
   todo el ancho de la pantalla, y 264 px de menú fijo se los comían. Bajo los
   1300 px los mismos menús pasan a un cajón lateral. */
const props = defineProps({
  algoCaido: { type: Boolean, default: false },
  destinoConfig: { type: String, default: '/ajustes' },
});
const emit = defineEmits(['salir']);

const BASE = import.meta.env.BASE_URL;
const SITIO_URL = import.meta.env.VITE_SITIO_URL || 'http://localhost:3100';
const auth = useAuthStore();
const route = useRoute();

/* `area` es la clave de permiso granular (stores/auth.js); un ítem sin área lo
   ve todo el equipo (Asistencia: fichar lo propio no exige permiso). `rutas`
   son otras pantallas que cuentan como «estar» en ese ítem, para que el menú
   siga marcado dentro de una ficha. */
const MENUS = [
  { id: 'agenda', label: 'Agenda', to: '/agenda', area: 'agenda' },
  {
    id: 'reservas', label: 'Reservas', items: [
      { to: '/', label: 'Reservas', icon: 'calendar', area: 'reservas' },
      { to: '/consultas-web', label: 'Consultas del sitio web', icon: 'mensajes', area: 'consultas_web' },
    ],
  },
  {
    id: 'clientes', label: 'Clientes', items: [
      { to: '/clientes', label: 'Tutores', icon: 'clientes', area: 'personas' },
      { to: '/pacientes', label: 'Pacientes', icon: 'pets', area: 'personas', rutas: ['ficha'] },
    ],
  },
  {
    id: 'ventas', label: 'Ventas', items: [
      // Primero Caja: es la pantalla del día a día del mostrador.
      { to: '/caja', label: 'Caja', icon: 'caja', area: 'caja' },
      { to: '/ventas', label: 'Ventas', icon: 'ventas', area: 'ventas' },
      { to: '/estadisticas', label: 'Estadísticas', icon: 'estadisticas', area: 'estadisticas' },
    ],
  },
  {
    id: 'inventario', label: 'Inventario', items: [
      { to: '/inventario', label: 'Inventario', icon: 'inventario', area: 'inventario' },
      { to: '/proveedores', label: 'Proveedores', icon: 'proveedores', area: 'proveedores' },
      { to: '/pedidos', label: 'Pedidos', icon: 'pedidos', area: 'compras' },
    ],
  },
  {
    id: 'recordatorios', label: 'Recordatorios', items: [
      { to: '/recordatorios', label: 'Recordatorios', icon: 'recordatorios', area: 'recordatorios' },
      { to: '/mensajes', label: 'Mensajes enviados', icon: 'mensajes', area: 'recordatorios' },
    ],
  },
  {
    id: 'equipo', label: 'Equipo', items: [
      { to: '/tareas', label: 'Tareas', icon: 'tareas', area: 'tareas' },
      { to: '/asistencia', label: 'Asistencia', icon: 'equipo' },
    ],
  },
  { id: 'admin', label: 'Administración', admin: true },
];

function puedeVer(item) {
  if (item.sysopOnly) return auth.esSysop;
  if (!item.area) return true;
  return auth.puede(item.area);
}

/* Administración = las secciones de Ajustes, agrupadas igual que en esa
   pantalla, más Sysop para quien figura en SYSOP_EMAILS. */
const gruposAdmin = computed(() => {
  const grupos = [];
  for (const s of SECCIONES_AJUSTES) {
    if (!puedeVerSeccion(auth, s)) continue;
    let g = grupos.find((x) => x.titulo === s.grupo);
    if (!g) grupos.push((g = { titulo: s.grupo, items: [] }));
    g.items.push({ to: `/ajustes/${s.id}`, label: s.label });
  }
  if (auth.esSysop) grupos.push({ titulo: 'Sistema', items: [{ to: '/sysop', label: 'Sysop' }] });
  return grupos;
});

const menus = computed(() => MENUS
  .map((m) => (m.items ? { ...m, items: m.items.filter(puedeVer) } : m))
  .filter((m) => (m.admin ? gruposAdmin.value.length : m.items ? m.items.length : puedeVer(m))));

function esActivo(m) {
  if (m.admin) return ['ajustes', 'especialista-detalle', 'sysop'].includes(route.name);
  if (m.to) return route.path === m.to;
  return m.items.some((i) => route.path === i.to || i.rutas?.includes(route.name));
}

/* ── Desplegables ──
   Patrón de «divulgación» (botón con aria-expanded + lista de enlaces), no
   role=menu: son enlaces de navegación. Se cierran con Escape, al hacer clic
   afuera y al cambiar de pantalla. Las flechas recorren los enlaces. */
const abierto = ref(null);
const cajon = ref(false);
const raiz = ref(null);

function alternar(id) {
  abierto.value = abierto.value === id ? null : id;
}
async function abrirYEnfocar(id) {
  abierto.value = id;
  await nextTick();
  raiz.value?.querySelector(`#panel-${id} a, #panel-${id} button`)?.focus();
}
function moverFoco(e) {
  const enlaces = [...e.currentTarget.querySelectorAll('a, button')];
  const i = enlaces.indexOf(document.activeElement);
  const siguiente = { ArrowDown: i + 1, ArrowUp: i - 1, Home: 0, End: enlaces.length - 1 }[e.key];
  if (siguiente === undefined) return;
  e.preventDefault();
  enlaces[(siguiente + enlaces.length) % enlaces.length]?.focus();
}
function alPresionarFuera(e) {
  if (abierto.value && !raiz.value?.contains(e.target)) abierto.value = null;
}
function alTeclear(e) {
  if (e.key !== 'Escape') return;
  if (abierto.value) {
    const id = abierto.value;
    abierto.value = null;
    document.getElementById(`menu-btn-${id}`)?.focus();
  } else if (cajon.value) {
    cajon.value = false;
  }
}
onMounted(() => {
  document.addEventListener('pointerdown', alPresionarFuera);
  document.addEventListener('keydown', alTeclear);
});
onUnmounted(() => {
  document.removeEventListener('pointerdown', alPresionarFuera);
  document.removeEventListener('keydown', alTeclear);
});
watch(() => route.fullPath, () => { abierto.value = null; cajon.value = false; });

/* En el cajón, cada sección es una pestaña desplegable y solo una queda
   abierta: con todo a la vista eran 30 enlaces seguidos y había que recorrer
   la lista para encontrar el propio. Al abrir el cajón se despliega la sección
   de la pantalla en que se está. */
const grupoCajon = ref(null);
watch(cajon, (v) => { if (v) grupoCajon.value = menus.value.find((m) => !m.to && esActivo(m))?.id || null; });
function alternarGrupo(id) {
  grupoCajon.value = grupoCajon.value === id ? null : id;
}

function iniciales(nombre) {
  const p = (nombre || 'V').split(' ').filter(Boolean);
  return ((p[0]?.[0] || '') + (p[1]?.[0] || '')).toUpperCase();
}
const rolVisible = computed(() => (auth.user?.rol === 'admin' ? 'Administrador' : 'Equipo clínico'));
</script>

<template>
  <header ref="raiz" class="barra">
    <button class="barra-ic hamburguesa" aria-label="Abrir el menú" @click="cajon = true">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path :d="ICONOS.menu" /></svg>
    </button>

    <RouterLink to="/agenda" class="marca" :aria-label="`${CLINICA}, ir a la Agenda`">
      <img :src="`${BASE}isotipo.webp`" alt="" class="marca-logo" />
      <span>{{ CLINICA }}</span>
    </RouterLink>

    <nav class="menus" aria-label="Navegación principal">
      <template v-for="m in menus" :key="m.id">
        <RouterLink v-if="m.to" :to="m.to" class="menu-btn" :class="{ activo: esActivo(m) }">{{ m.label }}</RouterLink>

        <div v-else class="menu" :class="{ 'menu-der': m.admin }">
          <button
            :id="`menu-btn-${m.id}`" type="button" class="menu-btn"
            :class="{ activo: esActivo(m), abierto: abierto === m.id }"
            :aria-expanded="abierto === m.id" :aria-controls="`panel-${m.id}`"
            @click="alternar(m.id)" @keydown.down.prevent="abrirYEnfocar(m.id)"
          >
            {{ m.label }}
            <svg class="chev" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONOS.chevron" /></svg>
          </button>

          <div v-if="abierto === m.id && m.admin" :id="`panel-${m.id}`" class="panel panel-admin" @keydown="moverFoco">
            <section v-for="g in gruposAdmin" :key="g.titulo" class="panel-grupo">
              <h2 class="panel-titulo">{{ g.titulo }}</h2>
              <RouterLink v-for="i in g.items" :key="i.to" :to="i.to" class="panel-link">{{ i.label }}</RouterLink>
            </section>
          </div>
          <div v-else-if="abierto === m.id" :id="`panel-${m.id}`" class="panel" @keydown="moverFoco">
            <RouterLink v-for="i in m.items" :key="i.to" :to="i.to" class="panel-link con-ic"
              :class="{ actual: route.path === i.to || i.rutas?.includes(route.name) }">
              <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONOS[i.icon]" /></svg>
              {{ i.label }}
            </RouterLink>
          </div>
        </div>
      </template>
    </nav>

    <div class="barra-der">
      <BuscadorGlobal class="buscador" />

      <RouterLink :to="destinoConfig" class="barra-ic" :class="{ caido: algoCaido }"
        :aria-label="algoCaido ? 'Configuración (hay algo caído)' : 'Configuración'"
        :title="algoCaido ? 'Hay algo caído: mira Estado del sistema' : 'Configuración'">
        <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONOS.gear" /></svg>
        <span v-if="algoCaido" class="punto-alerta"></span>
      </RouterLink>

      <div class="menu menu-der">
        <button id="menu-btn-usuario" type="button" class="avatar" :aria-expanded="abierto === 'usuario'"
          aria-controls="panel-usuario" :aria-label="`Cuenta de ${auth.user?.nombre || 'usuario'}`"
          @click="alternar('usuario')" @keydown.down.prevent="abrirYEnfocar('usuario')">
          {{ iniciales(auth.user?.nombre) }}
        </button>
        <div v-if="abierto === 'usuario'" id="panel-usuario" class="panel panel-usuario" @keydown="moverFoco">
          <p class="yo"><strong>{{ auth.user?.nombre || 'Usuario' }}</strong><span>{{ rolVisible }}</span></p>
          <RouterLink to="/cuenta" class="panel-link">Mi cuenta</RouterLink>
          <a :href="SITIO_URL" target="_blank" rel="noopener" class="panel-link">Ver el sitio público ↗</a>
          <button type="button" class="panel-link salir" @click="emit('salir')">Cerrar sesión</button>
        </div>
      </div>
    </div>
  </header>

  <!-- Cajón (pantallas angostas): los mismos menús, como pestañas desplegables. -->
  <div v-if="cajon" class="cajon-fondo" @click="cajon = false"></div>
  <aside class="cajon" :class="{ abierto: cajon }" :aria-hidden="!cajon" aria-label="Menú">
    <div class="cajon-cab">
      <span class="marca"><img :src="`${BASE}isotipo.webp`" alt="" class="marca-logo" /><span>{{ CLINICA }}</span></span>
      <button class="barra-ic" aria-label="Cerrar el menú" @click="cajon = false">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path :d="ICONOS.cerrar" /></svg>
      </button>
    </div>
    <nav class="cajon-nav" aria-label="Navegación principal">
      <template v-for="m in menus" :key="m.id">
        <RouterLink v-if="m.to" :to="m.to" class="cajon-link solo">{{ m.label }}</RouterLink>
        <section v-else class="cajon-grupo" :class="{ abierto: grupoCajon === m.id }">
          <button
            type="button" class="cajon-pestana" :class="{ actual: esActivo(m) }"
            :aria-expanded="grupoCajon === m.id" :aria-controls="`cajon-panel-${m.id}`"
            @click="alternarGrupo(m.id)"
          >
            {{ m.label }}
            <svg class="chev" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONOS.chevron" /></svg>
          </button>
          <div v-show="grupoCajon === m.id" :id="`cajon-panel-${m.id}`" class="cajon-items">
            <template v-if="!m.admin">
              <RouterLink v-for="i in m.items" :key="i.to" :to="i.to" class="cajon-link">{{ i.label }}</RouterLink>
            </template>
            <template v-else>
              <template v-for="g in gruposAdmin" :key="g.titulo">
                <h3 class="cajon-subtitulo">{{ g.titulo }}</h3>
                <RouterLink v-for="i in g.items" :key="i.to" :to="i.to" class="cajon-link">{{ i.label }}</RouterLink>
              </template>
            </template>
          </div>
        </section>
      </template>
    </nav>
  </aside>
</template>

<style scoped>
.barra {
  position: sticky; top: 0; z-index: var(--z-barra);
  display: flex; align-items: center; gap: 0.9rem;
  height: var(--alto-barra); padding: 0 1rem;
  background: var(--side-bg); color: var(--side-ink);
}
.marca { display: inline-flex; align-items: center; gap: 0.55rem; color: #fff; font-weight: 700; font-size: var(--t-base); flex: none; }
.marca-logo { width: 30px; height: 30px; object-fit: contain; background: #fff; border-radius: var(--r-sm); padding: 2px; }

.menus { display: flex; align-items: center; gap: 0.15rem; flex: 1; min-width: 0; }
.menu { position: relative; }
.menu-btn {
  display: inline-flex; align-items: center; gap: 0.3rem; white-space: nowrap;
  height: 36px; padding: 0 0.7rem; border-radius: var(--r-sm);
  font-size: var(--t-sm); font-weight: 600; color: var(--side-ink);
  transition: background 0.15s ease-out, color 0.15s ease-out;
}
.menu-btn:hover, .menu-btn.abierto { background: rgba(255, 255, 255, 0.08); color: #fff; }
.menu-btn.activo { background: rgba(255, 255, 255, 0.14); color: #fff; }
.chev { opacity: 0.75; transition: transform 0.18s ease-out; }
.menu-btn.abierto .chev { transform: rotate(180deg); }
.menu-btn:focus-visible, .barra-ic:focus-visible, .avatar:focus-visible { outline-color: #fff; }

.panel {
  position: absolute; top: calc(100% + 6px); left: 0; z-index: var(--z-desplegable);
  min-width: 230px; padding: 0.35rem;
  background: var(--surface); color: var(--ink);
  border: 1px solid var(--line); border-radius: var(--r-md); box-shadow: var(--shadow-lg);
  display: flex; flex-direction: column;
  animation: aparecer 0.14s ease-out;
}
.menu-der .panel { left: auto; right: 0; }
@keyframes aparecer { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
.panel-link {
  display: flex; align-items: center; gap: 0.6rem; width: 100%; text-align: left;
  padding: 0.5rem 0.65rem; border-radius: var(--r-sm);
  font-size: var(--t-sm); font-weight: 500; color: var(--ink);
}
.panel-link svg { color: var(--ink-soft); flex: none; }
.panel-link:hover, .panel-link:focus-visible { background: var(--accent-hover); outline: none; }
.panel-link.actual { font-weight: 700; color: var(--brand-ink); }
.panel-link.actual svg { color: var(--brand-ink); }

.panel-admin { flex-direction: row; gap: 0.6rem; padding: 0.7rem; min-width: 0; width: max-content; }
.panel-grupo { display: flex; flex-direction: column; min-width: 190px; }
.panel-titulo {
  font-size: var(--t-micro); font-weight: 700; color: var(--ink-soft);
  padding: 0.25rem 0.65rem 0.35rem; letter-spacing: 0.02em;
}

.barra-der { display: flex; align-items: center; gap: 0.45rem; margin-left: auto; flex: none; }
.buscador { width: clamp(160px, 16vw, 280px); flex: none; }
.buscador :deep(.buscador-input) {
  background: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.16);
  color: #fff; font-size: var(--t-sm); padding: 0.42rem 0.85rem;
}
.buscador :deep(.buscador-input::placeholder) { color: var(--side-ink-soft); }
.buscador :deep(.buscador-input:focus) { background: var(--surface); color: var(--ink); border-color: var(--accent); }
.buscador :deep(.buscador-resultados) { left: auto; width: min(420px, 90vw); z-index: var(--z-desplegable); }

.barra-ic {
  position: relative; display: grid; place-items: center; flex: none;
  width: 36px; height: 36px; border-radius: var(--r-sm); color: var(--side-ink);
  transition: background 0.15s ease-out, color 0.15s ease-out;
}
.barra-ic:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
.barra-ic.caido { color: #fff; }
.punto-alerta {
  position: absolute; top: 7px; right: 7px; width: 8px; height: 8px; border-radius: 50%;
  background: var(--danger); box-shadow: 0 0 0 2px var(--side-bg);
}
.avatar {
  width: 34px; height: 34px; border-radius: 50%; flex: none;
  background: var(--brand); color: #fff; font-weight: 700; font-size: var(--t-micro);
  box-shadow: inset 0 0 0 1.5px rgba(255, 255, 255, 0.25);
}
.panel-usuario { min-width: 220px; }
.yo { display: flex; flex-direction: column; padding: 0.45rem 0.65rem 0.6rem; margin-bottom: 0.25rem; border-bottom: 1px solid var(--line); line-height: 1.3; }
.yo strong { font-size: var(--t-sm); }
.yo span { font-size: var(--t-micro); color: var(--ink-soft); }
.salir { color: var(--danger); }

.hamburguesa { display: none; }

/* ── Cajón ── */
.cajon-fondo { position: fixed; inset: 0; z-index: var(--z-modal-backdrop); background: rgba(16, 26, 61, 0.5); }
.cajon {
  position: fixed; top: 0; bottom: 0; left: 0; z-index: var(--z-modal); width: 286px;
  display: flex; flex-direction: column; background: var(--side-bg); color: var(--side-ink);
  transform: translateX(-100%); visibility: hidden;
  transition: transform 0.26s cubic-bezier(0.22, 1, 0.36, 1), visibility 0s linear 0.26s;
}
.cajon.abierto { transform: none; visibility: visible; transition: transform 0.26s cubic-bezier(0.22, 1, 0.36, 1); }
.cajon-cab { display: flex; align-items: center; justify-content: space-between; height: var(--alto-barra); padding: 0 0.7rem 0 1rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
.cajon-nav { flex: 1; overflow-y: auto; padding: 0.6rem 0.6rem 1.2rem; display: flex; flex-direction: column; gap: 0.15rem; }
.cajon-grupo { display: flex; flex-direction: column; border-radius: var(--r-md); transition: background 0.18s ease-out; }
.cajon-grupo.abierto { background: var(--side-desplegado); margin: 0.2rem 0; }
.cajon-grupo.abierto .cajon-pestana { background: transparent; }
.cajon-grupo.abierto .cajon-subtitulo { color: var(--side-ink-soft-desplegado); }
.cajon-pestana {
  display: flex; align-items: center; justify-content: space-between; width: 100%;
  padding: 0.55rem 0.7rem; border-radius: var(--r-sm); text-align: left;
  font-size: var(--t-base); font-weight: 600; color: var(--side-ink);
  transition: background 0.15s ease-out, color 0.15s ease-out;
}
.cajon-pestana:hover, .cajon-pestana[aria-expanded="true"] { background: rgba(255, 255, 255, 0.06); color: #fff; }
.cajon-pestana.actual { color: #fff; }
.cajon-pestana .chev { opacity: 0.75; transition: transform 0.18s ease-out; }
.cajon-pestana[aria-expanded="true"] .chev { transform: rotate(180deg); }
.cajon-pestana:focus-visible { outline-color: #fff; }
.cajon-items { display: flex; flex-direction: column; padding: 0.15rem 0 0.4rem 0.6rem; }
.cajon-subtitulo { font-size: var(--t-micro); font-weight: 700; color: var(--side-ink-soft); padding: 0.45rem 0.7rem 0.2rem; }
.cajon-link { padding: 0.48rem 0.7rem; border-radius: var(--r-sm); font-size: var(--t-base); color: var(--side-ink); }
.cajon-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }
.cajon-link.router-link-exact-active { background: var(--accent); color: #fff; font-weight: 600; }
.cajon-link.solo { font-weight: 600; }

@media (max-width: 1300px) {
  .menus { display: none; }
  .hamburguesa { display: grid; }
}
@media (max-width: 720px) {
  .buscador { display: none; }
}
@media (prefers-reduced-motion: reduce) {
  .panel { animation: none; }
  .cajon, .cajon.abierto, .chev, .menu-btn, .barra-ic, .cajon-pestana, .cajon-grupo { transition: none; }
}
@media print {
  .barra, .cajon, .cajon-fondo { display: none; }
}
</style>
