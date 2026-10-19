<script setup>
import { ref, computed, nextTick } from 'vue';
import { api, apiError, urlFoto } from '../api.js';
import { useAuthStore } from '../stores/auth.js';
import { SITIO_URL } from '../config.js';
import BaseIcon from '../components/BaseIcon.vue';
import PasswordInput from '../components/PasswordInput.vue';
import EditorFoto from '../components/EditorFoto.vue';

const auth = useAuthStore();

/* ── Foto de perfil ── la respuesta trae el usuario completo, pero solo se
   toma la foto: el resto de `auth.user` (es_sysop, etc.) no viene en ella. */
async function subirFoto(archivo) {
  const fd = new FormData();
  fd.append('archivo', archivo);
  const { data } = await api.post('/auth/me/foto', fd);
  auth.user = { ...auth.user, foto: data.foto };
}
async function quitarFoto() {
  const { data } = await api.delete('/auth/me/foto');
  auth.user = { ...auth.user, foto: data.foto };
}

/* ── Datos personales y de contacto ── */
const form = ref({
  nombre: auth.user?.nombre || '',
  email: auth.user?.email || '',
  telefono: auth.user?.telefono || '',
});
const guardandoDatos = ref(false);
const errorDatos = ref('');
const okDatos = ref('');
const inputNombre = ref(null);

async function guardarDatos() {
  guardandoDatos.value = true;
  errorDatos.value = '';
  okDatos.value = '';
  try {
    await api.patch('/auth/me', {
      nombre: form.value.nombre.trim(),
      email: form.value.email.trim(),
      telefono: form.value.telefono.trim() || null,
    });
    await auth.fetchMe();
    okDatos.value = 'Datos actualizados.';
    setTimeout(() => (okDatos.value = ''), 2500);
  } catch (e) {
    errorDatos.value = apiError(e, 'No se pudieron guardar los cambios.');
  } finally {
    guardandoDatos.value = false;
  }
}

/* ── Privacidad: los seis derechos de la ley 21.719 ──
   Antes era una tercera columna angosta que enumeraba los derechos según CÓMO
   los resuelve el sistema (tres al instante, el resto por solicitud). Eso es
   organización interna; quien entra acá llega con una intención —«quiero
   llevarme mis datos», «que no me escriban»—. Por eso son cuatro filas por
   intención, cada una con los nombres legales que cubre junto al título: los
   seis quedan a la vista sin obligar a nadie a saber qué es la «portabilidad».

   Lo que no cambió es el reparto: acceso, portabilidad, rectificación de los
   datos editables y oposición al marketing se resuelven solos; supresión y
   bloqueo abren una solicitud, porque pueden chocar con los plazos de
   conservación de la ficha clínica y ahí decide una persona (§14). */
const marketing = ref(Boolean(auth.user?.marketing_consent));
const guardandoMarketing = ref(false);
const okMarketing = ref('');
const errorMarketing = ref('');

async function cambiarMarketing() {
  const valor = !marketing.value;
  guardandoMarketing.value = true;
  okMarketing.value = '';
  errorMarketing.value = '';
  try {
    await api.patch('/auth/me', { acepta_marketing: valor });
    marketing.value = valor;
    await auth.fetchMe();
    okMarketing.value = valor
      ? 'Listo, te avisaremos de novedades.'
      : 'Listo, no te enviaremos más comunicaciones comerciales.';
    setTimeout(() => (okMarketing.value = ''), 3500);
  } catch (e) {
    // Antes este error se pintaba en la tarjeta de Datos personales, lejos del
    // interruptor que lo provocó.
    errorMarketing.value = apiError(e, 'No se pudo guardar tu preferencia.');
  } finally {
    guardandoMarketing.value = false;
  }
}

const descargando = ref(false);
const errorDescarga = ref('');

async function descargarMisDatos() {
  descargando.value = true;
  errorDescarga.value = '';
  try {
    const { data } = await api.get('/auth/mis-datos');
    /* Se arma el archivo en el navegador y no se pide al servidor un
       endpoint que devuelva un adjunto: los datos ya viajaron en la
       respuesta, así que bajarlos otra vez solo duplicaría la consulta. */
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `mis-datos-${new Date().toISOString().slice(0, 10)}.json`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  } catch (e) {
    errorDescarga.value = apiError(e, 'No pudimos preparar tu archivo. Inténtalo de nuevo.');
  } finally {
    descargando.value = false;
  }
}

/* Rectificar lo editable no necesita a nadie: se lleva a la persona al
   formulario de arriba en vez de abrirle una solicitud para algo que puede
   hacer sola en diez segundos. */
