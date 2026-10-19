<script setup>
import { ref, onMounted } from 'vue';
import { api, apiError } from '../api.js';
import Paginador from '../components/Paginador.vue';
import BuscadorAvanzado from '../components/BuscadorAvanzado.vue';

const CAMPOS_BUSQUEDA = [
  { key: 'q', label: 'Nombre', placeholder: 'Buscar proveedor…' },
];

const proveedores = ref([]);
const loading = ref(true);
const error = ref('');
const filtros = ref({ q: '' });
const pagina = ref(1);
const paginacion = ref({ total: 0, total_paginas: 1, limit: 30 });

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const params = { page: pagina.value, limit: 30 };
    if (filtros.value.q) params.q = filtros.value.q;
    const { data } = await api.get('/proveedores', { params });
    proveedores.value = data.items;
    paginacion.value = { total: data.total, total_paginas: data.total_paginas, limit: data.limit };
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar los proveedores.');
  } finally {
    loading.value = false;
  }
}

function buscar() {
  pagina.value = 1;
  cargar();
}

function cambiarPagina(p) {
  pagina.value = p;
  cargar();
}

/* Modal crear/editar */
const modal = ref(false);
const editandoId = ref(null);
const guardando = ref(false);
const errorModal = ref('');
const vacio = { nombre: '', contacto_nombre: '', telefono: '', email: '', notas: '' };
const form = ref({ ...vacio });

function abrirCrear() {
  editandoId.value = null;
  form.value = { ...vacio };
  errorModal.value = '';
  modal.value = true;
}

function abrirEditar(p) {
  editandoId.value = p.id;
  form.value = {
    nombre: p.nombre || '',
    contacto_nombre: p.contacto_nombre || '',
    telefono: p.telefono || '',
    email: p.email || '',
    notas: p.notas || '',
  };
  errorModal.value = '';
  modal.value = true;
}

async function guardar() {
  guardando.value = true;
  errorModal.value = '';
  try {
    if (editandoId.value) {
      const { data } = await api.patch(`/proveedores/${editandoId.value}`, form.value);
      const i = proveedores.value.findIndex((x) => x.id === editandoId.value);
      if (i !== -1) proveedores.value[i] = data;
    } else {
      const { data } = await api.post('/proveedores', form.value);
      proveedores.value.unshift(data);
    }
    modal.value = false;
  } catch (e) {
    errorModal.value = apiError(e, 'No se pudo guardar el proveedor.');
  } finally {
    guardando.value = false;
  }
}

async function eliminar(p) {
  if (!confirm(`¿Dar de baja "${p.nombre}"?`)) return;
  try {
    await api.delete(`/proveedores/${p.id}`);
    proveedores.value = proveedores.value.filter((x) => x.id !== p.id);
  } catch (e) {
    error.value = apiError(e, 'No se pudo dar de baja el proveedor.');
  }
}

/* Pedido sugerido: ítems de este proveedor bajo su punto de reorden. */
const pedidoAbierto = ref(null);
const pedidoItems = ref([]);
const pedidoLoading = ref(false);
const pedidoError = ref('');

async function verPedidoSugerido(p) {
  if (pedidoAbierto.value === p.id) {
    pedidoAbierto.value = null;
    return;
  }
  pedidoAbierto.value = p.id;
  pedidoLoading.value = true;
  pedidoError.value = '';
  try {
    const { data } = await api.get(`/proveedores/${p.id}/pedido-sugerido`);
    pedidoItems.value = data.items;
  } catch (e) {
    pedidoError.value = apiError(e, 'No se pudo calcular el pedido sugerido.');
  } finally {
    pedidoLoading.value = false;
  }
}

function copiarPedido(p) {
  const lineas = pedidoItems.value.map(
    (i) => `- ${i.nombre}: ${i.cantidad_sugerida}${i.unidad ? ' ' + i.unidad : ''}`,
  );
  const texto = `Pedido para ${p.nombre}:\n${lineas.join('\n')}`;
  navigator.clipboard?.writeText(texto);
}

