import axios from 'axios';

export const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8100';

// Clave de sitio de reCAPTCHA v3 (pública, ver recaptcha.js). Mismo par
// sitio/secret que website — el backend la verifica igual para ambos
// formularios públicos (contacto y reserva).
export const RECAPTCHA_SITE_KEY = import.meta.env.VITE_RECAPTCHA_SITE_KEY || '';

export const api = axios.create({
  baseURL: API_URL,
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('access_token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

api.interceptors.response.use(
  (res) => res,
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

/* ── Fechas ── (añadido al fusionar el portal del cliente, ver App.vue) */
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

export function fechaLarga(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  return `${d.getDate()} de ${MESES[d.getMonth()]}, ${d.getFullYear()}`;
}
export function fechaCorta(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
export function horaCorta(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: true });
}
export function edadDesde(iso) {
  if (!iso) return '—';
  const nac = new Date(iso);
  const hoy = new Date();
  let años = hoy.getFullYear() - nac.getFullYear();
  let meses = hoy.getMonth() - nac.getMonth();
  if (meses < 0) { años--; meses += 12; }
  if (años <= 0) return `${meses} ${meses === 1 ? 'mes' : 'meses'}`;
  return `${años} ${años === 1 ? 'año' : 'años'}${meses ? ` ${meses} ${meses === 1 ? 'mes' : 'meses'}` : ''}`;
}

/* Foto genérica por especie (fallback cuando la mascota no tiene foto_url).
   La carpeta es `especies/` y no `mascotas/`: con ese nombre chocaba con la
   ruta /mascotas del portal, y Apache, al ver una carpeta real, redirigía a
   /mascotas/ y respondía 403 a quien recargaba Mis Mascotas. */
export function fotoEspecie(especie) {
  const e = (especie || '').toLowerCase();
  if (e.includes('gat')) return '/especies/gato.jpg';
  if (e.includes('perr')) return '/especies/perro.jpg';
  return '/especies/otro.jpg';
}

/* Las fotos subidas desde el portal vuelven como ruta relativa
   (`/uploads/<nombre>`), igual que la del especialista: se le antepone la API.
   Las que vinieron de ACVet ya son URL completas y pasan tal cual. */
export function urlFoto(u) {
  if (!u) return null;
  return u.startsWith('/') ? `${API_URL}${u}` : u;
}
export function fotoMascota(m) {
  return urlFoto(m?.foto_url) || fotoEspecie(m?.especie);
}

/* Recorta al centro en cuadrado y la vuelve a codificar en JPEG antes de
   subirla. Tres cosas a la vez: una foto de 12 MP del teléfono baja a ~100 KB,
   todas las fotos quedan con la misma forma, y se va el EXIF — que en una foto
   de celular trae la ubicación GPS de la casa del tutor, en un directorio que
   se sirve sin token. */
export async function prepararFoto(archivo, lado = 640) {
  const bmp = await createImageBitmap(archivo);
  const corte = Math.min(bmp.width, bmp.height);
  const salida = Math.min(lado, corte);
  const canvas = document.createElement('canvas');
  canvas.width = canvas.height = salida;
  canvas.getContext('2d').drawImage(
    bmp, (bmp.width - corte) / 2, (bmp.height - corte) / 2, corte, corte, 0, 0, salida, salida);
  bmp.close?.();
  const blob = await new Promise((ok) => canvas.toBlob(ok, 'image/jpeg', 0.86));
  if (!blob) throw new Error('No se pudo procesar la imagen.');
  return new File([blob], 'foto.jpg', { type: 'image/jpeg' });
}
