<script setup>
import { ref, computed } from 'vue';
import { useReveal } from '../useReveal.js';

const props = defineProps({
  clinica: { type: Object, required: true },
});

const root = ref(null);
useReveal(root);

/* Mismos tres glifos, mismos colores de marca y mismo trazado que
   TheFooter.vue — es la firma de redes del sitio, no una nueva. Se repite
   aquí (computed y no una constante) porque depende de `clinica`, igual que
   allá. */
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
    // ícono. Mismo motivo por el que el botón sólido de WhatsApp ya usaba
    // --azul-oscuro en vez de blanco (7.56:1) — ver btn-wa en el historial.
    textoHover: 'var(--azul-oscuro)',
    path: 'M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.47-1.75-1.64-2.05-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.87 1.22 3.07c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.69.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35M12.05 21.79h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.89 9.9-9.89 2.64 0 5.12 1.03 6.99 2.9a9.82 9.82 0 0 1 2.89 6.99c0 5.45-4.44 9.89-9.89 9.89M20.52 3.49A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.6 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.69 1.45h.01c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.17-3.49-8.42',
  },
]);
</script>

<template>
  <section id="contacto" ref="root" class="seccion contacto">
    <div class="wrap contacto-in">
      <div class="contacto-texto" data-reveal>
        <h2>Estamos en Puente Alto</h2>
        <p class="contacto-lead">A pasos de la plaza, con estacionamiento para clientes.</p>

        <ul class="contacto-lista">
          <li>
            <span class="ci" aria-hidden="true">📍</span>
            <div>
              <strong>Dirección</strong>
              <span>{{ clinica.direccion }}</span>
            </div>
          </li>
          <li>
            <span class="ci" aria-hidden="true">📞</span>
            <div>
              <strong>Teléfono / WhatsApp</strong>
              <a :href="`tel:${clinica.telefonoPlano}`">{{ clinica.telefono }}</a>
            </div>
          </li>
          <li>
            <span class="ci" aria-hidden="true">✉️</span>
            <div>
              <strong>Correo</strong>
              <a :href="`mailto:${clinica.email}`">{{ clinica.email }}</a>
            </div>
          </li>
          <li v-if="clinica.instagram">
            <span class="ci" aria-hidden="true">📷</span>
            <div>
              <strong>Instagram</strong>
              <a :href="clinica.instagram" target="_blank" rel="noopener">{{ clinica.instagramHandle }}</a>
            </div>
          </li>
        </ul>

        <!-- Redes en vez del botón de WhatsApp: al número ya se llega desde
             la fila "Teléfono / WhatsApp" de arriba, así que un botón entero
             repetía el mismo camino. Las redes en cambio no tenían ningún
             lugar en esta sección — solo Instagram, como texto, en la lista.
             "Cómo llegar" se queda: al mapa se llega por tres caminos en este
             bloque (la dirección, este botón y el mapa entero, que también
             es enlace), pero ninguno es un botón redundante como lo era el
             de WhatsApp. -->
        <div class="contacto-acciones">
          <ul class="contacto-social">
            <li v-for="s in SOCIALES.filter((r) => r.url)" :key="s.nombre">
              <a
                :href="s.url" target="_blank" rel="noopener"
                class="social-btn"
                :style="{ '--marca': s.color, '--marca-texto': s.textoHover || '#fff' }"
              >
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path :d="s.path" /></svg>
                <span class="sr-only">{{ s.nombre }}</span>
              </a>
            </li>
          </ul>
          <a :href="clinica.mapa" target="_blank" rel="noopener" class="btn btn-borde">Cómo llegar</a>
        </div>
      </div>

      <a :href="clinica.mapa" target="_blank" rel="noopener" class="contacto-mapa" data-reveal>
        <iframe
          title="Mapa de la clínica"
          :src="clinica.mapaEmbed"
          loading="lazy"
          referrerpolicy="no-referrer-when-downgrade"
          tabindex="-1"
        ></iframe>
        <span class="contacto-mapa-fallback">
          <span class="pin" aria-hidden="true">📍</span>
          <strong>{{ clinica.direccion }}</strong>
          <span>Ver en Google Maps</span>
        </span>
      </a>
    </div>
  </section>
</template>

<style scoped>
/* Blanco, como el resto de Inicio. Este componente también lo usa la página
   de Contacto, donde queda seguido de .form-sec, que ya es blanco: ahí los
   separa el filete superior. */
.contacto { background: transparent; border-top: 1px solid var(--line); }
/* Sin `align-items: center`. La columna de texto mide unos 500px y el mapa
   tenía 380 fijos, así que quedaba flotando en medio con ~60px muertos arriba
   y abajo y las dos columnas no cerraban a la misma altura. Estirado, el
   bloque se lee como un rectángulo cerrado y de paso el mapa se ve más. */
.contacto-in {
  display: grid; grid-template-columns: 1fr 1fr; gap: clamp(2rem, 5vw, 3.5rem);
  align-items: stretch;
}
/* Los botones al fondo de su columna, para que cierren a la misma línea que
   el borde inferior del mapa. Si el texto llegara a ser la columna más alta,
   `auto` no encuentra espacio sobrante y no hace nada: no estorba. */
.contacto-texto { display: flex; flex-direction: column; }
.contacto-acciones { margin-top: auto; }
.contacto-texto h2 { color: var(--azul); font-size: clamp(1.9rem, 4.2vw, 2.6rem); margin-bottom: 0.5rem; }
.contacto-lead {
  color: var(--ink-soft); margin-bottom: 1.8rem;
  font-size: var(--texto-lead); line-height: var(--lead-alto);
}

