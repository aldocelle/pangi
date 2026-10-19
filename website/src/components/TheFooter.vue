<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { RouterLink } from 'vue-router';
import { HORARIO } from '../config.js';
import { api } from '../api.js';
import { agruparServicios } from '../agrupar.js';

const props = defineProps({
  clinica: { type: Object, required: true },
  reservaUrl: { type: String, required: true },
});
const anio = new Date().getFullYear();

/* ── Redes ──
   Glifos de cada marca en línea (sin dependencias ni peticiones extra).
   El color de cada plataforma solo aparece al pasar el cursor: en reposo
   el footer se mantiene en la paleta del sitio. */
const SOCIALES = computed(() => [
  {
    nombre: 'Instagram',
    url: props.clinica.instagram,
    color: '#e1306c',
    path: 'M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41-.56-.22-.96-.48-1.38-.9-.42-.42-.68-.82-.9-1.38-.16-.42-.36-1.06-.41-2.23-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41 1.27-.06 1.65-.07 4.85-.07M12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.31-1.46.72-2.13 1.38C1.35 2.68.94 3.35.63 4.14.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.31.79.72 1.46 1.38 2.13.67.66 1.34 1.07 2.13 1.38.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56.79-.31 1.46-.72 2.13-1.38.66-.67 1.07-1.34 1.38-2.13.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91-.31-.79-.72-1.46-1.38-2.13C21.32 1.35 20.65.94 19.86.63 19.1.33 18.22.13 16.95.07 15.67.01 15.26 0 12 0m0 5.84A6.16 6.16 0 1 0 18.16 12 6.16 6.16 0 0 0 12 5.84M12 16a4 4 0 1 1 4-4 4 4 0 0 1-4 4m6.41-11.85a1.44 1.44 0 1 0 1.44 1.44 1.44 1.44 0 0 0-1.44-1.44',
  },
  {
    nombre: 'Facebook',
    url: props.clinica.facebook,
    color: '#1877f2',
    path: 'M24 12.07A12 12 0 1 0 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.54-4.69 1.32 0 2.7.24 2.7.24v2.96h-1.52c-1.5 0-1.96.93-1.96 1.88v2.27h3.34l-.53 3.49h-2.81V24A12 12 0 0 0 24 12.07',
  },
  {
    nombre: 'WhatsApp',
    url: `https://wa.me/${props.clinica.telefonoPlano}`,
    color: '#25d366',
    // Blanco sobre este verde da 1.98:1: no alcanza ni el 3:1 que pide un
    // ícono. Mismo criterio que ya usa ContactoSection.vue para esta misma
    // red — ver btn-wa en el historial.
    textoHover: 'var(--azul-oscuro)',
    path: 'M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.47-1.75-1.64-2.05-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.87 1.22 3.07c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.69.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35M12.05 21.79h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.89 9.9-9.89 2.64 0 5.12 1.03 6.99 2.9a9.82 9.82 0 0 1 2.89 6.99c0 5.45-4.44 9.89-9.89 9.89M20.52 3.49A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.6 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.69 1.45h.01c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.17-3.49-8.42',
  },
]);

/* ── Estado real de atención ──
   El horario ya está en pantalla; saber si están abiertos ahora mismo es
   la pregunta que el visitante trae. Se resuelve con la hora del propio
   dispositivo (clínica y público están en Chile). */
const DIA_A_FILA = { 0: 'Domingo', 1: 'Lunes a Viernes', 2: 'Lunes a Viernes', 3: 'Lunes a Viernes', 4: 'Lunes a Viernes', 5: 'Lunes a Viernes', 6: 'Sábado' };
const ahora = ref(new Date());
let reloj = null;
onMounted(() => { reloj = setInterval(() => (ahora.value = new Date()), 60000); });
onUnmounted(() => clearInterval(reloj));

const filaDeHoy = computed(() => DIA_A_FILA[ahora.value.getDay()]);

const enMinutos = (hhmm) => {
  const [h, m] = hhmm.split(':');
  return +h * 60 + +m;
};

