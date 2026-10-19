<script setup>
/* «Ingresar con Google» (punto 9 del correo del 21-ago-2026).

   El backend ya sabía hacer esto —`POST /auth/google` verifica el ID token
   contra Google y devuelve el JWT de la casa— y el store ya tenía la acción.
   Lo único que faltaba era el botón.

   Usa Google Identity Services, que dibuja el botón él mismo: así el diseño
   es el que Google exige y no hay que mantener una copia. El script se carga
   una sola vez aunque haya varios botones en la página.

   Sin `VITE_GOOGLE_CLIENT_ID` no se muestra nada. Es a propósito: el sitio
   tiene que poder salir a producción antes de que la credencial esté cargada,
   y un botón que falla al tocarlo es peor que ningún botón. El client id es
   el MISMO que usa el Calendar de la clínica (`GOOGLE_CLIENT_ID` en el
   backend): el token se valida contra ese `aud` y no contra otro. */
import { onMounted, ref, useTemplateRef } from 'vue';
import { useAuthStore } from '../stores/auth.js';

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
    // Ya estaba cargado por otro botón: 'load' no vuelve a dispararse.
    if (window.google?.accounts?.id) resolve();
  });
}

async function entrar(respuesta) {
  try {
    await auth.loginConGoogle(respuesta.credential);
    emit('listo');
  } catch {
    emit('error', 'No pudimos ingresar con Google. Intenta con tu correo y contraseña.');
  }
}

onMounted(async () => {
  if (!CLIENT_ID) return;
  try {
    await cargarScript();
    window.google.accounts.id.initialize({ client_id: CLIENT_ID, callback: entrar });
    window.google.accounts.id.renderButton(contenedor.value, {
      theme: 'outline', size: 'large', width: 320,
      text: 'continue_with', locale: 'es',
    });
  } catch {
    // Sin conexión con Google no se muestra nada y queda el formulario de
    // siempre: entrar con correo y contraseña sigue funcionando igual.
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
/* Google dibuja el botón con un ancho fijo; centrarlo evita que quede
   pegado a la izquierda dentro de un formulario más ancho. */
.google-btn { display: flex; justify-content: center; }
.google-o {
  display: flex; align-items: center; gap: 0.75rem;
  font-size: var(--t-micro); color: var(--ink-soft);
}
.google-o::before, .google-o::after {
  content: ''; flex: 1; height: 1px; background: var(--line);
}
</style>