async function irADatos() {
  document.getElementById('cfg-datos')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  await nextTick();
  inputNombre.value?.focus({ preventScroll: true });
}

/* Solicitudes que revisa el equipo. Cada tarjeta abre el MISMO formulario con
   el tipo ya elegido; el selector sigue ahí por si la persona quería otra cosa. */
const TIPOS = {
  supresion: { label: 'Eliminar mis datos', titulo: 'Solicitar que eliminemos tus datos' },
  bloqueo: { label: 'Bloquear el uso de mis datos', titulo: 'Solicitar que bloqueemos el uso de tus datos' },
  rectificacion: { label: 'Corregir un dato que no puedo editar', titulo: 'Solicitar la corrección de un dato' },
  oposicion: { label: 'Oponerme a otro uso de mis datos', titulo: 'Oponerte a un uso de tus datos' },
};
const solicitudTipo = ref('supresion');
const solicitudDetalle = ref('');
const enviandoSolicitud = ref(false);
const okSolicitud = ref('');
const errorSolicitud = ref('');
const mostrarSolicitud = ref(false);
const tituloSolicitud = computed(() => TIPOS[solicitudTipo.value]?.titulo || 'Solicitud');

async function abrirSolicitud(tipo) {
  solicitudTipo.value = tipo;
  okSolicitud.value = '';
  errorSolicitud.value = '';
  mostrarSolicitud.value = true;
  await nextTick();
  document.getElementById('cfg-solicitud')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function enviarSolicitud() {
  enviandoSolicitud.value = true;
  okSolicitud.value = '';
  errorSolicitud.value = '';
  try {
    const { data } = await api.post('/auth/solicitudes', {
      tipo: solicitudTipo.value,
      detalle: solicitudDetalle.value.trim() || null,
    });
    okSolicitud.value = data.mensaje;
    solicitudDetalle.value = '';
    mostrarSolicitud.value = false;
  } catch (e) {
    errorSolicitud.value = apiError(e, 'No se pudo registrar tu solicitud.');
  } finally {
    enviandoSolicitud.value = false;
  }
}

/* ── Contraseña ── */
const passActual = ref('');
const passNueva = ref('');
const passConfirmar = ref('');
const guardandoPass = ref(false);
const errorPass = ref('');
const okPass = ref('');

async function guardarPassword() {
  errorPass.value = '';
  okPass.value = '';
  if (!passNueva.value || passNueva.value.length < 6) {
    errorPass.value = 'La nueva contraseña debe tener al menos 6 caracteres.';
    return;
  }
  if (passNueva.value !== passConfirmar.value) {
    errorPass.value = 'Las contraseñas no coinciden.';
    return;
  }
  guardandoPass.value = true;
  try {
    const { data } = await api.patch('/auth/me', {
      password_actual: passActual.value,
      password_nueva: passNueva.value,
    });
    // Cambiar la clave invalida los tokens anteriores, incluido el de esta
    // pestaña: el backend devuelve uno nuevo para no cerrar esta sesión.
    if (data?.access_token) {
      auth.token = data.access_token;
      localStorage.setItem('access_token', data.access_token);
    }
    passActual.value = '';
    passNueva.value = '';
    passConfirmar.value = '';
    okPass.value = 'Contraseña actualizada.';
    setTimeout(() => (okPass.value = ''), 2500);
  } catch (e) {
    errorPass.value = apiError(e, 'No se pudo cambiar la contraseña.');
  } finally {
    guardandoPass.value = false;
  }
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1><span class="head-ic"><BaseIcon name="gear" :size="22" /></span> Configuración</h1>
        <p class="muted">Tus datos de contacto, tu contraseña y lo que hacemos con tu información.</p>
      </div>
    </header>

    <!-- Cuenta: dos formularios de altura parecida, lado a lado -->
    <div class="cuenta">
      <section id="cfg-datos" class="card card-pad">
        <div class="section-head"><h2>Datos personales</h2></div>
        <div class="perfil-foto">
          <EditorFoto
            :src="urlFoto(auth.user?.foto)" :iniciales="auth.iniciales" :tiene-foto="!!auth.user?.foto"
            :nombre="auth.user?.nombre || 'tu perfil'" :size="84" :subir="subirFoto" :quitar="quitarFoto"
          />
          <div>
            <strong>Foto de perfil</strong>
            <p class="muted">La ves tú y el equipo de la clínica. JPG, PNG o WebP; la recortamos en cuadrado.</p>
          </div>
        </div>
        <form class="form" @submit.prevent="guardarDatos">
          <div class="field">
            <label for="cfg-nombre">Nombre completo</label>
            <input id="cfg-nombre" ref="inputNombre" class="input" v-model="form.nombre" required />
          </div>
          <div class="field">
            <label for="cfg-email">Correo electrónico</label>
            <input id="cfg-email" class="input" type="email" v-model="form.email" required />
          </div>
          <div class="field">
            <label for="cfg-telefono">Teléfono</label>
            <input id="cfg-telefono" class="input" type="tel" v-model="form.telefono" placeholder="+56 9 1234 5678" />
          </div>

          <p v-if="errorDatos" class="form-error">{{ errorDatos }}</p>
          <p v-if="okDatos" class="form-ok">{{ okDatos }}</p>

          <button type="submit" class="btn btn-primary" :disabled="guardandoDatos">
            {{ guardandoDatos ? 'Guardando…' : 'Guardar cambios' }}
          </button>
        </form>
      </section>

      <section class="card card-pad">
        <div class="section-head"><h2>Contraseña</h2></div>
        <p class="muted hint">
          Si iniciaste sesión con Google, no tienes contraseña propia — al intentar
          cambiarla verás un aviso.
        </p>
        <form class="form" @submit.prevent="guardarPassword">
          <div class="field">
            <label for="cfg-pass-actual">Contraseña actual</label>
            <PasswordInput id="cfg-pass-actual" v-model="passActual" autocomplete="current-password" required />
          </div>
          <div class="field">
            <label for="cfg-pass-nueva">Nueva contraseña</label>
            <PasswordInput id="cfg-pass-nueva" v-model="passNueva" autocomplete="new-password" required />
          </div>
          <div class="field">
            <label for="cfg-pass-confirmar">Confirmar nueva contraseña</label>
            <PasswordInput id="cfg-pass-confirmar" v-model="passConfirmar" autocomplete="new-password" required />
          </div>

          <p v-if="errorPass" class="form-error">{{ errorPass }}</p>
          <p v-if="okPass" class="form-ok">{{ okPass }}</p>

          <button type="submit" class="btn btn-ghost" :disabled="guardandoPass">
            {{ guardandoPass ? 'Guardando…' : 'Cambiar contraseña' }}
          </button>
        </form>
      </section>
    </div>

    <!-- Privacidad: a todo el ancho, por intención -->
    <section class="card card-pad privacidad" aria-labelledby="cfg-priv-titulo">
      <div class="priv-head">
        <span class="priv-ic"><BaseIcon name="shield" :size="22" /></span>
        <div>
          <h2 id="cfg-priv-titulo">Privacidad y tus datos</h2>
          <p class="muted">
            La ley 21.719 te da seis derechos sobre tus datos. Casi todos los ejerces acá mismo.
          </p>
        </div>
      </div>

      <ul class="derechos">
        <!-- Acceso + portabilidad -->
        <li class="derecho">
          <span class="derecho-ic"><BaseIcon name="download" :size="18" /></span>
          <div class="derecho-txt">
            <h3>Ver y llevarme mis datos <span class="leyes">Acceso · Portabilidad</span></h3>
            <p class="muted">Cuenta, mascotas, fichas, citas y compras, en un archivo.</p>
          </div>
          <div class="acciones">
            <button type="button" class="btn btn-ghost btn-sm" :disabled="descargando" @click="descargarMisDatos">
              {{ descargando ? 'Preparando…' : 'Descargar' }}
            </button>
          </div>
          <p v-if="errorDescarga" class="form-error derecho-msg">{{ errorDescarga }}</p>
        </li>

        <!-- Oposición (marketing) -->
        <li class="derecho con-switch">
          <span class="derecho-ic"><BaseIcon name="mail" :size="18" /></span>
          <div class="derecho-txt">
            <h3><span id="cfg-mkt-label">Novedades y promociones</span> <span class="leyes">Oposición</span></h3>
            <p class="muted">
              Los recordatorios de citas y avisos de salud te llegan igual.
              <button type="button" class="link" @click="abrirSolicitud('oposicion')">Oponerme a otro uso</button>
            </p>
          </div>
          <div class="acciones">
            <button
              type="button"
              role="switch"
              class="interruptor"
              :class="{ on: marketing }"
              :aria-checked="marketing"
              aria-labelledby="cfg-mkt-label"
              :disabled="guardandoMarketing"
              @click="cambiarMarketing"
            ><span class="perilla"></span></button>
          </div>
          <p v-if="okMarketing" class="form-ok derecho-msg">{{ okMarketing }}</p>
          <p v-if="errorMarketing" class="form-error derecho-msg">{{ errorMarketing }}</p>
        </li>

        <!-- Rectificación -->
        <li class="derecho">
          <span class="derecho-ic"><BaseIcon name="edit" :size="18" /></span>
          <div class="derecho-txt">
            <h3>Corregir un dato <span class="leyes">Rectificación</span></h3>
            <p class="muted">Nombre, correo y teléfono, arriba. Otro dato (RUT, una mascota), lo corregimos nosotros.</p>
          </div>
          <div class="acciones">
            <button type="button" class="btn btn-ghost btn-sm" @click="irADatos">Editar</button>
            <button type="button" class="link" @click="abrirSolicitud('rectificacion')">Otro dato</button>
          </div>
        </li>

        <!-- Supresión + bloqueo -->
        <li class="derecho">
          <span class="derecho-ic"><BaseIcon name="user" :size="18" /></span>
          <div class="derecho-txt">
            <h3>Eliminar o bloquear mis datos <span class="leyes">Supresión · Bloqueo</span></h3>
            <p class="muted">
              <span class="revisa">Lo revisa el equipo</span> y te responde por correo. Si la
              ficha clínica debe conservarse, te explicamos por qué.
            </p>
          </div>
          <div class="acciones">
            <button type="button" class="btn btn-ghost btn-sm" @click="abrirSolicitud('supresion')">Eliminar</button>
            <button type="button" class="btn btn-ghost btn-sm" @click="abrirSolicitud('bloqueo')">Bloquear</button>
          </div>
        </li>
      </ul>

      <p v-if="okSolicitud" class="form-ok solicitud-ok">{{ okSolicitud }}</p>

      <form
        v-if="mostrarSolicitud"
        id="cfg-solicitud"
        class="form solicitud"
        @submit.prevent="enviarSolicitud"
      >
        <h3>{{ tituloSolicitud }}</h3>
        <div class="solicitud-campos">
          <div class="field">
            <label for="cfg-tipo">Qué necesitas</label>
            <select id="cfg-tipo" class="input" v-model="solicitudTipo">
              <option v-for="(t, clave) in TIPOS" :key="clave" :value="clave">{{ t.label }}</option>
            </select>
          </div>
          <div class="field">
            <label for="cfg-detalle">Cuéntanos (opcional)</label>
            <textarea id="cfg-detalle" class="input" rows="3" v-model="solicitudDetalle"
                      maxlength="2000" placeholder="Por ejemplo: qué dato hay que corregir."></textarea>
          </div>
        </div>
        <p v-if="errorSolicitud" class="form-error">{{ errorSolicitud }}</p>
        <div class="solicitud-btns">
          <button type="button" class="btn btn-ghost" @click="mostrarSolicitud = false">Cancelar</button>
          <button type="submit" class="btn btn-primary" :disabled="enviandoSolicitud">
            {{ enviandoSolicitud ? 'Enviando…' : 'Enviar solicitud' }}
          </button>
        </div>
      </form>

      <p class="priv-pie muted">
        Más detalle en nuestra
        <a :href="`${SITIO_URL}/privacidad`" target="_blank" rel="noopener">política de privacidad</a>.
      </p>
    </section>
  </div>
</template>

<style scoped>
.perfil-foto { display: flex; align-items: flex-start; gap: 1.1rem; padding-bottom: 1.2rem; margin-bottom: 1.2rem; border-bottom: 1px solid var(--line); }
.perfil-foto strong { display: block; color: var(--brand); font-size: var(--t-base); margin-top: 0.6rem; }
.perfil-foto .muted { font-size: var(--t-sm); margin: 0.2rem 0 0; max-width: 34ch; }

.page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.6rem; flex-wrap: wrap; }
.page-head h1 { font-size: var(--t-2xl); color: var(--brand); display: flex; align-items: center; gap: 0.6rem; }
.head-ic { width: 40px; height: 40px; border-radius: 12px; background: var(--brand-tint); color: var(--brand); display: grid; place-items: center; }
.page-head .muted { margin-top: 0.2rem; }

.cuenta { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr)); gap: 1.2rem; }
.section-head { margin-bottom: 1rem; }
.section-head h2 { font-size: var(--t-lg); color: var(--brand); }