onMounted(cargar);
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Proveedores</h1>
        <p class="page-sub">A quién pedirle reposición de cada ítem de inventario.</p>
      </div>
      <button class="btn btn-primary" @click="abrirCrear">+ Nuevo proveedor</button>
    </div>

    <BuscadorAvanzado v-model="filtros" :campos="CAMPOS_BUSQUEDA" @buscar="buscar" />

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>
    <p v-else-if="!proveedores.length" class="empty">Sin proveedores registrados.</p>

    <div v-else class="lista">
      <div v-for="p in proveedores" :key="p.id" class="card prov-card">
        <div class="prov-head" @click="abrirEditar(p)">
          <strong>{{ p.nombre }}</strong>
          <span v-if="p.contacto_nombre" class="muted">{{ p.contacto_nombre }}</span>
        </div>
        <div class="prov-datos">
          <span v-if="p.telefono">📞 {{ p.telefono }}</span>
          <span v-if="p.email">✉ {{ p.email }}</span>
        </div>
        <div class="prov-acciones">
          <button class="btn btn-ghost btn-sm" @click="verPedidoSugerido(p)">
            {{ pedidoAbierto === p.id ? 'Ocultar pedido sugerido' : 'Ver pedido sugerido' }}
          </button>
          <button class="btn btn-ghost btn-sm" @click="abrirEditar(p)">Editar</button>
          <button class="btn btn-ghost btn-sm peligro" @click="eliminar(p)">Dar de baja</button>
        </div>

        <div v-if="pedidoAbierto === p.id" class="pedido-sugerido">
          <p v-if="pedidoLoading" class="empty">Calculando…</p>
          <p v-else-if="pedidoError" class="error-text">{{ pedidoError }}</p>
          <p v-else-if="!pedidoItems.length" class="empty">Nada bajo el mínimo de este proveedor.</p>
          <template v-else>
            <ul class="pedido-lista">
              <li v-for="i in pedidoItems" :key="i.id">
                {{ i.nombre }} — pedir {{ i.cantidad_sugerida }}{{ i.unidad ? ' ' + i.unidad : '' }}
                <span class="muted">(quedan {{ i.stock_actual }}, mínimo {{ i.stock_minimo }})</span>
              </li>
            </ul>
            <button class="btn btn-ghost btn-sm" @click="copiarPedido(p)">Copiar pedido</button>
          </template>
        </div>
      </div>
    </div>
    <Paginador
      v-if="!loading && proveedores.length"
      :page="pagina" :total-paginas="paginacion.total_paginas"
      :total="paginacion.total" :limit="paginacion.limit"
      @cambiar="cambiarPagina"
    />

    <div v-if="modal" class="overlay" @click.self="modal = false">
      <div class="modal-box">
        <h2>{{ editandoId ? 'Editar proveedor' : 'Nuevo proveedor' }}</h2>
        <form @submit.prevent="guardar">
          <div class="field">
            <label for="p-nombre">Nombre</label>
            <input id="p-nombre" class="input" v-model="form.nombre" required />
          </div>
          <div class="field">
            <label for="p-contacto">Persona de contacto</label>
            <input id="p-contacto" class="input" v-model="form.contacto_nombre" />
          </div>
          <div class="grid-2">
            <div class="field">
              <label for="p-telefono">Teléfono</label>
              <input id="p-telefono" class="input" v-model="form.telefono" placeholder="+56 9 ..." />
            </div>
            <div class="field">
              <label for="p-email">Correo</label>
              <input id="p-email" class="input" type="email" v-model="form.email" />
            </div>
          </div>
          <div class="field">
            <label for="p-notas">Notas</label>
            <textarea id="p-notas" class="textarea" v-model="form.notas"></textarea>
          </div>
          <p v-if="errorModal" class="error-text">{{ errorModal }}</p>
          <div class="modal-acciones">
            <button type="button" class="btn btn-ghost" @click="modal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="guardando">
              {{ guardando ? 'Guardando…' : 'Guardar' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.lista { display: flex; flex-direction: column; gap: 0.7rem; }
.prov-card { padding: 1rem 1.2rem; }
.prov-head { display: flex; align-items: baseline; gap: 0.6rem; cursor: pointer; flex-wrap: wrap; }
.prov-datos { display: flex; gap: 1.2rem; margin-top: 0.3rem; font-size: var(--t-sm); color: var(--ink-soft); flex-wrap: wrap; }
.prov-acciones { display: flex; gap: 0.5rem; margin-top: 0.7rem; flex-wrap: wrap; }
/* .peligro vive en app.css; era una copia que ganaba por especificidad. */

.pedido-sugerido { margin-top: 0.8rem; padding-top: 0.8rem; border-top: 1px solid var(--line); }
.pedido-lista { list-style: none; margin: 0 0 0.6rem; padding: 0; display: flex; flex-direction: column; gap: 0.3rem; font-size: var(--t-base); }

.overlay {
  position: fixed; inset: 0; z-index: 50;
  background: rgba(0, 0, 0, 0.45);
  display: flex; align-items: center; justify-content: center; padding: 1rem;
}
.modal-box {
  background: #fff; border-radius: var(--r-lg);
  padding: 1.4rem; width: 100%; max-width: 480px;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
  max-height: 90vh; overflow-y: auto;
}
.modal-box h2 { font-size: var(--t-xl); margin: 0 0 1rem; }
.modal-box form { display: flex; flex-direction: column; gap: 0.8rem; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
.modal-acciones { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 0.3rem; }
@media (max-width: 560px) { .grid-2 { grid-template-columns: 1fr; } }
</style>
