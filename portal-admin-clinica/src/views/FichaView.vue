<script setup>
import { ref, computed, onMounted } from 'vue';
import { api, apiError, fotoEspecie, urlFoto } from '../api.js';
import { useAuthStore } from '../stores/auth.js';
import HistorialLineasVenta from '../components/HistorialLineasVenta.vue';
import ProtocolosEstado from '../components/ProtocolosEstado.vue';
import NuevaVentaModal from '../components/NuevaVentaModal.vue';

const auth = useAuthStore();
const esAdmin = computed(() => auth.user?.rol === 'admin');
/* El historial comercial completo (precios, totales, estado de pago) sale
   de /transacciones, que exige el permiso "ventas". Quien no lo tiene
   sigue viendo la lista escueta de siempre —qué se compró y cuándo, sin
   plata— que viene con el historial clínico y no requiere ese permiso. */
const puedeVerVentas = computed(() => auth.puede('ventas'));

/* Venta directa desde la ficha: abre el mismo modal que usa /ventas, con el
   paciente ya elegido (shape idéntico al que arma `elegirPropietario` dentro
   del propio modal), así que no hace falta ninguna lógica nueva ahí para
   saltarse el paso de buscar cliente. */
const mostrarNuevaVenta = ref(false);
// Se incrementa tras cada venta para forzar el remount de
// `<HistorialLineasVenta>` (vía :key) y que la venta recién creada aparezca
// sin recargar la página entera.
const comprasKey = ref(0);
const pacienteParaVenta = computed(() => paciente.value && ({
  id: props.mascotaId,
  nombre: paciente.value.nombre,
  usuario_id: paciente.value.usuario_id,
  dueno_nombre: paciente.value.dueno_nombre,
}));

function onVentaCreada() {
  mostrarNuevaVenta.value = false;
  comprasKey.value++;
  okMsg.value = 'Venta registrada.';
}

const props = defineProps({
  mascotaId: { type: String, required: true },
});

const paciente = ref(null);
const ficha = ref(null);
const historial = ref({ citas: [], compras: [] });
const loading = ref(true);
const error = ref('');
const okMsg = ref('');

const editFicha = ref({ antecedentes: '', vacunas: '', condiciones_cronicas: '', caracter: '', vif: '', vilef: '' });
const busyFicha = ref(false);

/* Los diez hallazgos del examen clínico que pidió la clínica, en un solo
   sitio: la lista manda el formulario de nota nueva, el de edición y qué se
   vacía al guardar. Repartida en tres lugares, agregar un campo obligaba a
   acordarse de los tres.

   Las opciones de los cualitativos viven acá y no en la base a propósito:
   cambiarlas es editar esta lista, no correr una migración.

   OJO — tres escalas a confirmar con el equipo clínico antes de usarlas en
   serio: condición corporal (acá 1-9, WSAVA; hay clínicas que usan 1-5),
   escala de dolor (acá 0-4) y grado periodontal (PD0-PD4, estándar AVDC). */
const EXAMEN = [
  { id: 'conciencia', label: 'Conciencia', opciones: ['Alerta', 'Deprimido', 'Estuporoso', 'Comatoso'] },
  { id: 'condicion_corporal', label: 'Condición corporal', opciones: ['1/9', '2/9', '3/9', '4/9', '5/9', '6/9', '7/9', '8/9', '9/9'] },
  { id: 'mucosas', label: 'Mucosas', opciones: ['Rosadas', 'Pálidas', 'Ictéricas', 'Cianóticas', 'Congestivas'] },
  { id: 'hidratacion', label: 'Hidratación', opciones: ['Normal', '5%', '6-8%', '10-12%'] },
  { id: 'frecuencia_cardiaca', label: 'Frec. cardíaca', unidad: 'lpm', tipo: 'number', min: 0, max: 400 },
  { id: 'frecuencia_respiratoria', label: 'Frec. respiratoria', unidad: 'rpm', tipo: 'number', min: 0, max: 300 },
  { id: 'temperatura', label: 'Temperatura', unidad: '°C', tipo: 'number', min: 20, max: 45, step: 0.1 },
  { id: 'dolor_escala', label: 'Escala de dolor', opciones: ['0 · sin dolor', '1 · leve', '2 · moderado', '3 · intenso', '4 · severo'] },
  { id: 'dolor_origen', label: 'Origen del dolor', tipo: 'text', ancho: true },
  { id: 'periodontal', label: 'Enfermedad periodontal', opciones: ['PD0 · sano', 'PD1 · gingivitis', 'PD2 · leve', 'PD3 · moderada', 'PD4 · severa'] },
];

const CAMPOS_NOTA = [
  'motivo', 'examen', 'diagnostico', 'tratamiento', 'indicaciones', 'proximo_control',
  'prediagnosticos', 'examenes_complementarios', 'especialista_id',
  ...EXAMEN.map((c) => c.id),
];

function notaVacia() {
  return Object.fromEntries(CAMPOS_NOTA.map((k) => [k, '']));
}

const nota = ref(notaVacia());

/* Los numéricos viajan como número o como nada. Un input vacío entrega '' y
   el backend lo rechazaría por no ser un entero; mandar null es decirle
   "no se midió", que es justo lo que significa. */
function limpiarNota(datos) {
  const salida = { ...datos, proximo_control: datos.proximo_control || null };
  for (const c of EXAMEN) {
    const v = salida[c.id];
    if (v === '' || v === undefined) salida[c.id] = null;
    else if (c.tipo === 'number') salida[c.id] = Number(v);
  }
  for (const k of ['prediagnosticos', 'examenes_complementarios']) {
    if (!salida[k]) salida[k] = null;
  }
  // El <select> sin elegir entrega '' y el backend espera un UUID: sin esto,
  // guardar una nota sin especialista responde 422 y se pierde lo escrito.
  if (!salida.especialista_id) salida.especialista_id = null;
  return salida;
}
/* Para el «Atendido por». Solo los activos: la lista es para elegir quién
   atendió hoy, no para revisar el historial (las notas viejas siguen
   mostrando su `atendido_por`, que viene resuelto del backend). */
const especialistas = ref([]);

const busyNota = ref(false);
const showNotaForm = ref(false);

function fmt(dt) {
  return new Date(dt).toLocaleDateString('es-CL', { day: 'numeric', month: 'long', year: 'numeric' });
}

function fmtCorta(dt) {
  return new Date(dt).toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function fmtPagoEstado(payment_status) {
  return { approved: 'Abono pagado', pending: 'Abono pendiente', rejected: 'Abono rechazado', cancelled: 'Abono cancelado' }[payment_status] || 'Sin abono';
}

/* El importador de ACVet crea un especialista placeholder por cada código
   sin nombre real en el respaldo (ver INFORME_IMPORTACION_ACVET.md §3):
   "Médico #6362 (ACVet)". Es un dato legítimo (alguien atendió), pero se
   distingue del staff actual para no leerse como un error del sistema. */
function esPlaceholderLegado(nombre) {
  return !!nombre && /^Médico #\d+ \(ACVet\)$/.test(nombre);
}

function fechaNacimientoTexto(fechaNacimiento) {
  if (!fechaNacimiento) return null;
  return new Date(fechaNacimiento).toLocaleDateString('es-CL', { day: 'numeric', month: 'long', year: 'numeric' });
}

function edadTexto(fechaNacimiento) {
  if (!fechaNacimiento) return null;
  const nac = new Date(fechaNacimiento);
  const hoy = new Date();
  let años = hoy.getFullYear() - nac.getFullYear();
  let meses = hoy.getMonth() - nac.getMonth();
  if (meses < 0 || (meses === 0 && hoy.getDate() < nac.getDate())) { años--; meses += 12; }
  if (hoy.getDate() < nac.getDate()) meses--;
  if (meses < 0) meses += 12;
  const partes = [];
  if (años > 0) partes.push(`${años} año${años === 1 ? '' : 's'}`);
  partes.push(`${meses} mes${meses === 1 ? '' : 'es'}`);
  return partes.join(' y ');
}

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const [f, p, h] = await Promise.all([
      api.get(`/fichas/mascota/${props.mascotaId}`),
      api.get(`/fichas/mascota/${props.mascotaId}/paciente`),
      api.get(`/fichas/mascota/${props.mascotaId}/historial`),
    ]);
    ficha.value = f.data;
    paciente.value = p.data;
    historial.value = h.data;
    editFicha.value = {
      antecedentes: f.data.antecedentes || '',
      vacunas: f.data.vacunas || '',
      condiciones_cronicas: f.data.condiciones_cronicas || '',
      caracter: f.data.caracter || '',
      vif: f.data.vif || '',
      vilef: f.data.vilef || '',
    };
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar la ficha.');
  } finally {
    loading.value = false;
  }
}

