<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { api, apiError, fotoEspecie, urlFoto, formatCLP } from '../api.js';
import { useAuthStore } from '../stores/auth.js';
import BuscadorAvanzado from '../components/BuscadorAvanzado.vue';
import Paginador from '../components/Paginador.vue';
import ReagendarCitaModal from '../components/ReagendarCitaModal.vue';

function iso(d) {
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

const hoy = new Date();
// Mismo supuesto que `desde`: el día se fija al abrir la página. Se guarda
// como texto porque las fechas del backend llegan en 'YYYY-MM-DD' y comparar
// dos cadenas con ese formato ordena igual que comparar dos fechas.
const HOY_ISO = iso(hoy);
const desde = ref(iso(hoy));
const hasta = ref(iso(new Date(hoy.getFullYear(), hoy.getMonth() + 1, hoy.getDate())));

const filtros = ref({ q: '', servicio_id: '', especialista_id: '', estado: '' });

const reservas = ref([]);
const servicios = ref([]);
const especialistas = ref([]);
const loading = ref(true);
const error = ref('');
const abierta = ref(null); // id de reserva expandida

const ESTADOS = ['Agendada', 'Confirmada', 'Completada', 'Cancelada'];

const auth = useAuthStore();

/* ── Recordatorios y tareas, en la portada ────────────────────────────────
   La clínica pidió verlos acá y no solo en su propia pantalla: son trabajo
   del día, y una sección a la que hay que navegar es una sección que se
   olvida. Van resumidos —lo vencido, lo de hoy, y un enlace al listado
   completo— porque esta página sigue siendo Reservas, no un tablero.

   Cada bloque se pide solo si el usuario tiene el permiso del área. Sin ese
   chequeo, quien entra con acceso únicamente a Reservas dispararía dos 403 al
   abrir el panel y vería dos tarjetas vacías sin saber por qué. */
const MAX_EN_PORTADA = 4;

const recordatorios = ref([]);
const recordatoriosTotal = ref(0);
const tareas = ref([]);

const veRecordatorios = computed(() => auth.puede('recordatorios'));
const veTareas = computed(() => auth.puede('tareas'));

const tareasPortada = computed(() => tareas.value.slice(0, MAX_EN_PORTADA));
/* `fecha_vencimiento` llega como 'YYYY-MM-DD', así que comparar como texto
   ordena igual que comparar como fecha y evita construir un Date por tarea. */
const tareasVencidas = computed(() =>
  tareas.value.filter((t) => estaVencida(t)).length
);
function estaVencida(t) {
  return !!t.fecha_vencimiento && t.fecha_vencimiento < HOY_ISO;
}
const recordatoriosVencidos = computed(() => recordatorios.value.filter((r) => r.vencido).length);

async function cargarRecordatorios() {
  if (!veRecordatorios.value) return;
  try {
    /* Pendientes con fecha de hoy o anterior: exactamente la cola de llamados.
       El backend los devuelve del más atrasado al menos, así que los primeros
       cuatro ya son los que más urgen. */
    const { data } = await api.get('/recordatorios', {
      params: { estado: 'pendiente', hasta: HOY_ISO, limit: MAX_EN_PORTADA },
    });
    recordatorios.value = data.items || [];
    recordatoriosTotal.value = data.total ?? recordatorios.value.length;
  } catch {
    /* Silencio a propósito: esto es un accesorio de la portada. Que se caiga
       el resumen no puede impedir ver las reservas, que es a lo que la
       persona entró. */
  }
}

async function cargarTareas() {
  if (!veTareas.value) return;
  try {
    const { data } = await api.get('/tareas', { params: { completada: false } });
    tareas.value = data;
  } catch {
    /* Ídem recordatorios. */
  }
}

async function completarTarea(t) {
  try {
    await api.patch(`/tareas/${t.id}`, { completada: true });
    tareas.value = tareas.value.filter((x) => x.id !== t.id);
  } catch (e) {
    error.value = apiError(e, 'No se pudo marcar la tarea como hecha.');
  }
}

/* ── Abonos pendientes ─────────────────────────────────────────────────────
   Panel aparte del listado de abajo, y fuera de su rango de fechas a
   propósito: quien reservó y no abonó puede haberlo hecho semanas atrás,
   lejos de la ventana que el equipo esté mirando. Con Getnet conectado el
   caso normal se resuelve solo (la pasarela marca 'approved' al cobrar), así
   que lo que queda acá es exactamente lo que necesita a una persona: el que
   abandonó el checkout, o el que pagó por transferencia.
   `GET /citas/pendientes-abono` las devuelve de la más antigua a la más
   nueva — el orden en que se vencen. */
const PENDIENTES_POR_PAGINA = 20;
const HORAS_PLAZO = 48; // política de la clínica: 48h desde la reserva para abonar

const pendientes = ref([]);
const pendientesAbierto = ref(false);
const pagPendientes = ref(1);
const confirmando = ref(null); // id en curso: evita el doble click sobre el mismo abono

const totalPaginasPendientes = computed(() =>
  Math.max(Math.ceil(pendientes.value.length / PENDIENTES_POR_PAGINA), 1)
);
const pendientesPagina = computed(() => {
  const inicio = (pagPendientes.value - 1) * PENDIENTES_POR_PAGINA;
  return pendientes.value.slice(inicio, inicio + PENDIENTES_POR_PAGINA);
});
const vencidos = computed(() => pendientes.value.filter(fueraDePlazo).length);

/* El plazo corre desde que se reservó, no desde la fecha de la cita: una hora
   pedida para dentro de dos meses igual tiene que abonarse en 48h. */
function horasEsperando(r) {
  return Math.floor((Date.now() - new Date(r.created_at).getTime()) / 3600000);
}
function fueraDePlazo(r) {
  return horasEsperando(r) >= HORAS_PLAZO;
}
function esperaLabel(r) {
  const h = horasEsperando(r);
  if (h < 1) return 'recién reservada';
  if (h < 24) return `hace ${h} h`;
  const d = Math.floor(h / 24);
  return `hace ${d} día${d === 1 ? '' : 's'}`;
}

function fechaCorta(isoStr) {
  return new Date(isoStr).toLocaleDateString('es-CL', { day: 'numeric', month: 'short' });
}

/* Etiqueta del abono en el listado principal. Sin `monto_abono_clp` la
   consulta no exige abono y no se muestra nada: es el caso de la mayoría de
   las reservas, y un badge en todas no distinguiría nada. */
const PAGO_LABEL = {
  approved: 'Abono pagado',
  pending: 'Abono pendiente',
  rejected: 'Abono rechazado',
  cancelled: 'Abono cancelado',
};
function pagoLabel(r) {
  if (!r.monto_abono_clp) return null;
  // Quien pagó el total no tiene "abono pagado": no queda saldo, y llamarlo
  // abono haría que en el mostrador le cobren de nuevo la otra mitad.
  if (r.payment_status === 'approved' && r.pago_modalidad === 'total') return 'Pagado completo';
  return PAGO_LABEL[r.payment_status] || 'Abono pendiente';
}

/* Lo que queda por cobrar el día de la atención. 0 si pagó todo. */
function saldoPendiente(r) {
  const total = r.monto_total_clp || (r.monto_abono_clp ? r.monto_abono_clp * 2 : 0);
  if (!total) return 0;
  if (r.payment_status !== 'approved') return total;
  return r.pago_modalidad === 'total' ? 0 : total - (r.monto_abono_clp || 0);
}

async function cargarPendientes() {
  try {
    const { data } = await api.get('/citas/pendientes-abono');
    pendientes.value = data;
    // Abre solo si hay algo vencido. Con todo dentro de plazo el panel es
    // informativo y estorba; con un abono pasado de 48h es trabajo por hacer
    // y nadie debería tener que descubrirlo abriendo un desplegable.
    if (vencidos.value) pendientesAbierto.value = true;
  } catch (e) {
    // No es fatal: el listado de reservas es lo que la vista debe mostrar sí
    // o sí, y este panel es un agregado.
    error.value = apiError(e, 'No se pudieron cargar los abonos pendientes.');
  }
}

/* Confirmación a mano, solo para el abono que llegó por fuera de la pasarela
   (transferencia o efectivo en el mostrador). El cobro con tarjeta nunca pasa
   por acá: lo marca el backend con la respuesta de Getnet.

   Hay que decir CÓMO llegó, y no es un detalle administrativo: el efectivo
   entra al cajón del turno y la transferencia no. Antes no se preguntaba y no
   entraba nunca — se registraron $75.000 en abonos que Caja no vio por ningún
   lado, y el cierre habría dado corto por ese monto. */
const eligiendoMedio = ref(null);

async function confirmarAbono(r, medio) {
  eligiendoMedio.value = null;
  confirmando.value = r.id;
  try {
    await api.patch(`/citas/${r.id}/abono-confirmado`, { medio });
    pendientes.value = pendientes.value.filter((p) => p.id !== r.id);
    // Confirmar el último de la página deja esa página sin existir.
    if (pagPendientes.value > totalPaginasPendientes.value) {
      pagPendientes.value = totalPaginasPendientes.value;
    }
    // La misma cita puede estar visible en el listado de abajo: se actualiza
    // en memoria en vez de recargar el rango completo.
    const enLista = reservas.value.find((x) => x.id === r.id);
    if (enLista) {
      enLista.payment_status = 'approved';
      if (enLista.estado === 'Agendada') enLista.estado = 'Confirmada';
    }
  } catch (e) {
    error.value = apiError(e, 'No se pudo registrar el abono.');
  } finally {
    confirmando.value = null;
  }
}

/* Enlace de pago para una hora ya tomada — típicamente una del mostrador, que
   nace sin monto. El backend (LinkDePago) congela el abono y decide si se puede;
   acá solo se muestra el enlace para copiarlo o mandarlo por WhatsApp.
   Desde el 12-oct (regla de producto) también esta hora vence: el tutor tiene 15
   minutos para pagar DESDE QUE SE GENERA EL ENLACE (`pagar_hasta`); si no paga,
   la hora se libera. Por eso se genera recién al mandarlo, y se le dice el plazo. */
const links = ref({}); // id de cita → { url, a_pagar_clp, exige_total, pagar_hasta }
/* `pagar_hasta` llega en UTC con «Z»; el navegador lo pasa a la hora local. */
const horaLimite = (iso) => (iso ? new Date(iso).toTimeString().slice(0, 5) : '');
const generandoLink = ref(null);
const copiado = ref(null);

function cobraEnLinea(r) {
  if (r.monto_abono_clp) return true;
  const s = servicios.value.find((x) => x.id === r.servicio_id);
  return !!s && (s.modalidad_pago || 'abono') !== 'nada';
}
function puedeLink(r) {
  return r.estado === 'Agendada' && r.payment_status !== 'approved'
    && new Date(r.fecha_hora) > new Date() && cobraEnLinea(r);
}

async function generarLink(r) {
  generandoLink.value = r.id;
  try {
    const { data } = await api.post(`/citas/${r.id}/link-pago`);
    links.value = { ...links.value, [r.id]: data };
    r.monto_abono_clp = data.monto_abono_clp;
    r.monto_total_clp = data.monto_total_clp;
    r.payment_status = r.payment_status || 'pending';
  } catch (e) {
    error.value = apiError(e, 'No se pudo generar el enlace de pago.');
  } finally {
    generandoLink.value = null;
  }
}

async function copiarLink(r) {
  try {
    await navigator.clipboard.writeText(links.value[r.id].url);
    copiado.value = r.id;
    setTimeout(() => { if (copiado.value === r.id) copiado.value = null; }, 2000);
  } catch {
    // Sin permiso de portapapeles el enlace sigue a la vista para copiarlo a mano.
  }
}

/* wa.me necesita el número con código de país y sin signos. Solo se ofrece si
   el teléfono tiene forma de celular chileno: adivinar uno mal tecleado
   mandaría el enlace de pago a un desconocido. */
function waLink(r) {
  let d = String(r.dueno.telefono || '').replace(/\D/g, '');
  if (d.length === 11 && d.startsWith('56')) d = d.slice(2);
  if (d.length !== 9) return null;
  const l = links.value[r.id];
  const que = l.exige_total ? 'el pago' : 'el abono';
  const plazo = l.pagar_hasta
    ? ` Tienes hasta las ${horaLimite(l.pagar_hasta)} para pagar; si no, la hora se libera.`
    : '';
  const texto = `Hola ${r.dueno.nombre || ''}, te enviamos el enlace para pagar ${que} de ${formatCLP(l.a_pagar_clp)} `
    + `de la hora de ${r.mascota.nombre} (${r.servicio}): ${l.url}${plazo}`;
  return `https://wa.me/56${d}?text=${encodeURIComponent(texto)}`;
}

/* Mismo buscador que Agenda, Tutores y Pacientes: un solo componente para
   todos los listados del panel, en vez de una fila de filtros hecha a mano por
   vista. Las opciones son `computed` porque servicios y especialistas llegan
   por HTTP después del primer render. El rango de fechas se queda arriba, en
   la cabecera: no es un filtro más, es el alcance de lo que se está mirando. */
const CAMPOS_BUSQUEDA = computed(() => [
  { key: 'q', label: 'Buscar', placeholder: 'Buscar por dueño, correo o mascota…' },
  {
    key: 'servicio_id', label: 'Consulta', type: 'select',
    options: servicios.value.map((s) => ({ value: s.id, label: s.nombre })),
  },
  {
    key: 'especialista_id', label: 'Especialista', type: 'select',
    options: especialistas.value.map((e) => ({ value: e.id, label: `${e.emoji || '🩺'} ${e.nombre}` })),
  },
  { key: 'estado', label: 'Estado', type: 'select', options: ESTADOS.map((e) => ({ value: e, label: e })) },
]);

const porDia = computed(() => {
  const map = new Map();
  for (const r of reservas.value) {
    const dia = r.fecha_hora.slice(0, 10);
    if (!map.has(dia)) map.set(dia, []);
    map.get(dia).push(r);
  }
  return [...map.entries()];
});

function labelDia(isoStr) {
  const [y, m, d] = isoStr.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('es-CL', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
  });
}

