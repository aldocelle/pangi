<script setup>
/* Consentimiento previo para la analítica (ley 21.719).

   NO aparece si no hay nada que consentir. `analiticaDisponible()` es falso
   mientras no exista `VITE_GA_MEASUREMENT_ID`, que es el estado de hoy: el
   sitio no pone una sola cookie propia ni de terceros. Un aviso de cookies en
   un sitio sin cookies no protege a nadie — enseña a aceptar sin leer, que es
   exactamente lo contrario de lo que la ley busca.

   Las dos opciones pesan lo mismo a propósito. Un «Aceptar» grande y verde
   junto a un «Rechazar» gris y chico es una elección dirigida, y un
   consentimiento arrancado así no es libre. Acá los dos botones son del mismo
   tamaño y con el mismo peso visual; el acento se reserva para el enlace a la
   política, que es lo que conviene que la gente abra. */
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { analiticaDisponible, decisionGuardada, aceptarAnalitica, rechazarAnalitica } from '../analytics.js';

const router = useRouter();
const visible = ref(analiticaDisponible() && decisionGuardada() === null);

function aceptar() {
  aceptarAnalitica(router);
  visible.value = false;
}

function rechazar() {
  rechazarAnalitica();
  visible.value = false;
}
</script>

<template>
  <!-- role="dialog" y no un banner mudo: es una decisión que se le pide a la
       persona, y quien navega con lector de pantalla tiene que enterarse. No
       lleva foco atrapado ni bloquea el sitio — el contenido sigue siendo
       legible y usable con el aviso abierto, que es lo correcto cuando la
       opción por defecto (no rastrear) ya es la que protege. -->
  <div
    v-if="visible"
    class="aviso"
    role="dialog"
    aria-labelledby="aviso-analitica-titulo"
  >
    <div class="aviso-texto">
      <p id="aviso-analitica-titulo" class="aviso-titulo">Sobre las estadísticas del sitio</p>
      <p>
        Nos ayuda saber qué páginas se visitan, para mejorar el sitio. Solo lo medimos
        si nos das permiso, y puedes cambiar de opinión cuando quieras desde la
        <RouterLink to="/privacidad">Política de Privacidad</RouterLink>.
        Reservar una hora y escribirnos funcionan igual decidas lo que decidas.
      </p>
    </div>
    <div class="aviso-botones">
      <button type="button" class="aviso-btn" @click="rechazar">No medir</button>
      <button type="button" class="aviso-btn" @click="aceptar">Permitir</button>
    </div>
  </div>
</template>

<style scoped>
.aviso {
  position: fixed;
  left: 16px;
  right: 16px;
  bottom: 16px;
  z-index: 70;            /* por encima del contenido, bajo el FAB de WhatsApp (60)
                             no: este tiene que ganarle, porque pide una decisión */
  max-width: 620px;
  margin: 0 auto;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 20px;
  padding: 16px 18px;
  background: var(--color-white);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  box-shadow: 0 8px 28px var(--color-shadow);
}

.aviso-texto { flex: 1 1 280px; }

.aviso-titulo {
  margin: 0 0 4px;
  font-weight: 700;
  color: var(--color-text-main);
  font-size: 0.95rem;
}

.aviso-texto p {
  margin: 0;
  font-size: 0.85rem;
  line-height: 1.5;
  color: var(--color-text-secondary);
}

.aviso-texto a { color: var(--color-primary-hover); }

.aviso-botones {
  display: flex;
  gap: 10px;
  flex: 0 0 auto;
}

/* Los dos botones, idénticos: ver la nota del <script>. */
.aviso-btn {
  flex: 1 1 auto;
  min-width: 104px;
  padding: 9px 16px;
  font: inherit;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--color-text-main);
  background: var(--color-white);
  border: 1.5px solid var(--color-border);
  border-radius: 8px;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}

.aviso-btn:hover {
  border-color: var(--color-primary-hover);
  background: var(--color-bg-light);
}

.aviso-btn:focus-visible {
  outline: 2px solid var(--color-primary-hover);
  outline-offset: 2px;
}

@media (max-width: 560px) {
  .aviso { flex-direction: column; align-items: stretch; }
  .aviso-botones { width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
  .aviso-btn { transition: none; }
}
</style>
