<script setup>
/* Pago del abono — vuelta desde el Web Checkout de Getnet, y también la
   puerta de entrada para pagar más tarde.

   Es una vista aparte de ReservarView.vue a propósito: el cliente sale del
   sitio hacia Getnet, así que al volver la app se monta de cero y no queda
   nada del estado en memoria (servicio, especialista, formulario). Todo lo
   que se muestra acá viene del backend, indexado por el id de la cita, que
   es lo único que sobrevive al viaje — en la URL de retorno y en el enlace
   del correo de confirmación.

   Dos caminos entran por acá:
     1. Vuelta de Getnet     → /pago/:citaId?requestId=…&reference=…
     2. Enlace del correo    → /pago/:citaId

   El `requestId` de la URL no se usa para decidir nada: el backend ya sabe
   qué sesión corresponde a esta cita y consulta el estado real contra
   Getnet. Confiar en la query sería confiar en algo que el visitante puede
   escribir a mano. */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import BaseIcon from '../components/BaseIcon.vue';
import { api, apiError, formatCLP } from '../api.js';
import { useAuthStore } from '../stores/auth.js';
import { CLINICA, MIN_VENTANA_PAGO } from '../config.js';
import SubirOrdenExamen from '../components/SubirOrdenExamen.vue';
import PoliticaDevolucion from '../components/PoliticaDevolucion.vue';

const props = defineProps({
  citaId: { type: String, required: true },
});

const route = useRoute();
const auth = useAuthStore();

const cargando = ref(true);
const error = ref('');
const cita = ref(null);
const pagando = ref(false);

/* Getnet puede tardar unos segundos en cerrar la sesión después de aprobar
   el cobro, y el cliente vuelve antes. Si llegamos desde el checkout y el
   estado todavía dice "pendiente", se reconsulta unas pocas veces antes de
   mostrar un resultado que probablemente cambie solo — mejor eso que
   anunciar "pago pendiente" a alguien que acaba de pagar. */
const VUELVE_DEL_CHECKOUT = !!route.query.requestId;
const REINTENTOS_MAX = 4;
const ESPERA_MS = 2500;
// `ref` y no una variable suelta: `esperandoConfirmacion` depende de esto,
// y con un `let` normal la pantalla se quedaría en "Confirmando…" para
// siempre (el computed nunca se recalcularía al agotarse los reintentos).
const reintentos = ref(0);
let temporizador = null;

const estadoPago = computed(() => cita.value?.estado_pago || null);

/* ¿La hora ya no es suya? La regla vive en el backend (`hora_liberada`:
   plazo de 15 minutos vencido o reserva «Expirada»). Sin el campo —backend
   anterior— queda al menos el estado «Expirada». */
const liberada = computed(() => !!cita.value && (cita.value.hora_liberada === true || cita.value.estado === 'Expirada'));

/* Cuenta regresiva hasta `pagar_hasta` (UTC). El reloj es el del navegador:
   si está corrido, la cuenta también, pero quien decide es el servidor. Al
   llegar a cero se vuelve a consultar, y es el backend el que la da por
   liberada. */
const ahora = ref(Date.now());
let reloj = null;
const msRestantes = computed(() => {
  const hasta = cita.value?.pagar_hasta ? Date.parse(cita.value.pagar_hasta) : NaN;
  return Number.isFinite(hasta) ? hasta - ahora.value : null;
});
const cuentaRegresiva = computed(() => {
  if (msRestantes.value == null || msRestantes.value <= 0) return '';
  const s = Math.ceil(msRestantes.value / 1000);
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
});
let reconsultadoAlVencer = false;
function tic() {
  ahora.value = Date.now();
  if (msRestantes.value != null && msRestantes.value <= 0 && !reconsultadoAlVencer && !liberada.value) {
    reconsultadoAlVencer = true;
    consultar();
  }
}
const esperandoConfirmacion = computed(
  () => VUELVE_DEL_CHECKOUT && estadoPago.value !== 'approved' && reintentos.value < REINTENTOS_MAX
);

