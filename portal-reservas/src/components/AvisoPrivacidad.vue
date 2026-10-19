<script setup>
/* Aviso de la Política de Privacidad para quien no tiene ningún registro.

   Son los tutores que vinieron de ACVet con su historial y sin constancia de
   haber visto la política (CLAUDE.md §14): el backend los marca con
   `privacidad_pendiente` en /auth/me.

   NO bloquea nada, y es a propósito. La base legal de tratar sus datos es la
   atención que ya reciben, no este clic; forzar una casilla simularía una
   elección que no existe. Por eso es una tarjeta chica en la esquina y no un
   modal: se puede seguir reservando, mirar las mascotas o pedir los datos sin
   tocarla. Mientras no diga «Entendido» vuelve a aparecer en la próxima visita;
   «Ahora no» solo la esconde durante esta.

   Los dos botones pesan parecido: un «Entendido» enorme junto a un «Ahora no»
   apagado dirigiría la respuesta. */
import { ref } from 'vue';
import { useAuthStore } from '../stores/auth.js';
import { SITIO_URL } from '../config.js';
import BaseIcon from './BaseIcon.vue';

const CLAVE = 'aviso_privacidad_pospuesto';
const auth = useAuthStore();

function yaPospuesto() {
  try { return sessionStorage.getItem(CLAVE) === '1'; } catch { return false; }
}

const oculto = ref(yaPospuesto());
const guardando = ref(false);
const fallo = ref(false);

function ahoraNo() {
  oculto.value = true;
  try { sessionStorage.setItem(CLAVE, '1'); } catch { /* sin almacenamiento: vuelve al recargar */ }
}

async function entendido() {
  guardando.value = true;
  fallo.value = false;
  try {
    await auth.aceptarPrivacidad();
  } catch {
    fallo.value = true;
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <Transition name="aviso">
    <section v-if="!oculto" class="aviso" aria-labelledby="aviso-priv-titulo">
      <div class="aviso-icono" aria-hidden="true"><BaseIcon name="shield" :size="20" /></div>
      <div class="aviso-cuerpo">
        <h2 id="aviso-priv-titulo">Tus datos, a la vista</h2>
        <p>
          Con la nueva ley de datos personales actualizamos nuestra Política de Privacidad.
          Ahí explicamos qué guardamos de ti y de tu mascota, y cómo pedir verlo,
          corregirlo o eliminarlo.
        </p>
        <p v-if="fallo" class="aviso-error" role="alert">
          No pudimos guardarlo. Inténtalo de nuevo en un momento.
        </p>
        <div class="aviso-acciones">
          <button type="button" class="btn btn-primary btn-sm" :disabled="guardando" @click="entendido">
            {{ guardando ? 'Guardando…' : 'Entendido' }}
          </button>
          <button type="button" class="btn btn-ghost btn-sm" :disabled="guardando" @click="ahoraNo">
            Ahora no
          </button>
          <a :href="`${SITIO_URL}/privacidad`" target="_blank" rel="noopener" class="aviso-enlace">
            Leer la política
          </a>
        </div>
      </div>
    </section>
  </Transition>
</template>

<style scoped>
/* z-index 150: sobre la barra superior (sticky, 100) y bajo el chat (200), que
   es lo que la persona abrió a propósito. */
.aviso {
  position: fixed; left: 1rem; bottom: 1rem; z-index: 150;
  display: flex; gap: 0.8rem; align-items: flex-start;
  width: min(23rem, calc(100vw - 2rem));
  padding: 1rem 1.1rem;
  background: var(--surface); color: var(--ink-soft);
  border: 1px solid var(--line); border-radius: var(--r-md);
  box-shadow: var(--shadow-lg);
}
.aviso-icono {
  flex: none; display: grid; place-items: center;
  width: 2.4rem; height: 2.4rem; border-radius: var(--r-full);
  background: var(--accent-tint); color: var(--accent-strong);
}
.aviso-cuerpo { min-width: 0; }
.aviso h2 {
  margin: 0 0 0.25rem; font-family: var(--font-display);
  font-size: var(--t-base); font-weight: 600; color: var(--ink);
}
.aviso p { margin: 0 0 0.75rem; font-size: var(--t-sm); line-height: 1.5; }
.aviso .aviso-error { color: var(--danger); margin-top: -0.25rem; }
.aviso-acciones { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.6rem; }
.aviso-enlace {
  font-size: var(--t-sm); color: var(--accent-strong);
  text-decoration: underline; text-underline-offset: 2px;
}
.aviso-enlace:hover { color: var(--ink); }

.aviso-enter-active, .aviso-leave-active { transition: opacity 0.25s var(--ease), transform 0.25s var(--ease); }
.aviso-enter-from, .aviso-leave-to { opacity: 0; transform: translateY(0.75rem); }
@media (prefers-reduced-motion: reduce) {
  .aviso-enter-active, .aviso-leave-active { transition: opacity 0.01s; }
  .aviso-enter-from, .aviso-leave-to { transform: none; }
}

/* En el celular ocupa el ancho y sube lo justo para no tapar el botón del chat,
   que vive abajo a la derecha. */
@media (max-width: 640px) {
  .aviso { left: 1rem; right: 1rem; width: auto; bottom: 5rem; }
}
</style>
