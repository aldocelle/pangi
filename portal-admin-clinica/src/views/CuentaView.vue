<script setup>
import { ref, computed } from 'vue';
import { api, apiError } from '../api.js';
import { useAuthStore } from '../stores/auth.js';
import PasswordInput from '../components/PasswordInput.vue';

const auth = useAuthStore();

const LARGO_MIN = 8;

const actual = ref('');
const nueva = ref('');
const confirmar = ref('');
const guardando = ref(false);
const ok = ref('');
const error = ref('');

/* Se valida en el cliente para dar respuesta inmediata, pero el backend
   repite estas mismas reglas: esto es comodidad, no seguridad. */
const problema = computed(() => {
  if (!actual.value) return 'Escribe tu contraseña actual.';
  if (nueva.value.length < LARGO_MIN) return `La nueva debe tener al menos ${LARGO_MIN} caracteres.`;
  if (nueva.value === actual.value) return 'La nueva debe ser distinta de la actual.';
  if (confirmar.value && nueva.value !== confirmar.value) return 'Las contraseñas nuevas no coinciden.';
  if (!confirmar.value) return 'Repite la nueva contraseña.';
  return '';
});

async function cambiar() {
  error.value = '';
  ok.value = '';
  if (problema.value) { error.value = problema.value; return; }

  guardando.value = true;
  try {
    const { data } = await api.patch('/auth/me', {
      password_actual: actual.value,
      password_nueva: nueva.value,
    });
    // Cambiar la clave invalida los tokens anteriores, incluido el de esta
    // pestaña: el backend devuelve uno nuevo para no cerrar esta sesión.
    if (data?.access_token) {
      auth.token = data.access_token;
      localStorage.setItem('access_token', data.access_token);
    }
    actual.value = '';
    nueva.value = '';
    confirmar.value = '';
    ok.value = 'Contraseña actualizada. Úsala la próxima vez que ingreses.';
  } catch (e) {
    error.value = apiError(e, 'No se pudo cambiar la contraseña.');
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Mi cuenta</h1>
        <p class="page-sub">Datos de tu sesión y cambio de contraseña.</p>
      </div>
    </div>

    <div class="cuenta-grid">
      <section class="card datos">
        <h2>Tu sesión</h2>
        <dl>
          <div><dt>Nombre</dt><dd>{{ auth.user?.nombre || '—' }}</dd></div>
          <div><dt>Correo</dt><dd>{{ auth.user?.email || '—' }}</dd></div>
          <div>
            <dt>Rol</dt>
            <dd>{{ auth.user?.rol === 'admin' ? 'Administrador' : 'Veterinario' }}</dd>
          </div>
        </dl>
        <p class="nota">
          Para cambiar tu nombre o correo, pídeselo a un administrador desde
          <RouterLink to="/equipo">Equipo</RouterLink>.
        </p>
      </section>

      <section class="card">
        <h2>Cambiar contraseña</h2>
        <form @submit.prevent="cambiar" novalidate>
          <div class="campo">
            <label for="c-actual">Contraseña actual</label>
            <PasswordInput id="c-actual" v-model="actual" autocomplete="current-password" />
          </div>
          <div class="campo">
            <label for="c-nueva">Nueva contraseña</label>
            <PasswordInput id="c-nueva" v-model="nueva" autocomplete="new-password" :minlength="LARGO_MIN" />
            <p class="pista">Al menos {{ LARGO_MIN }} caracteres.</p>
          </div>
          <div class="campo">
            <label for="c-conf">Repite la nueva</label>
            <PasswordInput id="c-conf" v-model="confirmar" autocomplete="new-password" />
          </div>

          <p v-if="error" class="msg msg-error" role="alert">{{ error }}</p>
          <p v-if="ok" class="msg msg-ok" role="status">{{ ok }}</p>

          <button class="btn btn-primary" :disabled="guardando">
            {{ guardando ? 'Guardando…' : 'Cambiar contraseña' }}
          </button>
        </form>
      </section>
    </div>
  </div>
</template>

<style scoped>
.cuenta-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 1.2rem; align-items: start;
}
.card h2 { font-size: var(--t-lg); margin: 0 0 1rem; }

dl { margin: 0; display: flex; flex-direction: column; gap: 0.85rem; }
dl div { display: flex; flex-direction: column; gap: 0.15rem; }
dt { font-size: var(--t-micro); color: var(--ink-soft); }
dd { margin: 0; font-weight: 600; }
.nota { margin-top: 1.2rem; font-size: var(--t-sm); color: var(--ink-soft); }
.nota a { text-decoration: underline; }

form { display: flex; flex-direction: column; gap: 0.9rem; }
.campo { display: flex; flex-direction: column; gap: 0.35rem; }
.campo label { font-size: var(--t-sm); font-weight: 600; }
.pista { font-size: var(--t-micro); color: var(--ink-soft); }

.msg { font-size: var(--t-sm); line-height: 1.45; margin: 0; }
.msg-error { color: #b42318; }
.msg-ok { color: #067647; }

form .btn { align-self: flex-start; margin-top: 0.2rem; }
</style>
