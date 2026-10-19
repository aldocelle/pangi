<script setup>
/* Importador de los CSV de ACVet, el espejo de «Exportar por tipo de dato».

   El archivo se lee ACÁ y se manda por lotes: el hosting compartido corta un
   request largo (mismo motivo que el export por páginas). Siempre se pasa por la
   VISTA PREVIA primero —corre el import de verdad en el servidor y lo revierte—
   y recién después se habilita «Importar». Por defecto solo se agrega lo que
   falta: lo que la clínica corrigió en el sistema nuevo no se pisa. */
import { ref, computed, onMounted } from 'vue';
import { api, apiError } from '../api.js';
import { leerZip, tipoDeArchivo } from '../zip.js';

const props = defineProps({ habilitado: { type: Boolean, default: false } });

const tipos = ref([]);
const archivos = ref({});   // clave → { nombre, filas, huella }
const estado = ref({});     // clave → { fase, hecho, total, msg, resumen, previa }
const actualizar = ref(false);
const seleccion = ref({});   // clave → ¿entra en «los seleccionados»?
const enLote = ref(false);   // corriendo una tanda de varios tipos
const confirmando = ref(false);
const error = ref('');
/* A qué cuenta del equipo se atribuyen las notas de «Casos clínicos». El
   servidor ya no adivina: con una sola cuenta activa se usa esa; con más,
   hay que elegir (antes caían al azar en una cuenta de prueba). */
const cuentaNotas = ref('');

onMounted(async () => {
  if (!props.habilitado) return;
  try {
    tipos.value = (await api.get('/importacion/tipos')).data;
    const cuentas = tipos.value.find((t) => t.cuentas)?.cuentas || [];
    if (cuentas.length === 1) cuentaNotas.value = cuentas[0].id;
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar la lista de datos importables.');
  }
});

/* CSV con comillas, comas y saltos de línea dentro de un campo (las notas
   clínicas los traen). El BOM del export de ACVet se descarta. */
function parsearCsv(texto) {
  if (texto.charCodeAt(0) === 0xfeff) texto = texto.slice(1);
  const filas = [];
  let fila = [], campo = '', entre = false;
  for (let i = 0; i < texto.length; i++) {
    const c = texto[i];
    if (entre) {
      if (c === '"') {
        if (texto[i + 1] === '"') { campo += '"'; i++; } else entre = false;
      } else campo += c;
    } else if (c === '"') entre = true;
    else if (c === ',') { fila.push(campo); campo = ''; }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && texto[i + 1] === '\n') i++;
      fila.push(campo); campo = '';
      if (fila.length > 1 || fila[0] !== '') filas.push(fila);
      fila = [];
    } else campo += c;
  }
  if (campo !== '' || fila.length) { fila.push(campo); filas.push(fila); }
  if (!filas.length) return [];
  const cab = filas[0].map((h) => h.trim());
  return filas.slice(1).map((f) => Object.fromEntries(cab.map((h, i) => [h, f[i] ?? ''])));
}

/* Dos fichas de ACVet con el mismo correo son la misma persona: la segunda se
   marca con `dup_de` para que el servidor no la cree otra vez, aunque caiga en
   otro lote. */
function marcarDuplicados(filas) {
  const visto = {};
  for (const f of filas) {
    const e = (f.email || '').trim().toLowerCase();
    if (!e) continue;
    if (visto[e] && visto[e] !== f.id) f.dup_de = visto[e];
    else visto[e] = f.id;
  }
}

function cargarCsv(t, nombre, texto) {
  const filas = parsearCsv(texto);
  if (t.clave === 'propietarios') marcarDuplicados(filas);
  archivos.value[t.clave] = { nombre, filas };
  estado.value[t.clave] = { fase: 'listo' };
  seleccion.value[t.clave] = false;   // elige quien importa: nada viene marcado de antemano
}

async function elegir(t, ev) {
  const archivo = ev.target.files?.[0];
  if (!archivo) return;
  estado.value[t.clave] = { fase: 'leyendo' };
  try {
    cargarCsv(t, archivo.name, await archivo.text());
  } catch (e) {
    delete archivos.value[t.clave];
    estado.value[t.clave] = { fase: 'error', msg: 'No se pudo leer el archivo.' };
  }
}

