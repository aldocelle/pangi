<script setup>
/* Protocolos de medicina preventiva, con la forma de ACVet (capturas 3–4 del
   dossier): tabla con búsqueda y filtro por especie, especie como botones
   (Común / Canino / Felino / Otro), frecuencia y edades con unidad, y la marca
   de profilaxis inicial.

   **La base sigue guardando MESES** (`frecuencia_meses`, `edad_*_meses`): los
   protocolos importados de ACVet y las aplicaciones ya calculadas dependen de
   eso, y pasar a días es una decisión abierta con la clínica (dossier §9.1).
   La unidad es solo de captura: «365 días» se guarda como 12 meses, y la
   pantalla avisa cuando un número en días no cae justo en meses. */
import { ref, computed, onMounted } from 'vue';
import { api, apiError } from '../api.js';

const protocolos = ref([]);
const loading = ref(true);
const error = ref('');
const editando = ref(null);
const busy = ref(false);
const q = ref('');
const filtroEspecie = ref('todas');

/* Los valores de la base no cambian: `null` = todas las especies. */
const ESPECIES = [
  { valor: '', label: 'Común', icono: '🌐' },
  { valor: 'perro', label: 'Canino', icono: '🐶' },
  { valor: 'gato', label: 'Felino', icono: '🐱' },
  { valor: 'otro', label: 'Otro', icono: '🐾' },
];
const nombreEspecie = (v) => ESPECIES.find((e) => e.valor === (v || ''))?.label || v;

const DIAS_POR_MES = 30.4375;
const aMeses = (n, unidad) => {
  if (n === '' || n === null || n === undefined) return null;
  const x = Number(n);
  if (unidad === 'años') return Math.round(x * 12);
  if (unidad === 'días') return Math.round(x / DIAS_POR_MES);
  return Math.round(x);
};
/* Para mostrar y para abrir el modal: años si es exacto, si no meses. */
function desdeMeses(m) {
  if (m === null || m === undefined) return { n: '', unidad: 'meses' };
  if (m > 0 && m % 12 === 0) return { n: m / 12, unidad: 'años' };
  return { n: m, unidad: 'meses' };
}
function legible(m, vacio = '—') {
  if (m === null || m === undefined) return vacio;
  const { n, unidad } = desdeMeses(m);
  if (unidad === 'años') return `${n} ${n === 1 ? 'año' : 'años'}`;
  return `${n} ${n === 1 ? 'mes' : 'meses'}`;
}

function vacio() {
  return {
    nombre: '', categoria: '', especie: '', inicial: false, descripcion: '',
    frec: { n: 1, unidad: 'años' }, ini: { n: '', unidad: 'meses' }, fin: { n: '', unidad: 'meses' },
  };
}
function nuevo() { error.value = ''; editando.value = vacio(); }
function editar(p) {
  error.value = '';
  editando.value = {
    id: p.id, nombre: p.nombre, categoria: p.categoria || '', especie: p.especie || '',
    inicial: !!p.inicial, descripcion: p.descripcion || '',
    frec: desdeMeses(p.frecuencia_meses), ini: desdeMeses(p.edad_inicio_meses), fin: desdeMeses(p.edad_fin_meses),
  };
}

/* Un número en días que no cae justo en meses se redondea: se avisa ANTES de
   guardar, para que nadie crea que guardó «21 días». */
const avisoRedondeo = computed(() => {
  const e = editando.value;
  if (!e) return '';
  const partes = [['frecuencia', e.frec], ['edad mínima', e.ini], ['edad máxima', e.fin]]
    .filter(([, v]) => v.unidad === 'días' && v.n !== '' && v.n !== null)
    .map(([nombre, v]) => `${nombre}: ${v.n} días → ${legible(aMeses(v.n, 'días'), '0 meses')}`);
  return partes.length ? `Se guarda en meses — ${partes.join(' · ')}.` : '';
});

async function guardar() {
  busy.value = true;
  error.value = '';
  const e = editando.value;
  const frecuencia = aMeses(e.frec.n, e.frec.unidad);
  if (!frecuencia || frecuencia < 1) {
    error.value = 'La frecuencia tiene que ser de al menos un mes.';
    busy.value = false;
    return;
  }
  try {
    const payload = {
      nombre: e.nombre.trim(),
      categoria: e.categoria.trim() || null,
      especie: e.especie || null,
      descripcion: e.descripcion.trim() || null,
      frecuencia_meses: frecuencia,
      edad_inicio_meses: aMeses(e.ini.n, e.ini.unidad),
      edad_fin_meses: aMeses(e.fin.n, e.fin.unidad),
      inicial: e.inicial,
    };
    if (e.id) await api.patch(`/protocolos/${e.id}`, payload);
    else await api.post('/protocolos', payload);
    editando.value = null;
    await cargar();
  } catch (err) {
    error.value = apiError(err, 'No se pudo guardar el protocolo.');
  } finally {
    busy.value = false;
  }
}

