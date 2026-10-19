<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { api, apiError, formatCLP, PUNTOS_VISIBLE } from '../api.js';
import { cargarConfigImpresion, imprimirRecibo } from '../recibo.js';

/* Reglas de Ajustes → Inventario y ventas. El backend las hace cumplir; acá
   se leen para avisar ANTES de llegar al error. Sin configuración, ninguna
   regla aplica (es lo que había antes). */
const reglas = ref({});
const impresionCfg = ref(null);
onMounted(async () => {
  try { reglas.value = (await api.get('/configuracion/ventas')).data; } catch { reglas.value = {}; }
  impresionCfg.value = await cargarConfigImpresion();
});

const props = defineProps({
  // Cuando se abre desde la ficha de un paciente: mismo shape que arma
  // `elegirPropietario` más abajo ({ id, nombre, usuario_id, dueno_nombre }).
  // Salta directo al paso 2 con el paciente ya elegido — el botón "← Cliente"
  // del paso 2 sigue permitiendo cambiarlo, sin lógica nueva para eso.
  pacientePreseleccionado: { type: Object, default: null },
});

const emit = defineEmits(['close', 'creada']);

/* pasos: 1 propietario (opcional) · 2 líneas de venta · 3 pago y confirmar */
const paso = ref(props.pacientePreseleccionado ? 2 : 1);
const error = ref('');
const busy = ref(false);

/* ── Paso 1: propietario / mascota (opcional: una venta de mostrador no
   siempre tiene dueño asociado, ej. venta de un producto a alguien de paso).
   Se busca contra el servidor (hay miles de mascotas, cargarlas todas al
   abrir el modal no es viable). ── */
const mascotasFiltradas = ref([]);
const qPropietario = ref('');
const buscandoPropietario = ref(false);
const propietario = ref(props.pacientePreseleccionado ? { ...props.pacientePreseleccionado } : null); // { usuario_id, dueno_nombre, id: mascota_id, nombre: mascota_nombre } o null

let debouncePropietario;
function buscarPropietario() {
  clearTimeout(debouncePropietario);
  if (qPropietario.value.trim().length < 2) {
    mascotasFiltradas.value = [];
    return;
  }
  debouncePropietario = setTimeout(async () => {
    buscandoPropietario.value = true;
    try {
      const { data } = await api.get('/mascotas/todas', { params: { q: qPropietario.value.trim(), limit: 15 } });
      mascotasFiltradas.value = data.items || [];
    } catch {
      mascotasFiltradas.value = [];
    } finally {
      buscandoPropietario.value = false;
    }
  }, 300);
}

function elegirPropietario(m) {
  propietario.value = m;
  paso.value = 2;
}

function omitirPropietario() {
  propietario.value = null;
  paso.value = 2;
}

/* ── Paso 2: líneas de venta (búsqueda de inventario contra el servidor,
   mismo motivo que el paso 1). ── */
const qItem = ref('');
const itemsFiltrados = ref([]);
const buscandoItem = ref(false);
const lineas = ref([]); // { item_id, nombre, tipo, cantidad, precio_venta, precio_costo, descuento }

let debounceItem;
function buscarItem() {
  clearTimeout(debounceItem);
  if (qItem.value.trim().length < 2) {
    itemsFiltrados.value = [];
    return;
  }
  debounceItem = setTimeout(async () => {
    buscandoItem.value = true;
    try {
      const { data } = await api.get('/inventario', { params: { q: qItem.value.trim(), limit: 12, excluir_uso_interno: true } });
      itemsFiltrados.value = data.items || [];
    } catch {
      itemsFiltrados.value = [];
    } finally {
      buscandoItem.value = false;
    }
  }, 300);
}

function agregarDesdeInventario(it) {
  lineas.value.push({
    item_id: it.id,
    servicio_id: null,
    nombre: it.nombre,
    tipo: it.tipo,
    cantidad: 1,
    precio_venta: Number(it.precio_venta) || 0,
    precio_costo: Number(it.precio_costo) || 0,
    descuento: 0,
    stockDisponible: it.stock_actual,
  });
  qItem.value = '';
  itemsFiltrados.value = [];
}

