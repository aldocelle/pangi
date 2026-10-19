<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth.js';
import { apiError } from '../api.js';
import { CLINICA } from '../config.js';
import MarcaPortal from '../components/MarcaPortal.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const estado = ref('confirmando'); // confirmando | ok | error
const error = ref('');

onMounted(async () => {
  const token = route.query.token;
  if (!token) {
    estado.value = 'error';
    error.value = 'Este enlace no incluye un código de confirmación.';
    return;
  }
  try {
    await auth.confirmar(token);
    estado.value = 'ok';
    setTimeout(() => router.push('/inicio'), 1200);
  } catch (e) {
    estado.value = 'error';
    error.value = apiError(e, 'No se pudo confirmar tu cuenta.');
  }
});
</script>

<template>
  <div class="login">
    <div class="login-form-wrap full">
      <div class="login-form">
        <MarcaPortal />
        <template v-if="estado === 'confirmando'">
          <h2>Confirmando tu cuenta…</h2>
          <p class="form-sub">Un momento, por favor.</p>
        </template>

        <template v-else-if="estado === 'ok'">
          <h2>¡Cuenta confirmada!</h2>
          <p class="form-ok">Tu correo quedó verificado. Te estamos llevando a tu portal…</p>
        </template>

        <template v-else>
          <h2>No pudimos confirmar tu cuenta</h2>
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
.form-error { background: var(--danger-tint); color: var(--danger); padding: 0.7rem 1rem; border-radius: var(--r-md); font-size: var(--t-sm); }
.form-ok { background: var(--ok-tint); color: var(--ok); padding: 0.7rem 1rem; border-radius: var(--r-md); font-size: var(--t-sm); }
.form-help { font-size: var(--t-sm); color: var(--ink-soft); }
.form-help a { color: var(--accent-strong); font-weight: 600; }
.form-help a:hover { text-decoration: underline; }
</style>