/* Un solo .zip con todos los CSV: se reparte a cada tarjeta por el nombre del
   archivo. No importa nada por sí solo; cada tarjeta sigue pasando por su vista
   previa. Lo que el zip trae y no se importa (agenda, preventiva) se avisa. */
const resumenZip = ref(null);

async function elegirZip(ev) {
  const archivo = ev.target.files?.[0];
  ev.target.value = '';
  if (!archivo) return;
  resumenZip.value = { fase: 'leyendo', nombre: archivo.name };
  try {
    const entradas = await leerZip(await archivo.arrayBuffer());
    const cargados = [], ignorados = [];
    for (const e of entradas) {
      const clave = tipoDeArchivo(e.nombre);
      const t = tipos.value.find((x) => x.clave === clave);
      if (!t) {
        if (!/__MACOSX|\/?\._/.test(e.nombre)) ignorados.push(e.nombre.split('/').pop());
        continue;
      }
      cargarCsv(t, e.nombre.split('/').pop(), await e.texto());
      cargados.push(t.titulo);
    }
    const faltan = tipos.value.filter((t) => !cargados.includes(t.titulo)).map((t) => t.titulo);
    resumenZip.value = { fase: 'listo', nombre: archivo.name, cargados, ignorados, faltan };
  } catch (e) {
    resumenZip.value = { fase: 'error', msg: e?.message || 'No se pudo leer el .zip.' };
  }
}

function faltaCuenta(t) {
  return !!t.cuentas && !cuentaNotas.value;
}

/* La vista previa vale para el modo y la cuenta con que se hizo: cambiar
   cualquiera de los dos obliga a repetirla antes de importar. */
function previaVigente(t) {
  const e = estado.value[t.clave];
  return e?.fase === 'previa' && e.modo === actualizar.value
    && (!t.cuentas || e.cuenta === cuentaNotas.value);
}

const SUMAS = ['creados', 'actualizados', 'omitidos', 'sin_relacion', 'vinculados', 'fusionados'];

async function correr(t, simular) {
  const { nombre, filas } = archivos.value[t.clave];
  const total = filas.length;
  const suma = Object.fromEntries(SUMAS.map((k) => [k, 0]));
  const avisos = {};
  estado.value[t.clave] = { fase: 'corriendo', hecho: 0, total, simular };
  try {
    for (let i = 0; i < total; i += t.lote) {
      const { data } = await api.post(`/importacion/${t.clave}`, {
        filas: filas.slice(i, i + t.lote), simular, actualizar: actualizar.value,
        primero: i === 0, archivo: nombre, total_filas: total,
        ...(t.cuentas ? { atribuir_a: cuentaNotas.value || null } : {}),
      }, { timeout: 120000 });
      for (const k of SUMAS) suma[k] += data[k] || 0;
      for (const [k, v] of Object.entries(Array.isArray(data.avisos) ? {} : data.avisos || {})) avisos[k] = (avisos[k] || 0) + v;
      estado.value[t.clave] = { fase: 'corriendo', hecho: Math.min(i + t.lote, total), total, simular };
    }
    estado.value[t.clave] = {
      fase: simular ? 'previa' : 'hecho', resumen: suma, avisos, modo: actualizar.value, cuenta: cuentaNotas.value,
    };
  } catch (e) {
    estado.value[t.clave] = { fase: 'error', msg: apiError(e, 'Falló la importación.') };
  }
}

const elegidos = computed(() => tipos.value.filter((t) => seleccion.value[t.clave] && archivos.value[t.clave]));
const hayCorriendo = computed(() => enLote.value || Object.values(estado.value).some((e) => e?.fase === 'corriendo'));
// «Importar» solo se habilita con la vista previa de CADA tipo elegido, hecha con el mismo modo.
const todosConPrevia = computed(() => elegidos.value.length > 0 && elegidos.value.every(previaVigente));
const faltaCuentaEnElegidos = computed(() => elegidos.value.some(faltaCuenta));

