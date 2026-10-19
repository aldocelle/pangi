import { defineStore } from 'pinia';
import { api } from '../api.js';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('access_token') || null,
    user: null,
  }),
  getters: {
    isLoggedIn: (s) => !!s.token,
    primerNombre: (s) => (s.user?.nombre || 'Cliente').split(' ')[0],
    iniciales: (s) => {
      const partes = (s.user?.nombre || 'C').split(' ').filter(Boolean);
      return ((partes[0]?.[0] || '') + (partes[1]?.[0] || '')).toUpperCase();
    },
  },
  actions: {
    async login(email, password) {
      const { data } = await api.post('/auth/login', { email, password });
      this.token = data.access_token;
      localStorage.setItem('access_token', this.token);
      await this.fetchMe();
    },
    async register(payload) {
      // /auth/register no entrega sesión: la cuenta queda pendiente hasta
      // que el usuario confirma su correo (ver confirmar() más abajo).
      const { data } = await api.post('/auth/register', payload);
      return data.mensaje;
    },
    async confirmar(token) {
      const { data } = await api.post('/auth/confirmar', { token });
      this.token = data.access_token;
      localStorage.setItem('access_token', this.token);
      await this.fetchMe();
    },
    async solicitarReset(email) {
      const { data } = await api.post('/auth/solicitar-reset', { email });
      return data.mensaje;
    },
    async restablecer(token, password) {
      const { data } = await api.post('/auth/restablecer', { token, password });
      this.token = data.access_token;
      localStorage.setItem('access_token', this.token);
      await this.fetchMe();
    },
    async loginConGoogle(idToken) {
      const { data } = await api.post('/auth/google', { id_token: idToken });
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
    // Constancia de que la persona vio la política vigente (AvisoPrivacidad.vue).
    // Si falla, `user` no cambia y el aviso sigue a la vista.
    async aceptarPrivacidad() {
      await api.post('/auth/aceptar-privacidad');
      if (this.user) this.user.privacidad_pendiente = false;
    },
    logout() {
      this.token = null;
      this.user = null;
      localStorage.removeItem('access_token');
    },
  },
});
