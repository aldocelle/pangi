<script setup>
import { ref } from 'vue';
import { CLINICA } from '../config.js';
import { useReveal } from '../useReveal.js';
import { useRouter } from 'vue-router';
import {
  analiticaDisponible, decisionGuardada, aceptarAnalitica, rechazarAnalitica,
} from '../analytics.js';

const router = useRouter();

const root = ref(null);
useReveal(root);

const ACTUALIZADO = '2 de octubre de 2026';

/* La analítica se puede revocar desde acá, que es lo que hace al
   consentimiento "revocable" de verdad: si la única forma de cambiar de
   opinión fuera borrar los datos del navegador, no sería una opción real.
   Los controles solo aparecen si hay analítica configurada — ver
   analytics.js: hoy no la hay, y un botón para desactivar algo que no está
   corriendo confunde más de lo que informa. */
const analitica = ref(decisionGuardada());
const hayAnalitica = analiticaDisponible();

function permitirAnalitica() { aceptarAnalitica(router); analitica.value = 'si'; }
function bloquearAnalitica() { rechazarAnalitica(); analitica.value = 'no'; }
</script>

<template>
  <div ref="root" class="pagina">
    <section class="seccion pp-sec">
      <div class="wrap pp-wrap" data-reveal>
        <p class="pp-actualizado">Última actualización: {{ ACTUALIZADO }}</p>
        <h1>Política de Privacidad</h1>

        <p>
          Esta política explica qué datos personales trata {{ CLINICA.nombre }} a través de
          este sitio, de la página de reservas y del portal de clientes, para qué los usa,
          con quién los comparte y qué derechos tienes sobre ellos. Se rige por la ley
          N° 19.628 sobre protección de la vida privada y, desde su entrada en vigor el
          1 de diciembre de 2026, por la ley N° 21.719 sobre protección y tratamiento de
          los datos personales.
        </p>

        <h2>1. Responsable</h2>
        <p>
          {{ CLINICA.nombre }}, con domicilio en {{ CLINICA.direccion }}, es responsable de
          los datos que se tratan a través de sus canales digitales y en la atención de la
          clínica. Para cualquier consulta sobre esta política o para ejercer tus derechos,
          escribe a <a :href="`mailto:${CLINICA.email}`">{{ CLINICA.email }}</a>.
        </p>

        <h2>2. Qué datos tratamos</h2>
        <ul class="pp-lista">
          <li><strong>Al reservar una hora:</strong> tu nombre, correo y teléfono; el nombre y los datos básicos de tu mascota; el motivo de la consulta y lo que completes en el formulario; la dirección, si la atención es a domicilio; y el estado del pago del abono.</li>
          <li><strong>Al pagar el abono:</strong> el pago lo procesa Getnet. Nosotros <strong>no recibimos ni guardamos los datos de tu tarjeta</strong>, solo el resultado (aprobado o rechazado), el monto y la referencia de la operación.</li>
          <li><strong>Al escribirnos:</strong> por el formulario de contacto, tu nombre, correo y mensaje; por WhatsApp o por el chat del sitio, tu número y el contenido de la conversación.</li>
          <li><strong>Al crear una cuenta en el portal de clientes:</strong> tus datos de contacto, el historial de citas y de compras, y la ficha clínica de tu mascota (antecedentes, notas de atención, exámenes y documentos adjuntos). Si usas «Ingresar con Google», recibimos tu nombre y correo desde tu cuenta de Google, nunca tu contraseña.</li>
          <li><strong>En la atención de la clínica:</strong> el RUT, la dirección, el teléfono y el correo de los tutores, el historial clínico y de compras de cada mascota y, si participas, tus puntos del programa de fidelización. Si ya eras cliente antes de este sistema, esos datos pasaron desde el sistema de gestión anterior de la clínica: no se obtuvieron de terceros.</li>
          <li><strong>Automáticos:</strong> este sitio hoy no usa analítica. Si se activara, solo se recopilarían datos de uso agregados (páginas visitadas, dispositivo, ubicación aproximada) y únicamente con tu permiso, como se explica en el punto 7.</li>
        </ul>
        <p>
          La ficha clínica corresponde a tu mascota, no a ti. Aun así, la asociamos a tu
          nombre y datos de contacto, y la tratamos con el mismo cuidado.
        </p>

        <h2>3. Para qué los usamos, y en qué nos basamos</h2>
        <ul class="pp-lista">
          <li><strong>Agendar, confirmar, cambiar y cancelar tus citas, y cobrar el abono</strong>, incluidos los correos de confirmación y los recordatorios. Es el servicio que nos pides.</li>
          <li><strong>Llevar la ficha clínica de tu mascota</strong>, para que su historial esté disponible en cada atención, y avisarte de vacunas o controles que le correspondan. Es parte de la atención y de nuestro interés en darle continuidad.</li>
          <li><strong>Emitir y conservar los documentos de venta</strong> y cumplir las obligaciones tributarias que nos impone la ley.</li>
          <li><strong>Responder tus consultas</strong>, por el formulario, WhatsApp o el chat.</li>
          <li><strong>Mantener seguros los sistemas</strong>: registro de las acciones del personal, límite de intentos y verificación contra robots. Es un interés legítimo nuestro y de los clientes.</li>
          <li><strong>Enviarte novedades u ofertas</strong>, solo si lo aceptas. Puedes retirar ese permiso cuando quieras desde Configuración.</li>
        </ul>
        <p>
          No vendemos tus datos ni los usamos para publicidad de terceros. No tomamos
          decisiones automatizadas que produzcan efectos jurídicos sobre ti.
        </p>

        <h2>4. Con quién se comparten</h2>
        <p>
          Tus datos no se venden ni se ceden con fines comerciales. Se comparten
          únicamente con los proveedores que hacen funcionar el servicio, y solo con lo
          que cada uno necesita:
        </p>
        <ul class="pp-lista">
          <li><strong>El proveedor de hosting</strong> — aloja este sitio, los portales, la base de datos y las copias de respaldo.</li>
          <li><strong>Google</strong> — Calendar, para crear el evento de tu cita en la agenda del especialista; Gmail, para enviarte los correos de confirmación; el inicio de sesión con Google, si lo usas; y reCAPTCHA, para distinguir personas de robots en los formularios.</li>
          <li><strong>Resend</strong> — envío de correos, cuando el canal principal no está disponible.</li>
          <li><strong>Getnet (Santander)</strong> — para cobrar el abono cuando la consulta lo exige.</li>
          <li><strong>Meta (WhatsApp)</strong> — si nos escribes por ese canal, o si te contactamos por él para confirmar o coordinar tu hora.</li>
          <li><strong>Anthropic</strong> — solo cuando el asistente conversacional con inteligencia artificial está activo y lo usas: el texto de tu mensaje se envía para generar la respuesta. Las preguntas simples, como precios u horarios, se contestan sin inteligencia artificial.</li>
          <li><strong>El especialista que te atiende</strong> recibe el aviso de tu reserva con los datos que necesita para la consulta.</li>
        </ul>
        <p>
          <strong>Varios de estos proveedores están fuera de Chile</strong> (principalmente
          en Estados Unidos y Europa), de modo que tus datos se procesan en el extranjero
          bajo las condiciones contractuales de cada servicio. Puedes ver la lista
          actualizada, con el detalle de cuáles salen del país, dentro de tu propia
          descarga de datos en el portal. También podemos entregar datos a una autoridad
          cuando la ley nos lo exija.
        </p>

        <h2>5. Cuánto tiempo los conservamos</h2>
        <p>
          Mientras mantengas una relación con la clínica (tuya o de tu mascota) y, después,
          por el plazo que exija la normativa aplicable. Los documentos de venta se
          conservan durante el plazo que fija la normativa tributaria, y la ficha clínica de
          un paciente tiene plazos de conservación propios: pueden obligarnos a mantenerlas
          aunque cierres tu cuenta. Si ocurre, te lo explicamos al responder tu solicitud.
          Lo que no estemos obligados a conservar lo eliminamos cuando lo pidas.
        </p>

        <h2>6. Tus derechos</h2>
        <p>
          Tienes seis derechos sobre tus datos personales, y puedes ejercerlos todos:
        </p>
        <ul class="pp-lista">
          <li><strong>Acceso</strong> — saber qué tenemos sobre ti y a quién se lo comunicamos. Desde el portal, en Configuración, puedes descargarlo tú mismo, al instante y sin pedírselo a nadie.</li>
          <li><strong>Rectificación</strong> — corregir lo que esté equivocado. Tu nombre, correo y teléfono los cambias tú; para el RUT o la dirección, pídelo y lo corregimos.</li>
          <li><strong>Supresión</strong> — que eliminemos tus datos, con el límite de los datos que la ley nos obliga a conservar, como se explica en el punto anterior.</li>
          <li><strong>Oposición</strong> — que dejemos de usarlos para algo puntual. Las comunicaciones comerciales las desactivas tú desde Configuración, cuando quieras.</li>
          <li><strong>Portabilidad</strong> — llevártelos a otra parte. La misma descarga del derecho de acceso viene en un formato estructurado, pensado para eso.</li>
          <li><strong>Bloqueo</strong> — suspender el uso de tus datos mientras se resuelve algo.</li>
        </ul>
        <p>
          Los tres que no son automáticos —supresión, oposición sobre otros usos, y
          bloqueo— se piden desde el portal, en Configuración, y quedan registrados con
          fecha. También puedes escribirnos a
          <a :href="`mailto:${CLINICA.email}`">{{ CLINICA.email }}</a>. Para no entregar tus
          datos a otra persona, podemos pedirte que acredites quién eres.
          <strong>Respondemos dentro de los plazos que fija la ley</strong>, y en la
          práctica intentamos hacerlo mucho antes.
        </p>
        <p>
          Si crees que no hemos respetado tus derechos, puedes reclamar ante la Agencia de
          Protección de Datos Personales, que comienza a funcionar con la ley N° 21.719 el
          1 de diciembre de 2026, o recurrir a los tribunales de justicia.
        </p>

        <h2>7. Estadísticas del sitio</h2>
        <p v-if="hayAnalitica">
          Usamos una herramienta de analítica para saber qué páginas se visitan y mejorar
          el sitio. <strong>Solo se activa si nos das permiso</strong>, y puedes cambiar de
          opinión aquí mismo cuando quieras:
        </p>
        <p v-else>
          Hoy este sitio <strong>no usa ninguna herramienta de analítica</strong> y no
          instala cookies de seguimiento. Si eso cambia, te lo preguntaremos antes de
          activarla, y podrás decidir desde esta misma página.
        </p>
        <div v-if="hayAnalitica" class="pp-analitica">
          <p class="pp-estado">
            Estado actual:
            <strong v-if="analitica === 'si'">permitida</strong>
            <strong v-else-if="analitica === 'no'">bloqueada</strong>
            <strong v-else>sin decidir</strong>
          </p>
          <div class="pp-botones">
            <button type="button" @click="bloquearAnalitica" :disabled="analitica === 'no'">No medir</button>
            <button type="button" @click="permitirAnalitica" :disabled="analitica === 'si'">Permitir</button>
          </div>
        </div>

        <h2>8. Seguridad</h2>
        <p>
          Tu contraseña nunca se guarda en texto plano, y las conexiones a nuestros
          sistemas viajan cifradas. El acceso a la información de los pacientes está
          restringido al personal de la clínica que lo necesita para atender, según su
          cargo, y las acciones del personal sobre los datos (altas, cambios, borrados y
          exportaciones) quedan registradas. Hacemos copias de respaldo diarias, guardadas
          en un lugar privado del servidor. Si ocurriera una vulneración de seguridad que
          afecte tus datos, te lo informaremos y lo comunicaremos a la autoridad cuando
          corresponda.
        </p>

        <h2>9. Cambios a esta política</h2>
        <p>
          Si cambiamos algo sustantivo, actualizamos la fecha del encabezado, te avisamos
          cuando corresponda y guardamos qué versión aceptaste, para que siempre se pueda
          saber a qué dijiste que sí.
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
.pp-lista { margin: 0 0 0.9rem; padding-left: 1.2rem; color: var(--ink-soft); line-height: 1.7; }
.pp-lista li { margin-bottom: 0.5rem; }
.pp-lista strong { color: var(--ink); }
.pp-analitica {
  margin: 0.4rem 0 0.9rem; padding: 1rem 1.1rem;
  background: var(--acento-suave); border-radius: 10px;
  display: flex; flex-wrap: wrap; align-items: center; gap: 0.8rem 1.2rem;
}
.pp-estado { margin: 0 !important; flex: 1 1 auto; }
.pp-estado strong { color: var(--ink); }
.pp-botones { display: flex; gap: 0.6rem; }
/* Mismo peso los dos: una opción destacada sobre la otra dirige la elección,
   y un consentimiento dirigido no es libre. */
.pp-botones button {
  font: inherit; font-size: 0.88rem; font-weight: 600;
  padding: 0.5rem 1rem; border-radius: 8px; cursor: pointer;
  color: var(--ink); background: var(--color-white);
  border: 1.5px solid var(--color-border);
}
.pp-botones button:hover:not(:disabled) { border-color: var(--acento); background: var(--color-white); }
.pp-botones button:disabled { opacity: 0.5; cursor: default; }
.pp-botones button:focus-visible { outline: 2px solid var(--acento); outline-offset: 2px; }
</style>
