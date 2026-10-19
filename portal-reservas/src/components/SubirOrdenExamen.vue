<script setup>
/* Adjuntar la orden del veterinario tratante a una reserva ya creada.

   Vive en un componente y no dentro de `ReservarView.vue` porque hace falta en
   más de un sitio, y en sitios que el mismo cliente puede ver en distinto
   orden: el paso de pago (antes de irse a Getnet), la pantalla de
   confirmación (servicios sin pago) y la vuelta del pago (`PagoView`), para
   quien pagó primero y nunca vio los otros dos.

   Deliberadamente NO bloquea nada. La reserva ya existe cuando esto aparece —
   mismo criterio que el abono (§5 del CLAUDE.md): la hora queda tomada y lo
   que falta se persigue después. Exigir el archivo para poder reservar hace
   perder la hora por un trámite que la clínica igual puede resolver por
   WhatsApp. */
import { ref } from 'vue';
import { api, apiError } from '../api';
import BaseIcon from './BaseIcon.vue';

const props = defineProps({
  citaId: { type: String, required: true },
  /* Solo para la radiografía el archivo sirve además para presupuestar, así
     que el texto cambia: en las otras dos es un requisito de la prestación. */
  paraPresupuesto: { type: Boolean, default: false },
});

const archivo = ref(null);
const subiendo = ref(false);
const listo = ref(false);
const nombreSubido = ref('');
const error = ref('');

const MAX_MB = 10;

function elegir(e) {
  error.value = '';
  const f = e.target.files?.[0] || null;
  /* Se avisa acá y no solo al llegar del backend: subir 10 MB por una red
     móvil para que recién entonces te digan que no cabía es la peor versión
     de este error. El backend igual lo valida —esto es comodidad, no la
     defensa—. */
  if (f && f.size > MAX_MB * 1024 * 1024) {
    error.value = `El archivo no puede superar ${MAX_MB} MB.`;
    archivo.value = null;
    e.target.value = '';
    return;
  }
  archivo.value = f;
}

async function subir() {
  if (!archivo.value) return;
  subiendo.value = true;
  error.value = '';
  try {
    const datos = new FormData();
    datos.append('archivo', archivo.value);
    const { data } = await api.post(`/citas/${props.citaId}/orden-examen`, datos);
    nombreSubido.value = data.nombre_original;
    listo.value = true;
    archivo.value = null;
  } catch (e) {
    error.value = apiError(e, 'No pudimos subir la orden. Intenta de nuevo.');
  } finally {
    subiendo.value = false;
  }
}
</script>

<template>
  <div class="orden" :class="{ 'orden--listo': listo }">
    <template v-if="listo">
      <p class="orden-ok">
        <BaseIcon name="check" :size="16" /> Recibimos tu orden (<strong>{{ nombreSubido }}</strong>).
        Queda en la ficha de tu mascota.
      </p>
      <button type="button" class="orden-otra" @click="listo = false">
        Subir otra en su lugar
      </button>
    </template>

    <template v-else>
      <p class="orden-titulo"><BaseIcon name="doc" :size="16" /> Adjunta la orden de examen</p>
      <p class="orden-texto">
        <template v-if="paraPresupuesto">
          Esta prestación necesita la indicación de tu veterinario. Si la
          adjuntas ahora, te confirmamos el presupuesto total antes de la hora.
        </template>
        <template v-else>
          Esta prestación necesita la indicación de tu veterinario tratante.
          Puedes adjuntarla ahora o traerla el día de la atención.
        </template>
      </p>

      <input
        id="orden-archivo"
        class="orden-input"
        type="file"
        accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.webp"
        @change="elegir"
      />
      <p class="orden-nota">PDF, Word, texto o imagen. Hasta {{ MAX_MB }} MB.</p>

      <button
        type="button"
        class="btn btn-ghost orden-btn"
        :disabled="!archivo || subiendo"
        @click="subir"
      >
        {{ subiendo ? 'Subiendo…' : 'Adjuntar orden' }}
      </button>

      <p v-if="error" class="orden-error">{{ error }}</p>
    </template>
  </div>
</template>

<style scoped>
.orden {
  border: 1px dashed var(--orden-borde);
  border-radius: 12px;
  padding: 1rem 1.1rem;
  margin: 0 auto 1.1rem;
  max-width: 480px;
  text-align: left;
  background: var(--surface);
}
.orden--listo { border-style: solid; border-color: var(--accent); }
.orden-titulo { font-weight: 700; font-size: var(--t-base); margin: 0 0 0.35rem; }
.orden-texto,
.orden-nota,
.orden-ok { font-size: var(--t-sm); line-height: 1.5; margin: 0 0 0.6rem; }
.orden-texto { color: var(--ink-soft); }
.orden-nota { color: var(--ink-soft); font-size: var(--t-micro); }
.orden-input { display: block; width: 100%; margin-bottom: 0.4rem; font-size: var(--t-sm); }
.orden-btn { width: 100%; }
.orden-ok { margin-bottom: 0.4rem; }
.orden-otra {
  background: none; border: 0; padding: 0; cursor: pointer;
  font-size: var(--t-micro); color: var(--ink-soft); text-decoration: underline;
}
.orden-error { color: #b3261e; font-size: var(--t-sm); margin: 0.6rem 0 0; }
</style>
