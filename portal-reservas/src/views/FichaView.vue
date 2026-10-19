<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { api, apiError, fechaLarga, fechaCorta, edadDesde, fotoMascota, API_URL } from '../api.js';
import { CLINICA } from '../config.js';
import { useDatosStore } from '../stores/datos.js';
import BaseIcon from '../components/BaseIcon.vue';
import EditorFoto from '../components/EditorFoto.vue';

const props = defineProps({ id: { type: String, required: true } });
const datos = useDatosStore();

const ficha = ref(null);
const cargando = ref(true);
const error = ref('');
const tab = ref('resumen');

/* Un color por pestaña: el ícono lo lleva siempre y la activa se rellena con
   él. Medidos con texto blanco encima (el de la activa): marca 11.9, verde
   6.16, morado 5.70, rosa 6.06, ámbar 6.11, turquesa 4.93. El turquesa sobre
   el riel da 4.37, por eso como TEXTO solo va sobre blanco o relleno, nunca
   en la pestaña inactiva. */
const TABS = [
  { id: 'resumen', label: 'Resumen', icon: 'home', tono: 'var(--brand)' },
  { id: 'historial', label: 'Historial Clínico', icon: 'doc', tono: 'var(--accent)' },
  { id: 'vacunas', label: 'Vacunas', icon: 'syringe', tono: 'var(--ok)' },
  { id: 'examenes', label: 'Exámenes', icon: 'flask', tono: 'var(--purple)' },
  { id: 'tratamientos', label: 'Tratamientos', icon: 'heart', tono: 'var(--rosa)' },
  { id: 'documentos', label: 'Documentos', icon: 'folder', tono: 'var(--warn-ink)' },
];

/* En el teléfono el carril se desliza: la pestaña que se toca a medio ver se
   trae entera, o la activa —ahora rellena de color— queda cortada contra el
   borde. `block: 'nearest'` para no mover la página hacia arriba o abajo. */
function elegirTab(id, e) {
  tab.value = id;
  e.currentTarget.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
}

const mascota = computed(() => datos.mascotaPorId(props.id));

/* La foto se sube por el store, que la deja en la mascota para todas las
   vistas: la misma vía que usa la tarjeta de Mis Mascotas. */
const subirFoto = (archivo) => datos.subirFotoMascota(props.id, archivo);
const quitarFoto = () => datos.quitarFotoMascota(props.id);
const notas = computed(() =>
  [...(ficha.value?.notas || [])].sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
);
const ultimasConsultas = computed(() => notas.value.slice(0, 3));

/* Los hallazgos del examen que el tutor SÍ ve, y en qué orden. La clínica
   pidió expresamente que un campo vacío no aparezca: en una ficha médica, un
   renglón que dice "Temperatura: —" no informa que no se midió, sugiere que
   algo faltó. Por eso se filtra por valor y no se pinta la fila completa. */
const EXAMEN_VISIBLE = [
  ['conciencia', 'Conciencia'],
  ['condicion_corporal', 'Condición corporal'],
  ['mucosas', 'Mucosas'],
  ['hidratacion', 'Hidratación'],
  ['frecuencia_cardiaca', 'Frecuencia cardíaca', 'lpm'],
  ['frecuencia_respiratoria', 'Frecuencia respiratoria', 'rpm'],
  ['temperatura', 'Temperatura', '°C'],
  ['dolor_escala', 'Escala de dolor'],
  ['dolor_origen', 'Origen del dolor'],
  ['periodontal', 'Enfermedad periodontal'],
];

function examenDe(n) {
  return EXAMEN_VISIBLE
    .filter(([campo]) => n[campo] !== null && n[campo] !== undefined && n[campo] !== '')
    .map(([campo, label, unidad]) => ({
      label,
      valor: unidad ? `${n[campo]} ${unidad}` : n[campo],
    }));
}

/* `proximo_control` llega como 'YYYY-MM-DD' sin hora, y el navegador lee eso
   como medianoche UTC: en Chile son las 20:00 del día ANTERIOR, así que un
   control del 3 de septiembre se le mostraba al tutor como el 2. El mediodía
   deja la fecha fuera del alcance de cualquier huso. */
function fechaControl(iso) {
  return iso ? fechaLarga(`${iso}T12:00:00`) : '—';
}

/* Uno por línea, como los escribió el veterinario. Se limpian las líneas en
   blanco para que un enter de más no deje una viñeta vacía. */
function lineas(texto) {
  return (texto || '').split('\n').map((l) => l.trim()).filter(Boolean);
}
/* Tratamientos: las consultas donde el veterinario dejó tratamiento o
   indicaciones. No es un módulo aparte con dosis y duración —la base no lo
   guarda así—, sino lo mismo que ya está en el Historial, sacado de entre los
   hallazgos y el diagnóstico, que es donde el tutor lo tenía que ir a buscar.

   Por eso no se habla de tratamientos «vigentes»: la nota no dice cuándo
   termina uno. Se dice lo que sí se sabe, qué se indicó y cuándo. */
