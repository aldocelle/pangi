<script setup>
/* Ajustes → Inventario y ventas: la pantalla `tab_inventario_ventas` de ACVet
   (capturas 5–7 del dossier). Inventario, control de ventas, puntos e
   impresión, de arriba hacia abajo, en el mismo orden que allá.

   Todo nace APAGADO y así reproduce lo que el sistema hacía antes: cada regla
   frena el mostrador, y encenderla es decisión de la clínica. Guardar es de
   admin (el backend lo exige); el resto del equipo lo ve en solo lectura. */
import { ref, computed, onMounted } from 'vue';
import { api, apiError, PUNTOS_VISIBLE } from '../api.js';
import { useAuthStore } from '../stores/auth.js';
import { TIPOS_ITEM } from '../tiposItem.js';

const auth = useAuthStore();
const esAdmin = computed(() => auth.user?.rol === 'admin');

const BODEGAS = ['Principal', 'Hospital', 'Farmacia'];

const ventas = ref(null);
const impresion = ref(null);
const loading = ref(true);
const error = ref('');
const aviso = ref('');
const busy = ref(false);

const nuevaClave = ref('');
const nuevoTipo = ref({ tipo: '', otro: '', margen: '' });

async function cargar() {
  loading.value = true;
  try {
    const [v, i] = await Promise.all([api.get('/configuracion/ventas'), api.get('/configuracion/impresion')]);
    ventas.value = v.data;
    impresion.value = i.data;
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar la configuración.');
  } finally {
    loading.value = false;
  }
}

/* Cada bloque guarda SOLO sus campos: un PUT parcial no pisa lo que otra
   persona haya cambiado en otro bloque mientras esta pantalla estaba abierta. */
async function guardar(seccion, campos) {
  busy.value = true;
  error.value = '';
  aviso.value = '';
  try {
    const { data } = await api.put(`/configuracion/${seccion}`, campos);
    if (seccion === 'ventas') ventas.value = data; else impresion.value = data;
    aviso.value = 'Guardado.';
    setTimeout(() => { aviso.value = ''; }, 2500);
  } catch (e) {
    error.value = apiError(e, 'No se pudo guardar.');
    await cargar();   // vuelve a lo que de verdad quedó guardado
  } finally {
    busy.value = false;
  }
}

function alternar(campo) {
  guardar('ventas', { [campo]: ventas.value[campo] });
}

function alternarBodega(b) {
  const actual = new Set(ventas.value.bodegas);
  if (actual.has(b)) actual.delete(b); else actual.add(b);
  if (!actual.size) { error.value = 'Tiene que quedar al menos una bodega.'; cargar(); return; }
  guardar('ventas', { bodegas: BODEGAS.filter((x) => actual.has(x)) });
}

function guardarMargen() {
  const m = ventas.value.margen_minimo;
  guardar('ventas', { margen_minimo: m === '' || m === null ? null : Number(m) });
}

function agregarTipo() {
  const tipo = (nuevoTipo.value.tipo === '__otro' ? nuevoTipo.value.otro : nuevoTipo.value.tipo).trim();
  if (!tipo || nuevoTipo.value.margen === '') return;
  const lista = ventas.value.margenes_por_tipo.filter((m) => m.tipo.toLowerCase() !== tipo.toLowerCase());
  lista.push({ tipo, margen: Number(nuevoTipo.value.margen) });
  nuevoTipo.value = { tipo: '', otro: '', margen: '' };
  guardar('ventas', { margenes_por_tipo: lista });
}
function quitarTipo(t) {
  guardar('ventas', { margenes_por_tipo: ventas.value.margenes_por_tipo.filter((m) => m.tipo !== t.tipo) });
}

async function guardarClave() {
  if (nuevaClave.value.length < 4) { error.value = 'La clave necesita al menos 4 caracteres.'; return; }
  await guardar('ventas', { clave_descuento: nuevaClave.value });
  nuevaClave.value = '';
}

