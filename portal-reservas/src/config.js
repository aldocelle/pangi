/* Configuración del portal (reserva pública + portal del cliente, ver
   App.vue). Editable para producción. */

// URL del sitio público (topbar y enlaces a Términos/Privacidad).
export const SITIO_URL = import.meta.env.VITE_SITIO_URL || 'http://localhost:3100';

// Carpeta desde la que se sirve el portal: '/' en su propio dominio o, por
// ejemplo, '/reservas/' dentro del sitio. Sale del `--base` de Vite al
// compilar. Las imágenes de public/ se piden con ella delante: una ruta
// absoluta como '/logo.webp' iría a buscar el archivo a la raíz del sitio.
export const BASE = import.meta.env.BASE_URL;

/* Datos de contacto de la clínica: los usa el portal entero (sidebar, Inicio,
   pie, ayuda). El nombre se fija al compilar (VITE_CLINICA_NOMBRE, ver .env).
   Dirección y horario van partidos en líneas y no en una sola cadena: en la
   columna lateral de Inicio (320 px) la cadena se cortaba donde caía. */
export const CLINICA = {
  nombre: import.meta.env.VITE_CLINICA_NOMBRE || 'PANGI',
  telefono: '+56 9 0000 0000',
  telefonoPlano: '56900000000',
  email: 'contacto@pangi.demo',
  direccion: ['Av. Principal 1234, local 5', 'Puente Alto, Región Metropolitana'],
  // Un tramo por línea: «10:00 – 14:00 y 15:00 – 18:00» no cabe en la columna.
  // Mismo horario que el sitio público (website/src/config.js, HORARIO).
  horarios: [
    { dias: 'Lunes a viernes', horas: ['10:00 – 14:00', '15:00 – 18:00'] },
    { dias: 'Sábados', horas: ['10:00 – 14:00'] },
  ],
};

/* A dónde se manda a quien agenda «consultando por WhatsApp» (especialistas
   sin horas en el sistema y consultas que la clínica coordina): al ASISTENTE
   con IA y no al WhatsApp de la clínica (decisión de producto, 12-oct-2026). El
   asistente consulta al especialista y agenda solo; la clínica, a mano.

   Sale de `VITE_WHATSAPP_BOT` y no se escribe acá a propósito: el número del
   asistente le contesta al backend que tenga conectado el webhook de Meta, y
   mandar ahí a los clientes de un servidor distinto los haría reservar en otra
   base. Sin la variable, todo sigue yendo a la clínica. */
export const WHATSAPP_RESERVAS = import.meta.env.VITE_WHATSAPP_BOT || CLINICA.telefonoPlano;
export const WHATSAPP_RESERVAS_ES_BOT = !!import.meta.env.VITE_WHATSAPP_BOT;

/* Minutos que el backend retiene el cupo sin pagar (MINUTOS_VENTANA_PAGO en
   backend/app/services/citas_expiracion.py). Duplicado a mano, como el resto de
   los valores que cruzan backend y frontend en este repo: si allá se cambia, hay
   que moverlo acá o la pantalla promete un plazo que no es el real.

   Vive acá y no en cada vista porque lo leen dos (ReservarView y PagoView), y
   durante un tiempo cada una prometía un plazo distinto: una decía 15 minutos y
   la otra «48 horas», en la misma pantalla de pago. Las 48 h no las ejecutaba
   nadie — la clínica lo notó (sep-2026). */
export const MIN_VENTANA_PAGO = 15;

// Navegación del sidebar del portal del cliente (AppShell.vue). Las
// secciones aún no construidas quedan marcadas como `proximamente` para
// no prometer lo que no existe.
//
// «Mensajes» se quitó (sep-2026, decisión de la clínica): no hay mensajería
// con el cliente. Lo que no resuelve el portal va por WhatsApp, que ya está en
// la barra superior y en la tarjeta de ayuda del sidebar.
export const NAV = [
  { label: 'Inicio', icon: 'home', to: '/inicio', name: 'inicio' },
  { label: 'Mis Mascotas', icon: 'pets', to: '/mascotas', name: 'mascotas' },
  { label: 'Citas', icon: 'calendar', to: '/citas', name: 'citas' },
  { label: 'Recordatorios', icon: 'bell', to: '/recordatorios', name: 'recordatorios', badge: true },
  { label: 'Documentos', icon: 'folder', to: '/documentos', name: 'documentos' },
  { label: 'Pagos', icon: 'card', to: '/pagos', name: 'pagos' },
  { label: 'Configuración', icon: 'gear', to: '/configuracion', name: 'configuracion' },
];
