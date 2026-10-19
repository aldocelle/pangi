<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { api, apiError, fotoEspecie, urlFoto } from '../api.js';
import { useAuthStore } from '../stores/auth.js';
import ConfirmarBorrado from '../components/ConfirmarBorrado.vue';
import Paginador from '../components/Paginador.vue';
import BuscadorAvanzado from '../components/BuscadorAvanzado.vue';
import ColumnaOrdenable from '../components/ColumnaOrdenable.vue';
import TogglePersonas from '../components/TogglePersonas.vue';

const CAMPOS_BUSQUEDA = [
  { key: 'q', label: 'Nombre', placeholder: 'Buscar por nombre de paciente…' },
  { key: 'especie', label: 'Especie', type: 'select', options: [
    { value: 'perro', label: 'Perro' }, { value: 'gato', label: 'Gato' }, { value: '__otro__', label: 'Otro' },
  ] },
  { key: 'raza', label: 'Raza', placeholder: 'Ej: Poodle' },
  { key: 'dueno', label: 'Dueño', placeholder: 'Nombre o correo del dueño…' },
];

const auth = useAuthStore();
const esAdmin = computed(() => auth.user?.rol === 'admin');
const router = useRouter();

function irAFicha(m) {
  router.push(`/ficha/${m.id}`);
}

const mascotas = ref([]);
const loading = ref(true);
const error = ref('');
const filtros = ref({ q: '', especie: '', raza: '', dueno: '' });
const pagina = ref(1);
const paginacion = ref({ total: 0, total_paginas: 1, limit: 30 });
const orden = ref('-ultima_atencion');

function fmtAtencion(iso) {
  if (!iso) return 'Sin atenciones';
  return new Date(iso).toLocaleDateString('es-CL', { day: '2-digit', month: 'short', year: 'numeric' });
}

function ordenarPor(campo) {
  orden.value = campo;
  pagina.value = 1;
  cargar();
}

/* Borrado definitivo */
const borrado = ref(null);
const borrando = ref(false);
const errorBorrado = ref('');

async function abrirBorrado(m) {
  errorBorrado.value = '';
  borrado.value = { id: m.id, nombre: m.nombre, impacto: null };
  try {
    const { data } = await api.get(`/clientes/mascotas/${m.id}/impacto`);
    if (borrado.value) borrado.value.impacto = data;
  } catch (e) {
    errorBorrado.value = apiError(e, 'No se pudo calcular el impacto.');
  }
}

async function confirmarBorrado(texto) {
  borrando.value = true;
  errorBorrado.value = '';
  try {
    await api.delete(`/clientes/mascotas/${borrado.value.id}`, { params: { confirmar: texto } });
    mascotas.value = mascotas.value.filter((m) => m.id !== borrado.value.id);
    borrado.value = null;
  } catch (e) {
    errorBorrado.value = apiError(e, 'No se pudo eliminar el paciente.');
  } finally {
    borrando.value = false;
  }
}

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const params = { page: pagina.value, limit: 30, orden: orden.value };
    // El nombre solo filtra desde 2 caracteres: con 1 sola letra el listado
    // completo (miles de mascotas) apenas se acota y no vale la pena la carga.
    if (filtros.value.q?.trim().length >= 2) params.q = filtros.value.q.trim();
    if (filtros.value.especie) params.especie = filtros.value.especie;
    if (filtros.value.raza?.trim().length >= 2) params.raza = filtros.value.raza.trim();
    if (filtros.value.dueno?.trim().length >= 2) params.dueno = filtros.value.dueno.trim();
    const { data } = await api.get('/mascotas/todas', { params });
    mascotas.value = data.items;
    paginacion.value = { total: data.total, total_paginas: data.total_paginas, limit: data.limit };
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar los pacientes.');
  } finally {
    loading.value = false;
  }
}

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

onMounted(cargar);
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Pacientes</h1>
        <p class="page-sub">Todas las mascotas registradas en la clínica.</p>
      </div>
      <TogglePersonas activo="pacientes" />
    </div>

    <BuscadorAvanzado v-model="filtros" :campos="CAMPOS_BUSQUEDA" @buscar="buscar" />

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>
    <p v-else-if="!mascotas.length" class="empty">Sin resultados.</p>

    <div v-else class="tabla card">
      <div class="t-head t-row">
        <ColumnaOrdenable campo="nombre" :orden-actual="orden" @ordenar="ordenarPor">Paciente</ColumnaOrdenable>
        <ColumnaOrdenable campo="especie" :orden-actual="orden" @ordenar="ordenarPor">Especie / raza</ColumnaOrdenable>
        <ColumnaOrdenable campo="peso_kg" :orden-actual="orden" @ordenar="ordenarPor">Peso</ColumnaOrdenable>
        <ColumnaOrdenable campo="ultima_atencion" :orden-actual="orden" @ordenar="ordenarPor">Última atención</ColumnaOrdenable>
        <span></span>
      </div>
      <div
        v-for="m in mascotas" :key="m.id"
        class="t-row t-row-link"
        role="button" tabindex="0"
        :aria-label="`Abrir ficha de ${m.nombre}`"
        @click="irAFicha(m)"
        @keydown.enter="irAFicha(m)"
      >
        <span class="paciente-col">
          <img class="especie-ic" :src="urlFoto(m.foto_url) || fotoEspecie(m.especie)" :alt="m.especie" />
          <strong>{{ m.nombre }}</strong>
        </span>
        <span class="cap">{{ m.especie }}<template v-if="m.raza"> · {{ m.raza }}</template></span>
        <span>{{ m.peso_kg ? `${m.peso_kg} kg` : '—' }}</span>
        <span class="atencion-col" :class="{ 'sin-atencion': !m.ultima_atencion }">{{ fmtAtencion(m.ultima_atencion) }}</span>
        <span class="acciones" @click.stop>
          <button class="btn btn-ghost btn-sm" @click="irAFicha(m)">Editar</button>
          <button v-if="esAdmin" class="btn btn-ghost btn-sm peligro" @click="abrirBorrado(m)">
            Eliminar
          </button>
        </span>
      </div>
    </div>
    <Paginador
      v-if="!loading && mascotas.length"
      :page="pagina" :total-paginas="paginacion.total_paginas"
      :total="paginacion.total" :limit="paginacion.limit"
      @cambiar="cambiarPagina"
    />

    <ConfirmarBorrado
      v-if="borrado"
      :nombre="borrado.nombre"
      titulo="Eliminar paciente"
      :impacto="borrado.impacto"
      :busy="borrando"
      :error="errorBorrado"
      @confirmar="confirmarBorrado"
      @cancelar="borrado = null"
    />
  </div>
</template>

<style scoped>
/* El patrón de listado (head-row, tabla, t-row, acciones) vive en app.css.
   Aquí queda solo lo propio de esta vista. */
.t-row { grid-template-columns: 1.2fr 1.4fr 0.7fr 1fr auto; min-width: 780px; }
.paciente-col { display: flex; align-items: center; gap: 0.6rem; min-width: 0; }
.especie-ic {
  flex: none;
  width: 32px; height: 32px; object-fit: cover;
  background: var(--brand-tint); border-radius: 50%;
}
.cap { text-transform: capitalize; }
.alergia { color: var(--warn-ink); font-weight: 500; }
.atencion-col { color: var(--ink); font-variant-numeric: tabular-nums; }
.atencion-col.sin-atencion { color: var(--ink-soft); font-style: italic; font-variant-numeric: normal; }

@media (max-width: 720px) {
  .t-row { min-width: 0; }
}
</style>