.form { display: flex; flex-direction: column; gap: 1rem; }
.hint { font-size: var(--t-sm); margin: -0.4rem 0 0.2rem; }

.form-error { background: var(--danger-tint); color: var(--danger); padding: 0.7rem 1rem; border-radius: var(--r-md); font-size: var(--t-sm); }
.form-ok { background: var(--ok-tint); color: var(--ok); padding: 0.7rem 1rem; border-radius: var(--r-md); font-size: var(--t-sm); }

/* ── Privacidad ── */
.privacidad { margin-top: 1.2rem; }
.priv-head { display: flex; gap: 0.9rem; align-items: flex-start; margin-bottom: 1.2rem; }
.priv-head h2 { font-size: var(--t-lg); color: var(--brand); margin: 0 0 0.2rem; }
.priv-head p { margin: 0; max-width: 72ch; }
.priv-ic { width: 40px; height: 40px; border-radius: 12px; background: var(--accent-tint); color: var(--accent); display: grid; place-items: center; flex: none; }

/* Una fila por intención, separadas por línea: como tarjetas de 2×2 la sección
   ocupaba más que los dos formularios de arriba juntos, con cuatro bordes,
   cuatro insignias y párrafos de tres líneas para decir una frase. */
.derechos { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--line); }
.derecho {
  display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: start;
  gap: 0.4rem 0.9rem; padding: 0.8rem 0; border-bottom: 1px solid var(--line);
}
.derecho h3 { margin: 0; font-size: var(--t-base); color: var(--ink); }
.derecho p { margin: 0.1rem 0 0; font-size: var(--t-sm); }
.derecho-ic { width: 32px; height: 32px; border-radius: 9px; background: var(--brand-tint); color: var(--brand); display: grid; place-items: center; }
.derecho-msg { grid-column: 2 / -1; margin: 0; }

