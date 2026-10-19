import { defineStore } from 'pinia';
import { api } from '../api.js';
import { MIN_VENTANA_PAGO } from '../config.js';

/* ¿Esta reserva perdió su hora por no pagar el abono?
 *
 * Sí cuando el backend ya la marcó 'Expirada', y TAMBIÉN cuando sigue en
 * 'Agendada' con abono pendiente y pasó la ventana de pago. Lo segundo es la
 * misma regla que aplica `SlotsService` al leer («vuelve a ofrecerse aunque el
 * barrido todavía no la haya marcado»): el horario ya se le ofrece a otro. Sin
 * repetirla acá, el portal le seguía mostrando al tutor como suya, con
 * Reagendar y Cancelar, una hora que el sistema ya estaba regalando — pasó en
 * pruebas el 14-sep, con el barrido sin cron.
 *
 * Mismo criterio que el backend, campo por campo: solo reservas con abono
 * congelado (`monto_abono_clp`), sin pago aprobado, creadas hace más de la
 * ventana. `created_at` es marca técnica en UTC y llega sin zona: se le agrega
 * la Z, y se recorta a segundos porque Safari no lee los seis decimales. */
export function perdioLaHora(c, ahora = Date.now()) {
  if (c.estado === 'Expirada') return true;
  const ms = msParaPagar(c, ahora, true);
  // Desde el 12-oct el servidor manda su veredicto (`hora_liberada`), que
  // conoce el caso de la hora coordinada por la clínica: su plazo corre desde
  // que se le mandó el enlace, no desde que se agendó. Se respeta, pero la
  // cuenta del reloj manda si venció con la página abierta.
  if (c.hora_liberada === true) return true;
  return ms !== null && ms <= 0;
}

/* Milisegundos que le quedan a la reserva para pagar el abono, o null si no
   espera ningún pago en línea. `incluirVencidas` deja pasar los negativos, que
   es lo que usa `perdioLaHora`; la cuenta regresiva de la pantalla no los
   quiere. Misma regla que el backend: monto congelado, pago no aprobado, y la
   ventana medida desde `created_at` (UTC). El reloj es el del navegador: si
   está corrido, la cuenta también, pero el barrido del servidor manda igual. */
export function msParaPagar(c, ahora = Date.now(), incluirVencidas = false) {
  if (c.estado !== 'Agendada' || c.monto_abono_clp == null || c.payment_status === 'approved') return null;
  // Con backend del 12-oct en adelante, el plazo lo dice el servidor
  // (`pagar_hasta`, UTC con Z; null = no corre plazo). Sin el campo —backend
  // anterior— se calcula como antes, desde `created_at`.
  let fin;
  if ('pagar_hasta' in c) {
    if (!c.pagar_hasta) return null;
    fin = Date.parse(c.pagar_hasta);
  } else {
    if (!c.created_at) return null;
    fin = Date.parse(`${String(c.created_at).slice(0, 19)}Z`) + MIN_VENTANA_PAGO * 60_000;
  }
  if (!Number.isFinite(fin)) return null;
  const ms = fin - ahora;
  return incluirVencidas || ms > 0 ? ms : null;
}

const cuentaComoProxima = (c, ahora) =>
  new Date(c.fecha_hora).getTime() >= ahora && c.estado !== 'Cancelada' && !perdioLaHora(c, ahora);

let reloj = null;

/* Días hacia adelante en que un cuidado ya pide acción. Coincide con la ventana
   «próximo» que usa la clínica para llamar: más allá, avisarlo solo mete ruido. */
export const DIAS_AVISO_CUIDADO = 30;

/* Store compartido: mascotas y citas del cliente logueado.
   Se cargan una vez y las vistas las reutilizan. */
