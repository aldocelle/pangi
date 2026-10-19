import { defineStore } from 'pinia';
import { api } from '../api.js';

/* La única área que el default NO incluye.

   Antes acá vivía una copia a mano de PERMISO_AREAS del backend, para restarle
   auditoría y armar el default. Se desincronizó: la lista decía 11 claves y el
   backend ya tenía 14, así que a un veterinario sin restricciones el panel le
   escondía Proveedores, Tareas y Recordatorios — tres secciones a las que el
   backend sí lo dejaba entrar.

   La copia no hacía falta: `permisos = null` significa «todo salvo auditoría»,
   y eso se puede responder sin conocer la lista completa. Al no existir la
   copia, tampoco puede volver a quedar vieja. La lista explícita de un usuario
   restringido sigue viniendo del backend en /auth/me y se usa tal cual. */
const AREA_RESTRINGIDA = 'auditoria';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('access_token') || null,
    user: null,
  }),
  getters: {
    isLoggedIn: (s) => !!s.token,
    // Uso: auth.puede('inventario'). Un admin siempre puede todo; un
    // veterinario según su lista de permisos (o el default si es null);
    // cualquier otro rol (no debería llegar al panel) no puede nada.
    puede: (s) => (area) => {
      if (!s.user) return false;
      if (s.user.rol === 'admin') return true;
      if (s.user.rol !== 'veterinario') return false;
      // Sin lista propia, puede todo salvo auditoría (mismo criterio que
      // PERMISOS_DEFAULT en el backend, pero sin repetir la lista de áreas).
      if (s.user.permisos == null) return area !== AREA_RESTRINGIDA;
      return s.user.permisos.includes(area);
    },
    // Lo decide el backend (SYSOP_EMAILS); acá solo se lee para dibujar.
    esSysop: (s) => s.user?.es_sysop === true,
  },
  actions: {
    async login(email, password) {
      const { data } = await api.post('/auth/login', { email, password });
      this.token = data.access_token;
      localStorage.setItem('access_token', this.token);
      await this.fetchMe();
    },
    async loginConGoogle(idToken) {
      const { data } = await api.post('/auth/google/equipo', { id_token: idToken });
      this.token = data.access_token;
      localStorage.setItem('access_token', this.token);
      await this.fetchMe();
    },
    async fetchMe() {
      if (!this.token) return;
      try {
        const { data } = await api.get('/auth/me');
        this.user = data;
      } catch {
        this.logout();
      }
    },
    logout() {
      this.token = null;
      this.user = null;
      localStorage.removeItem('access_token');
    },
  },
});