/* Servicios del catálogo (las "Consultas"). Se cargan enteros y no por
   búsqueda como el inventario: son una docena, no miles.

   Importa que la línea quede ligada al servicio y no solo con su nombre
   escrito: es lo que permite después responder "cuántas veces se vendió la
   consulta dermatológica" sin comparar texto libre, donde "consulta derma"
   ya no cuenta. */
const servicios = ref([]);

async function cargarServicios() {
  try {
    const { data } = await api.get('/catalogo/servicios');
    servicios.value = data;
  } catch {
    servicios.value = [];
  }
}

/* El <select> es un disparador, no un campo con estado: se vacía a mano en
   vez de con v-model. Con v-model, Vue agrupa el cambio del usuario y el
   reset en el mismo tick, ve que el valor pasó de '' a '' y no repinta —
   el desplegable se quedaba mostrando el servicio ya agregado. */
function agregarServicio(evento) {
  const select = evento.target;
  const s = servicios.value.find((x) => x.id === select.value);
  select.value = '';
  if (!s) return;
  lineas.value.push({
    item_id: null,
    servicio_id: s.id,
    nombre: s.nombre,
    tipo: 'Servicio',
    cantidad: 1,
    precio_venta: Number(s.precio_clp) || 0,
    precio_costo: 0,
    descuento: 0,
    stockDisponible: null,
  });
}

/* Sigue existiendo la línea a mano: el mostrador cobra cosas que no están en
   ningún catálogo, y obligarlas a existir antes de poder cobrarlas frenaría
   la caja. Esas quedan sin `servicio_id`, igual que todo lo importado de
   ACVet. */
function agregarLineaManual() {
  lineas.value.push({
    item_id: null, servicio_id: null, nombre: '', tipo: 'Servicio',
    cantidad: 1, precio_venta: 0, precio_costo: 0, descuento: 0, stockDisponible: null,
  });
}

function quitarLinea(i) {
  lineas.value.splice(i, 1);
}

function subtotalLinea(l) {
  return Math.max(0, (l.precio_venta || 0) * (l.cantidad || 0) - (l.descuento || 0));
}

const totalLineas = computed(() => lineas.value.reduce((n, l) => n + subtotalLinea(l), 0));

const lineasValidas = computed(
  () => lineas.value.length > 0 && lineas.value.every((l) => l.nombre.trim() && l.cantidad > 0)
);

/* Vender más de lo que hay no bloquea la venta —frenar el mostrador porque el
   stock está mal cargado sale más caro que el descuadre—, pero sí se avisa. El
   dato ya se traía del inventario al agregar la línea y no se mostraba en
   ninguna parte: se podían vender 10 unidades de algo con 3 sin una sola
   señal. Ahora el backend deja el stock en negativo, así que la diferencia
   queda visible después; esto la hace visible antes. */
function sobreStock(l) {
  return l.stockDisponible != null && l.cantidad > l.stockDisponible;
}
const lineasSobreStock = computed(() => lineas.value.filter(sobreStock));

/* Lo que frena pasar al cobro según las reglas encendidas. */
const bloqueoVenta = computed(() => {
  if (reglas.value.exigir_paciente && !propietario.value) {
    return 'La clínica exige registrar el paciente para vender: vuelve al primer paso y elige la mascota.';
  }
  if (reglas.value.no_vender_sin_stock && lineasSobreStock.value.length) {
    return 'La clínica no permite vender sin stock: ajusta las cantidades marcadas.';
  }
  return '';
});

/* ── Descuentos con clave y puntos ── */
const claveDescuento = ref('');
const hayDescuento = computed(() => lineas.value.some((l) => Number(l.descuento) > 0));
const pideClave = computed(() => !!reglas.value.clave_descuentos && hayDescuento.value);

