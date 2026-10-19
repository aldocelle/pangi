<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { api, apiError, urlFoto } from '../api.js';
import { useAuthStore } from '../stores/auth.js';
import HistorialVentas from '../components/HistorialVentas.vue';
import ConfirmarBorrado from '../components/ConfirmarBorrado.vue';
import Paginador from '../components/Paginador.vue';
import BuscadorAvanzado from '../components/BuscadorAvanzado.vue';
import ColumnaOrdenable from '../components/ColumnaOrdenable.vue';
import TogglePersonas from '../components/TogglePersonas.vue';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();

/* El historial sale de /transacciones, que exige el permiso "ventas": sin
   él la sección solo mostraría un 403. El sidebar ya esconde Ventas a quien
   no lo tiene, así que esconder también su historial es coherente. */
const puedeVerVentas = computed(() => auth.puede('ventas'));

// El backend genera un correo placeholder (@interno.clinica) cuando el
// cliente se crea solo con nombre + teléfono; no es un contacto real y no
// tiene sentido mostrárselo al staff como si lo fuera.
function mostrarEmail(email) {
  return email && email.endsWith('@interno.clinica') ? 'Sin correo registrado' : email;
}

const CAMPOS_BUSQUEDA = [
  { key: 'q', label: 'Nombre o correo', placeholder: 'Buscar por nombre o correo…' },
  { key: 'telefono', label: 'Teléfono', placeholder: '+56 9 …', autocompletar: false },
  { key: 'rut', label: 'RUT', placeholder: '12.345.678-9', autocompletar: false },
  { key: 'mascota', label: 'Mascota', placeholder: 'Nombre de la mascota…' },
];

const clientes = ref([]);
const loading = ref(true);
const error = ref('');
const filtros = ref({ q: '', telefono: '', rut: '', mascota: '' });
const pagina = ref(1);
const paginacion = ref({ total: 0, total_paginas: 1, limit: 30 });
const orden = ref('nombre');
const solicitudes = ref([]);   // recuperaciones de clave pendientes

function ordenarPor(campo) {
  orden.value = campo;
  pagina.value = 1;
  cargar();
}

const seleccion = ref(null);   // detalle del cliente abierto
const detalle = ref(null);

/* Modal crear cliente */
const modal = ref(false);
const form = ref({ nombre: '', email: '', telefono: '', password: '' });
const creando = ref(false);
const passGenerada = ref(null);  // contraseña temporal a mostrar tras crear
const clienteCreado = ref(null);

/* Modal agregar mascota (la edición ahora ocurre en la ficha del paciente) */
const modalMascota = ref(false);
const formMascota = ref({ nombre: '', especie: 'perro', raza: '', sexo: 'M' });
const guardandoMascota = ref(false);

/* Modal de borrado definitivo (clientes y mascotas) */
const borrado = ref(null);   // { tipo, nombre, url, impacto }
const borrando = ref(false);
const errorBorrado = ref('');

async function abrirBorrado(tipo, item, url) {
  errorBorrado.value = '';
  borrado.value = { tipo, nombre: item.nombre, url, impacto: null };
  try {
    const { data } = await api.get(`${url}/impacto`);
    if (borrado.value) borrado.value.impacto = data;
  } catch (e) {
    errorBorrado.value = apiError(e, 'No se pudo calcular el impacto.');
  }
}

async function confirmarBorrado(texto) {
  if (!borrado.value) return;
  borrando.value = true;
  errorBorrado.value = '';
  const { tipo, url } = borrado.value;
  try {
    await api.delete(url, { params: { confirmar: texto } });
    if (tipo === 'cliente') {
      detalle.value = null;
      seleccion.value = null;
    } else if (detalle.value) {
      await abrirDetalle({ id: detalle.value.id });
    }
    borrado.value = null;
    await cargar();
  } catch (e) {
    errorBorrado.value = apiError(e, 'No se pudo eliminar.');
  } finally {
    borrando.value = false;
  }
}

/* Modal mover mascota: buscador propio, ya que la lista de clientes en
   pantalla está paginada y puede no incluir al cliente destino. */
const modalMover = ref(false);
const mascotaAMover = ref(null);
const clienteDestino = ref('');
const buscaDestino = ref('');
const resultadosDestino = ref([]);
let debounceDestino;

async function buscarDestino() {
  clearTimeout(debounceDestino);
  debounceDestino = setTimeout(async () => {
    if (!buscaDestino.value.trim()) { resultadosDestino.value = []; return; }
    try {
      const { data } = await api.get('/clientes', { params: { q: buscaDestino.value, limit: 30 } });
      resultadosDestino.value = data.items.filter((c) => c.id !== detalle.value?.id);
    } catch { resultadosDestino.value = []; }
  }, 300);
}

async function cargarSolicitudes() {
  try {
    const { data } = await api.get('/clientes/solicitudes-reset');
    solicitudes.value = data;
  } catch { solicitudes.value = []; }
}