// Basta con estar dentro de cualquiera de los tramos del día: entre el de la
// mañana y el de la tarde hay un cierre de por medio, y ahí toca "Cerrado".
const abierto = computed(() => {
  const fila = HORARIO.find((h) => h.dias === filaDeHoy.value);
  if (!fila) return false;
  const min = ahora.value.getHours() * 60 + ahora.value.getMinutes();
  return fila.tramos.some(([desde, hasta]) => min >= enMinutos(desde) && min < enMinutos(hasta));
});

// Enlaces a las páginas del sitio.
const ENLACES = [
  { label: 'Equipo', to: '/equipo' },
  { label: 'Servicios', to: '/servicios' },
  { label: 'Contacto', to: '/contacto' },
];

// Misma fuente que ServiciosView.vue (backend real) — antes era un tercer
// array hardcodeado con nombres que ya no coincidían con los otros dos.
const servicios = ref([]);
onMounted(async () => {
  try {
    const { data } = await api.get('/catalogo/servicios');
    // Se agrupa ANTES de recortar a 6: si no, el control podría ocupar uno de
    // los seis sitios del pie duplicando a la consulta.
    servicios.value = agruparServicios(data).slice(0, 6);
  } catch {
    servicios.value = []; // el footer no es crítico: si falla, esa columna queda vacía
  }
});
</script>

<template>
  <footer class="footer">
    <div class="wrap footer-in">
      <!-- Marca + contacto -->
      <div class="col col-brand">
        <div class="footer-brand">
          <strong>{{ clinica.nombre }}</strong>
          <!-- `--turno` escalona el encendido: sin él los tres parpadean a la
               vez y el pie titila. Con turnos la luz los recorre. -->
          <ul class="footer-social">
            <li v-for="(s, i) in SOCIALES.filter((r) => r.url)" :key="s.nombre">
              <a
                :href="s.url" target="_blank" rel="noopener"
                class="social-btn"
                :style="{ '--marca': s.color, '--turno': i, '--marca-texto': s.textoHover || '#fff' }"
              >
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path :d="s.path" /></svg>
                <span class="sr-only">{{ s.nombre }}</span>
              </a>
            </li>
          </ul>
        </div>
        <p class="footer-lema">{{ clinica.lema }}</p>
        <ul class="footer-contacto">
          <li>
            <svg class="ci" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" /><circle cx="12" cy="10" r="3" />
            </svg>
            <a :href="clinica.mapa" target="_blank" rel="noopener">{{ clinica.direccion }}</a>
          </li>
          <li>
            <svg class="ci" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 1.9.6 2.8a2 2 0 0 1-.4 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.5 2.8.6a2 2 0 0 1 1.7 2Z" />
            </svg>
            <a :href="`tel:${clinica.telefonoPlano}`">{{ clinica.telefono }}</a>
          </li>
          <li>
            <svg class="ci" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="2" y="4" width="20" height="16" rx="2" /><path d="m2 7 10 6 10-6" />
            </svg>
            <a :href="`mailto:${clinica.email}`">{{ clinica.email }}</a>
          </li>
        </ul>
      </div>

      <!-- Enlaces rápidos -->
      <div class="col">
        <h3>Navegación</h3>
        <ul class="footer-links">
          <li v-for="e in ENLACES" :key="e.label"><RouterLink :to="e.to">{{ e.label }}</RouterLink></li>
          <li>
            <a :href="reservaUrl" target="_blank" rel="noopener" class="footer-link-cta">Reserva de hora →</a>
          </li>
        </ul>
      </div>

      <!-- Servicios -->
      <div class="col">
        <h3>Servicios</h3>
        <ul class="footer-links">
          <li v-for="s in servicios" :key="s.id"><RouterLink to="/servicios">{{ s.nombre }}</RouterLink></li>
        </ul>
      </div>

      <!-- Horario -->
      <div class="col">
        <h3>Horario de atención</h3>

        <p class="estado" :class="{ 'estado-abierto': abierto }">
          <span class="estado-punto" aria-hidden="true"></span>
          {{ abierto ? 'Abierto ahora' : 'Cerrado ahora' }}
        </p>

        <ul class="footer-horario">
          <li v-for="h in HORARIO" :key="h.dias" :class="{ 'es-hoy': h.dias === filaDeHoy }">
            <span class="hdias">{{ h.dias }}</span>
            <span v-if="h.tramos.length" class="hhoras">
              <span v-for="[desde, hasta] in h.tramos" :key="desde">{{ desde }} – {{ hasta }}</span>
            </span>
            <span v-else class="hhoras cerrado">Cerrado</span>
          </li>
        </ul>
        <p class="footer-nota">Reserva tu hora en línea, disponible 24/7.</p>
      </div>
    </div>

    <div class="footer-copy">
      <div class="wrap footer-copy-in">
        <span>
          © {{ anio }} {{ clinica.nombre }} · Puente Alto, Región Metropolitana ·
          <RouterLink to="/privacidad" class="footer-privacidad">Política de Privacidad</RouterLink> ·
          <RouterLink to="/terminos" class="footer-privacidad">Términos y Condiciones</RouterLink>
        </span>
        <!-- Corazón dibujado y no el emoji 🧡: su color lo fija la fuente del
             sistema y no había forma de moverlo de naranja. Así sigue al
             acento de la paleta, sea cual sea. `aria-label` porque el texto
             pierde una palabra si el icono no se anuncia. -->
        <span class="footer-hecho">
          Hecho con
          <svg class="corazon" viewBox="0 0 24 24" role="img" aria-label="cariño">
            <path d="M12 21s-8.4-5.3-8.4-11a4.8 4.8 0 0 1 8.4-3.2A4.8 4.8 0 0 1 20.4 10c0 5.7-8.4 11-8.4 11Z" />
          </svg>
          para el cuidado de tu mascota.
        </span>
        <span class="footer-demo">{{ clinica.nombre }} es una clínica ficticia: este sitio es una demostración.</span>
      </div>
    </div>
  </footer>