async function consultar() {
  try {
    const { data } = await api.get(`/pagos/cita/${props.citaId}`);
    cita.value = data;
    error.value = '';
  } catch (e) {
    // El respaldo NO repite el título de la pantalla («No encontramos esa
    // reserva»), que es el caso normal con un id inválido: se leía la misma
    // frase dos veces seguidas. Cuando la API manda un motivo propio, ese gana.
    error.value = apiError(e, 'El enlace puede estar incompleto, o la reserva ya no existe.');
  } finally {
    cargando.value = false;
  }
}

function programarReconsulta() {
  if (!esperandoConfirmacion.value) return;
  temporizador = setTimeout(async () => {
    reintentos.value += 1;
    await consultar();
    programarReconsulta();
  }, ESPERA_MS);
}

/* Abono o total, igual que en el último paso de la reserva: quien llega acá
   desde el enlace del correo (días después) tiene el mismo derecho a elegir
   que quien pagó al momento de reservar. Los montos los manda el backend —
   congelados al reservar — en vez de recalcularlos acá: esta vista no conoce
   el precio del especialista, y aunque lo conociera, un precio que subió
   entremedio no puede cambiar lo que se le prometió a esta persona. */
const modalidadPago = ref('abono');
const montoTotal = computed(() => cita.value?.monto_total_clp || 0);
const montoAbono = computed(() => cita.value?.monto_abono_clp ?? cita.value?.monto_clp ?? 0);
/* Hay consultas que se pagan completas por adelantado (Telemedicina). La
   regla la manda el backend en el resumen y no se deduce de los montos: viene
   congelada de la cita, así que una reserva vieja conserva la que vio quien
   la hizo aunque la clínica cambie la del servicio después. */
const exigeTotal = computed(() => cita.value?.modalidad_requerida === 'total');
const puedeElegir = computed(() => !exigeTotal.value && montoTotal.value > montoAbono.value);
/* El backend manda `saldo_clp: null` cuando SÍ queda diferencia por cobrar
   pero no se puede calcular todavía: una radiografía depende de cuántas
   placas salgan. Distinto de 0, que significa «no debe nada». */
const saldoDesconocido = computed(() => cita.value != null && cita.value.saldo_clp == null);
const montoAPagar = computed(() => {
  if (exigeTotal.value) return montoTotal.value;
  return modalidadPago.value === 'total' && puedeElegir.value ? montoTotal.value : montoAbono.value;
});

/* RUT de quien paga (decisión de producto, 06-oct): opcional. Va a Getnet para que
   el checkout no lo pida de nuevo, y queda en la reserva para el comprobante.
   Misma regla que el backend (Rut::normalizar): si se escribe, el dígito
   verificador tiene que cuadrar — se avisa acá, antes de salir a Getnet. */
const rut = ref('');
const rutError = ref('');

function normalizarRut(valor) {
  const limpio = (valor || '').replace(/[^0-9kK]/g, '').toUpperCase();
  const m = limpio.match(/^(\d{6,8})([0-9K])$/);
  if (!m) return null;
  const cuerpo = m[1].replace(/^0+/, '');
  let suma = 0;
  let factor = 2;
  for (let i = cuerpo.length - 1; i >= 0; i--) {
    suma += Number(cuerpo[i]) * factor;
    factor = factor === 7 ? 2 : factor + 1;
  }
  const r = 11 - (suma % 11);
  const dv = r === 11 ? '0' : r === 10 ? 'K' : String(r);
  return cuerpo && dv === m[2] ? { cuerpo, dv } : null;
}

/* Al salir del campo se muestra con puntos y guion: así la persona ve que se
   leyó bien lo que escribió. */
function formatearRut() {
  rutError.value = '';
  if (!rut.value.trim()) return;
  const r = normalizarRut(rut.value);
  if (!r) { rutError.value = 'Ese RUT no es válido. Revisa el número y el dígito verificador.'; return; }
  rut.value = `${Number(r.cuerpo).toLocaleString('es-CL')}-${r.dv}`;
}

async function pagar() {
  formatearRut();
  if (rutError.value) return;
  pagando.value = true;
  error.value = '';
  try {
    const { data } = await api.post(`/pagos/cita/${props.citaId}/sesion`, {
      modalidad: exigeTotal.value ? 'total' : modalidadPago.value,
      ...(rut.value.trim() ? { rut: rut.value.trim() } : {}),
    });
    window.location.assign(data.process_url);
  } catch (e) {
    pagando.value = false;
    // 410: el plazo venció mientras la página estaba abierta. Se vuelve a
    // leer la reserva y la pantalla pasa sola a «la hora se liberó».
    if (e?.response?.status === 410) {
      await consultar();
      return;
    }
    error.value = apiError(e, 'No pudimos abrir el pago. Intenta de nuevo.');
  }
}

