<script setup>
import { ref, computed, onMounted } from 'vue';
import { api, apiError, API_URL, formatCLP } from '../api.js';
import { useAuthStore } from '../stores/auth.js';
import HistorialVentasModal from '../components/HistorialVentasModal.vue';

const auth = useAuthStore();

/* ── La foto del tipo de consulta ──────────────────────────────────────
   Sube al servidor y se guarda en `servicios.imagen`; no reemplaza al ícono,
   lo tapa mientras exista. Quitarla devuelve la tarjeta al ícono, así que no
   hay forma de dejar el paso 1 del portal sin nada que mostrar. */
const subiendoImagen = ref(false);
const errorImagen = ref('');

function urlImagen(s) {
  return s?.imagen ? `${API_URL}${s.imagen}` : null;
}

async function elegirImagen(ev) {
  const archivo = ev.target.files?.[0];
  ev.target.value = '';                       // permite reintentar el mismo archivo
  if (!archivo || !editando.value?.id) return;
  if (archivo.size > 4 * 1024 * 1024) {
    errorImagen.value = 'La imagen no puede superar 4 MB.';

    return;
  }
  errorImagen.value = '';
  subiendoImagen.value = true;
  try {
    const cuerpo = new FormData();
    cuerpo.append('archivo', archivo);
    const { data } = await api.post(`/catalogo/servicios/${editando.value.id}/imagen`, cuerpo);
    editando.value.imagen = data.imagen;
    const enLista = servicios.value.find((x) => x.id === editando.value.id);
    if (enLista) enLista.imagen = data.imagen;   // la lista de atrás, al día
  } catch (e) {
    errorImagen.value = apiError(e, 'No se pudo subir la imagen.');
  } finally {
    subiendoImagen.value = false;
  }
}

async function quitarImagen() {
  if (!editando.value?.id) return;
  errorImagen.value = '';
  try {
    await api.delete(`/catalogo/servicios/${editando.value.id}/imagen`);
    editando.value.imagen = null;
    const enLista = servicios.value.find((x) => x.id === editando.value.id);
    if (enLista) enLista.imagen = null;
  } catch (e) {
    errorImagen.value = apiError(e, 'No se pudo quitar la imagen.');
  }
}
/* /transacciones exige el permiso "ventas": configurar las consultas y
   ver cuánto se cobró por ellas son atribuciones distintas. */
const puedeVerVentas = computed(() => auth.puede('ventas'));
/* Servicio cuyo historial de ventas se está mirando (§10). Sale de las
   mismas ventas del módulo Ventas, no de un contador aparte. */
const historialDe = ref(null);

const servicios = ref([]);
const especialistas = ref([]);
const loading = ref(true);
const error = ref('');
const editando = ref(null); // objeto en edición (o {} para crear)
const busy = ref(false);

const EMOJIS = ['🐾', '🛁', '🏠', '💻', '🩺', '📋', '✅', '✂', '🐩', '💉', '🦷', '🔬'];

function nuevo() {
  editando.value = {
    nombre: '', descripcion: '', emoji: '🐾',
    duracion_min: 30, precio_clp: null, precio_sabado_clp: null, precio_detalle: '',
    modalidad_pago: 'abono', tarifa_por_especialista: false, activo: true,
    abono_fijo_clp: null, requiere_orden_examen: false, coordinar_whatsapp: false,
    orden: servicios.value.length, especialista_ids: [],
  };
}

function editar(s) {
  editando.value = JSON.parse(JSON.stringify(s));
}

async function guardar() {
  busy.value = true;
  error.value = '';
  try {
    const payload = { ...editando.value };
    // Vacío tiene que viajar como null y no como '': el backend espera un
    // entero o nada, y '' lo rechazaría con un 422 al guardar.
    for (const k of ['precio_clp', 'precio_sabado_clp', 'abono_fijo_clp']) {
      if (payload[k] === '' || payload[k] === null || payload[k] === undefined) payload[k] = null;
      else payload[k] = Number(payload[k]);
    }
    payload.duracion_min = Number(payload.duracion_min);
    if (payload.id) {
      await api.put(`/catalogo/servicios/${payload.id}`, payload);
    } else {
      await api.post('/catalogo/servicios', payload);
    }
    editando.value = null;
    await cargar();
  } catch (e) {
    error.value = apiError(e, 'No se pudo guardar el servicio.');
  } finally {
    busy.value = false;
  }
}