const puntos = ref(null);   // { activo, saldo, valor_punto }
const puntosCanjear = ref(0);
watch(propietario, async (p) => {
  puntos.value = null;
  puntosCanjear.value = 0;
  if (!PUNTOS_VISIBLE || !p?.usuario_id) return;
  try {
    const { data } = await api.get(`/transacciones/puntos/${p.usuario_id}`);
    puntos.value = data.activo ? data : null;
  } catch { puntos.value = null; }
}, { immediate: true });
const montoCanje = computed(() => {
  if (!puntos.value) return 0;
  const n = Math.max(0, Math.min(Number(puntosCanjear.value) || 0, puntos.value.saldo));
  return Math.min(totalLineas.value, n * puntos.value.valor_punto);
});

function irAPago() {
  /* El efectivo NO se prellena con el total, aunque sea el medio más común.
     Prellenarlo hacía que cobrar con tarjeta fuera un campo minado: el cajero
     escribía el monto en "Débito" y quedaban los dos, con el doble de pago
     registrado. Como `_efectivo_de_ventas()` del backend suma `pago_efectivo`
     para el cuadre del turno, eso terminaba en un faltante de caja falso.
     Que el cajero escriba dónde entró la plata es un segundo de trabajo y
     elimina la clase entera de error. */
  paso.value = 3;
}

/* ── Paso 3: desglose de pago ── */
const documentoId = ref('');

/* Quién atendió. Opcional a propósito: bloquear la caja por un campo nuevo
   cuesta más de lo que rinde, y una venta sin el dato sigue siendo una venta
   correcta. Se manda igual en la cabecera y en cada línea porque las dos
   columnas ya existen y quien liquide después va a mirar una u otra. */
const especialistaId = ref('');
const especialistas = ref([]);
onMounted(async () => {
  try {
    const { data } = await api.get('/catalogo/especialistas/todos');
    especialistas.value = data.filter((e) => e.activo);
  } catch { /* sin lista, el selector queda vacío y la venta se cobra igual */ }
});
const pagoEfectivo = ref(0);
const pagoDebito = ref(0);
const pagoCredito = ref(0);
const pagoTransferencia = ref(0);
const pagoOtro = ref(0);

const totalPagado = computed(
  () => Number(pagoEfectivo.value || 0) + Number(pagoDebito.value || 0) + Number(pagoCredito.value || 0)
    + Number(pagoTransferencia.value || 0) + Number(pagoOtro.value || 0)
);
// Lo que hay que COBRAR: el canje de puntos rebaja el total, no es un pago.
const totalACobrar = computed(() => totalLineas.value - montoCanje.value);
const pendiente = computed(() => Math.max(0, Math.round((totalACobrar.value - totalPagado.value) * 100) / 100));
const sobrepago = computed(() => totalPagado.value > totalACobrar.value);

/* ── Las dos razones sociales ──────────────────────────────────────────────
   La clínica factura los productos con una empresa y los servicios con otra,
   así que el cobro se hace en dos: la boleta interna sale completa, pero al
   pasar la tarjeta hay que saber cuánto va a cada una.

   El reparto sale de QUÉ se vendió, no de cómo se paga. Misma regla que
   `es_linea_servicio()` en el backend, que es quien la aplica al guardar: si
   las dos se separan, el mostrador cobra un monto y la venta registra otro. */
const TIPOS_SERVICIO = ['Clinico', 'Laboratorio', 'Peluqueria', 'Quirurgico', 'Imagenología'];

function esServicio(l) {
  if (l.servicio_id) return true;
  const t = (l.tipo || '').trim();
  return TIPOS_SERVICIO.includes(t) || t.toLowerCase() === 'servicio';
}

const totalServicios = computed(() =>
  lineas.value.filter(esServicio).reduce((n, l) => n + subtotalLinea(l), 0)
);
const totalProductos = computed(() => totalLineas.value - totalServicios.value);
/* Solo se muestra el desglose cuando hay de los dos: con una venta que es
   puro producto, repetir el mismo número dos veces estorba. */
