<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth.js';
import { apiError } from '../api.js';
import PasswordInput from '../components/PasswordInput.vue';
import BotonGoogle from '../components/BotonGoogle.vue';
import { CLINICA } from '../marca.js';

const auth = useAuthStore();
const router = useRouter();

const email = ref('');
const password = ref('');
const error = ref('');
const busy = ref(false);

async function submit() {
  error.value = '';
  busy.value = true;
  try {
    await auth.login(email.value, password.value);
    if (!['veterinario', 'admin'].includes(auth.user?.rol)) {
      auth.logout();
      error.value = 'Este panel es solo para el equipo de la clínica.';
      return;
    }
    router.push('/');
  } catch (e) {
    error.value = apiError(e, 'No se pudo iniciar sesión. Intenta de nuevo.');
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-brand">
        <img src="/logo.webp" :alt="CLINICA" class="login-logo" />
        <h1>Panel de administración</h1>
        <p>Reservas, consultas, especialistas y fichas de la clínica.</p>
      </div>

      <form @submit.prevent="submit" class="login-form">
        <BotonGoogle @listo="router.push('/')" @error="error = $event" />
        <div class="field">
          <label for="email">Correo</label>
          <input id="email" class="input" type="email" v-model="email" required placeholder="tu@correo.cl" />
        </div>
        <div class="field">
          <label for="password">Contraseña</label>
          <PasswordInput id="password" v-model="password" required />
        </div>
        <p v-if="error" class="error-text">{{ error }}</p>
        <button class="btn btn-primary" type="submit" :disabled="busy">
          {{ busy ? 'Un momento…' : 'Ingresar' }}
        </button>
      </form>
    </div>
  </div>
</template>

<style scoped>
.login-wrap {
  min-height: 100dvh; display: grid; place-items: center; padding: 1.5rem;
  /* Azul de marca, no el verde suelto que había antes (sin relación con
     ningún token de la paleta) — mismo gradiente que --side-bg/--side-bg-2
     usan en el sidebar del panel. */
  background: linear-gradient(160deg, var(--brand-strong), var(--side-bg-2));
}
.login-card {
  width: 100%; max-width: 400px;
  background: var(--surface);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow-lg);
  padding: 2.2rem;
}
.login-brand { text-align: center; margin-bottom: 1.6rem; }
.login-logo {
  /* Del ancho del título "Panel de administración", no un ícono chico:
     a 64px el logo (que trae su propio texto, el nombre de la clínica)
     quedaba ilegible al lado del título. */
  width: 190px; height: 190px; object-fit: contain;
  background: #fff; border-radius: var(--r-sm); padding: 8px;
}
.login-brand h1 { font-size: var(--t-2xl); margin-top: 0.7rem; }
.login-brand p { color: var(--ink-soft); font-size: var(--t-base); margin-top: 0.2rem; }
.login-form { display: flex; flex-direction: column; gap: 1rem; }
.login-form .btn { margin-top: 0.4rem; padding: 0.8em; }
</style>