/* Una tanda recorre los tipos elegidos EN EL ORDEN de las tarjetas, que es el
   orden en que dependen unos de otros, y se detiene en el primer error. En la
   vista previa de una tanda, lo que depende de un tipo todavía no importado sale
   «sin su dato de origen»: es esperable y se resuelve al importar en orden. */
const cargados = computed(() => tipos.value.filter((t) => archivos.value[t.clave]));
function marcar(valor) {
  for (const t of cargados.value) seleccion.value[t.clave] = valor;
}

async function tanda(simular) {
  enLote.value = true;
  confirmando.value = false;
  try {
    for (const t of elegidos.value) {
      await correr(t, simular);
      if (estado.value[t.clave]?.fase === 'error') break;
    }
  } finally {
    enLote.value = false;
  }
}

function pct(s) {
  return s.total ? Math.round((s.hecho / s.total) * 100) : 0;
}

/* «1 nuevo» / «5 nuevos»: el singular importa en una lista que suele traer cifras chicas. */
function pl(n, uno, varios) {
  return `${n.toLocaleString('es-CL')} ${n === 1 ? uno : varios}`;
}

function lineas(s) {
  const r = s.resumen;
  const l = [pl(r.creados, 'nuevo', 'nuevos')];
  if (r.actualizados) l.push(pl(r.actualizados, 'actualizado', 'actualizados'));
  if (r.vinculados) l.push(pl(r.vinculados, 'vinculado a una cuenta existente', 'vinculados a una cuenta existente'));
  // `omitidos` incluye las fichas duplicadas fusionadas: se restan para no contarlas dos veces.
  const yaEstaban = Math.max(0, r.omitidos - (r.fusionados || 0));
  l.push(pl(yaEstaban, 'ya estaba (sin tocar)', 'ya estaban (sin tocar)'));
  if (r.fusionados) l.push(pl(r.fusionados, 'ficha duplicada fusionada', 'fichas duplicadas fusionadas'));
  if (r.sin_relacion) l.push(`${pl(r.sin_relacion, 'sin su dato de origen', 'sin su dato de origen')} (importa antes el tipo anterior)`);
  const tel = s.avisos?.telefono_repetido;
  if (tel) l.push(pl(tel, 'teléfono repetido quedó vacío', 'teléfonos repetidos quedaron vacíos'));
  return l.join(' · ');
}
</script>

