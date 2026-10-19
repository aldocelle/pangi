<script setup>
/* Ventas = el historial comercial de la clínica: qué compró cada cliente y
   qué se compró para cada paciente. La tabla prioriza eso —número, fecha,
   cliente, paciente, detalle, total y estado— y el cobro en sí vive en Caja.

   Sale de `transacciones` + `ventas`, las mismas que usa Caja; acá se leen
   por venta, allá por día de turno. */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, apiError, formatCLP } from '../api.js';
import Paginador from '../components/Paginador.vue';
import BuscadorAvanzado from '../components/BuscadorAvanzado.vue';
import NuevaVentaModal from '../components/NuevaVentaModal.vue';
import DetalleVentaModal from '../components/DetalleVentaModal.vue';

const route = useRoute();
const router = useRouter();

const especialistas = ref([]);
const categorias = ref([]);

/* Contexto de entrada: llegar desde la ficha de un cliente o de un paciente
   ("Ver en Ventas") acota el listado a esa persona o mascota. Va en la URL y
   no en un filtro escrito porque el id no es tecleable, y se muestra como una
   pastilla que se puede quitar — si no, el listado aparecería recortado sin
   ninguna señal de por qué. */
const contexto = ref(null);

function leerContexto() {
  const { propietario_id: cliente, mascota_id: mascota, etiqueta } = route.query;
  if (cliente) contexto.value = { clave: 'propietario_id', id: cliente, etiqueta: etiqueta || 'este cliente' };
  else if (mascota) contexto.value = { clave: 'mascota_id', id: mascota, etiqueta: etiqueta || 'este paciente' };
  else contexto.value = null;
}

function abrirVenta(v) {
  abierta.value = { id: v.id, precargada: v };
}

function cerrarVenta() {
  abierta.value = null;
  // Si la venta se abrió desde la URL, sacar el parámetro: si no, volver a
  // elegir el mismo resultado en el buscador global no reabriría nada,
  // porque la ruta no cambiaría.
  if (route.query.venta) {
    const { venta, ...resto } = route.query;
    router.replace({ path: '/ventas', query: resto });
  }
}

function quitarContexto() {
  // Solo navega: el watcher de la query se encarga de releer el contexto y
  // recargar. Hacerlo también acá disparaba dos peticiones por un clic.
  router.replace({ path: '/ventas' });
}

/* Dos ejes en un solo desplegable: en qué va la plata y en qué va el
   documento. Se listan juntos porque para quien busca son la misma pregunta
   ("muéstrame las anuladas", "muéstrame las que deben"). */
const ESTADOS = [
  { value: 'pagada', label: 'Pagada' },
  { value: 'parcial', label: 'Parcialmente pagada' },
  { value: 'pendiente', label: 'Pendiente de pago' },
  { value: 'devuelta', label: 'Con devolución' },
  { value: 'anulada', label: 'Anulada' },
  { value: 'borrador', label: 'Borrador' },
];

const CAMPOS_BUSQUEDA = computed(() => [
  /* Un solo cuadro que busca por todo lo que el mostrador recuerda de una
     venta (número, boleta, cliente, RUT, paciente, producto o servicio). Los
     campos de abajo acotan; este encuentra. */
  { key: 'q', label: 'Buscar', placeholder: 'N.º, cliente, RUT, mascota o producto…' },
  { key: 'estado', label: 'Estado', type: 'select', options: ESTADOS },
  // Desde acá, plegados bajo «Más filtros» (ver `principales` en el template).
  { key: 'paciente', label: 'Paciente', placeholder: 'Nombre de la mascota…' },
  { key: 'producto', label: 'Producto o servicio', placeholder: 'Nombre del ítem vendido…' },
  {
    key: 'categoria', label: 'Categoría', type: 'select',
    options: categorias.value.map((c) => ({ value: c, label: c })),
  },
  {
    key: 'especialista_id', label: 'Especialista', type: 'select',
    options: especialistas.value.map((e) => ({ value: e.id, label: e.nombre })),
  },
  { key: 'documento', label: 'Documento', placeholder: 'Número de documento', autocompletar: false },
]);

const VACIO = {
  q: '', paciente: '', producto: '', categoria: '', estado: '',
  especialista_id: '', documento: '',
};