const hayDosRazones = computed(() => totalProductos.value > 0 && totalServicios.value > 0);

const razones = ref({ productos: 'Productos', servicios: 'Servicios' });
onMounted(async () => {
  try {
    const { data } = await api.get('/catalogo/ajustes');
    razones.value = {
      productos: data.razon_social_productos?.nombre || 'Productos',
      servicios: data.razon_social_servicios?.nombre || 'Servicios',
    };
  } catch {
    /* Sin ajustes cargados quedan los rótulos genéricos, que igual sirven. */
  }
});

onMounted(cargarServicios);

/* Una venta se puede dejar a medio armar: la atención sigue en curso y se
   le van cargando cosas para cobrar al final. Un borrador NO descuenta
   stock ni cuenta en el cuadre de caja hasta que se confirma, así que
   dejarlo abierto no descuadra nada. */
async function confirmar(comoBorrador = false) {
  busy.value = true;
  error.value = '';
  try {
    const { data } = await api.post('/transacciones', {
      estado_venta: comoBorrador ? 'borrador' : 'confirmada',
      propietario_id: propietario.value?.usuario_id || null,
      documento_id: documentoId.value.trim() || null,
      especialista_id: especialistaId.value || null,
      // Un borrador viaja sin pagos: el backend los rechaza, porque plata
      // cobrada en una venta que Caja no cuenta deja el turno con un
      // sobrante que nadie puede explicar.
      pago_efectivo: comoBorrador ? 0 : (pagoEfectivo.value || 0),
      pago_debito: comoBorrador ? 0 : (pagoDebito.value || 0),
      pago_credito: comoBorrador ? 0 : (pagoCredito.value || 0),
      pago_transferencia: comoBorrador ? 0 : (pagoTransferencia.value || 0),
      pago_otro: comoBorrador ? 0 : (pagoOtro.value || 0),
      total_pendiente: comoBorrador ? 0 : pendiente.value,
      ventas: lineas.value.map((l) => ({
        item_id: l.item_id,
        servicio_id: l.servicio_id || null,
        propietario_id: propietario.value?.usuario_id || null,
        mascota_id: propietario.value?.id || null,
        especialista_id: especialistaId.value || null,
        nombre: l.nombre.trim(),
        tipo: l.tipo || null,
        cantidad: l.cantidad,
        precio_costo: l.precio_costo || 0,
        precio_venta: l.precio_venta || 0,
        descuento: l.descuento || 0,
      })),
      ...(pideClave.value ? { clave_descuento: claveDescuento.value } : {}),
      ...(!comoBorrador && montoCanje.value > 0 ? { puntos_canjeados: Number(puntosCanjear.value) } : {}),
    });
    // «Imprimir automáticamente al confirmar venta» (Ajustes → Impresión).
    if (!comoBorrador && impresionCfg.value?.auto_imprimir) {
      imprimirRecibo(data, impresionCfg.value, { cliente: propietario.value?.dueno_nombre });
    }
    emit('creada', data);
  } catch (e) {
    error.value = apiError(e, 'No se pudo registrar la venta.');
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal card nv-modal" role="dialog" aria-modal="true" aria-label="Nueva venta">
      <div class="nv-head">
        <h2>Nueva venta</h2>
        <button class="modal-close" aria-label="Cerrar" @click="emit('close')">✕</button>
      </div>

      <nav class="migas">
        <button :class="{ done: paso > 1, current: paso === 1 }" @click="paso = 1">
          {{ propietario ? propietario.dueno_nombre : 'Cliente' }}
        </button>
        <button :class="{ done: paso > 2, current: paso === 2 }" :disabled="paso < 2" @click="paso >= 2 && (paso = 2)">Productos</button>
        <button :class="{ current: paso === 3 }" :disabled="paso < 3">Pago</button>
      </nav>

      <div class="nv-body">
        <!-- Paso 1: cliente (opcional) -->
        <section v-if="paso === 1">
          <input
            class="input" v-model="qPropietario" placeholder="Buscar por mascota o dueño… (mín. 2 letras)"
            autofocus @input="buscarPropietario"
          />
          <div class="opciones-list">
            <p v-if="buscandoPropietario" class="empty">Buscando…</p>
            <template v-else>
              <button v-for="m in mascotasFiltradas" :key="m.id" class="opcion-row" @click="elegirPropietario(m)">
                <strong>{{ m.nombre }}</strong>
                <span class="muted">{{ m.dueno_nombre }}</span>
                <span class="opcion-go">→</span>
              </button>
              <p v-if="qPropietario.trim().length >= 2 && !mascotasFiltradas.length" class="empty">Sin resultados.</p>
            </template>
          </div>
          <button type="button" class="btn btn-ghost btn-sm omitir-btn" @click="omitirPropietario">
            Venta sin cliente asociado →
          </button>
        </section>

        <!-- Paso 2: líneas de venta -->
        <section v-else-if="paso === 2">
          <button class="volver-paso" @click="paso = 1">
            ← {{ propietario ? `${propietario.nombre} (${propietario.dueno_nombre})` : 'Sin cliente' }}
          </button>

          <div class="buscador-item">
            <input
              class="input" v-model="qItem" placeholder="Buscar producto o insumo en inventario… (mín. 2 letras)"
              @input="buscarItem"
            />
            <div v-if="buscandoItem || itemsFiltrados.length" class="opciones-list opciones-flotante">
              <p v-if="buscandoItem" class="empty">Buscando…</p>
              <button v-for="it in itemsFiltrados" :key="it.id" class="opcion-row" @click="agregarDesdeInventario(it)">
                <strong>{{ it.nombre }}</strong>
                <span class="muted">{{ formatCLP(it.precio_venta) }} · stock {{ it.stock_actual }}</span>
                <span class="opcion-go">+</span>
              </button>
            </div>
          </div>
          <div class="agregar-fila">
            <label class="sr-only" for="nv-servicio">Agregar servicio del catálogo</label>
            <select id="nv-servicio" class="input input-sm" @change="agregarServicio">
              <option value="">+ Agregar servicio del catálogo…</option>
              <option v-for="s in servicios" :key="s.id" :value="s.id">
                {{ s.emoji }} {{ s.nombre }}<template v-if="s.precio_clp"> — {{ formatCLP(s.precio_clp) }}</template>
              </option>
            </select>
            <button type="button" class="btn btn-ghost btn-sm" @click="agregarLineaManual">+ Línea manual</button>
          </div>

          <p v-if="!lineas.length" class="empty">Agrega al menos un producto o servicio.</p>

          <ul v-else class="lineas-venta">
            <li v-for="(l, i) in lineas" :key="i" class="linea-venta">
              <input
                class="input input-sm linea-nombre" v-model="l.nombre"
                :readonly="!!(l.item_id || l.servicio_id)" placeholder="Nombre del producto o servicio"
              />
              <input
                class="input input-sm linea-num" type="number" min="1" v-model.number="l.cantidad"
                :class="{ 'sobre-stock': sobreStock(l) }"
                :title="sobreStock(l) ? `Solo hay ${l.stockDisponible} en stock` : 'Cantidad'"
              />
              <input class="input input-sm linea-num" type="number" min="0" step="1" v-model.number="l.precio_venta" title="Precio unitario" />
              <input class="input input-sm linea-num" type="number" min="0" step="1" v-model.number="l.descuento" title="Descuento" />
              <span class="linea-subtotal">{{ formatCLP(subtotalLinea(l)) }}</span>
              <button type="button" class="linea-quitar" aria-label="Quitar línea" @click="quitarLinea(i)">✕</button>
            </li>
          </ul>

          <div v-if="lineas.length" class="lineas-cabecera">
            <span></span><span>Cant.</span><span>Precio</span><span>Desc.</span><span>Subtotal</span><span></span>
          </div>

          <!-- Después del encabezado de columnas, no entre él y las filas:
               ahí lo dejaba huérfano bajo el aviso. -->
          <p v-if="lineasSobreStock.length" class="aviso-forzado aviso-stock">
            ⚠ Estás vendiendo más de lo que hay registrado:
            <span v-for="(l, i) in lineasSobreStock" :key="i">
              {{ l.nombre || 'sin nombre' }} ({{ l.cantidad }} de {{ l.stockDisponible }}){{ i < lineasSobreStock.length - 1 ? ', ' : '' }}</span>.
            <template v-if="reglas.no_vender_sin_stock">La clínica no permite vender sin stock.</template>
            <template v-else>La venta se registra igual y el stock quedará en negativo para que puedas cuadrarlo.</template>
          </p>

          <div v-if="lineas.length" class="nv-total">
            <span>Total</span>
            <strong>{{ formatCLP(totalLineas) }}</strong>
          </div>

          <p v-if="error" class="error-text">{{ error }}</p>
          <p v-if="bloqueoVenta" class="aviso-forzado">⚠ {{ bloqueoVenta }}</p>
          <button class="btn btn-primary btn-block" :disabled="!lineasValidas || !!bloqueoVenta" @click="irAPago">
            Continuar al pago →
          </button>
          <button
            class="btn btn-ghost btn-block btn-borrador"
            :disabled="!lineasValidas || busy" @click="confirmar(true)"
          >
            {{ busy ? 'Guardando…' : 'Guardar como borrador (cobrar después)' }}
          </button>
        </section>

        <!-- Paso 3: pago y confirmar -->
        <section v-else-if="paso === 3">
          <button class="volver-paso" @click="paso = 2">← Productos</button>

          <div class="resumen">
            <div v-if="montoCanje" class="res-row"><span>Canje de puntos</span><strong>−{{ formatCLP(montoCanje) }}</strong></div>
            <div class="res-row"><span>Total a cobrar</span><strong>{{ formatCLP(totalACobrar) }}</strong></div>
          </div>

          <div v-if="puntos" class="field">
            <label for="nv-puntos">Canjear puntos (saldo: {{ puntos.saldo }}, cada uno vale {{ formatCLP(puntos.valor_punto) }})</label>
            <input id="nv-puntos" class="input" type="number" min="0" :max="puntos.saldo" step="1" v-model.number="puntosCanjear" />
          </div>
          <div v-if="pideClave" class="field">
            <label for="nv-clave">Clave para autorizar el descuento</label>
            <input id="nv-clave" class="input" type="password" autocomplete="off" v-model="claveDescuento" />
          </div>

          <!-- El cobro va separado por razón social: la boleta interna sale
               completa, pero cada empresa cobra lo suyo. -->
          <div v-if="hayDosRazones" class="razones">
            <p class="razones-tit">Cobrar por separado</p>
            <div class="razon-row">
              <span>{{ razones.productos }}</span>
              <strong>{{ formatCLP(totalProductos) }}</strong>
            </div>
            <div class="razon-row">
              <span>{{ razones.servicios }}</span>
              <strong>{{ formatCLP(totalServicios) }}</strong>
            </div>
          </div>

          <div class="grid-2">
            <div class="field">
              <label for="nv-doc">N.º de documento (opcional)</label>
              <input id="nv-doc" class="input" v-model="documentoId" placeholder="Boleta, factura…" />
            </div>
            <div class="field">
              <label for="nv-atendio">¿Quién atendió? (opcional)</label>
              <select id="nv-atendio" class="select" v-model="especialistaId">
                <option value="">—</option>
                <option v-for="e in especialistas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
              </select>
            </div>
          </div>

          <div class="grid-2">
            <div class="field">
              <label for="nv-efectivo">Efectivo</label>
              <input id="nv-efectivo" class="input" type="number" min="0" step="1" v-model.number="pagoEfectivo" />
            </div>
            <div class="field">
              <label for="nv-debito">Débito</label>
              <input id="nv-debito" class="input" type="number" min="0" step="1" v-model.number="pagoDebito" />
            </div>
            <div class="field">
              <label for="nv-credito">Crédito</label>
              <input id="nv-credito" class="input" type="number" min="0" step="1" v-model.number="pagoCredito" />
            </div>
            <div class="field">
              <label for="nv-transf">Transferencia</label>
              <input id="nv-transf" class="input" type="number" min="0" step="1" v-model.number="pagoTransferencia" />
            </div>
          </div>
          <div class="field">
            <label for="nv-otro">Otro medio de pago</label>
            <input id="nv-otro" class="input" type="number" min="0" step="1" v-model.number="pagoOtro" />
          </div>

          <p v-if="sobrepago" class="aviso-forzado">
            ⚠ El pago ingresado ({{ formatCLP(totalPagado) }}) supera el total de la venta
            ({{ formatCLP(totalACobrar) }}). Corrige el desglose para poder registrarla.
          </p>
          <!-- Tres estados, no dos. Con `pendiente` topado en 0, un sobrepago
               caía en la rama del "Pago completo ✓" y el recuadro contradecía
               al aviso de arriba en la misma pantalla. -->
          <div class="nv-total" :class="{ 'pendiente-alerta': pendiente > 0 || sobrepago }">
            <template v-if="sobrepago">
              <span>Sobra</span><strong>{{ formatCLP(totalPagado - totalACobrar) }}</strong>
            </template>
            <template v-else-if="pendiente > 0">
              <span>Queda pendiente</span><strong>{{ formatCLP(pendiente) }}</strong>
            </template>
            <template v-else>
              <span>Pago completo</span><strong>✓</strong>
            </template>
          </div>

          <p v-if="error" class="error-text">{{ error }}</p>
          <!-- Con sobrepago el botón se bloquea, no solo avisa: el backend
               ahora también lo rechaza, así que dejarlo activo solo llevaría
               a un error del servidor donde el modal ya sabe la respuesta. -->
          <!-- `confirmar(false)` explícito: con `@click="confirmar"` Vue pasa el
               evento del DOM como primer argumento, que es truthy, y toda venta
               cobrada se habría guardado como borrador —sin descontar stock ni
               entrar al cuadre de caja. -->
          <button class="btn btn-primary btn-block" :disabled="busy || sobrepago || (pideClave && !claveDescuento)" @click="confirmar(false)">
            {{ busy ? 'Registrando…' : 'Registrar venta' }}
          </button>
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped>
.razones { margin: 0.7rem 0 0.2rem; padding: 0.6rem 0.85rem; border: 1px dashed var(--line); border-radius: var(--r-md); }
.razones-tit {
  margin: 0 0 0.3rem; font-size: var(--t-micro); font-weight: 700; letter-spacing: 0.06em;
  text-transform: uppercase; color: var(--ink-soft);
}
.razon-row { display: flex; justify-content: space-between; gap: 1rem; font-size: var(--t-base); padding: 0.15rem 0; }
.razon-row strong { font-variant-numeric: tabular-nums; }


.nv-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; padding: 1.4rem; }
.nv-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem; }
.nv-head h2 { font-size: var(--t-xl); }

