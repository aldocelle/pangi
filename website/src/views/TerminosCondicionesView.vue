<script setup>
import { ref } from 'vue';
import { CLINICA } from '../config.js';
import { useReveal } from '../useReveal.js';

const root = ref(null);
useReveal(root);

const ACTUALIZADO = '13 de agosto de 2026';
</script>

<template>
  <div ref="root" class="pagina">
    <section class="seccion pp-sec">
      <div class="wrap pp-wrap" data-reveal>
        <p class="pp-actualizado">Última actualización: {{ ACTUALIZADO }}</p>
        <h1>Términos y Condiciones</h1>

        <p>
          Estos términos rigen el uso del sitio web, la página de reservas y el portal de
          clientes de {{ CLINICA.nombre }}. Al reservar una hora o crear una cuenta, aceptas
          lo descrito aquí.
        </p>

        <h2>1. Qué ofrecemos</h2>
        <p>
          A través de estos canales digitales puedes conocer nuestros servicios, agendar una
          hora de atención y, con una cuenta, revisar el historial de citas y la ficha clínica
          de tu mascota. No reemplazan una urgencia: ante una emergencia, contacta directamente
          a la clínica por teléfono.
        </p>

        <h2>2. Cuentas de usuario</h2>
        <p>
          Eres responsable de la información que entregas al registrarte y de mantener tu
          contraseña en privado. Si sospechas que alguien más accedió a tu cuenta, avísanos a
          <a :href="`mailto:${CLINICA.email}`">{{ CLINICA.email }}</a> para revisar el acceso.
        </p>

        <h2>3. Reservas</h2>
        <p>
          Una reserva queda confirmada cuando recibes el correo de confirmación. Los horarios
          disponibles pueden cambiar por causas ajenas a la clínica (imprevistos del
          especialista, mantención de instalaciones); en ese caso te contactaremos para
          reagendar. Puedes cancelar o modificar tu hora desde el portal de clientes o
          escribiéndonos directamente.
        </p>

        <h2>4. Pagos</h2>
        <p>
          Algunos servicios requieren un pago para confirmar la hora: un abono del 50% o el
          valor completo, según la consulta. Ese cobro se procesa en línea con tarjeta a través
          de Getnet, en el paso final de la reserva; el detalle del monto se muestra antes de
          pagar. Si la hora exige pago y no se completa dentro del plazo indicado, la reserva
          se cancela automáticamente.
        </p>

        <h2>5. Uso aceptable</h2>
        <p>
          No está permitido usar estos canales para suplantar a otra persona, intentar acceder
          a cuentas ajenas, ni interferir con el funcionamiento del sitio o del sistema de
          reservas.
        </p>

        <h2>6. Datos personales</h2>
        <p>
          El tratamiento de tus datos se explica en la
          <router-link to="/privacidad">Política de Privacidad</router-link>, que forma parte de
          estos términos.
        </p>

        <h2>7. Cambios a estos términos</h2>
        <p>
          Si actualizamos estos términos, se reflejará en esta misma página con una nueva fecha
          de actualización arriba. El uso continuado de estos canales después de un cambio
          implica su aceptación.
        </p>

        <h2>8. Contacto</h2>
        <p>
          Ante cualquier duda sobre estos términos, escribe a
          <a :href="`mailto:${CLINICA.email}`">{{ CLINICA.email }}</a>.
        </p>
      </div>
    </section>
  </div>
</template>

<style scoped>
.pp-sec { padding: clamp(4rem, 10vw, 6rem) 0; }
.pp-wrap { max-width: 68ch; margin: 0 auto; }
.pp-actualizado { color: var(--ink-soft); font-size: 0.88rem; margin-bottom: 0.6rem; }
.pp-sec h1 { color: var(--azul); font-size: clamp(1.9rem, 4.4vw, 2.5rem); margin-bottom: 1.2rem; }
.pp-sec h2 { color: var(--azul); font-size: 1.25rem; margin: 2.2rem 0 0.8rem; }
.pp-sec p { color: var(--ink-soft); line-height: 1.7; margin-bottom: 0.9rem; }
.pp-sec a { color: var(--azul); font-weight: 600; }
</style>
