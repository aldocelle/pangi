<script setup>
import { ref, reactive, onMounted } from 'vue';
import { CLINICA, RESERVA_URL, RECAPTCHA_SITE_KEY } from '../config.js';
import { api } from '../api.js';
import { precargarRecaptcha, obtenerTokenRecaptcha } from '../recaptcha.js';
import ContactoSection from '../components/ContactoSection.vue';
import ContactoHero from '../components/ContactoHero.vue';
import CtaAgendar from '../components/CtaAgendar.vue';

const form = reactive({ nombre: '', email: '', emailConfirmar: '', asunto: '', mensaje: '' });
const errores = reactive({ nombre: '', email: '', emailConfirmar: '', asunto: '', mensaje: '' });
const enviando = ref(false);
const enviado = ref(false);
const fallo = ref('');

// Precargada al entrar a la página, no al enviar: el script de Google tarda
// un momento en responder y así ese tiempo lo absorbe mientras la persona
// todavía está escribiendo, no al hacer clic en "Enviar".
onMounted(() => { precargarRecaptcha(); });

function validar() {
  errores.nombre = form.nombre.trim() ? '' : 'Escribe tu nombre.';
  errores.email = !form.email.trim()
    ? 'Escribe tu correo.'
    : /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())
      ? ''
      : 'Revisa el correo: parece incompleto.';
  errores.emailConfirmar = !form.emailConfirmar.trim()
    ? 'Vuelve a escribir tu correo.'
    : form.emailConfirmar.trim().toLowerCase() === form.email.trim().toLowerCase()
      ? ''
      : 'Los correos no coinciden.';
  errores.asunto = form.asunto.trim() ? '' : 'Cuéntanos brevemente el motivo.';
  errores.mensaje = form.mensaje.trim() ? '' : 'Cuéntanos en qué te ayudamos.';
  return !errores.nombre && !errores.email && !errores.emailConfirmar && !errores.asunto && !errores.mensaje;
}

/* La consulta se manda al servidor y queda registrada ahí. No se abre el
   programa de correo del visitante: mucha gente usa webmail y no tiene
   ninguno configurado, así que ese camino perdía mensajes en silencio. */
async function enviar() {
  if (!validar()) {
    // Lleva el foco al primer campo con problema, para quien navega con teclado.
    const primero = ['nombre', 'email', 'emailConfirmar', 'asunto', 'mensaje'].find((c) => errores[c]);
    document.getElementById(`f-${primero}`)?.focus();
    return;
  }

  enviando.value = true;
  fallo.value = '';
  try {
    const recaptchaToken = await obtenerTokenRecaptcha('contacto');
    await api.post('/contacto/mensajes', {
      nombre: form.nombre.trim(),
      email: form.email.trim(),
      asunto: form.asunto.trim(),
      mensaje: form.mensaje.trim(),
      recaptcha_token: recaptchaToken,
    });
    enviado.value = true;
    Object.assign(form, { nombre: '', email: '', emailConfirmar: '', asunto: '', mensaje: '' });
  } catch (e) {
    fallo.value =
      e?.response?.data?.detail ||
      'No pudimos enviar tu consulta en este momento. Inténtalo de nuevo o escríbenos por WhatsApp.';
  } finally {
    enviando.value = false;
  }
}
</script>

