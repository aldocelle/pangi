<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter, useRoute, RouterLink } from 'vue-router';
import { useAuthStore } from '../stores/auth.js';
import { useDatosStore } from '../stores/datos.js';
import { NAV, CLINICA, BASE } from '../config.js';
import BaseIcon from './BaseIcon.vue';
import { urlFoto } from '../api.js';

const auth = useAuthStore();
const datos = useDatosStore();
const router = useRouter();
const route = useRoute();

onMounted(() => {
  datos.cargar();
  datos.iniciarReloj();
});

// Cuidados que piden acción (criterio en stores/datos.js, el mismo que
// RecordatoriosView usa para destacarlos).
const nRecordatorios = computed(() => datos.cuidadosPorAtender.length);

const menuAbierto = ref(false);   // sidebar en móvil
const userMenu = ref(false);

// Título de la página actual según la ruta
const pageTitle = computed(() => {
  const titleMap = {
    'inicio': 'Mi portal',
    'mascotas': 'Mis mascotas',
    'ficha': 'Datos de la mascota',
    'citas': 'Mis citas',
    'recordatorios': 'Recordatorios',
    'documentos': 'Documentos',
    'pagos': 'Pagos',
    'configuracion': 'Configuración',
    'nueva-cita': 'Reservar nueva cita',
  };
  return titleMap[route.name] || 'Portal del cliente';
});

function salir() {
  auth.logout();
  router.push('/login');
}

function irProximamente(item) {
  // Secciones aún no construidas: no navegar, solo cerrar menú móvil.
  menuAbierto.value = false;
}

// ¿la ruta activa coincide con el item?
function esActivo(item) {
  return route.name === item.name;
}
</script>

<template>
  <div class="app-shell">
    <!-- Sidebar -->
    <aside class="sidebar" :class="{ open: menuAbierto }">
      <div class="side-brand">
        <img :src="`${BASE}isotipo.webp`" alt="" class="side-logo" />
        <div class="side-brand-text">
          <strong>Portal del cliente</strong>
          <span>{{ CLINICA.nombre }}</span>
        </div>
      </div>

      <nav class="side-nav" aria-label="Navegación principal">
        <template v-for="item in NAV" :key="item.label">
          <RouterLink
            v-if="!item.proximamente"
            :to="item.to"
            class="side-link"
            :class="{ active: esActivo(item) }"
            @click="menuAbierto = false"
          >
            <BaseIcon :name="item.icon" :size="20" />
            <span>{{ item.label }}</span>
            <span v-if="item.badge && nRecordatorios" class="side-badge">{{ nRecordatorios }}</span>
          </RouterLink>
          <button
            v-else
            class="side-link disabled"
            type="button"
            @click="irProximamente(item)"
            :title="`${item.label} — próximamente`"
          >
            <BaseIcon :name="item.icon" :size="20" />
            <span>{{ item.label }}</span>
            <span class="side-soon">Pronto</span>
          </button>
        </template>
      </nav>

      <div class="side-help">
        <strong>¿Necesitas ayuda?</strong>
        <span>Estamos para ayudarte</span>
        <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener" class="btn btn-primary btn-sm side-help-btn">
          Contactar <BaseIcon name="chevron" :size="16" />
        </a>
      </div>

      <button class="side-link side-logout" type="button" @click="salir">
        <BaseIcon name="logout" :size="20" />
        <span>Cerrar sesión</span>
      </button>
    </aside>

    <div v-if="menuAbierto" class="backdrop" @click="menuAbierto = false"></div>

    <!-- Contenido -->
    <div class="main">
      <header class="topbar">
        <!-- Lado izquierdo: menú + título -->
        <button class="hamburger" @click="menuAbierto = true" aria-label="Abrir menú">
          <BaseIcon name="menu" :size="24" />
        </button>
        <h1 class="top-title">{{ pageTitle }}</h1>

        <!-- Espaciador -->
        <div class="top-spacer"></div>

        <!-- Lado derecho: recordatorios, contacto, usuario -->
        <div class="top-actions">
          <!-- Recordatorios con badge destacado -->
          <RouterLink to="/recordatorios" class="top-action-link recordatorios" aria-label="Mis recordatorios">
            <BaseIcon name="bell" :size="20" />
            <span v-if="nRecordatorios" class="badge">{{ nRecordatorios }}</span>
          </RouterLink>

          <!-- WhatsApp: siempre accesible -->
          <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener"
             class="top-action-link whatsapp" aria-label="Contactar por WhatsApp">
            <BaseIcon name="wa" :size="20" />
            <span class="top-tel-sm">{{ CLINICA.telefono }}</span>
          </a>

          <!-- Menú de usuario -->
          <div class="user-menu-wrap" @click="userMenu = !userMenu">
            <div class="user-trigger">
              <img v-if="auth.user?.foto" class="user-avatar user-avatar-foto" :src="urlFoto(auth.user.foto)" alt="" />
              <span v-else class="user-avatar">{{ auth.iniciales }}</span>
              <div class="user-info">
                <span class="user-name">{{ auth.user?.nombre || 'Cliente' }}</span>
                <span class="user-email">{{ auth.user?.email || '' }}</span>
              </div>
              <BaseIcon name="chevronDown" :size="16" :class="{ rotated: userMenu }" />
            </div>
            <div v-if="userMenu" class="user-dropdown" @click.stop>
              <div class="user-dropdown-header">
                <img v-if="auth.user?.foto" class="user-avatar-lg user-avatar-foto" :src="urlFoto(auth.user.foto)" alt="" />
                <span v-else class="user-avatar-lg">{{ auth.iniciales }}</span>
                <div>
                  <div class="user-name-lg">{{ auth.user?.nombre || 'Cliente' }}</div>
                  <div class="user-email-lg">{{ auth.user?.email || '' }}</div>
                </div>
              </div>
              <div class="user-dropdown-divider"></div>
              <RouterLink to="/configuracion" class="user-dropdown-item">
                <BaseIcon name="gear" :size="16" /> Configuración
              </RouterLink>
              <button class="user-dropdown-item logout" @click="salir">
                <BaseIcon name="logout" :size="16" /> Cerrar sesión
              </button>
            </div>
          </div>
        </div>
      </header>

      <main class="content">
        <slot />
      </main>
    </div>
  </div>
