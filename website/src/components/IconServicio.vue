<script setup>
/* Set de iconos monolineales para los servicios. Una sola grilla (24×24),
   un solo grosor de trazo y currentColor: la uniformidad es estructural,
   no depende de que cada icono se dibuje "parecido".

   El catálogo de servicios viene del backend y sus nombres cambian sin
   aviso, así que el match es tolerante (sin tildes, sin mayúsculas, por
   palabra clave) y siempre hay un fallback — un servicio nuevo nunca
   aparece sin icono. */
import { computed } from 'vue';

const props = defineProps({
  nombre: { type: String, default: '' },
});

/* Cada entrada: [palabras que deben aparecer en el nombre, clave del icono].
   Se evalúa en orden, así que lo más específico va primero. */
const REGLAS = [
  [['dermatolog'], 'piel'],
  [['telemedicina'], 'pantalla'],
  [['domicilio'], 'casa'],
  [['esteriliz'], 'escudo'],
  [['peluqueria', 'estilista', 'grooming'], 'tijeras'],
  [['ecograf', 'radiograf', 'imagen'], 'escaner'],
  [['farmacia', 'pet shop', 'petshop'], 'pastilla'],
  [['consulta', 'general', 'urgencia'], 'estetoscopio'],
  [['vacuna'], 'jeringa'],
];

function normalizar(s) {
  return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

const clave = computed(() => {
  const n = normalizar(props.nombre);
  for (const [palabras, icono] of REGLAS) {
    if (palabras.some((p) => n.includes(p))) return icono;
  }
  return 'huella';
});
</script>

<template>
  <svg
    class="ic" viewBox="0 0 24 24" width="24" height="24"
    fill="none" stroke="currentColor" stroke-width="1.6"
    stroke-linecap="round" stroke-linejoin="round"
    aria-hidden="true" focusable="false"
  >
    <!-- Dermatología: lupa sobre la piel -->
    <template v-if="clave === 'piel'">
      <circle cx="10.5" cy="10.5" r="6.5" />
      <path d="M15.4 15.4 21 21" />
      <path d="M7.5 11.5c1-1.2 2-1.2 3 0s2 1.2 3 0" />
    </template>

    <!-- Consultas: estetoscopio -->
    <template v-else-if="clave === 'estetoscopio'">
      <path d="M5 3v6a4 4 0 0 0 8 0V3" />
      <path d="M4 3h2M12 3h2" />
      <path d="M9 13v2a5 5 0 0 0 10 0v-1.5" />
      <circle cx="19" cy="11" r="2.2" />
    </template>

    <!-- Esterilizaciones: escudo con corazón -->
    <template v-else-if="clave === 'escudo'">
      <path d="M12 21c-4.2-1.6-7-4.4-7-8.6V6.2l7-3 7 3v6.2c0 4.2-2.8 7-7 8.6Z" />
      <path d="M12 14.6c-2-1.2-3-2.1-3-3.3a1.6 1.6 0 0 1 3-.8 1.6 1.6 0 0 1 3 .8c0 1.2-1 2.1-3 3.3Z" />
    </template>

    <!-- Peluquería: tijeras -->
    <template v-else-if="clave === 'tijeras'">
      <circle cx="6" cy="18" r="2.6" />
      <circle cx="6" cy="6" r="2.6" />
      <path d="M8.1 7.4 20 19" />
      <path d="M8.1 16.6 20 5" />
      <path d="m11.5 12 1.6 1.6" />
    </template>

    <!-- Ecografías y radiografías: marco de escaneo -->
    <template v-else-if="clave === 'escaner'">
      <path d="M3 8V6a3 3 0 0 1 3-3h2" />
      <path d="M16 3h2a3 3 0 0 1 3 3v2" />
      <path d="M21 16v2a3 3 0 0 1-3 3h-2" />
      <path d="M8 21H6a3 3 0 0 1-3-3v-2" />
      <path d="M7 12h10" />
    </template>

    <!-- Farmacia: pastilla -->
    <template v-else-if="clave === 'pastilla'">
      <path d="M10.3 20.3 20.3 10.3a4.7 4.7 0 0 0-6.6-6.6L3.7 13.7a4.7 4.7 0 0 0 6.6 6.6Z" />
      <path d="M8.4 8.4 15.6 15.6" />
    </template>

    <!-- Telemedicina: pantalla -->
    <template v-else-if="clave === 'pantalla'">
      <rect x="2.5" y="3.5" width="19" height="13" rx="2.4" />
      <path d="M8.5 21h7" />
      <path d="M12 16.5V21" />
      <path d="M9 10h1.8M13.2 10H15M11 8v4" />
    </template>

    <!-- Atención a domicilio: casa -->
    <template v-else-if="clave === 'casa'">
      <path d="M3.5 10.4 12 3.6l8.5 6.8V19a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2Z" />
      <path d="M9.5 21v-6h5v6" />
    </template>

    <!-- Vacunas: jeringa -->
    <template v-else-if="clave === 'jeringa'">
      <path d="m14 5 5 5" />
      <path d="M18.5 3.5 20.5 5.5" />
      <path d="m16.5 6.5-9 9L4 20l4.5-3.5 9-9" />
      <path d="m10 10 1.8 1.8M12.5 7.5l1.8 1.8" />
    </template>

    <!-- Fallback: huella -->
    <template v-else>
      <ellipse cx="6.4" cy="10.6" rx="2.1" ry="2.5" />
      <ellipse cx="10.6" cy="6.9" rx="2.1" ry="2.6" />
      <ellipse cx="15" cy="6.9" rx="2.1" ry="2.6" />
      <ellipse cx="18.4" cy="10.9" rx="2.1" ry="2.5" />
      <path d="M12.4 13.2c-2.7 0-4.8 1.9-4.8 4.2 0 1.6 1.1 2.6 2.6 2.6.9 0 1.5-.4 2.2-.4s1.3.4 2.2.4c1.5 0 2.6-1 2.6-2.6 0-2.3-2.1-4.2-4.8-4.2Z" />
    </template>
  </svg>
</template>

<style scoped>
.ic { display: block; width: 100%; height: 100%; }
</style>