<template>
  <div class="pagina">
    <ContactoHero :reserva-url="RESERVA_URL" :clinica="CLINICA" />

    <section class="seccion form-sec">
      <div class="wrap form-grid">
        <!-- Texto + foto y formulario en una sola composición a dos columnas,
             no dos bloques apilados: apilados, la tarjeta quedaba flotando
             sola en una banda vacía y se leía como un anexo. Emparejados, la
             columna izquierda explica y la derecha recibe — el mismo reparto
             de "lectura a la izquierda, cosa concreta a la derecha" que ya
             usan ContactoSection.vue (con el mapa) y QuienesSomos.vue. -->
        <div class="form-intro">
          <h2 class="titulo-hero">
            Cuéntanos qué
            <span class="enfasis">necesita tu mascota</span>
          </h2>
          <p class="titulo-hero-sub">
            Respondemos dentro del horario de atención. Si es urgente,
            <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener">
              escríbenos por WhatsApp</a>
            y te contestamos antes.
          </p>
          <!-- Foto propia de esta página, distinta a la de QuienesSomos.vue
               en Inicio, para no repetir imagen entre páginas. -->
          <img
            class="form-foto"
            src="/contacto.webp"
            alt="Una veterinaria gata gris atendiendo a un gato naranja en la consulta"
            loading="lazy" decoding="async" width="1400" height="1120"
          />
        </div>

        <form class="form" novalidate @submit.prevent="enviar">
          <h2 class="form-titulo">Formulario de contacto</h2>

          <!-- Nombre, correo, confirmar correo y asunto comparten fila: son
               datos cortos, y a lo ancho de la tarjeta entera (tan ancha como
               el bloque de arriba) cada uno por su lado quedaría en un campo
               de más de 700px para escribir un nombre. `auto-fit` en vez de
               un número fijo de columnas: a lo ancho caben los cuatro, y
               según se achica la tarjeta pasan a dos y luego a uno sin un
               breakpoint que mantener a mano.
               El correo se pide dos veces para atajar errores de tipeo (el
               dato que más falla al escribirlo una sola vez, y el único por
               el que se puede responder): "Confirmar correo" va pegado a
               "Correo" para que se puedan comparar de un vistazo, y no deja
               pegar texto — si se pudiera pegar, ambos campos repetirían el
               mismo error y la doble escritura no serviría de nada. -->
          <div class="fila-datos">
            <div class="campo">
              <label for="f-nombre">Nombre</label>
              <input
                id="f-nombre" v-model="form.nombre" type="text" autocomplete="name"
                :aria-invalid="!!errores.nombre"
                :aria-describedby="errores.nombre ? 'e-nombre' : undefined"
              />
              <p v-if="errores.nombre" id="e-nombre" class="error">{{ errores.nombre }}</p>
            </div>

            <div class="campo">
              <label for="f-email">Correo</label>
              <input
                id="f-email" v-model="form.email" type="email" autocomplete="email"
                :aria-invalid="!!errores.email"
                :aria-describedby="errores.email ? 'e-email' : undefined"
              />
              <p v-if="errores.email" id="e-email" class="error">{{ errores.email }}</p>
            </div>

            <div class="campo">
              <label for="f-emailConfirmar">Confirmar correo</label>
              <input
                id="f-emailConfirmar" v-model="form.emailConfirmar" type="email" autocomplete="off"
                @paste.prevent @drop.prevent
                :aria-invalid="!!errores.emailConfirmar"
                :aria-describedby="errores.emailConfirmar ? 'e-emailConfirmar' : 'a-emailConfirmar'"
              />
              <p v-if="errores.emailConfirmar" id="e-emailConfirmar" class="error">{{ errores.emailConfirmar }}</p>
              <p v-else id="a-emailConfirmar" class="campo-ayuda">Escríbelo de nuevo: no se puede pegar.</p>
            </div>

            <div class="campo">
              <label for="f-asunto">Asunto</label>
              <input
                id="f-asunto" v-model="form.asunto" type="text"
                :aria-invalid="!!errores.asunto"
                :aria-describedby="errores.asunto ? 'e-asunto' : undefined"
              />
              <p v-if="errores.asunto" id="e-asunto" class="error">{{ errores.asunto }}</p>
            </div>
          </div>

          <div class="campo">
            <label for="f-mensaje">Mensaje</label>
            <textarea
              id="f-mensaje" v-model="form.mensaje" rows="5"
              :aria-invalid="!!errores.mensaje"
              :aria-describedby="errores.mensaje ? 'e-mensaje' : undefined"
            ></textarea>
            <p v-if="errores.mensaje" id="e-mensaje" class="error">{{ errores.mensaje }}</p>
          </div>

          <button type="submit" class="btn btn-primary" :disabled="enviando">
            {{ enviando ? 'Enviando…' : 'Enviar consulta' }}
          </button>

          <p v-if="enviado" class="aviso ok" role="status">
            Recibimos tu consulta. Te responderemos dentro del horario de atención.
          </p>
          <p v-if="fallo" class="aviso aviso-fallo" role="alert">
            {{ fallo }} También puedes escribirnos a
            <a :href="`mailto:${CLINICA.email}`">{{ CLINICA.email }}</a>.
          </p>

          <!-- Texto exigido por Google al usar reCAPTCHA de forma invisible
               (sin el desafío visible, hay que avisar igual). El badge
               flotante que agrega el script se oculta más abajo: este
               párrafo lo reemplaza como aviso, ya integrado al formulario en
               vez de flotando sobre el resto del sitio. Sin clave configurada
               no se carga reCAPTCHA, y el aviso no se muestra. -->
          <p v-if="RECAPTCHA_SITE_KEY" class="aviso recaptcha-aviso">
            Este sitio está protegido por reCAPTCHA. Aplican la
            <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Política de Privacidad</a>
            y los
            <a href="https://policies.google.com/terms" target="_blank" rel="noopener">Términos de Servicio</a>
            de Google.
          </p>
        </form>
      </div>
    </section>

    <ContactoSection :clinica="CLINICA" />
    <CtaAgendar :reserva-url="RESERVA_URL" />
  </div>
</template>

<style scoped>
/* Banda tintada, como las secciones alternas del sitio: da a la tarjeta
   blanca del formulario una superficie sobre la cual estar realmente
   elevada (ver Regla del Reposo Plano en DESIGN.md) — antes eran tarjeta
   pálida sobre fondo pálido y nada se despegaba de nada. */
.form-sec { background: var(--bg); }

