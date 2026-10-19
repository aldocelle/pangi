<script setup>
import { ref, computed, onMounted } from 'vue';
import { api, apiError } from '../api.js';
import Paginador from '../components/Paginador.vue';

/* Fecha en formato YYYY-MM-DD tomada del reloj del navegador y no de
   `toISOString()`, que convierte a UTC y en Chile devuelve el día siguiente
   desde las 20:00 — justo cuando se está cuadrando la caja. */
function hoyISO() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const fecha = ref(hoyISO());
const estado = ref(null);
const loading = ref(true);
const error = ref('');

const transacciones = ref([]);
const paginaTx = ref(1);
const paginacionTx = ref({ total: 0, total_paginas: 1, limit: 30 });
const buscarTx = ref('');
const loadingTx = ref(false);

const equipo = ref([]);

/* Un modal a la vez: 'abrir' | 'cerrar' | 'turno' | 'egreso' | 'egresos'
   | 'cierres'. Con un ref por modal era fácil dejar dos abiertos a la vez al
   encadenar acciones. */
const modal = ref(null);
const form = ref({});
const busy = ref(false);
const errorModal = ref('');
const historial = ref([]);

const sesion = computed(() => estado.value?.sesion_abierta || null);
/* La caja abierta de OTRO día: `abrir/cerrar/cambio-turno` en el backend
   operan sobre la única caja abierta que exista en todo el sistema, sin
   mirar qué fecha se está viendo acá. Si quedó una sesión sin cerrar de un
   día anterior (ej. un cambio de turno que no se hizo, o un dato de prueba),
   viéndose "hoy" no aparecía ningún botón para cerrarla — bloqueaba "Abrir
   caja" sin que hubiera manera de llegar a ella desde el panel. */
const sesionOtroDia = computed(() => estado.value?.sesion_abierta_otro_dia || null);
/* La que de verdad pueden tocar cerrar/cambio de turno/egreso: la del día que
   se está viendo, o si no hay, la que quedó colgada de otro día. */
const sesionActiva = computed(() => sesion.value || sesionOtroDia.value);
/* El esperado de la sesión que de verdad se va a cerrar/traspasar — nunca el
   del día que se está viendo si esa sesión es de otro día (`estado.
   efectivo_esperado` queda en null en ese caso). */
const efectivoEsperadoActivo = computed(() => (
  sesion.value ? estado.value?.efectivo_esperado : estado.value?.efectivo_esperado_otro_dia
));
const esHoy = computed(() => fecha.value === hoyISO());

/* Por qué las acciones de efectivo están deshabilitadas. Todo movimiento
   cuelga de un turno (ver MovimientoCaja.sesion_id): sin caja abierta no hay
   dónde imputarlo, y la caja solo se abre sobre el día en curso — hacerlo
   hacia atrás dejaría un turno con fecha vieja y efectivo que ya no existe. */
const ayudaAccion = computed(() => {
  if (sesionActiva.value) return '';
  return esHoy.value
    ? 'Abre la caja para registrar egresos y cierres.'
    : 'Este día no tuvo caja abierta. Solo se puede operar sobre el día en curso.';
});

/* En pesos chilenos y sin decimales: la caja no maneja centavos, y en una
   tabla de 20 filas "$60.000" se lee de un vistazo donde "60.000 CLP" no. */
function pesos(n) {
  const v = Number(n || 0);
  return `${v < 0 ? '-' : ''}$${Math.abs(Math.round(v)).toLocaleString('es-CL')}`;
}

/* 24 horas: es-CL por defecto devuelve "07:48 p. m.", que en una columna de
   tabla se parte en dos líneas y descuadra la fila entera. */
const HORA = { hour: '2-digit', minute: '2-digit', hour12: false };

function hora(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleTimeString('es-CL', HORA);
}

function fechaHora(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  return `${d.toLocaleDateString('es-CL')} ${d.toLocaleTimeString('es-CL', HORA)}`;
}

