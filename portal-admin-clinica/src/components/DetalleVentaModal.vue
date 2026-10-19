<script setup>
/* Detalle completo de una venta: qué se compró, para quién, y cómo se pagó.
   Es la misma pantalla desde donde se abra —el listado de Ventas, la ficha
   del cliente, la del paciente o la de un producto— para que "abrir la
   venta" signifique siempre lo mismo. */
import { ref, computed, onMounted } from 'vue';
import { api, apiError, formatCLP } from '../api.js';
import { useAuthStore } from '../stores/auth.js';
import AnularVentaModal from './AnularVentaModal.vue';
import DevolucionModal from './DevolucionModal.vue';
import { cargarConfigImpresion, imprimirRecibo } from '../recibo.js';

/* Imprimir el comprobante (o el presupuesto, si es borrador) con la
   configuración de Ajustes → Inventario y ventas → Impresión. */
const errorImpresion = ref('');
async function imprimir() {
  errorImpresion.value = '';
  const cfg = await cargarConfigImpresion();
  if (!imprimirRecibo(venta.value, cfg, { cliente: venta.value?.cliente?.nombre })) {
    errorImpresion.value = 'El navegador bloqueó la ventana de impresión. Permite ventanas emergentes para este sitio.';
  }
}

const props = defineProps({
  ventaId: { type: String, required: true },
  /* Cuando la venta ya viene cargada (el listado la trae entera) se pinta
     de inmediato y la petición solo la refresca: abrir el detalle no puede
     dejar la pantalla en blanco por algo que ya estaba en memoria. */
  precargada: { type: Object, default: null },
});
const emit = defineEmits(['close', 'actualizada']);

const auth = useAuthStore();
/* Anular es de admin (el backend exige `require_admin`); la devolución basta
   con el permiso de ventas — es una operación de mostrador. Esconder lo que
   el backend igual va a rechazar evita prometer un botón que da 403. */
const puedeAnular = computed(() => auth.user?.rol === 'admin');
const puedeDevolver = computed(() => auth.puede('ventas'));

const venta = ref(props.precargada);
const loading = ref(!props.precargada);
const error = ref('');
const mostrarAnular = ref(false);
const mostrarDevolucion = ref(false);
const busyConfirmar = ref(false);

const esBorrador = computed(() => venta.value?.estado_venta === 'borrador');
/* Las dos razones sociales con que factura la clínica. El desglose lo calcula
   el backend al serializar la venta (`desglose_razon`), no este componente:
   así el número que se ve acá es el mismo que ve el cuadre de caja. */
const razones = ref({ productos: 'Productos', servicios: 'Servicios' });
const hayDosRazones = computed(() => {
  const d = props.venta?.desglose_razon;
  return !!d && d.productos > 0 && d.servicios > 0;
});

onMounted(async () => {
  try {
    const { data } = await api.get('/catalogo/ajustes');
    razones.value = {
      productos: data.razon_social_productos?.nombre || 'Productos',
      servicios: data.razon_social_servicios?.nombre || 'Servicios',
    };
  } catch {
    /* Con los rótulos genéricos el desglose se lee igual. */
  }
});

const estaAnulada = computed(() => venta.value?.estado_venta === 'anulada');
const sePuedeDevolver = computed(
  () => venta.value && !esBorrador.value && !estaAnulada.value
    && venta.value.lineas.some((l) => (l.cantidad || 0) - (l.cantidad_devuelta || 0) > 0),
);

function tras(data) {
  venta.value = data;
  mostrarAnular.value = false;
  mostrarDevolucion.value = false;
  emit('actualizada');
}

async function confirmarBorrador() {
  busyConfirmar.value = true;
  error.value = '';
  try {
    const { data } = await api.post(`/transacciones/${props.ventaId}/confirmar`);
    tras(data);
  } catch (e) {
    error.value = apiError(e, 'No se pudo confirmar la venta.');
  } finally {
    busyConfirmar.value = false;
  }
}

async function cargar() {
  error.value = '';
  try {
    const { data } = await api.get(`/transacciones/${props.ventaId}`);
    venta.value = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar la venta.');
  } finally {
    loading.value = false;
  }
}