const tratamientos = computed(() => notas.value.filter((n) => n.tratamiento || n.indicaciones));
const ultimoTratamiento = computed(() => tratamientos.value[0] || null);
const tratamientosAnteriores = computed(() => tratamientos.value.slice(1));

/* El control más cercano que todavía no pasó, de cualquier consulta. Se compara
   como texto 'YYYY-MM-DD' contra la fecha local de hoy: armar un Date desde
   ese texto lo corre al día anterior en Chile (ver fechaControl). */
const proximoControl = computed(() => {
  const d = new Date();
  const hoy = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  return notas.value
    .map((n) => n.proximo_control)
    .filter((f) => f && f >= hoy)
    .sort()[0] || null;
});

/* Vacunas y desparasitaciones (`/mascotas/{id}/preventivo`). Se pide recién al
   abrir la pestaña: la mayoría de las visitas a la ficha no la abren.

   El estado es el mismo que ve la clínica en el panel. «pendiente» se dice
   «Sin registro» y no «Pendiente» ni «Vencida»: solo significa que la clínica
   no tiene esa dosis anotada, y muchas mascotas llegaron de ACVet o se
   vacunaron en otro lado. Decirle «pendiente» a un tutor que la vacunó el mes
   pasado es acusarlo de algo que no hizo. */
const preventivo = ref(null);
// El id de la mascota que se está pidiendo, o null: si el tutor cambia de
// mascota a medio camino, la respuesta de la anterior se descarta.
const cargandoPrev = ref(null);
const errorPrev = ref('');

async function cargarPreventivo() {
  const id = props.id;
  if (preventivo.value?.mascota === id || cargandoPrev.value === id) return;
  cargandoPrev.value = id;
  errorPrev.value = '';
  try {
    const { data } = await api.get(`/mascotas/${id}/preventivo`);
    if (id === props.id) preventivo.value = { ...data, mascota: id };
  } catch (e) {
    if (id === props.id) errorPrev.value = apiError(e, 'No se pudieron cargar las vacunas.');
  } finally {
    if (cargandoPrev.value === id) cargandoPrev.value = null;
  }
}
watch(tab, (t) => { if (t === 'vacunas') cargarPreventivo(); });

const ESTADO_PREV = {
  vencido: { label: 'Vencida', chip: 'chip-danger', orden: 0 },
  proximo: { label: 'Próxima', chip: 'chip-warn', orden: 1 },
  al_dia: { label: 'Al día', chip: 'chip-ok', orden: 2 },
  pendiente: { label: 'Sin registro', chip: 'chip-muted', orden: 3 },
};
// Lo que pide acción primero: vencidas, después las que se vienen.
const calendario = computed(() =>
  [...(preventivo.value?.estado || [])].sort((a, b) => ESTADO_PREV[a.estado].orden - ESTADO_PREV[b.estado].orden)
);
const urgente = computed(() => calendario.value.find((p) => p.estado === 'vencido' || p.estado === 'proximo') || null);
const haySinRegistro = computed(() => calendario.value.some((p) => p.estado === 'pendiente'));
const dosis = computed(() => preventivo.value?.aplicaciones || []);
const sinNadaDeVacunas = computed(() => !calendario.value.length && !dosis.value.length && !ficha.value?.vacunas);
const esVacuna = (categoria) => /vacun/i.test(categoria || '');

const documentosExamen = computed(() =>
  (ficha.value?.documentos || []).filter((d) => d.tipo === 'examen' || d.tipo === 'rx')
);

/* El endpoint de descarga exige JWT (`Depends(get_current_user)` en
   `fichas.py`), y un <a href> plano NO manda el header Authorization: el
   navegador navegaba a la URL desnuda y el backend contestaba
   401 {"detail":"Not authenticated"} — que es lo que veía el dueño al abrir un
   examen desde la ficha de su mascota.

   El panel ya tenía este mismo arreglo desde antes; el portal se quedó sin él.
   Es exactamente la trampa del §9.5 del CLAUDE.md: los tres frontends están
   duplicados a propósito y un arreglo hay que replicarlo a mano en cada uno.

   Se pide como blob por axios —que sí adjunta el token— y se abre ese blob. */
const abriendoDoc = ref(null);
const errorDoc = ref('');

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
const sexoLabel = computed(() => {
  const s = mascota.value?.sexo;
  if (s === 'M') return 'Macho';
  if (s === 'F') return 'Hembra';
  return '—';
});

const esterilizadoLabel = computed(() => {
  const e = mascota.value?.esterilizado;
  if (e === true) return 'Sí';
  if (e === false) return 'No';
  return '—'; // sin registrar
});

