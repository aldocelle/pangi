<script setup>
/* Cierre común de todas las páginas: la banda azul con la invitación a
   reservar. Vive en un componente y no copiado en cada vista para que el
   texto y el estilo se cambien en un solo lugar — apareciendo seis veces,
   una copia divergente se nota enseguida.

   Nació en ServiciosView.vue; el resto de las páginas lo adoptó después. */
defineProps({
  reservaUrl: { type: String, required: true },
  titulo: { type: String, default: '¿Listo para agendar?' },
  texto: { type: String, default: 'Reserva la atención de tu mascota en línea, disponible 24/7.' },
  /* Botones a medida: [{ label, href }]. Sin esto va el único "Reserva de
     hora" hacia reservaUrl, que es lo que necesitan cinco de las seis
     páginas. Una página con varias vías (por ejemplo, a domicilio, en la
     clínica y telemedicina) pasa cada una a un WhatsApp con el mensaje ya
     escrito. */
  acciones: { type: Array, default: () => [] },
});
</script>

<template>
  <section class="cta" :class="{ 'cta-multi': acciones.length }">
    <div class="wrap cta-in">
      <div>
        <!-- Slot con el prop de texto plano como valor por defecto: las
             páginas que solo pasan `titulo` (la mayoría) no cambian en
             nada; una página puede usarlo para resaltar parte del
             título con .hero-enfasis, igual que en los hero del sitio. -->
        <h2><slot name="titulo">{{ titulo }}</slot></h2>
        <p v-if="texto">{{ texto }}</p>
      </div>
      <div class="cta-acciones">
        <template v-if="acciones.length">
          <a
            v-for="a in acciones" :key="a.href"
            :href="a.href" target="_blank" rel="noopener"
            class="btn btn-primary btn-lg"
          >{{ a.label }}</a>
        </template>
        <a v-else :href="reservaUrl" target="_blank" rel="noopener" class="btn btn-primary btn-lg">
          Reserva de hora
        </a>
      </div>
    </div>
  </section>
</template>

<style scoped>
.cta { background: var(--azul); color: #fff; padding: clamp(2.5rem, 5vw, 3.5rem) 0; }
.cta-in { display: flex; align-items: center; justify-content: space-between; gap: 1.5rem; flex-wrap: wrap; }
/* Los botones se envuelven entre sí antes que empujar al texto: con tres
   opciones no caben en una línea junto al titular. */
.cta-acciones { display: flex; gap: 0.7rem; flex-wrap: wrap; }

/* Con varias vías, los botones bajan a su propia fila centrada: puestos a
   la derecha del titular quedaban apretados contra el borde, y ninguna de
   las tres opciones debe verse como la principal. */
.cta-multi .cta-acciones { flex-basis: 100%; justify-content: center; }

/* Destello que recorre los botones, el mismo recurso que el de reserva del
   header (ver cta-destello en TheHeader.vue): cruza en poco más de un
   latido y espera los otros cinco, porque un brillo sostenido en un botón
   fijo cansa la vista. El ciclo se cuenta en --latido —la unidad de tempo
   del sitio, 833ms, un latido a 72 por minuto— y no en segundos sueltos,
   para que no se desalinee si ese pulso cambia.

   Los tres van escalonados un latido entre sí: la luz cruza de izquierda a
   derecha como un solo gesto que recorre las opciones, en vez de tres
   destellos simultáneos que compiten entre ellos. */
.cta-multi .cta-acciones .btn { position: relative; overflow: hidden; }
.cta-multi .cta-acciones .btn::after {
  content: ''; position: absolute; inset: 0; pointer-events: none;
  background: linear-gradient(105deg,
    transparent 35%, rgba(255, 255, 255, 0.38) 50%, transparent 65%);
  transform: translateX(-120%);
  animation: cta-brillo calc(var(--latido) * 6) cubic-bezier(0.45, 0.05, 0.55, 0.95) infinite;
}
.cta-multi .cta-acciones .btn:nth-child(2)::after { animation-delay: var(--latido); }
.cta-multi .cta-acciones .btn:nth-child(3)::after { animation-delay: calc(var(--latido) * 2); }
@keyframes cta-brillo {
  0%        { transform: translateX(-120%); }
  22%, 100% { transform: translateX(120%); }
}
@media (prefers-reduced-motion: reduce) {
  .cta-multi .cta-acciones .btn::after { animation: none; content: none; }
}
.cta h2 { font-size: clamp(1.5rem, 3.5vw, 2rem); }
.cta p { color: rgba(255, 255, 255, 0.88); margin-top: 0.3rem; line-height: 1.65; }
.btn-lg { padding: 0.95em 1.9em; font-size: 1.02rem; }
</style>