async function guardarFicha() {
  busyFicha.value = true;
  okMsg.value = '';
  error.value = '';
  try {
    const { data } = await api.patch(`/fichas/mascota/${props.mascotaId}`, editFicha.value);
    ficha.value = { ...ficha.value, ...data };
    okMsg.value = 'Ficha actualizada.';
    setTimeout(() => (okMsg.value = ''), 2500);
  } catch (e) {
    error.value = apiError(e, 'No se pudo guardar la ficha.');
  } finally {
    busyFicha.value = false;
  }
}

async function guardarNota() {
  if (!nota.value.motivo && !nota.value.diagnostico) {
    error.value = 'La nota necesita al menos motivo o diagnóstico.';
    return;
  }
  busyNota.value = true;
  error.value = '';
  try {
    await api.post(`/fichas/mascota/${props.mascotaId}/notas`, limpiarNota(nota.value));
    nota.value = notaVacia();
    showNotaForm.value = false;
    await cargar();
  } catch (e) {
    error.value = apiError(e, 'No se pudo guardar la nota.');
  } finally {
    busyNota.value = false;
  }
}

/* ── Editar nota existente ── */
const editandoNotaId = ref(null);
const editNota = ref({});
const busyEditNota = ref(false);

function abrirEditarNota(n) {
  editandoNotaId.value = n.id;
  editNota.value = Object.fromEntries(CAMPOS_NOTA.map((k) => [k, n[k] ?? '']));
}

async function guardarEdicionNota() {
  busyEditNota.value = true;
  error.value = '';
  try {
    const { data } = await api.patch(`/fichas/notas/${editandoNotaId.value}`, limpiarNota(editNota.value));
    const i = ficha.value.notas.findIndex((n) => n.id === editandoNotaId.value);
    if (i !== -1) ficha.value.notas[i] = { ...ficha.value.notas[i], ...data };
    editandoNotaId.value = null;
    okMsg.value = 'Nota actualizada.';
    setTimeout(() => (okMsg.value = ''), 2500);
  } catch (e) {
    error.value = apiError(e, 'No se pudo actualizar la nota.');
  } finally {
    busyEditNota.value = false;
  }
}

async function eliminarNota(n) {
  const fecha = fmt(n.created_at);
  if (!confirm(`¿Eliminar definitivamente la nota clínica del ${fecha}?\n\nEsta acción no se puede deshacer.`)) return;
  error.value = '';
  try {
    await api.delete(`/fichas/notas/${n.id}`);
    ficha.value.notas = ficha.value.notas.filter((x) => x.id !== n.id);
    okMsg.value = 'Nota eliminada.';
    setTimeout(() => (okMsg.value = ''), 2500);
  } catch (e) {
    error.value = apiError(e, 'No se pudo eliminar la nota.');
  }
}

/* ── Documentos clínicos: exámenes, RX, recetas ── */
const docArchivo = ref(null);
const docTipo = ref('examen');
const docDescripcion = ref('');
const busyDoc = ref(false);
const errorDoc = ref('');

/* Tope igual al del backend (TAMANO_MAX_BYTES en app/routes/fichas.py). Se
   comprueba ACÁ, al elegir el archivo, y no solo allá: sin esto una
   radiografía de 200 MB se subía entera por la conexión de la clínica —
   minutos de espera con una barra que avanza— para recibir al final "no puede
   superar 10 MB". Y con nginx delante ni siquiera llegaba ese mensaje: cortaba
   con un 413 sin explicación. El aviso instantáneo cuesta una línea. */
const DOC_MAX_BYTES = 10 * 1024 * 1024;

function onDocArchivo(ev) {
  const f = ev.target.files[0] || null;
  if (f && f.size > DOC_MAX_BYTES) {
    errorDoc.value = `"${f.name}" pesa ${tamanoLegible(f.size)} y el máximo son 10 MB. `
      + 'Comprime el archivo o súbelo por partes.';
    docArchivo.value = null;
    ev.target.value = '';
    return;
  }
  errorDoc.value = '';
  docArchivo.value = f;
}

async function subirDocumento() {
  if (!docArchivo.value) {
    errorDoc.value = 'Elige un archivo primero.';
    return;
  }
  busyDoc.value = true;
  errorDoc.value = '';
  try {
    const form = new FormData();
    form.append('archivo', docArchivo.value);
    form.append('tipo', docTipo.value);
    if (docDescripcion.value.trim()) form.append('descripcion', docDescripcion.value.trim());
    const { data } = await api.post(`/fichas/mascota/${props.mascotaId}/documentos`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    ficha.value.documentos = [data, ...(ficha.value.documentos || [])];
    docArchivo.value = null;
    docDescripcion.value = '';
    const input = document.getElementById('doc-archivo');
    if (input) input.value = '';
  } catch (e) {
    errorDoc.value = apiError(e, 'No se pudo subir el documento.');
  } finally {
    busyDoc.value = false;
  }
}

const abriendoDoc = ref(null);
const filtroDoc = ref('todos');

const documentosFiltrados = computed(() => {
  const docs = ficha.value?.documentos || [];
  return filtroDoc.value === 'todos' ? docs : docs.filter((d) => d.tipo === filtroDoc.value);
});

/* El endpoint de descarga exige JWT (Depends(get_current_user)); un <a href>
   plano no manda el Authorization header, por eso el navegador devolvía
   401. Se pide como blob por axios (que sí adjunta el token) y se abre
   ese blob en una pestaña nueva. */
async function abrirDocumento(d) {
  errorDoc.value = '';
  abriendoDoc.value = d.id;
  try {
    const { data } = await api.get(`/fichas/documentos/${d.id}/archivo`, { responseType: 'blob' });
    const blobUrl = URL.createObjectURL(data);
    window.open(blobUrl, '_blank', 'noopener');
    setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000);
  } catch (e) {
    errorDoc.value = apiError(e, 'No se pudo abrir el documento.');
  } finally {
    abriendoDoc.value = null;
  }
}

async function eliminarDocumento(d) {
  if (!confirm(`¿Eliminar "${d.nombre_original}"? Esta acción no se puede deshacer.`)) return;
  errorDoc.value = '';
  try {
    await api.delete(`/fichas/documentos/${d.id}`);
    ficha.value.documentos = ficha.value.documentos.filter((x) => x.id !== d.id);
  } catch (e) {
    errorDoc.value = apiError(e, 'No se pudo eliminar el documento.');
  }
}

function tipoLabel(t) {
  return { examen: 'Examen', rx: 'RX', receta: 'Receta', otro: 'Otro' }[t] || t;
}

