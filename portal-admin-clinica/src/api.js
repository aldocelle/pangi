import axios from 'axios';

export const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8100';

export const api = axios.create({
  baseURL: API_URL,
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('access_token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

/* Los emojis guardados en la base (especialistas y consultas) traen el selector
   U+FE0F («presentación de emoji»), y con él el navegador los pinta en COLOR
   aunque la fuente 'Emoji Mono' los tenga: sin él salen de una tinta, como el
   resto de los iconos del panel (ver --font en app.css). Se quita acá, en un
   solo punto, y solo en los campos llamados `emoji`: el texto que escribe una
   persona (notas, mensajes) no se toca. */
function sinSelectorEmoji(x) {
  if (Array.isArray(x)) { for (const v of x) sinSelectorEmoji(v); return; }
  if (!x || typeof x !== 'object') return;
  for (const k of Object.keys(x)) {
    const v = x[k];
    if (k === 'emoji' && typeof v === 'string') x[k] = v.replace(/\uFE0F/g, '');
    else if (v && typeof v === 'object') sinSelectorEmoji(v);
  }
}

api.interceptors.response.use(
  (res) => { sinSelectorEmoji(res.data); return res; },
  (err) => {
    if (err.response?.status === 401) {
      localStorage.removeItem('access_token');
      if (!location.pathname.startsWith('/login')) location.href = '/login';
    }
    return Promise.reject(err);
  }
);

export function apiError(err, fallback) {
  const detalle = err?.response?.data?.detail;
  // Los errores que escribimos nosotros (400, 404, 409…) traen `detail` como
  // texto ya redactado para el usuario: ese se muestra tal cual.
  if (typeof detalle === 'string' && detalle.trim()) return detalle;
  // Pero FastAPI usa el MISMO campo para los 422 de validación de Pydantic, y
  // ahí `detail` es un ARRAY de objetos. Devolverlo tal cual imprimía el JSON
  // crudo en pantalla — el formulario de login llegó a mostrar
  // [{"type":"value_error","loc":["body","email"],"msg":"value is not a valid…
  // a un usuario que solo se equivocó al escribir su correo. Esos mensajes
  // vienen en inglés y en jerga de Pydantic, así que no sirven para mostrar:
  // gana el mensaje del llamador, que es específico y está en español. El
  // detalle real va a la consola, que es donde hace falta para depurar.
  if (detalle) {
    console.warn('Error de validación del backend:', detalle);
    return fallback || 'Revisa los datos ingresados e intenta de nuevo.';
  }
  // La request salió pero no hubo respuesta: backend caído o sin red.
  if (err?.request && !err?.response) {
    return 'No pudimos conectar con el servidor. Verifica tu conexión o inténtalo de nuevo en unos minutos.';
  }
  return fallback || 'Ocurrió un error. Intenta de nuevo.';
}

/* El peso chileno no tiene centavos. Sin redondear, una suma de montos que
   llegan como decimal (MariaDB DECIMAL) se mostraba «267.714.690,89 CLP». */
export function formatCLP(n) {
  if (n == null) return null;
  return Math.round(Number(n)).toLocaleString('es-CL') + ' CLP';
}

export const DIAS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

/* Foto genérica por especie (mismo criterio que portal-reservas/api.js) */
export function fotoEspecie(especie) {
  const e = (especie || '').toLowerCase();
  if (e.includes('gat')) return '/mascotas/gato.jpg';
  if (e.includes('perr')) return '/mascotas/perro.jpg';
  return '/mascotas/otro.jpg';
}

/* Las fotos que suben los tutores desde el portal vuelven como ruta relativa
   (`/uploads/<nombre>`); las de ACVet, como URL completa. Mismo helper que en
   portal-reservas (sin build compartido, §9.5). */
export function urlFoto(u) {
  if (!u) return null;
  return u.startsWith('/') ? `${API_URL}${u}` : u;
}

/* Sistema de puntos: ESCONDIDO por decisión de la clínica (12-oct-2026). El
   backend sigue completo y apagado (`ventas.puntos_activo`); esto solo saca de
   la vista la sección de Ajustes y el canje en la venta. Para volver a
   mostrarlo, ponerlo en true. */
export const PUNTOS_VISIBLE = false;