const MESES = [
  'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
  'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
];
const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

// El backend manda la hora local de la clínica sin zona ("2026-08-20T10:00:00"),
// que JS interpreta como hora local del navegador. Es lo correcto acá: el
// cliente y la clínica están en la misma zona (ver §9.6 de CLAUDE.md).
const fechaLegible = computed(() => {
  if (!cita.value?.fecha_hora) return '';
  const d = new Date(cita.value.fecha_hora);
  const txt = `${DIAS[d.getDay()]} ${d.getDate()} de ${MESES[d.getMonth()]}`;
  return txt.charAt(0).toUpperCase() + txt.slice(1);
});
const horaLegible = computed(() => {
  if (!cita.value?.fecha_hora) return '';
  return new Date(cita.value.fecha_hora).toTimeString().slice(0, 5);
});

onMounted(async () => {
  await consultar();
  programarReconsulta();
  reloj = setInterval(tic, 1000);
});
onUnmounted(() => { clearTimeout(temporizador); clearInterval(reloj); });
</script>

<template>
  <div class="pago-page">
    <div class="panel">
      <p v-if="cargando" class="empty">Consultando tu pago…</p>

      <!-- No se pudo leer la reserva: id inválido, o el backend caído -->
      <template v-else-if="!cita">
        <h2>No encontramos esa reserva</h2>
        <p class="sub">{{ error }}</p>
        <p class="sub">
          Si acabas de reservar, revisa el enlace del correo de confirmación.
        </p>
        <a class="btn btn-wa" :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener">
          Escríbenos por WhatsApp
        </a>
      </template>

      <template v-else-if="cita.cancelada">
        <h2>Esta reserva está cancelada</h2>
        <p class="sub">No hay ningún pago pendiente. Si fue un error, escríbenos y la reactivamos.</p>
        <a class="btn btn-wa" :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener">
          Escríbenos por WhatsApp
        </a>
      </template>

      <!-- Pago aprobado: la hora quedó firme -->
      <template v-else-if="estadoPago === 'approved'">
        <div class="check-big" aria-hidden="true"><BaseIcon name="check" :size="30" /></div>
        <h2>Pago recibido</h2>
        <p class="sub">
          Tu hora quedó confirmada para el <strong class="cap">{{ fechaLegible }}</strong>
          <!-- El punto va dentro del v-if: sin especialista la frase ya cierra
               con el de «Hrs.», y afuera quedaba «15:30 Hrs..». -->
          a las <strong>{{ horaLegible }} Hrs.</strong><template v-if="cita.especialista"> con {{ cita.especialista }}.</template>
          Enviamos el comprobante a <strong>{{ cita.correo }}</strong>.
        </p>
        <div class="resumen">
          <div class="res-row"><span>Consulta</span><strong>{{ cita.servicio }}</strong></div>
          <div class="res-row" v-if="cita.mascota"><span>Mascota</span><strong>{{ cita.mascota }}</strong></div>
          <div class="res-row total">
            <span>{{ cita.modalidad === 'total' ? 'Pagado (total)' : (saldoDesconocido ? 'Abono pagado' : 'Abono pagado (50%)') }}</span>
            <strong>{{ formatCLP(cita.monto_pagado_clp ?? cita.monto_clp) }}</strong>
          </div>
          <div class="res-row" v-if="cita.saldo_clp > 0">
            <span>Saldo para el día de la atención</span><strong>{{ formatCLP(cita.saldo_clp) }}</strong>
          </div>
          <!-- `saldo_clp` en null no es «no debe nada»: es «queda diferencia y
               se calcula en la clínica» (radiografías, peluquería). Callarlo
               dejaría a la persona creyendo que llega con todo pagado. -->
          <div class="res-row" v-else-if="saldoDesconocido">
            <span>Saldo para el día de la atención</span>
            <strong>Se calcula en la clínica</strong>
          </div>
          <div class="res-row" v-if="cita.autorizacion"><span>Autorización</span><strong>{{ cita.autorizacion }}</strong></div>
        </div>
        <!-- Último lugar donde se puede pedir la orden: quien pagó de
             inmediato salió del sitio antes de ver las dos pantallas del
             asistente que la ofrecían, y aterriza acá. -->
        <SubirOrdenExamen
          v-if="cita.requiere_orden_examen && !cita.orden_adjunta"
          :cita-id="citaId"
          :para-presupuesto="cita.monto_total_clp == null"
        />
        <div class="acciones">
          <RouterLink v-if="auth.isLoggedIn" class="btn btn-primary" to="/citas">Ver mis citas</RouterLink>
          <RouterLink class="btn btn-ghost" to="/">Reservar otra hora</RouterLink>
        </div>
      </template>

      <!-- Volvió del checkout y Getnet todavía no cierra la sesión -->
      <template v-else-if="esperandoConfirmacion">
        <h2>Confirmando tu pago…</h2>
        <p class="sub">
          Estamos verificando el cobro con Getnet. Esto toma unos segundos, no cierres esta página.
        </p>
      </template>

      <!-- Pasó el plazo de pago: la hora ya se le ofrece a otras personas.
           Antes caía en la rama de abajo y decía «Tu hora está reservada,
           falta el pago» sobre una hora que ya no estaba reservada. -->
      <template v-else-if="liberada">
        <div class="reloj-big" aria-hidden="true"><BaseIcon name="clock" :size="30" /></div>
        <h2>Esta hora ya no está reservada</h2>
        <p class="sub">
          Pasaron los {{ MIN_VENTANA_PAGO }} minutos para pagar el abono, así que el
          <strong class="cap">{{ fechaLegible }}</strong> a las <strong>{{ horaLegible }} Hrs.</strong>
          quedó libre para otras personas. Puedes reservar de nuevo, la misma hora si sigue disponible
          u otra que te acomode.
        </p>
        <div class="acciones">
          <RouterLink class="btn btn-primary" to="/">Reservar otra hora</RouterLink>
          <RouterLink v-if="auth.isLoggedIn" class="btn btn-ghost" to="/citas">Ver mis citas</RouterLink>
        </div>
        <p class="nota">
          ¿Alcanzaste a pagar y no ves la confirmación?
          <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener">Escríbenos por WhatsApp</a>
          y lo revisamos.
        </p>
      </template>

      <!-- Pendiente o rechazado: hay que (re)intentar el pago -->
      <template v-else>
        <h2>{{ estadoPago === 'rejected' ? 'El pago no se completó' : 'Tu hora está reservada, falta el pago' }}</h2>
        <!-- El plazo, a la vista y antes de los montos: es lo que decide si
             la hora sigue siendo suya. -->
        <p v-if="cuentaRegresiva" class="plazo" role="timer" aria-live="off">
          <BaseIcon name="clock" :size="16" />
          Te quedan <strong>{{ cuentaRegresiva }}</strong> min para pagar. Después, la hora se libera.
        </p>
        <p class="sub">
          <template v-if="estadoPago === 'rejected'">
            El cobro fue rechazado o quedó a medias, así que no se descontó nada de tu tarjeta.
            Puedes intentarlo de nuevo.
          </template>
          <template v-else>
            Tenemos el <strong class="cap">{{ fechaLegible }}</strong> a las <strong>{{ horaLegible }} Hrs.</strong>
            a tu nombre. Queda firme con el abono del 50%<template v-if="puedeElegir">, y si prefieres
            puedes dejarla pagada entera</template>.
          </template>
        </p>

        <div class="resumen">
          <div class="res-row"><span>Consulta</span><strong>{{ cita.servicio }}</strong></div>
          <div class="res-row" v-if="cita.especialista"><span>Especialista</span><strong>{{ cita.especialista }}</strong></div>
          <div class="res-row"><span>Fecha</span><strong class="cap">{{ fechaLegible }} · {{ horaLegible }} Hrs.</strong></div>
          <div class="res-row" v-if="puedeElegir || exigeTotal"><span>Valor de la consulta</span><strong>{{ formatCLP(montoTotal) }}</strong></div>
        </div>

        <!-- Solo si hay dos montos distintos que ofrecer. Las citas anteriores
             a la migración pueden no tener total: ahí se cobra el abono y
             punto, sin mostrar una elección de una sola opción. -->
        <fieldset v-if="puedeElegir" class="modo-pago-set">
          <legend>¿Cuánto quieres pagar?</legend>
          <label class="modo-pago" :class="{ activa: modalidadPago === 'abono' }">
            <input type="radio" value="abono" v-model="modalidadPago" />
            <span class="modo-pago-texto">
              <strong>Solo el abono (50%)</strong>
              <small>El saldo de {{ formatCLP(montoTotal - montoAbono) }} se paga el día de la atención.</small>
            </span>
            <span class="modo-pago-monto">{{ formatCLP(montoAbono) }}</span>
          </label>
          <label class="modo-pago" :class="{ activa: modalidadPago === 'total' }">
            <input type="radio" value="total" v-model="modalidadPago" />
            <span class="modo-pago-texto">
              <strong>El total</strong>
              <small>Llegas con todo pagado, sin trámite el día de la atención.</small>
            </span>
            <span class="modo-pago-monto">{{ formatCLP(montoTotal) }}</span>
          </label>
        </fieldset>
        <div v-else class="resumen">
          <div class="res-row total">
            <span>{{ exigeTotal ? 'Total a pagar' : 'Abono a pagar' }}</span>
            <strong>{{ formatCLP(montoAPagar) }}</strong>
          </div>
          <p v-if="exigeTotal" class="nota">Esta consulta se paga completa antes de la atención.</p>
        </div>

        <div class="field rut-campo">
          <label for="pago-rut">RUT de quien paga <span class="opcional">(opcional)</span></label>
          <input
            id="pago-rut" v-model="rut" class="input" inputmode="text" autocomplete="off"
            placeholder="12.345.678-5" maxlength="12" :aria-invalid="!!rutError"
            aria-describedby="pago-rut-ayuda" @blur="formatearRut"
          />
          <small v-if="rutError" id="pago-rut-ayuda" class="error-text" role="alert">{{ rutError }}</small>
          <small v-else-if="cita.rut_registrado" id="pago-rut-ayuda" class="hint">
            Ya tenemos un RUT registrado ({{ cita.rut_registrado }}). Si pagas con ese, puedes dejarlo en blanco.
          </small>
          <small v-else id="pago-rut-ayuda" class="hint">Va al comprobante y te ahorra escribirlo en Getnet.</small>
        </div>

        <p v-if="error" class="error-text">{{ error }}</p>
        <button class="btn btn-primary btn-big" :disabled="pagando" @click="pagar">
          {{ pagando ? 'Abriendo el pago…' : (estadoPago === 'rejected' ? 'Reintentar el pago' : `Pagar ${formatCLP(montoAPagar)} con tarjeta`) }}
        </button>
        <!-- Antes decía «este mismo enlace sigue sirviendo» pasado el plazo:
             desde el 12-oct el cobro ya no se abre después de los
             MIN_VENTANA_PAGO minutos (el backend responde 410) y la pantalla
             pasa a «la hora se liberó». Una hora coordinada por la clínica no
             tiene plazo (`pagar_hasta: null`) y no muestra la cuenta. -->
        <p class="nota">Pago seguro de Getnet (Santander).</p>
        <PoliticaDevolucion />
        <p class="nota">
          ¿Problemas con el pago?
          <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener">Escríbenos por WhatsApp</a>.
        </p>
      </template>
    </div>
  </div>