async function toggleActivo(s) {
  await api.put(`/catalogo/servicios/${s.id}`, { ...s, activo: !s.activo });
  await cargar();
}

async function eliminar(s) {
  if (!confirm(`¿Eliminar "${s.nombre}"? No se puede deshacer. Las citas ya reservadas con esta consulta no se ven afectadas.`)) return;
  try {
    await api.delete(`/catalogo/servicios/${s.id}`);
    await cargar();
  } catch (e) {
    alert(apiError(e, 'No se pudo eliminar la consulta.'));
  }
}

async function cargar() {
  loading.value = true;
  try {
    const [s, e] = await Promise.all([
      api.get('/catalogo/servicios/todos'),
      api.get('/catalogo/especialistas/todos'),
    ]);
    servicios.value = s.data;
    // Solo personal activo: la importación del legado dejó 54 especialistas
    // inactivos, uno por código de médico del sistema anterior ("Médico #6362
    // (ACVet)"), y no tiene sentido asignarle una consulta a ninguno. No se
    // pueden borrar — cuelgan de miles de notas y ventas históricas.
    especialistas.value = e.data.filter((x) => x.activo);
  } finally {
    loading.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Consultas</h1>
        <p class="page-sub">Los tipos de atención que la clínica ofrece al reservar.</p>
      </div>
      <button class="btn btn-primary" @click="nuevo">+ Nueva consulta</button>
    </div>

    <p v-if="loading" class="empty">Cargando…</p>
    <div v-else class="lista">
      <article v-for="s in servicios" :key="s.id" class="card svc" :class="{ off: !s.activo }">
        <img v-if="urlImagen(s)" class="svc-img" :src="urlImagen(s)" alt="" />
          <span v-else class="svc-emoji" aria-hidden="true">{{ s.emoji }}</span>
        <div class="svc-main">
          <h2>{{ s.nombre }}</h2>
          <div class="svc-tags">
            <span class="tag">{{ s.duracion_min >= 60 ? (s.duracion_min / 60) + ' hora' + (s.duracion_min > 60 ? 's' : '') : s.duracion_min + ' minutos' }}</span>
            <span v-if="s.precio_clp" class="tag">{{ formatCLP(s.precio_clp) }}</span>
            <span v-if="!s.activo" class="tag hid">Inactivo</span>
          </div>
          <p v-if="s.descripcion" class="svc-desc">{{ s.descripcion }}</p>
        </div>
        <div class="svc-actions">
          <button v-if="puedeVerVentas" class="btn btn-ghost btn-sm" @click="historialDe = s">Ventas</button>
          <button class="btn btn-ghost btn-sm" @click="editar(s)">Editar</button>
          <button class="btn btn-ghost btn-sm" @click="toggleActivo(s)">{{ s.activo ? 'Desactivar' : 'Activar' }}</button>
          <button class="btn btn-ghost btn-sm peligro" @click="eliminar(s)">Eliminar</button>
        </div>
      </article>
    </div>

    <!-- Historial de ventas del servicio. Busca por id Y por nombre: las
         ventas importadas de ACVet no tienen `servicio_id`, y dejarlas fuera
         haría ver un servicio con años de uso como recién estrenado. -->
    <HistorialVentasModal
      v-if="historialDe"
      :titulo="historialDe.nombre"
      subtitulo="Historial de ventas de esta consulta"
      :params="{ servicio_id: historialDe.id, servicio_nombre: historialDe.nombre }"
      vacio="Esta consulta todavía no se ha cobrado en una venta."
      @close="historialDe = null"
    />

    <!-- Modal crear/editar -->
    <div v-if="editando" class="modal-backdrop" @click.self="editando = null">
      <div class="modal" role="dialog" aria-modal="true" aria-label="Editar consulta">
        <div class="modal-head">
          <h3>{{ editando.id ? 'Editar consulta' : 'Nueva consulta' }}</h3>
          <button class="modal-close" aria-label="Cerrar" @click="editando = null">✕</button>
        </div>

        <form @submit.prevent="guardar" class="form">
          <div class="field">
            <label for="c-nombre">Nombre *</label>
            <input id="c-nombre" class="input" v-model="editando.nombre" required />
          </div>
          <div class="field">
            <label for="c-desc">Descripción</label>
            <textarea id="c-desc" class="textarea" v-model="editando.descripcion"></textarea>
          </div>
          <div class="row-3">
            <div class="field">
              <label for="c-dur">Duración (min)</label>
              <select id="c-dur" class="select" v-model="editando.duracion_min">
                <option :value="15">15</option>
                <option :value="30">30</option>
                <option :value="45">45</option>
                <option :value="60">60</option>
                <option :value="90">90</option>
              </select>
            </div>
            <div class="field">
              <label for="c-precio">Precio lunes a viernes CLP (opcional)</label>
              <input id="c-precio" class="input" type="number" min="0" step="500" v-model="editando.precio_clp" placeholder="—" />
              <small class="campo-nota">
                Es el valor que el cliente ve al reservar, y del que sale el abono del 50%.
              </small>
            </div>
            <div class="field">
              <label for="c-precio-sab">Precio sábado CLP (opcional)</label>
              <input id="c-precio-sab" class="input" type="number" min="0" step="500" v-model="editando.precio_sabado_clp" placeholder="—" />
              <small class="campo-nota">
                Solo si el sábado vale distinto. Vacío = se cobra el mismo de lunes a viernes.
              </small>
            </div>
            <div class="field">
              <label for="c-modalidad">Cobro en línea</label>
              <select id="c-modalidad" class="select" v-model="editando.modalidad_pago">
                <option value="abono">Abono del 50% (el cliente puede pagar todo)</option>
                <option value="total">Pago completo por adelantado</option>
                <option value="nada">No se cobra en línea</option>
              </select>
              <small class="campo-nota">
                &laquo;No se cobra en línea&raquo; deja reservar sin pagar y el valor se
                cobra en la clínica; el precio de arriba se sigue mostrando.
              </small>
            </div>
            <div class="field">
              <label for="c-abono-fijo">Abono de monto fijo CLP (opcional)</label>
              <input id="c-abono-fijo" class="input" type="number" min="0" step="500" v-model="editando.abono_fijo_clp" placeholder="—" />
              <small class="campo-nota">
                Para cuando el valor final no se sabe al reservar: radiografías
                (depende de cuántas salgan) o peluquería (del peso y el pelaje).
                Con esto puesto se cobra esta cifra y no el 50%, el precio de
                arriba pasa a ser un &laquo;desde&raquo;, y el cliente ya no puede
                elegir &laquo;pagar el total&raquo; &mdash;nadie sabe cuál es todavía.
              </small>
            </div>
            <div class="field">
              <label for="c-orden-examen">Orden de examen</label>
              <label class="check">
                <input id="c-orden-examen" type="checkbox" v-model="editando.requiere_orden_examen" />
                <span>Pedir que adjunte la orden al reservar</span>
              </label>
              <small class="campo-nota">
                Para las prestaciones que no se realizan sin la indicación del
                veterinario tratante: exámenes de sangre, ecografía y radiología.
              </small>
            </div>
            <div class="field">
              <label for="c-coordinar-wa">Toma de horas</label>
              <label class="check">
                <input id="c-coordinar-wa" type="checkbox" v-model="editando.coordinar_whatsapp" />
                <span>Confirmar disponibilidad por WhatsApp (no se agenda en línea)</span>
              </label>
              <small class="campo-nota">
                En la página de reservas sale con el botón de WhatsApp en vez del
                calendario, y el asistente no la agenda solo. Desde el panel se
                sigue pudiendo agendar a mano.
              </small>
            </div>
            <div class="field">
              <label for="c-tarifa-esp">Valor</label>
              <label class="check">
                <input id="c-tarifa-esp" type="checkbox" v-model="editando.tarifa_por_especialista" />
                <span>Lo pone cada especialista</span>
              </label>
              <small class="campo-nota">
                Solo para las interconsultas, donde cada especialidad cobra lo suyo.
                Sin esto, una consulta sin precio no muestra ni cobra ningún valor
                &mdash;que es lo correcto en las de &laquo;valor según evaluación&raquo;.
              </small>
            </div>
            <div class="field campo-ancho">
              <label for="c-precio-det">Desglose de precios (opcional)</label>
              <input
                id="c-precio-det" class="input" maxlength="300"
                v-model="editando.precio_detalle"
                placeholder="Lunes a viernes: $15.000 · Sábado: $20.000"
              />
              <small class="campo-nota">
                Se muestra en el sitio cuando la tarifa depende del día. Reemplaza al
                &laquo;Desde $X&raquo; que se arma con el precio base.
              </small>
            </div>
            <div class="field">
              <label for="c-emoji">Ícono</label>
              <select id="c-emoji" class="select" v-model="editando.emoji">
                <option v-for="e in EMOJIS" :key="e" :value="e">{{ e }}</option>
              </select>
              <small class="hint">Se usa cuando no hay foto.</small>
            </div>
          </div>

          <!-- Va DESPUÉS de guardar por primera vez: la foto se sube contra el
               id del servicio, y un servicio nuevo todavía no lo tiene. -->
          <div class="field" v-if="editando.id">
            <label>Foto</label>
            <div class="img-row">
              <img v-if="urlImagen(editando)" class="img-prev" :src="urlImagen(editando)" alt="" />
              <span v-else class="img-prev img-prev--vacia" aria-hidden="true">{{ editando.emoji }}</span>
              <div class="img-acciones">
                <label class="btn btn-ghost btn-sm" :class="{ ocupado: subiendoImagen }">
                  {{ subiendoImagen ? 'Subiendo…' : (urlImagen(editando) ? 'Cambiar foto' : 'Subir foto') }}
                  <input type="file" accept=".jpg,.jpeg,.png,.webp" hidden @change="elegirImagen" />
                </label>
                <button v-if="urlImagen(editando)" type="button" class="btn btn-ghost btn-sm"
                        @click="quitarImagen">Quitar</button>
              </div>
            </div>
            <small class="hint">
              JPG, PNG o WebP, hasta 4 MB. Se ve como un cuadrado de 46 px en el
              portal, así que conviene que el sujeto quede centrado. Sin foto, la
              tarjeta muestra el ícono.
            </small>
            <small v-if="errorImagen" class="error-text">{{ errorImagen }}</small>
          </div>

          <fieldset class="field esp-field">
            <legend>Especialistas que atienden este servicio</legend>
            <p class="hint">Sin selección = todos los especialistas activos.</p>
            <label v-for="e in especialistas" :key="e.id" class="check">
              <input type="checkbox" :value="e.id" v-model="editando.especialista_ids" />
              {{ e.emoji }} {{ e.nombre }} <small v-if="e.especialidad">· {{ e.especialidad }}</small>
            </label>
          </fieldset>

          <p v-if="error" class="error-text">{{ error }}</p>
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" @click="editando = null">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="busy">{{ busy ? 'Guardando…' : 'Guardar' }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
.lista { display: flex; flex-direction: column; gap: 0.8rem; }
.svc { display: flex; align-items: flex-start; gap: 1.1rem; }
.svc.off { opacity: 0.55; }
.svc-img { width: 38px; height: 38px; flex: none; border-radius: 10px; object-fit: cover; background: var(--accent-tint); }
.img-row { display: flex; align-items: center; gap: 0.9rem; }
.img-prev { width: 64px; height: 64px; border-radius: var(--r-md); object-fit: cover; flex: none; }
.img-prev--vacia { display: grid; place-items: center; font-size: 1.6rem; background: var(--accent-tint); }
.img-acciones { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.ocupado { opacity: 0.6; pointer-events: none; }
.svc-emoji {
  width: 54px; height: 54px; flex: none;
  display: grid; place-items: center;
  background: var(--brand-tint); border-radius: var(--r-md); font-size: 1.6rem;
}
.svc-main { flex: 1; min-width: 0; }
.svc-main h2 { font-size: var(--t-xl); color: var(--brand-ink); }
.svc-tags { display: flex; gap: 0.45rem; flex-wrap: wrap; margin-top: 0.35rem; }
.svc-desc { color: var(--ink-soft); font-size: var(--t-base); margin-top: 0.4rem; }
.svc-actions { display: flex; gap: 0.5rem; flex: none; }

.form { display: flex; flex-direction: column; gap: 1rem; }
.row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.8rem; }
@media (max-width: 520px) { .row-3 { grid-template-columns: 1fr; } }
.esp-field { border: 1px solid var(--line); border-radius: var(--r-md); padding: 0.9rem 1rem; }
.esp-field legend { font-weight: 600; font-size: var(--t-sm); padding: 0 0.3rem; }
.hint { font-size: var(--t-micro); color: var(--ink-soft); margin-bottom: 0.5rem; }
.check { display: flex; align-items: center; gap: 0.5rem; padding: 0.25rem 0; font-size: var(--t-base); cursor: pointer; }
.check small { color: var(--ink-soft); }
.campo-nota { display: block; margin-top: 0.3rem; font-size: var(--t-micro); color: var(--ink-soft); }
.campo-ancho { grid-column: 1 / -1; }
</style>