/* ── Derechos sobre datos personales (ley 21.719) ──
   Cola aparte de las de contraseña, aunque las dos sean "algo que el cliente
   pidió": estas tienen RELOJ. La ley cuenta el plazo desde que se recibe la
   solicitud, así que se ordenan de la más antigua a la más nueva —al revés
   que cualquier otro listado del panel— y la fila muestra cuántos días lleva
   esperando. Sin ese dato, una cola de trabajo no dice cuál es la urgente. */
const solicitudesDatos = ref([]);
const resolviendo = ref(null);

const ETIQUETA_TIPO = {
  supresion: 'Eliminar sus datos',
  bloqueo: 'Bloquear el uso de sus datos',
  oposicion: 'Oponerse a un uso',
  rectificacion: 'Corregir un dato',
  otro: 'Otra solicitud',
};

function diasEsperando(iso) {
  if (!iso) return 0;
  return Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
}

async function cargarSolicitudesDatos() {
  try {
    const { data } = await api.get('/clientes/solicitudes-datos');
    solicitudesDatos.value = data;
  } catch { solicitudesDatos.value = []; }
}

async function resolverSolicitudDatos(s, estado) {
  const respuesta = window.prompt(
    estado === 'resuelta'
      ? '¿Qué se hizo? Queda guardado como la respuesta al titular.'
      : '¿Por qué se rechaza? Queda guardado como la respuesta al titular.',
    '',
  );
  if (respuesta === null) return;   // canceló el diálogo
  resolviendo.value = s.id;
  try {
    await api.patch(`/clientes/solicitudes-datos/${s.id}`, { estado, respuesta });
    await cargarSolicitudesDatos();
  } catch (e) {
    error.value = apiError(e, 'No se pudo actualizar la solicitud.');
  } finally {
    resolviendo.value = null;
  }
}

async function cargar() {
  loading.value = true;
  error.value = '';
  cargarSolicitudes();
  cargarSolicitudesDatos();
  try {
    const params = { page: pagina.value, limit: 30, orden: orden.value };
    // El nombre/correo solo filtra desde 2 caracteres: con 1 sola letra el
    // listado completo (miles de clientes) apenas se acota.
    if (filtros.value.q?.trim().length >= 2) params.q = filtros.value.q.trim();
    if (filtros.value.telefono) params.telefono = filtros.value.telefono;
    if (filtros.value.rut) params.rut = filtros.value.rut;
    if (filtros.value.mascota?.trim().length >= 2) params.mascota = filtros.value.mascota.trim();
    const { data } = await api.get('/clientes', { params });
    clientes.value = data.items;
    paginacion.value = { total: data.total, total_paginas: data.total_paginas, limit: data.limit };
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar los clientes.');
  } finally {
    loading.value = false;
  }
}
onMounted(cargar);

let debounce;
function buscar() {
  pagina.value = 1;
  clearTimeout(debounce);
  debounce = setTimeout(cargar, 300);
}

function cambiarPagina(p) {
  pagina.value = p;
  cargar();
}

async function abrirDetalle(c) {
  seleccion.value = c.id;
  detalle.value = null;
  try {
    const { data } = await api.get(`/clientes/${c.id}`);
    detalle.value = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar el detalle.');
  }
}

// Llegar acá desde el buscador global (/clientes?id=...) abre el detalle
// directo, sin obligar a encontrarlo de nuevo en el listado.
if (route.query.id) {
  abrirDetalle({ id: route.query.id });
}

/* ── Edición inline de datos del cliente en el panel de detalle ── */
const editandoCampo = ref(null);
const valorCampo = ref('');
const busyCampo = ref(false);

function abrirEdicionCampo(campo, valorActual) {
  editandoCampo.value = campo;
  valorCampo.value = valorActual ?? '';
}

async function guardarCampo(campo) {
  if (campo === 'nombre' && !valorCampo.value.trim()) {
    editandoCampo.value = null;
    return;
  }
  busyCampo.value = true;
  error.value = '';
  try {
    const valor = valorCampo.value === '' ? null : valorCampo.value;
    const { data } = await api.patch(`/clientes/${detalle.value.id}`, { [campo]: valor });
    detalle.value = { ...detalle.value, ...data };
    const i = clientes.value.findIndex((c) => c.id === detalle.value.id);
    if (i !== -1) clientes.value[i] = { ...clientes.value[i], ...data };
    editandoCampo.value = null;
  } catch (e) {
    error.value = apiError(e, 'No se pudo guardar.');
  } finally {
    busyCampo.value = false;
  }
}

/* ── Crear cliente ── */
function abrirCrear() {
  form.value = { nombre: '', email: '', telefono: '', password: '' };
  passGenerada.value = null;
  clienteCreado.value = null;
  modal.value = true;
}
async function crear() {
  creando.value = true;
  error.value = '';
  try {
    const payload = { ...form.value };
    if (!payload.password) delete payload.password;
    if (!payload.email) delete payload.email;
    const { data } = await api.post('/clientes', payload);
    clienteCreado.value = data;
    passGenerada.value = data.password_temporal || form.value.password;
    await cargar();
  } catch (e) {
    error.value = apiError(e, 'No se pudo crear el cliente.');
  } finally {
    creando.value = false;
  }
}
function cerrarModal() {
  modal.value = false;
  passGenerada.value = null;
  clienteCreado.value = null;
}