const ventas = ref([]);
const resumen = ref(null);
const loading = ref(true);
const error = ref('');
const filtros = ref({ ...VACIO });
const pagina = ref(1);
const paginacion = ref({ total: 0, total_paginas: 1, limit: 20 });
/* { id, precargada }: desde el listado la venta ya viene entera y el
   detalle se pinta sin esperar; desde la URL (buscador global) solo se
   tiene el id y el modal la pide. */
const abierta = ref(null);
const mostrarNueva = ref(false);
const okMsg = ref('');

/* ── Período ──
   Va aparte del buscador y siempre hay uno elegido. Antes ningún chip estaba
   encendido al entrar, y el resumen sumaba las 12.376 ventas desde siempre:
   una cifra que no le sirve a nadie para el día a día. Se abre en 30 días;
   «Todo» sigue estando, pero hay que pedirlo. «Fechas…» despliega desde/hasta
   para un rango a mano, con su etiqueta escrita (antes eran dos dd-mm-yyyy
   sin decir cuál era cuál). */
const RANGOS = [
  { key: 'hoy', label: 'Hoy', dias: 0, titulo: 'Hoy' },
  { key: '7', label: '7 días', dias: 6, titulo: 'Últimos 7 días' },
  { key: '30', label: '30 días', dias: 29, titulo: 'Últimos 30 días' },
  { key: '6m', label: '6 meses', meses: 6, titulo: 'Últimos 6 meses' },
  { key: 'anio', label: 'Este año', anio: true, titulo: 'Este año' },
  { key: 'todo', label: 'Todo', titulo: 'Todo el historial' },
  { key: 'fechas', label: 'Fechas…', titulo: 'Rango elegido' },
];
const rangoActivo = ref('30');
const rango = ref({ desde: '', hasta: '' });

/* Fecha LOCAL en yyyy-mm-dd. `toISOString()` la pasa a UTC: desde las 20:00
   o 21:00 de Chile «Hoy» ya era mañana y el listado salía vacío. */
function iso(fecha) {
  const p = (n) => String(n).padStart(2, '0');
  return `${fecha.getFullYear()}-${p(fecha.getMonth() + 1)}-${p(fecha.getDate())}`;
}

function fechasDe(key) {
  const r = RANGOS.find((x) => x.key === key);
  if (!r || key === 'todo') return { desde: '', hasta: '' };
  if (key === 'fechas') return { ...rango.value };
  const hoy = new Date();
  let desde;
  if (r.anio) desde = new Date(hoy.getFullYear(), 0, 1);
  else if (r.meses) desde = new Date(hoy.getFullYear(), hoy.getMonth() - r.meses, hoy.getDate());
  else desde = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate() - r.dias);
  return { desde: iso(desde), hasta: iso(hoy) };
}

function aplicarRango(r) {
  rangoActivo.value = r.key;
  // «Fechas…» solo abre los dos campos; se busca cuando se escribe una fecha.
  if (r.key === 'fechas') return;
  rango.value = fechasDe(r.key);
  buscar();
}

const tituloRango = computed(() => {
  if (rangoActivo.value === 'fechas') {
    const { desde, hasta } = rango.value;
    if (desde && hasta) return `Del ${fmtCorta(desde)} al ${fmtCorta(hasta)}`;
    if (desde) return `Desde el ${fmtCorta(desde)}`;
    if (hasta) return `Hasta el ${fmtCorta(hasta)}`;
    return 'Todo el historial';
  }
  return RANGOS.find((r) => r.key === rangoActivo.value)?.titulo || '';
});

function paramsActuales() {
  const p = {};
  for (const [k, v] of Object.entries({ ...filtros.value, ...rango.value })) {
    if (v !== '' && v != null) p[k] = v;
  }
  if (contexto.value) p[contexto.value.clave] = contexto.value.id;
  return p;
}

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const params = paramsActuales();
    const [lista, agg] = await Promise.all([
      api.get('/transacciones', { params: { ...params, page: pagina.value, limit: 20 } }),
      api.get('/transacciones/resumen', { params }),
    ]);
    ventas.value = lista.data.items;
    paginacion.value = {
      total: lista.data.total, total_paginas: lista.data.total_paginas, limit: lista.data.limit,
    };
    resumen.value = agg.data;
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar las ventas.');
  } finally {
    loading.value = false;
  }
  cargarBorradores();
}

