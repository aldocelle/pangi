<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import RespaldoDatasets from './RespaldoDatasets.vue';
import { api, apiError } from '../api.js';
import { useAuthStore } from '../stores/auth.js';

/* Este componente vive en dos formas: como modal flotante (como nació) y
   embebido dentro de la página de Ajustes, que es donde se reunió toda la
   configuración. Embebido pierde el fondo oscuro, el rol de diálogo, la cruz
   de cerrar y su propia barra de pestañas: la sección la manda la página, que
   la lleva en la URL. */
const props = defineProps({
  embebido: { type: Boolean, default: false },
  seccion: { type: String, default: '' },
});

const emit = defineEmits(['close']);

const auth = useAuthStore();
const esAdmin = computed(() => auth.user?.rol === 'admin');

// Base pública del portal de reservas: mismo host, puerto del portal (3001 en dev).
const baseReservas = computed(() => {
  // `VITE_RESERVA_URL` como segunda opción: es la que SÍ trae el
  // `.env.production`, y sin ella el respaldo de abajo arma la URL con el
  // dominio del propio panel — en producción mostraba «panel.…/» como página
  // de reservas (visto el 14-sep-2026).
  const env = import.meta.env.VITE_PORTAL_URL || import.meta.env.VITE_RESERVA_URL;
  if (env) return env.replace(/\/$/, '') + '/';
  const { protocol, hostname, port } = window.location;
  return port ? `${protocol}//${hostname}:3101/` : `${protocol}//${hostname}/`;
});

// Sin "Reminders" (era texto estático, nada configurable) ni "Team Access"
// (lista de solo lectura redundante con Equipo, que ahora además muestra
// permisos por persona) — quedan solo pestañas donde hay algo real que
// tocar. Ver backend/TODO_PERMISOS_AUDITORIA.md para el porqué.
const TABS = [
  { id: 'general', label: 'General', icon: '🏪' },
  { id: 'wording', label: 'Wording', icon: '📝' },
  { id: 'servicios', label: 'Servicios', icon: '💼' },
  { id: 'integraciones', label: 'Integraciones', icon: '🔌' },
  { id: 'datos', label: 'Datos', icon: '💾' },
];

const tab = ref('general');

const ajustes = ref(null);
const loading = ref(true);
const busy = ref(false);
const okMsg = ref('');
const error = ref('');

/* Servicios: asignar especialistas a cada uno */
const servicios = ref([]);
const especialistasDisponibles = ref([]);
const serviciosCargados = ref(false);
const serviciosCargando = ref(false);
const serviciosError = ref('');
const guardandoServicio = ref(false);
/* Un servicio abierto a la vez. Con las 9 listas desplegadas el panel se
   volvía una columna de 3000px con scrolls anidados que se peleaban. */
const svcAbierto = ref(null);

async function alternarServicio(id, ev) {
  const abriendo = svcAbierto.value !== id;
  svcAbierto.value = abriendo ? id : null;
  if (!abriendo) return;
  /* Al abrir uno de los últimos, su lista queda bajo el borde del modal.
     Se acerca sola en vez de obligar a buscarla. */
  await nextTick();
  ev?.currentTarget?.parentElement?.scrollIntoView({
    block: 'nearest',
    behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
  });
}

function atiende(svc, espId) {
  return (svc.especialista_ids || []).includes(espId);
}

function alternarEspecialista(svc, espId) {
  const actuales = svc.especialista_ids || [];
  svc.especialista_ids = actuales.includes(espId)
    ? actuales.filter((id) => id !== espId)
    : [...actuales, espId];
  guardarServicio(svc);
}

/* Integraciones → Google Calendar de la clínica (uno solo, no por especialista) */
const google = ref({ configurado: false, conectado: false, cuenta: null, calendario_reservas: null, instrucciones: '' });
const calendarios = ref([]);
const googleCargado = ref(false);
const googleCargando = ref(false);
const googleError = ref('');
const guardandoCalendario = ref(false);