function guardarPuntos() {
  guardar('ventas', { pesos_por_punto: Number(ventas.value.pesos_por_punto), valor_punto: Number(ventas.value.valor_punto) });
}

function guardarImpresion(campos) {
  guardar('impresion', campos);
}

const REGLAS = [
  { campo: 'clave_descuentos', grupo: 'Sistema de descuentos', label: 'Solicitar clave para aplicar descuentos' },
  { campo: 'no_vender_sin_stock', grupo: 'Control de stock', label: 'No permitir vender ítems sin stock' },
  { campo: 'exigir_caja_abierta', grupo: 'Caja del día', label: 'Exigir caja abierta del día para vender' },
  { campo: 'exigir_paciente', grupo: 'Control de registro', label: 'No permitir vender sin registrar paciente' },
];

onMounted(cargar);
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Inventario y ventas</h1>
        <p class="page-sub">Opciones de inventario, control de ventas{{ PUNTOS_VISIBLE ? ", sistema de puntos" : "" }} e impresión.</p>
      </div>
      <span v-if="aviso" class="ok-text">{{ aviso }}</span>
    </div>

    <p v-if="!esAdmin" class="solo-lectura">Solo un administrador puede cambiar estas reglas. Las ves en modo lectura.</p>
    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>

    <template v-else-if="ventas && impresion">
      <!-- ── Inventario ── -->
      <section class="card bloque">
        <h2 class="bloque-tit">Configuración de inventario</h2>

        <div class="opcion">
          <p class="op-tit">Bodegas habilitadas</p>
          <div class="checks">
            <label v-for="b in BODEGAS" :key="b" class="check">
              <input type="checkbox" :checked="ventas.bodegas.includes(b)" :disabled="!esAdmin || busy" @change="alternarBodega(b)" />
              Bodega {{ b }}
            </label>
          </div>
          <small class="ayuda">Cada ítem del inventario se asigna a una de estas bodegas.</small>
        </div>

        <label class="check opcion">
          <input type="checkbox" v-model="ventas.reiniciar_stock_solo_admin" :disabled="!esAdmin || busy"
            @change="alternar('reiniciar_stock_solo_admin')" />
          Permitir corregir el stock a mano solo a administradores
        </label>

        <div class="opcion">
          <label class="op-tit" for="v-margen">Margen de venta mínimo</label>
          <div class="fila-input">
            <input id="v-margen" class="input corto" type="number" min="0" max="99" step="0.1"
              v-model="ventas.margen_minimo" :disabled="!esAdmin" placeholder="Sin alerta" />
            <span>%</span>
            <button v-if="esAdmin" class="btn btn-ghost btn-sm" :disabled="busy" @click="guardarMargen">Guardar</button>
          </div>
          <small class="ayuda">El inventario marca los productos cuyo margen —(venta − costo) / venta— quede bajo este porcentaje.</small>
        </div>

        <div class="opcion">
          <p class="op-tit">Márgenes por tipo de ítem</p>
          <table class="tabla-datos">
            <thead><tr><th>Tipo</th><th>Margen</th><th></th></tr></thead>
            <tbody>
              <tr v-if="!ventas.margenes_por_tipo.length"><td colspan="3" class="muted">Sin márgenes por tipo: todos usan el mínimo general.</td></tr>
              <tr v-for="m in ventas.margenes_por_tipo" :key="m.tipo">
                <td>{{ m.tipo }}</td>
                <td>{{ m.margen }} %</td>
                <td class="der"><button v-if="esAdmin" class="btn btn-danger btn-sm" :disabled="busy" @click="quitarTipo(m)" :aria-label="`Quitar ${m.tipo}`">×</button></td>
              </tr>
            </tbody>
          </table>
          <div v-if="esAdmin" class="fila-input agregar">
            <select class="select" v-model="nuevoTipo.tipo" aria-label="Tipo de ítem">
              <option value="">Selecciona tipo…</option>
              <option v-for="t in TIPOS_ITEM" :key="t" :value="t">{{ t }}</option>
              <option value="__otro">Otro (texto libre)…</option>
            </select>
            <input v-if="nuevoTipo.tipo === '__otro'" class="input" v-model="nuevoTipo.otro" maxlength="50" placeholder="Nombre del tipo" />
            <input class="input corto" type="number" min="0" max="99" v-model="nuevoTipo.margen" placeholder="%" aria-label="Margen" />
            <button class="btn btn-ghost btn-sm" :disabled="busy" @click="agregarTipo">+ Agregar tipo</button>
          </div>
        </div>
      </section>

      <!-- ── Control de ventas ── -->
      <section class="card bloque">
        <h2 class="bloque-tit">Control de ventas</h2>
        <div v-for="r in REGLAS" :key="r.campo" class="opcion">
          <p class="op-tit">{{ r.grupo }}</p>
          <label class="check">
            <input type="checkbox" v-model="ventas[r.campo]" :disabled="!esAdmin || busy || (r.campo === 'clave_descuentos' && !ventas.tiene_clave_descuento)"
              @change="alternar(r.campo)" />
            {{ r.label }}
          </label>
          <div v-if="r.campo === 'clave_descuentos' && esAdmin" class="fila-input">
            <input class="input" type="password" autocomplete="new-password" v-model="nuevaClave"
              :placeholder="ventas.tiene_clave_descuento ? 'Cambiar la clave' : 'Definir la clave (mín. 4)'" />
            <button class="btn btn-ghost btn-sm" :disabled="busy || !nuevaClave" @click="guardarClave">Guardar clave</button>
          </div>
          <small v-if="r.campo === 'clave_descuentos' && !ventas.tiene_clave_descuento" class="ayuda">
            Define la clave primero; después se puede exigir.
          </small>
        </div>
        <div class="opcion">
          <p class="op-tit">Método de pago</p>
          <p class="ayuda">El método de pago nunca se preselecciona: quien cobra escribe dónde entró la plata.
            Prellenarlo causaba pagos registrados dos veces y faltantes falsos en Caja.</p>
        </div>
      </section>

      <!-- ── Puntos ── (escondido: ver PUNTOS_VISIBLE en api.js) -->
      <section v-if="PUNTOS_VISIBLE" class="card bloque">
        <h2 class="bloque-tit">Sistema de puntos</h2>
        <label class="check opcion">
          <input type="checkbox" v-model="ventas.puntos_activo" :disabled="!esAdmin || busy" @change="alternar('puntos_activo')" />
          Sistema de puntos {{ ventas.puntos_activo ? 'ACTIVO' : 'INACTIVO' }}
        </label>
        <p class="ayuda">Los clientes acumulan puntos con cada compra y los canjean como rebaja en compras futuras.</p>
        <div class="fila-input opcion">
          <label for="v-ppp">1 punto por cada $</label>
          <input id="v-ppp" class="input corto" type="number" min="1" v-model="ventas.pesos_por_punto" :disabled="!esAdmin" />
          <label for="v-vp">pagados · cada punto vale $</label>
          <input id="v-vp" class="input corto" type="number" min="0" v-model="ventas.valor_punto" :disabled="!esAdmin" />
          <button v-if="esAdmin" class="btn btn-ghost btn-sm" :disabled="busy" @click="guardarPuntos">Guardar</button>
        </div>
      </section>

      <!-- ── Impresión ── -->
      <section class="card bloque">
        <h2 class="bloque-tit">Configuración de impresión</h2>
        <div class="opcion">
          <p class="op-tit">Tipo de impresora</p>
          <div class="checks">
            <label class="check"><input type="radio" value="escritorio" v-model="impresion.tipo_impresora" :disabled="!esAdmin"
              @change="guardarImpresion({ tipo_impresora: 'escritorio' })" /> Impresora de escritorio</label>
            <label class="check"><input type="radio" value="termica" v-model="impresion.tipo_impresora" :disabled="!esAdmin"
              @change="guardarImpresion({ tipo_impresora: 'termica' })" /> Impresora térmica (recibos)</label>
          </div>
        </div>
        <div class="opcion">
          <label class="op-tit" for="i-ret">Retención informativa en presupuestos (%)</label>
          <div class="fila-input">
            <input id="i-ret" class="input corto" type="number" min="0" max="50" step="0.25" v-model="impresion.retencion_pct" :disabled="!esAdmin" />
            <button v-if="esAdmin" class="btn btn-ghost btn-sm" :disabled="busy"
              @click="guardarImpresion({ retencion_pct: impresion.retencion_pct === '' ? null : Number(impresion.retencion_pct) })">Guardar porcentaje</button>
            <button v-if="esAdmin" class="btn btn-ghost btn-sm" :disabled="busy" @click="guardarImpresion({ retencion_pct: null })">Desactivar retención</button>
          </div>
          <small class="ayuda">Se muestra como referencia al imprimir un presupuesto (una venta en borrador). No cambia el total.</small>
        </div>
        <div class="opcion">
          <p class="op-tit">Opciones</p>
          <label class="check"><input type="checkbox" v-model="impresion.auto_imprimir" :disabled="!esAdmin"
            @change="guardarImpresion({ auto_imprimir: impresion.auto_imprimir })" /> Imprimir automáticamente al confirmar venta</label>
          <label class="check"><input type="checkbox" v-model="impresion.desglose_iva" :disabled="!esAdmin"
            @change="guardarImpresion({ desglose_iva: impresion.desglose_iva })" /> Mostrar desglose de IVA en recibo</label>
        </div>
        <div class="opcion">
          <p class="op-tit">Márgenes de formularios (mm)</p>
          <div class="margenes">
            <label v-for="lado in ['superior', 'derecho', 'inferior', 'izquierdo']" :key="lado" class="field">
              <span class="cap">{{ lado }}</span>
              <input class="input" type="number" min="0" max="50" step="0.01" v-model.number="impresion.margenes_mm[lado]"
                :disabled="!esAdmin" @change="guardarImpresion({ margenes_mm: impresion.margenes_mm })" />
            </label>
          </div>
          <small class="ayuda">Se guardan al cambiarlos y se aplican al recibo de escritorio.</small>
        </div>
        <div class="opcion">
          <label class="op-tit" for="i-msg">Mensaje en recibo</label>
          <textarea id="i-msg" class="textarea" maxlength="300" v-model="impresion.mensaje_recibo" :disabled="!esAdmin"
            placeholder="Ej: ¡Gracias por su preferencia! Horario de atención: Lun-Vie 9:00-18:00"></textarea>
          <div v-if="esAdmin" class="der">
            <button class="btn btn-ghost btn-sm" :disabled="busy" @click="guardarImpresion({ mensaje_recibo: impresion.mensaje_recibo || '' })">Guardar mensaje</button>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.bloque { padding: 1rem 1.2rem; margin-bottom: 1rem; display: flex; flex-direction: column; gap: 0.9rem; }
.bloque-tit { font-size: var(--t-lg); }
.opcion { display: flex; flex-direction: column; gap: 0.35rem; }
.op-tit { font-weight: 600; font-size: var(--t-sm); }
.checks { display: flex; flex-wrap: wrap; gap: 0.4rem 1.2rem; }
.check { display: inline-flex; align-items: center; gap: 0.45rem; font-size: var(--t-base); cursor: pointer; }
.fila-input { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
.fila-input.opcion { flex-direction: row; }
.agregar { margin-top: 0.5rem; }
.corto { max-width: 7.5rem; }
.ayuda { color: var(--ink-soft); font-size: var(--t-sm); }
.muted { color: var(--ink-soft); }
.der { text-align: right; }
.ok-text { color: var(--ok); font-size: var(--t-sm); }
.solo-lectura { background: var(--info-tint); color: var(--ink); border-radius: var(--r-sm); padding: 0.55rem 0.8rem; font-size: var(--t-sm); margin-bottom: 1rem; }
.margenes { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.6rem; max-width: 34rem; }
.cap { font-size: var(--t-sm); text-transform: capitalize; color: var(--ink-soft); }
@media (max-width: 600px) { .margenes { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
