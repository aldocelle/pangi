<script setup>
/* Pagos: lo que el tutor pagó en la clínica y en línea, y lo que debe.

   Sale de `GET /pagos/mios`, que trae dos listas que NO se suman entre sí:
   las compras del mostrador (`transacciones`, con la misma regla que el panel)
   y los abonos de reservas pagados por Getnet. El saldo pendiente sale solo de
   las compras: un abono por pagar no es deuda, es una hora que todavía no se
   confirmó.

   No hay facturas ni boletas acá: el sistema no las emite (las da la clínica
   al cobrar). Lo que se muestra es el detalle de cada compra. */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { RouterLink } from 'vue-router';
import { api, apiError, formatCLP, fechaLarga, horaCorta } from '../api.js';
import { CLINICA } from '../config.js';
import BaseIcon from '../components/BaseIcon.vue';

const datos = ref(null);
const cargando = ref(true);
const error = ref('');

async function cargar() {
  cargando.value = true;
  error.value = '';
  try {
    const { data } = await api.get('/pagos/mios');
    datos.value = data;
  } catch (e) {
    error.value = apiError(e, 'No pudimos cargar tus pagos.');
  } finally {
    cargando.value = false;
  }
}
onMounted(cargar);

const compras = computed(() => datos.value?.compras || []);

/* Una reserva del portal sin pagar se libera a los 15 minutos. El backend ya
   no la manda pasado el plazo, pero si la página quedó abierta se esconde
   sola: «Pagar» sobre una hora liberada fue justo lo que se vio en pruebas. */
const ahora = ref(Date.now());
let reloj = null;
onMounted(() => { reloj = setInterval(() => { ahora.value = Date.now(); }, 15_000); });
onUnmounted(() => clearInterval(reloj));
const vencida = (a) => !a.pagado && !!a.pagar_hasta && Date.parse(a.pagar_hasta) <= ahora.value;
const abonos = computed(() => (datos.value?.abonos || []).filter((a) => !vencida(a)));
const horaLimite = (iso) => new Date(iso).toTimeString().slice(0, 5);
const porPagar = computed(() => abonos.value.filter((a) => !a.pagado));
const pendiente = computed(() => Number(datos.value?.resumen?.pendiente || 0));
const anio = new Date().getFullYear();

/* La pestaña arranca en la que tiene algo: quien solo reservó por la web no
   tiene compras en el mostrador, y abrirle una lista vacía parece un error. */
const pestana = ref('');
const pestanaActiva = computed(() => pestana.value || (compras.value.length || !abonos.value.length ? 'compras' : 'abonos'));

const soloPendientes = ref(false);
const comprasVisibles = computed(() =>
  soloPendientes.value ? compras.value.filter((c) => Number(c.pendiente) > 0) : compras.value);

const gruposCompras = computed(() => {
  const grupos = new Map();
  for (const c of comprasVisibles.value) {
    const y = (c.fecha || '').slice(0, 4) || 'Sin fecha';
    if (!grupos.has(y)) grupos.set(y, []);
    grupos.get(y).push(c);
  }
  return [...grupos.entries()].map(([anioGrupo, items]) => ({
    anio: anioGrupo,
    items,
    total: items.reduce((s, c) => s + Number(c.total || 0), 0),
  }));
});

const abierta = ref(null);
const alternar = (id) => { abierta.value = abierta.value === id ? null : id; };

const clp = (n) => formatCLP(n)?.replace(' CLP', '') ?? '—';
const pesos = (n) => `$${clp(n)}`;

const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const mesCorto = (iso) => (iso ? MESES[new Date(iso).getMonth()] : '');

const CHIP = { pagada: 'chip-ok', parcial: 'chip-warn', pendiente: 'chip-danger' };

function nombreCorto(c) {
  if (c.lineas.length === 1) return c.lineas[0].nombre;
  if (c.lineas.length > 1) return `${c.lineas[0].nombre} y ${c.lineas.length - 1} más`;
  return c.detalle;
}

const mensajeSaldo = computed(() => encodeURIComponent(
  `Hola, quiero pagar mi saldo pendiente de ${pesos(pendiente.value)} en la clínica.`));
</script>