async function cargarGoogle() {
  if (googleCargado.value || googleCargando.value) return;
  googleCargando.value = true;
  googleError.value = '';
  try {
    const { data } = await api.get('/google/status');
    google.value = data;
    googleCargado.value = true;
    if (data.conectado) await cargarCalendarios();
  } catch (e) {
    googleError.value = apiError(e, 'No se pudo cargar el estado de Google Calendar.');
  } finally {
    googleCargando.value = false;
  }
}

async function cargarCalendarios() {
  try {
    const { data } = await api.get('/google/calendars');
    calendarios.value = data;
  } catch {
    calendarios.value = [];
  }
}

async function conectarGoogle() {
  googleError.value = '';
  try {
    const { data } = await api.get('/google/auth-url');
    window.open(data.url, '_blank', 'width=520,height=640');
  } catch (e) {
    googleError.value = apiError(e, 'Google no está configurado todavía.');
  }
}

async function refrescarGoogle() {
  googleCargado.value = false;
  await cargarGoogle();
}

async function cargarServicios() {
  if (serviciosCargados.value || serviciosCargando.value) return;
  serviciosCargando.value = true;
  serviciosError.value = '';
  try {
    const [s, e] = await Promise.all([
      api.get('/catalogo/servicios'),
      api.get('/catalogo/especialistas'),
    ]);
    servicios.value = s.data;
    /* Deduplica especialistas por nombre (hay duplicados en la BD).
       Mantiene el primer ID de cada nombre único. */
    const vistos = new Set();
    especialistasDisponibles.value = e.data.filter(esp => {
      const clave = esp.nombre.toLowerCase();
      if (vistos.has(clave)) return false;
      vistos.add(clave);
      return true;
    });
    serviciosCargados.value = true;
  } catch (err) {
    serviciosError.value = apiError(err, 'No se pudieron cargar los servicios.');
  } finally {
    serviciosCargando.value = false;
  }
}

async function guardarServicio(svc) {
  guardandoServicio.value = true;
  serviciosError.value = '';
  try {
    const payload = {
      nombre: svc.nombre,
      descripcion: svc.descripcion,
      emoji: svc.emoji,
      duracion_min: svc.duracion_min,
      precio_clp: svc.precio_clp,
      activo: svc.activo,
      orden: svc.orden,
      especialista_ids: svc.especialista_ids || [],
    };
    await api.put(`/catalogo/servicios/${svc.id}`, payload);
    serviciosError.value = '';
  } catch (e) {
    serviciosError.value = apiError(e, 'No se pudo guardar el servicio.');
  } finally {
    guardandoServicio.value = false;
  }
}

async function elegirCalendarioReservas(calendarioId) {
  guardandoCalendario.value = true;
  googleError.value = '';
  try {
    const { data } = await api.put('/google/calendario-reservas', null, { params: { calendario_id: calendarioId } });
    google.value.calendario_reservas = data.calendario_reservas;
  } catch (e) {
    googleError.value = apiError(e, 'No se pudo guardar el calendario.');
  } finally {
    guardandoCalendario.value = false;
  }
}

function irATab(id) {
  tab.value = id;
  if (id === 'servicios') cargarServicios();
  if (id === 'integraciones') cargarGoogle();
}

/* Datos → exportar dump completo (SQL + CSV por tabla, en un ZIP) */
const exportando = ref(false);
const exportError = ref('');

