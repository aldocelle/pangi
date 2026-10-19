// Metadatos por página (title, description, canonical, Open Graph, JSON-LD).
// El sitio es un SPA en history mode (createWebHistory en router.js), así que
// cada página tiene su URL de verdad: SITE_URL + "/ruta". Esto arma las
// etiquetas en el cliente en cada cambio de ruta.
//
// El paso que faltaba antes —salir del hash routing— ya está dado: con `#` las
// ocho páginas colapsaban en una sola indexable. Lo que sigue pendiente es el
// prerenderizado, para que el HTML llegue con contenido sin depender de que el
// buscador ejecute JavaScript.
import { CLINICA, HORARIO } from './config.js';

export const SITE_URL = import.meta.env.VITE_SITIO_URL || 'http://localhost:3100';
const OG_IMAGE = `${SITE_URL}/og-image.jpg`;

// Un sitio de demostración no se indexa: todas las páginas van con noindex.
const PAGINAS = {
  home: {
    path: '/',
    title: `${CLINICA.nombre} — Clínica veterinaria en Puente Alto`,
    description:
      'Clínica veterinaria en Puente Alto. Consultas, vacunas, cirugía, imagenología, laboratorio y peluquería. Reserva tu hora en línea.',
  },
  equipo: {
    path: '/equipo',
    title: `Nuestro equipo veterinario — ${CLINICA.nombre}`,
    description: `Conoce al equipo de médicos veterinarios y especialistas de ${CLINICA.nombre}.`,
  },
  servicios: {
    path: '/servicios',
    title: `Servicios veterinarios — ${CLINICA.nombre}`,
    description: 'Consultas, medicina preventiva, cirugía, ecografía, radiología, laboratorio, peluquería y telemedicina.',
  },
  contacto: {
    path: '/contacto',
    title: `Contacto y ubicación — ${CLINICA.nombre}`,
    description: `Dirección, teléfono, horario de atención y formulario de contacto de ${CLINICA.nombre}.`,
  },
  privacidad: {
    path: '/privacidad',
    title: `Política de Privacidad — ${CLINICA.nombre}`,
    description: `Cómo ${CLINICA.nombre} recopila, usa y protege los datos de sus clientes y sus mascotas.`,
  },
  terminos: {
    path: '/terminos',
    title: `Términos y Condiciones — ${CLINICA.nombre}`,
    description: `Condiciones de uso del sitio, la reserva de horas y el portal de clientes de ${CLINICA.nombre}.`,
  },
  'not-found': {
    title: `Página no encontrada — ${CLINICA.nombre}`,
    description: `La página que buscas no existe o fue movida. Vuelve al inicio de ${CLINICA.nombre}.`,
  },
};
for (const p of Object.values(PAGINAS)) p.noindex = true;

const DIA_A_ISO = {
  'Lunes a Viernes': ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
  Sábado: ['Saturday'],
  Domingo: ['Sunday'],
};

function schemaClinica() {
  const especificaciones = HORARIO.flatMap((bloque) => {
    const dias = DIA_A_ISO[bloque.dias] || [];
    return bloque.tramos.map(([abre, cierra]) => ({
      '@type': 'OpeningHoursSpecification',
      dayOfWeek: dias,
      opens: abre,
      closes: cierra,
    }));
  });

  return {
    '@context': 'https://schema.org',
    '@type': 'VeterinaryCare',
    name: CLINICA.nombre,
    image: OG_IMAGE,
    url: SITE_URL,
    telephone: `+${CLINICA.telefonoPlano}`,
    email: CLINICA.email,
    address: {
      '@type': 'PostalAddress',
      streetAddress: 'Av. Principal 1234, local 5',
      addressLocality: 'Puente Alto',
      addressRegion: 'Región Metropolitana',
      addressCountry: 'CL',
    },
    openingHoursSpecification: especificaciones,
    sameAs: [CLINICA.instagram, CLINICA.facebook].filter(Boolean),
  };
}

function canonicalDe(pagina) {
  if (!pagina.path) return null;
  return pagina.path === '/' ? `${SITE_URL}/` : `${SITE_URL}${pagina.path}`;
}

// Reutilizado por analytics.js para no mantener dos listas de títulos por
// ruta: uno para el <title> y otro para los eventos de page_view.
export function paginaDe(nombreRuta) {
  return (typeof nombreRuta === 'string' && PAGINAS[nombreRuta]) || PAGINAS['not-found'];
}

// `router` para escuchar cambios de ruta; `head` es la instancia de
// @unhead/vue creada en main.js.
export function registrarSEO(router, head) {
  // El schema del negocio no cambia entre páginas: se agrega una sola vez.
  head.push({
    script: [{ type: 'application/ld+json', innerHTML: JSON.stringify(schemaClinica()) }],
  });

  // Una sola entrada que se actualiza (patch) en cada navegación, en vez de
  // apilar una entrada nueva por cada cambio de ruta.
  const entrada = head.push({});

  router.afterEach((to) => {
    const pagina = paginaDe(to.name);
    const canonical = canonicalDe(pagina);
    const url = canonical || SITE_URL;

    entrada.patch({
      title: pagina.title,
      meta: [
        { name: 'description', content: pagina.description },
        { property: 'og:type', content: 'website' },
        { property: 'og:site_name', content: CLINICA.nombre },
        { property: 'og:locale', content: 'es_CL' },
        { property: 'og:title', content: pagina.title },
        { property: 'og:description', content: pagina.description },
        { property: 'og:image', content: OG_IMAGE },
        { property: 'og:url', content: url },
        { name: 'twitter:card', content: 'summary_large_image' },
        { name: 'twitter:title', content: pagina.title },
        { name: 'twitter:description', content: pagina.description },
        { name: 'twitter:image', content: OG_IMAGE },
        ...(pagina.noindex ? [{ name: 'robots', content: 'noindex, nofollow' }] : []),
      ],
      link: canonical ? [{ rel: 'canonical', href: canonical }] : [],
    });
  });
}
