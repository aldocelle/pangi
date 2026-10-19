<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth.js';
import { apiError } from '../api.js';
import { CLINICA } from '../config.js';
import PasswordInput from '../components/PasswordInput.vue';
import MarcaPortal from '../components/MarcaPortal.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const estado = ref('form'); // form | ok | error
const error = ref('');
const password = ref('');
const busy = ref(false);
const token = ref('');

onMounted(() => {
  token.value = route.query.token || '';
  if (!token.value) {
    estado.value = 'error';
    error.value = 'Este enlace no incluye un código de recuperación.';
  }
});

async function enviar() {
  error.value = '';
  busy.value = true;
  try {
    await auth.restablecer(token.value, password.value);
    estado.value = 'ok';
    setTimeout(() => router.push('/inicio'), 1200);
  } catch (e) {
    error.value = apiError(e, 'No se pudo restablecer tu contraseña.');
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="login">
    <div class="login-form-wrap full">
      <div class="login-form">
        <MarcaPortal />
        <template v-if="estado === 'form'">
          <h2>Elige tu nueva contraseña</h2>
          <p class="form-sub">Mínimo 8 caracteres, con letras y números.</p>

          <form @submit.prevent="enviar">
            <div class="field">
              <label for="pass">Nueva contraseña</label>
              <PasswordInput id="pass" v-model="password" required minlength="8" autocomplete="new-password" placeholder="••••••••" />
            </div>

            <p v-if="error" class="form-error">{{ error }}</p>

            <button class="btn btn-primary btn-block" type="submit" :disabled="busy">
              {{ busy ? 'Guardando…' : 'Guardar y entrar' }}
            </button>
          </form>
        </template>

        <template v-else-if="estado === 'ok'">
          <h2>¡Contraseña actualizada!</h2>
          <p class="form-ok">Te estamos llevando a tu portal…</p>
        </template>

        <template v-else>
          <h2>No pudimos restablecer tu contraseña</h2>
          <p class="form-error">{{ error }}</p>
          <button class="btn btn-primary btn-block" type="button" @click="router.push('/login')">
            Volver a iniciar sesión
          </button>
          <p class="form-help">
            ¿Necesitas ayuda?
            <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener">Escríbenos por WhatsApp</a>
          </p>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.login { min-height: 100dvh; display: grid; }
.login-form-wrap.full { display: grid; place-items: center; padding: 2rem; background: var(--surface); }
.login-form { width: 100%; max-width: 380px; display: flex; flex-direction: column; gap: 1.1rem; text-align: center; }
.login-form h2 { font-size: var(--t-3xl); color: var(--brand); }
.form-sub { color: var(--ink-soft); font-size: var(--t-base); }
.login-form form { display: flex; flex-direction: column; gap: 1.1rem; text-align: left; }
.field label { display: block; font-size: var(--t-sm); font-weight: 600; margin-bottom: 0.4rem; color: var(--ink); }
.form-error { background: var(--danger-tint); color: var(--danger); padding: 0.7rem 1rem; border-radius: var(--r-md); font-size: var(--t-sm); }
.form-ok { background: var(--ok-tint); color: var(--ok); padding: 0.7rem 1rem; border-radius: var(--r-md); font-size: var(--t-sm); }
.form-help { font-size: var(--t-sm); color: var(--ink-soft); }
.form-help a { color: var(--accent-strong); font-weight: 600; }
.form-help a:hover { text-decoration: underline; }
</style>