/* 'abono' sigue acá aunque ya no se pueda crear uno: los turnos anteriores
   tienen movimientos de ese tipo y sin su etiqueta aparecerían sin nombre en
   el historial. */
const ETIQUETA_MOVIMIENTO = {
  inicio: 'Inicio de caja',
  abono: 'Abono de caja',
  egreso: 'Egreso de caja',
  cierre: 'Cierre de caja',
};

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const { data } = await api.get('/caja', { params: { fecha: fecha.value } });
    estado.value = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar la caja.');
  } finally {
    loading.value = false;
  }
}

async function cargarTransacciones() {
  loadingTx.value = true;
  try {
    const params = { fecha: fecha.value, page: paginaTx.value, limit: 30 };
    if (buscarTx.value.trim()) params.q = buscarTx.value.trim();
    const { data } = await api.get('/caja/transacciones', { params });
    transacciones.value = data.items;
    paginacionTx.value = { total: data.total, total_paginas: data.total_paginas, limit: data.limit };
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar las transacciones.');
  } finally {
    loadingTx.value = false;
  }
}

function cambiarDia() {
  paginaTx.value = 1;
  cargar();
  cargarTransacciones();
}

let debounce;
function buscar() {
  clearTimeout(debounce);
  debounce = setTimeout(() => {
    paginaTx.value = 1;
    cargarTransacciones();
  }, 300);
}

function cambiarPaginaTx(p) {
  paginaTx.value = p;
  cargarTransacciones();
}

// ── Modales ──
function abrirModal(cual) {
  errorModal.value = '';
  modal.value = cual;
  if (cual === 'abrir') form.value = { monto_inicial: '', comentario: '' };
  if (cual === 'cerrar') form.value = { monto_declarado: '', notas: '' };
  if (cual === 'turno') { form.value = { usuario_id: '', monto_declarado: '', notas: '' }; cargarEquipo(); }
  if (cual === 'egreso') form.value = { monto: '', comentario: '' };
  if (cual === 'egresos') cargarHistorial('/caja/egresos');
  if (cual === 'cierres') cargarHistorial('/caja/sesiones');
}

function cerrarModal() {
  modal.value = null;
  errorModal.value = '';
}

async function cargarEquipo() {
  try {
    const { data } = await api.get('/staff');
    equipo.value = data.filter((u) => u.es_activo && u.id !== sesionActiva.value?.usuario_id);
  } catch {
    equipo.value = [];
  }
}

async function cargarHistorial(url) {
  historial.value = [];
  try {
    const { data } = await api.get(url, { params: { limit: 30 } });
    historial.value = data.items;
  } catch (e) {
    errorModal.value = apiError(e, 'No se pudo cargar el historial.');
  }
}

async function enviar(peticion) {
  busy.value = true;
  errorModal.value = '';
  try {
    await peticion();
    cerrarModal();
    await cargar();
  } catch (e) {
    errorModal.value = apiError(e, 'No se pudo completar la operación.');
  } finally {
    busy.value = false;
  }
}

const abrirCaja = () => enviar(() => api.post('/caja/abrir', {
  monto_inicial: Number(form.value.monto_inicial || 0),
  comentario: form.value.comentario || null,
}));

const cerrarCaja = () => enviar(() => api.post('/caja/cerrar', {
  monto_declarado: Number(form.value.monto_declarado || 0),
  notas: form.value.notas || null,
}));

const cambiarTurno = () => enviar(() => api.post('/caja/cambio-turno', {
  usuario_id: form.value.usuario_id,
  monto_declarado: Number(form.value.monto_declarado || 0),
  notas: form.value.notas || null,
}));

const registrarMovimiento = (tipo) => enviar(() => api.post('/caja/movimientos', {
  tipo,
  monto: Number(form.value.monto || 0),
  comentario: form.value.comentario || null,
}));

