<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth.js';
import { apiError } from '../api.js';
import { CLINICA, SITIO_URL } from '../config.js';
import { obtenerTokenRecaptcha } from '../recaptcha.js';
import PasswordInput from '../components/PasswordInput.vue';
import BotonGoogle from '../components/BotonGoogle.vue';
import BaseIcon from '../components/BaseIcon.vue';

const auth = useAuthStore();
const router = useRouter();

const nombre = ref('');
const email = ref('');
const telefono = ref('');
const password = ref('');
const aceptaTerminos = ref(false);
const aceptaPrivacidad = ref(false);
const aceptaMarketing = ref(false);
const error = ref('');
const busy = ref(false);
const mensaje = ref('');

async function crearCuenta() {
  error.value = '';
  busy.value = true;
  try {
    const recaptchaToken = await obtenerTokenRecaptcha('registro');
    mensaje.value = await auth.register({
      nombre: nombre.value.trim(),
      email: email.value.trim(),
      telefono: telefono.value.trim() || null,
      password: password.value,
      acepta_terminos: aceptaTerminos.value,
      acepta_privacidad: aceptaPrivacidad.value,
      acepta_marketing: aceptaMarketing.value,
      recaptcha_token: recaptchaToken,
    });
  } catch (e) {
    error.value = apiError(e, 'No se pudo crear la cuenta.');
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <!-- Misma composición que LoginView: foto de fondo atenuada y una tarjeta
       centrada. Antes era la versión vieja del login (dos columnas al 50%),
       así que pasar de «Iniciar sesión» a «Crea una» cambiaba de diseño. -->
  <div class="login">
    <img src="/recepcion.webp" alt="" class="login-bg" />
    <div class="login-scrim"></div>

    <div class="login-card">
      <img src="/logo.webp" :alt="CLINICA.nombre" class="form-logo" />
      <h1 class="portal-title">Portal del cliente</h1>

      <form v-if="!mensaje" class="login-form" @submit.prevent="crearCuenta">
        <h2>Crear cuenta</h2>
        <p class="form-head-link">¿Ya tienes cuenta? <router-link to="/login">Inicia sesión</router-link></p>

        <!-- Con Google la cuenta se crea sola en el primer ingreso, así que
             este mismo botón sirve para registrarse. -->
        <BotonGoogle @listo="router.push('/inicio')" @error="error = $event" />

        <p class="form-sub">Con tu cuenta puedes ver el historial de tus mascotas y reservar horas más rápido.</p>
        <div class="field">
          <label for="nombre">Nombre completo</label>
          <input id="nombre" class="input" v-model="nombre" required placeholder="Tu nombre" />
        </div>
        <div class="field">
          <label for="email">Correo electrónico</label>
          <input id="email" class="input" type="email" v-model="email" required autocomplete="email" placeholder="tu@correo.cl" />
        </div>
        <div class="field">
          <label for="telefono">Teléfono (opcional)</label>
          <input id="telefono" class="input" type="tel" v-model="telefono" placeholder="+56 9 ..." />
        </div>
        <div class="field">
          <label for="pass">Contraseña</label>
          <PasswordInput id="pass" v-model="password" required minlength="8" autocomplete="new-password" />
          <!-- La regla va DEBAJO del campo y no en el placeholder: un
               placeholder desaparece al escribir, o sea justo cuando la persona
               necesita saber qué le falta. Es además lo que ya hace la vista
               hermana (Restablecer), que la muestra como texto. -->
          <small class="hint">Mínimo 8 caracteres, con letras y números.</small>
        </div>

        <div class="field field-checks">
          <label class="check">
            <input type="checkbox" v-model="aceptaTerminos" required />
            <span>
              Acepto los <a :href="`${SITIO_URL}/terminos`" target="_blank" rel="noopener">Términos y Condiciones</a>
            </span>
          </label>
          <label class="check">
            <input type="checkbox" v-model="aceptaPrivacidad" required />
            <span>
              Acepto la <a :href="`${SITIO_URL}/privacidad`" target="_blank" rel="noopener">Política de Privacidad</a>
            </span>
          </label>
          <label class="check">
            <input type="checkbox" v-model="aceptaMarketing" />
            <span>Quiero recibir novedades y promociones por correo (opcional)</span>
          </label>
        </div>

        <p v-if="error" class="form-error">{{ error }}</p>

        <button class="btn btn-primary btn-block" type="submit" :disabled="busy">
          {{ busy ? 'Creando cuenta…' : 'Crear cuenta' }}
        </button>

        <p class="form-help">
          <BaseIcon name="chat" :size="15" />
          ¿Problemas para registrarte?
          <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener">Escríbenos por WhatsApp</a>
        </p>
      </form>

      <div v-else class="login-form">
        <h2>Revisa tu correo</h2>
        <p class="form-ok">{{ mensaje }}</p>
        <button class="btn btn-primary btn-block" type="button" @click="router.push('/login')">
          Volver a iniciar sesión
        </button>
        <p class="form-help">
          <BaseIcon name="chat" :size="15" />
          ¿Problemas para entrar?
          <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener">Escríbenos por WhatsApp</a>
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Copia de los estilos de LoginView.vue (scoped, sin archivo común entre las
   dos vistas): si se cambia uno, cambiar el otro. */
.login {
  min-height: 100dvh; position: relative; overflow: hidden;
  display: grid; place-items: center; padding: 2rem;
  background: var(--brand-strong);
}
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
.portal-title { font-size: 1.7rem; font-weight: 700; color: var(--brand); text-align: center; }
.form-logo { width: 190px; height: 190px; object-fit: contain; }
.login-form { width: 100%; display: flex; flex-direction: column; gap: 1.1rem; text-align: center; }
.field { text-align: left; }
.login-form h2 { font-size: var(--t-3xl); color: var(--brand); }
.form-head-link { font-size: var(--t-sm); color: var(--ink-soft); margin-top: -0.5rem; }
.form-head-link a { color: var(--accent-strong); font-weight: 600; }
.form-head-link a:hover { text-decoration: underline; }
.form-sub { color: var(--ink-soft); font-size: var(--t-base); }
.form-error { background: var(--danger-tint); color: var(--danger); padding: 0.7rem 1rem; border-radius: var(--r-md); font-size: var(--t-sm); }
.form-ok { background: var(--ok-tint); color: var(--ok); padding: 0.7rem 1rem; border-radius: var(--r-md); font-size: var(--t-sm); }
.form-help {
  text-align: center; font-size: var(--t-sm); color: var(--ink-soft); margin-top: 0.3rem;
  display: flex; align-items: center; justify-content: center; gap: 0.4rem; flex-wrap: wrap;
}
.form-help svg { flex: none; opacity: 0.7; }
.form-help a { color: var(--accent-strong); font-weight: 600; }
.form-help a:hover { text-decoration: underline; }

.field-checks { gap: 0.6rem; }
.check { display: flex; align-items: flex-start; gap: 0.55rem; font-size: var(--t-sm); color: var(--ink-soft); cursor: pointer; }
.check input { margin-top: 0.2rem; flex: none; }
.check a { color: var(--accent-strong); font-weight: 600; }
.check a:hover { text-decoration: underline; }

@media (max-width: 480px) {
  .login { padding: 1rem; }
  .login-card { padding: 2.25rem 1.5rem; }
}
</style>
