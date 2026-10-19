/* Configuración editable del sitio público. Lo que cambia de una clínica a
   otra vive acá y no repartido por los componentes. */

// A dónde llevan TODOS los enlaces de reserva del sitio: el botón del navbar,
// el del hero, "Agenda una hora", cada fila de servicio, el del pie. Es el
// portal de reservas; en producción se fija al compilar con VITE_RESERVA_URL.
export const RESERVA_URL = import.meta.env.VITE_RESERVA_URL || 'http://localhost:3101';

/* A dónde lleva una especialidad al hacer clic: el portal expone `/b/{slug}`
   por especialista, que salta directo a los horarios de esa persona.

   `directo` dice cuál de las dos salió, y con eso quien lo llama ajusta el
   texto: prometer "reservar con la Dra. X" cuando el enlace lleva al listado
   general sería mentirle a quien lo lee. */
export function enlaceEspecialista(slug) {
  const directo = !!slug;
  return {
    url: directo ? `${RESERVA_URL.replace(/\/+$/, '')}/b/${slug}` : RESERVA_URL,
    directo,
  };
}

// Backend del que el sitio carga servicios y equipo.
export const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8100';

// Clave de sitio de reCAPTCHA v3 para el formulario de Contacto (pública por
// diseño). Sin ella, recaptcha.js no carga nada y el formulario funciona igual.
export const RECAPTCHA_SITE_KEY = import.meta.env.VITE_RECAPTCHA_SITE_KEY || '';

// Datos de contacto de la clínica. Las redes en null no se muestran.
export const CLINICA = {
  nombre: import.meta.env.VITE_CLINICA_NOMBRE || 'PANGI',
  lema: 'Medicina veterinaria cercana, con todo en un solo lugar',
  direccion: 'Av. Principal 1234, local 5, Puente Alto',
  // Solo para mostrar; marcar y WhatsApp van por telefonoPlano.
  telefono: '+56 9 0000 0000',
  telefonoPlano: '56900000000', // para wa.me y tel:
  email: 'contacto@pangi.demo',
  instagram: null,
  instagramHandle: null,
  facebook: null,
  // "Cómo llegar" y el mapa de Contacto. `hl=es` fija el idioma de Maps.
  mapa: 'https://www.google.com/maps/search/?api=1&query=Puente+Alto,+Chile&hl=es',
  mapaEmbed: 'https://www.google.com/maps?q=Puente+Alto,+Chile&hl=es&output=embed',
};

// Horario de atención. Los tramos son la fuente de verdad: de acá el pie arma
// lo que se lee y decide si la clínica está abierta ahora. Lista vacía = cerrado.
export const HORARIO = [
  { dias: 'Lunes a Viernes', tramos: [['10:00', '14:00'], ['15:00', '19:00']] },
  { dias: 'Sábado', tramos: [['10:00', '14:00']] },
  { dias: 'Domingo', tramos: [] },
];

// Opiniones de la portada. Sin foto se muestra la inicial.
// PANGI es una clínica ficticia: estas opiniones son de demostración.
export const TESTIMONIOS = [
  {
    texto: 'Llevé a mi gata por una alergia y me explicaron todo con calma. Además me llegó el recordatorio de la vacuna sin que tuviera que acordarme.',
    autor: 'Carolina M.',
  },
  {
    texto: 'Reservé la hora desde el celular un domingo en la noche y el lunes nos atendieron puntual. Muy buena atención con nuestro perro.',
    autor: 'Felipe R.',
  },
  {
    texto: 'Tienen la ficha de mi perro completa: cada vez que vamos saben exactamente qué se le hizo antes. Eso da mucha tranquilidad.',
    autor: 'Josefina A.',
  },
];
