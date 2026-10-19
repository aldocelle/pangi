<script setup>
import { ref } from 'vue';

defineProps({
  id: { type: String, default: null },
  placeholder: { type: String, default: '' },
  autocomplete: { type: String, default: 'current-password' },
  required: { type: Boolean, default: false },
  minlength: { type: [Number, String], default: null },
});
const modelValue = defineModel({ type: String, default: '' });

const visible = ref(false);
</script>

<template>
  <div class="pass-wrap">
    <input
      :id="id" class="input" :type="visible ? 'text' : 'password'"
      v-model="modelValue" :placeholder="placeholder"
      :autocomplete="autocomplete" :required="required" :minlength="minlength"
    />
    <button
      type="button" class="pass-toggle"
      :aria-label="visible ? 'Ocultar contraseña' : 'Mostrar contraseña'"
      @click="visible = !visible"
    >
      <svg v-if="!visible" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Zm10 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
      </svg>
      <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.24 4.24M9.4 5.3A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17.5 17.5 0 0 1-3.2 4.1M6.5 6.6C4 8.3 2 12 2 12s3.5 7 10 7c1.2 0 2.3-.2 3.3-.6" />
      </svg>
    </button>
  </div>
</template>

<style scoped>
.pass-wrap { position: relative; display: flex; }
.pass-wrap .input { padding-right: 2.6rem; width: 100%; }
.pass-toggle {
  position: absolute; right: 0.6rem; top: 50%; transform: translateY(-50%);
  display: grid; place-items: center;
  color: var(--ink-soft); padding: 0.2rem;
}
.pass-toggle:hover { color: var(--ink); }
</style>
