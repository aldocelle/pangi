<script setup>
/* Mensajes: qué avisos salieron, por qué canal, y el envío masivo de lo
   vencido — la pantalla «WhatsApp Mensajes» de ACVet (captura 9).

   Lo que NO se trasladó, a propósito: la barra de cupo «36 / 100» y «comprar
   más mensajes». Eso es el plan de ACVet; la API de WhatsApp de Meta cobra
   por conversación y no tiene ese contador. En su lugar va el conteo real de
   lo que este sistema avisó, por canal.

   El masivo sale por correo y, en modo pruebas, SOLO CUENTA: el backend no
   envía nada mientras `CORREOS_AUTOMATICOS` esté apagado. */
import { ref, computed, onMounted } from 'vue';
import { api, apiError } from '../api.js';
import Paginador from '../components/Paginador.vue';

const CANALES = { email: 'Email', whatsapp: 'WhatsApp', telefono: 'Teléfono', mostrador: 'Mostrador', 'sin canal': 'Sin canal' };

const hoy = new Date();
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
function lunes(d) {
  const x = new Date(d);
  x.setDate(x.getDate() - ((x.getDay() + 6) % 7));
  return x;
}
function sumar(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }

const RANGOS = {
  'esta': () => [lunes(hoy), sumar(lunes(hoy), 6)],
  'anterior': () => [sumar(lunes(hoy), -7), sumar(lunes(hoy), -1)],
  'mes': () => [new Date(hoy.getFullYear(), hoy.getMonth(), 1), new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0)],
};
const rango = ref('esta');
const desde = ref(iso(RANGOS.esta()[0]));
const hasta = ref(iso(RANGOS.esta()[1]));
const q = ref('');

const items = ref([]);
const total = ref(0);
const pagina = ref(1);
const totalPaginas = ref(1);
const limite = ref(20);
const resumen = ref({ por_canal: {}, total: 0 });
const loading = ref(true);
const error = ref('');

function elegirRango(id) {
  rango.value = id;
  const [a, b] = RANGOS[id]();
  desde.value = iso(a);
  hasta.value = iso(b);
  pagina.value = 1;
  cargar();
}

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const params = { page: pagina.value, limit: 20, enviado_desde: desde.value, enviado_hasta: hasta.value };
    if (q.value.trim()) params.q = q.value.trim();
    const [l, r] = await Promise.all([
      api.get('/recordatorios', { params }),
      api.get('/recordatorios/resumen-envios', { params: { desde: desde.value, hasta: hasta.value } }),
    ]);
    items.value = l.data.items;
    total.value = l.data.total;
    totalPaginas.value = l.data.total_paginas;
    limite.value = l.data.limit;
    resumen.value = r.data;
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar los mensajes.');
  } finally {
    loading.value = false;
  }
}

let timer;
function buscar() {
  clearTimeout(timer);
  timer = setTimeout(() => { pagina.value = 1; cargar(); }, 250);
}

/* ── Envío masivo: primero se calcula, después se confirma sobre ESE número ── */
const masivo = ref(null);
const masivoBusy = ref(false);
async function prepararMasivo() {
  masivoBusy.value = true;
  error.value = '';
  try {
    const { data } = await api.post('/recordatorios/envio-masivo', { simular: true });
    masivo.value = data;
  } catch (e) {
    error.value = apiError(e, 'No se pudo calcular el envío.');
  } finally {
    masivoBusy.value = false;
  }
}
async function enviarMasivo() {
  masivoBusy.value = true;
  try {
    const { data } = await api.post('/recordatorios/envio-masivo', { simular: false });
    masivo.value = data;
    if (!data.simulado) cargar();
  } catch (e) {
    error.value = apiError(e, 'No se pudo enviar.');
  } finally {
    masivoBusy.value = false;
  }
}

const canales = computed(() => Object.entries(resumen.value.por_canal || {}));
function fecha(isoTs) {
  if (!isoTs) return '';
  const [d, h] = isoTs.split('T');
  const [y, m, dd] = d.split('-');
  return `${dd}-${m}-${y}${h ? ' ' + h.slice(0, 5) : ''}`;
}