/* ── Borradores por cobrar ──
   Cola de trabajo, igual que «Abonos pendientes» en Reservas: va FUERA del
   período y de los filtros, porque un borrador de hace dos meses sigue
   esperando que alguien lo cobre y con el rango de 30 días no se vería. Solo
   respeta el contexto (llegar desde la ficha de un cliente o paciente). */
const borradores = ref([]);
const nBorradores = ref(0);

async function cargarBorradores() {
  const params = { estado: 'borrador', page: 1, limit: 5 };
  if (contexto.value) params[contexto.value.clave] = contexto.value.id;
  try {
    const { data } = await api.get('/transacciones', { params });
    borradores.value = data.items;
    nBorradores.value = data.total;
  } catch {
    // Si falla, la cola no aparece; el listado sigue funcionando y el
    // filtro «Estado: Borrador» los sigue encontrando.
    borradores.value = [];
    nBorradores.value = 0;
  }
}

async function cargarFiltros() {
  const [esp, cat] = await Promise.allSettled([
    api.get('/catalogo/especialistas'),
    api.get('/transacciones/categorias'),
  ]);
  especialistas.value = esp.status === 'fulfilled' ? esp.value.data : [];
  categorias.value = cat.status === 'fulfilled' ? cat.value.data : [];
}

function buscar() {
  pagina.value = 1;
  cargar();
}

function cambiarPagina(p) {
  pagina.value = p;
  cargar();
}

function verBorradores() {
  // Un borrador puede ser más viejo que el período elegido.
  rangoActivo.value = 'todo';
  rango.value = { desde: '', hasta: '' };
  filtros.value = { ...filtros.value, estado: 'borrador' };
  buscar();
}

function ventaCreada() {
  mostrarNueva.value = false;
  okMsg.value = 'Venta registrada.';
  setTimeout(() => (okMsg.value = ''), 2500);
  pagina.value = 1;
  cargar();
}

/* Estando YA en Ventas, el buscador global solo cambia la query: el
   componente no se remonta y `onMounted` no vuelve a correr, así que sin
   este watcher el resultado elegido no abría nada. */
watch(() => route.query, (q, anterior) => {
  if (q.venta && q.venta !== anterior?.venta) {
    abierta.value = { id: q.venta, precargada: null };
  }
  // Recargar solo si cambió lo que filtra. Cerrar una venta también toca la
  // query (saca `?venta=`) y eso no debería costar otra consulta.
  const cambioContexto = q.propietario_id !== anterior?.propietario_id
    || q.mascota_id !== anterior?.mascota_id;
  if (cambioContexto) {
    leerContexto();
    pagina.value = 1;
    cargar();
  }
});

/* Las fechas llegan como «2026-09-30T10:42:00», en hora de la clínica y sin
   zona. Se leen como texto y no con `new Date(iso)`: una fecha sola
   («2026-09-30») el navegador la toma como UTC y en Chile cae el día antes. */
function partes(valor) {
  const [a, m, d] = String(valor).slice(0, 10).split('-').map(Number);
  return { a, m, d, hora: String(valor).slice(11, 16) };
}

function fmtCorta(valor) {
  const { a, m, d } = partes(valor);
  return `${String(d).padStart(2, '0')}-${String(m).padStart(2, '0')}-${a}`;
}

/* La hora solo si es real: lo importado de ACVet trae medianoche. */
function horaDe(v) {
  const h = v.fecha ? partes(v.fecha).hora : '';
  return h && h !== '00:00' ? h : '';
}

function etiquetaDia(clave) {
  const hoy = new Date();
  const ayer = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate() - 1);
  if (clave === iso(hoy)) return 'Hoy';
  if (clave === iso(ayer)) return 'Ayer';
  const { a, m, d } = partes(clave);
  const f = new Date(a, m - 1, d);
  const opciones = { weekday: 'long', day: 'numeric', month: 'long' };
  if (a !== hoy.getFullYear()) opciones.year = 'numeric';
  const texto = f.toLocaleDateString('es-CL', opciones);
  // Solo la primera letra: `text-transform: capitalize` dejaba «3 De Octubre».
  return texto.charAt(0).toUpperCase() + texto.slice(1);
}

/* Agrupado por día: la fecha va una vez en el encabezado del grupo y la fila
   muestra la hora. Sin subtotal por día a propósito: el grupo puede seguir en
   la página siguiente, y un subtotal de media jornada se leería como el del
   día entero. Los totales del período están en las tarjetas. */