.migas { display: flex; gap: 0.3rem; flex-wrap: wrap; margin-bottom: 1rem; }
.migas button { font-size: var(--t-micro); font-weight: 600; padding: 0.35em 0.7em; border-radius: var(--r-full); color: var(--ink-soft); background: var(--bg); border: 1px solid var(--line); }
.migas button.current { background: var(--brand); border-color: var(--brand); color: white; }
.migas button.done { color: var(--brand-ink); border-color: var(--brand-tint); background: var(--brand-tint); }
.migas button:disabled { cursor: default; }

.opciones-list { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.8rem; }
.opcion-row { display: flex; align-items: center; gap: 0.8rem; text-align: left; padding: 0.6rem 0.9rem; border: 1.5px solid var(--line); border-radius: var(--r-md); }
.opcion-row:hover { border-color: var(--accent); background: var(--accent-tint); }
.opcion-row strong { flex: none; }
.opcion-go { color: var(--brand-ink); font-weight: 700; margin-left: auto; }
.muted { color: var(--ink-soft); font-size: var(--t-sm); }

.volver-paso { display: block; color: var(--ink-soft); font-weight: 500; font-size: var(--t-sm); margin-bottom: 0.7rem; }
.volver-paso:hover { color: var(--accent-strong); }

.omitir-btn { width: 100%; margin-top: 0.8rem; color: var(--ink-soft); }