async function exportarDatos() {
  exportando.value = true;
  exportError.value = '';
  try {
    const { data } = await api.get('/exportacion/completa', { responseType: 'blob' });
    const url = URL.createObjectURL(data);
    const a = document.createElement('a');
    a.href = url;
    a.download = `export_${new Date().toISOString().slice(0, 10)}.zip`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch (e) {
    // El error viaja como blob JSON (responseType 'blob'), hay que leerlo aparte.
    if (e?.response?.data instanceof Blob) {
      try {
        const texto = await e.response.data.text();
        exportError.value = JSON.parse(texto)?.detail || 'No se pudo generar la exportación.';
      } catch {
        exportError.value = 'No se pudo generar la exportación.';
      }
    } else {
      exportError.value = apiError(e, 'No se pudo generar la exportación.');
    }
  } finally {
    exportando.value = false;
  }
}

async function cargar() {
  loading.value = true;
  try {
    const { data } = await api.get('/catalogo/ajustes');
    /* Las razones sociales se normalizan al cargar: el backend las manda
       desde sus defaults, pero una fila guardada antes de que existieran
       podría traerlas a medias, y un v-model contra undefined.nombre revienta
       la pantalla entera de ajustes. */
    for (const k of ['razon_social_productos', 'razon_social_servicios']) {
      data[k] = { nombre: '', rut: '', ...(data[k] || {}) };
    }
    ajustes.value = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar los ajustes.');
  } finally {
    loading.value = false;
  }
}

async function guardar() {
  busy.value = true;
  okMsg.value = '';
  error.value = '';
  try {
    /* Solo envía los campos que el backend acepta. El modelo AjustesIn rechaza
       campos extras con extra="forbid" para evitar basura en la BD. */
    const a = ajustes.value;
    const payload = {
      nombre: a.nombre,
      url_slug: a.url_slug,
      idioma: a.idioma,
      zona_horaria: a.zona_horaria,
      wording: a.wording,
    };
    await api.put('/catalogo/ajustes', payload);
    okMsg.value = 'Cambios guardados.';
    setTimeout(() => (okMsg.value = ''), 2500);
  } catch (e) {
    error.value = apiError(e, 'No se pudieron guardar los ajustes.');
  } finally {
    busy.value = false;
  }
}

/* Embebido, la sección la fija la página contenedora. `immediate` porque al
   entrar directo a /ajustes/integraciones hay que disparar su carga perezosa
   igual que si se hubiera hecho clic en la pestaña.

   Va acá abajo y no junto a `tab`, que es donde estaría más a mano, JUSTO por
   ese `immediate`: se ejecuta durante el setup, y `irATab` lee los refs de las
   cargas perezosas (`serviciosCargando`, `googleCargado`…). Declarado más
   arriba, esos refs todavía no existen y el watch muere con «Cannot access
   before initialization» — sin romper la pantalla, pero dejando las dos
   secciones perezosas sin cargar nunca sus datos. */
watch(() => props.seccion, (nueva) => {
  if (props.embebido && nueva && nueva !== tab.value) irATab(nueva);
}, { immediate: true });

onMounted(cargar);
</script>

<template>
  <div
    :class="embebido ? 'aj-suelto' : 'modal-backdrop'"
    @click.self="embebido || emit('close')"
  >
    <div
      class="aj-modal" :class="embebido ? 'aj-modal--plano' : 'modal'"
      :role="embebido ? null : 'dialog'"
      :aria-modal="embebido ? null : 'true'"
      aria-label="Config"
    >
      <!-- Embebido no lleva pestañas propias: la página de Ajustes ya tiene su
           menú lateral con TODAS las secciones, y dos niveles de pestañas para
           lo mismo obligan a recordar en cuál de los dos estaba cada cosa. -->
      <div v-if="!embebido" class="aj-tabs" role="tablist">
        <button
          v-for="t in TABS" :key="t.id"
          role="tab" :aria-selected="tab === t.id"
          class="aj-tab" :class="{ active: tab === t.id }"
          @click="irATab(t.id)"
        >
          <span aria-hidden="true">{{ t.icon }}</span>
          {{ t.label }}
        </button>
        <button class="modal-close aj-close" aria-label="Cerrar" @click="emit('close')">✕</button>
      </div>

      <p v-if="loading" class="empty">Cargando ajustes…</p>

      <template v-else-if="ajustes">
       <fieldset class="aj-fieldset" :disabled="!esAdmin">
        <!-- General -->
        <div v-if="tab === 'general'" class="aj-body">
          <div class="field">
            <label for="a-nombre">Nombre</label>
            <input id="a-nombre" class="input" v-model="ajustes.nombre" />
          </div>
          <div class="field">
            <label for="a-slug">URL de la página de reservas</label>
            <div class="slug-row">
              <span class="slug-fix">{{ baseReservas }}</span>
              <input id="a-slug" class="input" v-model="ajustes.url_slug" />
            </div>
          </div>
          <div class="row-2">
            <div class="field">
              <label for="a-idioma">Idioma</label>
              <select id="a-idioma" class="select" v-model="ajustes.idioma">
                <option>Español</option>
                <option>English</option>
              </select>
            </div>
            <div class="field">
              <label for="a-tz">Zona horaria</label>
              <!-- No es un <select>: hoy el sistema solo opera en
                   America/Santiago (fechas como strings manuales, sin
                   soporte multi-zona horaria — ver CLAUDE.md §9.6). Un
                   dropdown con una sola opción no es un ajuste, es una
                   etiqueta disfrazada. -->
              <p id="a-tz" class="tz-fija">America/Santiago</p>
            </div>
          </div>

          <!-- Las dos empresas con que factura la clínica. Solo rotulan el
               desglose de una venta: el sistema no emite documentos
               tributarios ni habla con el SII. -->
          <fieldset class="razon-set">
            <legend>Razones sociales</legend>
            <p class="aj-note">
              La clínica factura los productos con una empresa y los servicios con otra.
              Al cobrar una venta que mezcla las dos, el panel muestra cuánto le toca a
              cada una. Si se dejan en blanco, dice «Productos» y «Servicios».
            </p>
            <div class="row-2">
              <div class="field">
                <label for="a-rs-prod">Empresa que emite los productos</label>
                <input id="a-rs-prod" class="input" v-model="ajustes.razon_social_productos.nombre" placeholder="Razón social" />
                <input class="input rs-rut" v-model="ajustes.razon_social_productos.rut" placeholder="RUT" aria-label="RUT de la empresa de productos" />
              </div>
              <div class="field">
                <label for="a-rs-serv">Empresa que emite los servicios</label>
                <input id="a-rs-serv" class="input" v-model="ajustes.razon_social_servicios.nombre" placeholder="Razón social" />
                <input class="input rs-rut" v-model="ajustes.razon_social_servicios.rut" placeholder="RUT" aria-label="RUT de la empresa de servicios" />
              </div>
            </div>
          </fieldset>
        </div>

        <!-- Wording -->
        <div v-if="tab === 'wording'" class="aj-body">
          <p class="aj-hint">Personaliza los títulos que ven tus clientes al reservar.</p>
          <div class="field">
            <label for="w-global">Global (encabezado de la página)</label>
            <textarea id="w-global" class="textarea" v-model="ajustes.wording.global"></textarea>
          </div>
          <div class="row-2">
            <div class="field">
              <label for="w-consultas">Consultas</label>
              <input id="w-consultas" class="input" v-model="ajustes.wording.consultas" />
            </div>
            <div class="field">
              <label for="w-esp">Especialistas</label>
              <input id="w-esp" class="input" v-model="ajustes.wording.especialistas" />
            </div>
            <div class="field">
              <label for="w-times">Horario</label>
              <input id="w-times" class="input" v-model="ajustes.wording.times" />
            </div>
            <div class="field">
              <label for="w-client">Datos del cliente</label>
              <input id="w-client" class="input" v-model="ajustes.wording.client" />
            </div>
            <div class="field">
              <label for="w-review">Revisión</label>
              <input id="w-review" class="input" v-model="ajustes.wording.review" />
            </div>
            <div class="field">
              <label for="w-completed">Confirmación</label>
              <input id="w-completed" class="input" v-model="ajustes.wording.completed" />
            </div>
          </div>

          <!-- Cuándo se paga. Van aparte de los títulos de arriba porque no
               son rótulos de paso: son la frase que el cliente lee justo antes
               de decidir, y la clínica las cambia por otro motivo (cambió cómo
               cobra, no cómo se llama la pantalla). -->
          <p class="aj-hint aj-hint-sep">
            Cuándo se paga. El portal elige una según lo que cobre cada consulta,
            configurado en <strong>Tipos de consulta</strong>. Se muestran en el paso
            de datos, antes de confirmar.
          </p>
          <div class="field">
            <label for="w-pago-atencion">Se paga en la clínica</label>
            <input id="w-pago-atencion" class="input" maxlength="200" v-model="ajustes.wording.pago_en_atencion" />
            <small class="campo-nota">
              Para las consultas que no cobran nada en línea: Consulta General,
              Corte de uñas, domicilio, exámenes.
            </small>
          </div>
          <div class="field">
            <label for="w-pago-total">Se paga completa por adelantado</label>
            <input id="w-pago-total" class="input" maxlength="200" v-model="ajustes.wording.pago_total_anticipado" />
            <small class="campo-nota">Las telemedicinas: se pagan enteras antes de la videollamada.</small>
          </div>
          <div class="field">
            <label for="w-pago-abono">Abono ahora, saldo en la clínica</label>
            <input id="w-pago-abono" class="input" maxlength="200" v-model="ajustes.wording.pago_abono_saldo" />
            <small class="campo-nota">
              Cuando el total todavía no se sabe al reservar: Radiología (depende de
              cuántas placas salgan) y Peluquería (del peso y el pelaje).
            </small>
          </div>
        </div>

        <!-- Servicios -->
        <div v-if="tab === 'servicios'" class="aj-body">
          <!-- «Todos» a secas se leía como falso: al abrir una consulta sin
               nadie marcado, la lista entera sin tildar parece «no la atiende
               nadie» (pasó el 13-sep-2026 con Consulta General), y en la
               reserva tampoco salen todos: salen los que además tienen un
               HORARIO que acepta esa consulta. Se dice las dos cosas. -->
          <p class="svc-hint">
            Asigna qué especialistas atienden cada tipo de consulta.
            <br><small>Sin nadie marcado = la puede atender cualquier especialista
            activo. En la reserva aparecen solo los que además tienen un horario
            para esa consulta.</small>
          </p>

          <p v-if="serviciosCargando" class="empty">Cargando…</p>
          <p v-else-if="serviciosError" class="error-text">{{ serviciosError }}</p>

          <div v-else class="svc-lista">
            <div v-for="svc in servicios" :key="svc.id" class="servicio-card">
              <button
                type="button" class="servicio-header"
                :aria-expanded="svcAbierto === svc.id"
                @click="alternarServicio(svc.id, $event)"
              >
                <span class="svc-flecha" :class="{ abierta: svcAbierto === svc.id }" aria-hidden="true">›</span>
                <span class="svc-id">
                  <strong>{{ svc.emoji }} {{ svc.nombre }}</strong>
                  <small>{{ svc.duracion_min }} min · {{ svc.precio_clp ? svc.precio_clp.toLocaleString('es-CL') + ' CLP' : 'Sin precio' }}</small>
                </span>
                <span class="svc-count" :class="{ todos: !(svc.especialista_ids || []).length }">
                  {{ (svc.especialista_ids || []).length || 'Todos' }}
                </span>
              </button>

              <div v-if="svcAbierto === svc.id" class="especialistas-list">
                <p v-if="!(svc.especialista_ids || []).length" class="svc-hint">
                  <small>Nadie marcado: no es «ninguno», es «cualquiera con horario para esta consulta».</small>
                </p>
                <label v-for="esp in especialistasDisponibles" :key="esp.id" class="esp-check">
                  <input
                    type="checkbox"
                    :checked="atiende(svc, esp.id)"
                    @change="alternarEspecialista(svc, esp.id)"
                  />
                  <span>{{ esp.nombre }}</span>
                </label>
              </div>
            </div>
          </div>
        </div>

       </fieldset>

        <!-- Integraciones -->
        <div v-if="tab === 'integraciones'" class="aj-body">
          <p class="aj-hint">Integraciones (pagos, calendarios, webhooks).</p>

          <div class="google-resumen">
            <h4 class="google-titulo">Google Calendar de la clínica</h4>
            <p class="aj-note">
              Un solo calendario para todas las reservas, sin importar el especialista
              (cada evento indica en su título quién atiende). Cada especialista puede
              además marcar un calendario personal de conflicto desde su propia ficha.
            </p>

            <p v-if="googleCargando" class="empty">Cargando…</p>
            <p v-else-if="!google.configurado" class="empty">
              {{ google.instrucciones }}
            </p>

            <template v-else>
              <div v-if="google.conectado" class="google-item">
                <span class="google-estado ok">✓ {{ google.cuenta?.email }}</span>
                <!-- Desconectar se movió a Sysop: corta Calendar para todos. -->
                <span class="aj-note">Para desconectarla, pídelo a soporte técnico.</span>
              </div>
              <div v-else class="google-item">
                <span class="google-estado pendiente">Sin conectar</span>
                <button type="button" class="btn btn-primary btn-sm" @click="conectarGoogle" :disabled="!esAdmin">
                  Conectar cuenta
                </button>
                <button type="button" class="btn btn-ghost btn-sm" @click="refrescarGoogle">↻</button>
              </div>

              <div v-if="google.conectado" class="field cal-reservas-field">
                <label for="cal-reservas">Calendario donde se crean las reservas</label>
                <select
                  id="cal-reservas" class="select"
                  :value="google.calendario_reservas"
                  :disabled="!esAdmin || guardandoCalendario"
                  @change="elegirCalendarioReservas($event.target.value)"
                >
                  <option :value="null">— Elegir calendario —</option>
                  <option v-for="c in calendarios" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                </select>
              </div>
            </template>

            <p v-if="googleError" class="error-text">{{ googleError }}</p>
          </div>

          <p class="aj-note">
            Este sistema es propio: cuando quieras conectar pagos (Webpay, Stripe)
            u otros webhooks, se desarrollan aquí sin costos de plan premium.
            Pídemelo cuando lo necesites.
          </p>
        </div>

        <!-- Datos -->
        <div v-if="tab === 'datos'" class="aj-body">
          <p class="aj-hint">Respaldo completo de los datos de la clínica.</p>

          <div class="datos-export">
            <div class="datos-info">
              <strong>Exportar todo</strong>
              <p class="aj-note">
                Descarga un .zip con un respaldo completo de la base de datos
                (<code>dump_completo.sql</code>, restaurable con <code>mysql</code>) y
                un CSV individual por cada tabla (clientes, mascotas, fichas clínicas,
                notas, citas, especialistas, servicios, inventario, ventas y transacciones).
              </p>
            </div>
            <button
              type="button" class="btn btn-primary"
              :disabled="!esAdmin || exportando"
              @click="exportarDatos"
            >
              {{ exportando ? 'Generando…' : '⬇ Descargar respaldo (.zip)' }}
            </button>
            <p v-if="exportError" class="error-text">{{ exportError }}</p>
            <RespaldoDatasets :habilitado="esAdmin" />
            <!-- El importador de ACVet se movió a Sysop: escribe fichas, ventas
                 y datos de tutores, y solo se usa en una migración. -->
          </div>
        </div>

        <!-- Solo en las pestañas que editan la fila de ajustes. En
             «servicios» cada consulta se guarda con su propio botón, en
             «integraciones» se guarda al elegir calendario y en «datos» no hay
             nada que guardar: ahí un «Guardar ajustes» promete algo que no
             hace. -->
        <div class="aj-foot" v-if="['general', 'wording'].includes(tab)">
          <span v-if="!esAdmin" class="aj-note aj-solo-admin">Solo un administrador puede editar los ajustes.</span>
          <span v-if="okMsg" class="ok-msg">{{ okMsg }}</span>
          <span v-if="error" class="error-text">{{ error }}</span>
          <button v-if="esAdmin" class="btn btn-primary" :disabled="busy" @click="guardar">
            {{ busy ? 'Guardando…' : 'Guardar ajustes' }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.aj-modal { max-width: 740px; max-height: 88vh; padding: 0; overflow: hidden; display: flex; flex-direction: column; }
/* Embebido: sin caja flotante, sin tope de alto (el scroll lo lleva la página)
   y sin el ancho máximo del modal, que dentro de la página se leía como una
   columna angosta con dos márgenes enormes al lado. */
.aj-suelto { display: contents; }
.aj-modal--plano {
  max-width: none; max-height: none; overflow: visible;
  background: transparent; box-shadow: none; border: 0; border-radius: 0;
}
.aj-modal--plano .aj-body { padding: 0; overflow: visible; }
.aj-tabs {
  display: flex; align-items: stretch;
  background: var(--side-bg);
  padding: 0 0.5rem;
  flex-shrink: 0;
  overflow-x: auto;
}
.aj-tab {
  display: flex; flex-direction: column; align-items: center; gap: 0.2rem;
  padding: 0.9rem 1rem;
  font-size: var(--t-micro); font-weight: 600;
  color: oklch(0.8 0.02 128);
  border-bottom: 3px solid transparent;
}
.aj-tab:hover { color: white; }
.aj-tab.active { color: white; border-bottom-color: var(--brand); background: oklch(1 0 0 / 0.07); }
.aj-close { margin-left: auto; align-self: center; color: oklch(0.8 0.02 128); }
.aj-close:hover { color: white; background: oklch(1 0 0 / 0.1); }

.aj-body { padding: 1rem 1.2rem; display: flex; flex-direction: column; gap: 0.6rem; overflow-y: auto; flex: 1; min-height: 0; }
.aj-hint { color: var(--ink-soft); font-size: var(--t-base); text-align: center; }
/* Embebido en la página de Ajustes la columna es ancha y el texto centrado
   queda flotando sobre títulos alineados a la izquierda. Centrarlo solo tenía
   sentido dentro del modal angosto. */
.aj-modal--plano .aj-hint { text-align: left; }
.aj-note {
  font-size: var(--t-sm); color: var(--ink-soft);
  background: var(--bg); border: 1px solid var(--line);
  border-radius: var(--r-md); padding: 0.8rem 1rem;
}
.row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem; }
@media (max-width: 560px) { .row-2 { grid-template-columns: 1fr; } }
.slug-row { display: flex; align-items: center; gap: 0.4rem; }
.slug-fix { font-size: var(--t-sm); color: var(--ink-soft); white-space: nowrap; }
.tz-fija {
  font-size: var(--t-base); color: var(--ink-soft);
  background: var(--bg); border: 1px solid var(--line);
  border-radius: var(--r-md); padding: 0.55em 0.8em; margin: 0;
}

.aj-fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
.razon-set { border: 1px solid var(--line); border-radius: var(--r-md); padding: 0.6rem 0.9rem 0.9rem; margin: 0.6rem 0 0; min-width: 0; }
.razon-set legend { font-size: var(--t-micro); font-weight: 700; color: var(--ink-soft); padding: 0 0.4em; text-transform: uppercase; letter-spacing: 0.05em; }
.rs-rut { margin-top: 0.4rem; }
.aj-fieldset:disabled { opacity: 0.6; }

.google-resumen { display: flex; flex-direction: column; gap: 0.6rem; }
.google-titulo { font-size: var(--t-base); }
.google-lista { list-style: none; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
.google-item {
  display: flex; align-items: center; gap: 0.6rem;
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--line); border-radius: var(--r-md);
  font-size: var(--t-base);
}
.google-nombre { font-weight: 600; flex: 1; }
.google-estado { flex: 1; }
.google-estado.ok { color: var(--ok); font-weight: 600; }
.google-estado.pendiente { color: var(--ink-soft); }
.cal-reservas-field { margin-top: 0.4rem; }
.aj-solo-admin { font-size: var(--t-sm); color: var(--ink-soft); margin-right: auto; }

.datos-export { display: flex; flex-direction: column; gap: 1rem; align-items: flex-start; }
.datos-info strong { display: block; margin-bottom: 0.4rem; }
.datos-export code {
  background: var(--bg); border: 1px solid var(--line); border-radius: 5px;
  padding: 0.1em 0.4em; font-size: 0.85em;
}

.svc-hint {
  color: var(--ink-soft); font-size: var(--t-base); text-align: left;
  background: var(--bg); border: 1px solid var(--line);
  padding: 0.7rem 1rem; border-radius: var(--r-md); margin-bottom: 0.8rem;
}
.svc-hint small { display: block; margin-top: 0.3rem; font-size: var(--t-micro); }

/* Acordeón: la lista de servicios cabe entera en pantalla y solo el
   servicio abierto despliega sus especialistas. El scroll es uno solo,
   el del cuerpo del modal. */
.svc-lista {
  border: 1px solid var(--line); border-radius: var(--r-md); overflow: hidden;
}
.servicio-card + .servicio-card { border-top: 1px solid var(--line-tabla); }

.servicio-header {
  width: 100%; padding: 0.75rem 0.9rem;
  display: flex; align-items: center; gap: 0.75rem;
  text-align: left; background: none; cursor: pointer;
  transition: background 0.12s;
}
.servicio-header:hover { background: var(--accent-hover); }
.servicio-header:focus-visible { outline: 2px solid var(--accent); outline-offset: -2px; }

.svc-flecha {
  flex: none; color: var(--ink-soft); font-size: 1.1rem; line-height: 1;
  transition: transform 0.15s;
}
.svc-flecha.abierta { transform: rotate(90deg); }

.svc-id { flex: 1; min-width: 0; display: flex; flex-direction: column; line-height: 1.3; }
.svc-id strong { font-size: var(--t-base); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.svc-id small { font-size: var(--t-micro); color: var(--ink-soft); }

.svc-count {
  flex: none; font-weight: 600; font-size: var(--t-micro);
  color: var(--brand); background: var(--brand-tint);
  padding: 0.25rem 0.6rem; border-radius: 999px;
}
/* "Todos" es el estado por defecto, no un conteo: se lee más apagado. */
.svc-count.todos { color: var(--ink-soft); background: var(--bg); }

.especialistas-list {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 0.15rem 0.8rem;
  padding: 0.5rem 0.9rem 0.9rem 2.4rem;
  border-top: 1px solid var(--line);
  background: var(--bg);
}
.esp-check {
  display: flex; align-items: center; gap: 0.5rem; cursor: pointer;
  font-size: var(--t-sm); padding: 0.3rem 0.4rem;
  border-radius: var(--r-sm); transition: background 0.12s;
}
.esp-check:hover { background: var(--surface); }
.esp-check input { cursor: pointer; flex: none; }
.esp-check span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

@media (prefers-reduced-motion: reduce) {
  .svc-flecha, .servicio-header, .esp-check { transition: none; }
}

.aj-foot {
  display: flex; align-items: center; justify-content: flex-end; gap: 1rem;
  padding: 1rem 1.5rem;
  border-top: 1px solid var(--line);
  flex-shrink: 0;
}
.ok-msg { color: var(--ok); font-size: var(--t-base); font-weight: 500; }
</style>
