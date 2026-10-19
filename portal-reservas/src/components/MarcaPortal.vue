<script setup>
import { CLINICA } from '../config.js';
/**
 * El logo y el nombre, en versión compacta y sobre fondo claro.
 *
 * Para las páginas públicas SIN el panel lateral oscuro: Restablecer y
 * Confirmar. A las dos se llega desde un enlace de un correo, y hasta ahora
 * eran una página blanca sin una sola marca — justo donde alguien tiene
 * motivos para preguntarse si está en el sitio correcto y no en una copia.
 *
 * Login y Registro NO lo usan: ellos tienen su propia versión, grande y sobre
 * el panel azul, que es parte de la composición de esa pantalla.
 */
</script>

<template>
  <div class="marca">
    <img src="/isotipo.webp" alt="" class="marca-logo" />
    <p class="marca-txt">
      <strong>{{ CLINICA.nombre }}</strong>
      <span>Clínica Veterinaria</span>
    </p>
  </div>
</template>

<style scoped>
/* Centrada: las dos pantallas que la usan centran todo lo demás (título,
   aviso, botón), y la marca alineada a la izquierda rompía ese eje. */
.marca { display: flex; align-items: center; justify-content: center; gap: 0.9rem; margin-bottom: 1.8rem; }
.marca-logo { width: 88px; height: 88px; object-fit: contain; flex: none; }
.marca-txt { display: flex; flex-direction: column; line-height: 1.2; margin: 0; text-align: left; }
.marca-txt strong { font-size: var(--t-xl); color: var(--brand-ink); }
.marca-txt span { font-size: var(--t-sm); color: var(--ink-soft); }
</style>