.form-grid {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: clamp(2rem, 5vw, 4rem); align-items: start;
}
.form-intro p {
  color: var(--ink-soft); margin-top: 1rem;
  font-size: var(--texto-lead); line-height: var(--lead-alto); max-width: 46ch;
}
.form-intro a { color: var(--acento); font-weight: 600; }
.form-intro a:hover { text-decoration: underline; }
/* Sin aspect-ratio ni recorte: la foto ya viene 1402×1122, así que se
   muestra entera, igual que la de "Los amamos, igual que tú". Ahora vive
   dentro de `.form-intro`, debajo del texto, así que lleva su propio
   margen superior en vez del `gap` que le daba `.form-top`.
   height:auto explícito: sin esto, el `height` del HTML (puesto para
   reservar espacio y evitar salto de layout) se aplicaba como alto literal
   en píxeles y no como proporción — la foto quedaba estirada. */
.form-foto {
  width: 100%; height: auto; margin-top: clamp(1.8rem, 4vw, 2.6rem);
  border-radius: var(--r-lg); box-shadow: var(--shadow);
}

/* Tarjeta blanca real (no tintada, como el resto de las tarjetas del
   sitio — ver Cards en DESIGN.md), elevada sobre la banda tintada de
   `.form-sec` con la sombra marcada (`--shadow-lg`): antes era una tarjeta
   pálida sobre fondo pálido, del mismo tono, y no se despegaba de nada. Sin
   `max-width` ni `margin-inline: auto`: ahora vive en su propia columna del
   grid de dos columnas, así que el ancho ya lo define la rejilla, no un
   tope propio. */
.form {
  display: flex; flex-direction: column; gap: 1.1rem;
  background: var(--surface);
  border-radius: var(--r-lg);
  padding: clamp(1.7rem, 3.5vw, 2.3rem);
  box-shadow: var(--shadow-lg);
}
/* Le pone nombre a la tarjeta, igual que "Cuéntanos qué necesita tu mascota"
   se lo pone a la columna de al lado — sin esto, la tarjeta empezaba
   directo en los campos y no se leía como una unidad con encabezado propio.
   Escala más chica que ese h2 (2.2rem vs 1.2rem): ahí es el titular de la
   página, acá es la etiqueta de la tarjeta. */
.form-titulo { color: var(--azul); font-size: 1.2rem; }
/* Dos columnas fijas, no auto-fit: a lo ancho de esta columna del grid, un
   minmax(200px,1fr) caía justo en el límite entre 1 y 2 columnas según el
   navegador — con dos columnas explícitas, Nombre+Correo y Confirmar
   correo+Asunto quedan agrupados en dos filas parejas, sin ese salto. */
.fila-datos {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 0.9rem;
}
.campo { display: flex; flex-direction: column; gap: 0.35rem; }
.campo label { font-weight: 600; color: var(--azul); font-size: 0.88rem; }

/* Fondo tintado en vez de blanco: la tarjeta ya es blanca (`.form`), así que
   un campo también blanco se fundía con ella y perdía su propio borde
   visual — tintado, cada campo se lee como una caja distinta dentro de la
   tarjeta, y el foco (blanco + halo turquesa) es lo que de verdad resalta. */
.campo input, .campo textarea {
  width: 100%; padding: 0.7em 0.9em;
  font: inherit; color: var(--ink);
  background: var(--bg);
  border: 1.5px solid transparent;
  border-radius: var(--r-md);
  transition: border-color var(--dur-fast) var(--ease), box-shadow var(--dur-fast) var(--ease), background var(--dur-fast) var(--ease);
}
.campo textarea { resize: vertical; min-height: 7rem; }
.campo input:hover, .campo textarea:hover { border-color: var(--acento-claro); }
.campo input:focus, .campo textarea:focus {
  outline: none; background: var(--surface); border-color: var(--acento);
  box-shadow: 0 0 0 4px var(--acento-suave);
}
.campo [aria-invalid='true'] { border-color: var(--error); }

/* Rojo oscuro para que el mensaje cumpla contraste sobre el fondo claro. */
.error { color: var(--error); font-size: 0.88rem; }
.campo-ayuda { color: var(--ink-soft); font-size: 0.82rem; }
.aviso { color: var(--ink-soft); font-size: 0.92rem; line-height: 1.55; }
.aviso a { color: var(--acento-fuerte); font-weight: 600; }
.aviso.ok {
  color: #1b7a4b; font-weight: 600;
  background: #eaf7f0; border-radius: var(--r-sm); padding: 0.7em 0.9em;
}
.aviso-fallo { color: var(--error); }
/* Más chico y sin negrita: es letra chica legal, no parte de la conversación
   con quien completa el formulario. */
.recaptcha-aviso { font-size: 0.8rem; }

.form .btn { align-self: flex-start; }

@media (max-width: 820px) {
  .form-grid { grid-template-columns: 1fr; }
  .form-intro p { max-width: none; }
  .form .btn { align-self: stretch; text-align: center; }
}

/* Dos columnas fijas (arriba) necesitan su propio quiebre a una sola: sin
   auto-fit, no se acomodan solas en un teléfono angosto. */
@media (max-width: 420px) {
  .fila-datos { grid-template-columns: 1fr; }
}

@media (prefers-reduced-motion: reduce) {
  .campo input, .campo textarea { transition: none; }
}
</style>