/* Modal cambiar contraseña: en blanco = se genera una temporal (como antes);
   con texto = el staff elige una puntual, p. ej. si el cliente la pide por
   teléfono y prefiere algo que va a recordar. */
const modalPassword = ref(false);
const clientePassword = ref(null);
const passwordElegida = ref('');
const passwordResultado = ref(null);
const guardandoPassword = ref(false);
const errorPassword = ref('');

function abrirModalPassword(c) {
  clientePassword.value = c;
  passwordElegida.value = '';
  passwordResultado.value = null;
  errorPassword.value = '';
  modalPassword.value = true;
}

async function confirmarPassword() {
  guardandoPassword.value = true;
  errorPassword.value = '';
  try {
    const payload = passwordElegida.value.trim() ? { password: passwordElegida.value.trim() } : {};
    const { data } = await api.post(`/clientes/${clientePassword.value.id}/reset-password`, payload);
    passwordResultado.value = data.password_temporal;
    await cargarSolicitudes();
  } catch (e) {
    errorPassword.value = apiError(e, 'No se pudo cambiar la contraseña.');
  } finally {
    guardandoPassword.value = false;
  }
}

function cerrarModalPassword() {
  modalPassword.value = false;
  clientePassword.value = null;
}

async function toggleActivo(c) {
  try {
    await api.patch(`/clientes/${c.id}`, { es_activo: !c.es_activo });
    await cargar();
    if (detalle.value?.id === c.id) abrirDetalle(c);
  } catch (e) {
    error.value = apiError(e, 'No se pudo actualizar.');
  }
}

async function eliminarCliente(c) {
  await abrirBorrado('cliente', c, `/clientes/${c.id}`);
}

/* ── Mascotas: agregar / editar (en la ficha) / eliminar / mover ── */
function abrirMascota() {
  formMascota.value = { nombre: '', especie: 'perro', raza: '', sexo: 'M' };
  modalMascota.value = true;
}
function abrirEditarMascota(m) {
  router.push(`/ficha/${m.id}`);
}
async function crearMascota() {
  guardandoMascota.value = true;
  error.value = '';
  try {
    await api.post(`/clientes/${detalle.value.id}/mascotas`, formMascota.value);
    modalMascota.value = false;
    await abrirDetalle({ id: detalle.value.id });
    await cargar();
  } catch (e) {
    error.value = apiError(e, 'No se pudo guardar la mascota.');
  } finally {
    guardandoMascota.value = false;
  }
}
async function eliminarMascota(m) {
  await abrirBorrado('mascota', m, `/clientes/mascotas/${m.id}`);
}
function abrirMover(m) {
  mascotaAMover.value = m;
  clienteDestino.value = '';
  buscaDestino.value = '';
  resultadosDestino.value = [];
  modalMover.value = true;
}
async function moverMascota() {
  if (!clienteDestino.value) return;
  try {
    await api.post(`/clientes/mascotas/${mascotaAMover.value.id}/mover`, { nuevo_cliente_id: clienteDestino.value });
    modalMover.value = false;
    await abrirDetalle({ id: detalle.value.id });
    await cargar();
  } catch (e) {
    alert(apiError(e, 'No se pudo mover la mascota.'));
  }
}

function copiar(txt) {
  navigator.clipboard?.writeText(txt);
}
</script>

