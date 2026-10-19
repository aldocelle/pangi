import { createRouter, createWebHistory } from 'vue-router';

const routes = [
  { path: '/', name: 'home', component: () => import('./views/HomeView.vue') },
  // "Quiénes somos" se fusionó dentro de la Home; se mantiene el redirect
  // por si queda algún enlace externo o marcador apuntando aquí.
  { path: '/nosotros', redirect: '/' },
  { path: '/equipo', name: 'equipo', component: () => import('./views/EquipoView.vue') },
  { path: '/servicios', name: 'servicios', component: () => import('./views/ServiciosView.vue') },
  // Páginas de un sitio anterior: quedan como redirección a Servicios.
  { path: '/farmacia-pet-shop', redirect: '/servicios' },
  { path: '/dermatologia-domicilio', redirect: '/servicios' },
  { path: '/contacto', name: 'contacto', component: () => import('./views/ContactoView.vue') },
  { path: '/privacidad', name: 'privacidad', component: () => import('./views/PoliticaPrivacidadView.vue') },
  { path: '/terminos', name: 'terminos', component: () => import('./views/TerminosCondicionesView.vue') },
  // Antes redirigía en silencio a "/": un link roto se veía como si nada
  // hubiera pasado, y para un crawler eso es contenido duplicado bajo URLs
  // distintas. Ahora hay una página 404 real (ver seo.js, que la marca
  // "noindex" para que no compita en el buscador).
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('./views/NotFoundView.vue') },
];

const router = createRouter({
  // History mode, no hash. Es LA condición para que este sitio se encuentre.
  //
  // Con `#/servicios` el buscador no ve una página aparte: todo lo que va
  // después del `#` no viaja al servidor y Google lo descarta desde que
  // abandonó el esquema `#!` en 2015. O sea que las ocho páginas del sitio
  // colapsaban en UNA sola indexable —la portada— y el sitemap declaraba
  // siete URLs que para el buscador eran la misma.
  //
  // Y era peor que eso: el .htaccess ya reescribía `/servicios` al SPA, así
  // que esa URL respondía 200… mostrando la HOME, porque en modo hash el
  // router solo mira el fragmento. Contenido duplicado bajo siete direcciones.
  //
  // El .htaccess de public_html ya manda cualquier ruta sin extensión a
  // index.html, que es lo único que history mode necesita del servidor.
  //
  // Los enlaces viejos con `#/servicios` que anden por WhatsApp o Instagram
  // no dan 404: cargan la portada y el fragmento se ignora.
  history: createWebHistory(),
  routes,
  scrollBehavior(to) {
    // Si hay ancla (#servicios) espera al render; si no, arriba.
    if (to.hash) {
      return { el: to.hash, behavior: 'smooth', top: 80 };
    }
    return { top: 0 };
  },
});

/* Enlaces viejos con almohadilla: `/#/privacidad`, `/#/terminos`. Los siguen
   usando el registro y la reserva del portal ya publicado, y con history mode el
   fragmento se ignoraba y la persona caía en la portada: aceptaba una política
   que no podía abrir. Se reescriben a la ruta real, sin perder el historial. */
router.beforeEach((to) => {
  const m = to.path === '/' ? /^#\/([a-z0-9\-/]+)$/.exec(to.hash) : null;
  if (m) return { path: '/' + m[1], replace: true };
});

export default router;