async function eliminarMovimiento(m) {
  if (!confirm(`¿Eliminar este ${ETIQUETA_MOVIMIENTO[m.tipo].toLowerCase()} de ${pesos(m.monto)}?`)) return;
  try {
    await api.delete(`/caja/movimientos/${m.id}`);
    await cargar();
  } catch (e) {
    error.value = apiError(e, 'No se pudo eliminar el movimiento.');
  }
}

onMounted(() => {
  cargar();
  cargarTransacciones();
});
</script>

<template>
  <div class="page">
    <header class="head-row">
      <div>
        <h1 class="page-title">Caja</h1>
        <p class="page-sub">Turno, movimientos de efectivo y cuadre del día.</p>
      </div>
      <div class="head-acciones">
        <input type="date" class="input input-fecha" v-model="fecha" @change="cambiarDia" />
        <RouterLink to="/ventas" class="btn btn-ghost btn-sm">Historial de ventas</RouterLink>
        <button class="btn btn-ghost btn-sm" @click="abrirModal('egresos')">Historial de egresos</button>
        <button class="btn btn-ghost btn-sm" @click="abrirModal('cierres')">Historial de cierres</button>
      </div>
    </header>

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>

    <template v-else-if="estado">
      <!-- Estado del turno -->
      <section class="card caja-estado">
        <div class="ce-info">
          <h2>Caja</h2>
          <template v-if="sesion">
            <span class="chip chip-abierta">{{ sesion.usuario_nombre }}</span>
            <span class="ce-desde">Abierta {{ hora(sesion.abierta_at) }}</span>
          </template>
          <span v-else class="chip chip-cerrada">Sin caja abierta</span>
        </div>

        <p v-if="sesionOtroDia" class="ce-nota ce-aviso">
          Hay una caja sin cerrar del <strong>{{ sesionOtroDia.fecha }}</strong>,
          abierta por {{ sesionOtroDia.usuario_nombre }}. Hay que cerrarla (o
          hacer un cambio de turno) antes de poder abrir una nueva.
        </p>

        <div v-if="sesion" class="ce-cifras">
          <div><dt>Fondo inicial</dt><dd>{{ pesos(sesion.monto_inicial) }}</dd></div>
          <div class="ce-destacado">
            <dt>Efectivo esperado</dt>
            <dd>{{ pesos(estado.efectivo_esperado) }}</dd>
          </div>
        </div>

        <!-- Las acciones se muestran siempre, deshabilitadas mientras no haya
             turno abierto, en vez de aparecer y desaparecer: un botón que no
             está no se puede buscar, y con la caja cerrada la pantalla parecía
             no tener egresos. Deshabilitados enseñan el orden real — primero
             se abre la caja, después se mueve efectivo. -->
        <div class="ce-acciones">
          <button
            v-if="!sesionActiva && esHoy" class="btn btn-accent btn-sm"
            @click="abrirModal('abrir')"
          >Abrir caja</button>
          <button
            class="btn btn-ghost btn-sm" :disabled="!sesionActiva" :title="ayudaAccion"
            @click="abrirModal('cerrar')"
          >Cerrar caja</button>
          <button
            class="btn btn-ghost btn-sm" :disabled="!sesionActiva" :title="ayudaAccion"
            @click="abrirModal('turno')"
          >Cambio de turno</button>
          <button
            class="btn btn-ghost btn-sm" :disabled="!sesion" :title="ayudaAccion"
            @click="abrirModal('egreso')"
          >− Egreso de caja</button>
        </div>
        <p v-if="!sesionActiva" class="ce-nota">{{ ayudaAccion }}</p>
      </section>

      <!-- Turnos ya cerrados del día -->
      <section v-if="estado.sesiones.some((s) => !s.abierta)" class="card turnos-cerrados">
        <h3>Turnos cerrados</h3>
        <div class="tc-lista">
          <div v-for="s in estado.sesiones.filter((x) => !x.abierta)" :key="s.id" class="tc-item">
            <strong>{{ s.usuario_nombre }}</strong>
            <span class="muted">{{ hora(s.abierta_at) }} – {{ hora(s.cerrada_at) }}</span>
            <span>Contado {{ pesos(s.monto_declarado) }}</span>
            <span class="muted">Esperado {{ pesos(s.monto_esperado) }}</span>
            <span
              class="tc-dif"
              :class="s.diferencia === 0 ? 'ok' : (s.diferencia > 0 ? 'sobra' : 'falta')"
            >
              {{ s.diferencia === 0 ? 'Cuadra' : (s.diferencia > 0 ? `Sobran ${pesos(s.diferencia)}` : `Faltan ${pesos(-s.diferencia)}`) }}
            </span>
          </div>
        </div>
      </section>

      <!-- Movimientos de caja -->
      <section class="card">
        <h3>Movimientos de caja</h3>
        <p v-if="!estado.movimientos.length" class="empty">
          Sin movimientos de efectivo en este día.
        </p>
        <div v-else class="tabla tabla-mov">
          <div class="t-row t-head m-row">
            <span>Tipo de movimiento</span><span>Monto</span><span>Comentarios</span><span>Hora</span><span></span>
          </div>
          <div v-for="m in estado.movimientos" :key="m.id" class="t-row m-row">
            <span class="m-tipo">
              <strong>{{ ETIQUETA_MOVIMIENTO[m.tipo] || m.tipo }}</strong>
              <small class="muted">{{ m.usuario_nombre }}</small>
            </span>
            <span class="m-monto" :class="m.tipo === 'egreso' ? 'egreso' : ''">
              {{ m.tipo === 'egreso' ? '−' : '' }}{{ pesos(m.monto) }}
            </span>
            <span class="muted">{{ m.comentario || '—' }}</span>
            <span class="muted">{{ fechaHora(m.created_at) }}</span>
            <span class="acciones">
              <button
                v-if="m.eliminable" class="m-borrar" title="Eliminar movimiento"
                @click="eliminarMovimiento(m)"
              >🗑</button>
            </span>
          </div>
        </div>
      </section>

      <!-- Resúmenes del día -->
      <div class="resumenes">
        <section class="card res">
          <h3>Resumen productos</h3>
          <div class="res-linea res-total">
            <span>Ingreso bruto</span><strong>{{ pesos(estado.resumen_productos.ingreso_bruto) }}</strong>
          </div>
          <div v-if="estado.resumen_productos.descuentos" class="res-linea muted">
            <span>Descuentos</span><span>−{{ pesos(estado.resumen_productos.descuentos) }}</span>
          </div>
          <p class="res-nota">{{ estado.resumen_productos.lineas }} líneas · fármacos, alimento, pet-shop e insumos</p>
        </section>

        <section class="card res">
          <h3>Resumen servicios</h3>
          <div class="res-linea res-total">
            <span>Ingreso bruto</span><strong>{{ pesos(estado.resumen_servicios.ingreso_bruto) }}</strong>
          </div>
          <div v-if="estado.resumen_servicios.descuentos" class="res-linea muted">
            <span>Descuentos</span><span>−{{ pesos(estado.resumen_servicios.descuentos) }}</span>
          </div>
          <p class="res-nota">{{ estado.resumen_servicios.lineas }} líneas · clínico, laboratorio, peluquería, cirugía e imagenología</p>
        </section>

        <section class="card res res-destacada">
          <h3>Resumen transacciones</h3>
          <div class="res-linea"><span>Efectivo</span><span>{{ pesos(estado.resumen_transacciones.efectivo) }}</span></div>
          <div class="res-linea"><span>Débito</span><span>{{ pesos(estado.resumen_transacciones.debito) }}</span></div>
          <div class="res-linea"><span>Crédito</span><span>{{ pesos(estado.resumen_transacciones.credito) }}</span></div>
          <div class="res-linea"><span>Transferencia</span><span>{{ pesos(estado.resumen_transacciones.transferencia) }}</span></div>
          <div class="res-linea"><span>Otro</span><span>{{ pesos(estado.resumen_transacciones.otro) }}</span></div>
          <div class="res-linea res-total"><span>Total</span><strong>{{ pesos(estado.resumen_transacciones.total) }}</strong></div>
          <div v-if="estado.resumen_transacciones.pendiente" class="res-linea res-pendiente">
            <span>Pendiente de pago</span><span>{{ pesos(estado.resumen_transacciones.pendiente) }}</span>
          </div>
        </section>
      </div>

      <!-- Transacciones del día -->
      <section class="card">
        <div class="tx-head">
          <h3>Transacciones</h3>
          <input
            class="input input-buscar" v-model="buscarTx" @input="buscar"
            placeholder="Buscar por n°, cliente o documento…"
          />
        </div>
        <p v-if="loadingTx" class="empty">Cargando…</p>
        <p v-else-if="!transacciones.length" class="empty">Sin transacciones en este día.</p>
        <template v-else>
          <div class="tabla tabla-tx">
            <div class="t-row t-head tx-row">
              <span>N°</span><span>Hora</span><span>Ingreso</span><span>Tipo</span>
              <span>Cliente</span><span>Mascota</span><span>Asignado a</span><span>N° Documento</span>
            </div>
            <div v-for="t in transacciones" :key="t.id" class="t-row tx-row">
              <span class="tx-num">{{ t.numero }}</span>
              <span class="muted">{{ hora(t.fecha) }}</span>
              <span class="tx-monto">{{ pesos(t.ingreso) }}</span>
              <span><span class="tag">{{ t.tipo }}</span></span>
              <span class="tx-elipsis">{{ t.cliente }}</span>
              <span class="tx-elipsis">{{ t.mascota }}</span>
              <span class="tx-elipsis">{{ t.asignado_a }}</span>
              <span class="muted">{{ t.documento || 'Sin boleta' }}</span>
            </div>
          </div>
          <Paginador
            :page="paginaTx" :total-paginas="paginacionTx.total_paginas"
            :total="paginacionTx.total" :limit="paginacionTx.limit"
            @cambiar="cambiarPaginaTx"
          />
        </template>
      </section>
    </template>

    <!-- ── Modales ── -->
    <div v-if="modal" class="modal-backdrop" @click.self="cerrarModal">
      <div class="modal modal-caja" role="dialog" aria-modal="true">
        <div class="modal-head">
          <h3>
            {{ {
              abrir: 'Abrir caja', cerrar: 'Cerrar caja', turno: 'Cambio de turno',
              egreso: 'Egreso de caja',
              egresos: 'Historial de egresos', cierres: 'Historial de cierres',
            }[modal] }}
          </h3>
          <button class="modal-close" aria-label="Cerrar" @click="cerrarModal">✕</button>
        </div>

        <!-- Abrir -->
        <form v-if="modal === 'abrir'" class="form" @submit.prevent="abrirCaja">
          <p class="muted">Declara el efectivo con que parte el turno.</p>
          <div class="field">
            <label for="c-inicial">Fondo inicial</label>
            <input id="c-inicial" class="input" type="number" min="0" step="1" v-model="form.monto_inicial" required />
          </div>
          <div class="field">
            <label for="c-com">Comentario</label>
            <input id="c-com" class="input" v-model="form.comentario" placeholder="Opcional" />
          </div>
          <p v-if="errorModal" class="error-text">{{ errorModal }}</p>
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" @click="cerrarModal">Cancelar</button>
            <button type="submit" class="btn btn-accent" :disabled="busy">{{ busy ? 'Abriendo…' : 'Abrir caja' }}</button>
          </div>
        </form>

        <!-- Cerrar -->
        <form v-else-if="modal === 'cerrar'" class="form" @submit.prevent="cerrarCaja">
          <div class="esperado-box">
            <span>Efectivo esperado</span>
            <strong>{{ pesos(efectivoEsperadoActivo) }}</strong>
          </div>
          <div class="field">
            <label for="c-contado">Efectivo contado</label>
            <input id="c-contado" class="input" type="number" min="0" step="1" v-model="form.monto_declarado" required />
            <!-- La diferencia se muestra antes de confirmar: si hay un
                 descuadre, es el momento de volver a contar, no después. -->
            <small v-if="form.monto_declarado !== ''" class="dif-aviso" :class="Number(form.monto_declarado) - (efectivoEsperadoActivo || 0) === 0 ? 'ok' : 'alerta'">
              {{
                Number(form.monto_declarado) - (efectivoEsperadoActivo || 0) === 0
                  ? 'Cuadra con lo esperado.'
                  : `Diferencia: ${pesos(Number(form.monto_declarado) - (efectivoEsperadoActivo || 0))}`
              }}
            </small>
          </div>
          <div class="field">
            <label for="c-notas">Notas del cierre</label>
            <textarea id="c-notas" class="textarea" v-model="form.notas" placeholder="Opcional"></textarea>
          </div>
          <p v-if="errorModal" class="error-text">{{ errorModal }}</p>
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" @click="cerrarModal">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="busy">{{ busy ? 'Cerrando…' : 'Cerrar caja' }}</button>
          </div>
        </form>

        <!-- Cambio de turno -->
        <form v-else-if="modal === 'turno'" class="form" @submit.prevent="cambiarTurno">
          <p class="muted">
            Se cierra el turno de {{ sesionActiva?.usuario_nombre }} y se abre uno nuevo con el
            efectivo contado como fondo inicial.
          </p>
          <div class="field">
            <label for="c-quien">Recibe la caja</label>
            <select id="c-quien" class="select" v-model="form.usuario_id" required>
              <option value="" disabled>Selecciona un miembro del equipo…</option>
              <option v-for="u in equipo" :key="u.id" :value="u.id">{{ u.nombre }}</option>
            </select>
          </div>
          <div class="field">
            <label for="c-entrega">Efectivo contado</label>
            <input id="c-entrega" class="input" type="number" min="0" step="1" v-model="form.monto_declarado" required />
            <small class="muted">Esperado: {{ pesos(efectivoEsperadoActivo) }}</small>
          </div>
          <p v-if="errorModal" class="error-text">{{ errorModal }}</p>
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" @click="cerrarModal">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="busy">{{ busy ? 'Guardando…' : 'Traspasar caja' }}</button>
          </div>
        </form>

        <!-- Egreso -->
        <form
          v-else-if="modal === 'egreso'" class="form"
          @submit.prevent="registrarMovimiento('egreso')"
        >
          <p class="muted">
            Efectivo que sale del cajón: pago a proveedor, compra menor, etc.
          </p>
          <div class="field">
            <label for="c-monto">Monto</label>
            <input id="c-monto" class="input" type="number" min="1" step="1" v-model="form.monto" required />
            <small class="muted">
              Disponible en caja: {{ pesos(estado?.efectivo_esperado) }}
            </small>
          </div>
          <div class="field">
            <label for="c-motivo">Comentario</label>
            <input id="c-motivo" class="input" v-model="form.comentario" placeholder="En qué se gastó" />
          </div>
          <p v-if="errorModal" class="error-text">{{ errorModal }}</p>
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" @click="cerrarModal">Cancelar</button>
            <button type="submit" class="btn btn-accent" :disabled="busy">{{ busy ? 'Guardando…' : 'Registrar' }}</button>
          </div>
        </form>

        <!-- Historial de egresos -->
        <div v-else-if="modal === 'egresos'">
          <p v-if="errorModal" class="error-text">{{ errorModal }}</p>
          <p v-if="!historial.length" class="empty">Sin egresos registrados.</p>
          <div v-else class="hist">
            <div v-for="m in historial" :key="m.id" class="hist-item">
              <div>
                <strong>{{ pesos(m.monto) }}</strong>
                <small class="muted">{{ m.comentario || 'Sin detalle' }}</small>
              </div>
              <div class="hist-meta">
                <span>{{ fechaHora(m.created_at) }}</span>
                <small class="muted">{{ m.usuario_nombre }}</small>
              </div>
            </div>
          </div>
        </div>

        <!-- Historial de cierres -->
        <div v-else-if="modal === 'cierres'">
          <p v-if="errorModal" class="error-text">{{ errorModal }}</p>
          <p v-if="!historial.length" class="empty">Sin turnos registrados.</p>
          <div v-else class="hist">
            <div v-for="s in historial" :key="s.id" class="hist-item">
              <div>
                <strong>{{ s.usuario_nombre }}</strong>
                <small class="muted">{{ s.fecha }} · {{ hora(s.abierta_at) }} – {{ s.cerrada_at ? hora(s.cerrada_at) : 'abierta' }}</small>
              </div>
              <div class="hist-meta">
                <span v-if="s.monto_declarado !== null">Contado {{ pesos(s.monto_declarado) }}</span>
                <small
                  v-if="s.diferencia !== null"
                  :class="s.diferencia === 0 ? 'dif-ok' : 'dif-alerta'"
                >
                  {{ s.diferencia === 0 ? 'Cuadra' : `Dif. ${pesos(s.diferencia)}` }}
                </small>
                <small v-else class="muted">En curso</small>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.head-acciones { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.input-fecha { width: auto; padding: 0.45em 0.7em; font-size: var(--t-base); }
.muted { color: var(--ink-soft); }
h3 { font-size: var(--t-lg); color: var(--brand); margin-bottom: 0.9rem; }

/* ── Estado del turno ── */
.caja-estado {
  display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;
  margin-bottom: 1rem;
}
.ce-info { display: flex; align-items: center; gap: 0.7rem; flex-wrap: wrap; }
.ce-info h2 { font-size: var(--t-xl); color: var(--brand); margin-right: 0.2rem; }
.ce-desde { color: var(--ink-soft); font-size: var(--t-sm); }
.chip { padding: 0.25em 0.85em; border-radius: var(--r-full); font-size: var(--t-micro); font-weight: 600; }
.chip-abierta { background: var(--ok-tint); color: var(--ok); }
.chip-cerrada { background: var(--bg); color: var(--ink-soft); border: 1px solid var(--line); }

.ce-cifras { display: flex; gap: 1.8rem; margin-left: auto; }
.ce-cifras dt { font-size: var(--t-micro); color: var(--ink-soft); }
.ce-cifras dd { margin: 0; font-weight: 700; font-size: var(--t-lg); }
.ce-destacado dd { color: var(--brand); font-size: var(--t-2xl); }

.ce-acciones { display: flex; gap: 0.5rem; flex-wrap: wrap; }
/* Ocupa la fila entera del flex de .caja-estado: la explicación va debajo de
   los botones que justifica, no apretada a su costado. */
.ce-nota { flex-basis: 100%; color: var(--ink-soft); font-size: var(--t-sm); margin-top: -0.6rem; }
.ce-aviso {
  flex-basis: 100%; margin-top: 0; margin-bottom: 0.4rem;
  background: var(--warn-tint); color: var(--warn-ink);
  border-radius: var(--r-sm); padding: 0.5rem 0.7rem; font-size: var(--t-sm);
}

/* ── Turnos cerrados ── */
.turnos-cerrados { margin-bottom: 1rem; }
.tc-lista { display: flex; flex-direction: column; gap: 0.5rem; }
.tc-item {
  display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
  padding: 0.6rem 0.9rem; background: var(--bg); border-radius: var(--r-md);
  font-size: var(--t-base);
}
.tc-item strong { color: var(--brand); }
.tc-dif { margin-left: auto; font-weight: 600; }
.tc-dif.ok { color: var(--ok); }
.tc-dif.sobra { color: var(--warn-ink); }
.tc-dif.falta { color: var(--danger); }

/* ── Movimientos ── */
.tabla-mov { overflow-x: auto; }
.m-row { grid-template-columns: 1.4fr 0.9fr 1.8fr 1fr 40px; min-width: 760px; }
.m-tipo { display: flex; flex-direction: column; line-height: 1.25; }
.m-tipo small { font-size: var(--t-micro); }
.m-monto { font-weight: 700; font-variant-numeric: tabular-nums; }
.m-monto.egreso { color: var(--danger); }
.m-borrar {
  width: 30px; height: 30px; border-radius: var(--r-sm);
  display: grid; place-items: center; font-size: var(--t-base);
  transition: background 0.15s;
}
.m-borrar:hover { background: var(--danger-tint); }

/* ── Resúmenes ── */
.resumenes {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 1rem; margin: 1rem 0;
}
.res-linea {
  display: flex; justify-content: space-between; gap: 1rem;
  padding: 0.4rem 0; font-size: var(--t-base);
  font-variant-numeric: tabular-nums;
}
.res-total {
  border-top: 1px solid var(--line); margin-top: 0.3rem; padding-top: 0.6rem;
  font-size: var(--t-lg);
}
.res-total strong { font-size: var(--t-xl); color: var(--brand); }
.res-nota { color: var(--ink-soft); font-size: var(--t-micro); margin-top: 0.6rem; line-height: 1.4; }
.res-pendiente { color: var(--danger); font-weight: 600; }
/* El total del día es la cifra que se busca primero al cuadrar: se destaca
   con el azul de marca en vez del verde del sistema anterior, que en esta
   paleta (azul + naranja) entraría como un tercer color sin función. */
.res-destacada { border-color: var(--brand); box-shadow: var(--shadow); }
.res-destacada .res-total strong { font-size: var(--t-2xl); }

/* ── Transacciones ── */
.tx-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.9rem; }
.tx-head h3 { margin-bottom: 0; }
.input-buscar { width: auto; min-width: 280px; padding: 0.45em 0.8em; font-size: var(--t-base); }
.tabla-tx { overflow-x: auto; }
/* La tabla mas ancha del panel: ocho columnas y 980px de minimo. Es justo
   donde mas se pierde la fila, y es la pantalla con la que se cuadra la caja
   todos los dias. */