/* El nombre legal va pegado al título, en chico: los seis siguen a la vista
   sin ocupar una fila propia. */
.leyes { font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft); margin-left: 0.3rem; white-space: nowrap; }
.revisa { font-weight: 600; color: var(--warn-ink); }

.acciones { align-self: center; display: flex; align-items: center; justify-content: flex-end; gap: 0.5rem 0.9rem; flex-wrap: wrap; }

.link {
  font-size: var(--t-sm); font-weight: 600; color: var(--accent);
  text-decoration: underline; text-underline-offset: 3px;
}
.link:hover { color: var(--accent-strong); }

/* Interruptor: un botón que dice «Darme de baja» obliga a leer para saber en
   qué estado estás; la perilla lo muestra antes de leer nada. */
.interruptor {
  flex: none; position: relative; width: 48px; height: 28px; border-radius: var(--r-full);
  background: var(--line-strong); transition: background var(--dur-fast) var(--ease);
}
.interruptor.on { background: var(--accent); }
.interruptor:focus-visible { outline: 3px solid var(--accent-hover); outline-offset: 2px; }
.interruptor:disabled { opacity: 0.6; cursor: wait; }
.perilla {
  position: absolute; top: 3px; left: 3px; width: 22px; height: 22px; border-radius: 50%;
  background: #fff; box-shadow: 0 1px 3px rgba(38, 50, 110, 0.25);
  transition: transform var(--dur-fast) var(--ease);
}
.interruptor.on .perilla { transform: translateX(20px); }