export const useDatosStore = defineStore('datos', {
  state: () => ({
    mascotas: [],
    citas: [],
    cargado: false,
    cargando: false,
    /* Recordatorios CLÍNICOS de la clínica (`/recordatorios/mios`): vacuna que
       vence, control que sigue. Antes el portal no los pedía y llamaba
       «recordatorios» a las próximas citas, que ya están en Citas. */
    cuidados: [],
    /* «Ahora», en estado para que las listas se recalculen solas: sin esto una
       reserva cuya ventana de pago vence mientras la persona mira la pantalla
       seguía en Próximas hasta recargar, con la cuenta en 0:00. */
    ahora: Date.now(),
  }),
  getters: {
    // Citas futuras que siguen en pie, ordenadas
    proximasCitas: (s) =>
      s.citas
        .filter((c) => cuentaComoProxima(c, s.ahora))
        .sort((a, b) => new Date(a.fecha_hora) - new Date(b.fecha_hora)),
    // Todo lo demás (pasadas, canceladas, sin abono), más recientes primero
    citasAnteriores: (s) =>
      s.citas
        .filter((c) => !cuentaComoProxima(c, s.ahora))
        .sort((a, b) => new Date(b.fecha_hora) - new Date(a.fecha_hora)),
    mascotaPorId: (s) => (id) => s.mascotas.find((m) => m.id === id),
    citasDeMascota: (s) => (id) => s.citas.filter((c) => c.mascota_id === id),
    // La próxima cita vigente de una mascota, o null.
    proximaCitaDe() {
      return (mascotaId) => this.proximasCitas.find((c) => c.mascota_id === mascotaId) || null;
    },
    /* Lo que cuenta la campana: cuidados vencidos o dentro de la ventana de
       aviso, de mascotas que NO tienen ya una hora tomada.

       Antes contaba las próximas citas que el cliente no había abierto, con el
       «visto» guardado en localStorage: la campana avisaba de horas que la
       persona misma había reservado, y volvía a encenderse en otro navegador.
       Ahora sale del estado de las cosas, no de lo que se miró: se apaga sola
       cuando hay hora, o cuando la clínica da el cuidado por completado. */
    cuidadosPorAtender() {
      return this.cuidados.filter((r) =>
        (r.vencido || (r.dias_restantes ?? Infinity) <= DIAS_AVISO_CUIDADO)
        && !this.proximaCitaDe(r.mascota_id));
    },
  },
  actions: {
    /* La foto vuelve en la mascota completa: se reemplaza acá para que Inicio,
       Mis Mascotas, la ficha y Citas la vean sin recargar, la cambie quien la
       cambie. */
    ponerMascota(m) {
      const i = this.mascotas.findIndex((x) => x.id === m.id);
      if (i >= 0) this.mascotas[i] = m;
    },
    async subirFotoMascota(id, archivo) {
      const fd = new FormData();
      fd.append('archivo', archivo);
      const { data } = await api.post(`/mascotas/${id}/foto`, fd);
      this.ponerMascota(data);
    },
    async quitarFotoMascota(id) {
      const { data } = await api.delete(`/mascotas/${id}/foto`);
      this.ponerMascota(data);
    },
    /* Un solo intervalo para toda la app. Mira cada segundo, pero solo mueve
       `ahora` (y con eso recalcula las listas) cada segundo si hay una reserva
       esperando pago; si no, cada 30 s, que basta para que una cita que ya
       pasó baje a Anteriores. Mirar cada segundo evita que una reserva recién
       cargada espere hasta 30 s para empezar su cuenta. */
    iniciarReloj() {
      if (reloj) return;
      reloj = setInterval(() => {
        const t = Date.now();
        const hayCuenta = this.citas.some((c) => msParaPagar(c, t) !== null);
        if (hayCuenta || t - this.ahora >= 30_000) this.ahora = t;
      }, 1000);
    },
    async cargar(forzar = false) {
      if (this.cargado && !forzar) return;
      this.cargando = true;
      try {
        const [m, c, r] = await Promise.all([
          api.get('/mascotas/'),
          api.get('/citas/mias'),
          // Aparte y sin propagar el error: si falla, el portal sigue
          // mostrando mascotas y citas, que es lo que la persona vino a ver.
          api.get('/recordatorios/mios').catch(() => ({ data: [] })),
        ]);
        this.mascotas = m.data;
        this.citas = c.data;
        this.cuidados = r.data;
        this.cargado = true;
      } finally {
        this.cargando = false;
      }
    },
    /* Marca la caché como vencida sin pedir nada todavía.
     *
     * `cargar()` corta de entrada si `cargado` está en true, que es lo que
     * hace que entrar a Mascotas o a Citas no dispare una petición cada vez.
     * El precio es que quien reserva una hora DENTRO de la misma sesión de la
     * SPA no la veía aparecer: el store seguía con la lista de antes y
     * `/citas` mostraba «No tienes citas próximas» con la cita ya creada en la
     * base. Solo se arreglaba recargando la página entera.
     *
     * Cancelar y reagendar ya llamaban a `cargar(true)`; lo que faltaba era
     * avisar al CREAR. Se invalida en vez de recargar para no gastar dos
     * peticiones en la pantalla de "gracias", que no las usa: la próxima
     * visita a Citas o Mascotas las trae. */
    invalidar() {
      this.cargado = false;
    },
  },
});
