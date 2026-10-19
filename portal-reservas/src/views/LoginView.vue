<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth.js';
import { apiError } from '../api.js';
import { CLINICA, BASE } from '../config.js';
import PasswordInput from '../components/PasswordInput.vue';
import BotonGoogle from '../components/BotonGoogle.vue';
import BaseIcon from '../components/BaseIcon.vue';

const auth = useAuthStore();
const router = useRouter();

const email = ref('');
const password = ref('');
const error = ref('');
const busy = ref(false);

/* Recuperación de contraseña por correo: ver RestablecerView.vue. */
const modoReset = ref(false);
const resetEmail = ref('');
const resetMsg = ref('');
const resetBusy = ref(false);

async function entrar() {
  error.value = '';
  busy.value = true;
  try {
    await auth.login(email.value.trim(), password.value);
    router.push('/inicio');
  } catch (e) {
    error.value = apiError(e, 'Correo o contraseña incorrectos.');
  } finally {
    busy.value = false;
  }
}

function abrirReset() {
  resetEmail.value = email.value;
  resetMsg.value = '';
  modoReset.value = true;
}

async function solicitarReset() {
  resetBusy.value = true;
  resetMsg.value = '';
  try {
    resetMsg.value = await auth.solicitarReset(resetEmail.value.trim());
  } catch (e) {
    resetMsg.value = apiError(e, 'No se pudo enviar la solicitud. Escríbenos por WhatsApp.');
  } finally {
    resetBusy.value = false;
  }
}
</script>

<template>
  <!-- Una sola columna, siempre -- ya no "dividido a la mitad" (feedback sobre
       la primera versión, que ponía la foto en un panel al 50%).
       La foto pasa a fondo de toda la pantalla, atenuada, y el formulario
       vive en una tarjeta centrada: una sola composición, un solo peso
       visual, en vez de dos columnas compitiendo por la atención. -->
  <div class="login">
    <img :src="`${BASE}recepcion.webp`" alt="" class="login-bg" />
    <div class="login-scrim"></div>

    <div class="login-card">
      <!-- Orden de la pantalla: logo arriba, "Portal del cliente" debajo
           (H1 de verdad, es el título de la pantalla), "Iniciar sesión"
           debajo de eso. El logo va al mismo tamaño que en el login del
           panel de staff (190px) -- los dos logins comparten esa medida
           a propósito. -->
      <img :src="`${BASE}logo.webp`" :alt="CLINICA.nombre" class="form-logo" />
      <h1 class="portal-title">Portal del cliente</h1>

      <!-- Login -->
      <form v-if="!modoReset" class="login-form" @submit.prevent="entrar">
        <h2>Iniciar sesión</h2>
        <!-- Es la salida para quien no tiene cuenta todavía:
             va después del título, no pegada a él. -->
        <p class="form-head-link">¿No tienes cuenta? <router-link to="/registro">Crea una</router-link></p>

        <BotonGoogle @listo="router.push('/inicio')" @error="error = $event" />

        <!-- Justo arriba de donde se ingresa el correo, no
             debajo del título -- ahí queda pegada a lo que describe. -->
        <p class="form-sub">Ingresa con tu correo para ver la información de tus mascotas.</p>
        <div class="field">
          <label for="email">Correo electrónico</label>
          <input id="email" class="input" type="email" v-model="email" required autocomplete="email" placeholder="tu@correo.cl" />
        </div>
        <div class="field">
          <label for="pass">Contraseña</label>
          <PasswordInput id="pass" v-model="password" required autocomplete="current-password" placeholder="••••••••" />
        </div>

        <p v-if="error" class="form-error">{{ error }}</p>

        <button class="btn btn-primary btn-block" type="submit" :disabled="busy">
          {{ busy ? 'Ingresando…' : 'Ingresar' }}
        </button>

        <!-- Orden: primero el WhatsApp de soporte, después
             "¿Olvidaste tu contraseña?". -->
        <p class="form-help">
          <BaseIcon name="chat" :size="15" />
          ¿Problemas para entrar?
          <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener">Escríbenos por WhatsApp</a>
        </p>

        <button type="button" class="link-btn link-btn-center" @click="abrirReset">¿Olvidaste tu contraseña?</button>
      </form>

      <!-- Recuperar contraseña -->
      <form v-else class="login-form" @submit.prevent="solicitarReset">
        <h2>Recuperar acceso</h2>
        <p class="form-sub">Ingresa tu correo y te enviamos un enlace para elegir una nueva contraseña.</p>

        <div class="field">
          <label for="remail">Correo electrónico</label>
          <input id="remail" class="input" type="email" v-model="resetEmail" required autocomplete="email" placeholder="tu@correo.cl" />
        </div>

        <p v-if="resetMsg" class="form-ok">{{ resetMsg }}</p>

        <button class="btn btn-primary btn-block" type="submit" :disabled="resetBusy || !!resetMsg">
          {{ resetBusy ? 'Enviando…' : 'Enviar solicitud' }}
        </button>

        <button type="button" class="link-btn" @click="modoReset = false">← Volver a iniciar sesión</button>

        <p class="form-help">
          <BaseIcon name="chat" :size="15" />
          ¿Prefieres contacto directo?
          <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener">Escríbenos por WhatsApp</a>
        </p>
      </form>
    </div>
  </div>