function horas(r) {
  const ini = new Date(r.fecha_hora);
  const fin = new Date(ini.getTime() + (r.duracion_min || 30) * 60000);
  const f = (d) => d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false });
  return `${f(ini)} - ${f(fin)}`;
}

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const params = { desde: desde.value, hasta: hasta.value };
    const f = filtros.value;
    if (f.q?.trim()) params.q = f.q.trim();
    if (f.servicio_id) params.servicio_id = f.servicio_id;
    if (f.especialista_id) params.especialista_id = f.especialista_id;
    if (f.estado) params.estado = f.estado;
    const { data } = await api.get('/citas/reservas', { params });
    reservas.value = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar las reservas.');
  } finally {
    loading.value = false;
  }
}

/* Reagendar: hasta ahora el panel solo cambiaba el ESTADO, así que mover una
   hora por teléfono obligaba a cancelar y crear de nuevo — perdiendo el
   historial y las respuestas del formulario. Una cita sin
   servicio_id/especialista_id (importada de ACVet, o creada a mano) no se
   puede mover: el backend responde 400, así que no se ofrece el botón. */
const citaReagendando = ref(null);

function puedeReagendar(r) {
  return !!r.servicio_id && !!(r.especialista_id || r.especialista?.id) && r.estado !== 'Cancelada';
}