const grupos = computed(() => {
  const out = [];
  let actual = null;
  for (const v of ventas.value) {
    const clave = v.fecha ? String(v.fecha).slice(0, 10) : 'sin-fecha';
    if (!actual || actual.clave !== clave) {
      actual = { clave, etiqueta: v.fecha ? etiquetaDia(clave) : 'Sin fecha', ventas: [] };
      out.push(actual);
    }
    actual.ventas.push(v);
  }
  return out;
});

const ICONO_ESPECIE = { perro: '🐶', canino: '🐶', gato: '🐱', felino: '🐱' };
function iconoEspecie(especie) {
  return ICONO_ESPECIE[String(especie || '').toLowerCase()] || '🐾';
}

/* Lo que importa del detalle es QUÉ se vendió; «1 servicio» es el dato
   secundario. Antes era al revés: la cantidad en negrita y el nombre chico. */
function nombresDe(v) {
  return v.lineas.map((l) => (l.cantidad > 1 ? `${l.cantidad}× ${l.nombre}` : l.nombre)).join(' · ') || '—';
}

const MEDIOS = {
  pago_efectivo: 'Efectivo', pago_debito: 'Débito', pago_credito: 'Crédito',
  pago_transferencia: 'Transferencia', pago_otro: 'Otro',
};
function mediosDe(v) {
  return Object.entries(v.pagos || {}).filter(([, monto]) => monto > 0).map(([k]) => MEDIOS[k] || k).join(' + ');
}

/* Una venta de $0 «Pagada» no pagó nada: es un control sin costo o una línea
   que se cobró en otra venta. Se dice tal cual y se apaga. */
function estadoDe(v) {
  if (v.estado === 'pagada' && !v.total) return { clave: 'sincargo', label: 'Sin cargo' };
  return { clave: v.estado, label: v.estado_label };
}

function haceCuanto(valor) {
  const { a, m, d, hora } = partes(valor);
  const [hh, mm] = (hora || '00:00').split(':').map(Number);
  const min = Math.round((Date.now() - new Date(a, m - 1, d, hh, mm).getTime()) / 60000);
  if (min < 60) return `hace ${Math.max(min, 1)} min`;
  if (min < 60 * 24) return `hace ${Math.round(min / 60)} h`;
  const dias = Math.round(min / 1440);
  return `hace ${dias} día${dias === 1 ? '' : 's'}`;
}

function numero(n) {
  return Number(n || 0).toLocaleString('es-CL');
}

const ticketPromedio = computed(() => {
  const r = resumen.value;
  const vivas = (r?.n_ventas || 0) - (r?.n_anuladas || 0);
  return vivas > 0 ? r.total / vivas : 0;
});