.tx-row { grid-template-columns: 68px 52px 96px 104px 1.3fr 0.8fr 1.5fr 1.1fr; min-width: 980px; }
.tx-num { font-weight: 600; color: var(--brand); font-variant-numeric: tabular-nums; }
.tx-monto { font-weight: 600; font-variant-numeric: tabular-nums; }
.tx-elipsis { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* ── Modales ── */
.modal-caja { max-width: 480px; }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.form > .muted { font-size: var(--t-base); }
.esperado-box {
  display: flex; justify-content: space-between; align-items: center;
  background: var(--brand-tint); border-radius: var(--r-md); padding: 0.8rem 1rem;
}
.esperado-box strong { font-size: var(--t-xl); color: var(--brand); }
.dif-aviso { font-size: var(--t-micro); font-weight: 600; }
.dif-aviso.ok { color: var(--ok); }
.dif-aviso.alerta { color: var(--danger); }

.hist { display: flex; flex-direction: column; gap: 0.5rem; max-height: 55vh; overflow-y: auto; }
.hist-item {
  display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start;
  padding: 0.7rem 0.9rem; background: var(--bg); border-radius: var(--r-md);
}
.hist-item > div { display: flex; flex-direction: column; line-height: 1.3; }
.hist-item small { font-size: var(--t-micro); }
.hist-meta { text-align: right; font-size: var(--t-sm); }
.dif-ok { color: var(--ok); font-weight: 600; }
.dif-alerta { color: var(--danger); font-weight: 600; }

@media (max-width: 720px) {
  .ce-cifras { margin-left: 0; }
  .input-buscar { min-width: 0; width: 100%; }
}
</style>