async function cargar() {
  cargando.value = true;
  error.value = '';
  // Otra mascota: las vacunas de la anterior no le sirven.
  preventivo.value = null;
  if (tab.value === 'vacunas') cargarPreventivo();
  try {
    await datos.cargar();
    const { data } = await api.get(`/fichas/mascota/${props.id}`);
    ficha.value = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar la ficha.');
  } finally {
    cargando.value = false;
  }
}
onMounted(cargar);
watch(() => props.id, cargar);
</script>

<template>
  <div class="page">
    <!-- Migas -->
    <nav class="miga" aria-label="Ruta">
      <RouterLink to="/inicio">Inicio</RouterLink>
      <BaseIcon name="chevron" :size="14" />
      <RouterLink to="/mascotas">Mis Mascotas</RouterLink>
      <BaseIcon name="chevron" :size="14" />
      <strong>{{ mascota?.nombre || 'Ficha' }}</strong>
    </nav>

    <p v-if="cargando" class="empty-state">Cargando ficha…</p>
    <p v-else-if="error" class="card card-pad empty-state">{{ error }}</p>

    <template v-else-if="mascota">
      <!-- Cabecera de la mascota -->
      <section class="card card-pad ficha-head">
        <EditorFoto
          :src="fotoMascota(mascota)" :tiene-foto="!!mascota.foto_url" :nombre="mascota.nombre"
          :subir="subirFoto" :quitar="quitarFoto"
        />
        <div class="fh-info">
          <h1>{{ mascota.nombre }}</h1>
          <div class="fh-tags">
            <span class="chip chip-muted">{{ mascota.especie }}</span>
            <span v-if="mascota.raza" class="chip chip-muted">{{ mascota.raza }}</span>
            <span class="chip chip-muted">{{ sexoLabel }}</span>
            <span v-if="mascota.fecha_nacimiento" class="chip chip-brand">{{ edadDesde(mascota.fecha_nacimiento) }}</span>
          </div>
          <div class="fh-datos">
            <div><span class="muted">Fecha de nacimiento</span><strong>{{ mascota.fecha_nacimiento ? fechaCorta(mascota.fecha_nacimiento) : '—' }}</strong></div>
            <div><span class="muted">Peso actual</span><strong>{{ mascota.peso_kg ? `${mascota.peso_kg} kg` : '—' }}</strong></div>
            <!-- Distingue "no está esterilizado" de "nadie lo ha registrado":
                 el campo es opcional y false no significa lo mismo que null. -->
            <div><span class="muted">Esterilizado</span><strong>{{ esterilizadoLabel }}</strong></div>
            <div><span class="muted">Alergias</span><strong>{{ mascota.alergias || 'Ninguna registrada' }}</strong></div>
          </div>
        </div>
        <RouterLink to="/citas/nueva" class="btn btn-primary fh-cta">
          <BaseIcon name="plus" :size="16" /> Reservar cita
        </RouterLink>
      </section>

      <!-- Tabs -->
      <div class="tabs" role="tablist">
        <button
          v-for="t in TABS" :key="t.id"
          class="tab" :class="{ active: tab === t.id }" :style="{ '--tono': t.tono }"
          role="tab" :aria-selected="tab === t.id"
          @click="elegirTab(t.id, $event)"
        >
          <BaseIcon :name="t.icon" :size="17" /> {{ t.label }}
        </button>
      </div>

      <!-- RESUMEN -->
      <div v-if="tab === 'resumen'" class="tab-body grid-2">
        <section class="card card-pad">
          <div class="section-head"><h2>Últimas consultas</h2></div>
          <p v-if="!ultimasConsultas.length" class="empty-state">No hay consultas registradas todavía.</p>
          <ul v-else class="consulta-list">
            <li v-for="n in ultimasConsultas" :key="n.id" class="consulta-item">
              <span class="consulta-ic"><BaseIcon name="doc" :size="18" /></span>
              <div>
                <span class="muted consulta-fecha">{{ fechaCorta(n.created_at) }}</span>
                <strong>{{ n.motivo || 'Consulta' }}</strong>
                <p class="muted">{{ n.diagnostico || 'Sin diagnóstico registrado.' }}</p>
              </div>
              <!-- Se fue el chip «Completada». Lo llevaban TODAS las consultas
                   del listado, siempre: si nunca varía no informa, solo ocupa
                   el sitio donde el ojo va a buscar una diferencia. -->
            </li>
          </ul>
        </section>

        <section class="card card-pad">
          <div class="section-head"><h2>Información adicional</h2></div>
          <dl class="info-extra">
            <div><dt>Especie</dt><dd class="cap">{{ mascota.especie }}</dd></div>
            <div><dt>Raza</dt><dd>{{ mascota.raza || '—' }}</dd></div>
            <div><dt>Alergias</dt><dd>{{ mascota.alergias || 'Ninguna registrada' }}</dd></div>
            <div><dt>Antecedentes</dt><dd>{{ ficha?.antecedentes || 'Sin antecedentes registrados.' }}</dd></div>
            <div><dt>Condiciones</dt><dd>{{ ficha?.condiciones_cronicas || 'Ninguna diagnosticada.' }}</dd></div>
          </dl>
        </section>
      </div>

      <!-- HISTORIAL -->
      <div v-else-if="tab === 'historial'" class="tab-body">
        <section class="card card-pad">
          <div class="section-head"><h2>Historial clínico</h2></div>
          <p v-if="!notas.length" class="empty-state">Aún no hay atenciones registradas en la ficha.</p>
          <ul v-else class="hist-full">
            <li v-for="n in notas" :key="n.id" class="hist-full-item">
              <div class="hfi-top">
                <span class="consulta-ic"><BaseIcon name="doc" :size="18" /></span>
                <strong>{{ n.motivo || 'Consulta' }}</strong>
                <span class="muted">{{ fechaLarga(n.created_at) }}</span>
              </div>
              <div class="hfi-body">
                <!-- Examen clínico: solo los hallazgos que el médico completó. -->
                <dl v-if="examenDe(n).length" class="examen-lista">
                  <div v-for="d in examenDe(n)" :key="d.label" class="examen-dato">
                    <dt>{{ d.label }}</dt>
                    <dd>{{ d.valor }}</dd>
                  </div>
                </dl>
                <p v-if="n.examen"><span class="hfi-label">Otros hallazgos:</span> {{ n.examen }}</p>

                <template v-if="lineas(n.prediagnosticos).length">
                  <p class="hfi-label">Prediagnósticos:</p>
                  <ul class="hfi-lista">
                    <li v-for="(d, i) in lineas(n.prediagnosticos)" :key="i">{{ d }}</li>
                  </ul>
                </template>
                <p v-if="n.diagnostico"><span class="hfi-label">Diagnóstico:</span> {{ n.diagnostico }}</p>

                <template v-if="lineas(n.examenes_complementarios).length">
                  <p class="hfi-label">Exámenes complementarios:</p>
                  <ul class="hfi-lista">
                    <li v-for="(e, i) in lineas(n.examenes_complementarios)" :key="i">{{ e }}</li>
                  </ul>
                </template>

                <p v-if="n.tratamiento"><span class="hfi-label">Tratamiento:</span> {{ n.tratamiento }}</p>
                <p v-if="n.indicaciones"><span class="hfi-label">Indicaciones:</span> {{ n.indicaciones }}</p>
                <p v-if="n.proximo_control" class="hfi-control">
                  <span class="hfi-label">Próximo control:</span> {{ fechaControl(n.proximo_control) }}
                </p>
              </div>
            </li>
          </ul>
        </section>
      </div>

      <!-- EXÁMENES: documentos clínicos de tipo examen/rx -->
      <div v-else-if="tab === 'examenes'" class="tab-body">
        <section class="card card-pad">
          <div class="section-head"><h2>Exámenes y radiografías</h2></div>
          <p v-if="!documentosExamen.length" class="empty-state">Aún no hay exámenes cargados.</p>
          <ul v-else class="doc-list">
            <li v-for="d in documentosExamen" :key="d.id" class="doc-item">
              <span class="doc-ic"><BaseIcon :name="d.tipo === 'rx' ? 'flask' : 'doc'" :size="18" /></span>
              <div class="doc-info">
                <button type="button" class="doc-nombre doc-boton" :disabled="abriendoDoc === d.id" @click="abrirDocumento(d)">
                  {{ d.descripcion || d.nombre_original }}
                                </button>
                <span class="muted">{{ fechaLarga(d.created_at) }}</span>
              </div>
              <span class="chip chip-info">{{ d.tipo === 'rx' ? 'RX' : 'Examen' }}</span>
            </li>
          </ul>
          <p v-if="errorDoc" class="doc-error">{{ errorDoc }}</p>
        </section>
      </div>

      <!-- DOCUMENTOS: todos los adjuntos de la ficha (exámenes, RX, recetas, otros) -->
      <div v-else-if="tab === 'documentos'" class="tab-body">
        <section class="card card-pad">
          <div class="section-head"><h2>Documentos</h2></div>
          <p v-if="!(ficha?.documentos?.length)" class="empty-state">Aún no hay documentos adjuntos.</p>
          <ul v-else class="doc-list">
            <li v-for="d in ficha.documentos" :key="d.id" class="doc-item">
              <span class="doc-ic"><BaseIcon name="doc" :size="18" /></span>
              <div class="doc-info">
                <button type="button" class="doc-nombre doc-boton" :disabled="abriendoDoc === d.id" @click="abrirDocumento(d)">
                  {{ d.descripcion || d.nombre_original }}
                                </button>
                <span class="muted">{{ fechaLarga(d.created_at) }}</span>
              </div>
              <span class="chip chip-info">{{ { examen: 'Examen', rx: 'RX', receta: 'Receta' }[d.tipo] || 'Otro' }}</span>
            </li>
          </ul>
          <p v-if="errorDoc" class="doc-error">{{ errorDoc }}</p>
        </section>
      </div>

      <!-- TRATAMIENTOS: lo indicado en cada consulta -->
      <div v-else-if="tab === 'tratamientos'" class="tab-body trat">
        <section v-if="!tratamientos.length" class="card card-pad pronto">
          <span class="pronto-ic"><BaseIcon name="heart" :size="34" /></span>
          <h2>Sin tratamientos registrados</h2>
          <p class="muted">
            Cuando el veterinario indique un tratamiento o cuidados para {{ mascota.nombre }},
            los verás aquí con la fecha de la consulta.
          </p>
        </section>

        <template v-else>
          <p v-if="proximoControl" class="trat-control">
            <BaseIcon name="calendar" :size="18" />
            <span>Próximo control: <strong>{{ fechaControl(proximoControl) }}</strong></span>
            <RouterLink to="/citas/nueva" class="link-accent">Reservar</RouterLink>
          </p>

          <!-- Lo último indicado, destacado: es lo que el tutor está siguiendo
               ahora o lo que tiene que preguntar si ya no le queda claro. -->
          <section class="card trat-ultimo">
            <header class="trat-cab">
              <span class="trat-etq">Última indicación</span>
              <strong>{{ ultimoTratamiento.motivo || 'Consulta' }}</strong>
              <span class="muted">
                {{ fechaLarga(ultimoTratamiento.created_at) }}
                <template v-if="ultimoTratamiento.atendido_por"> · {{ ultimoTratamiento.atendido_por }}</template>
              </span>
            </header>
            <div class="trat-cuerpo">
              <p v-if="ultimoTratamiento.diagnostico" class="trat-dx">
                <span class="hfi-label">Diagnóstico:</span> {{ ultimoTratamiento.diagnostico }}
              </p>
              <div v-if="ultimoTratamiento.tratamiento" class="trat-bloque">
                <h3><BaseIcon name="heart" :size="16" /> Tratamiento</h3>
                <p class="trat-texto">{{ ultimoTratamiento.tratamiento }}</p>
              </div>
              <div v-if="ultimoTratamiento.indicaciones" class="trat-bloque">
                <h3><BaseIcon name="check" :size="16" /> Indicaciones</h3>
                <p class="trat-texto">{{ ultimoTratamiento.indicaciones }}</p>
              </div>
            </div>
          </section>

          <section v-if="tratamientosAnteriores.length" class="card card-pad">
            <div class="section-head"><h2>Indicaciones anteriores</h2></div>
            <ul class="trat-lista">
              <li v-for="n in tratamientosAnteriores" :key="n.id" class="trat-item">
                <div class="trat-item-cab">
                  <strong>{{ n.motivo || 'Consulta' }}</strong>
                  <span class="muted">{{ fechaLarga(n.created_at) }}</span>
                </div>
                <p v-if="n.tratamiento" class="trat-texto">
                  <span class="hfi-label">Tratamiento:</span> {{ n.tratamiento }}
                </p>
                <p v-if="n.indicaciones" class="trat-texto">
                  <span class="hfi-label">Indicaciones:</span> {{ n.indicaciones }}
                </p>
              </li>
            </ul>
          </section>

          <p class="trat-pie muted">
            ¿Tienes dudas con un tratamiento? No lo cambies ni lo suspendas por tu cuenta:
            <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener">escríbenos</a>.
          </p>
        </template>
      </div>

      <!-- VACUNAS: calendario (estado por protocolo), dosis aplicadas y lo que
           la clínica anotó en la ficha. -->
      <div v-else-if="tab === 'vacunas'" class="tab-body vac">
        <p v-if="cargandoPrev && !preventivo" class="empty-state">Cargando vacunas…</p>
        <p v-else-if="errorPrev" class="card card-pad empty-state">{{ errorPrev }}</p>

        <section v-else-if="sinNadaDeVacunas" class="card card-pad pronto">
          <span class="pronto-ic"><BaseIcon name="syringe" :size="34" /></span>
          <h2>Sin vacunas registradas</h2>
          <p class="muted">
            Cuando el equipo registre una vacuna o desparasitación de {{ mascota.nombre }}, la verás
            aquí con la fecha de la próxima dosis. Si la vacunaron en otro lugar, trae su carnet a la
            próxima visita.
          </p>
          <RouterLink to="/citas/nueva" class="btn btn-ghost btn-sm">
            <BaseIcon name="calendar" :size="16" /> Reservar hora
          </RouterLink>
        </section>

        <template v-else-if="preventivo">
          <!-- Lo más urgente, arriba y con la acción al lado: es lo único de la
               pestaña que pide hacer algo. -->
          <p v-if="urgente" class="vac-aviso" :class="urgente.estado">
            <BaseIcon :name="urgente.estado === 'vencido' ? 'alert' : 'calendar'" :size="18" />
            <span v-if="urgente.estado === 'vencido'">
              <strong>{{ urgente.nombre }}</strong> venció el {{ fechaControl(urgente.proxima_fecha) }}.
            </span>
            <span v-else>
              Próxima dosis de <strong>{{ urgente.nombre }}</strong>: {{ fechaControl(urgente.proxima_fecha) }}.
            </span>
            <RouterLink to="/citas/nueva" class="link-accent">Reservar</RouterLink>
          </p>

          <section v-if="calendario.length" class="card card-pad">
            <div class="section-head"><h2>Calendario de {{ mascota.nombre }}</h2></div>
            <ul class="vac-lista">
              <li v-for="p in calendario" :key="p.protocolo_id" class="vac-item">
                <span class="consulta-ic"><BaseIcon :name="esVacuna(p.categoria) ? 'syringe' : 'shield'" :size="18" /></span>
                <div class="vac-txt">
                  <strong>{{ p.nombre }}</strong>
                  <span class="muted">
                    <template v-if="p.ultima_aplicacion">Última dosis: {{ fechaControl(p.ultima_aplicacion) }}</template>
                    <template v-else>Sin dosis anotada en la clínica</template>
                    <template v-if="p.proxima_fecha"> · Próxima: {{ fechaControl(p.proxima_fecha) }}</template>
                    <template v-else-if="p.ultima_aplicacion"> · Cada {{ p.frecuencia_meses }} {{ p.frecuencia_meses === 1 ? 'mes' : 'meses' }}</template>
                  </span>
                </div>
                <span class="chip" :class="ESTADO_PREV[p.estado].chip">{{ ESTADO_PREV[p.estado].label }}</span>
              </li>
            </ul>
            <p v-if="haySinRegistro" class="vac-nota muted">
              «Sin registro» quiere decir que no tenemos esa dosis anotada, no que le falte: si se
              la pusieron en otro lugar, trae su carnet y la registramos.
            </p>
          </section>

          <section v-if="dosis.length" class="card card-pad">
            <div class="section-head"><h2>Dosis aplicadas</h2></div>
            <ul class="vac-dosis">
              <li v-for="a in dosis" :key="a.id">
                <span class="vac-fecha">{{ fechaCorta(`${a.fecha_aplicacion}T12:00:00`) }}</span>
                <strong>{{ a.nombre }}</strong>
                <span class="chip" :class="esVacuna(a.categoria) ? 'chip-brand' : 'chip-muted'">{{ a.categoria || 'Preventivo' }}</span>
              </li>
            </ul>
          </section>

          <!-- El texto libre de la ficha: es donde quedó el historial de antes de
               este sistema (ACVet). Se muestra tal cual lo escribió el equipo. -->
          <section v-if="ficha?.vacunas" class="card card-pad">
            <div class="section-head"><h2>Anotado en su ficha</h2></div>
            <p class="trat-texto">{{ ficha.vacunas }}</p>
          </section>
        </template>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* Los hallazgos, en pares etiqueta/valor. Rejilla y no párrafos: son datos
   cortos que se comparan de un vistazo entre una consulta y otra. */
