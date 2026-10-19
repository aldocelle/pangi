<script setup>
/* «Continuar con Google» del panel. Copia del de portal-reservas (sin build
   compartido, §9.5) con una diferencia que importa: llama a
   /auth/google/equipo, que solo deja entrar a cuentas del equipo que ya
   existen. El de clientes rechaza al equipo, y al revés.

   Google dibuja el botón él mismo. El script se espera antes de usarlo: con
   `async`, `window.google` todavía no existe cuando la vista se monta, y así
   el botón no aparecía nunca. Sin `VITE_GOOGLE_CLIENT_ID`, o sin conexión con
   Google, no se muestra nada y queda el formulario de siempre. */
import { onMounted, ref, useTemplateRef } from 'vue';
import { useAuthStore } from '../stores/auth.js';
import { apiError } from '../api.js';

const emit = defineEmits(['listo', 'error']);

const auth = useAuthStore();
const CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID || '';
const contenedor = useTemplateRef('contenedor');
const disponible = ref(!!CLIENT_ID);

const SRC = 'https://accounts.google.com/gsi/client';

function cargarScript() {
  return new Promise((resolve, reject) => {
    if (window.google?.accounts?.id) return resolve();
    let el = document.querySelector(`script[src="${SRC}"]`);
    if (!el) {
      el = document.createElement('script');
      el.src = SRC;
      el.async = true;
      el.defer = true;
      document.head.appendChild(el);
    }
    el.addEventListener('load', resolve, { once: true });
    el.addEventListener('error', reject, { once: true });
    if (window.google?.accounts?.id) resolve();
  });
}

async function entrar(respuesta) {
  try {
    await auth.loginConGoogle(respuesta.credential);
    emit('listo');
  } catch (e) {
    // El 403 trae la razón («no es del equipo», «desactivada»): se muestra tal cual.
    emit('error', apiError(e, 'No se pudo ingresar con Google. Intenta con tu correo y contraseña.'));
  }
}

onMounted(async () => {
  if (!CLIENT_ID) return;
  try {
    await cargarScript();
    window.google.accounts.id.initialize({ client_id: CLIENT_ID, callback: entrar });
    // Ancho fijo en px (Google no acepta %): el del contenedor, hasta 320. Con
    // 320 fijo la tarjeta se salía de una pantalla de 400 px.
    const ancho = Math.max(200, Math.min(320, contenedor.value.clientWidth || 320));
    window.google.accounts.id.renderButton(contenedor.value, {
      theme: 'outline', size: 'large', width: ancho,
      text: 'continue_with', locale: 'es',
    });
  } catch {
    disponible.value = false;
  }
});
</script>

<template>
  <div v-if="disponible" class="google-wrap">
    <div ref="contenedor" class="google-btn"></div>
    <div class="google-o"><span>o</span></div>
  </div>
</template>

<style scoped>
.google-wrap { display: flex; flex-direction: column; gap: 0.9rem; }
.google-btn { display: flex; justify-content: center; min-height: 44px; }
.google-o {
  display: flex; align-items: center; gap: 0.75rem;
  font-size: var(--t-micro); color: var(--ink-soft);
}
.google-o::before, .google-o::after {
  content: ''; flex: 1; height: 1px; background: var(--line);
}
</style>