function tamanoLegible(bytes) {
  if (!bytes) return '';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/* ── Panel lateral: edición inline de datos del paciente ── */
const editandoCampo = ref(null); // 'microchip' | 'esterilizado' | 'estado' | 'peso' | null
const valorCampo = ref('');
const busyCampo = ref(false);

function abrirEdicionCampo(campo, valorActual) {
  editandoCampo.value = campo;
  valorCampo.value = valorActual ?? '';
}

async function guardarCampo(campo) {
  if (campo === 'nombre' && !valorCampo.value.trim()) {
    editandoCampo.value = null; // el nombre no puede quedar vacío: se descarta el cambio
    return;
  }
  busyCampo.value = true;
  error.value = '';
  try {
    let valor = valorCampo.value === '' ? null : valorCampo.value;
    if (campo === 'peso_kg' && valor !== null) valor = Number(valor);
    const { data } = await api.patch(`/clientes/mascotas/${props.mascotaId}`, { [campo]: valor });
    paciente.value = { ...paciente.value, ...data };
    editandoCampo.value = null;
  } catch (e) {
    error.value = apiError(e, 'No se pudo guardar.');
  } finally {
    busyCampo.value = false;
  }
}

const especieFotoTag = computed(() => urlFoto(paciente.value?.foto_url) || fotoEspecie(paciente.value?.especie));

/* ── Resumen clínico: lo que el staff necesita ver sin leer toda la ficha ── */
const ultimaNota = computed(() => ficha.value?.notas?.[0] || null);

const conteoDocsPorTipo = computed(() => {
  const out = { examen: 0, rx: 0, receta: 0, otro: 0 };
  for (const d of ficha.value?.documentos || []) out[d.tipo] = (out[d.tipo] || 0) + 1;
  return out;
});

const tieneAlergias = computed(() => !!paciente.value?.alergias?.trim());
const tieneCondicionesCronicas = computed(() => !!ficha.value?.condiciones_cronicas?.trim());

function irADocumentos(tipo) {
  filtroDoc.value = tipo;
  document.getElementById('sec-documentos')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function irARegistros() {
  document.getElementById('sec-registros')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function irAFichaPermanente() {
  document.getElementById('sec-ficha-permanente')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/* ── Edición inline de datos del dueño (email, teléfono): distinto endpoint
   del de la mascota, porque es un Usuario, no un campo de Mascota. ── */
const editandoCampoDueno = ref(null); // 'dueno_email' | 'dueno_telefono' | null
const valorCampoDueno = ref('');
const busyCampoDueno = ref(false);

function abrirEdicionDueno(campo, valorActual) {
  editandoCampoDueno.value = campo;
  valorCampoDueno.value = valorActual ?? '';
}

async function guardarCampoDueno(campo) {
  const valor = valorCampoDueno.value.trim();
  if (campo === 'dueno_email' && !valor) {
    editandoCampoDueno.value = null; // el email no puede quedar vacío: se descarta
    return;
  }
  busyCampoDueno.value = true;
  error.value = '';
  try {
    const payload = campo === 'dueno_email' ? { email: valor } : { telefono: valor || null };
    await api.patch(`/clientes/${paciente.value.usuario_id}`, payload);
    paciente.value = { ...paciente.value, [campo]: valor || null };
    editandoCampoDueno.value = null;
  } catch (e) {
    error.value = apiError(e, 'No se pudo guardar.');
  } finally {
    busyCampoDueno.value = false;
  }
}

onMounted(async () => {
  await cargar();
  // Aparte y sin bloquear: si esto falla, la ficha igual se ve; lo único que
  // pasa es que el selector queda vacío.
  try {
    const { data } = await api.get('/catalogo/especialistas/todos');
    especialistas.value = data.filter((e) => e.activo);
  } catch { /* selector vacío, la ficha funciona igual */ }
});
</script>

<template>
  <div class="page page-ancha">
    <RouterLink to="/pacientes" class="back">← Pacientes</RouterLink>

    <p v-if="loading" class="empty">Cargando ficha…</p>
    <p v-else-if="error && !ficha" class="error-text">{{ error }}</p>

    <template v-else-if="ficha && paciente">
      <p v-if="error" class="error-text">{{ error }}</p>
      <p v-if="okMsg" class="ok-msg">{{ okMsg }}</p>

      <!-- Resumen clínico: lo crítico, visible sin bajar a leer la ficha completa -->
      <div class="resumen-clinico">
        <div class="resumen-paciente">
          <img class="resumen-emoji" :src="especieFotoTag" alt="" />
          <div class="resumen-paciente-info">
            <h1 class="resumen-nombre">{{ paciente.nombre }}</h1>
            <p class="resumen-sub">
              {{ paciente.raza || paciente.especie || 'Sin raza registrada' }}
              <span v-if="edadTexto(paciente.fecha_nacimiento)">
                · {{ edadTexto(paciente.fecha_nacimiento) }}
                <span class="resumen-fecha-nac">(nació el {{ fechaNacimientoTexto(paciente.fecha_nacimiento) }})</span>
              </span>
              <span v-if="paciente.peso_kg"> · {{ paciente.peso_kg }} kg</span>
            </p>
            <p class="resumen-dueno">{{ paciente.dueno_nombre }}</p>
          </div>

          <!-- Contacto del dueño: editable en el mismo lugar donde se busca -->
          <div class="resumen-contacto">
            <div class="contacto-campo">
              <span class="contacto-icono" aria-hidden="true">✉</span>
              <a
                v-if="editandoCampoDueno !== 'dueno_email'"
                class="contacto-valor editable"
                :href="`mailto:${paciente.dueno_email}`"
                @click.prevent="abrirEdicionDueno('dueno_email', paciente.dueno_email)"
              >{{ paciente.dueno_email }}</a>
              <input
                v-else class="input input-inline contacto-input" type="email" v-model="valorCampoDueno" :disabled="busyCampoDueno"
                @keyup.enter="guardarCampoDueno('dueno_email')" @blur="guardarCampoDueno('dueno_email')"
              />
            </div>
            <div class="contacto-campo">
              <span class="contacto-icono" aria-hidden="true">☎</span>
              <span
                v-if="editandoCampoDueno !== 'dueno_telefono'"
                class="contacto-valor editable"
                @click="abrirEdicionDueno('dueno_telefono', paciente.dueno_telefono)"
              >{{ paciente.dueno_telefono || 'Agregar teléfono' }}</span>
              <input
                v-else class="input input-inline contacto-input" type="tel" v-model="valorCampoDueno" :disabled="busyCampoDueno"
                placeholder="+56 9 ..."
                @keyup.enter="guardarCampoDueno('dueno_telefono')" @blur="guardarCampoDueno('dueno_telefono')"
              />
            </div>
          </div>
        </div>

        <div class="resumen-alertas" v-if="tieneAlergias || tieneCondicionesCronicas || paciente.estado === 'Fallecido'">
          <span v-if="paciente.estado === 'Fallecido'" class="alerta alerta-neutra">Fallecido</span>
          <span v-if="tieneAlergias" class="alerta alerta-critica">⚠ Alergia: {{ paciente.alergias }}</span>
          <span v-if="tieneCondicionesCronicas" class="alerta alerta-atencion">Condición crónica</span>
        </div>

        <div class="resumen-accesos">
          <button type="button" class="acceso" @click="irARegistros">
            <span class="acceso-cifra">{{ ficha.notas.length }}</span>
            <span class="acceso-label">Registro{{ ficha.notas.length === 1 ? '' : 's' }} clínico{{ ficha.notas.length === 1 ? '' : 's' }}</span>
          </button>
          <button type="button" class="acceso" :disabled="!conteoDocsPorTipo.examen" @click="irADocumentos('examen')">
            <span class="acceso-cifra">{{ conteoDocsPorTipo.examen }}</span>
            <span class="acceso-label">Exámenes</span>
          </button>
          <button type="button" class="acceso" :disabled="!conteoDocsPorTipo.rx" @click="irADocumentos('rx')">
            <span class="acceso-cifra">{{ conteoDocsPorTipo.rx }}</span>
            <span class="acceso-label">RX</span>
          </button>
          <button type="button" class="acceso" :disabled="!conteoDocsPorTipo.receta" @click="irADocumentos('receta')">
            <span class="acceso-cifra">{{ conteoDocsPorTipo.receta }}</span>
            <span class="acceso-label">Recetas</span>
          </button>
          <button type="button" class="acceso" @click="irAFichaPermanente">
            <span class="acceso-cifra">{{ paciente.esterilizado ? '✓' : '—' }}</span>
            <span class="acceso-label">Esterilizado</span>
          </button>
        </div>

        <div class="resumen-ultima" v-if="ultimaNota">
          <span class="resumen-ultima-label">Última consulta · {{ fmt(ultimaNota.created_at) }}</span>
          <p class="resumen-ultima-texto">
            {{ ultimaNota.diagnostico || ultimaNota.motivo || 'Sin diagnóstico registrado.' }}
          </p>
        </div>
      </div>

      <div class="ficha-layout">
        <!-- Columna principal: registros clínicos -->
        <div class="col-principal">
          <div id="sec-registros" class="card registros-card">
            <div class="registros-head section-head">
              <h2 class="section-title">📋 Registros clínicos</h2>
              <button class="btn btn-accent btn-sm" @click="showNotaForm = !showNotaForm">
                {{ showNotaForm ? 'Cerrar' : '+ Nuevo registro' }}
              </button>
            </div>

            <form v-if="showNotaForm" class="nota-form" @submit.prevent="guardarNota">
              <div class="grid-2">
                <div class="field">
                  <label for="n-motivo">Descripción / Anamnesis</label>
                  <textarea id="n-motivo" class="textarea" v-model="nota.motivo" placeholder="Motivo de la consulta…"></textarea>
                </div>
                <div class="field">
                  <label for="n-examen">Hallazgos examen clínico</label>
                  <textarea id="n-examen" class="textarea" v-model="nota.examen" placeholder="Hallazgos relevantes…"></textarea>
                </div>
              </div>
              <fieldset class="examen-set">
                <legend>Examen clínico</legend>
                <div class="examen-grid">
                  <div v-for="c in EXAMEN" :key="c.id" class="field examen-campo" :class="{ ancho: c.ancho }">
                    <label :for="`n-${c.id}`">
                      {{ c.label }}<span v-if="c.unidad" class="examen-unidad"> ({{ c.unidad }})</span>
                    </label>
                    <select
                      v-if="c.opciones" :id="`n-${c.id}`" class="select"
                      v-model="nota[c.id]"
                    >
                      <option value="">—</option>
                      <option v-for="o in c.opciones" :key="o" :value="o">{{ o }}</option>
                    </select>
                    <input
                      v-else :id="`n-${c.id}`" class="input"
                      :type="c.tipo === 'number' ? 'number' : 'text'"
                      :min="c.min" :max="c.max" :step="c.step"
                      v-model="nota[c.id]"
                      :placeholder="c.tipo === 'number' ? '—' : 'Dónde duele…'"
                    />
                  </div>
                </div>
              </fieldset>

              <div class="grid-2">
                <div class="field">
                  <label for="n-prediag">Prediagnósticos</label>
                  <textarea
                    id="n-prediag" class="textarea" v-model="nota.prediagnosticos"
                    placeholder="Uno por línea…"
                  ></textarea>
                </div>
                <div class="field">
                  <label for="n-complementarios">Exámenes complementarios</label>
                  <textarea
                    id="n-complementarios" class="textarea" v-model="nota.examenes_complementarios"
                    placeholder="Citología, raspado, biopsia…"
                  ></textarea>
                </div>
              </div>
              <div class="grid-2">
                <div class="field">
                  <label for="n-diagnostico">Diagnóstico</label>
                  <textarea id="n-diagnostico" class="textarea" v-model="nota.diagnostico" placeholder="Diagnóstico o pre-diagnóstico…"></textarea>
                </div>
                <div class="field">
                  <label for="n-tratamiento">Tratamiento</label>
                  <textarea id="n-tratamiento" class="textarea" v-model="nota.tratamiento" placeholder="Medicamentos, dosis…"></textarea>
                </div>
              </div>
              <div class="grid-2">
                <div class="field">
                  <label for="n-indicaciones">Receta / indicaciones al dueño</label>
                  <textarea id="n-indicaciones" class="textarea" v-model="nota.indicaciones" placeholder="Cuidados en casa, control…"></textarea>
                </div>
                <div class="field">
                  <label for="n-proximo">Próximo control</label>
                  <input id="n-proximo" class="input" type="date" v-model="nota.proximo_control" />
                </div>
                <div class="field">
                  <label for="n-atendio">Atendido por <small>(si no fuiste tú)</small></label>
                  <select id="n-atendio" class="select" v-model="nota.especialista_id">
                    <option value="">—</option>
                    <option v-for="e in especialistas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                  </select>
                </div>
              </div>
              <div class="form-actions">
                <button type="submit" class="btn btn-primary" :disabled="busyNota">
                  {{ busyNota ? 'Guardando…' : 'Guardar registro' }}
                </button>
              </div>
            </form>

            <p v-if="!ficha.notas.length" class="empty">Sin registros clínicos todavía.</p>
            <ol v-else class="notas-list">
              <li v-for="n in ficha.notas" :key="n.id" class="nota-item">
                <!-- Modo edición -->
                <form v-if="editandoNotaId === n.id" class="edit-nota" @submit.prevent="guardarEdicionNota">
                  <span class="nota-fecha">{{ fmt(n.created_at) }}</span>
                  <input class="input" v-model="editNota.motivo" placeholder="Descripción / Anamnesis" />
                  <textarea class="textarea" v-model="editNota.examen" placeholder="Hallazgos examen clínico"></textarea>
              <fieldset class="examen-set">
                <legend>Examen clínico</legend>
                <div class="examen-grid">
                  <div v-for="c in EXAMEN" :key="c.id" class="field examen-campo" :class="{ ancho: c.ancho }">
                    <label :for="`edit-n-${c.id}`">
                      {{ c.label }}<span v-if="c.unidad" class="examen-unidad"> ({{ c.unidad }})</span>
                    </label>
                    <select
                      v-if="c.opciones" :id="`edit-n-${c.id}`" class="select"
                      v-model="editNota[c.id]"
                    >
                      <option value="">—</option>
                      <option v-for="o in c.opciones" :key="o" :value="o">{{ o }}</option>
                    </select>
                    <input
                      v-else :id="`edit-n-${c.id}`" class="input"
                      :type="c.tipo === 'number' ? 'number' : 'text'"
                      :min="c.min" :max="c.max" :step="c.step"
                      v-model="editNota[c.id]"
                      :placeholder="c.tipo === 'number' ? '—' : 'Dónde duele…'"
                    />
                  </div>
                </div>
              </fieldset>

              <div class="grid-2">
                <div class="field">
                  <label for="edit-n-prediag">Prediagnósticos</label>
                  <textarea
                    id="edit-n-prediag" class="textarea" v-model="editNota.prediagnosticos"
                    placeholder="Uno por línea…"
                  ></textarea>
                </div>
                <div class="field">
                  <label for="edit-n-complementarios">Exámenes complementarios</label>
                  <textarea
                    id="edit-n-complementarios" class="textarea" v-model="editNota.examenes_complementarios"
                    placeholder="Citología, raspado, biopsia…"
                  ></textarea>
                </div>
              </div>
                  <input class="input" v-model="editNota.diagnostico" placeholder="Diagnóstico" />
                  <textarea class="textarea" v-model="editNota.tratamiento" placeholder="Tratamiento"></textarea>
                  <textarea class="textarea" v-model="editNota.indicaciones" placeholder="Receta / indicaciones"></textarea>
                  <label class="field-hint" for="edit-n-proximo">Próximo control</label>
                  <input id="edit-n-proximo" class="input" type="date" v-model="editNota.proximo_control" />
                  <div class="nota-acciones">
                    <button type="button" class="btn btn-ghost btn-sm" @click="editandoNotaId = null">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="busyEditNota">
                      {{ busyEditNota ? 'Guardando…' : 'Guardar' }}
                    </button>
                  </div>
                </form>

                <!-- Modo lectura: cada campo con su etiqueta encima, no en línea -->
                <template v-else>
                  <div class="nota-head">
                    <span class="nota-fecha-grande">{{ fmt(n.created_at) }}</span>
                    <span
                      class="nota-atendido"
                      :class="{ 'nota-atendido-legado': esPlaceholderLegado(n.atendido_por) }"
                    >
                      {{ n.atendido_por
                        ? (esPlaceholderLegado(n.atendido_por) ? 'Historial importado · sin médico identificado' : n.atendido_por)
                        : 'Sin registrar' }}
                    </span>
                  </div>

                  <div class="nota-campos">
                    <div v-if="n.motivo" class="nota-campo">
                      <span class="nota-campo-label">Descripción / Anamnesis</span>
                      <p class="nota-campo-valor">{{ n.motivo }}</p>
                    </div>
                    <div v-if="n.examen" class="nota-campo">
                      <span class="nota-campo-label">Hallazgos del examen</span>
                      <p class="nota-campo-valor">{{ n.examen }}</p>
                    </div>
                    <div v-if="n.diagnostico" class="nota-campo nota-campo-destacado">
                      <span class="nota-campo-label">Diagnóstico</span>
                      <p class="nota-campo-valor">{{ n.diagnostico }}</p>
                    </div>
                    <div v-if="n.tratamiento" class="nota-campo nota-campo-destacado">
                      <span class="nota-campo-label">Tratamiento</span>
                      <p class="nota-campo-valor">{{ n.tratamiento }}</p>
                    </div>
                    <div v-if="n.indicaciones" class="nota-campo">
                      <span class="nota-campo-label">Receta / indicaciones</span>
                      <p class="nota-campo-valor">{{ n.indicaciones }}</p>
                    </div>
                    <div v-if="n.proximo_control" class="nota-campo nota-campo-destacado">
                      <span class="nota-campo-label">Próximo control</span>
                      <p class="nota-campo-valor">{{ fmtCorta(n.proximo_control) }}</p>
                    </div>
                    <p v-if="!n.motivo && !n.examen && !n.diagnostico && !n.tratamiento && !n.indicaciones && !n.proximo_control" class="nota-campo-vacio">
                      Registro sin contenido.
                    </p>
                  </div>

                  <div class="nota-foot">
                    <button class="btn btn-ghost btn-sm" @click="abrirEditarNota(n)">Editar</button>
                    <button v-if="esAdmin" class="btn btn-ghost btn-sm btn-peligro" @click="eliminarNota(n)">Eliminar</button>
                  </div>
                </template>
              </li>
            </ol>
          </div>

          <ProtocolosEstado :mascota-id="mascotaId" />

          <div id="sec-documentos" class="card">
            <div class="section-head">
              <h2 class="section-title">📎 Documentos (exámenes, RX, recetas)</h2>
            </div>
            <form class="doc-form" @submit.prevent="subirDocumento">
              <div class="field">
                <label for="doc-archivo">Archivo</label>
                <!-- El input nativo se oculta: su botón y su «No file chosen» los
                     escribe el navegador en SU idioma, no en el de la clínica. -->
                <div class="archivo-row">
                  <label class="btn btn-ghost btn-sm">
                    Elegir archivo
                    <input id="doc-archivo" type="file" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.webp" hidden @change="onDocArchivo" />
                  </label>
                  <span class="archivo-nombre">{{ docArchivo ? docArchivo.name : 'Ningún archivo seleccionado' }}</span>
                </div>
              </div>
              <div class="grid-2">
                <div class="field">
                  <label for="doc-tipo">Tipo</label>
                  <select id="doc-tipo" class="input" v-model="docTipo">
                    <option value="examen">Examen</option>
                    <option value="rx">RX</option>
                    <option value="receta">Receta</option>
                    <option value="otro">Otro</option>
                  </select>
                </div>
                <div class="field">
                  <label for="doc-desc">Descripción</label>
                  <input id="doc-desc" class="input" v-model="docDescripcion" placeholder="Ej: Hemograma completo" />
                </div>
              </div>
              <p v-if="errorDoc" class="error-text">{{ errorDoc }}</p>
              <button type="submit" class="btn btn-ghost" :disabled="busyDoc">
                {{ busyDoc ? 'Subiendo…' : 'Subir documento' }}
              </button>
            </form>

            <p v-if="!ficha.documentos?.length" class="empty">Sin documentos adjuntos todavía.</p>
            <template v-else>
              <div class="doc-filtros">
                <button
                  type="button" class="doc-filtro" :class="{ activo: filtroDoc === 'todos' }"
                  @click="filtroDoc = 'todos'"
                >Todos ({{ ficha.documentos.length }})</button>
                <button
                  v-for="t in ['examen', 'rx', 'receta', 'otro']" :key="t"
                  type="button" class="doc-filtro" :class="{ activo: filtroDoc === t }"
                  v-show="conteoDocsPorTipo[t]"
                  @click="filtroDoc = t"
                >{{ tipoLabel(t) }} ({{ conteoDocsPorTipo[t] }})</button>
              </div>

              <ul class="doc-list">
                <li v-for="d in documentosFiltrados" :key="d.id" class="doc-item">
                  <div class="doc-info">
                    <span class="chip chip-info">{{ tipoLabel(d.tipo) }}</span>
                    <button
                      type="button" class="doc-nombre doc-nombre-btn"
                      :disabled="abriendoDoc === d.id"
                      @click="abrirDocumento(d)"
                    >{{ abriendoDoc === d.id ? 'Abriendo…' : (d.descripcion || d.nombre_original) }}</button>
                    <span class="doc-meta">{{ fmt(d.created_at) }}<template v-if="d.tamano_bytes"> · {{ tamanoLegible(d.tamano_bytes) }}</template></span>
                  </div>
                  <button class="btn btn-ghost btn-sm btn-peligro" @click="eliminarDocumento(d)">Eliminar</button>
                </li>
              </ul>
            </template>
          </div>

          <div id="sec-historial" class="card">
            <div class="section-head">
              <h2 class="section-title">🕓 Historial de visitas</h2>
            </div>
            <p v-if="!historial.citas.length" class="empty">Sin citas registradas.</p>
            <ol v-else class="historial-lista">
              <li v-for="(c, i) in historial.citas" :key="i" class="historial-item">
                <div class="historial-fecha">{{ fmt(c.fecha) }}</div>
                <div class="historial-datos">
                  <span>{{ c.servicio || 'Consulta' }}</span>
                  <span v-if="c.especialista" class="muted">— {{ c.especialista }}</span>
                  <span class="chip chip-info">{{ c.estado }}</span>
                  <span v-if="c.payment_status" class="chip" :class="c.payment_status === 'approved' ? 'chip-ok' : 'chip-info'">{{ fmtPagoEstado(c.payment_status) }}</span>
                </div>
              </li>
            </ol>

          </div>

          <!-- Compras: sección comercial, separada del historial clínico a
               propósito. Lo que se le vendió a un paciente no es un dato
               clínico, y mezclarlos haría que la ficha respondiera dos
               preguntas distintas en el mismo bloque. -->
          <div id="sec-compras" class="card">
            <div class="section-head">
              <h2 class="section-title">🧾 Compras</h2>
            </div>
            <template v-if="puedeVerVentas">
              <div class="compras-head">
                <p class="compras-nota">Productos y servicios adquiridos para {{ paciente.nombre }}.</p>
                <div class="compras-acciones">
                  <button class="btn btn-primary btn-sm" @click="mostrarNuevaVenta = true">+ Nueva venta</button>
                  <RouterLink
                    :to="{ path: '/ventas', query: { mascota_id: mascotaId, etiqueta: paciente.nombre } }"
                    class="btn btn-ghost btn-sm"
                  >Ver en Ventas</RouterLink>
                </div>
              </div>
              <HistorialLineasVenta
                :key="comprasKey"
                :params="{ mascota_id: mascotaId }"
                :agrupar="true"
                :mostrar-paciente="false"
                :mostrar-cliente="false"
                vacio="Todavía no hay compras registradas para este paciente."
              />
            </template>
            <template v-else>
              <p v-if="!historial.compras.length" class="empty">Sin compras registradas.</p>
              <ul v-else class="historial-compras">
                <li v-for="(v, i) in historial.compras" :key="i">
                  <span>{{ fmtCorta(v.fecha) }}</span>
                  <span>{{ v.cantidad }}× {{ v.nombre }}</span>
                </li>
              </ul>
            </template>
          </div>

          <div id="sec-ficha-permanente" class="card">
            <div class="sec-head-oscura">
              <h2 class="section-title">📄 Ficha permanente</h2>
            </div>
            <form class="perm-form" @submit.prevent="guardarFicha">
              <div class="grid-2">
                <div class="field">
                  <label for="f-ant">Antecedentes</label>
                  <textarea id="f-ant" class="textarea" v-model="editFicha.antecedentes"></textarea>
                </div>
                <div class="field">
                  <label for="f-vac">Vacunas</label>
                  <textarea id="f-vac" class="textarea" v-model="editFicha.vacunas"></textarea>
                </div>
              </div>
              <div class="field">
                <label for="f-cron">Condiciones crónicas</label>
                <textarea id="f-cron" class="textarea" v-model="editFicha.condiciones_cronicas"></textarea>
              </div>
              <div class="grid-2">
                <div class="field">
                  <label for="f-caracter">Carácter</label>
                  <select id="f-caracter" class="input" v-model="editFicha.caracter">
                    <option value="">—</option>
                    <option value="sociable">Sociable</option>
                    <option value="agresivo">Agresivo</option>
                    <option value="hiperactivo">Hiperactivo</option>
                  </select>
                </div>
                <div v-if="paciente?.especie === 'gato'" class="field">
                  <label for="f-vif">VIF / ViLeF</label>
                  <div class="vif-vilef-row">
                    <select id="f-vif" class="input" v-model="editFicha.vif" aria-label="VIF">
                      <option value="">VIF: —</option>
                      <option value="positivo">VIF: Positivo</option>
                      <option value="negativo">VIF: Negativo</option>
                    </select>
                    <select id="f-vilef" class="input" v-model="editFicha.vilef" aria-label="ViLeF">
                      <option value="">ViLeF: —</option>
                      <option value="positivo">ViLeF: Positivo</option>
                      <option value="negativo">ViLeF: Negativo</option>
                    </select>
                  </div>
                </div>
              </div>
              <button type="submit" class="btn btn-ghost" :disabled="busyFicha">
                {{ busyFicha ? 'Guardando…' : 'Guardar ficha' }}
              </button>
            </form>
          </div>
        </div>

        <!-- Panel lateral: paciente -->
        <aside class="col-lateral">
          <div class="card paciente-card">
            <div class="paciente-head">
              <img class="paciente-emoji" :src="especieFotoTag" alt="" />
              <div class="paciente-head-info">
                <h1
                  v-if="editandoCampo !== 'nombre'"
                  class="paciente-nombre editable"
                  @click="abrirEdicionCampo('nombre', paciente.nombre)"
                >{{ paciente.nombre }}</h1>
                <input
                  v-else class="input input-inline input-nombre" v-model="valorCampo" :disabled="busyCampo"
                  @keyup.enter="guardarCampo('nombre')" @blur="guardarCampo('nombre')"
                />
                <p class="paciente-dueno">{{ paciente.dueno_nombre }}</p>
              </div>
            </div>

            <div class="datos-grid">
              <div>
                <span class="dato-label">Especie</span>
                <span
                  v-if="editandoCampo !== 'especie'"
                  class="dato-valor cap editable"
                  @click="abrirEdicionCampo('especie', paciente.especie)"
                >{{ paciente.especie || '—' }}</span>
                <select
                  v-else class="input input-inline" v-model="valorCampo" :disabled="busyCampo"
                  @change="guardarCampo('especie')" @blur="editandoCampo = null"
                >
                  <option value="perro">Perro</option>
                  <option value="gato">Gato</option>
                  <option value="conejo">Conejo</option>
                  <option value="otro">Otro</option>
                </select>
              </div>
              <div>
                <span class="dato-label">Raza</span>
                <span
                  v-if="editandoCampo !== 'raza'"
                  class="dato-valor cap editable"
                  @click="abrirEdicionCampo('raza', paciente.raza)"
                >{{ paciente.raza || 'Sin indicar' }}</span>
                <input
                  v-else class="input input-inline" v-model="valorCampo" :disabled="busyCampo"
                  @keyup.enter="guardarCampo('raza')" @blur="guardarCampo('raza')"
                />
              </div>
              <div>
                <span class="dato-label">Sexo</span>
                <span
                  v-if="editandoCampo !== 'sexo'"
                  class="dato-valor editable"
                  @click="abrirEdicionCampo('sexo', paciente.sexo)"
                >{{ paciente.sexo === 'F' ? 'Hembra' : paciente.sexo === 'M' ? 'Macho' : '—' }}</span>
                <select
                  v-else class="input input-inline" v-model="valorCampo" :disabled="busyCampo"
                  @change="guardarCampo('sexo')" @blur="editandoCampo = null"
                >
                  <option value="M">Macho</option>
                  <option value="F">Hembra</option>
                </select>
              </div>
            </div>

            <div class="dato-bloque">
              <span class="dato-label">Edad y fecha de nacimiento</span>
              <div
                v-if="editandoCampo !== 'fecha_nacimiento'"
                class="dato-valor editable dato-edad"
                @click="abrirEdicionCampo('fecha_nacimiento', paciente.fecha_nacimiento)"
              >
                <template v-if="paciente.fecha_nacimiento">
                  <span class="dato-edad-principal">{{ edadTexto(paciente.fecha_nacimiento) }}</span>
                  <span class="dato-edad-fecha">Nació el {{ fechaNacimientoTexto(paciente.fecha_nacimiento) }}</span>
                </template>
                <template v-else>Sin registrar</template>
              </div>
              <input
                v-else class="input input-inline" type="date" v-model="valorCampo" :disabled="busyCampo"
                @change="guardarCampo('fecha_nacimiento')" @blur="editandoCampo = null"
              />
            </div>

            <div class="dato-bloque">
              <span class="dato-label">Estado reproductivo</span>
              <span
                v-if="editandoCampo !== 'esterilizado'"
                class="dato-valor editable"
                @click="abrirEdicionCampo('esterilizado', paciente.esterilizado)"
              >{{ paciente.esterilizado ? 'Esterilizado' : 'No esterilizado' }}</span>
              <select
                v-else class="input input-inline" v-model="valorCampo" :disabled="busyCampo"
                @change="guardarCampo('esterilizado')" @blur="editandoCampo = null"
              >
                <option :value="false">No esterilizado</option>
                <option :value="true">Esterilizado</option>
              </select>
            </div>

            <div class="dato-bloque">
              <span class="dato-label">Peso</span>
              <span
                v-if="editandoCampo !== 'peso_kg'"
                class="dato-valor dato-peso editable"
                @click="abrirEdicionCampo('peso_kg', paciente.peso_kg)"
              >{{ paciente.peso_kg ? `${paciente.peso_kg} kg` : 'Sin registrar' }}</span>
              <input
                v-else class="input input-inline" type="number" step="0.1" min="0" v-model="valorCampo" :disabled="busyCampo"
                @keyup.enter="guardarCampo('peso_kg')" @blur="guardarCampo('peso_kg')"
              />
            </div>

            <div class="dato-bloque">
              <span class="dato-label">Estado</span>
              <span
                v-if="editandoCampo !== 'estado'"
                class="dato-valor editable"
                @click="abrirEdicionCampo('estado', paciente.estado)"
              >
                <span class="chip" :class="paciente.estado === 'Fallecido' ? 'chip-danger' : 'chip-ok'">
                  {{ paciente.estado || 'Vivo' }}
                </span>
              </span>
              <select
                v-else class="input input-inline" v-model="valorCampo" :disabled="busyCampo"
                @change="guardarCampo('estado')" @blur="editandoCampo = null"
              >
                <option value="Vivo">Vivo</option>
                <option value="Fallecido">Fallecido</option>
              </select>
            </div>

            <div class="dato-bloque">
              <span class="dato-label">Alergias</span>
              <span
                v-if="editandoCampo !== 'alergias'"
                class="dato-valor editable" :class="{ alergia: paciente.alergias }"
                @click="abrirEdicionCampo('alergias', paciente.alergias)"
              >{{ paciente.alergias || 'Sin registrar' }}</span>
              <input
                v-else class="input input-inline" v-model="valorCampo" :disabled="busyCampo"
                placeholder="Ninguna conocida"
                @keyup.enter="guardarCampo('alergias')" @blur="guardarCampo('alergias')"
              />
            </div>

            <div class="dato-bloque">
              <span class="dato-label">Microchip</span>
              <span
                v-if="editandoCampo !== 'microchip_codigo'"
                class="dato-valor editable"
                @click="abrirEdicionCampo('microchip_codigo', paciente.microchip_codigo)"
              >{{ paciente.microchip_codigo || 'Ingrese número de microchip' }}</span>
              <input
                v-else class="input input-inline" v-model="valorCampo" :disabled="busyCampo"
                placeholder="Número de microchip"
                @keyup.enter="guardarCampo('microchip_codigo')" @blur="guardarCampo('microchip_codigo')"
              />
            </div>

          </div>
        </aside>
      </div>

      <NuevaVentaModal
        v-if="mostrarNuevaVenta"
        :paciente-preseleccionado="pacienteParaVenta"
        @close="mostrarNuevaVenta = false"
        @creada="onVentaCreada"
      />
    </template>
  </div>
</template>

<style scoped>
/* Examen clínico: una rejilla de casillas chicas, no una columna de campos
   anchos. Son diez datos cortos que el veterinario llena de corrido durante
   la consulta; a ancho completo obligaban a bajar media pantalla para pasar
   de la temperatura al grado periodontal. */
.examen-set { border: 1px solid var(--line); border-radius: var(--r-md); padding: 0.7rem 0.9rem 0.9rem; margin: 0 0 0.9rem; min-width: 0; }
.examen-set legend { font-size: var(--t-micro); font-weight: 700; color: var(--ink-soft); padding: 0 0.4em; text-transform: uppercase; letter-spacing: 0.05em; }
.examen-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.6rem 0.8rem; }
/* El origen del dolor es texto libre y no una opción: necesita más de una
   casilla para que quepa "oído derecho, dorso lumbar". */
.examen-campo.ancho { grid-column: span 2; }
.examen-campo label { font-size: var(--t-micro); }
.examen-unidad { color: var(--ink-soft); font-weight: 400; }
@media (max-width: 560px) { .examen-campo.ancho { grid-column: span 1; } }


.page-ancha { max-width: 1400px; }
.back { display: inline-block; color: var(--ink-soft); font-weight: 500; font-size: var(--t-base); margin-bottom: 1rem; }
.back:hover { color: var(--brand-ink); }
.ok-msg { color: var(--ok); font-size: var(--t-base); font-weight: 500; margin-bottom: 0.8rem; }

/* ── Resumen clínico ── */
.resumen-clinico {
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--r-lg); padding: 1.3rem 1.5rem; margin-bottom: 1.4rem;
  display: flex; flex-direction: column; gap: 1.1rem;
}

/* Franja oscura de cabecera: se extiende hasta los bordes del card (compensa
   el padding del contenedor padre) y toma su misma esquina redondeada. */
.resumen-paciente {
  display: flex; align-items: center; gap: 0.9rem; flex-wrap: wrap;
  background: linear-gradient(135deg, var(--side-bg), var(--side-bg-2));
  margin: -1.3rem -1.5rem 0; padding: 1.3rem 1.5rem;
  border-radius: var(--r-lg) var(--r-lg) 0 0;
}
.resumen-emoji {
  width: 52px; height: 52px; flex: none; object-fit: cover;
  background: rgba(255, 255, 255, 0.12); border-radius: 50%;
}
.resumen-paciente-info { min-width: 0; }
.resumen-nombre { color: #fff; font-size: var(--t-2xl); font-weight: 700; margin: 0; letter-spacing: -0.01em; }
.resumen-sub { color: rgba(255, 255, 255, 0.72); font-size: var(--t-base); margin-top: 0.15rem; }
.resumen-sub::first-letter { text-transform: uppercase; }
.resumen-fecha-nac { color: rgba(255, 255, 255, 0.55); }
.resumen-dueno { color: rgba(255, 255, 255, 0.72); font-size: var(--t-sm); margin-top: 0.1rem; }

/* Contacto del dueño: a la derecha de la cabecera en desktop, editable
   con el mismo patrón click-to-edit que el resto de la ficha. */
.resumen-contacto {
  display: flex; flex-direction: column; gap: 0.35rem;
  margin-left: auto; padding-left: 1rem;
}
.contacto-campo { display: flex; align-items: center; gap: 0.5rem; font-size: var(--t-sm); }
.contacto-icono { color: rgba(255, 255, 255, 0.6); font-size: var(--t-base); width: 1.1em; text-align: center; flex-shrink: 0; }
.contacto-valor { color: rgba(255, 255, 255, 0.9); }
a.contacto-valor { color: #fff; }
a.contacto-valor:hover { text-decoration: underline; }
.contacto-input { padding: 0.3em 0.6em; font-size: var(--t-sm); min-width: 190px; }
@media (max-width: 640px) {
  .resumen-contacto { margin-left: 0; padding-left: 0; width: 100%; padding-top: 0.8rem; border-top: 1px solid rgba(255, 255, 255, 0.15); }
}

.resumen-alertas { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.alerta {
  display: inline-flex; align-items: center; gap: 0.35em;
  padding: 0.4em 0.85em; border-radius: 999px; font-size: var(--t-micro); font-weight: 650;
}
.alerta-critica { background: var(--danger-tint); color: var(--danger); }
.alerta-atencion { background: var(--warn-tint); color: var(--warn-ink); }
.alerta-neutra { background: var(--bg); color: var(--ink-soft); }

.resumen-accesos {
  display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.7rem;
  padding-top: 1.1rem; border-top: 1px solid var(--line);
}
.acceso {
  display: flex; flex-direction: column; align-items: center; gap: 0.2rem;
  padding: 0.7rem 0.5rem; border-radius: var(--r-md);
  background: var(--bg); border: 1px solid transparent;
  transition: border-color 0.15s ease, background 0.15s ease;
  cursor: pointer;
}
.acceso:not(:disabled):hover { border-color: var(--accent); background: var(--accent-tint); }
.acceso:disabled { cursor: default; opacity: 0.45; }
.acceso-cifra { font-size: var(--t-2xl); font-weight: 700; color: var(--brand-ink); font-variant-numeric: tabular-nums; }
.acceso-label { font-size: var(--t-micro); color: var(--ink-soft); font-weight: 600; text-align: center; }
@media (max-width: 720px) { .resumen-accesos { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 440px) { .resumen-accesos { grid-template-columns: repeat(2, 1fr); } }

.resumen-ultima { padding-top: 1.1rem; border-top: 1px solid var(--line); }
.resumen-ultima-label { font-size: var(--t-micro); color: var(--ink-soft); font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; }
.resumen-ultima-texto { font-size: var(--t-base); color: var(--ink); margin-top: 0.3rem; line-height: 1.5; }

.ficha-layout { display: grid; grid-template-columns: 1.7fr 1fr; gap: 1.4rem; align-items: start; }
@media (max-width: 980px) { .ficha-layout { grid-template-columns: 1fr; } }

/* min-width: 0 es necesario en un hijo de grid con contenido de texto largo
   (notas clínicas): sin esto, el track del grid crece para acomodar el
   contenido en vez de forzarlo a envolver, y desborda el viewport en móvil. */
.col-principal { display: flex; flex-direction: column; gap: 1.2rem; min-width: 0; }
.col-lateral { position: sticky; top: 1rem; min-width: 0; }

/* Cabecera de sección con franja oscura: se reutiliza en Registros clínicos,
   Documentos y Ficha permanente. La franja (.section-head, app.css) compensa
   el padding de 1.1rem de .card; por eso ninguna de estas tarjetas lo cambia
   — Registros tenía 1.4rem y su franja quedaba 0.3rem corta por cada lado. */
.registros-head {
  display: flex; justify-content: space-between; align-items: center;
}

.nota-form { display: flex; flex-direction: column; gap: 0.9rem; padding: 1rem; margin-bottom: 1.2rem; background: var(--bg); border-radius: var(--r-lg); }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem; }
.vif-vilef-row { display: flex; gap: 0.6rem; }
.vif-vilef-row .input { flex: 1; min-width: 0; }
.form-actions { display: flex; justify-content: flex-end; }
@media (max-width: 560px) { .grid-2 { grid-template-columns: 1fr; } }

.notas-list { list-style: none; padding: 0; display: flex; flex-direction: column; gap: 0; margin: 0; max-height: 720px; overflow-y: auto; }
.nota-item { padding: 1.2rem 0; border-bottom: 1px solid var(--line-tabla); }
.nota-item:first-child { padding-top: 0; }
.nota-item:last-child { border-bottom: none; }

/* Cabecera de cada registro: fecha grande (es lo que se busca al escanear
   la lista) y quién atendió, separados con jerarquía clara. */
.nota-head { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; margin-bottom: 0.8rem; flex-wrap: wrap; }
.nota-fecha-grande { font-size: var(--t-base); font-weight: 700; color: var(--brand-ink); }
.nota-atendido { font-size: var(--t-micro); color: var(--ink-soft); font-weight: 500; }
.nota-atendido-legado { font-style: italic; color: var(--ink-soft); opacity: 0.85; }

/* Cada campo es una etiqueta pequeña sobre su valor: lectura por escaneo,
   no un párrafo con negrita corrida. */
.nota-campos { display: flex; flex-direction: column; gap: 0.7rem; margin-bottom: 0.9rem; }
.nota-campo-label {
  display: block; font-size: var(--t-micro); font-weight: 650; color: var(--ink-soft);
  text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.15rem;
}
.nota-campo-valor { font-size: var(--t-base); line-height: 1.55; color: var(--ink); }
.nota-campo-vacio { font-size: var(--t-base); color: var(--ink-soft); font-style: italic; }

/* Diagnóstico y tratamiento son la información de mayor peso clínico:
   se destacan con un borde y fondo sutil, no con más negrita. */
.nota-campo-destacado {
  background: var(--brand-tint); border-radius: var(--r-md);
  padding: 0.6rem 0.8rem;
}
.nota-campo-destacado .nota-campo-label { color: var(--brand-ink); }

.nota-foot { display: flex; gap: 0.5rem; }
.btn-peligro { color: var(--danger); }
.btn-peligro:hover { background: var(--danger-tint); border-color: var(--danger); }
.edit-nota { display: flex; flex-direction: column; gap: 0.5rem; }
.edit-nota .textarea { min-height: 58px; }
.edit-nota .nota-fecha { font-size: var(--t-micro); color: var(--ink-soft); }

.doc-form { display: flex; flex-direction: column; gap: 0.8rem; margin-bottom: 1rem; }
.archivo-row { display: flex; align-items: center; gap: 0.7rem; min-width: 0; }
.archivo-nombre { font-size: var(--t-sm); color: var(--ink-soft); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.doc-filtros { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 0.9rem; }
.doc-filtro {
  padding: 0.4em 0.9em; border-radius: 999px; font-size: var(--t-micro); font-weight: 600;
  border: 1px solid var(--line-strong); color: var(--ink-soft); background: var(--surface);
  transition: all 0.15s ease;
}
.doc-filtro:hover { border-color: var(--accent); color: var(--accent-strong); }
.doc-filtro.activo { background: var(--brand); border-color: var(--brand); color: #fff; }

.doc-list { list-style: none; padding: 0; display: flex; flex-direction: column; gap: 0.6rem; margin: 0; }
.doc-item { display: flex; align-items: center; justify-content: space-between; gap: 0.8rem; padding: 0.8rem 1rem; background: var(--bg); border-radius: var(--r-md); }
.doc-info { display: flex; flex-direction: column; gap: 0.3rem; min-width: 0; }
.doc-nombre { font-weight: 600; color: var(--brand-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.doc-nombre:hover { text-decoration: underline; }
.doc-nombre-btn { text-align: left; background: none; border: none; cursor: pointer; padding: 0; font: inherit; }
.doc-nombre-btn:disabled { cursor: default; opacity: 0.6; text-decoration: none; }
.doc-meta { font-size: var(--t-micro); color: var(--ink-soft); }

.perm-form { display: flex; flex-direction: column; gap: 0.9rem; }
.perm-form .textarea { min-height: 64px; }

/* Panel lateral paciente */
.paciente-card { padding: 1.4rem; display: flex; flex-direction: column; gap: 1.1rem; }
.paciente-head {
  display: flex; align-items: center; gap: 0.8rem;
  background: linear-gradient(135deg, var(--side-bg), var(--side-bg-2));
  margin: -1.4rem -1.4rem 0; padding: 1.2rem 1.4rem;
  border-radius: var(--r-lg) var(--r-lg) 0 0;
}
.paciente-emoji {
  width: 48px; height: 48px; flex: none; object-fit: cover;
  background: rgba(255, 255, 255, 0.12); border-radius: 50%;
}
.paciente-head-info { min-width: 0; flex: 1; }
.paciente-nombre { color: #fff; font-size: var(--t-2xl); margin: 0; }
.paciente-nombre.editable { display: inline-block; border-bottom: 1px dashed transparent; }
.paciente-nombre.editable:hover { border-color: rgba(255, 255, 255, 0.6); }
.input-nombre { font-size: var(--t-lg); font-weight: 600; width: 100%; }
.paciente-dueno { color: rgba(255, 255, 255, 0.72); font-size: var(--t-sm); }

.datos-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding-top: 0.9rem; border-top: 1px solid var(--line); }
.datos-grid > div { display: flex; flex-direction: column; gap: 0.2rem; }
.dato-bloque { display: flex; flex-direction: column; gap: 0.2rem; padding-top: 0.9rem; border-top: 1px solid var(--line); }
.dato-label { display: block; font-size: var(--t-micro); color: var(--ink-soft); font-weight: 600; }
.dato-valor { display: block; font-size: var(--t-base); font-weight: 500; }
.dato-peso { color: var(--accent-strong); font-weight: 700; font-size: var(--t-lg); }
.dato-edad { display: flex; flex-direction: column; gap: 0.1rem; }
.dato-edad-principal { font-weight: 600; }
.dato-edad-fecha { font-size: var(--t-micro); color: var(--ink-soft); font-weight: 400; }
.dato-link { color: var(--brand-ink); }
.cap { text-transform: capitalize; }
.alergia { color: var(--warn-ink); font-weight: 600; }
.editable { cursor: pointer; border-bottom: 1px dashed var(--line-strong); }
.editable:hover { color: var(--accent-strong); border-color: var(--accent); }
.input-inline { padding: 0.35em 0.6em; font-size: var(--t-base); }

.historial-lista { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.6rem; }
.historial-item { padding: 0.6rem 0; border-bottom: 1px solid var(--line-tabla); }
.historial-item:last-child { border-bottom: none; }
.historial-fecha { font-weight: 600; font-size: var(--t-sm); color: var(--ink-soft); margin-bottom: 0.2rem; }
.historial-datos { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; font-size: var(--t-base); }
.historial-subtitulo { font-size: var(--t-base); margin: 1rem 0 0.4rem; color: var(--ink-soft); }
.compras-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.7rem; }
.compras-nota { margin: 0; color: var(--ink-soft); font-size: var(--t-sm); }
.compras-acciones { display: flex; align-items: center; gap: 0.5rem; }
.historial-compras { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.3rem; font-size: var(--t-sm); }
.historial-compras li { display: flex; gap: 0.6rem; }
.historial-compras .muted, .historial-compras span:first-child { color: var(--ink-soft); }

.chip { padding: 0.2em 0.7em; border-radius: 999px; font-size: var(--t-micro); font-weight: 600; }
.chip-ok { background: var(--ok-tint); color: var(--ok); }
.chip-danger { background: var(--danger-tint); color: var(--danger); }
.chip-info { background: var(--brand-tint); color: var(--brand-ink); }
</style>