onMounted(cargar);
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Mensajes</h1>
        <p class="page-sub">Los avisos de recordatorios que salieron, por canal.</p>
      </div>
    </div>

    <section class="card bloque">
      <div class="conteo">
        <div class="cifra"><span class="num">{{ resumen.total }}</span><span class="etq">avisos en el período</span></div>
        <div v-for="[c, n] in canales" :key="c" class="cifra"><span class="num">{{ n }}</span><span class="etq">{{ CANALES[c] || c }}</span></div>
      </div>
      <p class="nota">El cupo de mensajes de ACVet no aplica acá: WhatsApp (Meta) cobra por conversación y no tiene un contador de «100 mensajes».
        Lo que se cuenta es lo que este sistema registró como avisado.</p>
    </section>

    <section class="card bloque masivo">
      <div>
        <strong>Recordatorios masivos — preventiva vencida</strong>
        <p class="nota">Un correo a cada tutor con un recordatorio vencido de los últimos 90 días. Nunca a mascotas fallecidas
          ni a correos inexistentes, y como máximo 50 por vez.</p>
      </div>
      <button class="btn btn-ghost" :disabled="masivoBusy" @click="prepararMasivo">Calcular envío</button>
      <div v-if="masivo" class="masivo-res" role="status">
        <p>
          Vencidos: <strong>{{ masivo.vencidos }}</strong> · se enviarían: <strong>{{ masivo.enviables }}</strong>
          · sin correo: {{ masivo.sin_correo }} · fallecidas: {{ masivo.fallecidas }}
          <template v-if="masivo.quedan_despues"> · quedan {{ masivo.quedan_despues }} para otra tanda</template>
        </p>
        <p v-if="masivo.simulado && masivo.envio_habilitado === false" class="pruebas">
          Modo pruebas: el envío está apagado y no se mandó nada. Se habilita cuando la clínica encienda los envíos automáticos.
        </p>
        <p v-else-if="!masivo.simulado" class="ok-text">Enviados: {{ masivo.enviados }}.</p>
        <button v-if="masivo.simulado && masivo.envio_habilitado && masivo.enviables" class="btn btn-primary"
          :disabled="masivoBusy" @click="enviarMasivo">Enviar {{ masivo.enviables }} correos</button>
      </div>
    </section>

    <div class="controles">
      <div class="seg" role="group" aria-label="Período">
        <button :class="{ activo: rango === 'esta' }" @click="elegirRango('esta')">Esta semana</button>
        <button :class="{ activo: rango === 'anterior' }" @click="elegirRango('anterior')">Semana anterior</button>
        <button :class="{ activo: rango === 'mes' }" @click="elegirRango('mes')">Este mes</button>
      </div>
      <label class="fecha">Desde <input class="input" type="date" v-model="desde" @change="rango = ''; pagina = 1; cargar()" /></label>
      <label class="fecha">Hasta <input class="input" type="date" v-model="hasta" @change="rango = ''; pagina = 1; cargar()" /></label>
      <input class="input buscador" v-model="q" placeholder="Buscar por paciente, tutor o recordatorio…" aria-label="Buscar" @input="buscar" />
    </div>

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>
    <p v-else-if="!items.length" class="empty">No salió ningún aviso en este período.</p>
    <div v-else class="card envoltura">
      <table class="tabla-datos">
        <thead><tr><th>Fecha</th><th>Recordatorio</th><th>Paciente</th><th>Tutor</th><th>Canal</th></tr></thead>
        <tbody>
          <tr v-for="r in items" :key="r.id">
            <td class="nowrap">{{ fecha(r.enviado_at) }}</td>
            <td>{{ r.titulo }}</td>
            <td>{{ r.mascota_nombre }}</td>
            <td>{{ r.dueno_nombre || '—' }}</td>
            <td><span class="canal">{{ CANALES[r.canal] || 'Sin canal' }} · Enviado ✓</span></td>
          </tr>
        </tbody>
      </table>
    </div>
    <Paginador v-if="totalPaginas > 1" :page="pagina" :total-paginas="totalPaginas" :total="total" :limit="limite"
      @cambiar="(p) => { pagina = p; cargar(); }" />
  </div>
</template>

<style scoped>
.bloque { padding: 1rem 1.2rem; margin-bottom: 1rem; }
.conteo { display: flex; flex-wrap: wrap; gap: 1.6rem; margin-bottom: 0.6rem; }
.cifra { display: flex; flex-direction: column; }
.num { font-size: var(--t-2xl); font-weight: 700; color: var(--brand-ink); }
.etq { font-size: var(--t-sm); color: var(--ink-soft); }
.nota { color: var(--ink-soft); font-size: var(--t-sm); }
.masivo { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 0.8rem; }
.masivo > div:first-child { flex: 1; min-width: 16rem; }
.masivo-res { width: 100%; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-start; font-size: var(--t-base); }
.pruebas { background: var(--warn-tint); color: var(--warn-ink); border-radius: var(--r-sm); padding: 0.5rem 0.7rem; font-size: var(--t-sm); }
.ok-text { color: var(--ok); }
.controles { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: center; margin-bottom: 0.8rem; }
.seg { display: inline-flex; border: 1px solid var(--line-strong); border-radius: var(--r-sm); overflow: hidden; }
.seg button { border: 0; background: var(--surface); padding: 0.45rem 0.8rem; font-family: var(--font); font-size: var(--t-sm); cursor: pointer; color: var(--ink); }
.seg button + button { border-left: 1px solid var(--line-strong); }
.seg button:hover { background: var(--accent-tint); }
.seg button.activo { background: var(--brand); color: var(--surface); }
.fecha { display: inline-flex; align-items: center; gap: 0.4rem; font-size: var(--t-sm); }
.buscador { flex: 1; min-width: 14rem; }
.envoltura { padding: 0.3rem 0.8rem; overflow-x: auto; }
.nowrap { white-space: nowrap; }
.canal { font-size: var(--t-sm); background: var(--brand-tint); color: var(--brand-ink); border-radius: 999px; padding: 0.15rem 0.6rem; white-space: nowrap; }
</style>