</template>

<style scoped>
.app-shell { display: flex; min-height: 100dvh; }

/* ── Sidebar ── */
.sidebar {
  width: var(--sidebar-w); flex: none;
  background: linear-gradient(180deg, var(--side-bg) 0%, var(--side-bg-2) 100%);
  color: var(--side-ink);
  display: flex; flex-direction: column;
  padding: 1.4rem 1rem;
  position: sticky; top: 0; height: 100dvh;
}
.side-brand { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 0.5rem; padding: 0 0.5rem 1.4rem; }
.side-logo { width: 56px; height: 56px; object-fit: contain; background: #fff; border-radius: 12px; padding: 4px; }
.side-brand-text { display: flex; flex-direction: column; line-height: 1.3; }
.side-brand-text strong { color: #fff; font-size: var(--t-lg); font-weight: 700; }
.side-brand-text span { font-size: var(--t-micro); color: var(--side-ink-soft); }

.side-nav { display: flex; flex-direction: column; gap: 0.25rem; flex: 1; }
.side-link {
  display: flex; align-items: center; gap: 0.85rem;
  padding: 0.7rem 0.9rem; border-radius: var(--r-md);
  color: var(--side-ink); font-size: var(--t-base); font-weight: 500;
  width: 100%; text-align: left;
  transition: background 0.18s, color 0.18s;
}
.side-link span:first-of-type { flex: 1; }
.side-link:hover:not(.disabled) { background: rgba(255, 255, 255, 0.06); color: #fff; }
.side-link.active { background: var(--accent); color: #fff; box-shadow: 0 6px 16px rgba(var(--accent-rgb), 0.3); }
.side-link.active :deep(svg) { stroke: #fff; }
.side-link.disabled { cursor: default; opacity: 0.55; }
.side-badge {
  background: var(--accent); color: #fff; font-size: var(--t-micro); font-weight: 700;
  min-width: 20px; height: 20px; border-radius: 999px;
  display: grid; place-items: center; padding: 0 6px;
}
.side-link.active .side-badge { background: rgba(255, 255, 255, 0.25); }
.side-soon {
  font-size: var(--t-micro); font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
  background: rgba(255, 255, 255, 0.1); color: var(--side-ink-soft);
  padding: 2px 7px; border-radius: 999px;
}

.side-help {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: var(--r-lg); padding: 1.1rem;
  display: flex; flex-direction: column; gap: 0.25rem; margin: 1rem 0;
}
.side-help strong { color: #fff; font-size: var(--t-base); }
.side-help span { font-size: var(--t-micro); color: var(--side-ink-soft); }
.side-help-btn { margin-top: 0.7rem; }

.side-logout { color: var(--side-ink-soft); }
.side-logout:hover { background: rgba(255, 255, 255, 0.06); color: #fff; }

/* ── Main ── */
.main { flex: 1; min-width: 0; display: flex; flex-direction: column; }

.topbar {
  position: sticky; top: 0; z-index: 30;
  display: flex; align-items: center; gap: 1rem;
  padding: 0.75rem clamp(1rem, 3vw, 2.5rem);
  background: var(--bg); border-bottom: 1px solid var(--line);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.hamburger { display: none; color: var(--ink); padding: 0.5rem; border-radius: var(--r-md); }
.hamburger:hover { background: var(--surface); }

/* Puede encogerse y cortar con «…». Era `flex: none`, y a 400 px «Datos de la
   mascota» empujaba el menú del usuario 32 px fuera de la pantalla: la ficha
   entera se deslizaba de lado. Los títulos cortos (Inicio, Citas) no lo
   mostraban, por eso pasó inadvertido. */
.top-title {
  font-size: var(--t-2xl); font-weight: 700; margin: 0; color: var(--ink);
  letter-spacing: -0.015em; flex: 0 1 auto; min-width: 0;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.top-spacer { flex: 1; }

.top-actions {
  display: flex; align-items: center; gap: 0.5rem;
}

.top-action-link {
  position: relative; display: grid; place-items: center;
  padding: 0.6rem; border-radius: var(--r-md); color: var(--ink-soft);
  transition: background 0.2s, color 0.2s;
}

.top-action-link:hover { background: var(--surface); color: var(--ink); }

.top-action-link.recordatorios:has(.badge) { color: var(--accent-strong); }

.badge {
  position: absolute; top: -4px; right: -4px;
  background: var(--accent); color: #fff; font-size: var(--t-micro); font-weight: 700;
  min-width: 20px; height: 20px; border-radius: 999px;
  display: grid; place-items: center; padding: 0 5px;
  box-shadow: 0 2px 6px rgba(var(--accent-rgb), 0.3);
}

.top-tel-sm { display: none; }

.user-menu-wrap {
  position: relative; cursor: pointer;
}

.user-trigger {
  display: flex; align-items: center; gap: 0.75rem;
  padding: 0.4rem 0.8rem; border-radius: var(--r-md);
  transition: background 0.2s;
}

.user-trigger:hover { background: var(--surface); }
.user-trigger :deep(svg.rotated) { transform: rotate(180deg); }
.user-trigger :deep(svg) { transition: transform 0.2s var(--ease); }

.user-avatar {
  width: 36px; height: 36px; border-radius: 50%;
  background: linear-gradient(135deg, var(--brand) 0%, #0a5652 100%);
  color: #fff; font-weight: 700; font-size: 0.82rem;
  display: grid; place-items: center; flex: none;
  box-shadow: 0 2px 8px rgba(38, 50, 110, 0.2);
}

.user-avatar-foto { object-fit: cover; padding: 0; background: var(--brand-tint); }

.user-info {
  display: flex; flex-direction: column; gap: 0.1rem; line-height: 1.3;
}

.user-name { font-size: var(--t-base); font-weight: 600; color: var(--ink); }

.user-email { font-size: var(--t-micro); color: var(--ink-soft); }

.user-dropdown {
  position: absolute; top: calc(100% + 8px); right: 0;
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--r-lg); box-shadow: var(--shadow-lg);
  min-width: 280px; z-index: 40; overflow: hidden;
}

.user-dropdown-header {
  display: flex; align-items: center; gap: 0.8rem;
  padding: 1rem; background: var(--bg);
}

.user-avatar-lg {
  width: 48px; height: 48px; border-radius: 50%;
  background: linear-gradient(135deg, var(--brand) 0%, #0a5652 100%);
  color: #fff; font-weight: 700; font-size: 1rem;
  display: grid; place-items: center; flex: none;
}

.user-name-lg { font-size: var(--t-base); font-weight: 600; color: var(--ink); }

.user-email-lg { font-size: var(--t-micro); color: var(--ink-soft); margin-top: 0.2rem; }

.user-dropdown-divider {
  height: 1px; background: var(--line); margin: 0;
}

.user-dropdown-item {
  display: flex; align-items: center; gap: 0.7rem;
  width: 100%; padding: 0.75rem 1rem; font-size: var(--t-base);
  color: var(--ink-soft); transition: background 0.2s, color 0.2s;
}

.user-dropdown-item:hover { background: var(--surface); color: var(--ink); }

.user-dropdown-item.logout {
  color: var(--danger); border-top: 1px solid var(--line); margin-top: 0.25rem; padding-top: 0.5rem;
}

.user-dropdown-item.logout:hover { background: var(--danger-tint); }

.content { padding: clamp(1.2rem, 3vw, 2rem); flex: 1; }

.backdrop { display: none; }

/* ── Responsive ── */
@media (max-width: 960px) {
  .sidebar {
    position: fixed; z-index: 60; left: 0; top: 0;
    transform: translateX(-100%); transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
  }
  .sidebar.open { transform: translateX(0); }
  .hamburger { display: grid; place-items: center; }
  .backdrop { display: block; position: fixed; inset: 0; background: rgba(16, 26, 61, 0.5); z-index: 50; }
  .top-title { font-size: var(--t-xl); }
  .top-action-link { padding: 0.5rem; }
  .user-info { display: none; }
}

@media (max-width: 600px) {
  .topbar { gap: 0.6rem; padding: 0.6rem 1rem; }
  .top-title { font-size: var(--t-lg); }
  .top-action-link { padding: 0.4rem; }
  .user-avatar { width: 32px; height: 32px; font-size: var(--t-micro); }
  .user-dropdown { right: -0.5rem; min-width: 250px; }
  .top-spacer { min-width: 0.5rem; }
}
</style>