<template>
  <div class="page">
    <header class="page-head">
      <h1><span class="head-ic"><BaseIcon name="card" :size="22" /></span> Pagos</h1>
      <p class="muted">Tus compras en la clínica y los abonos de tus reservas en línea.</p>
    </header>

    <!-- Esqueleto -->
    <template v-if="cargando">
      <div class="resumen" aria-busy="true" aria-label="Cargando pagos">
        <div v-for="n in 3" :key="n" class="tile"><span class="sk" style="width: 45%"></span><span class="sk sk-num"></span></div>
      </div>
      <div class="card lista-sk"><span v-for="n in 4" :key="n" class="sk sk-fila"></span></div>
    </template>

    <div v-else-if="error" class="card card-pad aviso-error">
      <p>{{ error }}</p>
      <button type="button" class="btn btn-ghost btn-sm" @click="cargar"><BaseIcon name="refresh" :size="16" /> Reintentar</button>
    </div>

    <section v-else-if="!compras.length && !abonos.length" class="card vacio">
      <span class="vacio-ic"><BaseIcon name="card" :size="30" /></span>
      <strong>Aún no hay pagos registrados</strong>
      <p class="muted">
        Cuando pagues una consulta, un medicamento o el abono de una reserva, el detalle
        aparecerá aquí.
      </p>
      <RouterLink to="/citas/nueva" class="btn btn-primary btn-sm"><BaseIcon name="plus" :size="16" /> Reservar una hora</RouterLink>
    </section>

    <template v-else>
      <!-- Resumen -->
      <div class="resumen">
        <div class="tile" :class="pendiente > 0 ? 'tile-debe' : 'tile-ok'">
          <span class="tile-label">Saldo pendiente</span>
          <strong class="tile-num">{{ pesos(pendiente) }}</strong>
          <span v-if="pendiente > 0" class="tile-nota">
            Se paga en la clínica.
            <a :href="`https://wa.me/${CLINICA.telefonoPlano}?text=${mensajeSaldo}`" target="_blank" rel="noopener">Consultar por WhatsApp</a>
          </span>
          <span v-else class="tile-nota"><BaseIcon name="check" :size="14" /> Estás al día</span>
        </div>
        <div class="tile">
          <span class="tile-label">Pagado en {{ anio }}</span>
          <strong class="tile-num">{{ pesos(datos.resumen.pagado_anio) }}</strong>
          <span class="tile-nota">En compras en la clínica</span>
        </div>
        <div class="tile" :class="{ 'tile-accion': porPagar.length }">
          <span class="tile-label">Abonos por pagar</span>
          <strong class="tile-num">{{ porPagar.length ? pesos(porPagar.reduce((s, a) => s + a.monto, 0)) : '—' }}</strong>
          <RouterLink v-if="porPagar.length === 1" :to="`/pago/${porPagar[0].cita_id}`" class="tile-cta">
            Pagar ahora <BaseIcon name="chevron" :size="14" />
          </RouterLink>
          <button v-else-if="porPagar.length > 1" type="button" class="tile-cta" @click="pestana = 'abonos'">
            Ver {{ porPagar.length }} reservas <BaseIcon name="chevron" :size="14" />
          </button>
          <span v-else class="tile-nota">Ninguna reserva espera pago</span>
        </div>
      </div>

      <!-- Pestañas -->
      <div class="barra">
        <div class="seg" role="tablist" aria-label="Tipo de pago">
          <button type="button" role="tab" :aria-selected="pestanaActiva === 'compras'" :class="{ on: pestanaActiva === 'compras' }" @click="pestana = 'compras'">
            En la clínica <span class="n">{{ compras.length }}</span>
          </button>
          <button type="button" role="tab" :aria-selected="pestanaActiva === 'abonos'" :class="{ on: pestanaActiva === 'abonos' }" @click="pestana = 'abonos'">
            Abonos en línea <span class="n">{{ abonos.length }}</span>
          </button>
        </div>
        <label v-if="pestanaActiva === 'compras' && pendiente > 0" class="solo-pend">
          <input v-model="soloPendientes" type="checkbox" /> Solo con saldo pendiente
        </label>
      </div>

      <!-- Compras en la clínica -->
      <template v-if="pestanaActiva === 'compras'">
        <section v-if="!compras.length" class="card vacio vacio-chico">
          <strong>Sin compras en la clínica</strong>
          <p class="muted">Lo que pagues en el mostrador —consultas, medicamentos, alimento— aparecerá aquí.</p>
        </section>

        <section v-for="g in gruposCompras" :key="g.anio" class="grupo" :aria-label="`Compras de ${g.anio}`">
          <h2 class="grupo-titulo">{{ g.anio }} <span class="grupo-total">{{ pesos(g.total) }}</span></h2>
          <ul class="lista card">
            <li v-for="c in g.items" :key="c.id" class="compra" :class="{ abierta: abierta === c.id }">
              <button type="button" class="compra-fila" :aria-expanded="abierta === c.id" @click="alternar(c.id)">
                <span class="compra-fecha">
                  <span class="dia">{{ new Date(c.fecha).getDate() }}</span>
                  <span class="mes">{{ mesCorto(c.fecha) }}</span>
                </span>
                <span class="compra-txt">
                  <span class="compra-nombre">{{ nombreCorto(c) }}</span>
                  <span class="compra-meta">
                    N° {{ c.numero }}
                    <template v-if="c.pacientes.length"> · {{ c.pacientes.map((p) => p.nombre).join(', ') }}</template>
                    · {{ c.detalle }}
                  </span>
                </span>
                <span class="compra-monto">
                  <strong>{{ pesos(c.total) }}</strong>
                  <span class="chip" :class="CHIP[c.estado]">{{ c.estado_label }}</span>
                </span>
                <BaseIcon name="chevronDown" :size="18" class="compra-flecha" />
              </button>

              <div v-if="abierta === c.id" class="compra-detalle">
                <table class="det-tabla">
                  <caption class="sr-only">Detalle de la compra N° {{ c.numero }}</caption>
                  <thead>
                    <tr><th scope="col">Detalle</th><th scope="col" class="num">Cant.</th><th scope="col" class="num">Total</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(l, i) in c.lineas" :key="i">
                      <td>
                        <span class="l-nombre">{{ l.nombre }}</span>
                        <span v-if="l.mascota || l.cantidad_devuelta" class="l-meta">
                          <template v-if="l.mascota">{{ l.mascota }}</template>
                          <template v-if="l.cantidad_devuelta"> · {{ l.cantidad_devuelta }} devuelto{{ l.cantidad_devuelta > 1 ? 's' : '' }}</template>
                        </span>
                      </td>
                      <td class="num">{{ l.cantidad }}</td>
                      <td class="num">{{ pesos(l.subtotal) }}</td>
                    </tr>
                  </tbody>
                </table>
                <dl class="totales">
                  <template v-if="c.descuento > 0"><dt>Descuento</dt><dd>−{{ pesos(c.descuento) }}</dd></template>
                  <template v-if="c.devuelto > 0"><dt>Devuelto</dt><dd>−{{ pesos(c.devuelto) }}</dd></template>
                  <dt class="t-total">Total</dt><dd class="t-total">{{ pesos(c.total) }}</dd>
                  <template v-for="m in c.medios" :key="m.medio"><dt>Pagado · {{ m.medio }}</dt><dd>{{ pesos(m.monto) }}</dd></template>
                  <template v-if="c.pendiente > 0"><dt class="t-debe">Pendiente</dt><dd class="t-debe">{{ pesos(c.pendiente) }}</dd></template>
                </dl>
              </div>
            </li>
          </ul>
        </section>
        <p v-if="compras.length" class="nota-pie muted">
          La boleta o factura tributaria la entrega la clínica al momento de pagar. Si necesitas una copia, escríbenos.
        </p>
      </template>

      <!-- Abonos en línea -->
      <template v-else>
        <section v-if="!abonos.length" class="card vacio vacio-chico">
          <strong>Sin abonos en línea</strong>
          <p class="muted">Algunas consultas piden un abono al reservar por la web; lo verás aquí.</p>
        </section>
        <ul v-else class="lista card">
          <li v-for="a in abonos" :key="a.cita_id" class="abono">
            <span class="abono-ic" :class="a.pagado ? 'ok' : 'espera'">
              <BaseIcon :name="a.pagado ? 'check' : 'clock'" :size="18" />
            </span>
            <span class="compra-txt">
              <span class="compra-nombre">{{ a.servicio || 'Reserva' }}<template v-if="a.mascota"> · {{ a.mascota }}</template></span>
              <span class="compra-meta">
                Hora del {{ fechaLarga(a.fecha_cita) }}, {{ horaCorta(a.fecha_cita) }}
                <template v-if="a.pagado && a.referencia"> · Ref. {{ a.referencia }}</template>
              </span>
              <span v-if="!a.pagado && a.pagar_hasta" class="abono-plazo">
                <BaseIcon name="clock" :size="13" /> Paga antes de las {{ horaLimite(a.pagar_hasta) }} o la hora se libera
              </span>
            </span>
            <span class="compra-monto">
              <strong>{{ pesos(a.monto) }}</strong>
              <span v-if="a.pagado" class="chip chip-ok">{{ a.modalidad === 'total' ? 'Pagado total' : 'Abono pagado' }}</span>
              <RouterLink v-else :to="`/pago/${a.cita_id}`" class="btn btn-primary btn-sm">Pagar abono</RouterLink>
            </span>
          </li>
        </ul>
      </template>
    </template>
  </div>