.examen-lista {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 0.5rem 0.9rem; margin: 0 0 0.7rem; padding: 0.7rem 0.85rem;
  background: var(--bg); border-radius: 10px;
}
.examen-dato { display: flex; flex-direction: column; gap: 0.05rem; min-width: 0; }
.examen-dato dt { font-size: var(--t-micro); text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-soft); }
.examen-dato dd { margin: 0; font-size: var(--t-base); font-weight: 600; font-variant-numeric: tabular-nums; }
.hfi-lista { margin: 0.15rem 0 0.6rem; padding-left: 1.2rem; }
.hfi-lista li { font-size: var(--t-base); line-height: 1.5; }
.hfi-control { font-weight: 600; }


.miga { display: flex; align-items: center; gap: 0.4rem; margin-bottom: 1.2rem; font-size: var(--t-sm); color: var(--ink-soft); }
.miga a { color: var(--brand); font-weight: 500; }
.miga a:hover { color: var(--accent-strong); }
.miga :deep(svg) { color: var(--ink-soft); }

.ficha-head { display: flex; gap: 1.4rem; align-items: flex-start; margin-bottom: 1.4rem; }
.fh-info { flex: 1; min-width: 0; }
.fh-info h1 { font-size: var(--t-3xl); color: var(--brand); }
.fh-tags { display: flex; gap: 0.4rem; flex-wrap: wrap; margin: 0.5rem 0 1rem; }
.fh-datos { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.9rem; }
.fh-datos div { display: flex; flex-direction: column; }
.fh-datos .muted { font-size: var(--t-micro); }
.fh-datos strong { font-size: var(--t-base); }
.fh-cta { flex: none; }