function fmtFecha(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('es-CL', {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}

const MEDIOS = [
  ['pago_efectivo', 'Efectivo'],
  ['pago_debito', 'Débito'],
  ['pago_credito', 'Crédito'],
  ['pago_transferencia', 'Transferencia'],
  ['pago_otro', 'Otro'],
];

const mediosUsados = computed(
  () => MEDIOS.filter(([k]) => (venta.value?.pagos?.[k] || 0) > 0),
);

/* ── Reclasificar el medio de pago ──
   Vivía en el listado, donde obligaba a desplegar una fila para corregir un
   dato de la venta. Corresponde al detalle: es lo único editable acá. */
const editandoPago = ref(false);
const formPago = ref({});
const busyPago = ref(false);
const errorPago = ref('');

function abrirEditarPago() {
  formPago.value = Object.fromEntries(MEDIOS.map(([k]) => [k, venta.value.pagos?.[k] || 0]));
  errorPago.value = '';
  editandoPago.value = true;
}

async function guardarPago() {
  busyPago.value = true;
  errorPago.value = '';
  try {
    const payload = Object.fromEntries(
      Object.entries(formPago.value).map(([k, v]) => [k, Number(v) || 0]),
    );
    await api.patch(`/transacciones/${props.ventaId}/pago`, payload);
    editandoPago.value = false;
    await cargar();
    emit('actualizada');
  } catch (e) {
    errorPago.value = apiError(e, 'No se pudo cambiar el medio de pago.');
  } finally {
    busyPago.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal dv-modal" role="dialog" aria-modal="true" aria-label="Detalle de la venta">
      <div class="modal-head">
        <h3>
          Venta
          <span v-if="venta?.numero" class="dv-numero">#{{ String(venta.numero).padStart(6, '0') }}</span>
        </h3>
        <button class="modal-close" aria-label="Cerrar" @click="emit('close')">✕</button>
      </div>

      <p v-if="loading" class="empty">Cargando…</p>
      <p v-else-if="error" class="error-text">{{ error }}</p>

      <template v-else-if="venta">
        <!-- Una venta anulada o devuelta se anuncia arriba de todo: el resto
             de la pantalla sigue mostrando montos, y sin este aviso se leen
             como plata vigente. -->
        <div v-if="venta.anulacion" class="dv-aviso dv-aviso-anulada">
          <strong>Venta anulada</strong>
          <span>{{ venta.anulacion.motivo }}</span>
          <small>
            {{ venta.anulacion.por || 'Alguien' }} · {{ fmtFecha(venta.anulacion.at) }}
            · {{ venta.cuenta_en_caja ? 'la plata entró y se devolvió' : 'esa plata nunca entró' }}
          </small>
        </div>
        <div v-else-if="venta.devuelto > 0" class="dv-aviso dv-aviso-devuelta">
          <strong>{{ venta.estado_venta_label }}</strong>
          <span>Se devolvieron {{ formatCLP(venta.devuelto) }} de {{ formatCLP(venta.total) }}.</span>
        </div>
        <div v-else-if="esBorrador" class="dv-aviso dv-aviso-borrador">
          <strong>Borrador</strong>
          <span>Todavía no descuenta stock ni cuenta en caja. Confírmala para registrarla como venta.</span>
        </div>

        <!-- Información -->
        <div class="dv-cabecera">
          <div class="dv-dato"><span>Fecha</span><strong>{{ fmtFecha(venta.fecha) }}</strong></div>
          <div class="dv-dato"><span>Registró</span><strong>{{ venta.creado_por || '—' }}</strong></div>
          <div v-if="venta.documento_id" class="dv-dato">
            <span>Documento</span><strong>{{ venta.documento_id }}</strong>
          </div>
          <div v-if="venta.especialista" class="dv-dato">
            <span>Especialista</span><strong>{{ venta.especialista }}</strong>
          </div>
          <div class="dv-dato">
            <span>Estado</span>
            <span class="estado" :class="`estado-${venta.estado}`">{{ venta.estado_label }}</span>
          </div>
        </div>

        <!-- Cliente y paciente -->
        <div class="dv-partes">
          <section class="dv-parte">
            <h4>Cliente</h4>
            <template v-if="venta.cliente">
              <strong>{{ venta.cliente.nombre }}</strong>
              <p class="muted">
                <span v-if="venta.cliente.rut">RUT {{ venta.cliente.rut }}</span>
                <span v-if="venta.cliente.telefono"> · {{ venta.cliente.telefono }}</span>
                <span v-if="venta.cliente.email"> · {{ venta.cliente.email }}</span>
              </p>
              <RouterLink :to="`/clientes?id=${venta.cliente.id}`" class="dv-link">Ver cliente →</RouterLink>
            </template>
            <!-- Venta de mostrador sin dueño asociado: es un caso legítimo
                 (alguien de paso comprando un producto), no un dato faltante. -->
            <p v-else class="muted">Venta sin cliente asociado.</p>
          </section>

          <section class="dv-parte">
            <h4>{{ venta.pacientes.length > 1 ? 'Pacientes' : 'Paciente' }}</h4>
            <template v-if="venta.pacientes.length">
              <div v-for="p in venta.pacientes" :key="p.id" class="dv-paciente">
                <strong>{{ p.nombre }}</strong>
                <span class="muted" v-if="p.especie"> · {{ p.especie }}</span>
                <RouterLink :to="`/ficha/${p.id}`" class="dv-link">Ver ficha →</RouterLink>
              </div>
            </template>
            <p v-else class="muted">Sin paciente asociado.</p>
          </section>
        </div>

        <!-- Detalle de la compra -->
        <h4 class="dv-titulo">Detalle de la compra</h4>
        <div class="dv-lineas">
          <div class="dv-linea dv-linea-head">
            <span>Producto o servicio</span><span>Cant.</span><span>Precio</span><span>Desc.</span><span>Total</span>
          </div>
          <div v-for="l in venta.lineas" :key="l.id" class="dv-linea">
            <span class="dv-nombre">
              {{ l.nombre }}
              <small v-if="l.mascota">para {{ l.mascota }}</small>
              <small v-else-if="l.tipo" class="muted">{{ l.tipo }}</small>
              <!-- Lo comprado se conserva y lo devuelto se anota al lado: si
                   se restara de la cantidad, la compra iría desapareciendo. -->
              <small v-if="l.cantidad_devuelta" class="dv-devuelta">
                {{ l.cantidad_devuelta }} devuelta{{ l.cantidad_devuelta === 1 ? '' : 's' }}
                el {{ fmtFecha(l.devuelta_at) }}
                <template v-if="l.motivo_devolucion"> · {{ l.motivo_devolucion }}</template>
              </small>
            </span>
            <span>{{ l.cantidad }}</span>
            <span>{{ formatCLP(l.precio_venta) }}</span>
            <span>{{ l.descuento ? '−' + formatCLP(l.descuento) : '—' }}</span>
            <span class="dv-total-linea">{{ formatCLP(l.subtotal) }}</span>
          </div>
          <p v-if="!venta.lineas.length" class="muted small">Esta venta no tiene líneas registradas.</p>
        </div>

        <!-- Resumen -->
        <div class="dv-resumen">
          <div class="dv-res-row"><span>Subtotal</span><span>{{ formatCLP(venta.subtotal) }}</span></div>
          <div v-if="venta.descuento" class="dv-res-row">
            <span>Descuento</span><span>−{{ formatCLP(venta.descuento) }}</span>
          </div>
          <div v-if="venta.devuelto" class="dv-res-row">
            <span>Devuelto</span><span>−{{ formatCLP(venta.devuelto) }}</span>
          </div>
          <div class="dv-res-row dv-res-total">
            <span>{{ venta.devuelto || estaAnulada ? 'Total vigente' : 'Total' }}</span>
            <strong>{{ formatCLP(estaAnulada || esBorrador ? venta.total_efectivo : venta.total - venta.devuelto) }}</strong>
          </div>

          <!-- Cuánto emitió cada razón social. Solo cuando la venta mezcla
               productos y servicios: si es de un solo tipo, el desglose
               repite el total y no informa nada. -->
          <template v-if="hayDosRazones">
            <div class="dv-res-row dv-res-razon">
              <span>{{ razones.productos }}</span>
              <span>{{ formatCLP(venta.desglose_razon.productos) }}</span>
            </div>
            <div class="dv-res-row">
              <span>{{ razones.servicios }}</span>
              <span>{{ formatCLP(venta.desglose_razon.servicios) }}</span>
            </div>
          </template>
        </div>

        <!-- Pago -->
        <h4 class="dv-titulo">Pago</h4>
        <div v-if="!editandoPago" class="dv-pago">
          <div class="dv-medios">
            <span v-for="[k, label] in mediosUsados" :key="k" class="tag">
              {{ label }}: {{ formatCLP(venta.pagos[k]) }}
            </span>
            <span v-if="!mediosUsados.length" class="muted small">Sin pago registrado.</span>
          </div>
          <div class="dv-res-row"><span>Pagado</span><span>{{ formatCLP(venta.pagado) }}</span></div>
          <div class="dv-res-row" :class="{ 'dv-pendiente': venta.pendiente > 0 }">
            <span>Saldo pendiente</span>
            <span>{{ venta.pendiente > 0 ? formatCLP(venta.pendiente) : '—' }}</span>
          </div>
          <button class="btn btn-ghost btn-sm" @click="abrirEditarPago">Cambiar medio de pago</button>
        </div>
        <div v-else class="dv-pago-form">
          <!-- El total no puede cambiar acá: si cambió, no es un error de
               medio de pago sino otra venta. El backend lo rechaza igual. -->
          <p class="muted small">Reclasifica por dónde entró la plata. El total tiene que quedar igual.</p>
          <div class="dv-pago-campos">
            <label v-for="[k, label] in MEDIOS" :key="k">
              {{ label }}
              <input class="input" type="number" min="0" step="1" v-model="formPago[k]" />
            </label>
          </div>
          <p v-if="errorPago" class="error-text">{{ errorPago }}</p>
          <div class="modal-actions">
            <button class="btn btn-ghost btn-sm" @click="editandoPago = false">Cancelar</button>
            <button class="btn btn-primary btn-sm" :disabled="busyPago" @click="guardarPago">
              {{ busyPago ? 'Guardando…' : 'Guardar' }}
            </button>
          </div>
        </div>
        <!-- Acciones. Ninguna borra la venta: confirmar, devolver y anular
             dejan siempre la fila y sus líneas en su sitio. -->
        <div v-if="!loading" class="dv-acciones">
          <button class="btn btn-ghost btn-sm" @click="imprimir">{{ esBorrador ? 'Imprimir presupuesto' : 'Imprimir comprobante' }}</button>
          <button
            v-if="esBorrador" class="btn btn-primary btn-sm"
            :disabled="busyConfirmar" @click="confirmarBorrador"
          >{{ busyConfirmar ? 'Confirmando…' : 'Confirmar venta' }}</button>
          <button
            v-if="sePuedeDevolver && puedeDevolver" class="btn btn-ghost btn-sm"
            @click="mostrarDevolucion = true"
          >Gestionar devolución</button>
          <button
            v-if="!esBorrador && !estaAnulada && puedeAnular"
            class="btn btn-ghost btn-sm btn-peligro" @click="mostrarAnular = true"
          >Anular venta</button>
        </div>
        <p v-if="errorImpresion" class="error-text">{{ errorImpresion }}</p>
      </template>

      <AnularVentaModal
        v-if="mostrarAnular" :venta="venta"
        @close="mostrarAnular = false" @anulada="tras"
      />
      <DevolucionModal
        v-if="mostrarDevolucion" :venta="venta"
        @close="mostrarDevolucion = false" @devuelta="tras"
      />
    </div>
  </div>
</template>

<style scoped>
/* Un filete arriba separa el desglose por empresa del total, que es de otra
   naturaleza: uno es cuánto se cobró y el otro quién lo emite. */
.dv-res-razon { border-top: 1px dashed var(--line); margin-top: 0.35rem; padding-top: 0.45rem; }


.dv-modal { max-width: 680px; }

.dv-aviso {
  display: flex; flex-direction: column; gap: 0.15rem;
  padding: 0.7rem 0.9rem; margin-bottom: 1rem; border-radius: var(--r-md);
  font-size: var(--t-sm);
}
.dv-aviso strong { font-size: var(--t-base); }
.dv-aviso small { font-size: var(--t-micro); opacity: 0.85; }
.dv-aviso-anulada { background: var(--danger-tint); color: var(--danger); }
.dv-aviso-devuelta { background: var(--warn-tint); color: var(--warn-ink); }
.dv-aviso-borrador { background: var(--brand-tint); color: var(--brand-ink); }

.dv-devuelta { color: var(--warn-ink); }

.dv-acciones {
  display: flex; gap: 0.5rem; flex-wrap: wrap;
  margin-top: 1.3rem; padding-top: 1rem; border-top: 1px solid var(--line);
}
.btn-peligro { color: var(--danger); }
.btn-peligro:hover:not(:disabled) { background: var(--danger-tint); border-color: var(--danger); color: var(--danger); }
.dv-numero { color: var(--accent-strong); font-variant-numeric: tabular-nums; }

.dv-cabecera {
  display: flex; flex-wrap: wrap; gap: 0.4rem 1.6rem;
  padding-bottom: 1rem; border-bottom: 1px solid var(--line);
}
.dv-dato { display: flex; flex-direction: column; gap: 0.1rem; }
.dv-dato > span:first-child { font-size: var(--t-micro); color: var(--ink-soft); font-weight: 600; }
.dv-dato strong { font-size: var(--t-base); }

.dv-partes { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding: 1rem 0; border-bottom: 1px solid var(--line); }
.dv-parte h4 { font-size: var(--t-micro); color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.3rem; }
.dv-paciente { margin-bottom: 0.4rem; }
.dv-link { display: block; margin-top: 0.2rem; font-size: var(--t-micro); font-weight: 600; color: var(--accent-strong); }
.dv-link:hover { text-decoration: underline; }

.dv-titulo { font-size: var(--t-micro); color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.04em; margin: 1.1rem 0 0.5rem; }

.dv-lineas { display: flex; flex-direction: column; }
.dv-linea {
  display: grid; grid-template-columns: 1fr 55px 100px 90px 100px;
  gap: 0.6rem; align-items: baseline;
  padding: 0.5rem 0; border-bottom: 1px solid var(--line-tabla);
  font-size: var(--t-sm);
}
.dv-linea > span:not(:first-child) { text-align: right; font-variant-numeric: tabular-nums; }
.dv-linea-head { font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.03em; }
.dv-nombre { display: flex; flex-direction: column; }
.dv-nombre small { font-size: var(--t-micro); color: var(--ink-soft); }
.dv-total-linea { font-weight: 600; }

.dv-resumen { margin-top: 0.8rem; padding: 0.7rem 0.9rem; background: var(--bg); border-radius: var(--r-md); }
.dv-res-row { display: flex; justify-content: space-between; gap: 1rem; font-size: var(--t-sm); padding: 0.2rem 0; }
.dv-res-row > span:first-child { color: var(--ink-soft); }
.dv-res-row > span:last-child, .dv-res-row strong { font-variant-numeric: tabular-nums; }
.dv-res-total { border-top: 1px solid var(--line); margin-top: 0.35rem; padding-top: 0.45rem; font-size: var(--t-lg); }
.dv-pendiente > span { color: var(--danger); font-weight: 600; }

.dv-pago { display: flex; flex-direction: column; gap: 0.3rem; align-items: flex-start; }
.dv-medios { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-bottom: 0.4rem; }
.dv-pago .btn { margin-top: 0.5rem; }
.dv-pago-campos { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0.6rem; margin: 0.6rem 0; }
.dv-pago-campos label { display: flex; flex-direction: column; gap: 0.2rem; font-size: var(--t-micro); color: var(--ink-soft); font-weight: 600; }

.estado { display: inline-flex; padding: 0.2em 0.7em; border-radius: var(--r-full); font-size: var(--t-micro); font-weight: 600; }
.estado-pagada { background: var(--ok-tint); color: var(--ok); }
.estado-parcial { background: var(--warn-tint); color: var(--warn-ink); }
.estado-pendiente { background: var(--danger-tint); color: var(--danger); }
.estado-anulada { background: var(--danger-tint); color: var(--danger); }
.estado-borrador { background: var(--brand-tint); color: var(--brand-ink); }
.estado-devuelta, .estado-devuelta_parcial { background: var(--warn-tint); color: var(--warn-ink); }

.muted { color: var(--ink-soft); }
.small { font-size: var(--t-sm); }

@media (max-width: 620px) {
  .dv-partes { grid-template-columns: 1fr; }
  .dv-linea { grid-template-columns: 1fr 1fr; gap: 0.2rem 0.6rem; }
  .dv-linea-head { display: none; }
  .dv-nombre { grid-column: 1 / -1; }
}
</style>