async function alReagendar() {
  citaReagendando.value = null;
  await cargar();
}

async function cambiarEstado(r, estado) {
  try {
    await api.patch(`/citas/${r.id}/estado`, { estado });
    r.estado = estado;
    // Cancelar una hora pagada abre una devolución: que aparezca en la cola ya.
    if (estado === 'Cancelada' && r.payment_status === 'approved') cargarDevoluciones();
  } catch (e) {
    error.value = apiError(e, 'No se pudo actualizar el estado.');
  }
}

/* ── Devoluciones por revisar ──────────────────────────────────────────────
   Horas PAGADAS que se cancelaron. La devolución la hace una persona (decisión
   de la clínica), y el tutor puede cancelar solo —por el portal o por el
   asistente de WhatsApp— sin que nadie del equipo lo vea. Esta cola es lo que
   evita que una se olvide: queda arriba hasta que alguien la marca.
   El monto sugerido sigue la política: 60% de lo pagado con 24 h de aviso. */
const devoluciones = ref([]);
const resolviendo = ref(null);

async function cargarDevoluciones() {
  try {
    const { data } = await api.get('/citas/devoluciones-pendientes');
    devoluciones.value = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar las devoluciones pendientes.');
  }
}

function avisoLabel(d) {
  if (d.horas_aviso == null) return 'Sin dato de cuándo se canceló';
  if (d.horas_aviso < 24) return `Canceló con ${Math.max(d.horas_aviso, 0)} h de aviso`;
  const dias = Math.floor(d.horas_aviso / 24);
  return `Canceló con ${dias} día${dias === 1 ? '' : 's'} de aviso`;
}