.buscador-item { position: relative; margin-bottom: 0.7rem; }
.agregar-fila { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.agregar-fila .input-sm { flex: 1 1 240px; min-width: 200px; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.opciones-flotante {
  position: absolute; top: calc(100% + 0.3rem); left: 0; right: 0; z-index: 20;
  background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-md);
  box-shadow: var(--shadow-lg); padding: 0.5rem; max-height: 260px; overflow-y: auto;
  margin-top: 0;
}

.lineas-cabecera {
  display: grid; grid-template-columns: 1fr 60px 90px 80px 90px 28px;
  gap: 0.5rem; padding: 0 0.2rem; margin-top: 0.4rem;
  font-size: var(--t-micro); color: var(--ink-soft); font-weight: 600;
}
.lineas-venta { list-style: none; display: flex; flex-direction: column; gap: 0.5rem; margin: 0.6rem 0 0; }
.linea-venta {
  display: grid; grid-template-columns: 1fr 60px 90px 80px 90px 28px;
  gap: 0.5rem; align-items: center;
}
.input-sm { padding: 0.4em 0.5em; font-size: var(--t-sm); }
.linea-nombre[readonly] { background: var(--bg); color: var(--ink); }
.linea-num { text-align: right; }
.linea-num.sobre-stock { border-color: var(--warn-ink); background: var(--warn-tint); }
.linea-subtotal { font-size: var(--t-sm); font-weight: 600; text-align: right; }
.linea-quitar { color: var(--danger); font-size: var(--t-base); padding: 0.2rem; }
.linea-quitar:hover { background: var(--danger-tint); border-radius: var(--r-sm); }

.nv-total {
  display: flex; justify-content: space-between; align-items: center;
  padding: 0.8rem 1rem; margin: 1rem 0; border-radius: var(--r-md);
  background: var(--brand-tint); color: var(--brand-ink); font-weight: 600;
}
.nv-total.pendiente-alerta { background: var(--danger-tint); color: var(--danger); }

.resumen { border: 1px solid var(--line); border-radius: var(--r-md); padding: 0.2rem 1rem; background: var(--bg); margin: 0.5rem 0 1rem; }
.res-row { display: flex; justify-content: space-between; gap: 1rem; padding: 0.55rem 0; }
.res-row span { color: var(--ink-soft); font-size: var(--t-sm); }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; margin-bottom: 0.8rem; }
.field label { font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft); }

.btn-block { width: 100%; }
.btn-borrador { margin-top: 0.5rem; }
.aviso-stock { margin-top: 0.7rem; }
.aviso-forzado { background: var(--warn-tint); color: var(--warn-ink); padding: 0.6rem 0.8rem; border-radius: var(--r-md); font-size: var(--t-micro); margin-bottom: 0.8rem; }

@media (max-width: 560px) {
  .lineas-cabecera { display: none; }
  .linea-venta { grid-template-columns: 1fr 28px; grid-template-areas: "nombre quitar" "cant cant" "precio precio" "desc desc" "subtotal subtotal"; row-gap: 0.3rem; padding-bottom: 0.6rem; border-bottom: 1px solid var(--line-tabla); }
  .linea-nombre { grid-area: nombre; }
  .linea-quitar { grid-area: quitar; }
  .linea-num, .linea-subtotal { grid-column: 1 / -1; text-align: left; }
  .grid-2 { grid-template-columns: 1fr; }
}
</style>