.solicitud-ok { margin-top: 1rem; }
.solicitud {
  margin-top: 1rem; padding: 1.1rem 1.2rem;
  border: 1px solid var(--accent); border-radius: var(--r-md); background: var(--accent-tint);
}
.solicitud h3 { margin: 0; font-size: var(--t-lg); color: var(--brand); }
.solicitud-campos { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 2fr); gap: 1rem; align-items: start; }
.solicitud-btns { display: flex; gap: 0.6rem; flex-wrap: wrap; justify-content: flex-end; }

.priv-pie { margin: 1.1rem 0 0; font-size: var(--t-sm); }
.priv-pie a { color: var(--accent); font-weight: 600; }

@media (max-width: 760px) {
  /* El ícono al lado le robaba 50 px a un párrafo que a 400 px ya va justo. */
  .priv-head { flex-direction: column; gap: 0.6rem; }
  /* Las acciones bajan debajo del texto en vez de apretarlo contra el borde. */
  .derecho { grid-template-columns: auto minmax(0, 1fr); }
  .acciones { grid-column: 2; justify-content: flex-start; }
  /* Salvo el interruptor: abajo quedaba solo, lejos de lo que enciende. */
  .derecho.con-switch { grid-template-columns: auto minmax(0, 1fr) auto; }
  .con-switch .acciones { grid-column: 3; align-self: start; }
  .solicitud-campos { grid-template-columns: minmax(0, 1fr); }
  .solicitud-btns { justify-content: stretch; }
  .solicitud-btns .btn { flex: 1; }
}
</style>
