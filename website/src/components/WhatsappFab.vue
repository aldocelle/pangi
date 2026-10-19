<script setup>
defineProps({
  clinica: { type: Object, required: true },
});
</script>

<template>
  <a
    :href="`https://wa.me/${clinica.telefonoPlano}`"
    target="_blank"
    rel="noopener"
    class="wa-fab"
    aria-label="Escríbenos por WhatsApp"
  >
    <svg viewBox="0 0 32 32" width="30" height="30" aria-hidden="true">
      <path
        fill="currentColor"
        d="M16 .5C7.4.5.5 7.4.5 16c0 2.8.7 5.4 2 7.8L.5 31.5l7.9-2c2.3 1.2 4.9 1.9 7.6 1.9 8.6 0 15.5-6.9 15.5-15.5S24.6.5 16 .5zm0 28.3c-2.4 0-4.7-.6-6.7-1.8l-.5-.3-4.7 1.2 1.3-4.6-.3-.5c-1.3-2.1-2-4.5-2-7 0-7.2 5.9-13 13.1-13S29 8.8 29 16s-5.9 12.8-13 12.8zm7.2-9.6c-.4-.2-2.3-1.1-2.7-1.3-.4-.1-.6-.2-.9.2s-1 1.3-1.2 1.5c-.2.2-.4.3-.8.1-.4-.2-1.7-.6-3.2-2-1.2-1-2-2.3-2.2-2.7-.2-.4 0-.6.2-.8.2-.2.4-.4.5-.7.2-.2.2-.4.4-.6.1-.3.1-.5 0-.7-.1-.2-.9-2.1-1.2-2.9-.3-.8-.6-.7-.9-.7h-.7c-.2 0-.6.1-.9.5-.3.4-1.2 1.2-1.2 2.9s1.2 3.4 1.4 3.6c.2.2 2.5 3.8 6 5.3.8.4 1.5.6 2 .7.8.3 1.6.2 2.2.1.7-.1 2.3-.9 2.6-1.8.3-.9.3-1.6.2-1.8-.1-.2-.3-.3-.7-.4z"
      />
    </svg>
    <span class="wa-tip">¿Necesitas ayuda?</span>
  </a>
</template>

<style scoped>
.wa-fab {
  position: fixed; bottom: 22px; right: 22px; z-index: 60;
  width: 58px; height: 58px; border-radius: 50%;
  /* Azul oscuro y no blanco: blanco sobre este verde da 1.98:1, por debajo
     del 3:1 que pide un ícono. Azul oscuro da 7.56:1 (ver --wa en app.css). */
  background: var(--wa); color: var(--azul-oscuro);
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 10px 26px rgba(var(--wa-rgb), 0.45);
  transition: transform 0.2s, box-shadow 0.2s;
}
.wa-fab:hover { transform: scale(1.08); box-shadow: 0 14px 32px rgba(var(--wa-rgb), 0.55); }
.wa-fab::after {
  content: ''; position: absolute; inset: 0; border-radius: 50%;
  box-shadow: 0 0 0 0 rgba(var(--wa-rgb), 0.5);
  /* Múltiplo de --latido y no un segundo suelto: todo lo que pulsa en el
     sitio comparte el mismo tempo (ver el punto de "Abierto ahora" en
     TheFooter.vue, el mismo anillo a 1x). 3x da un pulso ambiental, no un
     latido nervioso, en un botón que queda a la vista todo el tiempo. */
  animation: pulso calc(var(--latido) * 3) infinite;
}
@keyframes pulso {
  0% { box-shadow: 0 0 0 0 rgba(var(--wa-rgb), 0.5); }
  70% { box-shadow: 0 0 0 16px rgba(var(--wa-rgb), 0); }
  100% { box-shadow: 0 0 0 0 rgba(var(--wa-rgb), 0); }
}
.wa-tip {
  position: absolute; right: 70px; white-space: nowrap;
  background: #fff; color: var(--ink); font-size: 0.85rem; font-weight: 600;
  padding: 0.5em 0.9em; border-radius: var(--r-full); box-shadow: var(--shadow);
  opacity: 0; transform: translateX(8px); pointer-events: none; transition: 0.2s;
}
.wa-fab:hover .wa-tip { opacity: 1; transform: translateX(0); }
@media (prefers-reduced-motion: reduce) { .wa-fab::after { animation: none; } }
@media (max-width: 640px) { .wa-tip { display: none; } }
</style>
