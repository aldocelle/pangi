import { RECAPTCHA_SITE_KEY } from './config.js';

let promesaCarga = null;

/** Inyecta el script de reCAPTCHA v3 una sola vez. Sin site key, no hace nada
 *  (el backend, a su vez, omite la verificación si no tiene secret key). */
export function precargarRecaptcha() {
  if (!RECAPTCHA_SITE_KEY) return Promise.resolve(false);
  if (promesaCarga) return promesaCarga;

  promesaCarga = new Promise((resolve) => {
    if (window.grecaptcha) {
      window.grecaptcha.ready(() => resolve(true));
      return;
    }
    const script = document.createElement('script');
    script.src = `https://www.google.com/recaptcha/api.js?render=${RECAPTCHA_SITE_KEY}`;
    script.async = true;
    script.defer = true;
    script.onload = () => window.grecaptcha.ready(() => resolve(true));
    // `resolve(false)` y no `reject`: los dos llamadores la usan como precarga
    // suelta, sin await ni catch, así que un reject dejaba una promesa
    // rechazada sin manejar en la consola de cualquiera con bloqueador de
    // anuncios o tras un cortafuegos que filtre Google. El envío no dependía
    // de esto —`obtenerTokenRecaptcha` ya devolvía null— pero el error
    // enmascaraba a los de verdad.
    script.onerror = () => resolve(false);
    document.head.appendChild(script);
  });
  return promesaCarga;
}

/** Token de un solo uso para una acción del formulario. `null` si reCAPTCHA
 *  no está configurado o si falla la carga — el backend decide qué hacer con
 *  eso, el formulario no se bloquea nunca en el intento del lado del cliente. */
export async function obtenerTokenRecaptcha(accion) {
  if (!RECAPTCHA_SITE_KEY) return null;
  try {
    if (!await precargarRecaptcha()) return null;
    return await window.grecaptcha.execute(RECAPTCHA_SITE_KEY, { action: accion });
  } catch {
    return null;
  }
}
