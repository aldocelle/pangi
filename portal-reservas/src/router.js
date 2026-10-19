import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './stores/auth.js';

// `layout` en meta decide qué envoltorio usa App.vue:
//  - 'reserva'  → topbar simple (marca + volver al sitio), la reserva
//                 pública y "Nueva cita" logueado son el MISMO componente.
//  - 'publica'  → sin envoltorio (login/registro/confirmar): nadie tiene
//                 sesión todavía, no corresponde mostrar el sidebar.
//  - 'portal'   → AppShell con sidebar (requiere sesión).
const routes = [
  { path: '/', name: 'reservar', component: () => import('./views/ReservarView.vue'), meta: { layout: 'reserva' } },
  /* Misma pantalla que `/`, con ruta propia. Existe porque la RAÍZ de
     la raíz de un portal puede estar redirigida a otro sistema de agenda
     mientras la clínica migra, y ese redirect se come cualquier enlace armado
     sobre `/`, parámetros incluidos.

     Es la ruta que usa el enlace del asistente: sigue sirviendo con o sin ese
     redirect, sin depender de que nadie se acuerde. */
  { path: '/reservar', name: 'reservar-directo', component: () => import('./views/ReservarView.vue'), meta: { layout: 'reserva' } },
  { path: '/b/:slug', name: 'reservar-especialista', component: () => import('./views/ReservarView.vue'), props: true, meta: { layout: 'reserva' } },
  { path: '/citas/nueva', name: 'nueva-cita', component: () => import('./views/ReservarView.vue'), meta: { layout: 'reserva', auth: true } },
  // Vuelta desde el Web Checkout de Getnet (returnUrl) y, con la misma URL,
  // el enlace "pagar abono" del correo de confirmación. Pública: quien
  // reservó sin cuenta también tiene que poder pagar.
  { path: '/pago/:citaId', name: 'pago', component: () => import('./views/PagoView.vue'), props: true, meta: { layout: 'reserva' } },

  { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { layout: 'publica' } },
  { path: '/registro', name: 'registro', component: () => import('./views/RegistroView.vue'), meta: { layout: 'publica' } },
  { path: '/confirmar', name: 'confirmar', component: () => import('./views/ConfirmarView.vue'), meta: { layout: 'publica' } },
  { path: '/restablecer', name: 'restablecer', component: () => import('./views/RestablecerView.vue'), meta: { layout: 'publica' } },

  { path: '/inicio', name: 'inicio', component: () => import('./views/InicioView.vue'), meta: { layout: 'portal', auth: true } },
  { path: '/mascotas', name: 'mascotas', component: () => import('./views/MascotasView.vue'), meta: { layout: 'portal', auth: true } },
  { path: '/mascotas/:id', name: 'ficha', component: () => import('./views/FichaView.vue'), props: true, meta: { layout: 'portal', auth: true } },
  { path: '/citas', name: 'citas', component: () => import('./views/CitasView.vue'), meta: { layout: 'portal', auth: true } },
  { path: '/documentos', name: 'documentos', component: () => import('./views/DocumentosView.vue'), meta: { layout: 'portal', auth: true } },
  { path: '/pagos', name: 'pagos', component: () => import('./views/PagosView.vue'), meta: { layout: 'portal', auth: true } },
  { path: '/recordatorios', name: 'recordatorios', component: () => import('./views/RecordatoriosView.vue'), meta: { layout: 'portal', auth: true } },
  { path: '/configuracion', name: 'configuracion', component: () => import('./views/ConfiguracionView.vue'), meta: { layout: 'portal', auth: true } },

  { path: '/:pathMatch(.*)*', redirect: '/' },
];

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
  scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach((to) => {
  const auth = useAuthStore();
  if (to.meta.auth && !auth.isLoggedIn) return { name: 'login' };
  // "/citas/nueva" es la única ruta con layout 'reserva' que exige sesión
  // (ver meta.auth arriba) — el resto de ese layout (reservar,
  // reservar-especialista) es público a propósito, ReservarView.vue ya
  // adapta su propio comportamiento según auth.isLoggedIn.
  if (to.name === 'login' && auth.isLoggedIn) return { name: 'inicio' };
});

export default router;