/* Carril con fondo en vez de un subrayado suelto.
 *
 * Con cinco pestañas la fila no cabe en un teléfono y se desliza. Antes era un
 * `overflow-x: auto` sobre fondo blanco: sin pista de que hubiera más, la
 * quinta («Tratamientos») quedaba invisible y nadie la buscaba. El carril
 * tintado da el borde donde el contenido se corta, que es lo que hace legible
 * que ahí sigue habiendo cosa.
 *
 * `scroll-snap` para que al soltar quede una pestaña completa a la vista y no
 * media cortada. */
.tabs {
  display: flex; gap: 0.25rem;
  overflow-x: auto; scroll-snap-type: x proximity;
  -webkit-overflow-scrolling: touch; scrollbar-width: none;
  padding: 0.3rem; margin-bottom: 1.4rem;
  background: var(--brand-tint); border-radius: var(--r-full);
}
.tabs::-webkit-scrollbar { display: none; }
.tab {
  display: flex; align-items: center; gap: 0.45rem; white-space: nowrap;
  scroll-snap-align: start;
  padding: 0.55rem 0.95rem; border-radius: var(--r-full);
  font-size: var(--t-sm); font-weight: 600; color: var(--ink-soft);
  transition: color 0.2s var(--ease), background 0.2s var(--ease);
}
.tab :deep(svg) { color: var(--tono); transition: color 0.2s var(--ease); }
/* Hover sobre blanco: ahí el color de la pestaña pasa como texto en todos los
   tonos (el peor, turquesa, da 4.93). */