<template>
  <div class="importa">
    <strong>Importar desde ACVet (CSV)</strong>
    <p class="nota">
      Carga el <code>.zip</code> de ACVet de una vez, o sube los <code>*_respaldo.csv</code> uno por uno, <b>importando en este orden</b> (cada uno depende del anterior).
      Siempre se hace primero una vista previa que no guarda nada. Lo que ya está cargado no se duplica.
    </p>
    <p v-if="!habilitado" class="nota">Solo un administrador puede importar.</p>
    <p v-if="error" class="error-text">{{ error }}</p>

    <div v-if="habilitado" class="zip">
      <label class="archivo btn btn-primary btn-sm">
        Cargar un .zip con todos los CSV
        <input type="file" accept=".zip,application/zip" @change="elegirZip" />
      </label>
      <span class="nota">Reparte cada archivo a su tarjeta por el nombre. Después haces la vista previa de cada una.</span>
      <p v-if="resumenZip?.fase === 'leyendo'" class="nota" role="status">Leyendo {{ resumenZip.nombre }}…</p>
      <p v-if="resumenZip?.fase === 'error'" class="error-text">{{ resumenZip.msg }}</p>
      <p v-if="resumenZip?.fase === 'listo'" class="resumen">
        <b>{{ resumenZip.nombre }}:</b> {{ resumenZip.cargados.length }} de {{ tipos.length }} cargados.
        <span v-if="resumenZip.faltan.length"> Faltan: {{ resumenZip.faltan.join(', ') }}.</span>
        <span v-if="resumenZip.ignorados.length"> No se importan: {{ resumenZip.ignorados.join(', ') }}.</span>
      </p>
    </div>

    <div v-if="habilitado && cargados.length" class="tanda">
      <div class="elige">
        <span class="nota" v-if="elegidos.length"><b>{{ elegidos.length }}</b> seleccionados: {{ elegidos.map((t) => t.titulo).join(', ') }}.</span>
        <span class="nota" v-else>Marca con la casilla de cada tarjeta lo que quieres importar.</span>
        <span class="atajos">
          <button type="button" class="link" :disabled="hayCorriendo" @click="marcar(true)">Marcar todos</button>
          <button type="button" class="link" :disabled="hayCorriendo || !elegidos.length" @click="marcar(false)">Quitar marcas</button>
        </span>
      </div>
      <div class="acciones">
        <button type="button" class="btn btn-ghost btn-sm" :disabled="hayCorriendo || !elegidos.length || faltaCuentaEnElegidos"
          :title="faltaCuentaEnElegidos ? 'Elige en «Casos clínicos» a qué cuenta van las notas' : ''" @click="tanda(true)">
          Vista previa de los seleccionados
        </button>
        <button type="button" class="btn btn-primary btn-sm" :disabled="hayCorriendo || !todosConPrevia"
          :title="todosConPrevia ? '' : 'Marca tipos y haz primero su vista previa'" @click="confirmando = true">
          Importar los seleccionados
        </button>
      </div>
      <div v-if="confirmando" class="confirma" role="alertdialog" aria-label="Confirmar importación">
        <p>Se importarán {{ elegidos.length }} tipos, en orden, y <b>se guardarán en la base</b>.
          {{ actualizar ? 'Con «actualizar» activado: lo que ya existe se sobrescribe.' : 'Lo que ya existe no se toca.' }}</p>
        <div class="acciones">
          <button type="button" class="btn btn-primary btn-sm" @click="tanda(false)">Sí, importar</button>
          <button type="button" class="btn btn-ghost btn-sm" @click="confirmando = false">Cancelar</button>
        </div>
      </div>
    </div>

    <label v-if="habilitado" class="chk">
      <input type="checkbox" v-model="actualizar" />
      <span>Actualizar también lo que ya existe <em>(pisa lo que se haya corregido en este sistema)</em></span>
    </label>

    <div v-if="habilitado" class="grid">
      <div v-for="(t, i) in tipos" :key="t.clave" class="ds">
        <div class="ds-head">
          <label class="sel">
            <input type="checkbox" v-model="seleccion[t.clave]" :disabled="!archivos[t.clave] || hayCorriendo"
              :aria-label="'Incluir ' + t.titulo + ' en la importación'" />
            <strong>{{ i + 1 }}. {{ t.titulo }}</strong>
          </label>
          <span v-if="estado[t.clave]?.fase === 'hecho'" class="ok">Importado ✓</span>
        </div>
        <p class="nota">{{ t.detalle }}</p>
        <label class="archivo btn btn-ghost btn-sm">
          Elegir {{ t.archivo }}
          <input type="file" accept=".csv,text/csv" @change="elegir(t, $event)"
            :disabled="estado[t.clave]?.fase === 'corriendo'" />
        </label>
        <p v-if="archivos[t.clave]" class="nota">
          {{ archivos[t.clave].nombre }} · {{ archivos[t.clave].filas.length.toLocaleString('es-CL') }} filas
        </p>
        <div v-if="t.cuentas" class="cuenta-notas">
          <label :for="`cuenta-${t.clave}`">Las notas quedan a nombre de</label>
          <select :id="`cuenta-${t.clave}`" v-model="cuentaNotas" class="select" :disabled="hayCorriendo">
            <option value="" disabled>Elige una cuenta del equipo…</option>
            <option v-for="c in t.cuentas" :key="c.id" :value="c.id">{{ c.nombre }} · {{ c.email }}</option>
          </select>
          <span class="nota">Dato técnico: quién atendió de verdad se importa aparte y es lo que muestra la ficha.</span>
        </div>

        <div v-if="estado[t.clave]?.fase === 'corriendo'" class="avance" role="status">
          <span class="nota">{{ estado[t.clave].simular ? 'Revisando' : 'Importando' }}… {{ estado[t.clave].hecho.toLocaleString('es-CL') }} de {{ estado[t.clave].total.toLocaleString('es-CL') }}</span>
          <div class="barra"><div class="relleno" :style="{ width: pct(estado[t.clave]) + '%' }"></div></div>
        </div>
        <p v-if="estado[t.clave]?.fase === 'previa'" class="resumen">
          <b>Vista previa (no se guardó nada):</b> {{ lineas(estado[t.clave]) }}
        </p>
        <p v-if="estado[t.clave]?.fase === 'hecho'" class="resumen ok-t">
          <b>Listo:</b> {{ lineas(estado[t.clave]) }}
        </p>
        <p v-if="estado[t.clave]?.fase === 'error'" class="error-text">{{ estado[t.clave].msg }}</p>

        <div class="acciones">
          <button type="button" class="btn btn-ghost btn-sm"
            :disabled="!archivos[t.clave] || hayCorriendo || faltaCuenta(t)"
            :title="faltaCuenta(t) ? 'Elige primero a qué cuenta van las notas' : ''" @click="correr(t, true)">
            Vista previa
          </button>
          <button type="button" class="btn btn-primary btn-sm"
            :disabled="hayCorriendo || !previaVigente(t)"
            :title="previaVigente(t) ? '' : 'Haz primero la vista previa'"
            @click="correr(t, false)">
            Importar
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.importa { display: flex; flex-direction: column; gap: 0.6rem; width: 100%; margin-top: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 0.7rem; }
.ds { border: 1px solid var(--line); border-radius: var(--r-sm); padding: 0.7rem 0.8rem; display: flex; flex-direction: column; gap: 0.35rem; align-items: flex-start; background: var(--surface); }
.ds-head { display: flex; justify-content: space-between; width: 100%; gap: 0.5rem; }
.ok { color: var(--ok); font-size: var(--t-sm); font-weight: 600; }
.ok-t { color: var(--ok); }
.nota { color: var(--ink-soft); font-size: var(--t-sm); }
.resumen { font-size: var(--t-sm); }
.tanda { display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-start; border: 1px solid var(--line); border-radius: var(--r-sm); padding: 0.7rem 0.8rem; background: var(--surface); width: 100%; }
.confirma { border-left: 3px solid var(--accent); padding: 0.4rem 0.7rem; display: flex; flex-direction: column; gap: 0.5rem; }
.elige { display: flex; flex-wrap: wrap; gap: 0.3rem 1rem; align-items: baseline; justify-content: space-between; width: 100%; }
.atajos { display: flex; gap: 0.9rem; }
.link { background: none; border: none; padding: 0; font: inherit; font-size: var(--t-sm); color: var(--accent-strong, var(--accent)); text-decoration: underline; cursor: pointer; }
.link:disabled { color: var(--ink-soft); cursor: default; text-decoration: none; }
.sel { display: flex; gap: 0.45rem; align-items: center; cursor: pointer; }
.zip { display: flex; flex-direction: column; gap: 0.4rem; align-items: flex-start; }
.chk { display: flex; gap: 0.5rem; align-items: flex-start; font-size: var(--t-sm); }
.chk input { margin: 0.2em 0 0; flex: none; }
.archivo { position: relative; cursor: pointer; overflow: hidden; }
.archivo input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.acciones { display: flex; gap: 0.5rem; }
.cuenta-notas { display: flex; flex-direction: column; gap: 0.25rem; width: 100%; font-size: var(--t-sm); font-weight: 600; }
.cuenta-notas .select { width: 100%; font-weight: 400; }
.cuenta-notas .nota { font-weight: 400; }
.avance { width: 100%; display: flex; flex-direction: column; gap: 0.3rem; }
.barra { height: 6px; background: var(--brand-tint); border-radius: 999px; overflow: hidden; }
.relleno { height: 100%; background: var(--brand); transition: width 0.2s; }
</style>