async function resolverDevolucion(r, resultado) {
  resolviendo.value = r.id;
  try {
    await api.patch(`/citas/${r.id}/devolucion`, { resultado });
    devoluciones.value = devoluciones.value.filter((x) => x.id !== r.id);
  } catch (e) {
    error.value = apiError(e, 'No se pudo registrar la devolución.');
  } finally {
    resolviendo.value = null;
  }
}

/* El buscador emite `buscar` en cada tecla (a partir de 2 caracteres) y al
   confirmar un select; el respiro evita una consulta por pulsación sin que se
   note al escribir. Los selects pasan por el mismo camino: 250 ms de más en un
   cambio de filtro no se perciben, y así hay un solo punto de entrada. */
let timer;
function buscar() {
  clearTimeout(timer);
  timer = setTimeout(cargar, 250);
}
watch([desde, hasta], cargar);

onMounted(async () => {
  const [s, e] = await Promise.all([
    api.get('/catalogo/servicios/todos'),
    api.get('/catalogo/especialistas/todos'),
  ]);
  servicios.value = s.data;
  // Solo activos: los 54 especialistas del legado ("Médico #6362 (ACVet)") no
  // son personal real y llenarían el desplegable. Mismo criterio que Agenda y
  // EspecialistasView; no se pueden borrar porque cuelgan del histórico.
  especialistas.value = e.data.filter((x) => x.activo);
  // Los abonos pendientes no dependen del rango ni de los filtros: se piden
  // una sola vez, en paralelo con el primer listado.
  await Promise.all([cargar(), cargarPendientes(), cargarDevoluciones(), cargarRecordatorios(), cargarTareas()]);
});
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Reservas</h1>
        <p class="page-sub">Las horas agendadas por los clientes, por rango de fechas.</p>
      </div>
      <div class="rango">
        <input type="date" class="input" v-model="desde" aria-label="Desde" />
        <span>—</span>
        <input type="date" class="input" v-model="hasta" aria-label="Hasta" />
      </div>
    </div>

    <!-- Devoluciones por revisar. Siempre abierta: cada fila es plata de un
         cliente esperando una respuesta, no algo para descubrir en un
         desplegable. -->
    <section v-if="devoluciones.length" class="card abonos devoluciones">
      <div class="abonos-head">
        <span class="abonos-punto vencido"></span>
        <span class="abonos-titulo">
          {{ devoluciones.length }} hora{{ devoluciones.length === 1 ? '' : 's' }}
          pagada{{ devoluciones.length === 1 ? '' : 's' }} y cancelada{{ devoluciones.length === 1 ? '' : 's' }}:
          revisar devolución
        </span>
      </div>
      <div class="abonos-body">
        <p class="abonos-nota">
          Política: con 24 horas o más de aviso se devuelve el 60% de lo pagado; con menos, no hay
          reembolso. Haz la devolución por fuera del sistema y márcala acá.
        </p>
        <article v-for="r in devoluciones" :key="r.id" class="ab-row">
          <div class="ab-celda">
            <strong>{{ r.mascota.nombre }}</strong>
            <small>{{ r.dueno.nombre }} · {{ r.dueno.telefono || r.dueno.email }}</small>
          </div>
          <div class="ab-celda">
            <span>{{ fechaCorta(r.fecha_hora) }} · {{ horas(r) }}</span>
            <small>{{ r.servicio }} · {{ avisoLabel(r.devolucion) }}</small>
          </div>
          <div class="ab-celda ab-monto">
            <strong>
              {{ r.devolucion.corresponde_clp == null ? 'Decidir'
                : (r.devolucion.corresponde_clp ? `Devolver ${formatCLP(r.devolucion.corresponde_clp)}` : 'No corresponde') }}
            </strong>
            <small>Pagó {{ formatCLP(r.devolucion.pagado_clp) }}</small>
          </div>
          <div class="medio-abono">
            <button
              class="btn btn-accent btn-sm" :disabled="resolviendo === r.id"
              @click="resolverDevolucion(r, 'devuelta')"
            >Devuelta</button>
            <button
              class="btn btn-ghost btn-sm" :disabled="resolviendo === r.id"
              @click="resolverDevolucion(r, 'no_corresponde')"
            >No corresponde</button>
          </div>
        </article>
      </div>
    </section>

    <!-- Cola de abonos por confirmar. Va arriba del buscador porque no es
         algo que se consulte, es algo que hay que hacer; y no lo tocan ni el
         rango de fechas ni los filtros de abajo. -->
    <section v-if="pendientes.length" class="card abonos">
      <button
        class="abonos-head" :aria-expanded="pendientesAbierto"
        @click="pendientesAbierto = !pendientesAbierto"
      >
        <span class="abonos-punto" :class="{ vencido: vencidos > 0 }"></span>
        <span class="abonos-titulo">
          {{ pendientes.length }} reserva{{ pendientes.length === 1 ? '' : 's' }}
          con abono pendiente
        </span>
        <span v-if="vencidos" class="abonos-alerta">
          {{ vencidos }} pasada{{ vencidos === 1 ? '' : 's' }} de las {{ HORAS_PLAZO }} h
        </span>
        <span class="rsv-caret" :class="{ open: pendientesAbierto }">▾</span>
      </button>

      <div v-if="pendientesAbierto" class="abonos-body">
        <p class="abonos-nota">
          El pago con tarjeta se marca solo cuando Getnet lo aprueba. Registra el
          abono a mano únicamente si llegó por transferencia o en la clínica.
        </p>
        <article
          v-for="r in pendientesPagina" :key="r.id"
          class="ab-row" :class="{ vencido: fueraDePlazo(r) }"
        >
          <div class="ab-celda">
            <strong>{{ r.mascota.nombre }}</strong>
            <small>{{ r.dueno.nombre }} · {{ r.dueno.telefono || r.dueno.email }}</small>
          </div>
          <div class="ab-celda">
            <span>{{ fechaCorta(r.fecha_hora) }} · {{ horas(r) }}</span>
            <small>{{ r.servicio }}</small>
          </div>
          <div class="ab-celda ab-monto">
            <strong>{{ formatCLP(r.monto_abono_clp) }}</strong>
            <small :class="{ 'ab-vencida': fueraDePlazo(r) }">Reservada {{ esperaLabel(r) }}</small>
          </div>
          <!-- Dos pasos a propósito: el medio decide si el dinero entra al
               cajón, así que se elige explícitamente en vez de asumirlo. -->
          <template v-if="eligiendoMedio === r.id">
            <div class="medio-abono">
              <button class="btn btn-accent btn-sm" @click="confirmarAbono(r, 'efectivo')">Efectivo</button>
              <button class="btn btn-ghost btn-sm" @click="confirmarAbono(r, 'transferencia')">Transferencia</button>
              <button class="btn-cancelar" @click="eligiendoMedio = null" aria-label="Cancelar">✕</button>
            </div>
          </template>
          <button
            v-else
            class="btn btn-accent btn-sm"
            :disabled="confirmando === r.id"
            @click="eligiendoMedio = r.id"
          >
            {{ confirmando === r.id ? 'Guardando…' : 'Registrar abono' }}
          </button>
        </article>

        <Paginador
          :page="pagPendientes" :total-paginas="totalPaginasPendientes"
          :total="pendientes.length" :limit="PENDIENTES_POR_PAGINA"
          @cambiar="pagPendientes = $event"
        />
      </div>
    </section>

    <!-- Recordatorios y tareas del día. Van con los abonos, arriba del
         buscador, porque los tres son lo mismo: cosas que hacer. De ahí para
         abajo la página pasa a ser consulta. -->
    <div v-if="veRecordatorios || veTareas" class="colas">

      <section v-if="veRecordatorios" class="card cola">
        <header class="cola-head">
          <h2>Recordatorios</h2>
          <RouterLink to="/recordatorios" class="cola-link">Ver todos →</RouterLink>
        </header>

        <p v-if="!recordatorios.length" class="cola-vacia">
          Nada por avisar hoy.
        </p>
        <template v-else>
          <p class="cola-resumen">
            <strong>{{ recordatoriosTotal }}</strong>
            por avisar<template v-if="recordatoriosVencidos">,
              <span class="cola-alerta">{{ recordatoriosVencidos }} atrasado{{ recordatoriosVencidos === 1 ? '' : 's' }}</span>
            </template>
          </p>
          <ul class="cola-lista">
            <li v-for="r in recordatorios" :key="r.id" :class="{ vencido: r.vencido }">
              <span class="cola-txt">
                <strong>{{ r.mascota_nombre || 'Paciente' }}</strong>
                <small>{{ r.titulo }}</small>
              </span>
              <!-- El teléfono es el trabajo: el recordatorio se resuelve
                   llamando, no abriendo una ficha. -->
              <a
                v-if="r.dueno_telefono"
                class="cola-tel" :href="`tel:${r.dueno_telefono}`"
              >{{ r.dueno_telefono }}</a>
              <span v-else class="cola-tel cola-sin">sin teléfono</span>
            </li>
          </ul>
        </template>
      </section>

      <section v-if="veTareas" class="card cola">
        <header class="cola-head">
          <h2>Tareas</h2>
          <RouterLink to="/tareas" class="cola-link">Ver todas →</RouterLink>
        </header>

        <p v-if="!tareas.length" class="cola-vacia">
          No hay tareas pendientes.
        </p>
        <template v-else>
          <p class="cola-resumen">
            <strong>{{ tareas.length }}</strong>
            pendiente{{ tareas.length === 1 ? '' : 's' }}<template v-if="tareasVencidas">,
              <span class="cola-alerta">{{ tareasVencidas }} fuera de plazo</span>
            </template>
          </p>
          <ul class="cola-lista">
            <li
              v-for="t in tareasPortada" :key="t.id"
              :class="{ vencido: estaVencida(t) }"
            >
              <span class="cola-txt">
                <strong>{{ t.titulo }}</strong>
                <small>{{ t.asignado_a_nombre || 'Sin asignar' }}</small>
              </span>
              <button class="cola-hecha" @click="completarTarea(t)">Hecha</button>
            </li>
          </ul>
        </template>
      </section>

    </div>

    <BuscadorAvanzado v-model="filtros" :campos="CAMPOS_BUSQUEDA" @buscar="buscar" />

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando reservas…</p>
    <p v-else-if="!reservas.length" class="empty">Sin reservas en este rango.</p>

    <section v-for="[dia, items] in porDia" :key="dia" class="dia">
      <h2 class="dia-title">{{ labelDia(dia) }}</h2>
      <article v-for="r in items" :key="r.id" class="card rsv">
        <div class="rsv-main" @click="abierta = abierta === r.id ? null : r.id">
          <img class="rsv-ic" :src="urlFoto(r.mascota.foto_url) || fotoEspecie(r.mascota.especie)" :alt="r.mascota.especie" />
          <div class="rsv-cols">
            <div class="rsv-col">
              <span class="rsv-line">🕐 {{ horas(r) }}</span>
              <span class="rsv-line email">✉ {{ r.dueno.email }}</span>
            </div>
            <div class="rsv-col">
              <span class="rsv-line"><strong>{{ r.especialista?.emoji || '🩺' }} {{ r.especialista?.nombre || 'Sin especialista' }}</strong></span>
              <span class="rsv-line">{{ r.servicio }}</span>
            </div>
          </div>
          <span v-if="pagoLabel(r)" class="badge pago" :class="r.payment_status || 'pending'">
            {{ pagoLabel(r) }}
          </span>
          <span v-if="r.estado !== 'Agendada'" class="badge" :class="r.estado">{{ r.estado }}</span>
          <span class="rsv-caret" :class="{ open: abierta === r.id }">▾</span>
        </div>

        <div v-if="abierta === r.id" class="rsv-detail">
          <div class="det-grid">
            <div><small>Mascota</small><strong>{{ r.mascota.nombre }} ({{ r.mascota.especie }})</strong></div>
            <div v-if="r.mascota.alergias"><small>Alergias</small><strong class="alergia-texto">⚠ {{ r.mascota.alergias }}</strong></div>
            <div><small>Dueño</small><strong>{{ r.dueno.nombre }}</strong></div>
            <div v-if="r.dueno.telefono"><small>Teléfono</small><strong>{{ r.dueno.telefono }}</strong></div>
            <div v-for="(v, k) in r.respuestas" :key="k"><small>{{ k }}</small><strong>{{ v }}</strong></div>
            <!-- Referencia y autorización sirven para cuadrar el cobro contra
                 el panel de Getnet sin salir del sistema. -->
            <div v-if="r.monto_abono_clp">
              <small>{{ r.pago_modalidad === 'total' ? 'Pago total' : 'Abono (50%)' }}</small>
              <strong>
                {{ formatCLP(r.pago_modalidad === 'total' ? (r.monto_total_clp || r.monto_abono_clp * 2) : r.monto_abono_clp) }}
                · {{ pagoLabel(r) }}
              </strong>
            </div>
            <!-- Lo que hay que cobrar en el mostrador. Se muestra incluso en
                 cero: "nada por cobrar" es justo lo que quien atiende
                 necesita ver de un vistazo, y su ausencia se leería como
                 "no se sabe". -->
            <div v-if="r.monto_abono_clp && r.payment_status === 'approved'">
              <small>Saldo por cobrar</small>
              <strong>{{ saldoPendiente(r) > 0 ? formatCLP(saldoPendiente(r)) : 'Nada, está pagada' }}</strong>
            </div>
            <div v-if="r.pago_referencia"><small>Referencia Getnet</small><strong>{{ r.pago_referencia }}</strong></div>
            <div v-if="r.pago_autorizacion"><small>Autorización</small><strong>{{ r.pago_autorizacion }}</strong></div>
            <div v-if="r.notas_pre_cita" class="det-notas"><small>Notas</small><strong>"{{ r.notas_pre_cita }}"</strong></div>
          </div>
          <div class="det-actions">
            <select class="select" :value="r.estado" @change="cambiarEstado(r, $event.target.value)" aria-label="Cambiar estado">
              <option v-for="e in ESTADOS" :key="e">{{ e }}</option>
            </select>
            <button
              v-if="puedeReagendar(r)"
              type="button"
              class="btn btn-ghost btn-sm"
              @click="citaReagendando = r"
            >Reagendar</button>
            <RouterLink :to="`/ficha/${r.mascota.id}`" class="btn btn-primary btn-sm">Abrir ficha</RouterLink>
            <template v-if="r.monto_abono_clp && r.payment_status !== 'approved'">
              <div v-if="eligiendoMedio === r.id" class="medio-abono">
                <span class="medio-abono-tit">¿Cómo llegó?</span>
                <button class="btn btn-accent btn-sm" @click="confirmarAbono(r, 'efectivo')">Efectivo</button>
                <button class="btn btn-ghost btn-sm" @click="confirmarAbono(r, 'transferencia')">Transferencia</button>
                <button class="btn-cancelar" @click="eligiendoMedio = null" aria-label="Cancelar">✕</button>
              </div>
              <button
                v-else class="btn btn-ghost btn-sm"
                :disabled="confirmando === r.id"
                @click="eligiendoMedio = r.id"
              >
                {{ confirmando === r.id ? 'Guardando…' : 'Registrar abono recibido' }}
              </button>
            </template>
            <button
              v-if="puedeLink(r) && !links[r.id]"
              type="button" class="btn btn-ghost btn-sm"
              :disabled="generandoLink === r.id"
              @click="generarLink(r)"
            >{{ generandoLink === r.id ? 'Generando…' : 'Link de pago' }}</button>
          </div>
          <div v-if="links[r.id]" class="link-pago">
            <small>Enlace de pago · {{ formatCLP(links[r.id].a_pagar_clp) }}{{ links[r.id].exige_total ? ' (total)' : ' (abono)' }}<template
              v-if="links[r.id].pagar_hasta"> · el tutor tiene hasta las <strong>{{ horaLimite(links[r.id].pagar_hasta) }}</strong> para pagar; si no, la hora se libera</template></small>
            <div class="link-pago-fila">
              <input class="input" :value="links[r.id].url" readonly aria-label="Enlace de pago" @focus="$event.target.select()" />
              <button type="button" class="btn btn-ghost btn-sm" @click="copiarLink(r)">
                {{ copiado === r.id ? 'Copiado' : 'Copiar' }}
              </button>
              <a
                v-if="waLink(r)" :href="waLink(r)" target="_blank" rel="noopener"
                class="btn btn-accent btn-sm"
              >Enviar por WhatsApp</a>
            </div>
          </div>
        </div>
      </article>
    </section>
  </div>

  <ReagendarCitaModal
    v-if="citaReagendando"
    :cita="citaReagendando"
    @close="citaReagendando = null"
    @reagendada="alReagendar"
  />
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.1rem; }
.rango { display: flex; align-items: center; gap: 0.5rem; color: var(--ink-soft); }
.rango .input { width: auto; padding: 0.45em 0.7em; }