</template>

<style scoped>
.pago-page { padding: clamp(1.5rem, 5vw, 3rem) 1rem 5rem; }
.panel {
  max-width: 560px; margin: 0 auto; text-align: center;
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--r-lg); padding: clamp(1.4rem, 4vw, 2.4rem);
}
.panel h2 { font-size: clamp(1.3rem, 3vw, 1.7rem); letter-spacing: -0.01em; margin-bottom: 0.7rem; }
.sub { color: var(--ink-soft); max-width: 44ch; margin: 0 auto 1.3rem; line-height: 1.55; }
.empty { color: var(--ink-soft); padding: 2rem 0; }
/* Solo la primera letra, no cada palabra. `capitalize` convertía
   «lunes 24 de agosto · 15:00 h» en «Lunes 24 De Agosto · 15:00 H»:
   mayúsculas en las preposiciones y en la hora. `inline-block` porque
   ::first-letter no aplica a cajas puramente en línea. */
.cap { display: inline-block; }
.cap::first-letter { text-transform: uppercase; }

/* Mismo tic verde que cierra el flujo de reserva (ReservarView.vue): quien
   llega acá desde el checkout tiene que reconocer el mismo "listo". */
.plazo {
  display: inline-flex; align-items: center; gap: 0.4rem; margin: -0.2rem auto 1.1rem;
  padding: 0.45rem 0.9rem; border-radius: var(--r-full);
  background: var(--warn-tint); color: var(--warn-ink); font-size: var(--t-sm);
}
.plazo strong { font-variant-numeric: tabular-nums; }
.reloj-big {
  width: 64px; height: 64px; margin: 0 auto 1rem;
  display: grid; place-items: center;
  border-radius: 50%; background: var(--warn-tint); color: var(--warn-ink);
}
.check-big {
  width: 64px; height: 64px; margin: 0 auto 1rem;
  display: grid; place-items: center;
  border-radius: 50%; background: var(--ok-tint); color: var(--ok);
  font-size: 2rem; font-weight: 700;
}