<template>
  <div class="page">
    <header class="head-row">
      <div>
        <h1 class="page-title">Clientes</h1>
        <p class="page-sub">Crea y administra las cuentas de tus clientes para el portal.</p>
      </div>
      <div class="head-acciones">
        <TogglePersonas activo="clientes" />
        <button class="btn btn-primary" @click="abrirCrear">+ Nuevo cliente</button>
      </div>
    </header>

    <!-- Solicitudes de recuperación de contraseña pendientes -->
    <div v-if="solicitudes.length" class="reset-banner card">
      <span class="rb-ic">🔑</span>
      <div class="rb-body">
        <strong>{{ solicitudes.length }} solicitud(es) de recuperación de contraseña</strong>
        <p class="muted">
          Estos clientes pidieron recuperar su acceso. Ábrelos y usa "Resetear contraseña" para darles una nueva clave.
        </p>
        <div class="rb-chips">
          <button
            v-for="s in solicitudes" :key="s.id"
            class="rb-chip"
            :disabled="!s.cliente_id"
            @click="s.cliente_id && abrirDetalle({ id: s.cliente_id })"
          >
            {{ s.cliente_nombre || s.email }}
          </button>
        </div>
      </div>
    </div>

    <!-- Derechos sobre datos personales: tienen plazo legal, por eso van
         arriba y ordenadas de la más antigua a la más nueva. -->
    <div v-if="solicitudesDatos.length" class="datos-banner card">
      <span class="rb-ic">⚖</span>
      <div class="rb-body">
        <strong>{{ solicitudesDatos.length }} solicitud(es) sobre datos personales</strong>
        <p class="muted">
          Un cliente ejerció un derecho sobre sus datos. Hay plazo legal para responder,
          contado desde que la envió. Cerrar una solicitud no borra ni bloquea nada por sí
          solo: registra qué se decidió y quién lo decidió.
        </p>
        <ul class="sd-lista">
          <li v-for="s in solicitudesDatos" :key="s.id" class="sd-item">
            <div class="sd-info">
              <span class="sd-tipo">{{ ETIQUETA_TIPO[s.tipo] || s.tipo }}</span>
              <span class="sd-email">{{ s.email }}</span>
              <span class="sd-dias" :class="{ 'sd-dias--alerta': diasEsperando(s.created_at) >= 10 }">
                {{ diasEsperando(s.created_at) }} día(s) esperando
              </span>
              <p v-if="s.detalle" class="sd-detalle">“{{ s.detalle }}”</p>
            </div>
            <div class="sd-acciones">
              <button class="btn btn-ghost btn-sm" :disabled="resolviendo === s.id"
                      @click="resolverSolicitudDatos(s, 'rechazada')">Rechazar</button>
              <button class="btn btn-primary btn-sm" :disabled="resolviendo === s.id"
                      @click="resolverSolicitudDatos(s, 'resuelta')">Marcar resuelta</button>
            </div>
          </li>
        </ul>
      </div>
    </div>

    <BuscadorAvanzado v-model="filtros" :campos="CAMPOS_BUSQUEDA" @buscar="buscar" />

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>
    <p v-else-if="!clientes.length" class="empty">No hay clientes que coincidan con la búsqueda.</p>

    <div v-else class="layout">
      <!-- Lista -->
      <div class="lista">
        <div class="cli-orden">
          <ColumnaOrdenable campo="nombre" :orden-actual="orden" @ordenar="ordenarPor">Nombre</ColumnaOrdenable>
          <ColumnaOrdenable campo="n_mascotas" :orden-actual="orden" @ordenar="ordenarPor">Mascotas</ColumnaOrdenable>
          <ColumnaOrdenable campo="n_citas" :orden-actual="orden" @ordenar="ordenarPor">Citas</ColumnaOrdenable>
        </div>
        <article
          v-for="c in clientes" :key="c.id"
          class="card cli-row" :class="{ sel: seleccion === c.id, off: !c.es_activo }"
          @click="abrirDetalle(c)"
        >
          <!-- La foto que el tutor subió desde el portal; sin ella, la inicial. -->
          <img v-if="c.foto" class="cli-avatar avatar-foto" :src="urlFoto(c.foto)" alt="" />
          <span v-else class="cli-avatar">{{ (c.nombre[0] || 'C').toUpperCase() }}</span>
          <div class="cli-info">
            <strong>{{ c.nombre }}</strong>
            <span class="muted">{{ mostrarEmail(c.email) }}</span>
          </div>
          <div class="cli-tags">
            <span class="tag">🐾 {{ c.n_mascotas }}</span>
            <span class="tag">📅 {{ c.n_citas }}</span>
            <span v-if="!c.es_activo" class="tag off-tag">Inactivo</span>
          </div>
        </article>
        <Paginador
          :page="pagina" :total-paginas="paginacion.total_paginas"
          :total="paginacion.total" :limit="paginacion.limit"
          @cambiar="cambiarPagina"
        />
      </div>

      <!-- Detalle -->
      <aside class="detalle card" v-if="detalle">
        <div class="det-head">
          <img v-if="detalle.foto" class="det-avatar avatar-foto" :src="urlFoto(detalle.foto)" alt="" />
          <span v-else class="det-avatar">{{ (detalle.nombre[0] || 'C').toUpperCase() }}</span>
          <div class="det-head-info">
            <h2
              v-if="editandoCampo !== 'nombre'"
              class="det-nombre editable"
              @click="abrirEdicionCampo('nombre', detalle.nombre)"
            >{{ detalle.nombre }}</h2>
            <input
              v-else class="input input-inline det-input-nombre" v-model="valorCampo" :disabled="busyCampo"
              @keyup.enter="guardarCampo('nombre')" @blur="guardarCampo('nombre')"
            />
            <span
              v-if="editandoCampo !== 'email'"
              class="muted editable"
              @click="abrirEdicionCampo('email', detalle.email?.endsWith('@interno.clinica') ? '' : detalle.email)"
            >{{ mostrarEmail(detalle.email) }}</span>
            <input
              v-else class="input input-inline" type="email" v-model="valorCampo" :disabled="busyCampo"
              @keyup.enter="guardarCampo('email')" @blur="guardarCampo('email')"
            />
          </div>
        </div>

        <dl class="det-datos">
          <div>
            <dt>Teléfono</dt>
            <dd
              v-if="editandoCampo !== 'telefono'"
              class="editable"
              @click="abrirEdicionCampo('telefono', detalle.telefono)"
            >{{ detalle.telefono || '—' }}</dd>
            <dd v-else>
              <input
                class="input input-inline" v-model="valorCampo" :disabled="busyCampo"
                @keyup.enter="guardarCampo('telefono')" @blur="guardarCampo('telefono')"
              />
            </dd>
          </div>
          <div>
            <dt>RUT</dt>
            <dd
              v-if="editandoCampo !== 'rut'"
              class="editable"
              @click="abrirEdicionCampo('rut', detalle.rut)"
            >{{ detalle.rut || '—' }}</dd>
            <dd v-else>
              <input
                class="input input-inline" v-model="valorCampo" :disabled="busyCampo"
                @keyup.enter="guardarCampo('rut')" @blur="guardarCampo('rut')"
              />
            </dd>
          </div>
          <div>
            <dt>Dirección</dt>
            <dd
              v-if="editandoCampo !== 'direccion'"
              class="editable"
              @click="abrirEdicionCampo('direccion', detalle.direccion)"
            >{{ detalle.direccion || '—' }}</dd>
            <dd v-else>
              <input
                class="input input-inline" v-model="valorCampo" :disabled="busyCampo"
                @keyup.enter="guardarCampo('direccion')" @blur="guardarCampo('direccion')"
              />
            </dd>
          </div>
          <div><dt>Estado</dt><dd>
            <span class="chip" :class="detalle.es_activo ? 'chip-ok' : 'chip-off'">
              {{ detalle.es_activo ? 'Activo' : 'Inactivo' }}
            </span>
          </dd></div>
          <div><dt>Citas</dt><dd>{{ detalle.n_citas }}</dd></div>
        </dl>

        <div class="det-acciones">
          <button class="btn btn-ghost btn-sm" @click="abrirModalPassword(detalle)">Cambiar contraseña</button>
          <button class="btn btn-ghost btn-sm" @click="toggleActivo(detalle)">
            {{ detalle.es_activo ? 'Desactivar' : 'Activar' }}
          </button>
          <button class="btn btn-ghost btn-sm btn-danger-ghost" @click="eliminarCliente(detalle)">Eliminar</button>
        </div>

        <div class="det-mascotas">
          <div class="dm-head">
            <h3>Mascotas</h3>
            <button class="btn btn-ghost btn-sm" @click="abrirMascota">+ Agregar</button>
          </div>
          <p v-if="!detalle.mascotas.length" class="muted small">Sin mascotas registradas.</p>
          <ul v-else class="dm-list">
            <li v-for="m in detalle.mascotas" :key="m.id" class="dm-item">
              <div class="dm-info">
                <strong>{{ m.nombre }}</strong>
                <span class="muted">{{ m.especie }}<template v-if="m.raza"> · {{ m.raza }}</template></span>
              </div>
              <div class="dm-acciones">
                <button class="dm-btn" title="Editar" @click="abrirEditarMascota(m)">✏</button>
                <button class="dm-btn" title="Mover a otro dueño" @click="abrirMover(m)">↔</button>
                <button class="dm-btn" title="Eliminar" @click="eliminarMascota(m)">🗑</button>
              </div>
            </li>
          </ul>
        </div>

        <div v-if="puedeVerVentas" class="det-compras">
          <div class="dm-head">
            <h3>Historial de compras</h3>
            <RouterLink
              :to="{ path: '/ventas', query: { propietario_id: detalle.id, etiqueta: detalle.nombre } }"
              class="btn btn-ghost btn-sm"
            >
              Ver en Ventas
            </RouterLink>
          </div>
          <HistorialVentas
            :params="{ propietario_id: detalle.id }"
            vacio="Este cliente todavía no tiene compras registradas."
          />
        </div>
      </aside>
      <aside v-else class="detalle card det-empty">
        <p class="muted">Selecciona un cliente para ver su detalle y mascotas.</p>
      </aside>
    </div>

    <!-- Modal crear cliente -->
    <div v-if="modal" class="backdrop" @click.self="cerrarModal">
      <div class="modal card">
        <template v-if="!passGenerada">
          <h2>Nuevo cliente</h2>
          <p class="muted">Se creará una cuenta para el portal del cliente.</p>
          <form @submit.prevent="crear" class="form">
            <div class="field">
              <label>Nombre completo *</label>
              <input class="input" v-model="form.nombre" required />
            </div>
            <div class="field">
              <label>Teléfono *</label>
              <input class="input" v-model="form.telefono" placeholder="+56 9 ..." required />
            </div>
            <div class="field">
              <label>Correo electrónico <span class="muted">(opcional — sin él, el cliente no puede entrar al portal todavía)</span></label>
              <input class="input" type="email" v-model="form.email" />
            </div>
            <div class="field">
              <label>Contraseña <span class="muted">(opcional — si la dejas vacía, se genera una temporal)</span></label>
              <input class="input" v-model="form.password" placeholder="Se generará automáticamente" />
            </div>
            <p v-if="error" class="error-text">{{ error }}</p>
            <div class="modal-acciones">
              <button type="button" class="btn btn-ghost" @click="cerrarModal">Cancelar</button>
              <button type="submit" class="btn btn-primary" :disabled="creando">
                {{ creando ? 'Creando…' : 'Crear cliente' }}
              </button>
            </div>
          </form>
        </template>

        <!-- Éxito: mostrar credenciales -->
        <template v-else>
          <div class="exito">
            <span class="exito-ic">✓</span>
            <h2>Cliente creado</h2>
            <p v-if="clienteCreado.email?.endsWith('@interno.clinica')" class="muted">
              Se creó sin correo: {{ clienteCreado.nombre }} todavía no puede entrar al portal.
              Agrégale uno real desde su ficha cuando lo tengas.
            </p>
            <template v-else>
              <p class="muted">Comparte estas credenciales con {{ clienteCreado.nombre }}. La contraseña no volverá a mostrarse.</p>
              <div class="cred">
                <div class="cred-row"><span>Correo</span><code>{{ clienteCreado.email }}</code></div>
                <div class="cred-row">
                  <span>Contraseña</span>
                  <code>{{ passGenerada }}</code>
                  <button class="btn btn-ghost btn-sm" @click="copiar(passGenerada)">Copiar</button>
                </div>
              </div>
            </template>
            <div class="modal-acciones">
              <button class="btn btn-primary" @click="cerrarModal">Listo</button>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- Modal cambiar contraseña -->
    <div v-if="modalPassword" class="backdrop" @click.self="cerrarModalPassword">
      <div class="modal card modal-sm">
        <template v-if="!passwordResultado">
          <h2>Cambiar contraseña</h2>
          <p class="muted">Para {{ clientePassword?.nombre }}. La contraseña anterior dejará de funcionar.</p>
          <form @submit.prevent="confirmarPassword" class="form">
            <div class="field">
              <label>Nueva contraseña <span class="muted">(opcional — si la dejas vacía, se genera una temporal)</span></label>
              <input class="input" type="text" v-model="passwordElegida" placeholder="Se generará automáticamente" minlength="8" />
            </div>
            <p v-if="errorPassword" class="error-text">{{ errorPassword }}</p>
            <div class="modal-acciones">
              <button type="button" class="btn btn-ghost" @click="cerrarModalPassword">Cancelar</button>
              <button type="submit" class="btn btn-primary" :disabled="guardandoPassword">
                {{ guardandoPassword ? 'Guardando…' : 'Cambiar contraseña' }}
              </button>
            </div>
          </form>
        </template>

        <template v-else>
          <div class="exito">
            <span class="exito-ic">✓</span>
            <h2>Contraseña actualizada</h2>
            <p class="muted">Comparte esta contraseña con {{ clientePassword?.nombre }}. No volverá a mostrarse.</p>
            <div class="cred">
              <div class="cred-row">
                <span>Contraseña</span>
                <code>{{ passwordResultado }}</code>
                <button class="btn btn-ghost btn-sm" @click="copiar(passwordResultado)">Copiar</button>
              </div>
            </div>
            <div class="modal-acciones">
              <button class="btn btn-primary" @click="cerrarModalPassword">Listo</button>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- Modal agregar mascota -->
    <div v-if="modalMascota" class="backdrop" @click.self="modalMascota = false">
      <div class="modal card modal-sm">
        <h2>Agregar mascota</h2>
        <p class="muted">Para {{ detalle?.nombre }}</p>
        <form @submit.prevent="crearMascota" class="form">
          <div class="field">
            <label>Nombre *</label>
            <input class="input" v-model="formMascota.nombre" required />
          </div>
          <div class="row-2">
            <div class="field">
              <label>Especie</label>
              <select class="select" v-model="formMascota.especie">
                <option value="perro">Perro</option>
                <option value="gato">Gato</option>
                <option value="conejo">Conejo</option>
                <option value="otro">Otro</option>
              </select>
            </div>
            <div class="field">
              <label>Sexo</label>
              <select class="select" v-model="formMascota.sexo">
                <option value="M">Macho</option>
                <option value="F">Hembra</option>
              </select>
            </div>
          </div>
          <div class="field">
            <label>Raza</label>
            <input class="input" v-model="formMascota.raza" />
          </div>
          <div class="modal-acciones">
            <button type="button" class="btn btn-ghost" @click="modalMascota = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="guardandoMascota">
              {{ guardandoMascota ? 'Guardando…' : 'Agregar' }}
            </button>
          </div>
        </form>
      </div>
    </div>


    <!-- Modal mover mascota -->
    <div v-if="modalMover" class="backdrop" @click.self="modalMover = false">
      <div class="modal card modal-sm">
        <h2>Mover mascota</h2>
        <p class="muted">Mueve a <strong>{{ mascotaAMover?.nombre }}</strong> a otro cliente.</p>
        <form @submit.prevent="moverMascota" class="form">
          <div class="field">
            <label>Buscar cliente destino *</label>
            <input class="input" v-model="buscaDestino" @input="buscarDestino" placeholder="Nombre o correo…" />
            <ul v-if="resultadosDestino.length" class="destino-lista">
              <li
                v-for="c in resultadosDestino" :key="c.id"
                class="destino-item" :class="{ sel: clienteDestino === c.id }"
                @click="clienteDestino = c.id"
              >
                {{ c.nombre }} <span class="muted">({{ mostrarEmail(c.email) }})</span>
              </li>
            </ul>
            <p v-else-if="buscaDestino.trim()" class="muted small">Sin resultados.</p>
          </div>
          <div class="modal-acciones">
            <button type="button" class="btn btn-ghost" @click="modalMover = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="!clienteDestino">Mover</button>
          </div>
        </form>
      </div>
    </div>

    <ConfirmarBorrado
      v-if="borrado"
      :nombre="borrado.nombre"
      :titulo="borrado.tipo === 'cliente' ? 'Eliminar cliente' : 'Eliminar paciente'"
      :impacto="borrado.impacto"
      :busy="borrando"
      :error="errorBorrado"
      @confirmar="confirmarBorrado"
      @cancelar="borrado = null"
    />
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.2rem; flex-wrap: wrap; }
.head-acciones { display: flex; align-items: center; gap: 0.8rem; flex-wrap: wrap; }
.head h1 { color: var(--brand); font-size: var(--t-2xl); }
.head .muted { margin-top: 0.2rem; }
.empty { text-align: center; color: var(--ink-soft); padding: 2rem; }
.error-text { color: var(--danger); margin-bottom: 0.8rem; }