/* Abonos pendientes */
.abonos { padding: 0; margin-bottom: 1.1rem; overflow: hidden; }
.abonos-head {
  display: flex; align-items: center; gap: 0.7rem; width: 100%;
  padding: 0.9rem 1.2rem; text-align: left; font: inherit; cursor: pointer;
}
.abonos-head:hover { background: var(--accent-hover); }
.abonos-punto {
  width: 9px; height: 9px; flex: none; border-radius: 50%;
  background: var(--warn-ink);
}
.abonos-punto.vencido { background: var(--danger); }
.abonos-titulo { font-weight: 600; }
.abonos-alerta { color: var(--danger); font-size: var(--t-sm); }
.abonos-head .rsv-caret { margin-left: auto; }

.abonos-body { border-top: 1px solid var(--line); padding: 0.4rem 1.2rem 0.9rem; background: var(--bg); }
.abonos-nota { color: var(--ink-soft); font-size: var(--t-sm); padding: 0.7rem 0; }
/* La cola de devoluciones no se pliega: su encabezado es un título, no un botón. */
.devoluciones .abonos-head { cursor: default; }
.devoluciones .abonos-head:hover { background: none; }
.ab-row {
  display: grid; grid-template-columns: 1.3fr 1fr auto auto;
  align-items: center; gap: 1rem;
  padding: 0.75rem 0; border-top: 1px solid var(--line-tabla);
}
.ab-celda { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; font-size: var(--t-base); }
.ab-celda small { color: var(--ink-soft); font-size: var(--t-micro); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ab-monto { text-align: right; }
.ab-vencida { color: var(--danger) !important; font-weight: 600; }

/* Recordatorios y tareas: dos columnas en escritorio, apiladas cuando no hay
   ancho para que el texto de cada fila quede en una canaleta estrecha. */
.colas {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 1rem; margin-bottom: 1.1rem;
}
.cola { padding: 0.95rem 1.1rem 0.8rem; }
.cola-head { display: flex; align-items: baseline; justify-content: space-between; gap: 0.8rem; }
.cola-head h2 { font-size: var(--t-lg); margin: 0; }
.cola-link { font-size: var(--t-micro); color: var(--accent); text-decoration: none; white-space: nowrap; }
.cola-link:hover { text-decoration: underline; }

.cola-resumen { color: var(--ink-soft); font-size: var(--t-sm); margin: 0.35rem 0 0.2rem; }
.cola-resumen strong { color: var(--ink); }
.cola-alerta { color: var(--danger); font-weight: 600; }
.cola-vacia { color: var(--ink-soft); font-size: var(--t-sm); margin: 0.5rem 0 0.3rem; }

.cola-lista { list-style: none; margin: 0; padding: 0; }
.cola-lista li {
  display: flex; align-items: center; gap: 0.8rem;
  padding: 0.5rem 0; border-top: 1px solid var(--line-tabla);
}
/* El borde izquierdo marca lo atrasado sin depender solo del color del texto,
   que en estas filas ya carga el nombre del paciente. */
.cola-lista li.vencido { border-left: 2px solid var(--danger); padding-left: 0.6rem; margin-left: -0.6rem; }
.cola-txt { display: flex; flex-direction: column; gap: 0.05rem; min-width: 0; flex: 1; }
/* El título se recorta a dos líneas en vez de a una: en una tarea el título
   ES el contenido, y "Pedir shampoo dermatológico a Drag Pha…" no dice qué hay
   que hacer. La segunda línea (quién, o de qué recordatorio se trata) sí puede
   quedar en una sola: ahí el corte no pierde el sentido de la fila. */
.cola-txt strong {
  font-size: var(--t-base); line-height: 1.35;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
  overflow: hidden; overflow-wrap: anywhere;
}
.cola-txt small { color: var(--ink-soft); font-size: var(--t-micro); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cola-tel { font-size: var(--t-micro); color: var(--accent); text-decoration: none; white-space: nowrap; }
.cola-tel:hover { text-decoration: underline; }
.cola-sin { color: var(--ink-soft); font-style: italic; }
.cola-hecha {
  font: inherit; font-size: var(--t-micro); cursor: pointer; white-space: nowrap;
  padding: 0.25em 0.7em; border-radius: var(--r-full);
  border: 1px solid var(--line); background: transparent; color: var(--ink-soft);
}
/* --accent-strong y no --accent: el turquesa medio sobre su propio tinte da
   4.47, apenas bajo el 4.5 que exige el texto normal. El fuerte da 5.96, y
   es ademas lo que ya usan el resto de los hover del panel. */
.cola-hecha:hover { background: var(--accent-tint); border-color: var(--accent); color: var(--accent-strong); }

.dia { margin-bottom: 1.8rem; }
.dia-title { font-size: var(--t-lg); font-weight: 600; color: var(--ink-soft); margin-bottom: 0.7rem; text-transform: capitalize; }

.rsv { padding: 0; margin-bottom: 0.7rem; overflow: hidden; }
.rsv-main {
  display: flex; align-items: center; gap: 1rem;
  padding: 1rem 1.2rem; cursor: pointer;
}
.rsv-main:hover { background: var(--accent-hover); }
.rsv-ic {
  width: 44px; height: 44px; flex: none; object-fit: cover;
  background: var(--brand-tint); border-radius: 50%;
}
.rsv-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; flex: 1; min-width: 0; }
.rsv-col { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
.rsv-line { font-size: var(--t-base); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.email { color: var(--ink-soft); }
.rsv-caret { color: var(--ink-soft); transition: transform 0.25s; }
.rsv-caret.open { transform: rotate(180deg); }

/* Estado del abono. Los estados de cita (.badge.Agendada…) van con inicial
   mayúscula en app.css; estos son los valores de `payment_status`, en
   minúscula, así que no chocan. */
.badge.pago { flex: none; }
.badge.approved { background: var(--ok-tint); color: var(--ok); }
.badge.pending { background: var(--warn-tint); color: var(--warn-ink); }
.badge.rejected, .badge.cancelled { background: var(--danger-tint); color: var(--danger); }

.rsv-detail { border-top: 1px solid var(--line); padding: 1rem 1.2rem; background: var(--bg); }
.det-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.8rem; margin-bottom: 1rem; }
.det-grid small { display: block; color: var(--ink-soft); font-size: var(--t-micro); }
.det-notas { grid-column: 1 / -1; }
.alergia-texto { color: #991b1b; }
.det-actions { display: flex; gap: 0.7rem; align-items: center; flex-wrap: wrap; }
.link-pago { margin-top: 0.9rem; }
.link-pago small { display: block; color: var(--ink-soft); font-size: var(--t-micro); margin-bottom: 0.3rem; }
.link-pago-fila { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.link-pago-fila .input { flex: 1 1 260px; min-width: 0; padding: 0.45em 0.7em; }
.det-actions .select { width: auto; padding: 0.45em 0.8em; font-size: var(--t-base); }

@media (max-width: 640px) {
  .rsv-cols { grid-template-columns: 1fr; }
  .ab-row { grid-template-columns: 1fr; gap: 0.5rem; }
  .ab-monto { text-align: left; }
  .ab-row .btn { justify-self: start; }
}

/* Elegir el medio del abono. Compacto porque vive dentro de una fila de la
   cola y no puede empujar el resto del listado. */
.medio-abono {
  display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;
}
.medio-abono-tit {
  font-size: var(--t-micro); color: var(--ink-soft); margin-right: 0.15rem;
}
.btn-cancelar {
  background: none; border: 0; cursor: pointer; padding: 0.15rem 0.35rem;
  color: var(--ink-soft); font-size: var(--t-base); line-height: 1;
  border-radius: 4px;
}
.btn-cancelar:hover { background: var(--accent-tint); color: var(--accent-strong); }
.btn-cancelar:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }
</style>