.resumen {
  text-align: left; margin: 0 auto 1.3rem;
  border: 1px solid var(--line); border-radius: var(--r-md);
  padding: 0.4rem 1.2rem;
}
.res-row {
  display: flex; justify-content: space-between; gap: 1rem;
  padding: 0.65rem 0; border-bottom: 1px solid var(--line);
}
.res-row:last-child { border-bottom: none; }
.res-row span { color: var(--ink-soft); }
.res-row.total { font-size: var(--t-lg); }
.res-row.total strong { color: var(--brand); }

/* Elegir abono o total. Duplicado de ReservarView.vue a propósito: los
   estilos son `scoped` por componente y en este proyecto no hay hoja
   compartida entre vistas. Si se toca uno, tocar el otro. */
.modo-pago-set { border: 0; padding: 0; margin: 0 auto 1.1rem; max-width: 480px; }
.modo-pago-set legend {
  font-size: var(--t-base); font-weight: 600; color: var(--ink);
  padding: 0; margin-bottom: 0.6rem;
}
.modo-pago {
  display: flex; align-items: center; gap: 0.7rem;
  padding: 0.85rem 1rem; margin-bottom: 0.5rem;
  border: 1.5px solid var(--line-strong); border-radius: var(--r-md);
  cursor: pointer; background: var(--surface); text-align: left;
  transition: border-color 0.15s, background 0.15s;
}
.modo-pago:hover, .modo-pago.activa { border-color: var(--accent); background: var(--accent-tint); }
.modo-pago input { accent-color: var(--accent); flex: none; margin: 0; }
.modo-pago-texto { display: flex; flex-direction: column; gap: 0.15rem; flex: 1; min-width: 0; }
.modo-pago-texto strong { font-size: var(--t-base); }
.modo-pago-texto small { color: var(--ink-soft); font-size: var(--t-micro); line-height: 1.45; }
.modo-pago-monto { font-weight: 700; font-size: var(--t-lg); white-space: nowrap; }
.modo-pago:focus-within { outline: 2px solid var(--accent); outline-offset: 2px; }

/* `.field` pone la columna y la etiqueta; acá solo el ancho y el centrado del resto de la tarjeta. */
.rut-campo { max-width: 480px; margin: 0 auto 1rem; text-align: left; }
.rut-campo .opcional { font-weight: 400; color: var(--ink-soft); }
@media (prefers-reduced-motion: reduce) { .modo-pago { transition: none; } }

.btn-big { width: 100%; padding: 0.85em 1.4em; font-size: var(--t-lg); }
.acciones { display: flex; flex-wrap: wrap; gap: 0.7rem; justify-content: center; }
.nota { font-size: var(--t-micro); color: var(--ink-soft); margin-top: 0.8rem; line-height: 1.5; }
.error-text { color: var(--danger); margin-bottom: 0.8rem; }
</style>