.layout { display: grid; grid-template-columns: 1fr 340px; gap: 1.2rem; align-items: start; }
.lista { display: flex; flex-direction: column; gap: 0.7rem; }
.cli-orden {
  display: flex; align-items: center; gap: 1.4rem;
  padding: 0 1rem 0.3rem;
  font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft);
}
.cli-orden > :first-child { margin-right: auto; }
.cli-row { display: flex; align-items: center; gap: 1rem; padding: 1rem; cursor: pointer; transition: border-color 0.15s, box-shadow 0.15s; }
.cli-row:hover { border-color: var(--accent); }
.cli-row.sel { border-color: var(--brand); box-shadow: 0 0 0 2px var(--brand-tint); }
.cli-row.off { opacity: 0.6; }
.cli-avatar { width: 44px; height: 44px; border-radius: 50%; background: var(--brand); color: #fff; font-weight: 700; display: grid; place-items: center; flex: none; }
.cli-info { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.cli-info strong { color: var(--brand); }
.cli-info .muted { font-size: var(--t-sm); }
.cli-tags { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.tag { background: var(--bg); border: 1px solid var(--line); border-radius: 999px; padding: 0.15em 0.7em; font-size: var(--t-micro); }
.off-tag { background: var(--danger-tint); color: var(--danger); border-color: transparent; }

.detalle { padding: 1.3rem; position: sticky; top: 1rem; }
.det-empty { display: grid; place-items: center; min-height: 180px; text-align: center; }
.det-head { display: flex; gap: 0.9rem; align-items: center; margin-bottom: 1.1rem; }
.det-avatar { width: 54px; height: 54px; border-radius: 50%; background: var(--brand); color: #fff; font-weight: 700; font-size: 1.2rem; display: grid; place-items: center; flex: none; }
.avatar-foto { object-fit: cover; background: var(--brand-tint); }
.det-head-info { min-width: 0; flex: 1; display: flex; flex-direction: column; gap: 0.1rem; }
.det-nombre { color: var(--brand); font-size: var(--t-xl); margin: 0; }
.det-input-nombre { font-size: var(--t-lg); font-weight: 600; width: 100%; }
.det-head .muted { font-size: var(--t-sm); }
.det-datos { display: flex; flex-direction: column; gap: 0.7rem; margin-bottom: 1.1rem; }
.det-datos div { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
.det-datos dt { color: var(--ink-soft); font-size: var(--t-sm); flex: none; }
.det-datos dd { margin: 0; font-weight: 500; font-size: var(--t-base); text-align: right; min-width: 0; }
.det-datos dd .input-inline { text-align: left; }
.editable { cursor: pointer; border-bottom: 1px dashed var(--line-strong); }
.editable:hover { color: var(--accent-strong); border-color: var(--accent); }
.input-inline { padding: 0.35em 0.6em; font-size: var(--t-base); }
.chip { padding: 0.2em 0.7em; border-radius: 999px; font-size: var(--t-micro); font-weight: 600; }
.chip-ok { background: var(--ok-tint); color: var(--ok); }
.chip-off { background: var(--danger-tint); color: var(--danger); }
.det-acciones { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.2rem; }
.det-mascotas { border-top: 1px solid var(--line); padding-top: 1rem; }
.det-compras { border-top: 1px solid var(--line); padding-top: 1rem; margin-top: 1.2rem; }
.dm-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.7rem; }
.dm-head h3 { font-size: var(--t-base); color: var(--brand); }
.dm-list { list-style: none; display: flex; flex-direction: column; gap: 0.5rem; }
.dm-item { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 0.6rem 0.8rem; background: var(--bg); border-radius: var(--r-md); }
.dm-info { display: flex; flex-direction: column; min-width: 0; }
.dm-list .muted { font-size: var(--t-micro); text-transform: capitalize; }
.dm-acciones { display: flex; gap: 0.2rem; flex: none; }
.dm-btn { width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; font-size: var(--t-base); transition: background 0.15s; }
.dm-btn:hover { background: var(--brand-tint); }
.small { font-size: var(--t-sm); }
.btn-danger-ghost { color: var(--danger); }
.btn-danger-ghost:hover { border-color: var(--danger) !important; background: var(--danger-tint) !important; color: var(--danger) !important; }

/* Cola de derechos sobre datos personales */
.datos-banner { display: flex; gap: 0.9rem; align-items: flex-start; padding: 1rem 1.1rem; margin-bottom: 1rem; }
.sd-lista { list-style: none; margin: 0.7rem 0 0; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
.sd-item {
  display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; align-items: center;
  justify-content: space-between; padding: 0.6rem 0.75rem;
  background: var(--surface); border: 1px solid var(--line); border-radius: 8px;
}
.sd-info { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.3rem 0.75rem; flex: 1 1 260px; }
.sd-tipo { font-weight: 600; font-size: var(--t-sm, 0.9rem); }
.sd-email { font-size: var(--t-micro); color: var(--ink-soft); }
.sd-dias { font-size: var(--t-micro); color: var(--ink-soft); }
/* A los 10 días se marca: el plazo legal es más largo, pero una cola que
   avisa recién el último día no sirve para organizarse. */
.sd-dias--alerta { color: var(--danger); font-weight: 600; }
.sd-detalle { flex: 1 1 100%; margin: 0.2rem 0 0; font-size: var(--t-micro); color: var(--ink-soft); }
.sd-acciones { display: flex; gap: 0.4rem; flex: 0 0 auto; }

/* Banner de solicitudes de reset */
.reset-banner { display: flex; gap: 1rem; padding: 1.1rem 1.3rem; margin-bottom: 1.2rem; border-left: 4px solid var(--accent); align-items: flex-start; }
.rb-ic { font-size: 1.6rem; flex: none; }
.rb-body { flex: 1; }
.rb-body strong { color: var(--brand); }
.rb-body .muted { font-size: var(--t-sm); margin: 0.15rem 0 0.7rem; }
.rb-chips { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.rb-chip { background: var(--accent-tint); color: var(--accent-strong); font-size: var(--t-micro); font-weight: 600; padding: 0.3em 0.9em; border-radius: 999px; }
.rb-chip:hover:not(:disabled) { background: var(--accent); color: #fff; }
.rb-chip:disabled { opacity: 0.5; cursor: default; }

/* Modal */
.backdrop { position: fixed; inset: 0; background: rgba(26, 35, 80, 0.5); display: grid; place-items: center; z-index: 400; padding: 1rem; }
.modal { padding: 1.6rem; width: 100%; max-width: 460px; }
.modal-sm { max-width: 400px; }
.modal h2 { color: var(--brand); }
.modal > .muted { margin: 0.2rem 0 1.2rem; font-size: var(--t-base); }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
.field label { font-size: var(--t-sm); font-weight: 600; }
.field label .muted { font-weight: 400; font-size: var(--t-micro); }
.modal-acciones { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 0.4rem; }
.destino-lista { list-style: none; max-height: 200px; overflow-y: auto; border: 1px solid var(--line-strong); border-radius: var(--r-md); margin-top: 0.4rem; }
.destino-item { padding: 0.55em 0.8em; cursor: pointer; font-size: var(--t-base); transition: background 0.12s; }
.destino-item:hover { background: var(--accent-hover); }
.destino-item.sel { background: var(--accent); color: #fff; }
.destino-item.sel .muted { color: rgba(255, 255, 255, 0.85); }

.exito { text-align: center; }
.exito-ic { display: inline-grid; place-items: center; width: 56px; height: 56px; border-radius: 50%; background: var(--ok-tint); color: var(--ok); font-size: 1.8rem; margin-bottom: 0.8rem; }
.exito h2 { color: var(--brand); }
.exito .muted { max-width: 40ch; margin: 0.4rem auto 1.2rem; font-size: var(--t-sm); }
.cred { background: var(--bg); border-radius: var(--r-md); padding: 1rem; text-align: left; margin-bottom: 1.2rem; display: flex; flex-direction: column; gap: 0.7rem; }
.cred-row { display: flex; align-items: center; gap: 0.7rem; }
.cred-row span { width: 90px; color: var(--ink-soft); font-size: var(--t-sm); }
.cred-row code { flex: 1; background: var(--surface); border: 1px solid var(--line); border-radius: 6px; padding: 0.4em 0.7em; font-family: monospace; font-size: var(--t-base); }

@media (max-width: 900px) { .layout { grid-template-columns: 1fr; } .detalle { position: static; } }
</style>