onMounted(() => {
  leerContexto();
  // Desde la ficha de un cliente o paciente se quiere su historial entero,
  // no solo el último mes.
  if (contexto.value) rangoActivo.value = 'todo';
  rango.value = fechasDe(rangoActivo.value);
  // El buscador global entrega el id de la venta: se abre directo su
  // detalle en vez de dejar al usuario buscarla otra vez en la tabla.
  if (route.query.venta) abierta.value = { id: route.query.venta, precargada: null };
  cargar();
  cargarFiltros();
});
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Ventas</h1>
        <p class="page-sub">Qué compró cada cliente y qué se adquirió para cada paciente.</p>
      </div>
      <button class="btn btn-accent" @click="mostrarNueva = true">+ Nueva venta</button>
    </div>

    <p v-if="okMsg" class="ok-msg">{{ okMsg }}</p>

    <p v-if="contexto" class="contexto">
      Mostrando solo las ventas de <strong>{{ contexto.etiqueta }}</strong>
      <button class="contexto-quitar" @click="quitarContexto">quitar filtro ✕</button>
    </p>

    <div class="rangos" role="group" aria-label="Período">
      <button
        v-for="r in RANGOS" :key="r.key"
        class="rango-chip" :class="{ activo: rangoActivo === r.key }"
        :aria-pressed="rangoActivo === r.key"
        @click="aplicarRango(r)"
      >{{ r.label }}</button>
      <template v-if="rangoActivo === 'fechas'">
        <label class="rango-fecha">Desde
          <input v-model="rango.desde" type="date" class="input" @change="buscar" />
        </label>
        <label class="rango-fecha">Hasta
          <input v-model="rango.hasta" type="date" class="input" @change="buscar" />
        </label>
      </template>
    </div>

    <!-- Lo del período en cuatro cifras, en vez de una frase que nadie leía.
         Cambian con el período y con los filtros de abajo. -->
    <section v-if="resumen && !error" class="kpis" :class="{ cargando: loading }" :aria-label="`Resumen: ${tituloRango}`">
      <div class="kpi">
        <span class="kpi-label">Vendido · {{ tituloRango }}</span>
        <strong class="kpi-valor">{{ formatCLP(resumen.total) }}</strong>
        <span class="kpi-sub">
          {{ numero(resumen.n_ventas) }} venta{{ resumen.n_ventas === 1 ? '' : 's' }}
          <template v-if="resumen.n_anuladas"> · {{ numero(resumen.n_anuladas) }} anulada{{ resumen.n_anuladas === 1 ? '' : 's' }}, no suman</template>
        </span>
      </div>
      <div class="kpi">
        <span class="kpi-label">Cobrado</span>
        <strong class="kpi-valor">{{ formatCLP(resumen.pagado) }}</strong>
        <span class="kpi-sub">
          <template v-if="resumen.devuelto > 0">{{ formatCLP(resumen.devuelto) }} devueltos</template>
          <template v-else>Lo que ya entró</template>
        </span>
      </div>
      <div class="kpi" :class="{ 'kpi-alerta': resumen.pendiente > 0 }">
        <span class="kpi-label">Por cobrar</span>
        <strong class="kpi-valor">{{ formatCLP(resumen.pendiente) }}</strong>
        <span class="kpi-sub">{{ resumen.pendiente > 0 ? 'Pendiente de pago' : 'Nada pendiente' }}</span>
      </div>
      <div class="kpi">
        <span class="kpi-label">Ticket promedio</span>
        <strong class="kpi-valor">{{ formatCLP(ticketPromedio) }}</strong>
        <span class="kpi-sub">Por venta</span>
      </div>
    </section>

    <!-- Los borradores viven fuera del historial: sin esta cola quedarían
         invisibles y nadie los cobraría. -->
    <section v-if="nBorradores && filtros.estado !== 'borrador'" class="cola-borradores">
      <div class="cola-head">
        <h2 class="cola-titulo">
          {{ nBorradores }} borrador{{ nBorradores === 1 ? '' : 'es' }} por cobrar
        </h2>
        <button class="cola-ver" @click="verBorradores">
          Ver todos
        </button>
      </div>
      <ul class="cola-lista">
        <li v-for="b in borradores" :key="b.id" class="cola-item">
          <div class="cola-info">
            <strong>
              <template v-if="b.pacientes.length">{{ iconoEspecie(b.pacientes[0].especie) }} {{ b.pacientes.map((p) => p.nombre).join(', ') }}</template>
              <template v-else>{{ b.cliente?.nombre || 'Sin cliente' }}</template>
            </strong>
            <span class="cola-meta">
              <template v-if="b.pacientes.length && b.cliente">{{ b.cliente.nombre }} · </template>{{ nombresDe(b) }}
            </span>
          </div>
          <span class="cola-total">{{ formatCLP(b.total) }}</span>
          <span class="cola-edad">{{ b.fecha ? haceCuanto(b.fecha) : '' }}</span>
          <button class="btn btn-primary btn-sm" @click="abrirVenta(b)">Abrir y cobrar</button>
        </li>
      </ul>
    </section>

    <BuscadorAvanzado v-model="filtros" :campos="CAMPOS_BUSQUEDA" :principales="2" @buscar="buscar" />

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>
    <p v-else-if="!ventas.length" class="empty">
      Sin ventas<template v-if="rangoActivo !== 'todo'"> en «{{ tituloRango.toLowerCase() }}»</template>.
    </p>

    <div v-else class="tabla card">
      <div class="t-head t-row">
        <span>N.º</span><span>Hora</span><span>Cliente y paciente</span>
        <span>Detalle</span><span class="der">Total</span><span class="estado-col">Estado</span>
      </div>
      <template v-for="g in grupos" :key="g.clave">
        <h3 class="dia">{{ g.etiqueta }}</h3>
        <div
          v-for="v in g.ventas" :key="v.id"
          class="t-row t-row-link" :class="{ 'fila-anulada': v.estado_venta === 'anulada', 'fila-sincargo': estadoDe(v).clave === 'sincargo' }"
          tabindex="0" role="button" :aria-label="`Venta ${v.numero}`"
          @click="abrirVenta(v)" @keyup.enter="abrirVenta(v)"
        >
          <span class="num">#{{ String(v.numero || 0).padStart(6, '0') }}</span>
          <span class="hora">{{ horaDe(v) || '—' }}</span>
          <span class="quien">
            <span class="cliente" :class="{ 'sin-cliente': !v.cliente }">{{ v.cliente?.nombre || 'Sin cliente' }}</span>
            <small v-if="v.pacientes.length" class="paciente">
              {{ iconoEspecie(v.pacientes[0].especie) }} {{ v.pacientes.map((p) => p.nombre).join(', ') }}
            </small>
          </span>
          <span class="detalle-col">
            <span class="detalle-nombres">{{ nombresDe(v) }}</span>
            <small>{{ v.detalle }}</small>
          </span>
          <span class="total-col">
            <span class="total">{{ formatCLP(v.total) }}</span>
            <small v-if="mediosDe(v)">{{ mediosDe(v) }}</small>
          </span>
          <span class="estado-col">
            <span class="estado" :class="`estado-${estadoDe(v).clave}`">{{ estadoDe(v).label }}</span>
            <small v-if="v.estado === 'parcial' && v.pendiente > 0" class="falta">falta {{ formatCLP(v.pendiente) }}</small>
          </span>
        </div>
      </template>
    </div>

    <Paginador
      v-if="!loading && ventas.length"
      :page="pagina" :total-paginas="paginacion.total_paginas"
      :total="paginacion.total" :limit="paginacion.limit"
      @cambiar="cambiarPagina"
    />

    <DetalleVentaModal
      v-if="abierta"
      :venta-id="abierta.id" :precargada="abierta.precargada"
      @close="cerrarVenta" @actualizada="cargar"
    />

    <NuevaVentaModal
      v-if="mostrarNueva"
      @close="mostrarNueva = false"
      @creada="ventaCreada"
    />
  </div>