.contacto-lista { list-style: none; margin: 0 0 1.8rem; padding: 0; display: flex; flex-direction: column; gap: 1.1rem; }
.contacto-lista li { display: flex; gap: 0.9rem; align-items: flex-start; }
.contacto-lista li > div { min-width: 0; } /* deja que la dirección larga corte línea */
/* En tinte de marca y sin sombra. Eran cuadrados blancos sobre una sección
   blanca, separados del fondo solo por una sombra grande y difusa: se leían
   como fichas sueltas flotando al costado de la lista, no como parte de ella. */
.ci {
  flex: none; width: 44px; height: 44px; border-radius: var(--r-md);
  display: grid; place-items: center; background: var(--azul-tint);
  font-size: 1.2rem; line-height: 1;
  transition: transform var(--dur-fast) var(--ease), background var(--dur-fast) var(--ease);
}
.contacto-lista li:hover .ci { transform: scale(1.08); background: var(--acento-suave); }
/* Jerarquía al derecho. El rótulo ("Dirección", "Teléfono") iba en azul de
   marca y el dato en gris pequeño: pesaba más la etiqueta que la información
   que la gente viene a buscar. Ahora el rótulo es la línea de apoyo y el dato
   manda.
   El selector del dato es `li > div > span` y no `.contacto-lista span`
   porque ese también alcanzaba a `.ci`, que es un span: con más
   especificidad que la propia regla de `.ci`, le pisaba el tamaño y dejaba
   los iconos a 0.95rem en vez de los 1.2rem que dice su clase. */
.contacto-lista strong {
  display: block; color: var(--ink-soft);
  font-size: 0.82rem; font-weight: 700; margin-bottom: 0.12rem;
}
.contacto-lista li > div > span,
.contacto-lista a {
  color: var(--azul); font-size: 1rem; font-weight: 700;
  transition: color var(--dur-fast) var(--ease);
}
.contacto-lista a:hover { color: var(--acento); }

.contacto-acciones { display: flex; flex-wrap: wrap; gap: 0.8rem; padding-top: 0.4rem; }
/* El contorno va en `box-shadow` y no en `border`: un borde real sumaría 3px
   de alto y el botón quedaría un pelo más grande que el sólido de al lado. */
.btn-borde {
  color: var(--azul); background: var(--surface);
  box-shadow: inset 0 0 0 1.5px var(--line);
}
.btn-borde:hover {
  background: var(--azul-tint); box-shadow: inset 0 0 0 1.5px var(--azul);
  transform: translateY(-2px);
}

.sr-only {
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
}

/* Mismos glifos que el pie, tratamiento distinto: allá el fondo es azul
   oscuro y el icono en reposo va casi blanco sobre un círculo translúcido.
   Aquí la sección es blanca, así que el reposo se apoya en el mismo tinte
   de marca que ya usan los iconos de la lista de arriba (`.ci`) — mismo
   tamaño, mismo fondo — y solo al pasar el cursor entra el color de cada
   red, igual que en el pie. */
.contacto-social { list-style: none; margin: 0; padding: 0; display: flex; gap: 0.6rem; }
.social-btn {
  position: relative; isolation: isolate;
  display: grid; place-items: center;
  width: 44px; height: 44px; border-radius: 50%;
  background: var(--azul-tint); color: var(--azul);
  transition: color var(--dur-fast) var(--ease), transform var(--dur-fast) var(--ease),
              box-shadow var(--dur-fast) var(--ease);
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
  color: var(--marca-texto);
  box-shadow: 0 10px 22px -6px color-mix(in oklab, var(--marca) 60%, transparent);
}
.social-btn:hover::before, .social-btn:focus-visible::before { transform: scale(1); }
.social-btn:hover svg, .social-btn:focus-visible svg { transform: scale(1.12); }
.social-btn:focus-visible { outline: 2px solid var(--acento); outline-offset: 3px; }

.contacto-mapa {
  position: relative; display: block;
  border-radius: var(--r-lg); overflow: hidden; box-shadow: var(--shadow);
  background: var(--azul-tint); min-height: 380px;
}
/* Anclado a los cuatro lados en vez de `height: 100%`: para que un porcentaje
   resuelva, el ancla necesita altura definida, y en una sola columna solo
   tiene `min-height` — el iframe se habría quedado en sus 150px por defecto
   dentro de una caja de 380. Así llena lo que mida el ancla, la estire la
   grilla o la sostenga el min-height. */
.contacto-mapa iframe {
  position: absolute; inset: 0; z-index: 2;
  width: 100%; height: 100%; border: 0; display: block;
}
/* Fallback visible detrás del iframe: si el mapa no carga, se ve esto */
.contacto-mapa-fallback {
  position: absolute; inset: 0; z-index: 1;
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.3rem;
  text-align: center; color: var(--azul); padding: 1rem;
}
.contacto-mapa-fallback .pin { font-size: 2.2rem; }
.contacto-mapa-fallback strong { font-size: 1.05rem; }
.contacto-mapa-fallback span { color: var(--ink-soft); font-size: 0.9rem; }

@media (max-width: 820px) {
  .contacto-in { grid-template-columns: 1fr; }
  /* En el ancla y no en el iframe, que ahora la llena por completo. */
  .contacto-mapa { min-height: 300px; }
  /* A una columna los botones ya no tienen que alcanzar el pie de nada. */
  .contacto-acciones { margin-top: 0; }
}

/* `.btn-borde` no está en la lista de app.css, que solo cubre las variantes
   compartidas. */
@media (prefers-reduced-motion: reduce) {
  .ci, .contacto-lista a, .social-btn, .social-btn svg, .social-btn::before { transition: none; }
  .contacto-lista li:hover .ci { transform: none; }
  .btn-borde:hover { transform: none; }
}
</style>