async function borrar(p) {
  if (!confirm(`¿Eliminar el protocolo "${p.nombre}"? Las dosis ya registradas se conservan.`)) return;
  try {
    await api.delete(`/protocolos/${p.id}`);
    await cargar();
  } catch (err) {
    error.value = apiError(err, 'No se pudo eliminar.');
  }
}

async function cargar() {
  loading.value = true;
  try {
    // Son pocos (ACVet trae 8): se traen todos y se filtra en memoria.
    const todos = [];
    for (let page = 1; ; page++) {
      const { data } = await api.get('/protocolos', { params: { limit: 30, page } });
      todos.push(...data.items);
      if (page >= data.total_paginas) break;
    }
    protocolos.value = todos;
  } catch (err) {
    error.value = apiError(err, 'No se pudieron cargar los protocolos.');
  } finally {
    loading.value = false;
  }
}

const sinTildes = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const visibles = computed(() => protocolos.value.filter((p) =>
  (filtroEspecie.value === 'todas' || (p.especie || '') === filtroEspecie.value)
  && (!q.value.trim() || sinTildes(`${p.nombre} ${p.categoria || ''}`).includes(sinTildes(q.value.trim())))));

onMounted(cargar);
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Protocolos preventivos</h1>
        <p class="page-sub">Vacunas y desparasitaciones que se controlan por especie y frecuencia — alimentan el estado en cada ficha.</p>
      </div>
      <button class="btn btn-primary" @click="nuevo">+ Nuevo protocolo</button>
    </div>

    <div class="controles">
      <input class="input buscador" v-model="q" placeholder="Buscar protocolo…" aria-label="Buscar protocolo" />
      <select class="select" v-model="filtroEspecie" aria-label="Filtrar por especie">
        <option value="todas">Todas las especies</option>
        <option v-for="e in ESPECIES" :key="e.valor" :value="e.valor">{{ e.label }}</option>
      </select>
    </div>

    <p v-if="error && !editando" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>
    <p v-else-if="!visibles.length" class="empty">{{ protocolos.length ? 'Ningún protocolo coincide.' : 'Sin protocolos configurados.' }}</p>
    <div v-else class="card envoltura">
      <table class="tabla-datos">
        <thead>
          <tr><th>Nombre</th><th>Especie</th><th>Frecuencia</th><th>Edad inicio</th><th>Edad fin</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="p in visibles" :key="p.id">
            <td>
              <strong>{{ p.nombre }}</strong>
              <span v-if="p.inicial" class="tag">Inicial</span>
              <small v-if="p.categoria" class="muted"> · {{ p.categoria }}</small>
            </td>
            <td>{{ nombreEspecie(p.especie) }}</td>
            <td>{{ legible(p.frecuencia_meses) }}</td>
            <td>{{ legible(p.edad_inicio_meses, 'nacimiento') }}</td>
            <td>{{ legible(p.edad_fin_meses, p.inicial ? '1 año' : 'sin tope') }}</td>
            <td class="acciones">
              <button class="btn btn-ghost btn-sm" @click="editar(p)">Editar</button>
              <button class="btn btn-danger btn-sm" @click="borrar(p)">Eliminar</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="editando" class="modal-backdrop" @click.self="editando = null">
      <div class="modal" role="dialog" aria-modal="true" aria-label="Editar protocolo">
        <div class="modal-head">
          <h3>{{ editando.id ? 'Editar protocolo' : 'Nuevo protocolo' }}</h3>
          <button class="modal-close" aria-label="Cerrar" @click="editando = null">✕</button>
        </div>

        <form @submit.prevent="guardar" class="form">
          <div class="field">
            <label for="p-nombre">Nombre del protocolo *</label>
            <input id="p-nombre" class="input" v-model="editando.nombre" required placeholder="Ej: Vacuna séxtuple" />
          </div>
          <div class="field">
            <span class="lbl">Especie</span>
            <div class="especies" role="radiogroup" aria-label="Especie">
              <button v-for="e in ESPECIES" :key="e.valor" type="button" class="esp" role="radio"
                :aria-checked="editando.especie === e.valor" :class="{ activa: editando.especie === e.valor }"
                @click="editando.especie = e.valor">
                <span aria-hidden="true" class="glifo">{{ e.icono }}</span>{{ e.label }}
              </button>
            </div>
            <small class="ayuda">«Común» si el protocolo aplica a todas las especies.</small>
          </div>
          <div class="field">
            <label for="p-frec">Frecuencia de aplicación *</label>
            <div class="con-unidad">
              <input id="p-frec" class="input" type="number" min="1" v-model.number="editando.frec.n" required />
              <select class="select" v-model="editando.frec.unidad" aria-label="Unidad de la frecuencia">
                <option>días</option><option>meses</option><option>años</option>
              </select>
            </div>
            <small class="ayuda">Cada cuánto se repite el protocolo.</small>
          </div>
          <label class="check">
            <input type="checkbox" v-model="editando.inicial" />
            Profilaxis inicial (cachorros/gatitos)
          </label>
          <small class="ayuda">Solo aplica en los primeros meses de vida: sin edad máxima, la ventana termina al año.</small>
          <div class="grid-2">
            <div class="field">
              <label for="p-ini">Edad mínima</label>
              <div class="con-unidad">
                <input id="p-ini" class="input" type="number" min="0" v-model.number="editando.ini.n" placeholder="0" />
                <select class="select" v-model="editando.ini.unidad" aria-label="Unidad de la edad mínima">
                  <option>días</option><option>meses</option><option>años</option>
                </select>
              </div>
              <small class="ayuda">Vacío o 0: desde el nacimiento.</small>
            </div>
            <div class="field">
              <label for="p-fin">Edad máxima</label>
              <div class="con-unidad">
                <input id="p-fin" class="input" type="number" min="0" v-model.number="editando.fin.n" placeholder="Sin tope" />
                <select class="select" v-model="editando.fin.unidad" aria-label="Unidad de la edad máxima">
                  <option>días</option><option>meses</option><option>años</option>
                </select>
              </div>
            </div>
          </div>
          <div class="field">
            <label for="p-categoria">Categoría</label>
            <input id="p-categoria" class="input" v-model="editando.categoria" placeholder="Ej: Vacuna" />
          </div>
          <div class="field">
            <label for="p-desc">Descripción</label>
            <textarea id="p-desc" class="textarea" v-model="editando.descripcion" placeholder="Notas internas, opcional"></textarea>
          </div>

          <p v-if="avisoRedondeo" class="aviso">{{ avisoRedondeo }}</p>
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
.controles { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 0.9rem; }
.buscador { flex: 1; min-width: 12rem; }
.envoltura { padding: 0.3rem 0.8rem; overflow-x: auto; }
.acciones { white-space: nowrap; text-align: right; }
.acciones .btn + .btn { margin-left: 0.3rem; }
.muted { color: var(--ink-soft); }
.form { display: flex; flex-direction: column; gap: 0.9rem; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
.lbl { font-weight: 600; font-size: var(--t-sm); }
.ayuda { color: var(--ink-soft); font-size: var(--t-sm); }
.especies { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.4rem; }
.esp {
  display: flex; flex-direction: column; align-items: center; gap: 0.2rem; padding: 0.5rem 0.3rem;
  border: 1px solid var(--line-strong); border-radius: var(--r-sm); background: var(--surface);
  font-family: var(--font); font-size: var(--t-sm); color: var(--ink); cursor: pointer;
}
.esp:hover { background: var(--accent-tint); border-color: var(--accent); }
.esp.activa { background: var(--brand-tint); border-color: var(--brand); color: var(--brand-ink); font-weight: 600; }
.glifo { font-size: 1.2rem; }
.con-unidad { display: grid; grid-template-columns: minmax(0, 1fr) 6.5rem; gap: 0.4rem; }
.check { display: inline-flex; align-items: center; gap: 0.45rem; font-size: var(--t-base); cursor: pointer; }
.aviso { background: var(--warn-tint); color: var(--warn-ink); border-radius: var(--r-sm); padding: 0.5rem 0.7rem; font-size: var(--t-sm); }
@media (max-width: 520px) { .grid-2, .especies { grid-template-columns: 1fr 1fr; } }
</style>