</template>

<style scoped>
.login {
  min-height: 100dvh; position: relative; overflow: hidden;
  display: grid; place-items: center; padding: 2rem;
  background: var(--brand-strong);
}
/* La foto llena toda la pantalla, difuminada y con un velo encima -- es
   textura de fondo, no protagonista. La versión anterior la mostraba nítida
   en un panel al 50%, compitiendo con el formulario por la atención. */
.login-bg {
  position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover;
  filter: blur(3px) saturate(0.9);
  transform: scale(1.03); /* tapa el borde que deja el blur */
}
.login-scrim { position: absolute; inset: 0; background: rgba(26, 35, 80, 0.72); }

.login-card {
  position: relative; z-index: 2; width: 100%; max-width: 420px;
  background: var(--surface); border-radius: var(--r-xl);
  box-shadow: var(--shadow-lg); padding: 2.75rem 2.5rem;
  display: flex; flex-direction: column; align-items: center; gap: 1.4rem;
}
/* Mismo tamaño que .login-logo en el login del panel (portal-admin-clinica) --
   los dos comparten esa medida a propósito, ver commit de ese login. */
.portal-title { font-size: 1.7rem; font-weight: 700; color: var(--brand); text-align: center; }
.form-logo { width: 190px; height: 190px; object-fit: contain; }
.login-form { width: 100%; display: flex; flex-direction: column; gap: 1.1rem; text-align: center; }
/* .field hereda el centrado de .login-form -- lo devuelve a la izquierda:
   una etiqueta y un campo centrados se leen mal, solo el texto suelto
   (título, subtítulo, avisos) va centrado. */
.field { text-align: left; }
.login-form h2 { font-size: var(--t-3xl); color: var(--brand); }
.form-head-link { font-size: var(--t-sm); color: var(--ink-soft); margin-top: -0.5rem; }
.form-head-link a { color: var(--accent-strong); font-weight: 600; }
.form-head-link a:hover { text-decoration: underline; }
.form-sub { color: var(--ink-soft); font-size: var(--t-base); }
.form-error { background: var(--danger-tint); color: var(--danger); padding: 0.7rem 1rem; border-radius: var(--r-md); font-size: var(--t-sm); }
.form-ok { background: var(--ok-tint); color: var(--ok); padding: 0.7rem 1rem; border-radius: var(--r-md); font-size: var(--t-sm); }
.link-btn { background: none; border: none; color: var(--accent-strong); font-size: var(--t-sm); font-weight: 600; cursor: pointer; align-self: flex-start; }
.link-btn:hover { text-decoration: underline; }
.link-btn-center { align-self: center; }
.form-help {
  text-align: center; font-size: var(--t-sm); color: var(--ink-soft); margin-top: 0.3rem;
  display: flex; align-items: center; justify-content: center; gap: 0.4rem; flex-wrap: wrap;
}
.form-help svg { flex: none; opacity: 0.7; }
.form-help a { color: var(--accent-strong); font-weight: 600; }
.form-help a:hover { text-decoration: underline; }

@media (max-width: 480px) {
  .login-card { padding: 2.25rem 1.5rem; }
}
</style>