</template>

<style scoped>
.page-head { margin-bottom: 1.5rem; }
.page-head h1 { font-size: var(--t-2xl); color: var(--brand); display: flex; align-items: center; gap: 0.6rem; }
.head-ic { width: 42px; height: 42px; border-radius: 13px; background: var(--brand); color: #fff; display: grid; place-items: center; box-shadow: 0 6px 16px rgba(38, 50, 110, 0.22); }
.page-head .muted { margin-top: 0.35rem; font-size: var(--t-sm); }

.aviso-error { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.aviso-error p { margin: 0; color: var(--danger); }

.vacio { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 0.5rem; padding: 3rem 1.4rem; }
.vacio-chico { padding: 2rem 1.4rem; }
.vacio-ic { width: 72px; height: 72px; border-radius: 22px; background: linear-gradient(135deg, var(--brand-tint), var(--accent-tint)); color: var(--brand); display: grid; place-items: center; margin-bottom: 0.4rem; }
.vacio strong { font-size: var(--t-lg); color: var(--brand); }
.vacio p { margin: 0 0 0.6rem; max-width: 46ch; font-size: var(--t-sm); }

/* ── Resumen ── */
.resumen { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.6rem; }
.tile {
  display: flex; flex-direction: column; gap: 0.3rem; padding: 1.1rem 1.25rem;
  background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg);
}
.tile-label { font-size: var(--t-sm); font-weight: 600; color: var(--ink-soft); }
.tile-num { font-family: var(--font-display); font-size: var(--t-3xl); font-weight: 600; color: var(--brand); line-height: 1.15; font-variant-numeric: tabular-nums; }
.tile-nota { display: inline-flex; align-items: center; gap: 0.3rem; flex-wrap: wrap; font-size: var(--t-micro); color: var(--ink-soft); }
.tile-nota a { color: var(--accent-strong); font-weight: 600; }
.tile-nota a:hover { text-decoration: underline; }
.tile-ok .tile-nota { color: var(--ok); font-weight: 600; }
.tile-debe { background: var(--danger-tint); border-color: transparent; }
.tile-debe .tile-num { color: var(--danger); }
.tile-debe .tile-nota { color: var(--ink); }
.tile-accion { background: var(--accent-tint); border-color: transparent; }
.tile-cta { display: inline-flex; align-items: center; gap: 0.25rem; align-self: flex-start; font-size: var(--t-sm); font-weight: 700; color: var(--accent-strong); }
.tile-cta:hover { text-decoration: underline; }

/* ── Barra de pestañas ── */
.barra { display: flex; align-items: center; justify-content: space-between; gap: 0.8rem 1rem; flex-wrap: wrap; margin-bottom: 1.2rem; }
.seg { display: inline-flex; padding: 4px; gap: 4px; border-radius: var(--r-full); background: var(--surface); border: 1px solid var(--line); }
.seg button {
  display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.45rem 1rem; border-radius: var(--r-full);
  font-size: var(--t-sm); font-weight: 600; color: var(--ink-soft);
  transition: background var(--dur-fast) var(--ease), color var(--dur-fast) var(--ease);
}
.seg button:hover:not(.on) { background: var(--accent-hover); color: var(--accent-strong); }
.seg button.on { background: var(--brand); color: #fff; }
.n { font-size: var(--t-micro); font-weight: 700; opacity: 0.7; }
.solo-pend { display: inline-flex; align-items: center; gap: 0.45rem; font-size: var(--t-sm); color: var(--ink-soft); cursor: pointer; }

/* ── Grupos y lista ── */
.grupo + .grupo { margin-top: 1.6rem; }
.grupo-titulo {
  display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.7rem;
  font-family: var(--font); font-size: var(--t-sm); font-weight: 700; color: var(--ink-soft); letter-spacing: 0;
}
.grupo-titulo::after { content: ''; flex: 1; height: 1px; background: var(--line-strong); order: 1; }
.grupo-total { order: 2; font-weight: 600; font-variant-numeric: tabular-nums; }

.lista { list-style: none; margin: 0; padding: 0; overflow: hidden; }
.compra + .compra, .abono + .abono { border-top: 1px solid var(--line-tabla); }

.compra-fila, .abono {
  display: flex; align-items: center; gap: 1rem; width: 100%; padding: 0.95rem 1.25rem; text-align: left;
}
.compra-fila { transition: background var(--dur-fast) var(--ease); }
.compra-fila:hover { background: var(--bg); }
.compra.abierta .compra-fila { background: var(--bg); }

.compra-fecha {
  width: 46px; height: 50px; flex: none; border-radius: 12px; background: var(--brand-tint); color: var(--brand);
  display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1.05;
}
.compra-fecha .dia { font-family: var(--font-display); font-size: var(--t-lg); font-weight: 600; }
.compra-fecha .mes { font-size: var(--t-micro); font-weight: 600; text-transform: capitalize; }

.compra-txt { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 0.15rem; }
.compra-nombre { font-weight: 600; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.compra-meta { font-size: var(--t-micro); color: var(--ink-soft); }
.compra-monto { flex: none; display: flex; flex-direction: column; align-items: flex-end; gap: 0.3rem; }
.compra-monto strong { font-variant-numeric: tabular-nums; color: var(--brand); }
.compra-flecha { flex: none; color: var(--ink-soft); transition: transform var(--dur-fast) var(--ease); }
.compra.abierta .compra-flecha { transform: rotate(180deg); }

.compra-detalle { padding: 0.4rem 1.25rem 1.2rem calc(1.25rem + 46px + 1rem); background: var(--bg); }
.det-tabla { width: 100%; border-collapse: collapse; font-size: var(--t-sm); background: var(--surface); border-radius: var(--r-md); overflow: hidden; }
.det-tabla th { text-align: left; font-size: var(--t-micro); font-weight: 700; color: var(--ink-soft); padding: 0.55rem 0.9rem; border-bottom: 1px solid var(--line-tabla); }
.det-tabla td { padding: 0.6rem 0.9rem; vertical-align: top; }
.det-tabla tbody tr + tr td { border-top: 1px solid var(--line); }
.det-tabla .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.l-nombre { display: block; color: var(--ink); }
.l-meta { display: block; font-size: var(--t-micro); color: var(--ink-soft); }
.totales { display: grid; grid-template-columns: 1fr auto; gap: 0.3rem 1.5rem; margin: 0.8rem 0 0 auto; max-width: 300px; font-size: var(--t-sm); }
.totales dt { color: var(--ink-soft); }
.totales dd { margin: 0; text-align: right; font-variant-numeric: tabular-nums; }
.totales .t-total { color: var(--brand); font-weight: 700; padding-top: 0.3rem; border-top: 1px solid var(--line-strong); }
.totales .t-debe { color: var(--danger); font-weight: 700; }

.abono-ic { width: 42px; height: 42px; border-radius: 50%; flex: none; display: grid; place-items: center; }
.abono-ic.ok { background: var(--ok-tint); color: var(--ok); }
.abono-ic.espera { background: var(--warn-tint); color: var(--warn-ink); }
.abono-plazo { display: inline-flex; align-items: center; gap: 0.3rem; align-self: flex-start; margin-top: 0.2rem;
  padding: 0.1rem 0.55rem; border-radius: var(--r-full); background: var(--warn-tint); color: var(--warn-ink);
  font-size: var(--t-micro); font-weight: 600; }

.nota-pie { font-size: var(--t-micro); margin-top: 1rem; text-align: center; }

/* ── Esqueleto ── */
.sk { display: block; height: 12px; border-radius: 8px; background: linear-gradient(90deg, var(--bg) 0%, #f7f8fd 50%, var(--bg) 100%); background-size: 200% 100%; animation: brillo 1.3s ease-in-out infinite; }
.sk-num { height: 28px; width: 70%; margin-top: 0.4rem; }
.lista-sk { display: flex; flex-direction: column; gap: 1.2rem; padding: 1.4rem; }
.sk-fila { height: 40px; }
@keyframes brillo { from { background-position: 100% 0; } to { background-position: -100% 0; } }
@media (prefers-reduced-motion: reduce) { .sk { animation: none; } }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

@media (max-width: 760px) {
  .resumen { grid-template-columns: 1fr 1fr; }
  .resumen .tile:first-child { grid-column: 1 / -1; }
}
@media (max-width: 560px) {
  .seg { width: 100%; }
  .seg button { flex: 1; justify-content: center; padding: 0.45rem 0.6rem; white-space: nowrap; }
  /* El monto baja a su propia línea: al lado del nombre lo dejaba en dos
     palabras por renglón. */
  .compra-fila, .abono { flex-wrap: wrap; gap: 0.5rem 0.75rem; padding: 0.9rem 1rem; align-items: flex-start; }
  .compra-txt { flex-basis: calc(100% - 46px - 0.75rem); }
  .compra-nombre { white-space: normal; }
  .compra-flecha { display: none; }
  .compra-detalle { padding: 0.4rem 1rem 1rem; }
  .compra-monto { flex-direction: row; align-items: center; width: 100%; justify-content: space-between; padding-left: calc(46px + 0.75rem); }
  .abono .compra-monto { padding-left: calc(42px + 0.75rem); }
}
</style>
