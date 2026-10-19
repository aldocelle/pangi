import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './stores/auth.js';

// `meta.area` es la clave de permiso granular (ver PERMISO_AREAS en
// backend/app/routes/auth.py y stores/auth.js); `meta.adminOnly` es para
// lo que nunca pasa por el sistema de permisos (gestión del equipo).
// Rutas sin ninguno de los dos (login, cuenta) son accesibles a cualquier
// staff logueado.
const routes = [
  { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { public: true } },
  /* Kiosco de fichaje para el tablet compartido de recepción. `public` porque
     no hay sesión de staff —se autentica con un token de dispositivo—, y
     `kiosco` para que App.vue lo saque del layout (sin sidebar) y no le corra
     la guarda de permisos ni el fetchMe. Vive dentro del panel para no
     duplicar build, deploy ni dominio. */
  { path: '/fichaje', name: 'fichaje', component: () => import('./views/FichajeView.vue'), meta: { public: true, kiosco: true } },
  { path: '/', name: 'reservas', component: () => import('./views/ReservasView.vue'), meta: { area: 'reservas' } },
  { path: '/agenda', name: 'agenda', component: () => import('./views/AgendaView.vue'), meta: { area: 'agenda' } },
  { path: '/tareas', name: 'tareas', component: () => import('./views/TareasView.vue'), meta: { area: 'tareas' } },
  // SIN `meta.area`: fichar lo propio no exige permiso, y ponerlo acá dejaría
  // fuera de la pantalla a quien no tenga el área — o sea justo sin poder
  // marcar. Lo que se protege es la tabla del equipo, y eso lo hace el
  // backend (`require_permiso('asistencia')` en /asistencia/resumen) más la
  // propia vista, que la esconde.
  { path: '/asistencia', name: 'asistencia', component: () => import('./views/AsistenciaView.vue') },
  { path: '/recordatorios', name: 'recordatorios', component: () => import('./views/RecordatoriosView.vue'), meta: { area: 'recordatorios' } },
  // Registro de avisos por canal + envío masivo (paridad con ACVet). Mismo
  // permiso que Recordatorios: es el mismo trabajo, visto por lo que ya salió.
  { path: '/mensajes', name: 'mensajes', component: () => import('./views/MensajesView.vue'), meta: { area: 'recordatorios' } },
  /* Ajustes reúne TODO lo configurable: las cinco pestañas que vivían en el
     modal de la tuerca y las seis pantallas que colgaban del grupo
     «Configuración» del sidebar. Sin `meta.area` a propósito — la página tiene
     secciones de permisos distintos y filtra cada una por su cuenta; ponerle
     un área acá dejaría fuera, por ejemplo, a quien solo puede ver Auditoría.
     La sección va en la URL para que recargar o compartir el enlace devuelva
     a la misma pantalla. */
  { path: '/ajustes/:seccion?', name: 'ajustes', component: () => import('./views/AjustesView.vue') },

  /* Las rutas viejas siguen funcionando: quedaron enlaces en marcadores, en
     correos y dentro del propio panel. Redirigen a su sección de Ajustes. */
  { path: '/consultas', redirect: { name: 'ajustes', params: { seccion: 'consultas' } } },
  { path: '/especialistas', redirect: { name: 'ajustes', params: { seccion: 'especialistas' } } },
  /* La ficha de un especialista NO entra en Ajustes: es una pantalla de
     trabajo con su propio ancho (horarios, Google Calendar), no un panel de
     configuración embutido en una columna. */
  { path: '/especialistas/:especialistaId', name: 'especialista-detalle', component: () => import('./views/EspecialistaDetalleView.vue'), props: true, meta: { area: 'config_especialistas' } },
  { path: '/campos', redirect: { name: 'ajustes', params: { seccion: 'campos' } } },
  // adminOnly y no un área de permiso: acá se editan las instrucciones que
  // gobiernan al asistente, y eso es atribución de admin igual que Equipo.
  { path: '/asistente', redirect: { name: 'ajustes', params: { seccion: 'asistente' } } },
  { path: '/clientes', name: 'clientes', component: () => import('./views/ClientesView.vue'), meta: { area: 'personas' } },
  { path: '/equipo', redirect: { name: 'ajustes', params: { seccion: 'equipo' } } },
  { path: '/pacientes', name: 'pacientes', component: () => import('./views/PacientesView.vue'), meta: { area: 'personas' } },
  { path: '/ficha/:mascotaId', name: 'ficha', component: () => import('./views/FichaView.vue'), props: true, meta: { area: 'personas' } },
  { path: '/inventario', name: 'inventario', component: () => import('./views/InventarioView.vue'), meta: { area: 'inventario' } },
  { path: '/proveedores', name: 'proveedores', component: () => import('./views/ProveedoresView.vue'), meta: { area: 'proveedores' } },
  { path: '/pedidos', name: 'pedidos', component: () => import('./views/PedidosView.vue'), meta: { area: 'compras' } },
  { path: '/estadisticas', name: 'estadisticas', component: () => import('./views/EstadisticasView.vue'), meta: { area: 'estadisticas' } },
  { path: '/ventas', name: 'ventas', component: () => import('./views/VentasView.vue'), meta: { area: 'ventas' } },
  { path: '/caja', name: 'caja', component: () => import('./views/CajaView.vue'), meta: { area: 'caja' } },
  { path: '/consultas-web', name: 'consultas-web', component: () => import('./views/ConsultasWebView.vue'), meta: { area: 'consultas_web' } },
  { path: '/auditoria', redirect: { name: 'ajustes', params: { seccion: 'auditoria' } } },
  { path: '/cuenta', name: 'cuenta', component: () => import('./views/CuentaView.vue') },
  // `sysopOnly`: ni área ni rol, sino SYSOP_EMAILS del backend (`es_sysop`).
  { path: '/sysop', name: 'sysop', component: () => import('./views/SysopView.vue'), meta: { sysopOnly: true } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
  const auth = useAuthStore();

  // El kiosco de fichaje no pasa por sesión de staff: se autentica con un
  // token de dispositivo. Y NO debe disparar `fetchMe` —un token vencido de
  // quien entró antes al panel daría 401 y el interceptor mandaría el tablet
  // a /login.
  if (to.meta.kiosco) return;

  if (!to.meta.public && !auth.isLoggedIn) return { name: 'login' };
  if (to.name === 'login' && auth.isLoggedIn) return { name: 'reservas' };

  // Al recargar la página (F5) el token ya está en localStorage pero
  // `auth.user` todavía no — normalmente lo carga App.vue en su propio
  // onMounted, en paralelo a esta guarda. Sin esperarlo acá, la primera
  // navegación post-recarga vería auth.user=null y auth.puede() diría que
  // no a todo, mandando a cualquiera a /cuenta aunque sí tuviera acceso.
  if (auth.isLoggedIn && !auth.user) {
    await auth.fetchMe();
  }

  // Puede pasar que la URL se escriba a mano, o que un cambio de permisos
  // deje una pestaña abierta apuntando a algo que ya no le corresponde —
  // el sidebar ya esconde el link, pero eso no es seguridad real, el
  // backend igual devuelve 403. Esto solo evita el flash de una pantalla
  // vacía o llena de errores antes de que la API responda que no puede.
  if (auth.isLoggedIn) {
    const bloqueado = to.meta.sysopOnly
      ? !auth.esSysop
      : to.meta.adminOnly
        ? auth.user?.rol !== 'admin'
        : to.meta.area && !auth.puede(to.meta.area);
    if (bloqueado) return { name: 'cuenta' };
  }
});

export default router;
