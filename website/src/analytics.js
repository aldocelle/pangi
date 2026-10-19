// Google Analytics 4, detrás de un consentimiento explícito.
//
// POR QUÉ EL GATE
//
// Este archivo cargaba GA en cuanto existiera `VITE_GA_MEASUREMENT_ID`, sin
// preguntarle nada a nadie. Hoy la variable no está puesta —comprobado el
// 06-sep-2026: no aparece en `.env.production` ni `googletagmanager` en el
// bundle publicado— así que no rastrea a nadie. El problema es lo fácil que
// era que empezara: una variable de entorno, escrita desde un panel, sin
// pasar por revisión de código, y el sitio arranca a poner cookies de
// terceros. Es el mismo modo de falla del incidente del 01-sep con
// `VITE_RESERVA_URL` (§11 del CLAUDE.md).
//
// La ley 21.719 homologa el estándar chileno al europeo, y ahí la analítica
// con cookies de terceros necesita consentimiento previo, informado y
// revocable. «Previo» es la palabra que manda el diseño de este archivo: el
// script de Google no se inyecta hasta que alguien acepta. No sirve cargarlo
// y después "respetar" la elección.
//
// QUÉ NO NECESITA CONSENTIMIENTO, Y POR QUÉ NO HAY BANNER SI NO HAY GA
//
// El resto del sitio no pone una sola cookie: no hay sesión, no hay carrito,
// y el `localStorage` que usa el portal de reservas es para recordar lo que
// la propia persona escribió en el formulario. Sin GA configurado no hay nada
// que consentir, así que el banner NO aparece — un aviso de cookies en un
// sitio sin cookies es ruido que enseña a la gente a aceptar sin leer.
import { paginaDe } from './seo.js';

const GA_ID = import.meta.env.VITE_GA_MEASUREMENT_ID;

const CLAVE = 'sitio:consentimiento-analitica';

// Versión del consentimiento. Subirla vuelve a preguntar a todo el mundo, y
// es lo que hay que hacer si algún día se agrega otra herramienta: lo que la
// persona aceptó fue esto, no lo que venga después.
const VERSION = '1';

/** 'si' | 'no' | null (todavía no decidió) */
export function decisionGuardada() {
  try {
    const crudo = localStorage.getItem(CLAVE);
    if (!crudo) return null;
    const { version, decision } = JSON.parse(crudo);
    return version === VERSION ? decision : null;
  } catch {
    // localStorage bloqueado (modo privado, cookies de sitio deshabilitadas).
    // Sin poder recordar la decisión, se trata como "no decidió" y no se
    // carga nada: ante la duda, no rastrear.
    return null;
  }
}

export function guardarDecision(decision) {
  try {
    localStorage.setItem(CLAVE, JSON.stringify({ version: VERSION, decision, fecha: new Date().toISOString() }));
  } catch { /* ver arriba: si no se puede guardar, se vuelve a preguntar */ }
}

/** ¿Hay algo que consentir? Si no hay GA configurado, no. */
export function analiticaDisponible() {
  return Boolean(GA_ID);
}

let cargado = false;

function cargarGA(router) {
  if (cargado || !GA_ID) return;
  cargado = true;

  const script = document.createElement('script');
  script.async = true;
  script.src = `https://www.googletagmanager.com/gtag/js?id=${GA_ID}`;
  document.head.appendChild(script);

  window.dataLayer = window.dataLayer || [];
  function gtag() { window.dataLayer.push(arguments); }
  window.gtag = gtag;
  gtag('js', new Date());
  // `send_page_view: false`: con hash routing una sola carga de página cubre
  // varias "páginas" para el usuario, así que el page_view se dispara a mano
  // en cada cambio de ruta (abajo) en vez de una sola vez al cargar.
  gtag('config', GA_ID, { send_page_view: false, anonymize_ip: true });

  const registrar = (to) => {
    const pagina = paginaDe(to.name);
    gtag('event', 'page_view', {
      page_path: to.fullPath,
      page_title: pagina.title,
      page_location: window.location.href,
    });
  };

  // La página en la que está ahora cuenta: si alguien acepta en /contacto, esa
  // visita no debería perderse solo porque el consentimiento llegó tarde.
  registrar(router.currentRoute.value);
  router.afterEach(registrar);
}

export function registrarAnalytics(router) {
  if (!GA_ID) return;
  if (decisionGuardada() === 'si') cargarGA(router);
}

/** La llama el banner cuando alguien acepta. */
export function aceptarAnalitica(router) {
  guardarDecision('si');
  cargarGA(router);
}

/** La llama el banner cuando alguien rechaza — y también «Retirar» desde la
 *  política de privacidad, que es lo que hace revocable al consentimiento. */
export function rechazarAnalitica() {
  guardarDecision('no');
  // Si ya se había cargado en esta misma pestaña, se corta el envío. La
  // recarga siguiente ni siquiera inyecta el script.
  if (GA_ID) window[`ga-disable-${GA_ID}`] = true;
}