.tab:hover { color: var(--tono); background: var(--surface); }
/* La activa se rellena con SU color, no se subraya: sobre un carril tintado un
   subrayado de 2px casi no se ve, y el relleno además funciona sin depender
   del color —es la única pestaña con fondo sólido—. */
.tab.active, .tab.active:hover {
  color: #fff; background: var(--tono);
  box-shadow: 0 2px 6px rgba(16, 26, 61, 0.18);
}
.tab.active :deep(svg) { color: #fff; }

.grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); gap: 1.4rem; }

.consulta-list, .hist-full { list-style: none; padding: 0; display: flex; flex-direction: column; gap: 1rem; }
.consulta-item { display: grid; grid-template-columns: auto 1fr auto; gap: 0.8rem; align-items: start; padding-bottom: 1rem; border-bottom: 1px solid var(--line-tabla); }
.consulta-item:last-child { border-bottom: none; padding-bottom: 0; }
.consulta-ic { width: 38px; height: 38px; border-radius: 10px; background: var(--brand-tint); color: var(--brand); display: grid; place-items: center; flex: none; }
.consulta-fecha { font-size: var(--t-micro); display: block; }
.consulta-item strong { font-size: var(--t-base); }
.consulta-item .muted { font-size: var(--t-micro); }

.info-extra { display: flex; flex-direction: column; gap: 0.9rem; }
.info-extra div { display: grid; grid-template-columns: 130px 1fr; gap: 0.5rem; }
.info-extra dt { font-weight: 600; font-size: var(--t-sm); }
.info-extra dd { margin: 0; font-size: var(--t-sm); color: var(--ink-soft); }
.cap { text-transform: capitalize; }