</template>

<style scoped>
.ok-msg { color: var(--ok); font-size: var(--t-base); font-weight: 500; margin-bottom: 0.8rem; }

.contexto {
  display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;
  background: var(--accent-tint); color: var(--accent-strong);
  padding: 0.5rem 0.9rem; border-radius: var(--r-md);
  font-size: var(--t-sm); margin-bottom: 0.9rem;
}
.contexto-quitar { font-size: var(--t-micro); font-weight: 600; color: var(--accent-strong); text-decoration: underline; }
.rangos { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 0.9rem; }
.rango-chip {
  padding: 0.3em 0.85em; border-radius: var(--r-full);
  font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft);
  background: var(--surface); border: 1.5px solid var(--line-strong);
  transition: background 0.15s, border-color 0.15s, color 0.15s;
}
.rango-chip:hover { border-color: var(--accent); color: var(--accent-strong); background: var(--accent-tint); }
.rango-chip.activo { background: var(--accent); border-color: var(--accent); color: #fff; }

.rango-fecha {
  display: inline-flex; align-items: center; gap: 0.4rem;
  font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft);
}
.rango-fecha .input { width: auto; padding-block: 0.3rem; }

/* ── Tarjetas del período ── */
.kpis {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(min(190px, 100%), 1fr));
  gap: 0.8rem; margin-bottom: 1rem; transition: opacity 0.15s;
}
.kpis.cargando { opacity: 0.55; }
.kpi {
  display: flex; flex-direction: column; gap: 0.2rem; min-width: 0;
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--r-lg); padding: 0.9rem 1.1rem;
}
.kpi-label { font-size: var(--t-micro); font-weight: 650; color: var(--ink-soft); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.kpi-valor { font-size: var(--t-2xl); font-weight: 700; color: var(--ink); font-variant-numeric: tabular-nums; line-height: 1.2; }
.kpi-sub { font-size: var(--t-micro); color: var(--ink-soft); }
.kpi-alerta { border-color: var(--danger); background: var(--danger-tint); }
.kpi-alerta .kpi-valor, .kpi-alerta .kpi-label { color: var(--danger); }

/* ── Cola de borradores ── */
.cola-borradores {
  background: var(--brand-tint); border-radius: var(--r-lg);
  padding: 0.8rem 1rem; margin-bottom: 1rem;
}
.cola-head { display: flex; align-items: center; justify-content: space-between; gap: 0.8rem; margin-bottom: 0.5rem; }
.cola-titulo { font-size: var(--t-base); font-weight: 700; color: var(--brand-ink); margin: 0; }
.cola-ver { font-size: var(--t-micro); font-weight: 600; color: var(--brand-ink); text-decoration: underline; }
.cola-lista { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.4rem; }
.cola-item {
  display: grid; grid-template-columns: minmax(0, 1fr) auto auto auto; align-items: center; gap: 0.9rem;
  background: var(--surface); border-radius: var(--r-md); padding: 0.55rem 0.8rem;
}
.cola-info { display: flex; flex-direction: column; min-width: 0; }
.cola-info strong { color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cola-meta { font-size: var(--t-micro); color: var(--ink-soft); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cola-total { font-weight: 700; font-variant-numeric: tabular-nums; }
.cola-edad { font-size: var(--t-micro); color: var(--ink-soft); white-space: nowrap; }

/* ── Tabla ── */
.t-row { grid-template-columns: 84px 56px 1.3fr 1.9fr 150px 170px; min-width: 860px; }
.dia {
  margin: 0; padding: 0.9rem 0.5rem 0.35rem;
  font-size: var(--t-micro); font-weight: 700; color: var(--brand-ink);
  border-bottom: 1px solid var(--line-strong);
}
.num { color: var(--accent-strong); font-weight: 600; font-variant-numeric: tabular-nums; }
.hora { color: var(--ink-soft); font-variant-numeric: tabular-nums; }
.quien, .detalle-col, .total-col, .estado-col { display: flex; flex-direction: column; min-width: 0; gap: 0.1rem; }
.cliente { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sin-cliente { font-weight: 400; color: var(--ink-soft); font-style: italic; }
.paciente { font-size: var(--t-micro); color: var(--ink-soft); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.detalle-nombres { color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.detalle-col small, .total-col small { color: var(--ink-soft); font-size: var(--t-micro); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.total-col { align-items: flex-end; text-align: right; }
.der { text-align: right; }
.total { font-weight: 700; font-variant-numeric: tabular-nums; }
.estado-col { align-items: flex-start; padding-left: 0.8rem; }
.falta { font-size: var(--t-micro); color: var(--danger); font-weight: 600; }

.estado { display: inline-flex; padding: 0.2em 0.7em; border-radius: var(--r-full); font-size: var(--t-micro); font-weight: 600; white-space: nowrap; }
.estado-pagada { background: var(--ok-tint); color: var(--ok); }
.estado-parcial { background: var(--warn-tint); color: var(--warn-ink); }
.estado-pendiente { background: var(--danger-tint); color: var(--danger); }
.estado-anulada { background: var(--danger-tint); color: var(--danger); }
.estado-borrador { background: var(--brand-tint); color: var(--brand-ink); }
.estado-sincargo { background: var(--fila-alterna); color: var(--ink-soft); border: 1px solid var(--line-strong); }
.estado-devuelta, .estado-devuelta_parcial { background: var(--warn-tint); color: var(--warn-ink); }

/* Una venta anulada sigue en la lista —es parte del historial— pero no
   puede leerse como una venta viva. Se atenúa y el total va tachado; el
   badge rojo lo dice, esto lo hace visible sin leerlo. */
.fila-anulada { opacity: 0.62; }
.fila-anulada .total { text-decoration: line-through; }
/* Sin cargo: sigue en la lista, pero no compite con las que movieron plata. */
.fila-sincargo .total, .fila-sincargo .detalle-nombres { color: var(--ink-soft); }

@media (max-width: 720px) {
  .tabla { min-width: 0; }
  .t-row { min-width: 0 !important; }
  .detalle-col small, .detalle-nombres { white-space: normal; }
  .total-col { align-items: flex-start; text-align: left; }
  .estado-col { padding-left: 0; }
  .cola-item { grid-template-columns: minmax(0, 1fr) auto; }
  .t-row { grid-template-columns: minmax(0, 1fr) !important; }
}
</style>