</template>

<style scoped>
.footer { background: var(--azul-oscuro); color: rgba(255, 255, 255, 0.78); }
.footer-in {
  display: grid;
  grid-template-columns: 1.6fr 1fr 1fr 1.1fr;
  gap: 2.5rem;
  /* Por línea de base y no por el borde superior: el nombre de la clínica es
     más grande que los títulos de las otras columnas, así que alinear los
     topes dejaba sus letras dos píxeles más abajo. Alineado así se ajusta
     solo si alguien cambia un tamaño. */
  align-items: baseline;
  /* padding-block, no el atajo: `padding: x 0 y` ponía en cero el padding
     lateral que aporta .wrap y el contenido quedaba pegado al borde. */
  padding-block: 3.5rem 2.5rem;
}

/* El nombre arriba y el logo debajo. Antes iban lado a lado con el logo
   primero, y eso empujaba el nombre 15px hacia abajo: quedaba desalineado
   con los títulos "Navegación", "Servicios" y "Horario de atención", que
   arrancan en el borde superior de su columna. Apilados, el nombre abre la
   columna y la fila de títulos del pie se lee pareja. */
.footer-brand {
  display: flex; flex-direction: column; align-items: flex-start;
  gap: 0.8rem; margin-bottom: 1rem;
}
.footer-brand strong { color: #fff; font-size: 1.05rem; line-height: 1.25; }
.footer-lema { font-size: 0.9rem; opacity: 0.75; margin-bottom: 1.2rem; max-width: 34ch; }

.sr-only {
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
}

.footer-contacto { list-style: none; margin: 0 0 1.4rem; padding: 0; display: flex; flex-direction: column; gap: 0.7rem; }
.footer-contacto li { display: flex; gap: 0.65rem; align-items: flex-start; font-size: 0.9rem; }
.footer-contacto .ci { width: 17px; height: 17px; flex: none; margin-top: 0.15rem; color: var(--acento); }
/* padding-block: el texto solo mide ~22px de alto, bajo el mínimo de 24px
   que pide WCAG 2.2 AA (2.5.8) para el área clicable. */
.footer-contacto a { display: inline-block; padding-block: 0.15rem; transition: color var(--dur-fast) var(--ease); }
.footer-contacto a:hover { color: var(--acento); }

/* ── Redes ──
   El color de cada plataforma entra desde abajo al enfocar o pasar el
   cursor; en reposo el bloque se mantiene sobrio y en la paleta del sitio. */
.footer-social { list-style: none; margin: 0; padding: 0; display: flex; gap: 0.7rem; flex-wrap: wrap; }
.social-btn {
  /* Sin `overflow: hidden`: el anillo del pulso tiene que poder salirse del
     botón. El disco de color no lo necesitaba, ya es un círculo por su
     propio border-radius. */
  position: relative; isolation: isolate;
  display: grid; place-items: center;
  width: 44px; height: 44px; border-radius: 50%;
  color: rgba(255, 255, 255, 0.88);
  border: 1px solid rgba(255, 255, 255, 0.22);
  transition: color var(--dur-fast) var(--ease), transform var(--dur-fast) var(--ease),
              border-color var(--dur-fast) var(--ease), box-shadow var(--dur-fast) var(--ease);
}
.social-btn svg {
  width: 19px; height: 19px; position: relative; z-index: 1;
  transition: transform 0.28s var(--ease);
}
.social-btn::before {
  content: ''; position: absolute; inset: 0; z-index: 0; border-radius: 50%;
  background: var(--marca);
  transform: scale(0); transform-origin: 50% 100%;
  transition: transform 0.32s var(--ease);
}
.social-btn:hover, .social-btn:focus-visible {
  /* var(--marca-texto) por defecto en blanco; WhatsApp la pisa a azul
     oscuro porque blanco sobre su verde no alcanza 3:1 (ver SOCIALES arriba). */
  color: var(--marca-texto, #fff); border-color: transparent; transform: translateY(-3px);
  box-shadow: 0 10px 22px -6px color-mix(in oklab, var(--marca) 70%, transparent);
}
.social-btn:hover::before, .social-btn:focus-visible::before { transform: scale(1); }
.social-btn:hover svg, .social-btn:focus-visible svg { transform: scale(1.12); }
.social-btn:focus-visible { outline: 2px solid var(--acento); outline-offset: 3px; }

/* ── Encendido y apagado en bucle ──
   Reutiliza el mismo disco de color de marca que ya usa el hover, en vez de
   añadir un efecto nuevo: encendido es lo que se ve al pasar el cursor, y el
   bucle solo lo enciende solo.

   La onda no se detiene nunca. El turno de cada icono es exactamente un
   tercio del ciclo (1.2s de 3.6s), que es lo que lo hace continuo con tres
   iconos: cuando el tercero termina su vuelta, el primero ya está empezando
   la siguiente. Antes el ciclo duraba 6s con turnos de 0.55s, así que los
   tres quedaban resueltos en los primeros 3.5s y el pie pasaba los otros 2.5
   completamente quieto — se veía como un efecto que se apagaba, no como un
   latido.
   Sigue sin ser un parpadeo sincronizado: en cada instante hay UN icono
   encendido, no tres, y el recorrido se lee como una luz que va pasando. */
/* El origen va al centro solo mientras dura el bucle. En reposo el disco
   crece desde abajo, que es el gesto del hover (se llena de abajo hacia
   arriba) y ahí funciona porque es rápido; encendiéndose despacio y solo,
   se veía como un punto de color suelto en el borde inferior del botón.
   Declarado en los fotogramas, vuelve al origen inferior al parar. */
@keyframes social-encender {
  0%        { transform: scale(0); transform-origin: 50% 50%; }
  12%, 22%  { transform: scale(1); transform-origin: 50% 50%; }
  36%, 100% { transform: scale(0); transform-origin: 50% 50%; }
}
@keyframes social-brillar {
  0%        { color: rgba(255, 255, 255, 0.88); border-color: rgba(255, 255, 255, 0.22); transform: scale(1); }
  12%       { color: var(--marca-texto, #fff); border-color: transparent; transform: scale(1.09); }
  22%       { color: var(--marca-texto, #fff); border-color: transparent; transform: scale(1); }
  36%, 100% { color: rgba(255, 255, 255, 0.88); border-color: rgba(255, 255, 255, 0.22); transform: scale(1); }
}
/* El anillo que sale del icono: es lo que convierte el encendido en pulso.
   Nace del borde del botón y se expande hacia afuera mientras se desvanece,
   como una onda. Sale en el mismo instante en que el disco se enciende, así
   que se leen como un solo latido y no como dos efectos encimados. */
@keyframes social-pulso {
  0%        { opacity: 0; transform: scale(1); }
  12%       { opacity: 0.7; transform: scale(1); }
  36%, 100% { opacity: 0; transform: scale(1.8); }
}
/* Las tres animaciones comparten ciclo y turno a propósito: el disco, el
   brillo del botón y el anillo son un solo latido repartido en tres capas.
   Si se tocan estos números, se tocan los tres a la vez, y el turno debe
   seguir siendo un tercio del ciclo para que la onda no se corte. */
.social-btn {
  animation: social-brillar 3.6s var(--ease) infinite;
  animation-delay: calc(var(--turno, 0) * 1.2s);
}
.social-btn::before {
  animation: social-encender 3.6s var(--ease) infinite;
  animation-delay: calc(var(--turno, 0) * 1.2s);
}
.social-btn::after {
  content: ''; position: absolute; inset: 0; z-index: 0; border-radius: 50%;
  border: 2px solid var(--marca);
  opacity: 0; pointer-events: none;
  animation: social-pulso 3.6s var(--ease) infinite;
  animation-delay: calc(var(--turno, 0) * 1.2s);
}
/* Con el cursor encima manda el hover: se corta el bucle para que el icono
   no se apague ni lata debajo del puntero. */
.social-btn:hover, .social-btn:focus-visible,
.social-btn:hover::before, .social-btn:focus-visible::before,
.social-btn:hover::after, .social-btn:focus-visible::after { animation: none; }

.col h3 {
  color: #fff; font-size: 0.95rem; font-weight: 700;
  margin-bottom: 1.1rem; letter-spacing: 0.02em;
}
.footer-links { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.65rem; }
/* padding-block: el texto solo mide ~23px de alto, bajo el mínimo de 24px
   que pide WCAG 2.2 AA (2.5.8) para el área clicable. */
.footer-links a {
  display: inline-block; padding-block: 0.1rem; font-size: 0.9rem; color: rgba(255, 255, 255, 0.82);
  transition: color var(--dur-fast) var(--ease), transform var(--dur-fast) var(--ease);
}
.footer-links a:hover { color: var(--acento); transform: translateX(3px); }
.footer-link-cta { color: var(--acento-claro) !important; font-weight: 600; opacity: 1 !important; }

/* Estado de atención: responde de inmediato la duda con la que se llega
   al horario, sin obligar a comparar la hora mentalmente. */
.estado {
  display: inline-flex; align-items: center; gap: 0.5rem;
  font-size: 0.82rem; font-weight: 600;
  padding: 0.32em 0.85em; border-radius: var(--r-full);
  background: rgba(255, 255, 255, 0.09);
  color: rgba(255, 255, 255, 0.78);
  margin-bottom: 1rem;
}
.estado-punto { width: 7px; height: 7px; border-radius: 50%; background: currentColor; flex: none; }
.estado-abierto { background: rgba(37, 211, 102, 0.16); color: #7cf0aa; }
/* El punto no parpadea cada 2.6s a su aire: late en --latido, el mismo tempo
   que el corazón del pie. Ahí deja de ser un aviso que titila y pasa a ser lo
   que dice el texto que tiene al lado — que la clínica está viva y abierta
   ahora mismo. Cerrada, el punto simplemente no late: sin halo, sin línea
   plana, sin nada que sugiera otra cosa. */
.estado-abierto .estado-punto { animation: pulso var(--latido) ease-out infinite; }
@keyframes pulso {
  0%   { box-shadow: 0 0 0 0 rgba(124, 240, 170, 0.55); }
  8%   { box-shadow: 0 0 0 2px rgba(124, 240, 170, 0.5); }
  44%  { box-shadow: 0 0 0 7px rgba(124, 240, 170, 0); }
  100% { box-shadow: 0 0 0 0 rgba(124, 240, 170, 0); }
}

.footer-horario { list-style: none; margin: 0 0 1rem; padding: 0; display: flex; flex-direction: column; gap: 0.15rem; }
.footer-horario li {
  display: flex; justify-content: space-between; gap: 0.8rem; font-size: 0.88rem;
  padding: 0.4rem 0.55rem; margin-inline: -0.55rem; border-radius: var(--r-sm);
  /* Los días con dos tramos ocupan dos líneas: el nombre del día se queda
     arriba, a la altura del primero, en vez de centrarse entre ambos. */
  align-items: flex-start;
}
/* El día en curso, resaltado por fondo y peso (nada de filete lateral). */
.footer-horario li.es-hoy { background: rgba(255, 255, 255, 0.07); }
.footer-horario li.es-hoy .hdias { color: #fff; font-weight: 600; }
.hdias { color: rgba(255, 255, 255, 0.8); }
.hhoras {
  font-weight: 600; color: #fff; white-space: nowrap;
  display: flex; flex-direction: column; align-items: flex-end; gap: 0.1rem;
}
.hhoras.cerrado { color: var(--acento-claro); font-weight: 500; }
.footer-nota { font-size: 0.82rem; color: rgba(255, 255, 255, 0.66); }

.footer-copy { border-top: 1px solid rgba(255, 255, 255, 0.12); }
/* El corazón se alinea con la línea de base del texto en vez de asentarse
   sobre ella, que es como se sentaría un icono en línea por defecto. */
.footer-hecho { display: inline-flex; align-items: center; gap: 0.36em; }
/* El aviso de demo ocupa su propia línea: no compite con el © ni con el lema. */
.footer-demo { flex-basis: 100%; font-size: 0.78rem; color: rgba(255, 255, 255, 0.6); }
/* En el acento CLARO y no el medio, que es lo que pide la paleta sobre fondo
   oscuro: sobre el azul del pie el medio se hundía a 3.04:1 y el corazón se
   perdía en el fondo. El claro da 5.17:1.
   Y late de verdad, en el tempo de --latido, con el mismo lub-dub que el
   punto de "Abierto ahora" tres columnas más allá: los dos corazones del pie
   van sincronizados porque arrancan juntos, al montarse el componente.
   El origen queda algo bajo el centro: un corazón se contrae desde su base,
   no desde su punto medio geométrico. */
.corazon {
  width: 1.05em; height: 1.05em; fill: var(--acento-claro); flex: none;
  transform-origin: 50% 62%;
  animation: latido var(--latido) ease-in-out infinite;
}
.footer-copy-in {
  display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
  padding-block: 1.2rem; font-size: 0.8rem; color: rgba(255, 255, 255, 0.66);
}
.footer-privacidad { color: rgba(255, 255, 255, 0.66); text-decoration: underline; }
.footer-privacidad:hover { color: #fff; }

@media (max-width: 900px) {
  .footer-in { grid-template-columns: 1fr 1fr; gap: 2rem; }
  .col-brand { grid-column: 1 / -1; }
  /* Bajo 1140px el contenido llega hasta donde flota el botón de WhatsApp
     (58px + 22px de margen); sin este respiro tapa el copyright y, por ser
     el final del documento, no hay scroll que lo libere. */
  .footer-copy-in { padding-bottom: 5.5rem; }
}
@media (max-width: 560px) {
  .footer-in { grid-template-columns: 1fr; }
  .footer-copy-in { flex-direction: column; text-align: center; }
}

@media (prefers-reduced-motion: reduce) {
  .social-btn, .social-btn svg, .social-btn::before,
  .footer-links a, .footer-contacto a { transition: none; }
  /* El bucle de encendido se detiene por completo: es movimiento permanente
     y sin control del usuario, justo lo que esta preferencia pide evitar.
     Los iconos quedan en reposo y el hover sigue funcionando. */
  .social-btn, .social-btn::before, .social-btn::after { animation: none; }
  /* El corazón se queda quieto pero sigue ahí, en su color: el latido era la
     gracia, no la información. */
  .corazon { animation: none; }
  /* El color de marca sigue apareciendo; lo que se elimina es el movimiento. */
  .social-btn:hover, .social-btn:focus-visible { transform: none; }
  .social-btn:hover svg, .social-btn:focus-visible svg { transform: none; }
  .social-btn:hover::before, .social-btn:focus-visible::before { transform: scale(1); }
  .footer-links a:hover { transform: none; }
  .estado-abierto .estado-punto { animation: none; }
}
</style>
