<script setup>
import { RESERVA_URL, CLINICA } from './config.js';
import TheHeader from './components/TheHeader.vue';
import TheFooter from './components/TheFooter.vue';
import WhatsappFab from './components/WhatsappFab.vue';
import AvisoAnalitica from './components/AvisoAnalitica.vue';
</script>

<template>
  <TheHeader :reserva-url="RESERVA_URL" :clinica="CLINICA" />
  <main>
    <RouterView />
  </main>
  <TheFooter :clinica="CLINICA" :reserva-url="RESERVA_URL" />
  <WhatsappFab :clinica="CLINICA" />
  <AvisoAnalitica />
</template>
