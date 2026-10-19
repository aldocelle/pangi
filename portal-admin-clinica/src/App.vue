<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from './stores/auth.js';
import BarraSuperior from './components/BarraSuperior.vue';
import { api } from './api.js';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

// En el kiosco de fichaje (tablet compartido, sin sesión de staff) no se pide
// /auth/me: un token vencido de alguien que entró antes al panel daría 401 y
// el interceptor mandaría el tablet a /login.
onMounted(() => { if (!route.meta.kiosco) auth.fetchMe(); });

/* Algo caído (WhatsApp, Google, IA): punto rojo en la tuerca, y la tuerca lleva
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
   desmontado). Quedaba el menú arriba y el login otra vez en el centro, ya
   logueado. Esto vive acá porque App no se desmonta nunca. */
watch(
  () => auth.isLoggedIn && !!auth.user && route.name === 'login',
  (sobra) => { if (sobra) router.replace({ name: 'reservas' }); },
  { immediate: true },
);

function salir() {
  auth.logout();
  router.push('/login');
}
</script>

<template>
  <!-- Kiosco de fichaje: pantalla completa, sin layout del panel -->
  <RouterView v-if="route.meta.kiosco" />

  <div v-else-if="!auth.isLoggedIn">
    <RouterView />
  </div>

  <div v-else class="layout">
    <BarraSuperior :algo-caido="algoCaido" :destino-config="destinoConfig" @salir="salir" />
    <!-- `pantallaCompleta`: la Agenda usa todo el alto y el ancho, con su propio
         scroll; el resto de las pantallas lleva el margen de siempre. -->
    <main class="main" :class="{ completa: route.meta.pantallaCompleta }">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.layout { min-height: 100dvh; display: flex; flex-direction: column; }
.main { flex: 1; min-width: 0; width: 100%; max-width: 1680px; margin: 0 auto; padding: clamp(0.9rem, 2vw, 1.4rem); }
.main.completa { max-width: none; padding: 0; }
@media print {
  .main { padding: 0; max-width: none; }
}
</style>