.hist-full-item { border: 1px solid var(--line); border-radius: var(--r-md); padding: 1rem; }
.hfi-top { display: flex; align-items: center; gap: 0.7rem; margin-bottom: 0.6rem; }
.hfi-top strong { flex: 1; }
.hfi-top .muted { font-size: var(--t-micro); }
.hfi-body { display: flex; flex-direction: column; gap: 0.3rem; padding-left: 3rem; font-size: var(--t-sm); }
.hfi-label { font-weight: 600; color: var(--brand); }

.doc-list { list-style: none; padding: 0; display: flex; flex-direction: column; gap: 0.7rem; }
.doc-item { display: flex; align-items: center; gap: 0.8rem; border: 1px solid var(--line); border-radius: var(--r-md); padding: 0.8rem 1rem; }
.doc-ic { color: var(--brand); flex: none; }
.doc-info { flex: 1; display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
.doc-nombre { font-weight: 600; color: var(--brand-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.doc-nombre:hover { text-decoration: underline; }
/* Era un <a>; ahora es un <button> porque la descarga necesita el token y hay
   que pedirla por axios. Se le quita todo lo que un botón trae de fábrica para
   que siga leyéndose como el enlace que era. */
.doc-boton { background: none; border: 0; padding: 0; font: inherit; text-align: left; cursor: pointer; }
.doc-boton:disabled { opacity: 0.55; cursor: progress; }
.doc-error { color: var(--danger); font-size: var(--t-sm); margin-top: 0.6rem; }

/* ── Tratamientos ── */
.trat { display: flex; flex-direction: column; gap: 1.2rem; }
.trat-control {
  display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; margin: 0;
  padding: 0.8rem 1.1rem; border-radius: var(--r-md);
  background: var(--accent-tint); color: var(--accent-strong); font-size: var(--t-base);
}
.trat-control span { flex: 1; min-width: 12rem; color: var(--ink); }
.trat-ultimo { padding: 0; overflow: hidden; border-color: var(--accent); }
.trat-cab {
  display: flex; flex-direction: column; gap: 0.15rem;
  padding: 1.1rem 1.4rem; background: var(--accent-tint); border-bottom: 1px solid var(--line);
}
.trat-etq { font-size: var(--t-micro); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--accent-strong); }
.trat-cab strong { font-size: var(--t-lg); color: var(--ink); }
.trat-cab .muted { font-size: var(--t-sm); }
.trat-cuerpo { display: flex; flex-direction: column; gap: 1rem; padding: 1.1rem 1.4rem 1.3rem; }
.trat-dx { margin: 0; font-size: var(--t-sm); }
.trat-bloque h3 {
  display: flex; align-items: center; gap: 0.4rem; margin: 0 0 0.35rem;
  font-size: var(--t-base); color: var(--brand);
}
/* Como lo escribió el veterinario: una dosis por línea sigue siendo una dosis
   por línea. */
.trat-texto { margin: 0; font-size: var(--t-base); line-height: 1.55; white-space: pre-line; }
.trat-lista { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.trat-item { display: flex; flex-direction: column; gap: 0.3rem; padding: 0.9rem 0; border-top: 1px solid var(--line-tabla); }
.trat-item:first-child { border-top: 0; padding-top: 0; }
.trat-item-cab { display: flex; align-items: baseline; justify-content: space-between; gap: 0.3rem 1rem; flex-wrap: wrap; }
.trat-item .trat-texto { font-size: var(--t-sm); }
.trat-pie { margin: 0; font-size: var(--t-sm); text-align: center; }
.trat-pie a { color: var(--accent); font-weight: 600; }

/* ── Vacunas ── */
.vac { display: flex; flex-direction: column; gap: 1.2rem; }
/* Mismo molde que el próximo control de Tratamientos; en rojo solo si venció. */
.vac-aviso {
  display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; margin: 0;
  padding: 0.8rem 1.1rem; border-radius: var(--r-md); font-size: var(--t-base);
  background: var(--accent-tint); color: var(--accent-strong);
}
.vac-aviso.vencido { background: var(--danger-tint); color: var(--danger); }
.vac-aviso span { flex: 1; min-width: 12rem; color: var(--ink); }
.vac-lista { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.vac-item {
  display: flex; align-items: flex-start; gap: 0.8rem;
  padding: 0.85rem 0; border-top: 1px solid var(--line-tabla);
}
.vac-item:first-child { border-top: 0; padding-top: 0; }
.vac-txt { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 0.15rem; }
.vac-txt strong { font-size: var(--t-base); }
.vac-txt .muted { font-size: var(--t-sm); }
.vac-item .chip { flex: none; margin-top: 0.2rem; }
.vac-nota { margin: 0.9rem 0 0; font-size: var(--t-sm); }
/* Fecha en columna fija: se lee hacia abajo como un carnet. */
.vac-dosis { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.vac-dosis li {
  display: grid; grid-template-columns: 6.5rem minmax(0, 1fr) auto; gap: 0.8rem;
  padding: 0.6rem 0; border-top: 1px solid var(--line-tabla); font-size: var(--t-base);
}
.vac-dosis li:first-child { border-top: 0; padding-top: 0; }
.vac-fecha { color: var(--ink-soft); font-variant-numeric: tabular-nums; }
.vac-dosis strong { font-weight: 600; }
.vac-dosis .chip { align-self: start; justify-self: end; }
/* En el teléfono la etiqueta baja bajo el nombre: en la misma fila le dejaba
   al nombre de la vacuna una columna de tres letras. */
@media (max-width: 560px) {
  .vac-dosis li { grid-template-columns: 5.6rem minmax(0, 1fr); row-gap: 0.3rem; }
  .vac-dosis .chip { grid-column: 2; justify-self: start; }
}

.pronto { text-align: center; padding: 3rem 1.5rem; }
.pronto-ic { display: inline-grid; place-items: center; width: 72px; height: 72px; border-radius: 50%; background: var(--brand-tint); color: var(--brand); margin-bottom: 1rem; }
.pronto h2 { color: var(--brand); margin-bottom: 0.5rem; }
.pronto .muted { max-width: 46ch; margin: 0 auto 1.3rem; }

@media (max-width: 860px) {
  .grid-2 { grid-template-columns: 1fr; }
  .ficha-head { flex-direction: column; }
  .fh-cta { align-self: stretch; }
}
</style>
