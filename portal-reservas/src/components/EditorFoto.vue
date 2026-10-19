<script setup>
/* Foto de perfil editable: la de una mascota o la del tutor.

   El componente no sabe a dónde se sube: recibe `subir(file)` y `quitar()`
   como funciones y se ocupa solo de lo que es igual en los dos casos —elegir
   el archivo, prepararlo (prepararFoto: recorte cuadrado y sin EXIF), mostrar
   que está trabajando y el error si lo hubo. */
import { ref } from 'vue';
import { prepararFoto, apiError } from '../api.js';
import BaseIcon from './BaseIcon.vue';

const props = defineProps({
  src: { type: String, default: null },
  /* Lo que se ve sin foto: las iniciales del tutor. Para la mascota no hace
     falta, porque `src` ya trae la foto genérica por especie. */
  iniciales: { type: String, default: '' },
  tieneFoto: { type: Boolean, default: false },
  nombre: { type: String, required: true },
  size: { type: Number, default: 110 },
  subir: { type: Function, required: true },
  quitar: { type: Function, required: true },
  /* Para una foto chica dentro de una tarjeta (Mis Mascotas): cámara más
     chica, que no tape media cara, y sin «Quitar foto», que agregaría una
     línea solo a las tarjetas con foto y las dejaría de distinto alto. Quitar
     sigue en la ficha. */
  compacto: { type: Boolean, default: false },
});

const input = ref(null);
const trabajando = ref(false);
const error = ref('');

const TIPOS = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

async function elegido(e) {
  const archivo = e.target.files?.[0];
  e.target.value = '';
  if (!archivo) return;
  error.value = '';
  if (archivo.type && !TIPOS.includes(archivo.type)) {
    error.value = 'Usa una foto JPG, PNG o WebP.';
    return;
  }
  trabajando.value = true;
  try {
    let listo;
    try {
      listo = await prepararFoto(archivo);
    } catch {
      // HEIC en un navegador que no lo decodifica, o un archivo dañado.
      throw new Error('No pudimos leer esa imagen. Prueba con otra foto (JPG o PNG).');
    }
    await props.subir(listo);
  } catch (err) {
    error.value = err?.response ? apiError(err, 'No se pudo subir la foto.') : (err.message || 'No se pudo subir la foto.');
  } finally {
    trabajando.value = false;
  }
}

async function borrar() {
  error.value = '';
  trabajando.value = true;
  try {
    await props.quitar();
  } catch (err) {
    error.value = apiError(err, 'No se pudo quitar la foto.');
  } finally {
    trabajando.value = false;
  }
}
</script>

<template>
  <div class="ef" :class="{ compacto }">
    <div class="ef-marco" :style="{ width: `${size}px`, height: `${size}px` }" :class="{ ocupado: trabajando }">
      <img v-if="src" class="ef-img" :src="src" alt="" />
      <span v-else class="ef-iniciales" :style="{ fontSize: `${Math.round(size / 2.8)}px` }">{{ iniciales }}</span>
      <span v-if="trabajando" class="ef-cargando" aria-hidden="true"></span>
      <button
        type="button" class="ef-camara" :disabled="trabajando"
        :aria-label="tieneFoto ? `Cambiar la foto de ${nombre}` : `Agregar una foto de ${nombre}`"
        :title="tieneFoto ? 'Cambiar foto' : 'Agregar foto'"
        @click="input?.click()"
      >
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z" /><circle cx="12" cy="13" r="3.5" /></svg>
      </button>
    </div>
    <input ref="input" type="file" accept="image/*" hidden @change="elegido" />
    <button v-if="tieneFoto && !trabajando && !compacto" type="button" class="ef-quitar" @click="borrar">Quitar foto</button>
    <p v-if="error" class="ef-error" role="alert">{{ error }}</p>
    <span class="sr-only" aria-live="polite">{{ trabajando ? 'Subiendo foto…' : '' }}</span>
  </div>
</template>

<style scoped>
.ef { display: flex; flex-direction: column; align-items: center; gap: 0.35rem; flex: none; }
.ef-marco { position: relative; border-radius: 50%; flex: none; }
.ef-img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; background: var(--brand-tint); display: block; box-shadow: 0 0 0 4px var(--surface), 0 6px 18px rgba(38, 50, 110, 0.14); }
.ef-iniciales {
  width: 100%; height: 100%; border-radius: 50%; display: grid; place-items: center;
  background: linear-gradient(135deg, var(--brand) 0%, #0a5652 100%); color: #fff; font-weight: 700;
  box-shadow: 0 0 0 4px var(--surface), 0 6px 18px rgba(38, 50, 110, 0.18);
}
.ef-marco.ocupado .ef-img, .ef-marco.ocupado .ef-iniciales { filter: brightness(0.75); }
.ef-cargando {
  position: absolute; inset: 0; margin: auto; width: 32px; height: 32px; border-radius: 50%;
  border: 3px solid rgba(255, 255, 255, 0.45); border-top-color: #fff; animation: girar 0.8s linear infinite;
}
@keyframes girar { to { transform: rotate(360deg); } }
.ef-camara {
  position: absolute; right: 2%; bottom: 2%;
  width: 36px; height: 36px; border-radius: 50%; display: grid; place-items: center;
  background: var(--accent); color: #fff; border: 3px solid var(--surface);
  box-shadow: 0 4px 12px rgba(var(--accent-rgb), 0.35);
  transition: background var(--dur-fast) var(--ease), transform var(--dur-fast) var(--ease);
}
.ef-camara:hover:not(:disabled) { background: var(--accent-strong); transform: scale(1.06); }
.ef-camara:disabled { opacity: 0.6; cursor: progress; }
/* 28 px: sigue sobre el mínimo de 24 px para tocar con el dedo. */
.compacto .ef-camara { width: 28px; height: 28px; right: -6%; bottom: -6%; border-width: 2px; }
.compacto .ef-camara svg { width: 14px; height: 14px; }
.compacto .ef-cargando { width: 22px; height: 22px; border-width: 2px; }
.ef-quitar { font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft); padding: 0.15rem 0.4rem; border-radius: var(--r-sm); }
.ef-quitar:hover { color: var(--danger); text-decoration: underline; }
.ef-error { font-size: var(--t-micro); color: var(--danger); max-width: 22ch; text-align: center; margin: 0; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
@media (prefers-reduced-motion: reduce) { .ef-cargando { animation-duration: 2.4s; } }
</style>
