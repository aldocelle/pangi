<script setup>
import { ref } from 'vue';
import { RESERVA_URL, CLINICA, TESTIMONIOS } from '../config.js';
import { useReveal } from '../useReveal.js';

import HeroSection from '../components/HeroSection.vue';
import QuienesSomos from '../components/QuienesSomos.vue';
import ServiciosSection from '../components/ServiciosSection.vue';
import TestimoniosSection from '../components/TestimoniosSection.vue';
import CtaAgendar from '../components/CtaAgendar.vue';
import ContactoSection from '../components/ContactoSection.vue';

const root = ref(null);
useReveal(root);
</script>

<template>
  <div ref="root">
    <HeroSection :reserva-url="RESERVA_URL" :clinica="CLINICA" />
    <QuienesSomos :reserva-url="RESERVA_URL" />
    <ServiciosSection :reserva-url="RESERVA_URL" />
    <!-- Después de servicios y antes de contacto: la prueba social llega
         cuando ya se sabe qué se ofrece y justo antes de pedir el dato. -->
    <TestimoniosSection :testimonios="TESTIMONIOS" />
    <ContactoSection :clinica="CLINICA" />
    <!-- Último antes del pie, igual que en las otras cinco páginas: es la
         acción con la que cerramos, y conviene que esté siempre en el mismo
         lugar para que se encuentre sin buscarla. -->
    <CtaAgendar :reserva-url="RESERVA_URL" />
  </div>
</template>
