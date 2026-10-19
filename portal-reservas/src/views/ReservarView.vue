<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { api, apiError, formatCLP, API_URL, fotoMascota } from '../api.js';
import { useAuthStore } from '../stores/auth.js';
import { useDatosStore } from '../stores/datos.js';
import { precargarRecaptcha, obtenerTokenRecaptcha } from '../recaptcha.js';
import PasswordInput from '../components/PasswordInput.vue';
import BotonGoogle from '../components/BotonGoogle.vue';
import SubirOrdenExamen from '../components/SubirOrdenExamen.vue';
import PoliticaDevolucion from '../components/PoliticaDevolucion.vue';
import BaseIcon from '../components/BaseIcon.vue';
import { CLINICA, MIN_VENTANA_PAGO, WHATSAPP_RESERVAS, WHATSAPP_RESERVAS_ES_BOT } from '../config.js';

const props = defineProps({
  slug: { type: String, default: null }, // enlace permanente /b/{slug}
});

const auth = useAuthStore();
const datos = useDatosStore();
const router = useRouter();
const route = useRoute();

// URL del sitio público, para los enlaces a Términos y Privacidad del modal
// de cuenta — mismo patrón que App.vue ("Volver al sitio web").
const SITIO_URL = import.meta.env.VITE_SITIO_URL || 'http://localhost:3100';

/* Retratos que viven en public/equipo/, igual que en el panel
   (portal-admin-clinica/src/retratos.js): viajan con el despliegue y no
   dependen del disco del servidor. Sin build compartido entre frontends —
   si se agrega un especialista con foto hay que sumarlo acá y en el panel.
   Vacío = la foto subida desde el panel, o las iniciales. */
const RETRATOS = {
  'marta soto': 'marta-soto.webp',
  'tomas fuentes': 'tomas-fuentes.webp',
  'carla rivas': 'carla-rivas.webp',
  'javiera paredes': 'javiera-paredes.webp',
  'ignacio morales': 'ignacio-morales.webp',
  'valentina rojas': 'valentina-rojas.webp',
  'camila herrera': 'camila-herrera.webp',
};
function claveNombre(nombre) {
  return (nombre || '')
    .replace(/^(Dr|Dra)\.?\s+/i, '')
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().trim();
}
function urlFotoEsp(e) {
  const local = RETRATOS[claveNombre(e?.nombre)];
  if (local) return `/equipo/${local}`;
  return e?.foto ? `${API_URL}${e.foto}` : null;
}

/* La foto del tipo de consulta. Devuelve null cuando no hay, y ese null es la
   señal con la que la tarjeta cae al emoji: mientras la clínica no suba la
   foto de un servicio, esa tarjeta se ve exactamente como antes. */
function urlImagenSvc(sv) {
  return sv?.imagen ? `${API_URL}${sv.imagen}` : null;
}

const espFijo = ref(null); // especialista fijado por el enlace permanente

/* Con sesión activa: paso 4 pregunta qué mascota, no datos de contacto
   (ya los tenemos de la cuenta). Se reusan sus mascotas registradas en el
   portal en vez de pedirle escribir todo de nuevo. */
const misMascotas = ref([]);
const mascotaElegida = ref(null);
const mostrarNuevaMascota = ref(false);
const nuevaMascota = ref({ nombre: '', especie: 'perro' });
const creandoMascota = ref(false);

async function cargarMisMascotas() {
  try {
    const { data } = await api.get('/mascotas/');
    misMascotas.value = data;
    if (data.length === 1) mascotaElegida.value = data[0];
  } catch {
    /* si falla, el formulario de datos manuales sigue disponible */
  }
}

async function crearMascotaRapida() {
  if (!nuevaMascota.value.nombre.trim()) return;
  creandoMascota.value = true;
  error.value = '';
  try {
    const { data } = await api.post('/mascotas/', {
      nombre: nuevaMascota.value.nombre.trim(),
      especie: nuevaMascota.value.especie,
    });
    misMascotas.value.push(data);
    mascotaElegida.value = data;
    mostrarNuevaMascota.value = false;
    nuevaMascota.value = { nombre: '', especie: 'perro' };
  } catch (e) {
    error.value = apiError(e, 'No se pudo agregar la mascota.');
  } finally {
    creandoMascota.value = false;
  }
}

/* Al llegar al paso 4 sin sesión, se ofrece iniciar sesión o crear cuenta
   antes de seguir con el formulario de datos (sin perder la reserva en
   curso: login/registro es un modal inline, no una navegación aparte). */
const mostrarModalCuenta = ref(false);
const modoCuenta = ref('login'); // 'login' | 'registro'
const cuentaForm = ref({ email: '', password: '', nombre: '', telefono: '', aceptaTerminos: false, aceptaPrivacidad: false, aceptaMarketing: false });
const cuentaError = ref('');
const cuentaBusy = ref(false);

function abrirModalCuenta(modo) {
  modoCuenta.value = modo;
  cuentaError.value = '';
  mostrarModalCuenta.value = true;
}

function seguirSinCuenta() {
  mostrarModalCuenta.value = false;
}

/* La política de abono y devolución se lee desde el enlace del checkbox del
   paso 4: se acepta lo que se puede leer ahí mismo, sin salir de la reserva
   (el sitio público no la tiene escrita, y abrirlo perdería el formulario). */
const mostrarPoliticaAbono = ref(false);

/* Quedó con sesión, sea por el formulario o por Google: se cierra el modal y
   se cargan sus mascotas, porque el paso siguiente ya no pide los datos de
   contacto sino cuál de sus mascotas viene. */
async function trasIngresar() {
  mostrarModalCuenta.value = false;
  await cargarMisMascotas();
}

async function enviarCuenta() {
  cuentaError.value = '';
  cuentaBusy.value = true;
  try {
    if (modoCuenta.value === 'login') {
      await auth.login(cuentaForm.value.email, cuentaForm.value.password);
    } else {
      const recaptchaToken = await obtenerTokenRecaptcha('registro');
      await auth.register({
        email: cuentaForm.value.email, password: cuentaForm.value.password,
        nombre: cuentaForm.value.nombre, telefono: cuentaForm.value.telefono || null,
        acepta_terminos: cuentaForm.value.aceptaTerminos,
        acepta_privacidad: cuentaForm.value.aceptaPrivacidad,
        acepta_marketing: cuentaForm.value.aceptaMarketing,
        recaptcha_token: recaptchaToken,
      });
    }
    await trasIngresar();
  } catch (e) {
    cuentaError.value = apiError(e, 'No se pudo continuar. Revisa tus datos.');
  } finally {
    cuentaBusy.value = false;
  }
}

/* pasos: 1 consulta · 2 especialista · 3 hora · 4 datos · 5 revisar ·
   5.5 pago · 6 listo

   El pago va DESPUÉS de confirmar, no antes: Getnet es una pasarela por
   redirección (el cliente sale del sitio), así que crear la cita primero
   deja el cupo tomado mientras paga. Al revés se puede cobrar por una hora
   que otro tomó mientras el cliente estaba en el checkout.

   El id 5.5 en vez de 6 mantiene "6 = listo" como estaba, y el indicador
   igual lo muestra como el sexto y último paso (`n` se calcula por
   posición, ver `pasos`). Quien paga sale del sitio y vuelve a
   /pago/:citaId (PagoView.vue), así que el paso 6 de acá solo lo ven las
   reservas que no requieren abono. */
const paso = ref(1);
const ajustes = ref(null);
const servicios = ref([]);
const especialistas = ref([]);

const servicio = ref(null);
const especialista = ref(null);
/* Va acá arriba y no junto a `elegirGrupo`, aunque ahí es donde se usa: el
   watcher de `?paso=` corre con `immediate: true` bastante antes, y una
   `const` en zona muerta rompería el setup entero. */
const grupoElegido = ref(null);
const slots = ref({});
const semana = ref(0);           // 0 = esta semana, hasta ~12 (3 meses)
const MAX_SEMANAS = 12;
const fecha = ref('');
const hora = ref('');

const loading = ref(true);
const loadingPaso = ref(false);
/* Lo que el enlace del agente no pudo cumplir. Se muestra en el paso donde
   quedó la persona, no en un modal: el objetivo es que siga eligiendo ahí
   mismo, no que acepte un mensaje. */
const avisoEnlace = ref('');
const error = ref('');
const busy = ref(false);
const confirmada = ref(null);

// Cada cambio de paso queda como una entrada nueva en el historial del
// navegador (?paso=N), para que "atrás" navegue paso a paso dentro del
// asistente en vez de sacar al visitante de la página entera: antes ningún
// cambio de `paso` tocaba el router, así que todo el flujo vivía en una
// sola entrada de historial y "atrás" caía en lo que hubiera antes de
// llegar a /reservar (el sitio público, un resultado de búsqueda, etc).
function irPaso(n) {
  paso.value = n;
  router.push({ path: route.path, query: { ...route.query, paso: String(n) } }).catch(() => {});
}

// Si el estado en memoria no alcanza para mostrar el paso que pide la URL
// (recarga de página a mitad de flujo, o "atrás"/"adelante" hacia un paso
// 6 que reiniciar() ya vació) cae a paso 1 en vez de renderizar a medias.
// Corre también al montar (route.query.paso todavía no cambió), así que
// una URL compartida con ?paso=N nunca intenta resucitar un paso sin datos.
function sincronizarPasoDesdeUrl() {
  const n = Number(route.query.paso) || 1;

  // Con la cita ya creada, retroceder está prohibido.
  //
  // El guard de abajo solo miraba hacia adelante (`n < 5.5 || confirmada`), así
  // que volver atrás desde el pago pasaba sin mirar nada y devolvía el
  // asistente a los pasos 1-4 CON la cita ya en la base. De ahí salían dos
  // caminos y los dos terminaban mal: si la persona se iba, quedaba una hora
  // tomada sin pagar; y si rehacía el flujo, `confirmar()` corría de nuevo sin
  // limpiar `confirmada` y creaba una SEGUNDA cita, dejando la primera
  // huérfana en la agenda.
  //
  // La hora ya está tomada a su nombre y tiene su ventana para pagarla: lo
  // correcto es quedarse en el pago, no rearmar una reserva que ya existe.
  // Quien de verdad quiera otra cosa tiene el botón de empezar de nuevo, que
  // pasa por `reiniciar()` y sí limpia el estado.
  //
  // Lo que NO se puede es dejar a la persona sin salida, que es lo que pasaba
  // (la clínica lo probó, sep-2026): se arrepintió del día en el pago, apretó
  // «atrás» y el asistente la devolvía al pago una y otra vez, y tocar «Fecha y
  // hora» en los pasos no hacía nada. Retroceder desde el pago es justamente
  // querer otra hora, así que se trata igual que el botón: se suelta la cita
  // sin pagar y se vuelve a elegir (`cambiarHoraSinPagar`). Nunca a los pasos 4
  // o 5: confirmar de nuevo la MISMA hora chocaría con la cita que acabamos de
  // soltar, que sigue reteniendo el cupo hasta que venza su ventana.
  if (confirmada.value && n < 5.5) {
    cambiarHoraSinPagar(Math.min(n, 3));
    return;
  }

  const alcanzaEstado =
    // Al paso 2 se llega de dos formas: con una consulta suelta ya elegida, o
    // con un GRUPO —la dermatología—, donde la consulta se elige ahí mismo y
    // por eso `servicio` todavía está vacío. Sin la segunda condición, entrar
    // a Dermatología rebotaba de vuelta al paso 1.
    (n < 2 || servicio.value || grupoElegido.value) &&
    (n < 3 || especialista.value) &&
    (n < 4 || (fecha.value && hora.value)) &&
    // Desde el paso de pago (5.5) en adelante la cita ya existe: sin ella
    // no hay nada que cobrar ni que agradecer.
    (n < 5.5 || confirmada.value);
  if (alcanzaEstado) {
    paso.value = n;
  } else if (paso.value !== 1 || route.query.paso) {
    paso.value = 1;
    router.replace({ path: route.path, query: {} }).catch(() => {});
  }
}
watch(() => route.query.paso, sincronizarPasoDesdeUrl, { immediate: true });

/* Paso 4: "Información Paciente" — sin cuenta ni login */
const LS_KEY = 'reserva_contacto';
const paciente = ref({
  correo: '', mascotaNombre: '', tutor: '', telefono: '',
  servicioVeterinario: '', direccion: '',
  medioConfirmado: false,
  declaroAcepto: false,
  // Consentimiento de la política de abono/devolución de interconsultas —
  // distinto de declaroAcepto (que es sobre puntualidad en telemedicina).
  abonoAceptado: false,
  guardarInfo: false,
});

function cargarContactoGuardado() {
  try {
    const guardado = JSON.parse(localStorage.getItem(LS_KEY) || 'null');
    if (guardado) {
      paciente.value.correo = guardado.correo || '';
      paciente.value.tutor = guardado.tutor || '';
      paciente.value.telefono = guardado.telefono || '';
      paciente.value.guardarInfo = true;
    }
  } catch { /* localStorage no disponible: se ignora */ }
}

const dias = computed(() => Object.keys(slots.value).sort());
const w = computed(() => ajustes.value?.wording || {});
const esTelemedicina = computed(() => (servicio.value?.nombre || '').toLowerCase().includes('telemedicina'));
// Interconsulta con especialista: ya se eligió el especialista en el paso 2,
// así que volver a preguntar "qué tratamiento necesita" es redundante.
const esInterconsulta = computed(() => (servicio.value?.nombre || '').toLowerCase().includes('interconsulta'));
/* La dirección solo se pide cuando la atención es en la casa del tutor. En
   una consulta en la clínica no se usa para nada, y la clínica pidió sacarla
   del formulario (correo del 21-ago-2026); borrarla del todo, en cambio,
   dejaría las visitas a domicilio sin dónde ir. */
const esDomicilio = computed(() => (servicio.value?.nombre || '').toLowerCase().includes('domicilio'));

/* Semana completa: 7 días desde el inicio del rango, cada uno con sus horas
   separadas en mañana/tarde. */
const semanaCompleta = computed(() => {
  const hoy = new Date();
  hoy.setHours(0, 0, 0, 0);
  const out = [];
  for (let i = 0; i < 7; i++) {
    const iso = isoAddDays(semana.value * 7 + i);
    const [y, m, d] = iso.split('-').map(Number);
    const dt = new Date(y, m - 1, d);
    const horas = slots.value[iso] || [];
    out.push({
      iso,
      diaSemana: dt.toLocaleDateString('es-CL', { weekday: 'long' }),
      diaNum: d,
      mes: dt.toLocaleDateString('es-CL', { month: 'short' }).replace('.', ''),
      esHoy: dt.getTime() === hoy.getTime(),
      pasado: dt < hoy,
      manana: horas.filter((h) => parseInt(h) < 14),
      tarde: horas.filter((h) => parseInt(h) >= 14),
      total: horas.length,
    });
  }
  return out;
});
const totalSemana = computed(() => semanaCompleta.value.reduce((n, d) => n + d.total, 0));

/* Solo los días que efectivamente tienen horas. Quien atiende dos días a la
   semana veía cinco columnas "Cerrado" y una agenda que parecía vacía; la
   semana que se está mirando ya la dice el selector de fechas de arriba. */
const diasConHoras = computed(() => semanaCompleta.value.filter((d) => d.total));

/* ── Vista móvil de horarios ──
   Las 7 columnas lado a lado solo caben en escritorio. En un teléfono
   quedan a ~80px cada una: hay que arrastrar en horizontal para ver el
   fin de semana y la página crece a varias pantallas de alto. En móvil
   mostramos un día a la vez, elegido desde una tira compacta, y sus
   horas ocupan el ancho completo. */
// Se resuelve en el primer render (no en onMounted) para que en un teléfono
// no aparezca un fotograma con la grilla de escritorio.
const esMovil = ref(window.matchMedia('(max-width: 620px)').matches);
const diaSelIso = ref('');
let mqMovil = null;

function syncMovil(e) {
  esMovil.value = e.matches;
  if (e.matches) elegirDiaPorDefecto();
}

onMounted(() => {
  mqMovil = window.matchMedia('(max-width: 620px)');
  syncMovil(mqMovil);
  mqMovil.addEventListener('change', syncMovil);
});
onUnmounted(() => mqMovil?.removeEventListener('change', syncMovil));

// Primer día con horas de la semana visible.
function elegirDiaPorDefecto() {
  const dias = diasConHoras.value;
  if (!dias.length) return;
  if (dias.some((d) => d.iso === diaSelIso.value)) return;  // el elegido sigue sirviendo
  diaSelIso.value = dias[0].iso;
}

const diaSel = computed(
  () => diasConHoras.value.find((d) => d.iso === diaSelIso.value) || diasConHoras.value[0]
);

function isoAddDays(n) {
  const d = new Date();
  d.setDate(d.getDate() + n);
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

const rangoLabel = computed(() => {
  const f = (iso) => {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('es-CL', { day: 'numeric', month: 'short', year: 'numeric' });
  };
  return `${f(isoAddDays(semana.value * 7))} - ${f(isoAddDays(semana.value * 7 + 6))}`;
});

function labelDia(iso) {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('es-CL', { weekday: 'short', day: 'numeric', month: 'short' });
}
function labelFechaLarga(iso) {
  if (!iso) return '';
  const [y, m, d] = iso.split('-').map(Number);
  const txt = new Date(y, m - 1, d).toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  // Solo la inicial: capitalizar por palabra deja "1 De Agosto De 2026".
  return txt.charAt(0).toUpperCase() + txt.slice(1);
}
/* La misma fecha, pero dentro de una frase: «para el jueves 17 de septiembre
   de 2026», en minúscula y sin la coma que pone toLocaleDateString tras el
   día de la semana. La usa el texto del paso de pago. */
function labelFechaFrase(iso) {
  if (!iso) return '';
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d)
    .toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
    .replace(',', '');
}
function durLabel(min) {
  return min >= 60 ? `${min / 60} hora${min > 60 ? 's' : ''}` : `${min} minutos`;
}
/* Lo que dura la atención para el tutor, que no siempre es lo que se
   bloquea en la agenda: la consulta a domicilio ocupa 1½ h contando ida y
   vuelta, pero la atención en sí es de media hora y anunciar la hora y
   media confunde. */
function durServicio(s) {
  return durLabel(s?.duracion_visible_min || s?.duracion_min || 0);
}

/* ¿Va el chip verde con el precio? Solo cuando la consulta no trae su nota de
   precio (`precio_detalle`).

   La tarjeta de Consulta General decía el valor TRES veces: el chip «15.000
   CLP», la nota «Lunes a viernes: $15.000 · Sábado: $20.000» y otra vez en la
   descripción (la clínica lo pidió en un solo lugar, sep-2026). Manda la nota
   porque es la única que dice la verdad entera: el chip muestra solo el valor
   de lunes a viernes, así que quien reserva un sábado ve un número que no es el
   suyo. La descripción se limpió en los datos
   (`migrate_correcciones_catalogo_sep.py`), no acá.

   Se decide por la nota y no por `precio_sabado_clp` porque la repetición es la
   misma sin tarifa de sábado: Ecografía («$45.000. Solo de lunes a viernes.») y
   el domicilio («$35.000 en la comuna…») también la tenían. Todas las notas
   del catálogo que acompañan a un precio lo nombran; si alguna vez se carga una
   que no, el chip desaparece igual y el valor queda solo en el paso 4. */
function chipPrecio(s) {
  return !!s?.precio_clp && !s?.precio_detalle;
}

/* ── Consultas agrupadas ──────────────────────────────────────────────────
   Un `grupo` en el catálogo (columna `servicios.grupo`) junta variantes de la
   misma prestación: hoy «Consulta Dermatológica» y «Control Dermatológico».

   La clínica pidió ver a la especialista DOS VECES con su foto, una por opción,
   y decidir ahí. Puesto en el paso 1 el grupo es una sola entrada —quien
   reserva piensa «dermatología», no «consulta o control»—; la elección real,
   que depende del precio y de quién atiende, cae en el paso 2, que es donde
   está la persona y su valor.

   Un grupo de UNA sola consulta no se agrupa: sería un paso 2 con una tarjeta
   que no decide nada. Pasa si se desactiva una de las dos, y es justo cuando
   no hay que obligar a un clic de más. */
const entradasPaso1 = computed(() => {
  const salida = [];
  const vistos = new Set();
  for (const s of servicios.value) {
    if (!s.grupo) { salida.push({ tipo: 'servicio', servicio: s, clave: s.id }); continue; }
    if (vistos.has(s.grupo)) continue;
    vistos.add(s.grupo);
    const miembros = servicios.value.filter((x) => x.grupo === s.grupo);
    if (miembros.length < 2) { salida.push({ tipo: 'servicio', servicio: s, clave: s.id }); continue; }
    salida.push({ tipo: 'grupo', grupo: s.grupo, miembros, clave: `g:${s.grupo}` });
  }
  return salida;
});

/* Las tarjetas del paso 2 cuando se entró por un grupo: una por cada
   combinación de consulta y profesional. Con dos consultas que atiende la
   misma persona salen dos tarjetas con su foto, que es exactamente lo pedido.
   `especialistasPorServicio` se llena en `elegirGrupo`. */
const especialistasPorServicio = ref({});
const tarjetasGrupo = computed(() => {
  if (!grupoElegido.value) return [];
  const filas = [];
  for (const s of grupoElegido.value.miembros) {
    for (const e of (especialistasPorServicio.value[s.id] || [])) {
      filas.push({ clave: `${s.id}:${e.id}`, servicio: s, especialista: e });
    }
  }
  return filas;
});

/* `soloEsp`: abrir el grupo con UNA profesional ya elegida. Es la entrada desde
   Interconsultas (ver `elegirDeLaLista`); desde el paso 1 va vacío y salen
   todas las del grupo. */
async function elegirGrupo(g, soloEsp = null) {
  grupoElegido.value = g;
  servicio.value = null;
  especialista.value = null;
  especialistasPorServicio.value = {};
  loadingPaso.value = true;
  irPaso(2);
  try {
    // Una llamada por consulta del grupo, en paralelo: cada una puede tener
    // distinto equipo, y el endpoint no acepta varios `servicio_id`.
    const pares = await Promise.all(
      g.miembros.map(async (s) => {
        const { data } = await api.get('/catalogo/especialistas', { params: { servicio_id: s.id } });
        return [s.id, soloEsp ? data.filter((e) => e.id === soloEsp.id) : data];
      }),
    );
    especialistasPorServicio.value = Object.fromEntries(pares);
  } finally {
    loadingPaso.value = false;
  }
}

/* Una tarjeta de grupo fija las DOS cosas de una vez —qué consulta y con
   quién—, así que desde acá se salta directo al paso 3. */
async function elegirTarjetaGrupo(t) {
  servicio.value = t.servicio;
  await elegirEspecialista(t.especialista);
}

/* Interconsultas es la lista de TODOS los especialistas, y la dermatóloga
   aparece ahí primera. Pero entrar por esa tarjeta la reservaba como
   «Interconsulta» a su tarifa, sin preguntar si era consulta o control — la
   elección que sí existe entrando por Dermatología en el paso 1 (la clínica lo
   notó, sep-2026). La misma persona daba dos reservas distintas según por dónde
   se llegara.

   Cuando la profesional atiende un GRUPO de consultas (`servicios.grupo`), su
   tarjeta abre ese grupo filtrado a ella: las mismas dos tarjetas, consulta y
   control, con su valor. Se decide por el dato y no por su nombre, así que un
   segundo grupo mañana entra solo.

   Solo desde Interconsultas (`tarifa_por_especialista`), que es el servicio
   cuya elección real es «con quién». En cualquier otro —Telemedicina
   dermatológica, también de ella— la consulta ya está elegida y desviarla a
   Dermatología le cambiaría a la persona lo que pidió. */
function grupoDeEspecialista(e) {
  if (!servicio.value?.tarifa_por_especialista) return null;
  const ids = new Set((e.servicio_ids || []).map(String));
  return entradasPaso1.value.find(
    (en) => en.tipo === 'grupo' && en.miembros.some((m) => ids.has(String(m.id)))
  ) || null;
}

async function elegirDeLaLista(e) {
  const g = grupoDeEspecialista(e);
  if (g) {
    await elegirGrupo(g, e);
    return;
  }
  await elegirEspecialista(e);
}

async function elegirServicio(s) {
  grupoElegido.value = null;
  servicio.value = s;
  // Con enlace permanente el especialista ya está fijado: saltar "Sala"
  if (espFijo.value) {
    await elegirEspecialista(espFijo.value);
    return;
  }
  especialista.value = null;
  loadingPaso.value = true;
  irPaso(2);
  try {
    const { data } = await api.get('/catalogo/especialistas', { params: { servicio_id: s.id } });
    especialistas.value = data;
  } finally {
    loadingPaso.value = false;
  }
}

// Clínica externa duplicada del website (config.js): no hay build compartido
// entre los 5 frontends, así que cada uno repite este tipo de constante.
// Al asistente con IA si está configurado (ver WHATSAPP_RESERVAS en config.js).
const WHATSAPP_TEL = WHATSAPP_RESERVAS;

/* Quién consulta, para que la clínica pueda devolver el contacto sin tener que
   preguntarlo todo de nuevo. Con sesión iniciada sale de la cuenta y no se le
   pide nada a nadie; sin ella se pide UN campo: el nombre.

   **El teléfono no se pregunta.** Lo pedía y sobraba por su propio motivo, que
   ya estaba escrito acá abajo: el mensaje sale por WhatsApp, y WhatsApp le
   muestra a la clínica el número de quien escribe. Era un campo que pedía algo
   que la clínica iba a tener de todos modos, justo delante del único botón que
   le queda a alguien que no encontró hora.

   Sigue siendo OPCIONAL: esta pantalla aparece cuando no pudimos mostrar
   horas, o sea que ya venimos pidiéndole a la persona un trámite extra. Un
   formulario obligatorio ahí es la forma más rápida de que no escriba. */
const contactoWa = ref({ nombre: '' });

const quienConsulta = computed(() => {
  if (auth.isLoggedIn) {
    return {
      nombre: auth.user?.nombre || '',
      telefono: auth.user?.telefono || '',
      correo: auth.user?.email || '',
    };
  }
  return {
    // Sin sesión no hay teléfono: no se pregunta. El de WhatsApp lo pone
    // WhatsApp.
    nombre: contactoWa.value.nombre.trim(),
    telefono: '',
    correo: '',
  };
});

/* El mensaje va en varias líneas y con etiquetas porque del otro lado lo lee
   una persona que atiende WhatsApp entre paciente y paciente: tiene que poder
   sacar de un vistazo qué se pide, con quién y a quién llamar. La especialidad
   viaja además del nombre — en un equipo puede haber dos «Dra. Carla». */
function mensajeWhatsapp(e) {
  const q = quienConsulta.value;
  const lineas = [
    '¡Hola! Quisiera consultar disponibilidad para una hora.',
    '',
    `Consulta: ${servicio.value?.nombre || 'por definir'}`,
    `Profesional: ${e.nombre}${e.especialidad ? ` — ${e.especialidad}` : ''}`,
  ];
  const contacto = [q.nombre, q.telefono, q.correo].filter(Boolean).join(' · ');
  if (contacto) lineas.push(`Solicita: ${contacto}`);
  return encodeURIComponent(lineas.join('\n'));
}

async function elegirEspecialista(e) {
  especialista.value = e;
  fecha.value = '';
  hora.value = '';
  semana.value = 0;
  irPaso(3);
  // Especialistas externos sin horas fijas: nada que pedirle a /citas/slots,
  // el paso 3 muestra un botón a WhatsApp en su lugar (ver template).
  if (e.solo_consulta_whatsapp) return;
  await cargarSemana();
}

async function cargarSemana() {
  loadingPaso.value = true;
  try {
    const { data } = await api.get('/citas/slots', {
      params: {
        servicio_id: servicio.value.id,
        especialista_id: especialista.value.id,
        desde: isoAddDays(semana.value * 7),
        dias: 7,
      },
    });
    slots.value = data;
    if (esMovil.value) elegirDiaPorDefecto();
  } finally {
    loadingPaso.value = false;
  }
}

function moverSemana(delta) {
  const nueva = semana.value + delta;
  if (nueva < 0 || nueva > MAX_SEMANAS) return;
  semana.value = nueva;
  cargarSemana();
}

function labelColumna(iso) {
  const [y, m, d] = iso.split('-').map(Number);
  const dt = new Date(y, m - 1, d);
  const dia = dt.toLocaleDateString('es-CL', { weekday: 'short' }).replace('.', '');
  return { dia, fecha: `${d}/${m}` };
}

/* ── Calendario desplegable ── */
const calAbierto = ref(false);
const calMes = ref(new Date());  // mes visible en el calendario

const hoyISO = isoAddDays(0);
const maxISO = isoAddDays(MAX_SEMANAS * 7 + 6);

const calTitulo = computed(() =>
  calMes.value.toLocaleDateString('es-CL', { month: 'long', year: 'numeric' })
);

// Matriz de días del mes visible, empezando en lunes.
const calDias = computed(() => {
  const y = calMes.value.getFullYear();
  const m = calMes.value.getMonth();
  const primero = new Date(y, m, 1);
  // getDay: 0=dom … 6=sáb. Queremos empezar en lunes.
  let offset = (primero.getDay() + 6) % 7;
  const inicio = new Date(y, m, 1 - offset);
  const celdas = [];
  for (let i = 0; i < 42; i++) {
    const d = new Date(inicio.getTime() + i * 86400000);
    const iso = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    celdas.push({
      iso,
      num: d.getDate(),
      otroMes: d.getMonth() !== m,
      deshabilitado: iso < hoyISO || iso > maxISO,
    });
  }
  return celdas;
});

function moverCalMes(delta) {
  calMes.value = new Date(calMes.value.getFullYear(), calMes.value.getMonth() + delta, 1);
}

// Al elegir un día del calendario, salta a la semana que lo contiene.
function elegirDelCalendario(celda) {
  if (celda.deshabilitado) return;
  const [y, m, d] = celda.iso.split('-').map(Number);
  const elegido = new Date(y, m - 1, d);
  const hoy = new Date();
  hoy.setHours(0, 0, 0, 0);
  const diffDias = Math.floor((elegido - hoy) / 86400000);
  semana.value = Math.max(0, Math.floor(diffDias / 7));
  calAbierto.value = false;
  cargarSemana();
}

function abrirCalendario() {
  calMes.value = new Date();
  calAbierto.value = !calAbierto.value;
}

const DIAS_SEMANA = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];

// Cierra el calendario al hacer clic fuera de él.
function cerrarCalFuera(ev) {
  if (calAbierto.value && !ev.target.closest('.sem-cal-wrap')) {
    calAbierto.value = false;
  }
}
onMounted(() => document.addEventListener('click', cerrarCalFuera));
onUnmounted(() => document.removeEventListener('click', cerrarCalFuera));

function elegirSlot(dia, h) {
  fecha.value = dia;
  elegirHora(h);
}

function elegirHora(h) {
  hora.value = h;
  irPaso(4);
  paciente.value.servicioVeterinario = servicio.value?.nombre || '';
  if (!auth.isLoggedIn) mostrarModalCuenta.value = true;
}

/* ── Entrada por enlace: ?servicio=&especialista=&fecha=&hora= ──

   Es el enlace que arma el asistente de WhatsApp. Existe para que el agente
   pueda llevar a alguien al lugar exacto del asistente SIN tener ninguna
   herramienta de escritura: la reserva sigue ocurriendo en un solo sitio
   (POST /citas/reservar), con su recálculo de disponibilidad justo antes de
   insertar. Lo peor que puede conseguir quien intente burlar al agente es un
   enlace, y un enlace no hace nada que no pudiera hacer clickeando el portal.

   REPRODUCE LOS CLICS, NO ESCRIBE EL ESTADO. Llama a las mismas
   `elegirServicio` / `elegirEspecialista` / `elegirSlot` que usan los botones.
   Es la única forma de que un enlace no pueda dejar la pantalla en un estado
   al que nadie puede llegar clickeando: si mañana `elegirEspecialista` carga
   algo más, el enlace lo carga también, gratis. Asignar `servicio.value = x`
   a mano habría creado un segundo camino que hay que mantener de acuerdo con
   el primero, que es justo lo que ya se pagó caro en otras partes del repo.

   EL ENLACE NO RETIENE LA HORA, y por eso cada paso valida antes de avanzar.
   Entre que el agente manda el mensaje y la persona lo abre, la hora se la
   puede llevar cualquiera — es la misma condición de carrera que el paso 3
   ya maneja, solo que ahora se cruza más seguido. Cuando eso pasa NO se
   avanza: se queda en el paso 3, con las horas reales cargadas y un aviso de
   por qué. Avanzar igual dejaría a alguien en el paso 4 llenando datos para
   una hora que `POST /citas/reservar` va a rechazar con 409.

   Los parámetros se CONSUMEN AL PRINCIPIO, no al final. Se leen a variables
   y se borran de la URL antes de reproducir nada, por una razón concreta:
   `elegirServicio` y `elegirEspecialista` hacen `router.push`, así que cada
   paso deja su propia entrada en el historial. Limpiando al final, esas
   entradas intermedias se quedan con los parámetros dentro, y bastaba
   «atrás» + F5 para volver a dispararlo todo — la persona no podía salir del
   enlace. Limpiando antes, cada entrada que se crea después ya nace limpia. */
async function precargarDesdeUrl() {
  const q = route.query;
  const svcId = String(q.servicio || '');
  if (!svcId) return;
  const espId = String(q.especialista || '');
  const f = String(q.fecha || '');
  const h = String(q.hora || '');

  const { servicio: _s, especialista: _e, fecha: _f, hora: _h, ...resto } = q;
  await router.replace({ path: route.path, query: resto }).catch(() => {});

  try {
    const s = servicios.value.find((x) => x.id === svcId);
    if (!s) {
      // El servicio se desactivó o el enlace vino mal: el paso 1 ya está
      // cargado con lo que SÍ existe, así que se elige desde ahí.
      avisoEnlace.value = 'Esa consulta ya no está disponible. Elige una de la lista.';
      return;
    }
    await elegirServicio(s);

    if (!espId) return;
    const e = especialistas.value.find((x) => x.id === espId);
    if (!e) {
      avisoEnlace.value = 'Quien te indicaron ya no atiende esa consulta. Elige a otra persona del equipo.';
      return;
    }
    await elegirEspecialista(e);

    // Sin horas en el sistema no hay nada que validar: el paso 3 muestra su
    // botón de WhatsApp y ahí se coordina, que es lo correcto para este caso.
    if (e.solo_consulta_whatsapp) return;

    if (!/^\d{4}-\d{2}-\d{2}$/.test(f) || !/^\d{2}:\d{2}$/.test(h)) return;

    // `elegirEspecialista` deja la semana 0 cargada. Si la fecha cae más
    // adelante hay que traer SU semana antes de poder comprobar la hora.
    const dias = Math.round((Date.parse(f) - Date.parse(isoAddDays(0))) / 86400000);
    if (dias < 0 || dias > MAX_SEMANAS * 7 + 6) {
      avisoEnlace.value = 'Esa fecha ya pasó o está fuera del plazo para reservar. Elige otra.';
      return;
    }
    const sem = Math.floor(dias / 7);
    if (sem !== semana.value) {
      semana.value = sem;
      await cargarSemana();
    }

    if (!(slots.value[f] || []).includes(h)) {
      avisoEnlace.value = 'Esa hora ya la tomaron mientras llegabas. Estas son las que quedan.';
      return;
    }
    elegirSlot(f, h);
  } catch (err) {
    // Un enlace que falla no puede tumbar la página: el asistente completo
    // sigue ahí y se puede reservar a mano desde donde haya quedado.
    avisoEnlace.value = 'No pudimos abrir el enlace completo. Sigue desde acá.';
  }
}

const FALTANTES = [
  ['correo', 'Correo Electrónico'],
  ['mascotaNombre', 'Nombre de Mascota'],
  ['tutor', 'Tutor'],
  ['telefono', 'Número de Teléfono'],
];

/* La dirección sale de la lista fija porque solo es obligatoria en la
   atención a domicilio; en el resto ni siquiera se muestra. */
const obligatorios = computed(() =>
  esDomicilio.value ? [...FALTANTES, ['direccion', 'Dirección']] : FALTANTES
);

const datosValidos = computed(() => {
  const p = paciente.value;
  if (obligatorios.value.some(([campo]) => !String(p[campo] || '').trim())) return false;
  if (esTelemedicina.value && (!p.medioConfirmado || !p.declaroAcepto)) return false;
  return true;
});

function irARevisar() {
  error.value = '';
  const p = paciente.value;

  if (auth.isLoggedIn) {
    if (!mascotaElegida.value) {
      error.value = 'Elige para qué mascota es la atención.';
      return;
    }
  } else {
    const faltante = obligatorios.value.find(([campo]) => !String(p[campo] || '').trim());
    if (faltante) {
      error.value = `Completa el campo obligatorio: ${faltante[1]}.`;
      return;
    }
  }
  if (esTelemedicina.value && !p.medioConfirmado) {
    error.value = 'Debes confirmar el medio de conexión.';
    return;
  }
  if (esTelemedicina.value && !p.declaroAcepto) {
    error.value = 'Debes aceptar las condiciones de la reserva.';
    return;
  }
  if (requierePago.value && !p.abonoAceptado) {
    error.value = 'Debes aceptar la política de abono y devolución.';
    return;
  }
  if (requiereOrden.value && !ordenArchivo.value) {
    error.value = 'Para reservar este examen debes adjuntar la orden médica veterinaria.';
    return;
  }
  irPaso(5);
}


/* Abono del 50% que hace firme la reserva.

   El precio de una interconsulta vive en el especialista (cada uno cobra
   distinto), no en el servicio "Interconsultas" — a diferencia de
   Atención a Domicilio, que sí tiene precio fijo por servicio. La misma
   regla vive en `_monto_abono()` del backend, que es quien congela el
   monto real en la cita: si las dos se separan, acá se muestra una cifra y
   se cobra otra. */
const montoTotal = computed(() => {
  const s = servicio.value;
  /* Abono fijo = el total NO se conoce al reservar, así que acá no hay total
     que mostrar. Radiología depende de cuántas placas salgan y Peluquería del
     peso y el pelaje; el precio cargado es un «desde». Devolver ese piso haría
     tres cosas falsas a la vez: mostrarlo como «Valor», ofrecer «pagar el
     total» por esa cifra, y calcular el saldo restándola —que en Radiología,
     sin precio, daría −$27.000—. Misma regla que `_monto_total()` en el
     backend, que es quien congela el NULL en la cita. */
  if (s?.abono_fijo_clp) return 0;
  /* El sábado puede valer distinto (Consulta General: $15.000 de lunes a
     viernes, $20.000 el sábado). `fecha` viene como 'YYYY-MM-DD'; se le
     agrega la hora para que el navegador no la lea como UTC y termine
     mostrando el precio del viernes en una hora de sábado. getDay(): 6 =
     sábado. Misma regla que `_precio_consulta()` en el backend. */
  if (fecha.value && s?.precio_sabado_clp) {
    if (new Date(`${fecha.value}T12:00:00`).getDay() === 6) return s.precio_sabado_clp;
  }
  if (s?.precio_clp) return s.precio_clp;
  /* Sin tarifa propia del servicio hay dos casos, y los separa la bandera del
     catálogo. En Interconsultas manda la del especialista: es su valor de
     interconsulta, distinto en cada especialidad. En los servicios de «valor
     según evaluación» (Radiología, exámenes de sangre) no hay monto: caer ahí
     en la tarifa del profesional mostraría los $45.000 de dermatología a quien
     reserva una radiografía con la dermatóloga, si atiende ese servicio. */
  if (s?.tarifa_por_especialista) return especialista.value?.precio_clp || 0;
  return 0;
});
/* `Math.floor` y no `Math.round`: el backend usa `precio // 2` y hay que dar
   el MISMO número, porque este es el que se le promete al cliente en pantalla
   antes de mandarlo al checkout. `Math.round` redondea la mitad hacia arriba y
   Python la redondea al par, así que con un precio impar diferían en un peso.
   Ver `_monto_abono` en backend/app/routes/citas.py. */
const montoAbono = computed(() => {
  /* El monto fijo manda y no se cruza con el precio: es justamente el caso en
     que no hay un total del que sacar la mitad. Va primero porque Radiología
     no tiene precio — con el orden invertido daría 0 y el paso de pago
     desaparecería, dejando la hora tomada sin cobrar. */
  const fijo = servicio.value?.abono_fijo_clp;
  if (fijo) return fijo;
  return Math.floor(montoTotal.value / 2);
});
/* Si el total no se conoce, «pagar el total» no se puede ofrecer: no hay
   cifra que prometer. El cliente abona y el saldo se cobra en la clínica. */
/* «No hay un total que ofrecer», que es lo que decide si se dibuja la elección
   entre abono y total. Dos casos distintos con la misma consecuencia:

     · `abono_fijo_clp` — el precio cargado es un PISO, no un total (Radiología
       arranca en $55.000 y sube según cuántas placas salgan).
     · `tarifa_por_especialista` — «Interconsultas»: la clínica pidió quitar de
       ahí la opción de pagar el total (05-sep-2026). El número cargado es la
       tarifa de interconsulta del profesional, no el total de la atención.

   Va en pareja con `_monto_total()` del backend, que devuelve NULL en los dos
   casos: si el portal ofreciera la elección igual, el cobro la rechazaría y el
   cliente vería un error después de elegir. */
const totalDesconocido = computed(() =>
  !!servicio.value?.abono_fijo_clp || !!servicio.value?.tarifa_por_especialista
);

/* Exámenes de sangre, ecografía y radiología no se realizan sin la indicación
   del veterinario tratante. Por decisión de la clínica (ago-2026) la orden es
   OBLIGATORIA para reservar estos tres: sin ella no se puede avanzar. El
   archivo se retiene en el navegador durante el formulario —el endpoint de
   subida necesita el cita_id, que todavía no existe— y se sube solo apenas la
   cita se crea, en confirmar(). Desde el punto de vista de quien reserva, el
   paso 4 no deja continuar sin adjuntarla. */
const requiereOrden = computed(() => !!servicio.value?.requiere_orden_examen);

// La orden retenida en memoria hasta que exista la cita. Formatos y tope se
// validan acá por comodidad; el backend igual los revalida (es la defensa real).
const ordenArchivo = ref(null);
const ordenError = ref('');
const ordenSubida = ref(false);   // true tras subirla con éxito en confirmar()
const ORDEN_MAX_MB = 10;

const ORDEN_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

/* Miniatura de lo que se adjuntó. Existe para responder una sola pregunta:
   «¿mandé la foto correcta?». La orden se saca con el teléfono, en la galería
   hay veinte fotos parecidas, y un nombre como IMG_4821.jpg no despeja nada —
   quien se equivoca no se entera hasta el día de la atención, cuando el
   examen no se puede hacer.

   `URL.createObjectURL` retiene el archivo en memoria hasta que se suelta a
   mano, así que TODOS los caminos que borran la orden pasan por
   `soltarPreview()`, incluido el desmontaje de la vista.

   Los PDF no se miniaturizan a propósito: dibujar su primera página exige
   cargar una librería entera, y un `<embed>` en tamaño miniatura se ve en
   blanco en varios navegadores de teléfono — que es justo donde se reserva.
   Para esos se muestra una ficha con el nombre y el peso, y se deja abrirlos
   en el visor del navegador, que sí funciona en todos. */
const ordenPreview = ref('');
const ordenEsImagen = computed(
  () => !!ordenArchivo.value && (ordenArchivo.value.type || '').startsWith('image/')
);
const ordenPeso = computed(() => {
  const b = ordenArchivo.value?.size || 0;
  return b >= 1024 * 1024 ? `${(b / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`;
});

function soltarPreview() {
  if (ordenPreview.value) URL.revokeObjectURL(ordenPreview.value);
  ordenPreview.value = '';
}

function descartarOrden(input) {
  soltarPreview();
  ordenArchivo.value = null;
  if (input) input.value = '';
}

function elegirOrden(e) {
  ordenError.value = '';
  const f = e.target.files?.[0] || null;
  if (!f) { descartarOrden(); return; }
  const ext = (f.name.split('.').pop() || '').toLowerCase();
  if (!ORDEN_EXT.includes(ext)) {
    ordenError.value = 'Formato no permitido. Usa JPG, PNG o PDF.';
    descartarOrden(e.target); return;
  }
  if (f.size > ORDEN_MAX_MB * 1024 * 1024) {
    ordenError.value = `El archivo no puede superar ${ORDEN_MAX_MB} MB.`;
    descartarOrden(e.target); return;
  }
  soltarPreview();
  ordenArchivo.value = f;
  ordenPreview.value = URL.createObjectURL(f);
}

function quitarOrden() {
  descartarOrden();
  ordenError.value = '';
}

onUnmounted(soltarPreview);
/* Solo en la radiografía el archivo sirve además para presupuestar —el valor
   depende de cuántas placas salgan—, así que ahí el texto ofrece algo a cambio
   en vez de pedir un trámite. */
const ordenParaPresupuesto = computed(() => requiereOrden.value && totalDesconocido.value);

/* Cómo cobra este servicio en línea, dictado por el catálogo:
     · 'nada'  → se reserva sin pagar y se cobra en la clínica.
     · 'abono' → 50% para hacer firme la hora, con la opción de pagar todo.
     · 'total' → el 100% por adelantado (Telemedicina: no hay mostrador
       donde cobrar el saldo después).
   Un servicio sin la propiedad se lee como 'abono', que era la regla única
   antes de que existiera. La misma decisión vive en `_monto_abono()` y
   `_monto_de()` del backend, que es quien la aplica de verdad: acá solo se
   muestra, y si las dos se separan el cliente ve una cosa y paga otra. */
const modalidadServicio = computed(() => servicio.value?.modalidad_pago || 'abono');
const exigeTotal = computed(() => modalidadServicio.value === 'total');
const requierePago = computed(() => modalidadServicio.value !== 'nada' && montoAbono.value > 0);

/* Mensaje "según el día" para el paso 4: solo cuando el servicio cobra
   distinto el sábado (Consulta General 15/20, Dermatológica 45/55, Corte de
   uñas 6/10). Deja ver AMBOS valores y marca cuál aplica al día elegido, que
   es lo que la clínica pidió notificar antes del cobro. */
const precioPorDia = computed(() => {
  const s = servicio.value;
  if (!s?.precio_sabado_clp || !s?.precio_clp) return null;
  const esSabado = !!fecha.value &&
    new Date(`${fecha.value}T12:00:00`).getDay() === 6;
  return { lunViernes: s.precio_clp, sabado: s.precio_sabado_clp, esSabado };
});
/* La banda tarifaria del día elegido, no el nombre del día: el precio no es
   "del jueves", es el de lunes a viernes. Decir "Jueves: $15.000" haría pensar
   que cada día tiene el suyo. */
const labelDiaElegido = computed(() =>
  precioPorDia.value?.esSabado ? 'Sábado' : 'Lunes a viernes'
);

/* La nota del catálogo (`precio_detalle`) es lo que el número no dice: de qué
   depende el valor final y dónde se paga. Radiología («la diferencia depende
   de cuántas placas salgan y se paga en la clínica»), Peluquería, exámenes,
   domicilio.

   Se calla cuando la tarifa es por día porque ahí esa nota es LITERALMENTE el
   mismo texto que las dos líneas de arriba ("Lunes a viernes: $15.000 ·
   Sábado: $20.000"), y repetirla dos veces seguidas hace dudar de cuál manda.
   Lo que esas notas agregan al final —Telemedicina, "se paga completa antes de
   la consulta"— ya lo dice el bloque de forma de pago del mismo paso. */
const notaServicio = computed(() =>
  precioPorDia.value ? null : (servicio.value?.precio_detalle || null)
);

/* Solo presentación, para el paso de pago: parte `notaServicio` en trozos para
   poner los montos en negrita y marcar el que coincide con el abono que se
   cobra. No reescribe nada — la nota es texto aprobado por la clínica y se
   edita en el catálogo —: unir todos los `t` devuelve la nota exacta. */
const segmentosNotaServicio = computed(() => {
  const txt = notaServicio.value || '';
  const partes = [];
  let desde = 0;
  for (const m of txt.matchAll(/\$\s?\d{1,3}(?:\.\d{3})+|\$\s?\d+/g)) {
    if (m.index > desde) partes.push({ t: txt.slice(desde, m.index) });
    partes.push({ t: m[0], monto: true, abono: Number(m[0].replace(/\D/g, '')) === montoAbono.value });
    desde = m.index + m[0].length;
  }
  if (desde < txt.length) partes.push({ t: txt.slice(desde) });
  return partes;
});

/* Las consultas que no cobran en línea (Consulta General, Corte de uñas,
   domicilio, exámenes) no muestran ningún bloque de pago en este paso, así que
   sin esta línea la persona se queda sin saber cuándo paga. Solo cuando el
   catálogo no lo dijo ya con sus propias palabras. */
const seCobraEnLaAtencion = computed(() =>
  modalidadServicio.value === 'nada' && !notaServicio.value
);
const pagandoAbono = ref(false);

/* Abono o total: el cliente elige. La clínica solo *exige* el 50% para
   dejar firme la hora, pero quien prefiere dejarlo pagado y llegar sin
   trámite el día de la atención no tenía cómo hacerlo — terminaba pagando
   el saldo en el mostrador. El valor por defecto sigue siendo el abono
   porque es lo que la política pide; pagar todo es una comodidad, no un
   requisito. */
const modalidadPago = ref('abono');
/* Lo que se cobra de verdad: en un servicio de pago completo no hay elección
   que respetar, y dejar que `modalidadPago` mandara habría mostrado el abono
   en el botón mientras el backend cobraba el total. */
const modalidadEfectiva = computed(() => {
  /* Sin total conocido no hay nada que elegir: aunque `modalidadPago` haya
     quedado en 'total' de un servicio anterior, acá vuelve a 'abono'. Sin este
     corte, el botón cobraría `montoTotal`, que en estos servicios es 0. */
  if (totalDesconocido.value) return 'abono';
  return exigeTotal.value ? 'total' : modalidadPago.value;
});
const montoAPagar = computed(() =>
  modalidadEfectiva.value === 'total' ? montoTotal.value : montoAbono.value
);
/* 0 y no un número negativo cuando el total no se conoce: el saldo existe
   (la diferencia se paga en la clínica) pero no se puede calcular, y el UI
   lo dice con palabras en vez de una cifra inventada. */
const saldoTrasPagar = computed(() =>
  totalDesconocido.value ? 0 : montoTotal.value - montoAPagar.value
);

/* Los pasos de la reserva, numerados.
   El indicador anterior mostraba etiquetas sueltas y un paso todavía
   bloqueado se veía igual que uno disponible: mismo fondo, mismo borde,
   solo cambiaba el cursor. La gente lo tocaba, no pasaba nada, y concluía
   que la página estaba rota. Aquí cada paso lleva su número y su estado
   para que se lea como una secuencia. */
const pasos = computed(() => {
  const def = [
    { id: 1, label: w.value.consultas || 'Tipo de consulta', volver: () => true },
    { id: 2, label: w.value.especialistas || 'Especialista', volver: () => !!servicio.value || !!grupoElegido.value },
    { id: 3, label: w.value.times || 'Fecha y hora', volver: () => !!especialista.value },
    { id: 4, label: w.value.client || 'Tus datos', volver: () => false },
    { id: 5, label: w.value.review || 'Confirmar', volver: () => false },
    ...(requierePago.value ? [{ id: 5.5, label: 'Pago', volver: () => false }] : []),
  ];
  return def.map((p, i) => ({
    ...p,
    n: i + 1,
    hecho: paso.value > p.id,
    actual: paso.value === p.id,
    // Se puede volver a un paso ya completado, nunca saltar hacia adelante.
    navegable: paso.value > p.id && p.volver(),
  }));
});
const pasoActual = computed(() => pasos.value.find((p) => p.actual));

function irAPaso(p) {
  if (!p.navegable) return;
  if (confirmada.value) { cambiarHoraSinPagar(p.id); return; }
  irPaso(p.id);
}

/* La hora que se dejó sin pagar al volver atrás desde el pago, para avisarlo
   en el paso 3. */
const horaSoltada = ref(null);

/* Volver a elegir con la cita ya creada y sin pagar.

   NO la cancela en el servidor: no hay una vía pública para eso, y abrir una
   escritura sin token para ahorrar unos minutos no se justifica (el backend
   recibe solo arreglos críticos hasta el corte a Laravel). La cita sin pagar
   ya se suelta sola: el barrido de `citas_expiracion.py` la marca Expirada a
   los MIN_VENTANA_PAGO minutos y borra su evento de Google. Lo que hace esto es
   dejar de retenerla en la pantalla, para que la persona pueda elegir otra.

   La consulta, quien atiende, los datos y la orden de examen se conservan:
   quien se arrepiente del día no tiene por qué llenar el formulario de nuevo.
   Y el paso 3 recarga las horas reales en vez de mostrar las de hace un rato.

   Consecuencia a saber: esa hora sigue ocupada hasta que venza su ventana, así
   que durante unos minutos no se ofrece ni siquiera a quien la soltó. El aviso
   del paso 3 lo dice. */
function cambiarHoraSinPagar(destino = 3) {
  if (!confirmada.value) return;
  horaSoltada.value = { fecha: fecha.value, hora: hora.value };
  confirmada.value = null;
  ordenSubida.value = false;
  pagandoAbono.value = false;
  error.value = '';
  if (destino >= 3 && especialista.value) {
    elegirEspecialista(especialista.value);
  } else {
    irPaso(destino);
  }
}

/* Manda al cliente al Web Checkout de Getnet.

   El backend crea la sesión (necesita el secretKey, que nunca sale del
   servidor) y devuelve la `processUrl` ya firmada. Se usa
   `location.assign` en vez del router: es otro dominio, no una ruta de
   esta app. `pagandoAbono` no se apaga en un `finally` a propósito — si la
   llamada salió bien, la página se está yendo, y devolver el botón a su
   estado normal solo alcanzaría a parpadear. */
async function pagarAbono() {
  if (!confirmada.value?.id) return;
  pagandoAbono.value = true;
  error.value = '';
  try {
    const { data } = await api.post(`/pagos/cita/${confirmada.value.id}/sesion`, {
      modalidad: modalidadEfectiva.value,
    });
    window.location.assign(data.process_url);
  } catch (e) {
    error.value = apiError(e, 'No pudimos abrir el pago. Intenta de nuevo.');
    pagandoAbono.value = false;
  }
}

async function confirmar() {
  // Candado contra la doble reserva. El guard de navegación ya impide
  // retroceder con la cita creada, pero este es el que de verdad protege la
  // base: si por cualquier vía se llegara acá dos veces (doble clic en el
  // botón, un "adelante" del navegador, un estado que no previmos), lo que
  // corresponde es seguir al pago de la cita que YA existe, nunca crear otra.
  if (confirmada.value) {
    irPaso(5.5);
    return;
  }
  busy.value = true;
  error.value = '';
  const p = paciente.value;
  try {
    const respuestas = {
      'Servicio Veterinario': p.servicioVeterinario || servicio.value.nombre,
    };
    if (esDomicilio.value && p.direccion.trim()) respuestas['Dirección'] = p.direccion.trim();
    if (esTelemedicina.value) {
      // Las citas YA reservadas conservan «Plataforma ZOOM» en sus respuestas,
      // y esta bien: se reservaron con esa herramienta. Este valor describe lo
      // que se acordo el dia de la reserva, no la herramienta de hoy.
      respuestas['Medio de conexión'] = p.medioConfirmado ? 'Google Meet' : '';
      respuestas['Declaro y acepto'] = p.declaroAcepto ? 'Confirmado' : '';
    }
    if (requierePago.value) {
      respuestas['Declaro y acepto política de abono'] = p.abonoAceptado ? 'Confirmado' : '';
    }

    let data;
    if (auth.isLoggedIn) {
      ({ data } = await api.post('/citas/crear', {
        mascota_id: mascotaElegida.value.id,
        servicio_id: servicio.value.id,
        especialista_id: especialista.value.id,
        fecha_hora: `${fecha.value}T${hora.value}:00`,
        respuestas,
      }));
    } else {
      const recaptchaToken = await obtenerTokenRecaptcha('reservar');
      ({ data } = await api.post('/citas/reservar', {
        correo: p.correo.trim(),
        tutor: p.tutor.trim(),
        telefono: p.telefono.trim(),
        mascota_nombre: p.mascotaNombre.trim(),
        servicio_id: servicio.value.id,
        especialista_id: especialista.value.id,
        fecha_hora: `${fecha.value}T${hora.value}:00`,
        direccion: esDomicilio.value ? p.direccion.trim() : '',
        respuestas,
        recaptcha_token: recaptchaToken,
        // Constancia de que el aviso de privacidad se mostró en este flujo.
        // No es un consentimiento arrancado: es el registro de que se cumplió
        // el deber de informar, con la versión del documento que el backend
        // sella. Antes esta ruta creaba la cuenta sin capturar nada, y como la
        // columna es NOT NULL DEFAULT FALSE, todas esas personas quedaban
        // registradas como que nunca vieron la política.
        acepta_privacidad: true,
      }));
      if (p.guardarInfo) {
        localStorage.setItem(LS_KEY, JSON.stringify({ correo: p.correo, tutor: p.tutor, telefono: p.telefono }));
      } else {
        localStorage.removeItem(LS_KEY);
      }
    }
    // La orden se retuvo en memoria porque el endpoint necesita el cita_id;
    // ahora que la cita existe, se sube. Si fallara, la cita ya está hecha:
    // no se deshace —se marca ordenSubida=false para ofrecer reintento en la
    // pantalla siguiente (el SubirOrdenExamen de respaldo)—.
    //
    // La subida va ANTES de publicar `confirmada`, y ese orden arregla un bug
    // real. Con `confirmada` puesta primero, el paso 5 cambiaba en el acto a
    // su pantalla de "Gracias por Reservar" y Vue alcanzaba a pintarla durante
    // este await: quien reservaba una Radiología veía un agradecimiento que se
    // borraba solo un segundo después, y aterrizaba en el pago sin haber
    // leído nada. Solo pasaba en los servicios con orden de examen —los únicos
    // con un await entre medio— que además son los de abono, el peor cruce
    // posible. Publicando la cita y el paso juntos al final, Vue pinta una
    // sola vez y la transición al pago es directa.
    ordenSubida.value = false;
    if (requiereOrden.value && ordenArchivo.value && data.id) {
      try {
        const fd = new FormData();
        fd.append('archivo', ordenArchivo.value);
        await api.post(`/citas/${data.id}/orden-examen`, fd);
        ordenSubida.value = true;
      } catch (_e) {
        // No romper la confirmación ya hecha; el respaldo del paso siguiente
        // deja reintentar contra la cita recién creada.
      }
    }
    confirmada.value = data;
    // La lista del portal acaba de quedar vieja: hay una cita más. Sin esto,
    // el propio cliente que reserva no la ve en /citas hasta recargar.
    datos.invalidar();
    // La hora ya está tomada. Con abono, sigue el paso de pago (5.5). Sin
    // abono, la reserva ya está completa: en vez de saltar a un paso nuevo
    // sin ninguna lógica propia, el propio paso 5 cambia su contenido en el
    // lugar a "gracias" (ver el `v-if="!confirmada"` de esa sección) —
    // fusiona resumen+confirmación y el agradecimiento en una sola pantalla.
    if (data.monto_abono_clp) irPaso(5.5);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  } catch (e) {
    error.value = apiError(e, 'No se pudo reservar. Prueba otro horario.');
    if (e.response?.status === 409) {
      await elegirEspecialista(especialista.value);
    }
  } finally {
    busy.value = false;
  }
}

function reiniciar() {
  irPaso(1);
  servicio.value = null;
  // También el grupo: si no, «empezar de nuevo» dejaría el paso 2 alcanzable
  // con la dermatología ya elegida y el paso 1 no volvería a preguntarla.
  grupoElegido.value = null;
  especialistasPorServicio.value = {};
  especialista.value = null;
  fecha.value = '';
  hora.value = '';
  confirmada.value = null;
  horaSoltada.value = null;
  descartarOrden();
  ordenError.value = '';
  ordenSubida.value = false;
  pagandoAbono.value = false;
  mascotaElegida.value = misMascotas.value.length === 1 ? misMascotas.value[0] : null;
  mostrarNuevaMascota.value = false;
  const contactoPrevio = { correo: paciente.value.correo, tutor: paciente.value.tutor, telefono: paciente.value.telefono, guardarInfo: paciente.value.guardarInfo };
  paciente.value = {
    correo: paciente.value.guardarInfo ? contactoPrevio.correo : '',
    mascotaNombre: '', tutor: paciente.value.guardarInfo ? contactoPrevio.tutor : '',
    telefono: paciente.value.guardarInfo ? contactoPrevio.telefono : '',
    servicioVeterinario: '', direccion: '',
    medioConfirmado: false,
    declaroAcepto: false, abonoAceptado: false,
    guardarInfo: contactoPrevio.guardarInfo,
  };
}

onMounted(async () => {
  if (auth.isLoggedIn) {
    cargarMisMascotas();
  } else {
    cargarContactoGuardado();
    precargarRecaptcha(); // solo el flujo sin sesión llama a /citas/reservar
  }
  try {
    const [a, s] = await Promise.all([
      api.get('/catalogo/ajustes'),
      api.get('/catalogo/servicios'),
    ]);
    ajustes.value = a.data;
    servicios.value = s.data;

    // Enlace permanente: fija el especialista y filtra sus servicios
    if (props.slug) {
      const { data: e } = await api.get(`/catalogo/especialistas/slug/${props.slug}`);
      espFijo.value = e;
      servicios.value = s.data.filter(
        (svc) => !svc.especialista_ids.length || svc.especialista_ids.includes(e.id)
      );
    }

    // Va acá y no antes: resuelve ids contra el catálogo, así que necesita
    // `servicios` ya cargado. Y va dentro del try porque una de sus llamadas
    // puede fallar igual que las de arriba.
    await precargarDesdeUrl();
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar la página de reservas.');
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div class="rsv-page">
    <header class="rsv-hero">
      <img src="/portada-inicio.webp" alt="" aria-hidden="true" class="rsv-hero-fondo" />
      <div class="rsv-hero-velo" aria-hidden="true"></div>
      <div class="rsv-hero-blob" aria-hidden="true"></div>
      <!-- La salida al sitio vive acá, arriba a la izquierda del hero azul, y
           no en el carril del topbar (App.vue): ahí se confundía con los
           enlaces del portal. -->
      <a :href="SITIO_URL" class="rsv-volver">
        <BaseIcon name="chevronLeft" :size="16" />
        <span>Volver al sitio web</span>
      </a>
      <div class="rsv-hero-in">
        <img src="/isotipo.webp" alt="" class="rsv-logo" />
        <h1>{{ ajustes?.nombre || CLINICA.nombre }}</h1>
        <p class="rsv-global">{{ w.global || 'Reserva tu hora en unos minutos, sin llamar y sin esperar.' }}</p>
        <p v-if="espFijo" class="esp-fijo">
          <img v-if="urlFotoEsp(espFijo)" :src="urlFotoEsp(espFijo)" alt="" />
          <span v-else aria-hidden="true">{{ espFijo.emoji }}</span>
          Reservando con <strong>{{ espFijo.nombre }}<template v-if="espFijo.especialidad"> - {{ espFijo.especialidad }}</template></strong>
        </p>
        <p v-if="espFijo?.descripcion" class="esp-fijo-desc">{{ espFijo.descripcion }}</p>
      </div>
      <div class="rsv-hero-onda" aria-hidden="true"></div>
    </header>

    <div class="rsv-contenido">
      <!-- Lo que el enlace del agente no pudo cumplir (hora tomada, consulta
           dada de baja). Va acá arriba, dentro del flujo y no como modal: la
           persona tiene que poder seguir eligiendo sin cerrar nada. -->
      <p v-if="avisoEnlace" class="aviso-enlace" role="status">{{ avisoEnlace }}</p>
      <!-- Migas -->
      <nav v-if="paso < 6" class="pasos-nav" aria-label="Progreso de la reserva">
        <p class="pasos-cuenta">
          <!-- Se retrocede con el botón del navegador porque cada paso escribe
               su ?paso=N; esto es el mismo gesto, pero visible dentro de la
               página. NO aparece con la cita ya creada (paso 5.5): ahí la hora
               está tomada y volver llevaría a confirmar dos veces la misma
               reserva —el bug que se arregló el 01-sep—. -->
          <!-- En el pago (5.5) SÍ aparece, desde sep-2026: ahí «atrás» es
               cambiar la fecha u hora, y suelta la cita sin pagar en vez de
               volver a confirmarla (`cambiarHoraSinPagar`). -->
          <button
            v-if="paso > 1 && (!confirmada || paso === 5.5)"
            type="button" class="paso-atras"
            :aria-label="confirmada ? 'Cambiar fecha u hora' : `Volver al paso ${Math.floor(paso) - 1}`"
            @click="confirmada ? cambiarHoraSinPagar(3) : irPaso(Math.floor(paso) - 1)"
          >
            <BaseIcon name="chevronLeft" :size="16" />
            <span>Atrás</span>
          </button>
          Paso {{ pasoActual?.n }} de {{ pasos.length }}<span class="pasos-sep"> · </span>
          <strong>{{ pasoActual?.label }}</strong>
        </p>
        <ol class="pasos">
          <li
            v-for="(p, idx) in pasos" :key="p.id"
            class="paso-item"
            :class="{ hecho: p.hecho, actual: p.actual, pendiente: !p.hecho && !p.actual }"
          >
            <!-- El conector es un elemento real (no un pseudo-::before) para
                 poder animar su relleno con una capa propia: un pseudo no
                 admite una segunda capa interna independiente sin cálculos
                 de posición frágiles. Se llena con la MISMA regla que antes
                 decidía su color a secas: el paso anterior ya completado, o
                 éste siendo el actual (para que el tramo se vea "en camino"
                 apenas se entra al paso, no recién al completarlo). -->
            <span v-if="idx > 0" class="paso-conector" aria-hidden="true">
              <span class="paso-conector-fill" :class="{ lleno: pasos[idx - 1].hecho || p.actual }"></span>
            </span>
            <button
              type="button" class="paso-btn"
              :disabled="!p.navegable"
              :aria-current="p.actual ? 'step' : undefined"
              :title="p.navegable ? `Volver a ${p.label}` : (p.actual ? p.label : 'Completa los pasos anteriores')"
              @click="irAPaso(p)"
            >
              <span class="paso-n" aria-hidden="true">
                <Transition name="paso-check" mode="out-in">
                  <span v-if="p.hecho" key="check" class="paso-check"><BaseIcon name="check" :size="13" /></span>
                  <span v-else key="num">{{ p.n }}</span>
                </Transition>
              </span>
              <span class="paso-label">{{ p.label }}</span>
            </button>
          </li>
        </ol>
      </nav>

      <p v-if="loading" class="empty">Cargando…</p>
      <p v-else-if="error && paso === 1 && !servicios.length" class="error-text center">{{ error }}</p>

      <Transition v-else name="paso" mode="out-in">
      <div :key="paso" class="rsv-body">
      <!-- Paso 1: Tipo de Consulta -->
      <section v-if="paso === 1" class="panel">
        <h2>{{ w.consultas || 'Tipo de Consulta' }}</h2>
        <div class="svc-list onda">
          <!-- Una entrada por consulta suelta, y UNA por grupo: la dermatología
               se pregunta como una sola cosa acá y se abre en el paso 2, donde
               están la foto de quien atiende y el valor de cada opción. -->
          <button
            v-for="(en, i) in entradasPaso1" :key="en.clave"
            class="svc-row" :style="{ '--i': i }"
            @click="en.tipo === 'grupo' ? elegirGrupo(en) : elegirServicio(en.servicio)"
          >
            <template v-if="en.tipo === 'grupo'">
              <img v-if="urlImagenSvc(en.miembros[0])" class="svc-img" :src="urlImagenSvc(en.miembros[0])" alt="" loading="lazy" />
              <span v-else class="svc-emoji">{{ en.miembros[0].emoji }}</span>
              <span class="svc-info">
                <strong>{{ en.grupo }}</strong>
                <span class="svc-tags">
                  <span v-for="m in en.miembros" :key="m.id" class="tag">{{ m.nombre }}</span>
                </span>
                <span class="svc-desc">Elige entre {{ en.miembros.length }} opciones en el paso siguiente.</span>
              </span>
              <span class="svc-go">→</span>
            </template>
            <template v-else>
            <img v-if="urlImagenSvc(en.servicio)" class="svc-img" :src="urlImagenSvc(en.servicio)" alt="" loading="lazy" />
            <span v-else class="svc-emoji">{{ en.servicio.emoji }}</span>
            <span class="svc-info">
              <strong>{{ en.servicio.nombre }}</strong>
              <span class="svc-tags">
                <span class="tag">{{ durServicio(en.servicio) }}</span>
                <span v-if="chipPrecio(en.servicio)" class="tag precio">{{ formatCLP(en.servicio.precio_clp) }}</span>
              </span>
              <!-- La tarifa cambia según el día: mostrar solo el precio base
                   hace que quien reserve un sábado vea un valor que no es el
                   suyo. -->
              <span v-if="en.servicio.precio_detalle" class="svc-precio-det">{{ en.servicio.precio_detalle }}</span>
              <span v-if="en.servicio.descripcion" class="svc-desc">{{ en.servicio.descripcion }}</span>
              <!-- Ecografía, radiología: se confirma por WhatsApp (la clínica,
                   30-sep-2026). Se avisa acá y no recién en el paso 2. -->
              <span v-if="en.servicio.coordinar_whatsapp" class="svc-badge-whatsapp">Confirmar disponibilidad por WhatsApp</span>
            </span>
            <span class="svc-go">→</span>
            </template>
          </button>
        </div>
      </section>

      <!-- Paso 2: Especialista / Sala -->
      <section v-else-if="paso === 2" class="panel">
        <button class="volver" @click="irPaso(1)">← {{ grupoElegido ? grupoElegido.grupo : servicio.nombre }}</button>
        <h2>{{ w.especialistas || 'Sala' }}</h2>
        <p v-if="loadingPaso" class="empty">Cargando…</p>

        <!-- Entrada por GRUPO: una tarjeta por cada consulta del grupo con la
             foto de quien la atiende. Con dos consultas de la misma
             profesional, sale dos veces — que es lo que la clínica pidió ver.
             El subtítulo es el NOMBRE DE LA CONSULTA y no la especialidad:
             ahí es donde se está eligiendo, y «Dermatología» repetido dos
             veces no distinguiría una tarjeta de la otra. -->
        <div v-else-if="grupoElegido" class="svc-list svc-list--esp">
          <button v-for="t in tarjetasGrupo" :key="t.clave" class="svc-row" @click="elegirTarjetaGrupo(t)">
            <img v-if="urlFotoEsp(t.especialista)" :src="urlFotoEsp(t.especialista)" alt="" class="svc-foto" />
            <span v-else class="svc-emoji">{{ t.especialista.emoji }}</span>
            <span class="svc-info">
              <strong>{{ t.especialista.nombre }}</strong>
              <span class="svc-sub">{{ t.servicio.nombre }}</span>
              <!-- Acá el precio SÍ decide y no puede faltar: dentro de un
                   grupo la elección real es entre valores (Dermatológica 45/55
                   contra Control 30/40), no entre profesionales — suele ser la
                   misma. Pero va la nota SOLA, sin el chip: el chip dice
                   «45.000 CLP» y la nota «Lunes a viernes: $45.000 · Sábado:
                   $55.000», o sea el mismo número dos veces en una tarjeta de
                   tres líneas, y encima el chip miente en sábado. Por eso
                   `chipPrecio()` ya lo suprime cuando hay nota. -->
              <span class="svc-tags">
                <span class="tag">{{ durServicio(t.servicio) }}</span>
                <span v-if="chipPrecio(t.servicio)" class="tag precio">{{ formatCLP(t.servicio.precio_clp) }}</span>
              </span>
              <span v-if="t.servicio.precio_detalle" class="svc-precio-det">{{ t.servicio.precio_detalle }}</span>
            </span>
            <span class="svc-go">→</span>
          </button>
          <p v-if="!tarjetasGrupo.length" class="empty">
            No hay horas configuradas para estas consultas.
          </p>
        </div>

        <div v-else class="svc-list svc-list--esp">
          <button v-for="e in especialistas" :key="e.id" class="svc-row" @click="elegirDeLaLista(e)">
            <img v-if="urlFotoEsp(e)" :src="urlFotoEsp(e)" alt="" class="svc-foto" />
            <span v-else class="svc-emoji">{{ e.emoji }}</span>
            <span class="svc-info">
              <strong>{{ e.nombre }}</strong>
              <span v-if="e.especialidad" class="svc-sub">{{ e.especialidad }}</span>
              <!-- Solo en las interconsultas: es el único servicio donde el
                   valor lo pone el especialista y no la consulta, así que en
                   los demás el precio ya se mostró en el paso anterior y
                   repetirlo por profesional sugeriría que cambia según quién
                   atienda. -->
              <span v-if="servicio?.tarifa_por_especialista && e.precio_clp" class="svc-tags">
                <span class="tag precio">{{ formatCLP(e.precio_clp) }}</span>
              </span>
              <!-- Va dentro de svc-info (columna con ancho propio), no al
                   lado de la flecha: como hermano de la foto, con
                   `flex:none` + `white-space:nowrap`, el badge se rehusaba a
                   encogerse o partir línea y terminaba saliéndose del borde
                   de la tarjeta en nombres largos como "Dr. Miguel Solano"
                   (2 líneas). Acá adentro hereda el ancho de la columna y
                   puede partir en varias líneas sin desbordar nada. -->
              <span v-if="e.solo_consulta_whatsapp" class="svc-badge-whatsapp">Confirmar disponibilidad</span>
            </span>
            <span class="svc-go">→</span>
          </button>
          <p v-if="!especialistas.length" class="empty">No hay especialistas disponibles para este servicio.</p>
        </div>
      </section>

      <!-- Paso 3: Tiempo y hora (columnas por día) -->
      <section v-else-if="paso === 3" class="panel">
        <button class="volver" @click="irPaso(2)">← {{ especialista.nombre }}</button>

        <!-- Especialista externo sin horas fijas: no hay calendario que
             mostrar (no tiene Horarios cargados) — se deriva a WhatsApp
             para consultar disponibilidad a mano, como pidió la clínica. -->
        <div v-if="especialista.solo_consulta_whatsapp" class="whatsapp-cta">
          <span class="whatsapp-cta-ic" aria-hidden="true"><BaseIcon name="wa" :size="40" /></span>
          <h2>Consulta su disponibilidad por WhatsApp</h2>
          <p v-if="WHATSAPP_RESERVAS_ES_BOT">
            Escríbele a nuestro asistente por WhatsApp: le consulta la disponibilidad
            al especialista y te ayuda a agendar la hora ahí mismo.
          </p>
          <p v-else>
            Esta hora se coordina directamente con nosotros. Escríbenos y te
            confirmamos disponibilidad y valor para esta semana o la próxima.
          </p>

          <!-- Solo el nombre. El teléfono no se pregunta: el mensaje sale por
               WhatsApp y WhatsApp ya le muestra el número a la clínica. Con
               sesión no se pide nada. Opcional: el botón funciona vacío. -->
          <div v-if="!auth.isLoggedIn" class="wa-contacto">
            <input
              class="input" type="text" v-model="contactoWa.nombre"
              placeholder="Tu nombre (opcional)" autocomplete="name"
            />
          </div>
          <p v-else class="wa-como">
            Escribes como <strong>{{ quienConsulta.nombre || auth.user?.email }}</strong>.
          </p>

          <a
            class="btn-whatsapp"
            :href="`https://wa.me/${WHATSAPP_TEL}?text=${mensajeWhatsapp(especialista)}`"
            target="_blank" rel="noopener"
          >Escribir por WhatsApp</a>
        </div>

        <template v-else>
        <h2>{{ w.times || 'Escoja tiempo y hora' }}</h2>
        <!-- Solo tras volver desde el pago. Dice las dos cosas que la persona
             no puede adivinar: que esa hora no se ofrece por unos minutos, y
             que el enlace de pago del correo es de la reserva que dejó. -->
        <p v-if="horaSoltada" class="aviso-enlace" role="status">
          Dejaste sin pagar la hora del {{ labelFechaLarga(horaSoltada.fecha).toLowerCase() }} a las
          {{ horaSoltada.hora }} Hrs.: se libera sola en {{ MIN_VENTANA_PAGO }} minutos como máximo.
          No uses el enlace de pago del correo de esa reserva.
        </p>

        <!-- Navegación de semanas + calendario desplegable -->
        <div class="sem-nav">
          <button
            type="button" class="sem-flecha"
            :disabled="semana === 0" aria-label="Semana anterior"
            @click="moverSemana(-1)"
          >‹</button>

          <div class="sem-cal-wrap">
            <button type="button" class="sem-cal-btn" @click.stop="abrirCalendario">
              <span class="cal-ico" aria-hidden="true"><BaseIcon name="calendar" :size="16" /></span>
              {{ rangoLabel }}
              <span class="cal-chev" :class="{ abierto: calAbierto }" aria-hidden="true">▾</span>
            </button>

            <!-- Calendario mensual -->
            <div v-if="calAbierto" class="cal-pop" @click.stop>
              <div class="cal-head">
                <button type="button" class="cal-nav" @click="moverCalMes(-1)" aria-label="Mes anterior">‹</button>
                <span class="cal-titulo">{{ calTitulo }}</span>
                <button type="button" class="cal-nav" @click="moverCalMes(1)" aria-label="Mes siguiente">›</button>
              </div>
              <div class="cal-grid">
                <span v-for="d in DIAS_SEMANA" :key="d" class="cal-dow">{{ d }}</span>
                <button
                  v-for="c in calDias" :key="c.iso"
                  type="button"
                  class="cal-dia"
                  :class="{ 'otro-mes': c.otroMes, hoy: c.iso === hoyISO }"
                  :disabled="c.deshabilitado"
                  @click="elegirDelCalendario(c)"
                >{{ c.num }}</button>
              </div>
            </div>
          </div>

          <button
            type="button" class="sem-flecha"
            :disabled="semana >= MAX_SEMANAS" aria-label="Semana siguiente"
            @click="moverSemana(1)"
          >›</button>
        </div>

        <p v-if="loadingPaso" class="empty">Buscando horarios…</p>

        <template v-else>
          <!-- Móvil: un día a la vez, elegido desde la tira superior -->
          <div v-if="esMovil" class="dias-movil">
            <div class="dias-tira" role="tablist" aria-label="Elegir día">
              <button
                v-for="d in diasConHoras" :key="d.iso"
                type="button" role="tab"
                class="dia-pill"
                :class="{ activo: d.iso === diaSelIso, hoy: d.esHoy }"
                :aria-selected="d.iso === diaSelIso"
                @click="diaSelIso = d.iso"
              >
                <span class="pill-dia">{{ labelColumna(d.iso).dia }}</span>
                <span class="pill-num">{{ d.diaNum }}</span>
                <span class="pill-estado" aria-hidden="true">{{ d.total }}</span>
                <span class="sr-only">{{ d.total }} horas disponibles</span>
              </button>
            </div>

            <div v-if="diaSel" class="dia-panel">
              <p class="dia-titulo">
                <span v-if="diaSel.esHoy" class="dia-hoy-badge">Hoy</span>
                <span class="dia-nombre">{{ diaSel.diaSemana }}</span> {{ diaSel.diaNum }} de {{ diaSel.mes }}
              </p>

              <div v-if="diaSel.manana.length" class="franja">
                <p class="franja-tit">Mañana</p>
                <div class="horas-grid">
                  <button
                    v-for="h in diaSel.manana" :key="h"
                    type="button" class="hora-chip"
                    @click="elegirSlot(diaSel.iso, h)"
                  >{{ h }}</button>
                </div>
              </div>
              <div v-if="diaSel.tarde.length" class="franja">
                <p class="franja-tit">Tarde</p>
                <div class="horas-grid">
                  <button
                    v-for="h in diaSel.tarde" :key="h"
                    type="button" class="hora-chip"
                    @click="elegirSlot(diaSel.iso, h)"
                  >{{ h }}</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Escritorio: columnas por día lado a lado -->
          <div v-else class="cols-scroll">
            <div class="cols-grid">
              <div
                v-for="d in diasConHoras" :key="d.iso"
                class="col-dia" :class="{ hoy: d.esHoy }"
              >
                <div class="col-cab">
                  <span v-if="d.esHoy" class="col-hoy-badge">Hoy</span>
                  <span class="col-nombre">{{ labelColumna(d.iso).dia }}</span>
                  <span class="col-fecha">{{ labelColumna(d.iso).fecha }}</span>
                </div>

                <div class="col-horas">
                  <button
                    v-for="h in [...d.manana, ...d.tarde]" :key="h"
                    type="button" class="hora-chip"
                    @click="elegirSlot(d.iso, h)"
                  >{{ h }}</button>
                </div>
              </div>
            </div>
          </div>

          <p v-if="!totalSemana" class="sem-vacia">
            No hay horas disponibles esta semana. Usa las flechas ›  o el calendario para ver otras fechas
            (puedes agendar hasta con 3 meses de anticipación).
          </p>
        </template>
        </template>
      </section>

      <!-- Paso 4: mascota (con sesión) o datos de contacto (sin cuenta) -->
      <section v-else-if="paso === 4" class="panel">
        <button class="volver" @click="irPaso(3)">← {{ labelFechaLarga(fecha) }} · {{ hora }} Hrs.</button>
        <h2>{{ auth.isLoggedIn ? '¿Para cuál mascota es la atención?' : (w.client || 'Ingrese sus datos') }}</h2>

        <div class="cita-card">
          <div class="cita-card-esp">
            <img v-if="urlFotoEsp(especialista)" :src="urlFotoEsp(especialista)" alt="" class="cita-card-foto" />
            <span v-else class="cita-card-emoji" aria-hidden="true">{{ especialista.emoji }}</span>
            <div class="cita-card-esp-info">
              <strong>{{ especialista.nombre }}</strong>
              <span v-if="especialista.especialidad">{{ especialista.especialidad }}</span>
            </div>
          </div>
          <div class="cita-card-detalle">
            <div class="cita-card-item">
              <span class="cita-card-ic" aria-hidden="true">{{ servicio.emoji }}</span>
              <span>{{ servicio.nombre }} · {{ durServicio(servicio) }}</span>
            </div>
            <!-- La descripción NO se repite acá: es la misma frase que la
                 persona ya leyó en el paso 1 para elegir esta consulta
                 («Evaluación de piel, pelaje y oídos…»), y en el paso 4 no
                 decide nada — la elección ya está hecha. Ocupaba la línea más
                 larga de la tarjeta justo encima del valor, que es lo único
                 nuevo de esta pantalla. -->
            <div class="cita-card-item">
              <span class="cita-card-ic" aria-hidden="true"><BaseIcon name="calendar" :size="18" /></span>
              <span class="cap">{{ labelFechaLarga(fecha) }}</span>
            </div>
            <div class="cita-card-item">
              <span class="cita-card-ic" aria-hidden="true"><BaseIcon name="clock" :size="18" /></span>
              <span>{{ hora }} Hrs.</span>
            </div>
            <!-- `montoTotal` y no `precio_clp`: acá la fecha ya está elegida,
                 así que corresponde el valor de ese día (el sábado cuesta
                 distinto) y no el precio base de lunes a viernes.

                 Cuando la tarifa cambia según el día, el valor lo dice el
                 bloque de abajo con todas sus letras y esta línea sobra. -->
            <div v-if="montoTotal && !precioPorDia" class="cita-card-item">
              <span class="cita-card-ic" aria-hidden="true"><BaseIcon name="card" :size="18" /></span>
              <span>{{ formatCLP(montoTotal) }}</span>
            </div>
          </div>

          <!-- Mensaje según el día elegido.

               En el paso 3 la persona ya eligió fecha, así que acá se puede
               decir el valor de ESE día en vez del precio base: la tarifa de
               sábado es más cara en Consulta General ($15.000/$20.000),
               Dermatológica ($45.000/$55.000), Telemedicina y Corte de uñas
               ($6.000/$10.000), y la clínica pidió que el cobro no llegue de
               sorpresa. Se destaca el que aplica y se deja ver el otro, para
               que quien tenga flexibilidad pueda cambiarse de día. -->
          <div v-if="precioPorDia || notaServicio || seCobraEnLaAtencion" class="aviso-dia">
            <span class="aviso-dia-ic" aria-hidden="true"><BaseIcon name="calendar" :size="18" /></span>
            <div class="aviso-dia-cuerpo">
              <template v-if="precioPorDia">
                <p class="aviso-dia-tit">
                  {{ labelDiaElegido }}: <strong>{{ formatCLP(precioPorDia.esSabado ? precioPorDia.sabado : precioPorDia.lunViernes) }}</strong>
                </p>
                <p class="aviso-dia-otro">
                  <template v-if="precioPorDia.esSabado">
                    De lunes a viernes esta atención vale {{ formatCLP(precioPorDia.lunViernes) }}.
                  </template>
                  <template v-else>
                    Los sábados vale {{ formatCLP(precioPorDia.sabado) }}.
                  </template>
                </p>
              </template>
              <!-- La nota del catálogo (Radiología, Peluquería, exámenes,
                   domicilio) explica lo que el número no dice: de qué depende
                   el valor final y dónde se paga. No se repite cuando la
                   tarifa es por día, porque ahí esa nota ES el mismo texto que
                   las dos líneas de arriba. -->
              <p v-if="notaServicio" class="aviso-dia-nota">{{ notaServicio }}</p>
              <!-- El literal queda de respaldo, no de decisión: si el backend
                   es viejo o `wording` llega a medias, la línea sale igual en
                   vez de dejar el bloque en blanco. Es el mismo `||` que usan
                   los títulos de cada paso. -->
              <p v-if="seCobraEnLaAtencion" class="aviso-dia-nota">
                {{ w.pago_en_atencion || 'No se cobra nada ahora: el valor se paga el día de la atención.' }}
              </p>
            </div>
          </div>
        </div>

        <p v-if="!auth.isLoggedIn" class="cuenta-aviso">
          ¿Tienes cuenta? <button type="button" class="link-btn" @click="abrirModalCuenta('login')">Inicia sesión</button>
          o <button type="button" class="link-btn" @click="abrirModalCuenta('registro')">crea una cuenta</button>
          para guardar tus mascotas — o sigue sin cuenta, es opcional.
        </p>

        <form class="datos-form" @submit.prevent="irARevisar">
          <!-- Con sesión: elegir mascota de la cuenta, ya tenemos sus datos de contacto -->
          <template v-if="auth.isLoggedIn">
            <div class="field">
              <div class="mascota-list">
                <button
                  v-for="m in misMascotas" :key="m.id" type="button"
                  class="mascota-opcion" :class="{ active: mascotaElegida?.id === m.id }"
                  @click="mascotaElegida = m"
                >
                  <img class="mascota-foto" :src="fotoMascota(m)" alt="" />
                  <span class="mascota-nombre">{{ m.nombre }}</span>
                </button>

                <button type="button" class="mascota-opcion mascota-agregar" @click="mostrarNuevaMascota = !mostrarNuevaMascota">
                  <span class="mascota-emoji" aria-hidden="true">+</span>
                  <span class="mascota-nombre">Agregar mascota</span>
                </button>
              </div>

              <div v-if="mostrarNuevaMascota" class="nueva-mascota">
                <input class="input" v-model="nuevaMascota.nombre" placeholder="Nombre de tu mascota" />
                <select class="select" v-model="nuevaMascota.especie">
                  <option value="perro">Perro</option>
                  <option value="gato">Gato</option>
                  <option value="otro">Otro</option>
                </select>
                <button type="button" class="btn btn-ghost btn-sm" :disabled="creandoMascota" @click="crearMascotaRapida">
                  {{ creandoMascota ? 'Agregando…' : 'Agregar' }}
                </button>
              </div>

              <p v-if="!misMascotas.length && !mostrarNuevaMascota" class="hint">
                Todavía no tienes mascotas registradas. Agrega una para continuar.
              </p>
            </div>

            <div v-if="esDomicilio" class="field">
              <label for="p-direccion">Dirección *</label>
              <input id="p-direccion" class="input" v-model="paciente.direccion" required />
              <span class="hint">Indícanos tu dirección lo más precisa posible.</span>
            </div>
          </template>

          <!-- Sin sesión: formulario completo, como hoy -->
          <template v-else>
            <div class="field">
              <label for="p-correo">Correo Electrónico *</label>
              <input id="p-correo" class="input" type="email" v-model="paciente.correo" required />
            </div>

            <div class="field">
              <label for="p-mascota">Nombre de Mascota *</label>
              <input id="p-mascota" class="input" v-model="paciente.mascotaNombre" required />
            </div>

            <div class="field">
              <label for="p-tutor">Tutor *</label>
              <input id="p-tutor" class="input" v-model="paciente.tutor" required />
            </div>

            <div class="field">
              <label for="p-telefono">Número de Teléfono (fijo o celular) *</label>
              <input id="p-telefono" class="input" type="tel" v-model="paciente.telefono" required />
            </div>

            <div v-if="esDomicilio" class="field">
              <label for="p-direccion">Dirección *</label>
              <input id="p-direccion" class="input" v-model="paciente.direccion" required />
              <span class="hint">Indícanos tu dirección lo más precisa posible.</span>
            </div>
          </template>

          <template v-if="esTelemedicina">
            <div class="field">
              <label>Medio de conexión *</label>
              <label class="check">
                <input type="checkbox" v-model="paciente.medioConfirmado" required />
                Google Meet
              </label>
            </div>

            <!-- Estos pasos describían un flujo que NO existe: mandaban a buscar
                 un botón verde «ENTRAR A VIDEO CONFERENCIA» en el correo de
                 confirmación, y ese botón no está en ningún correo —los arma
                 `CitasEmail` en PHP y ninguno lo lleva—, ni hay dónde guardar un
                 enlace de reunión en el esquema. Lo único cierto es lo que dice
                 el propio correo: «el enlace llega antes de la hora agendada»,
                 o sea que hoy alguien lo envía a mano.

                 Se reescriben para decir eso y nada más. Si algún día el enlace
                 se genera solo (Google Calendar ya está integrado y sabe crear
                 uno al agendar), estos pasos vuelven a cambiar — pero mientras
                 tanto no se manda a nadie a buscar algo que no va a encontrar. -->
            <div class="field">
              <label>Cómo te conectas</label>
              <div class="info-box">
                <ol class="info-list">
                  <li>Al reservar te llega un correo de confirmación con el día y la hora.</li>
                  <li>El enlace de Google Meet se envía aparte, antes de tu hora.</li>
                  <li>Ábrelo a la hora reservada y espera a tu médico veterinario.</li>
                </ol>
                <p class="info-nota">
                  Si se acerca tu hora y el enlace no ha llegado, escríbenos por
                  WhatsApp al {{ CLINICA.telefono }}
                </p>
              </div>
            </div>
          </template>

          <!-- Ya no se pregunta la forma de pago: hay una sola (tarjeta por
               Getnet) y se cobra al final, después de confirmar. Se anuncia
               acá igual para que el monto no aparezca de sorpresa dos pasos
               más adelante. -->
          <div v-if="requierePago" class="field">
            <label>Forma de pago</label>
            <div class="pago-opcion pago-opcion-info">
              <span class="pago-texto">
                <strong>Tarjeta de crédito o débito</strong>
                <span class="check-texto">
                  Al confirmar la reserva pasarás al pago seguro de Getnet (Santander).
                  <template v-if="exigeTotal">
                    Esta consulta se paga completa por adelantado: {{ formatCLP(montoTotal) }}.
                  </template>
                  <template v-else-if="totalDesconocido">
                    Se abonan {{ formatCLP(montoAbono) }} ahora; la diferencia se paga
                    en la clínica el día de la atención.
                  </template>
                  <template v-else>
                    Podrás elegir entre abonar {{ formatCLP(montoAbono) }} (50%) o pagar
                    el total de {{ formatCLP(montoTotal) }}.
                  </template>
                </span>
              </span>
            </div>
          </div>

          <!-- Política de abono y devolución: en TODA reserva con pago en
               línea, no solo en interconsulta, y en el paso 4 (sep-2026).
               Es un término del contrato —cuánto se paga, cuánto se devuelve
               y cuándo—, así que tiene que estar a la vista y aceptado antes
               de pagar; el enlace plegado del paso 5 no alcanzaba. Distinto
               del aviso de privacidad de abajo, que solo informa: aquí sí
               hay algo que aceptar. Se guarda en `respuestas` de la cita.
               «48 horas» decía antes; lo que corre de verdad es la ventana de
               MIN_VENTANA_PAGO minutos (citas_expiracion.py). -->
          <div v-if="requierePago" class="field">
            <label>Política de abono y devolución *</label>
            <div class="abono-intro abono-intro-completa politica-paso4">
              <p v-if="exigeTotal"><BaseIcon name="stethoscope" :size="17" class="ic-inline" /> Para confirmar y hacer efectiva esta reserva se requiere el pago total de la consulta por adelantado.</p>
              <p v-else-if="totalDesconocido"><BaseIcon name="stethoscope" :size="17" class="ic-inline" /> Para confirmar y hacer efectiva esta reserva se requiere un abono de {{ formatCLP(montoAbono) }}.</p>
              <p v-else><BaseIcon name="stethoscope" :size="17" class="ic-inline" /> Para confirmar y hacer efectiva esta reserva se requiere un abono del 50% del valor de la {{ esInterconsulta ? 'interconsulta' : 'consulta' }}.</p>
              <p><BaseIcon name="timer" :size="17" class="ic-inline" /> Guardamos la hora {{ MIN_VENTANA_PAGO }} minutos para que realices el {{ exigeTotal ? 'pago' : 'abono' }}. Si no se paga en ese plazo, vuelve a quedar disponible para otras personas.</p>
              <!-- La devolución va resumida en una línea y no solo en el
                   modal: es la condición que se discute cuando alguien
                   cancela, y no puede quedar detrás de un enlace. El detalle
                   completo (y el WhatsApp) vive en el modal, sin repetirlo acá.
                   Mismos números que PoliticaDevolucion.vue: si cambian allá,
                   cambian acá. -->
              <p>↩️ Si cancelas con 24 horas de aviso, se devuelve el 60% de lo pagado; con menos aviso no hay devolución.</p>
              <label class="check check-block">
                <input type="checkbox" v-model="paciente.abonoAceptado" required />
                <!-- .prevent: el enlace vive dentro del <label>, y sin él
                     abrir la política marcaría la casilla sin haberla leído. -->
                <span class="check-texto">Declaro y acepto la <a href="#" class="enlace-politica" @click.prevent="mostrarPoliticaAbono = true">política de abono y devolución</a>.</span>
              </label>
            </div>
          </div>

          <!-- Último de los «declaro y acepto» antes de Continuar, a propósito:
               junto a los otros dos (Medio de conexión, Política de abono), no
               repartido entre bloques de puro texto informativo. -->
          <div v-if="esTelemedicina" class="field">
            <label>Declaro y acepto *</label>
            <label class="check check-block">
              <input type="checkbox" v-model="paciente.declaroAcepto" required />
              <span class="check-texto">
                <ul class="info-list">
                  <li>Debo conectarme puntual a la hora reservada. Retrasos sobre 15 minutos cancelan automáticamente la reserva, debiendo solicitar nueva hora.</li>
                  <li>La reserva de hora tomada es solo para un paciente.</li>
                  <li>El tiempo de atención publicado es meramente referencial.</li>
                </ul>
              </span>
            </label>
          </div>

          <label v-if="!auth.isLoggedIn" class="check">
            <input type="checkbox" v-model="paciente.guardarInfo" />
            Guardar mi información para futuras citas.
          </label>

          <!-- Aviso de privacidad (ley 21.719).
               Es un AVISO, no un checkbox, y la diferencia importa: para darte
               la hora que viniste a pedir la base legal es la prestación del
               servicio, no tu consentimiento. Obligar a marcar una casilla para
               poder reservar simula una elección que no existe — nadie va a
               decir que no y quedarse sin hora. Lo que la ley exige acá es
               informar, y eso es lo que hace este bloque.
               El consentimiento de verdad, el de comunicaciones comerciales, se
               pide aparte y se puede retirar cuando sea desde el portal. -->
          <p v-if="!auth.isLoggedIn" class="aviso-privacidad">
            Con tu reserva guardamos tus datos de contacto y los de tu mascota para
            atenderte y para tu ficha clínica. Puedes ver, descargar o pedir la
            eliminación de tus datos cuando quieras.
            <a :href="`${SITIO_URL}/privacidad`" target="_blank" rel="noopener">Cómo tratamos tus datos</a>.
          </p>

          <!-- Orden de examen OBLIGATORIA (sangre, ecografía, radiografía).
               El archivo se retiene en memoria y se sube al crear la cita;
               acá solo se elige y se valida para poder continuar. -->
          <div v-if="requiereOrden" class="orden-campo" :class="{ 'orden-campo--ok': ordenArchivo }">
            <p class="orden-campo-tit">
              <BaseIcon name="doc" :size="16" class="ic-inline" /> Adjunta la orden de examen <span class="orden-req">obligatorio</span>
            </p>
            <p class="orden-campo-sub">
              <template v-if="ordenParaPresupuesto">
                Esta prestación necesita la indicación de tu veterinario tratante.
                Con ella te confirmamos además el presupuesto total antes de la hora.
              </template>
              <template v-else>
                Esta prestación necesita la indicación de tu veterinario tratante
                para poder reservarla.
              </template>
            </p>

            <template v-if="!ordenArchivo">
              <label class="orden-boton" for="orden-file"><BaseIcon name="doc" :size="16" class="ic-inline" /> Adjuntar orden</label>
              <input id="orden-file" class="orden-file" type="file"
                     accept=".jpg,.jpeg,.png,.pdf" @change="elegirOrden" />
              <p class="orden-campo-nota">Formatos: JPG, JPEG, PNG o PDF. Hasta {{ ORDEN_MAX_MB }} MB.</p>
            </template>

            <template v-else>
              <p class="orden-ok"><BaseIcon name="check" :size="16" class="ic-inline" /> Orden adjuntada correctamente</p>

              <!-- La miniatura enlaza al archivo completo: mirarla en grande es
                   la única forma de comprobar que la orden se lee, y una foto
                   movida o cortada no se nota en 72 píxeles. Se abre en una
                   pestaña nueva para no perder el formulario a medio llenar. -->
              <a
                class="orden-preview" :href="ordenPreview" target="_blank" rel="noopener"
                :title="`Ver ${ordenArchivo.name} en grande`"
              >
                <img v-if="ordenEsImagen" :src="ordenPreview" alt="" class="orden-miniatura" />
                <span v-else class="orden-miniatura orden-miniatura--pdf" aria-hidden="true">PDF</span>
                <span class="orden-preview-datos">
                  <span class="orden-archivo">{{ ordenArchivo.name }}</span>
                  <span class="orden-peso">{{ ordenPeso }} · toca para verla en grande</span>
                </span>
              </a>

              <div class="orden-acciones">
                <button type="button" class="orden-link" @click="quitarOrden">Eliminar</button>
                <label class="orden-link" for="orden-file-reemp">Reemplazar por otra</label>
                <input id="orden-file-reemp" class="orden-file" type="file"
                       accept=".jpg,.jpeg,.png,.pdf" @change="elegirOrden" />
              </div>
            </template>

            <p v-if="ordenError" class="orden-campo-err">{{ ordenError }}</p>
          </div>

          <p v-if="error" class="error-text">{{ error }}</p>
          <button class="btn btn-primary" type="submit">Continuar →</button>
        </form>
      </section>

      <!-- Paso 5: Revisión y confirmación — y, sin abono de por medio, el
           agradecimiento en el mismo lugar. Antes eran dos pasos (5 y 6):
           el 6 no tenía ninguna lógica propia (ni botón, ni llamada a la
           API — la cita ya existe desde que se confirma acá), así que
           fusionarlos es directamente no navegar a una pantalla nueva:
           esta sección cambia su contenido en el lugar cuando `confirmada`
           queda seteada (`confirmar()` ya no llama `irPaso(6)`). Se acepta
           `paso === 6` en la condición por si quedara algún enlace viejo
           apuntando ahí. -->
      <section v-else-if="paso === 5 || paso === 6" class="panel" :class="{ gracias: confirmada }">
      <template v-if="!confirmada">
        <button class="volver" @click="irPaso(4)">← Editar datos</button>
        <h2>{{ w.review || 'Revise su información' }}</h2>
        <div class="resumen">
          <div class="res-row"><span>Consulta</span><strong>{{ servicio.emoji }} {{ servicio.nombre }}</strong></div>
          <div class="res-row"><span>Especialista</span><strong>{{ especialista.nombre }}</strong></div>
          <div class="res-row"><span>Fecha</span><strong class="cap">{{ labelFechaLarga(fecha) }}</strong></div>
          <div class="res-row"><span>Hora</span><strong>{{ hora }} Hrs. · {{ durServicio(servicio) }}</strong></div>
          <div class="res-row"><span>Mascota</span><strong>{{ auth.isLoggedIn ? mascotaElegida?.nombre : paciente.mascotaNombre }}</strong></div>
          <template v-if="!auth.isLoggedIn">
            <div class="res-row"><span>Tutor</span><strong>{{ paciente.tutor }}</strong></div>
            <div class="res-row"><span>Correo</span><strong>{{ paciente.correo }}</strong></div>
            <div class="res-row"><span>Teléfono</span><strong>{{ paciente.telefono }}</strong></div>
          </template>
          <div v-if="esDomicilio && paciente.direccion" class="res-row"><span>Dirección</span><strong>{{ paciente.direccion }}</strong></div>
          <!-- Tiene que ser `montoTotal`, que es de donde sale el abono que
               se cobra dos líneas más abajo. Esta fila calculaba el valor por
               su cuenta y con la prioridad al revés (el precio del especialista
               antes que el del servicio, e ignorando el sábado): mostraba una
               cifra y se cobraba otra. -->
          <div v-if="montoTotal" class="res-row"><span>Valor</span><strong>{{ formatCLP(montoTotal) }}</strong></div>
          <div v-if="requierePago" class="res-row total">
            <span>{{ exigeTotal ? 'Total a pagar' : (totalDesconocido ? 'Abono a pagar' : 'Abono a pagar (50%)') }}</span>
            <strong>{{ formatCLP(exigeTotal ? montoTotal : montoAbono) }}</strong>
          </div>
        </div>

        <p v-if="error" class="error-text">{{ error }}</p>
        <p v-if="!auth.isLoggedIn" class="aviso recaptcha-aviso">
          Este sitio está protegido por reCAPTCHA. Aplican la
          <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Política de Privacidad</a>
          y los
          <a href="https://policies.google.com/terms" target="_blank" rel="noopener">Términos de Servicio</a>
          de Google.
        </p>
        <button
          class="btn btn-primary btn-big"
          :disabled="busy"
          @click="confirmar"
        >
          <!-- "y pagar", sin decir cuánto: el monto se elige en el paso
               siguiente, así que prometer "abono" acá sería adelantar una
               decisión que el cliente todavía no tomó. -->
          {{ busy ? 'Reservando…' : (requierePago ? 'Confirmar y pagar' : 'Confirmar reserva') }}
        </button>
      </template>
      <template v-else>
        <div class="check-big" aria-hidden="true"><BaseIcon name="check" :size="34" /></div>
        <h2>{{ w.completed || 'Gracias por Reservar' }}</h2>
        <p class="gracias-sub">
          Te esperamos el <strong class="cap">{{ labelFechaLarga(fecha) }}</strong> a las
          <strong>{{ hora }} Hrs.</strong> con {{ especialista.nombre }}.
          Enviamos la confirmación a <strong>{{ auth.isLoggedIn ? auth.user?.email : paciente.correo }}</strong>.
        </p>
        <!-- Solo lo ven las reservas SIN pago: quien paga sale del sitio desde
             el paso 5.5 y vuelve a /pago/:citaId, nunca acá. Por eso el mismo
             componente está también en esos dos sitios. -->
        <SubirOrdenExamen
          v-if="requiereOrden && confirmada?.id && !ordenSubida"
          :cita-id="String(confirmada.id)"
          :para-presupuesto="ordenParaPresupuesto"
        />
        <div class="gracias-actions">
          <button class="btn btn-primary" @click="reiniciar">Reservar otra hora</button>
          <template v-if="auth.isLoggedIn">
            <RouterLink class="btn btn-ghost" to="/inicio">Mi portal</RouterLink>
            <RouterLink class="btn btn-ghost" to="/citas">Mis citas</RouterLink>
            <RouterLink class="btn btn-ghost" to="/mascotas">Mis mascotas</RouterLink>
          </template>
        </div>
      </template>
      </section>

      <!-- Paso 6 (5.5 interno): abono del 50% en el Web Checkout de Getnet.
           Quien cierre acá recibe el mismo enlace de pago en el correo de
           confirmación; quien vuelva atrás suelta la cita y elige otra hora. -->
      <section v-else-if="paso === 5.5" class="panel pago">
        <!-- El mismo «← …» que abre cada paso. Antes este no lo tenía a
             propósito (retroceder confirmaba dos veces la misma reserva); ahora
             retroceder suelta la cita sin pagar, así que el riesgo ya no está. -->
        <button class="volver" @click="cambiarHoraSinPagar(3)">← Cambiar fecha u hora</button>
        <!-- «Abona» y no «paga»: lo que se pide acá es el abono, y el texto
             anterior hacía pensar en el total. Cuando el servicio cobra el
             100% por adelantado —Telemedicina— sí se dice pagar, porque ahí
             no hay abono que valga. -->
        <h2 v-if="exigeTotal">¡Ya casi! Paga para confirmar tu hora</h2>
        <h2 v-else>¡Ya casi! Abona para confirmar tu hora</h2>
        <!-- Textos entregados por la clínica (14-sep-2026). La hora va como
             «Hrs.» en todo el flujo de reserva por pedido explícito (los
             botones de horario del paso 3 quedan solo con el número). La condición que elige la segunda oración es la
             misma de antes: solo cambió la redacción. -->
        <p class="abono-intro">
          Hemos reservado tu hora para el <strong>{{ labelFechaFrase(fecha) }}</strong> a las <strong>{{ hora }} Hrs.</strong>{{ ' ' }}
          <template v-if="totalDesconocido">Queda firme con el abono.</template>
          <template v-else>Puedes confirmarla con un abono del 50% o, si lo prefieres, pagar el total de la reserva.</template>
        </p>
        <!-- Pegado al texto de arriba y no junto al botón: es parte de lo que
             ya quedó listo en la reserva, a pedido de la clínica. Si la subida
             automática falló, el componente para reintentarla sigue más abajo,
             antes del botón (ver el comentario de SubirOrdenExamen). -->
        <p v-if="requiereOrden && ordenSubida" class="pago-orden-ok">
          <span class="pago-orden-ok-ic" aria-hidden="true"><BaseIcon name="check" :size="12" /></span>
          <span>Recibimos la orden médica; queda adjunta a los datos de tu reserva.</span>
        </p>

        <!-- Una sola columna, en el orden en que la persona necesita leerlo:
             qué reservó (la única tarjeta), cuánto tiempo se le guarda, cuánto
             paga y con qué, y el botón. Los grupos se separan con aire, no con
             más cajas: antes eran cuatro recuadros apilados con el mismo peso,
             y el botón competía con todos. -->
        <div class="pago-col">
        <div class="pago-grupo">
        <div class="pago-resumen">
          <div class="pago-esp">
            <img v-if="urlFotoEsp(especialista)" :src="urlFotoEsp(especialista)" alt="" class="pago-esp-foto" />
            <span v-else class="pago-esp-foto pago-esp-sinfoto" aria-hidden="true"><BaseIcon name="user" :size="24" /></span>
            <div class="pago-esp-info">
              <strong>{{ especialista.nombre }}</strong>
              <span v-if="especialista.especialidad">{{ especialista.especialidad }}</span>
            </div>
          </div>
          <ul class="pago-datos">
            <li class="pago-dato">
              <span class="pago-dato-ic" aria-hidden="true"><BaseIcon name="stethoscope" :size="18" /></span>
              <span>{{ servicio.nombre }} · {{ durServicio(servicio) }}</span>
            </li>
            <li class="pago-dato pago-dato--clave">
              <span class="pago-dato-ic" aria-hidden="true"><BaseIcon name="calendar" :size="18" /></span>
              <span class="cap">{{ labelFechaLarga(fecha) }}</span>
            </li>
            <li class="pago-dato pago-dato--clave">
              <span class="pago-dato-ic" aria-hidden="true"><BaseIcon name="clock" :size="18" /></span>
              <span>{{ hora }} Hrs.</span>
            </li>
            <li v-if="esDomicilio && paciente.direccion" class="pago-dato">
              <span class="pago-dato-ic" aria-hidden="true"><BaseIcon name="pin" :size="18" /></span>
              <span>{{ paciente.direccion }}</span>
            </li>
          </ul>
        </div>

        <!-- El horario se retiene mientras dure el pago, no para siempre: el
             backend lo libera a los MINUTOS_VENTANA_PAGO minutos
             (citas_expiracion.py). Decirlo acá es lo que hace que el plazo sea
             justo — si el cupo se puede perder, la persona tiene que saberlo
             ANTES de irse a buscar la tarjeta, no al volver. Va pegado a la
             hora que acaba de leer, que es la que se guarda. Sin contador: el
             frontend no conoce el vencimiento real, y uno calculado acá podría
             decir «quedan 3 minutos» de una hora que el backend ya soltó. -->
        <p class="pago-plazo">
          <span class="pago-plazo-ic" aria-hidden="true"><BaseIcon name="timer" :size="20" /></span>
          <span>
            Hemos reservado este horario para ti durante <strong>{{ MIN_VENTANA_PAGO }} minutos</strong> mientras
            completas el pago. Transcurrido ese tiempo, la hora volverá a estar disponible para otras personas.
          </span>
        </p>
        </div>

        <div class="pago-grupo">
        <div v-if="!totalDesconocido || notaServicio" class="pago-precio">
          <!-- Sin total conocido NO se muestra la fila: `montoTotal` vale 0 en
               los servicios de abono fijo (Radiología, Peluquería) porque el
               valor final depende de cuántas placas salgan o del pelaje, y esta
               pantalla anunciaba "Valor de la consulta $0" justo antes de
               cobrar. La nota de abajo y el párrafo del abono ya explican de
               qué depende. -->
          <div v-if="!totalDesconocido" class="res-row pago-valor">
            <span>Valor de la consulta</span><strong>{{ formatCLP(montoTotal) }}</strong>
          </div>
          <!-- `notaServicio` y no `precio_detalle` crudo, por lo mismo que en
               el paso 4: con tarifa por día la nota REPITE el número que la
               fila de arriba ya dio, y encima nombra los dos («Lunes a
               viernes: $45.000 · Sábado: $55.000» bajo un «$45.000» que ya
               resolvió cuál aplica) — justo en la pantalla donde la persona
               está por pagar y cualquier segundo número hace dudar de cuánto
               se le va a cobrar. Lo que sí sobrevive es la nota que agrega
               algo: Radiología y Peluquería explican de qué depende la
               diferencia, y ahí no hay ninguna fila que lo diga. -->
          <!-- Los montos van en negrita y el del abono además marcado, para
               que el número que se cobra se encuentre a la primera. Solo se
               envuelven trozos del mismo texto: unidos, dan la nota exacta. -->
          <p v-if="notaServicio" class="pago-nota-precio"><template v-for="(s, i) in segmentosNotaServicio" :key="i"><strong v-if="s.monto" class="pago-monto" :class="{ 'pago-monto--abono': s.abono }">{{ s.t }}</strong><template v-else>{{ s.t }}</template></template></p>
        </div>

        <!-- Abono o total. Radios de verdad (no botones que parecen radios):
             es una elección con estado, y el lector de pantalla tiene que
             anunciarla como tal. El abono va primero y preseleccionado
             porque es lo que la clínica exige; pagar todo es opcional. -->
        <p v-if="exigeTotal" class="abono-nota abono-nota-total">
          {{ w.pago_total_anticipado || 'Esta consulta se paga completa antes de la atención.' }}
        </p>
        <!-- Sin total conocido no hay elección que ofrecer: el valor final
             depende de cuántas radiografías salgan o del pelaje del perro, y
             solo se sabe en la clínica. -->
        <p v-else-if="totalDesconocido" class="abono-nota abono-nota-total">
          {{ w.pago_abono_saldo || 'Pagas el abono ahora; la diferencia se calcula y se paga en la clínica el día de la atención.' }}
        </p>
        <fieldset v-else class="modo-pago-set">
          <legend>¿Cuánto quieres pagar ahora?</legend>
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

        <!-- ANTES del botón de pago a propósito: al tocarlo, el cliente sale
             del sitio hacia Getnet y vuelve a /pago/:citaId, así que esta es la
             última vez que ve esta pantalla. Debajo del botón, la mitad no la
             vería nunca. -->
        <!-- Cuando la subida SÍ sale bien, el acuse va arriba, bajo el texto
             de la reserva (`.pago-orden-ok`). -->
        <SubirOrdenExamen
          v-if="requiereOrden && confirmada?.id && !ordenSubida"
          :cita-id="String(confirmada.id)"
          :para-presupuesto="ordenParaPresupuesto"
        />
        </div>

        <div class="pago-grupo pago-grupo--accion">
        <p v-if="error" class="error-text">{{ error }}</p>
        <button class="btn btn-primary btn-big pago-boton" :disabled="pagandoAbono" @click="pagarAbono">
          <BaseIcon name="card" :size="20" />
          <span>{{ pagandoAbono ? 'Abriendo el pago…' : `Pagar ${formatCLP(montoAPagar)} con tarjeta` }}</span>
        </button>
        <p v-if="saldoTrasPagar > 0" class="abono-nota">
          Queda un saldo de <strong>{{ formatCLP(saldoTrasPagar) }}</strong> para el día de la atención.
        </p>
        <!-- Decía «tienes 48 horas antes de que la hora se libere», debajo del
             aviso de arriba que dice 15 minutos. Lo real es lo de arriba; lo que
             SÍ sigue valiendo después es el enlace del correo, que revive la
             cita si nadie tomó la hora mientras tanto y, si alguien la tomó, deja
             el pago a la vista del panel para devolverlo (`_guardar_estado` en
             routes/pagos.py). Por eso no se promete que «sirve»: se avisa del
             riesgo. -->
        <p class="pago-seguro">
          <span class="pago-seguro-ic" aria-hidden="true"><BaseIcon name="shield" :size="16" /></span>
          <span>
            Te llevamos al pago seguro de Getnet (Santander). No guardamos los datos de tu tarjeta.
            El enlace de pago también va en el correo de confirmación, pero pasados los
            {{ MIN_VENTANA_PAGO }} minutos la hora puede tomarla otra persona.
          </span>
        </p>
        </div>
        <div class="pago-politica"><PoliticaDevolucion /></div>
        </div>
      </section>
    </div>
    </Transition>
    </div>

    <!-- Modal: la política de abono y devolución, abierta desde el enlace del
         checkbox del paso 4. Mismo envoltorio que el modal de cuenta. -->
    <div v-if="mostrarPoliticaAbono" class="cuenta-overlay" @click.self="mostrarPoliticaAbono = false">
      <div class="cuenta-modal politica-modal" role="dialog" aria-modal="true" aria-labelledby="politica-abono-titulo">
        <h3 id="politica-abono-titulo">Política de abono y devolución</h3>
        <div class="politica-modal-cuerpo">
          <p v-if="exigeTotal">🩺 Para confirmar y hacer efectiva esta reserva se requiere el pago total de la consulta por adelantado.</p>
          <p v-else-if="totalDesconocido">🩺 Para confirmar y hacer efectiva esta reserva se requiere un abono de {{ formatCLP(montoAbono) }}. La diferencia se paga en la clínica el día de la atención.</p>
          <p v-else>🩺 Para confirmar y hacer efectiva esta reserva se requiere un abono del 50% del valor de la {{ esInterconsulta ? 'interconsulta' : 'consulta' }}.</p>
          <p>🛑 Guardamos la hora {{ MIN_VENTANA_PAGO }} minutos para que realices el {{ exigeTotal ? 'pago' : 'abono' }}. Si no se paga en ese plazo, vuelve a quedar disponible para otras personas.</p>
          <PoliticaDevolucion desplegada />
        </div>
        <div class="politica-modal-acciones">
          <button type="button" class="btn btn-ghost" @click="mostrarPoliticaAbono = false">Cerrar</button>
          <button type="button" class="btn btn-primary" @click="paciente.abonoAceptado = true; mostrarPoliticaAbono = false">Acepto</button>
        </div>
      </div>
    </div>

    <!-- Modal: iniciar sesión / crear cuenta, sin perder la reserva en curso -->
    <div v-if="mostrarModalCuenta" class="cuenta-overlay" @click.self="seguirSinCuenta">
      <div class="cuenta-modal">
        <h3>{{ modoCuenta === 'login' ? 'Ingresa a tu cuenta' : 'Crea tu cuenta' }}</h3>
        <p class="cuenta-modal-sub">Guarda tus mascotas y datos para reservar más rápido la próxima vez.</p>

        <div class="tabs" role="tablist">
          <button role="tab" :aria-selected="modoCuenta === 'login'" :class="{ active: modoCuenta === 'login' }" @click="modoCuenta = 'login'">Ingresar</button>
          <button role="tab" :aria-selected="modoCuenta === 'registro'" :class="{ active: modoCuenta === 'registro' }" @click="modoCuenta = 'registro'">Crear cuenta</button>
        </div>

        <BotonGoogle class="cuenta-google" @listo="trasIngresar" @error="cuentaError = $event" />

        <form class="cuenta-form" @submit.prevent="enviarCuenta">
          <div v-if="modoCuenta === 'registro'" class="field">
            <label for="c-nombre">Nombre completo</label>
            <input id="c-nombre" class="input" v-model="cuentaForm.nombre" required />
          </div>
          <div v-if="modoCuenta === 'registro'" class="field">
            <label for="c-telefono">Teléfono (opcional)</label>
            <input id="c-telefono" class="input" type="tel" v-model="cuentaForm.telefono" placeholder="+56 9 ..." />
          </div>
          <div class="field">
            <label for="c-email">Correo</label>
            <input id="c-email" class="input" type="email" v-model="cuentaForm.email" required />
          </div>
          <div class="field">
            <label for="c-password">Contraseña</label>
            <PasswordInput
              id="c-password" v-model="cuentaForm.password" required
              :minlength="modoCuenta === 'registro' ? 8 : null"
              :placeholder="modoCuenta === 'registro' ? 'Mínimo 8 caracteres, con letras y números' : ''"
            />
          </div>
          <div v-if="modoCuenta === 'registro'" class="field field-checks">
            <label class="check">
              <input type="checkbox" v-model="cuentaForm.aceptaTerminos" required />
              <span>Acepto los <a :href="`${SITIO_URL}/terminos`" target="_blank" rel="noopener">Términos y Condiciones</a></span>
            </label>
            <label class="check">
              <input type="checkbox" v-model="cuentaForm.aceptaPrivacidad" required />
              <span>Acepto la <a :href="`${SITIO_URL}/privacidad`" target="_blank" rel="noopener">Política de Privacidad</a></span>
            </label>
            <label class="check">
              <input type="checkbox" v-model="cuentaForm.aceptaMarketing" />
              <span>Quiero recibir novedades y promociones por correo (opcional)</span>
            </label>
          </div>
          <p v-if="cuentaError" class="error-text">{{ cuentaError }}</p>
          <button class="btn btn-primary" type="submit" :disabled="cuentaBusy">
            {{ cuentaBusy ? 'Un momento…' : modoCuenta === 'login' ? 'Ingresar' : 'Crear cuenta' }}
          </button>
          <button type="button" class="btn btn-ghost" @click="seguirSinCuenta">Seguir sin cuenta</button>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.rsv-page { padding-bottom: 5rem; }

/* Cabezal como mini-hero: mismo gradiente azul + blob turquesa + foto de
   fondo espejada que el hero del sitio público (HeroSection.vue), para que
   este flujo se sienta como una página más del sitio, no una app aparte. */
.rsv-hero {
  position: relative; overflow: hidden;
  background: linear-gradient(160deg, var(--brand) 0%, var(--brand-strong) 100%);
  color: #fff; text-align: center;
  padding: clamp(1.1rem, 2.6vw, 1.7rem) 0 clamp(3rem, 6.5vw, 4.2rem);
}
.rsv-hero-fondo {
  position: absolute; inset: 0; width: 100%; height: 100%;
  object-fit: cover; object-position: right center;
  opacity: 0.35; transform: scaleX(-1);
}
.rsv-hero-velo {
  position: absolute; inset: 0;
  background: linear-gradient(160deg, var(--brand) 15%, rgba(26, 35, 80, 0.55) 55%, var(--brand-strong) 100%);
}
.rsv-hero-blob {
  position: absolute; top: -160px; right: -140px;
  width: 420px; height: 420px;
  background: radial-gradient(circle at 35% 35%, rgba(var(--accent-rgb), 0.28), rgba(var(--accent-rgb), 0) 70%);
  border-radius: 44% 56% 65% 35% / 45% 40% 60% 55%;
  filter: blur(4px);
}
.rsv-volver {
  position: absolute; top: clamp(0.7rem, 1.6vw, 1rem); left: clamp(1rem, 3vw, 2rem); z-index: 3;
  display: inline-flex; align-items: center; gap: 0.3rem;
  padding: 0.4em 0.9em 0.4em 0.6em; border-radius: var(--r-full);
  background: rgba(255, 255, 255, 0.14); border: 1px solid rgba(255, 255, 255, 0.28);
  color: #fff; font-weight: 600; font-size: var(--t-sm);
  transition: background 0.2s var(--ease);
}
.rsv-volver:hover { background: rgba(255, 255, 255, 0.24); }
.rsv-volver :deep(svg) { stroke: #fff; transition: transform 0.2s var(--ease); }
.rsv-volver:hover :deep(svg) { transform: translateX(-2px); }
/* En pantalla chica el enlace va en su propia fila, sobre el logo. */
@media (max-width: 640px) {
  .rsv-volver { position: relative; top: 0; left: 0; display: flex; width: fit-content; margin: 0 0 0.6rem 1rem; }
}
.rsv-hero-in { position: relative; z-index: 2; max-width: 640px; margin: 0 auto; padding: 0 clamp(1rem, 3vw, 2rem); }
/* El logo va limpio, sin placa ni filtro. Antes usaba
   filter: brightness(0) invert(1), que lo dejaba como una silueta blanca
   sólida: sirve para iconos de un color, pero este logo tiene dibujo y texto
   adentro y se perdían por completo. Se sostiene sobre el hero oscuro porque
   su interior es claro. */
.rsv-logo {
  display: block; width: 120px; height: 120px; object-fit: contain;
  /* Margen negativo: el PNG trae aire transparente alrededor del escudo, así
     que un margen 0 igual dejaba un hueco visible contra el título. */
  margin: 0 auto -0.9rem;
}
.rsv-hero-in h1 { font-size: clamp(1.6rem, 3.4vw, 2.1rem); letter-spacing: -0.02em; }
.rsv-global { color: rgba(255, 255, 255, 0.85); font-size: var(--t-lg); margin-top: 0.7rem; max-width: 46ch; margin-inline: auto; }
.esp-fijo {
  display: inline-flex; align-items: center; gap: 0.5rem;
  margin-top: 0.9rem; padding: 0.45em 1em;
  background: rgba(255, 255, 255, 0.14); color: #fff;
  border-radius: var(--r-full); font-size: var(--t-base);
}
.esp-fijo img { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; }
.esp-fijo-desc { color: rgba(255, 255, 255, 0.78); font-size: var(--t-sm); margin-top: 0.4rem; }
.rsv-hero-onda {
  position: absolute; bottom: -1px; left: 0; right: 0; height: 46px; z-index: 2;
  background: var(--bg);
  clip-path: ellipse(75% 100% at 50% 100%);
}

/* Aviso del enlace del agente. Usa los tokens de estado que ya existen en
   vez de un color propio: --warn-ink sobre --warn-tint da 11.46 de contraste
   (AA pide 4.5) y el tinte se despega 1.38 del blanco, suficiente para leerse
   como banda por si solo. */
.aviso-enlace {
  margin: 0 0 1rem;
  padding: 0.7rem 0.9rem;
  border-radius: var(--r-md, 10px);
  background: var(--warn-tint);
  color: var(--warn-ink);
  font-size: var(--t-sm);
  line-height: 1.45;
}

.rsv-contenido { max-width: 760px; margin: 0 auto; padding: clamp(1.6rem, 4vw, 2.4rem) clamp(1rem, 3vw, 2rem) 0; }

/* ── Indicador de pasos ──
   Antes eran etiquetas sueltas donde un paso bloqueado se veía idéntico a
   uno disponible. Ahora cada estado tiene su propia forma: hecho lleva
   ✓ y es clickeable para volver, el actual va relleno, y el pendiente se
   ve claramente apagado y sin borde sólido. */
.pasos-nav { margin-bottom: 1.6rem; }

/* En móvil el rastro completo no cabe, así que esta línea es la que
   informa. En escritorio acompaña al rastro. */
.pasos-cuenta {
  display: flex; align-items: center; justify-content: center; gap: 0.15rem;
  text-align: center; font-size: var(--t-sm); color: var(--ink-soft);
  margin-bottom: 0.7rem;
}
/* Se dibuja SIEMPRE como botón, no solo al pasar el mouse. Antes tenía fondo
   transparente y solo se pintaba en `:hover` — un gesto que en un teléfono no
   existe, así que en móvil quedaba una flecha suelta que nadie leía como algo
   tocable. Lleva la palabra «Atrás» por lo mismo: un ícono solo obliga a
   adivinar, y acá el costo de adivinar mal es perder lo que ya se escribió. */
.paso-atras {
  display: inline-flex; align-items: center; gap: 0.25rem;
  height: 1.9rem; padding: 0 0.6rem 0 0.45rem; margin-right: 0.5rem;
  border: 1px solid var(--line); border-radius: var(--r-full);
  color: var(--brand); background: var(--surface);
  font-size: var(--t-micro); font-weight: 600; line-height: 1;
  /* 44px de alto real para el dedo, sin agrandar la píldora: el área táctil
     se extiende por fuera del borde visible. */
  position: relative;
  transition: background 0.2s var(--ease), transform 0.2s var(--ease),
              border-color 0.2s var(--ease);
}
.paso-atras::after {
  content: ''; position: absolute; inset: -0.5rem -0.25rem;
}
.paso-atras:hover { background: var(--brand-tint); border-color: var(--brand); transform: translateX(-2px); }
.paso-atras:active { transform: translateX(-2px) scale(0.97); }
.paso-atras :deep(svg) { flex: none; }
.pasos-cuenta strong { color: var(--ink); font-weight: 600; }

.pasos {
  list-style: none; margin: 0; padding: 0;
  display: flex; align-items: flex-start; justify-content: center;
}
.paso-item { display: flex; align-items: center; min-width: 0; }

/* El conector: base gris fija + una capa de relleno que crece desde la
   izquierda cuando el tramo queda atrás. Antes el color cambiaba de golpe
   (era un pseudo-elemento sin transición); ahora se ve avanzar. */
.paso-conector {
  flex: none; position: relative; overflow: hidden;
  width: clamp(12px, 3vw, 34px); height: 2px; margin: 0 0.15rem;
  background: var(--line); border-radius: 2px;
  transform: translateY(-0.55rem);
}
.paso-conector-fill {
  position: absolute; inset: 0; background: var(--brand-tint);
  border-radius: 2px; transform-origin: left; transform: scaleX(0);
  transition: transform 0.45s var(--ease);
}
.paso-conector-fill.lleno { transform: scaleX(1); }

.paso-btn {
  display: flex; flex-direction: column; align-items: center; gap: 0.3rem;
  padding: 0.2rem 0.3rem; background: none; max-width: 11ch;
  transition: opacity 0.2s var(--ease);
}
.paso-btn:disabled { cursor: default; }
.paso-btn:not(:disabled) { cursor: pointer; }
.paso-btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: var(--r-sm); }

.paso-n {
  flex: none; width: 30px; height: 30px; border-radius: 50%;
  display: grid; place-items: center; position: relative;
  font-size: 0.85rem; font-weight: 700;
  border: 2px solid var(--line); background: var(--surface); color: var(--ink-soft);
  transition: background 0.25s var(--ease), border-color 0.25s var(--ease), color 0.25s var(--ease), transform 0.25s var(--ease);
}
.paso-label {
  font-size: var(--t-micro); font-weight: 600; line-height: 1.25; text-align: center;
  color: var(--ink-soft); transition: color 0.2s var(--ease), opacity 0.2s var(--ease);
}

/* Completado: se puede volver, así que se ve firme y responde al puntero. */
.paso-item.hecho .paso-n { background: var(--brand-tint); border-color: var(--brand-tint); color: var(--brand-ink); }
.paso-item.hecho .paso-label { color: var(--ink); }
.paso-item.hecho .paso-btn:not(:disabled):hover .paso-n { border-color: var(--brand-ink); }

/* Actual: el único relleno, para que el ojo caiga aquí primero. El "pop" al
   entrar (`paso-pop`) es lo que hace notar la LLEGADA a este paso — antes el
   único cambio era de color, y con la transición entre pantallas de por
   medio pasaba fácil desapercibido. Una sola vez, no en loop: es un aviso
   de "llegaste", no una animación de fondo permanente que distraiga. */
.paso-item.actual .paso-n { background: var(--accent); border-color: var(--accent); color: #fff; animation: paso-pop 0.4s var(--ease) both; }
.paso-item.actual .paso-label { color: var(--ink); }

/* Pendiente: apagado y con borde punteado. Dice "todavía no", no "roto". */
.paso-item.pendiente .paso-n { border-style: dashed; opacity: 0.65; }
.paso-item.pendiente .paso-label { opacity: 0.6; }

@keyframes paso-pop {
  0% { transform: scale(0.7); box-shadow: 0 0 0 0 var(--accent-tint); }
  55% { transform: scale(1.18); box-shadow: 0 0 0 6px var(--accent-tint); }
  100% { transform: scale(1); box-shadow: 0 0 0 0 transparent; }
}

/* Número ↔ check: sin esto el ✓ aparecía de golpe al completar un paso. */
.paso-check-enter-active, .paso-check-leave-active {
  transition: transform 0.22s var(--ease), opacity 0.22s var(--ease);
}
.paso-check-enter-from { transform: scale(0.3) rotate(-25deg); opacity: 0; }
.paso-check-leave-to { transform: scale(0.3); opacity: 0; }
.paso-check { color: var(--brand-ink); font-weight: 700; }

@media (prefers-reduced-motion: reduce) {
  .paso-item.actual .paso-n { animation: none; }
  .paso-conector-fill, .paso-n { transition-duration: 0.01ms; }
  .paso-check-enter-active, .paso-check-leave-active { transition-duration: 0.01ms; }
}

@media (max-width: 560px) {
  /* Cinco etiquetas no caben: quedan los números como barra de progreso
     y el nombre del paso lo da la línea de arriba. */
  .paso-label { display: none; }
  .paso-conector { transform: none; width: clamp(14px, 5vw, 30px); }
  .paso-btn { max-width: none; }
}

@media (prefers-reduced-motion: reduce) {
  .paso-btn, .paso-n { transition: none; }
}

.panel {
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--r-lg); padding: clamp(1.3rem, 4vw, 2rem);
  box-shadow: var(--shadow);
}
.panel h2 { font-size: var(--t-xl); margin-bottom: 1.2rem; text-align: center; }
.volver { color: var(--ink-soft); font-weight: 500; font-size: var(--t-base); margin-bottom: 0.6rem; }
.volver:hover { color: var(--accent-strong); }
.center { text-align: center; }
/* labelFechaLarga() ya entrega la inicial en mayúscula. */
.cap { text-transform: none; }

.svc-list { display: flex; flex-direction: column; gap: 0.6rem; }
.svc-row {
  display: flex; align-items: center; gap: 1rem; text-align: left;
  padding: 0.9rem 1.1rem;
  border: 1.5px solid var(--line); border-radius: var(--r-md);
  background: var(--surface);
  transition: border-color 0.2s, transform 0.25s cubic-bezier(0.22,1,0.36,1), box-shadow 0.25s;
}
.svc-row:hover { border-color: var(--accent); background: var(--accent-tint); transform: translateY(-2px); box-shadow: var(--shadow); }

/* ── Entrada en cascada del catálogo ──
   Las tarjetas suben una tras otra y una luz las recorre al aparecer, para
   que la lista se lea como algo que se despliega y no como un bloque que
   aterriza de golpe.

   El estado en reposo es visible: los fotogramas parten en opacity 0 pero
   la regla base no la toca. Si la animación no corre (pestaña oculta, motor
   sin soporte, movimiento reducido), las tarjetas siguen ahí. */
.onda .svc-row {
  position: relative;
  overflow: hidden;
  animation: svc-entra 0.72s cubic-bezier(0.22, 1, 0.36, 1) backwards;
  /* Se topa a 8 para que con muchos servicios el último no quede esperando. */
  animation-delay: calc(min(var(--i, 0), 8) * 95ms);
}
@keyframes svc-entra {
  from { opacity: 0; transform: translateY(16px); }
  to   { opacity: 1; transform: none; }
}

/* La luz: una banda diagonal que cruza una sola vez, justo detrás de la
   entrada de cada fila. */
.onda .svc-row::after {
  content: '';
  position: absolute; inset: 0;
  pointer-events: none;
  background: linear-gradient(105deg,
    transparent 35%,
    color-mix(in oklch, var(--accent) 22%, transparent) 50%,
    transparent 65%);
  transform: translateX(-120%);
  animation: svc-brillo 1.5s cubic-bezier(0.22, 1, 0.36, 1) backwards;
  animation-delay: calc(min(var(--i, 0), 8) * 95ms + 160ms);
}
@keyframes svc-brillo {
  from { transform: translateX(-120%); }
  to   { transform: translateX(120%); }
}

@media (prefers-reduced-motion: reduce) {
  /* Sin desplazamiento ni destello: solo aparecen. */
  .onda .svc-row { animation: svc-aparece 0.3s linear backwards; }
  .onda .svc-row::after { animation: none; content: none; }
  @keyframes svc-aparece { from { opacity: 0; } to { opacity: 1; } }
}
.svc-emoji {
  width: 46px; height: 46px; flex: none;
  display: grid; place-items: center;
  background: var(--brand-tint); border-radius: var(--r-md); font-size: 1.35rem;
}
.svc-foto { width: 46px; height: 46px; border-radius: 50%; object-fit: cover; flex: none; }
/* La foto de un SERVICIO va cuadrada con esquinas romas, no redonda: el círculo
   es la forma de las personas en este portal (`.svc-foto`, los retratos del
   equipo) y usarlo para una consulta borraría esa distinción. Mismo tamaño que
   `.svc-emoji`, así la lista no cambia de ritmo según qué tarjeta tenga foto. */
.svc-img {
  width: 46px; height: 46px; flex: none;
  border-radius: var(--r-md); object-fit: cover;
  background: var(--brand-tint);
}
/* Los especialistas son personas: si falta la foto, el ícono de respaldo
   va redondo (como un avatar), no con las esquinas romas de Consultas. */
.svc-list--esp .svc-emoji { border-radius: 50%; }
/* `min-width: 0` porque un hijo flex no baja de su contenido por defecto
   (`min-width: auto`): es lo que deja que la columna se encoja de verdad. */
.svc-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 0.25rem; }
.svc-info strong { color: var(--brand-ink); }
.svc-sub { font-size: var(--t-sm); color: var(--ink-soft); }
.svc-desc {
  font-size: var(--t-sm); color: var(--ink-soft);
  white-space: pre-line; line-height: 1.45; margin-top: 0.15rem;
}
/* `wrap` no es opcional: son etiquetas de largo variable que vienen del
   catálogo. Sin esto, dos como «Consulta Dermatológica» y «Control
   Dermatológico» desbordan la fila bajo ~420px — y el `overflow: hidden`
   que la animación de entrada necesita para recortar su destello convierte
   el desborde en un CORTE SILENCIOSO: la segunda etiqueta se ve cortada
   contra el borde, sin scroll horizontal que lo delate. */
.svc-tags { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.svc-go { color: var(--brand-ink); font-weight: 700; }
.svc-badge-whatsapp {
  /* Vive dentro de svc-info (columna con `flex:1`): `align-self: flex-start`
     evita que el `stretch` por defecto lo estire al 100% del ancho de la
     columna. Sin `white-space: nowrap` ni `flex: none` — acá SÍ puede partir
     en dos líneas si el nombre encima ya ocupa todo el ancho disponible, en
     vez de desbordar el borde de la tarjeta. */
  align-self: flex-start; margin-top: 0.15rem;
  font-size: var(--t-micro); font-weight: 700; color: var(--brand-strong);
  background: rgba(var(--wa-rgb), 0.12);
  border: 1.5px solid var(--wa); border-radius: var(--r-sm);
  padding: 0.35em 0.7em;
}

/* ── Paso 3, caso especialista sin horas fijas: CTA a WhatsApp ── */
.whatsapp-cta { text-align: center; padding: 2rem 1rem; }
.whatsapp-cta-ic { display: block; margin-bottom: 0.6rem; color: var(--wa, #25d366); }
.whatsapp-cta h2 { margin-bottom: 0.6rem; }
.whatsapp-cta p { color: var(--ink-soft); max-width: 46ch; margin-inline: auto; line-height: 1.55; }
.btn-whatsapp {
  display: inline-flex; align-items: center; gap: 0.5rem;
  margin-top: 1.3rem; padding: 0.9em 1.8em;
  background: var(--wa); color: var(--brand-strong); font-weight: 700;
  border-radius: var(--r-md); transition: background 0.15s, transform 0.15s;
}
.btn-whatsapp:hover { background: var(--wa-hover); transform: translateY(-1px); }

/* ── Navegación de semanas + calendario ── */
.sem-nav {
  display: flex; align-items: center; justify-content: space-between; gap: 0.6rem;
  margin-bottom: 1.3rem;
}
.sem-flecha {
  width: 42px; height: 42px; border-radius: var(--r-sm); flex: none;
  border: 1.5px solid var(--line-strong); background: var(--surface);
  color: var(--ink-soft); font-size: 1.5rem; font-weight: 700;
  display: grid; place-items: center; transition: border-color 0.15s, color 0.15s, background 0.15s;
}
.sem-flecha:hover:not(:disabled) { border-color: var(--accent); color: var(--accent-strong); background: var(--accent-tint); }
.sem-flecha:disabled { opacity: 0.3; cursor: not-allowed; }

.sem-cal-wrap { position: relative; flex: 1; display: flex; justify-content: center; }
.sem-cal-btn {
  display: inline-flex; align-items: center; gap: 0.55rem;
  padding: 0.6em 1.1em; border-radius: var(--r-md);
  border: 1.5px solid var(--line-strong); background: var(--surface);
  font-weight: 600; font-size: var(--t-base); color: var(--ink);
  transition: border-color 0.15s;
}
.sem-cal-btn:hover { border-color: var(--brand); }
.cal-chev { font-size: var(--t-micro); color: var(--ink-soft); transition: transform 0.2s; }
.cal-chev.abierto { transform: rotate(180deg); }

/* Popover del calendario */
.cal-pop {
  position: absolute; top: calc(100% + 8px); left: 50%; transform: translateX(-50%);
  z-index: 40; width: 300px;
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--r-lg); box-shadow: var(--shadow-lg); padding: 1rem;
}
.cal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.7rem; }
.cal-titulo { font-weight: 700; color: var(--brand); text-transform: capitalize; font-size: var(--t-base); }
.cal-nav {
  width: 30px; height: 30px; border-radius: var(--r-sm);
  display: grid; place-items: center; color: var(--ink-soft); font-size: 1.2rem; font-weight: 700;
}
.cal-nav:hover { background: var(--brand-tint); color: var(--brand); }
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 0.15rem; }
.cal-dow {
  text-align: center; font-size: var(--t-micro); font-weight: 700; color: var(--ink-soft);
  text-transform: uppercase; padding-bottom: 0.3rem;
}
.cal-dia {
  aspect-ratio: 1; border-radius: var(--r-sm);
  font-size: var(--t-sm); font-weight: 500; color: var(--ink);
  transition: background 0.12s, color 0.12s;
}
.cal-dia:hover:not(:disabled) { background: var(--accent); color: #fff; }
.cal-dia.otro-mes { color: var(--line-strong); }
.cal-dia.hoy { border: 1.5px solid var(--accent); font-weight: 700; }
.cal-dia:disabled { color: var(--line-strong); cursor: not-allowed; opacity: 0.5; }

/* ── Columnas por día ──
   En escritorio los 7 días entran sin scroll horizontal. Cada columna tiene
   altura fija con scroll vertical propio, para que el panel no se estire. */
.cols-scroll { margin: 0 -0.1rem; }
/* Flex y no una grilla de 7: solo se muestran los días con horas, y quien
   atiende un par de días a la semana quedaría con dos columnas flacas
   pegadas a la izquierda. Así se reparten el ancho, con tope para que dos
   columnas no se estiren a media pantalla, y centradas. */
.cols-grid {
  display: flex; flex-wrap: wrap; justify-content: center;
  gap: 0.4rem; padding: 0 0.1rem;
}
.col-dia {
  display: flex; flex-direction: column;
  flex: 1 1 0; min-width: 92px; max-width: 168px;
  border: 1.5px solid transparent; border-radius: var(--r-md);
}
.col-dia.hoy { border-color: var(--accent); background: var(--accent-tint); }

.col-cab {
  position: relative; text-align: center; padding: 0.6rem 0.1rem 0.55rem;
  border-bottom: 2px solid var(--line); margin: 0 0.35rem 0.55rem;
}
.col-dia.hoy .col-cab { border-bottom-color: var(--accent); }
.col-hoy-badge {
  position: absolute; top: -8px; left: 50%; transform: translateX(-50%);
  background: var(--accent); color: #fff; font-size: var(--t-micro); font-weight: 700;
  padding: 0.12em 0.55em; border-radius: var(--r-full); letter-spacing: 0.03em;
}
.col-nombre { display: block; font-weight: 700; font-size: var(--t-micro); color: var(--brand-ink); text-transform: capitalize; }
.col-fecha { display: block; font-size: var(--t-micro); color: var(--ink-soft); }
.col-dia.hoy .col-fecha { color: var(--accent-strong); font-weight: 600; }

/* Horas: todas visibles, sin scroll interno */
.col-horas {
  display: flex; flex-direction: column; gap: 0.35rem;
  padding: 0 0.35rem 0.5rem;
}

.hora-chip {
  padding: 0.5em 0.2em; border-radius: var(--r-md);
  border: 1.5px solid var(--line-strong); background: var(--surface);
  font-weight: 600; font-size: var(--t-sm); white-space: nowrap; flex: none;
  transition: background 0.15s, border-color 0.15s, color 0.15s;
}
.hora-chip:hover { background: var(--accent); border-color: var(--accent); color: #fff; }

/* ── Horarios en móvil: tira de días + horas del día elegido ──
   Solo la tira se desplaza en horizontal (piezas chicas y de tamaño
   parejo); las horas, que son lo que hay que tocar, usan el ancho
   completo y nunca quedan fuera de pantalla. */
.sr-only {
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
}

/* Sangra hasta el borde de la tarjeta para recuperar el padding del panel:
   con ese ancho extra los 7 días entran sin desplazar. */
.dias-tira {
  display: flex; gap: 0.3rem;
  margin-inline: calc(-1 * clamp(1.3rem, 4vw, 2rem));
  padding: 0.15rem 0.3rem 0.5rem;
  overflow-x: auto; scroll-snap-type: x proximity;
  -webkit-overflow-scrolling: touch;
}
.dia-pill {
  /* Ancla al texto solo-lectores: sin esto se posiciona contra un ancestro
     lejano, se sale de la tira y estira el ancho de la página. */
  position: relative;
  /* Reparten el ancho disponible; el mínimo mantiene el área táctil. */
  flex: 1 1 0; scroll-snap-align: start;
  display: flex; flex-direction: column; align-items: center; gap: 0.1rem;
  min-width: 44px; padding: 0.5rem 0.25rem;
  border: 1.5px solid var(--line-strong); border-radius: var(--r-md);
  background: var(--surface); color: var(--brand-ink);
  transition: background 0.15s, border-color 0.15s, color 0.15s;
}
.pill-dia { font-size: var(--t-micro); text-transform: capitalize; color: var(--ink-soft); }
.pill-num { font-size: var(--t-lg); font-weight: 700; line-height: 1.1; }
.pill-estado { font-size: var(--t-micro); font-weight: 600; color: var(--accent-strong); }

.dia-pill.hoy { border-color: var(--accent); }
.dia-pill.activo {
  background: var(--accent); border-color: var(--accent); color: #fff;
}
.dia-pill.activo .pill-dia,
.dia-pill.activo .pill-estado { color: rgba(255, 255, 255, 0.92); }

.dia-titulo {
  display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;
  font-weight: 700; color: var(--brand-ink);
  margin: 0.5rem 0 0.75rem;
}
/* Solo el nombre del día: capitalizar todo convertiría "de" en "De". */
.dia-nombre { text-transform: capitalize; }
.dia-hoy-badge {
  background: var(--accent); color: #fff;
  font-size: var(--t-micro); font-weight: 700; letter-spacing: 0.03em;
  padding: 0.15em 0.6em; border-radius: var(--r-full);
  text-transform: none;
}

.franja + .franja { margin-top: 1rem; }
.franja-tit {
  font-size: var(--t-micro); font-weight: 600; color: var(--ink-soft);
  margin-bottom: 0.45rem;
}
.horas-grid {
  display: grid; gap: 0.5rem;
  grid-template-columns: repeat(auto-fill, minmax(88px, 1fr));
}
/* Objetivo táctil cómodo: las horas son el único control que se toca aquí. */
.horas-grid .hora-chip { padding: 0.8em 0.3em; font-size: var(--t-base); }


@media (prefers-reduced-motion: reduce) {
  .dia-pill { transition: none; }
  .dias-tira { scroll-behavior: auto; }
}

.sem-vacia {
  text-align: center; color: var(--ink-soft); font-size: var(--t-base);
  margin-top: 1rem; padding: 1rem;
  background: var(--bg); border-radius: var(--r-md);
}

.cuenta-aviso {
  text-align: center; font-size: var(--t-base); color: var(--ink-soft);
  max-width: 560px; margin: -0.4rem auto 1.2rem; line-height: 1.6;
}
.link-btn { color: var(--accent-strong); font-weight: 600; text-decoration: underline; }
.link-btn:hover { color: var(--accent-strong); }

.abono-intro { text-align: center; color: var(--ink-soft); max-width: 46ch; margin: 0 auto 1.3rem; }
.abono-nota { text-align: center; font-size: var(--t-micro); color: var(--ink-soft); margin-top: 0.8rem; }
/* Letra chica legal (exigida por Google al esconder su badge flotante,
   ver .grecaptcha-badge en app.css), no parte de la conversación con
   quien reserva. */
.recaptcha-aviso { text-align: center; font-size: var(--t-micro); color: var(--ink-soft); margin-top: 0.8rem; }
/* Política completa (interconsulta): varios párrafos, así que se deja
   alineada a la izquierda en vez del centrado de la versión corta — un
   bloque de este largo centrado es más difícil de leer. */
.abono-intro-completa {
  text-align: left; max-width: 52ch; margin: 0 auto 1.3rem;
  display: flex; flex-direction: column; gap: 0.6rem;
  color: var(--ink-soft); line-height: 1.55; font-size: var(--t-base);
}

.politica-paso4 { margin: 0; max-width: none; font-size: var(--t-sm); }
.enlace-politica { color: var(--accent-strong); text-decoration: underline; text-underline-offset: 2px; }
.politica-modal { max-width: 480px; }
.politica-modal-cuerpo {
  display: flex; flex-direction: column; gap: 0.6rem; margin: 0.8rem 0 1.4rem;
  font-size: var(--t-sm); color: var(--ink-soft); line-height: 1.55;
}
.politica-modal-acciones { display: flex; justify-content: flex-end; gap: 0.6rem; flex-wrap: wrap; }

/* Tarjeta de resumen de la reserva (especialista + servicio + fecha/hora),
   mismo tratamiento visual que el bloque "esp-fijo" del hero, para que se
   sienta parte del mismo flujo. */
.cita-card {
  max-width: 480px; margin: 0 auto 1.2rem;
  border: 1px solid var(--line); border-radius: var(--r-md);
  overflow: hidden; background: var(--surface);
}
.cita-card-esp {
  display: flex; align-items: center; gap: 0.8rem;
  padding: 1rem 1.2rem;
  background: var(--brand-tint);
}
.cita-card-foto { width: 46px; height: 46px; border-radius: 50%; object-fit: cover; flex: none; }
.cita-card-emoji {
  width: 46px; height: 46px; flex: none;
  display: grid; place-items: center; font-size: 1.4rem;
  background: var(--surface); border-radius: 50%;
}
.cita-card-esp-info { display: flex; flex-direction: column; gap: 0.1rem; }
.cita-card-esp-info strong { color: var(--brand-ink); font-size: var(--t-lg); }
.cita-card-esp-info span { font-size: var(--t-sm); color: var(--ink-soft); }
.cita-card-detalle { display: flex; flex-direction: column; gap: 0.6rem; padding: 1rem 1.2rem; }
.cita-card-item { display: flex; align-items: center; gap: 0.6rem; font-size: var(--t-base); }
/* Grid y no `text-align`: adentro va un <svg>, no un glifo. El ancho fijo
   mantiene alineada la columna de texto de todas las filas. */
.cita-card-ic { flex: none; width: 1.4em; display: grid; place-items: center; color: var(--ink-soft); }
/* Un ícono que acompaña a un texto corrido: se sienta en la línea base en vez
   de empujarla. Se usa donde el dibujo va DENTRO del párrafo, no como columna. */
.ic-inline { vertical-align: -0.18em; flex: none; }

.cuenta-overlay {
  position: fixed; inset: 0; z-index: 100;
  background: rgba(20, 26, 50, 0.5);
  display: grid; place-items: center; padding: 1rem;
  overscroll-behavior: contain;
}
/* El alto tiene tope y el scroll va DENTRO de la tarjeta, no en el fondo.
   Sin esto, «Crear cuenta» —que suma nombre, teléfono, correo, contraseña y
   tres casillas— no cabía en una pantalla de teléfono, y como el overlay
   centra su contenido, lo que sobra se sale por arriba y por abajo a la vez:
   el título quedaba fuera de la pantalla y no había forma de subir hasta él.
   Un `overflow` en el overlay no lo arregla —a lo que se desborda hacia
   arriba no se llega scrolleando—; el tope tiene que estar en la tarjeta.
   `dvh` y no `vh` para descontar la barra del navegador en el teléfono. */
.cuenta-modal {
  width: 100%; max-width: 420px;
  max-height: calc(100vh - 2rem);
  max-height: calc(100dvh - 2rem);
  overflow-y: auto;
  background: var(--surface); border-radius: var(--r-lg);
  box-shadow: var(--shadow-lg); padding: 2rem;
}
.cuenta-modal h3 { font-size: var(--t-xl); margin-bottom: 0.4rem; }
.cuenta-modal-sub { font-size: var(--t-sm); color: var(--ink-soft); margin-bottom: 1.2rem; }
.cuenta-google { margin-bottom: 1rem; }
.cuenta-form { display: flex; flex-direction: column; gap: 1rem; }
.cuenta-form .btn { padding: 0.8em; }
.field-checks { gap: 0.6rem; }
.check { display: flex; align-items: flex-start; gap: 0.55rem; font-size: var(--t-sm); color: var(--ink-soft); cursor: pointer; }
.check input { margin-top: 0.2rem; flex: none; }
.check a { color: var(--brand-ink); font-weight: 600; }
.check a:hover { text-decoration: underline; }
.tabs {
  display: grid; grid-template-columns: 1fr 1fr;
  background: var(--bg); border-radius: var(--r-full);
  padding: 4px; margin-bottom: 1.2rem;
}
.tabs button {
  padding: 0.55em; border-radius: var(--r-full);
  font-weight: 600; font-size: var(--t-base); color: var(--ink-soft);
  transition: background 0.2s var(--ease), color 0.2s var(--ease);
}
.tabs button.active { background: var(--surface); color: var(--brand-ink); box-shadow: var(--shadow); }

.datos-form { display: flex; flex-direction: column; gap: 1.3rem; max-width: 560px; margin: 0 auto; }
.row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
@media (max-width: 480px) { .row-2 { grid-template-columns: 1fr; } }
.hint { font-size: var(--t-micro); color: var(--ink-soft); }

/* Selección de mascota (usuario con sesión): mismo tratamiento visual que
   las filas de servicio/especialista, para que se sienta parte del mismo
   flujo y no un widget aparte. */
.mascota-list { display: flex; flex-wrap: wrap; gap: 0.7rem; }
.mascota-opcion {
  display: flex; flex-direction: column; align-items: center; gap: 0.4rem;
  padding: 1rem 1.2rem; min-width: 92px;
  border: 1.5px solid var(--line-strong); border-radius: var(--r-md);
  background: var(--surface);
  transition: border-color 0.2s var(--ease), background 0.2s var(--ease), transform 0.2s var(--ease);
}
.mascota-opcion:hover { border-color: var(--accent); transform: translateY(-2px); }
.mascota-opcion.active { border-color: var(--accent); background: var(--accent-tint); }
.mascota-emoji { font-size: var(--t-3xl); }
.mascota-foto { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
.mascota-nombre { font-weight: 600; font-size: var(--t-sm); color: var(--ink); }
.mascota-agregar { border-style: dashed; color: var(--ink-soft); }
.mascota-agregar .mascota-emoji { color: var(--brand); font-weight: 700; }

.nueva-mascota {
  display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: center;
  margin-top: 0.9rem; padding: 0.9rem; background: var(--bg); border-radius: var(--r-md);
}
.nueva-mascota .input { flex: 1; min-width: 160px; }
.nueva-mascota .select { width: auto; }

.check { display: flex; align-items: center; gap: 0.6rem; font-size: var(--t-base); cursor: pointer; }
.check-block {
  align-items: flex-start;
  border: 1.5px solid var(--line-strong); border-radius: var(--r-md);
  padding: 0.9rem 1rem;
}
.check-block input[type='checkbox'] { margin-top: 0.2rem; flex: none; }
.check-texto { font-size: var(--t-base); color: var(--ink-soft); line-height: 1.5; }

.info-list { margin: 0.4rem 0; padding-left: 1.3rem; display: flex; flex-direction: column; gap: 0.3rem; }
.info-nota { margin-top: 0.5rem; font-size: var(--t-sm); }

.info-box {
  padding: 0.9rem 1rem;
  background: var(--bg); border: 1px solid var(--line);
  border-radius: var(--r-md); font-size: var(--t-base); color: var(--ink-soft); line-height: 1.5;
}

/* Ya no es una opción elegible sino un aviso: hay una sola forma de pago
   (tarjeta por Getnet) y se cobra en el último paso. Se conserva la caja
   porque ocupa el mismo lugar del formulario y se lee como parte de él. */
.pago-opcion {
  display: flex; align-items: flex-start; gap: 0.7rem;
  border: 1.5px solid var(--line-strong); border-radius: var(--r-md);
  padding: 0.9rem 1rem;
}
.pago-opcion-info { border-color: var(--brand); background: var(--brand-tint); }
.pago-texto { display: flex; flex-direction: column; gap: 0.3rem; }

/* Elegir abono o total, en el último paso. Clases propias (`modo-pago`) y no
   `pago-opcion`: esa de arriba es la caja informativa del paso 5, que no es
   clicable — compartir nombre le habría filtrado el hover y el estado activo.
   El radio nativo se deja visible en vez de dibujar uno propio: es el control
   que la gente reconoce como "elige uno", y ocultarlo obliga a reconstruir a
   mano el foco de teclado. */
.modo-pago-set { border: 0; padding: 0; margin: 0 auto 1.1rem; max-width: 480px; }
.modo-pago-set legend {
  font-size: var(--t-base); font-weight: 600; color: var(--ink);
  padding: 0; margin-bottom: 0.6rem;
}
.modo-pago {
  display: flex; align-items: center; gap: 0.7rem;
  padding: 0.85rem 1rem; margin-bottom: 0.5rem;
  border: 1.5px solid var(--line-strong); border-radius: var(--r-md);
  cursor: pointer; background: var(--surface);
  transition: border-color 0.15s, background 0.15s;
}
.modo-pago:hover, .modo-pago.activa { border-color: var(--accent); background: var(--accent-tint); }
.modo-pago input { accent-color: var(--accent); flex: none; margin: 0; }
.modo-pago-texto { display: flex; flex-direction: column; gap: 0.15rem; flex: 1; min-width: 0; }
.modo-pago-texto strong { font-size: var(--t-base); }
.modo-pago-texto small { color: var(--ink-soft); font-size: var(--t-micro); line-height: 1.45; }
.modo-pago-monto { font-weight: 700; font-size: var(--t-lg); white-space: nowrap; }
/* El foco del radio se dibuja en el círculo y se pierde de vista: se sube al
   contenedor, que es lo que el ojo sigue. */
.modo-pago:focus-within { outline: 2px solid var(--accent); outline-offset: 2px; }
@media (prefers-reduced-motion: reduce) { .modo-pago { transition: none; } }

.resumen {
  max-width: 480px; margin: 0 auto 1.3rem;
  border: 1px solid var(--line); border-radius: var(--r-md);
  padding: 0.4rem 1.2rem;
  background: var(--bg);
}
.res-row { display: flex; justify-content: space-between; gap: 1rem; padding: 0.65rem 0; }
.res-row:not(:last-child) { border-bottom: 1px solid var(--line); }
.res-row span { color: var(--ink-soft); font-size: var(--t-base); }
.res-row strong { text-align: right; font-size: var(--t-base); }
.res-row.total strong { color: var(--brand-ink); font-size: var(--t-lg); }
.btn-big { display: flex; width: 100%; max-width: 480px; margin: 0 auto; padding: 0.9em; }

.gracias { text-align: center; }
.check-big {
  width: 76px; height: 76px; margin: 0 auto 1rem;
  display: grid; place-items: center;
  background: var(--ok-tint); color: var(--ok);
  border-radius: 50%; font-size: 2.4rem; font-weight: 700;
}
.gracias-sub { color: var(--ink-soft); max-width: 42ch; margin: 0 auto 1.6rem; }
.gracias-actions { display: flex; gap: 0.8rem; justify-content: center; flex-wrap: wrap; }

/* Transición entre pasos: crossfade + leve desplazamiento, curva de salida
   sin rebote (misma --ease del sitio público). */
.paso-enter-active, .paso-leave-active { transition: opacity 0.28s var(--ease), transform 0.28s var(--ease); }
.paso-enter-from { opacity: 0; transform: translateY(8px); }
.paso-leave-to { opacity: 0; transform: translateY(-8px); }

@media (prefers-reduced-motion: reduce) {
  .paso-enter-active, .paso-leave-active { transition: opacity 0.15s linear; transform: none; }
  .paso-enter-from, .paso-leave-to { transform: none; }
}
/* Peso 500 y no 600. `precio_detalle` no siempre es un precio corto: el de
   Peluquería son tres líneas explicando de qué depende el total, y en
   negrita se leía más fuerte que el nombre del servicio, dejando la
   descripción real como letra chica gris debajo. El acento sigue
   distinguiéndolo; lo que se baja es el grito, no la jerarquía. */
.svc-precio-det {
  display: block; margin-top: 0.25rem;
  font-size: var(--t-micro); color: var(--accent-strong); font-weight: 500;
  line-height: 1.45; text-wrap: pretty;
}
.res-nota { font-size: var(--t-micro); color: var(--ink-soft); margin: -0.2rem 0 0.5rem; line-height: 1.45; }
/* ── Orden de examen obligatoria (paso 4) ─────────────────────────────── */
.orden-campo {
  border: 1px dashed var(--orden-borde);
  border-radius: 12px;
  padding: 1rem 1.1rem;
  margin: 0 0 1.1rem;
  background: var(--surface);
  text-align: left;
}
.orden-campo--ok { border-style: solid; border-color: var(--acento-orden); }
.orden-campo-tit { font-weight: 700; font-size: var(--t-base); margin: 0 0 0.35rem; }
.orden-req {
  font-size: var(--t-micro); font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.03em; color: #b3261e; margin-left: 0.35rem;
}
.orden-campo-sub { font-size: var(--t-sm); line-height: 1.5; color: var(--ink-soft); margin: 0 0 0.7rem; }
.orden-campo-nota { font-size: var(--t-micro); color: var(--ink-soft); margin: 0.45rem 0 0; }
.orden-boton {
  display: inline-block; cursor: pointer;
  padding: 0.55rem 1rem; border-radius: 10px;
  border: 1px solid var(--acento-orden); color: var(--acento-orden);
  font-weight: 600; font-size: var(--t-base);
}
.orden-boton:hover { background: var(--acento-orden-suave); }
.orden-file { position: absolute; width: 1px; height: 1px; opacity: 0; overflow: hidden; }
.orden-ok { color: #1a7f37; font-weight: 700; font-size: var(--t-base); margin: 0 0 0.3rem; }
.orden-ok .ic-inline { color: #1a7f37; }
.orden-archivo { font-size: var(--t-sm); color: var(--ink); word-break: break-all; }
.orden-preview {
  display: flex; align-items: center; gap: 0.75rem; text-decoration: none;
  margin: 0 0 0.75rem; padding: 0.5rem; border-radius: 8px;
  border: 1px solid var(--line); background: var(--surface);
}
.orden-preview:hover { border-color: var(--acento-orden); }
.orden-preview:focus-visible { outline: 2px solid var(--acento-orden); outline-offset: 2px; }
.orden-ok {
  display: flex; align-items: baseline; gap: 0.4rem;
  margin: 0 0 1rem; font-size: var(--t-base); color: var(--ok);
}
.orden-miniatura {
  width: 72px; height: 72px; flex: none; border-radius: 6px;
  object-fit: cover; background: var(--acento-orden-suave);
  display: flex; align-items: center; justify-content: center;
}
.orden-miniatura--pdf {
  font-size: var(--t-micro); font-weight: 700; letter-spacing: 0.06em;
  color: var(--acento-orden);
}
.orden-preview-datos { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
.orden-peso { font-size: var(--t-micro); color: var(--ink-soft); }
.orden-acciones { display: flex; gap: 1.1rem; align-items: center; }
.orden-link {
  background: none; border: 0; padding: 0; cursor: pointer;
  font-size: var(--t-micro); color: var(--ink-soft); text-decoration: underline;
}
.orden-link:hover { color: var(--acento-orden); }
.orden-campo-err { color: #b3261e; font-size: var(--t-sm); margin: 0.6rem 0 0; }
.wa-contacto {
  display: flex; flex-direction: column; gap: .55rem;
  max-width: 320px; margin: 0 auto 1.2rem; text-align: left;
}
.wa-como { font-size: var(--t-base); color: var(--ink-soft); margin: 0 0 1.2rem; }

/* Mensaje según el día. Va como franja al pie de la tarjeta y no como un
   ítem más de la lista: es lo único de esa tarjeta que puede cambiar el
   monto que se cobra, y perdido entre la fecha y la hora se leía como un
   dato más. */
.aviso-dia {
  display: flex; gap: 0.6rem; align-items: flex-start;
  padding: 0.8rem 1.2rem;
  border-top: 1px solid var(--line);
  background: var(--acento-orden-suave);
}
.aviso-dia-ic { flex: none; display: grid; place-items: center; color: var(--acento-orden); }
.aviso-dia-cuerpo { display: flex; flex-direction: column; gap: 0.15rem; }
.aviso-dia-tit { margin: 0; font-size: var(--t-base); color: var(--ink); }
.aviso-dia-tit strong { color: var(--acento-orden); }
.aviso-dia-otro,
.aviso-dia-nota { margin: 0; font-size: var(--t-micro); color: var(--ink-soft); line-height: 1.45; }
/* ── Paso de pago (5.5) ──
   Jerarquía, de más a menos peso: el título; el botón de pago; profesional,
   fecha y hora; el monto del abono; y las notas. Antes todos los bloques
   pesaban lo mismo —cuatro recuadros apilados— y el botón competía con ellos.

   Una sola tarjeta, la del resumen de la reserva. Todo lo demás se separa con
   aire: 2rem entre grupos, 0.85rem dentro. El plazo lleva fondo porque es lo
   único con urgencia; el acuse de la orden y la nota de Getnet son filas con
   ícono, sin caja.

   `.pago .pago-grupo > *` pesa (0,3,0) a propósito: tiene que ganarles a los
   márgenes propios de `.btn-big`, `.error-text` y a la raíz de
   `SubirOrdenExamen`, que traen `margin: … auto …` de cuando cada uno se
   centraba por su cuenta. `width: 100%` va junto porque `margin-inline: auto`
   en un hijo de flex anula el estiramiento. */
.pago h2 {
  font-size: var(--t-3xl); font-weight: 700; color: var(--brand-ink);
  margin-bottom: 0.55rem; text-wrap: balance;
}
.pago .abono-intro { text-wrap: pretty; margin-bottom: 2rem; }
.pago-col {
  max-width: 500px; margin: 0 auto;
  display: flex; flex-direction: column; gap: 2rem;
}
.pago-grupo { display: flex; flex-direction: column; gap: 0.85rem; }
/* `max-width: none` también: `.modo-pago-set` y la raíz de SubirOrdenExamen
   traen 480px y quedaban más angostos que la columna, con sangría a los lados. */
.pago .pago-grupo > * { width: 100%; max-width: none; margin: 0 auto; }

/* Resumen: la única tarjeta. Sin sombra ni fondo de color en la cabecera; el
   peso lo pone la tipografía — nombre en grande, fecha y hora en semibold —
   para que profesional, día y hora se lean de un vistazo sin etiquetas. */
.pago-resumen {
  border: 1px solid var(--line); border-radius: var(--r-md);
  background: var(--surface);
  padding: 1.15rem 1.25rem;
}
.pago-esp { display: flex; align-items: center; gap: 0.9rem; }
.pago-esp-foto {
  width: 56px; height: 56px; border-radius: 50%; object-fit: cover; flex: none;
}
.pago-esp-sinfoto {
  display: grid; place-items: center;
  background: var(--brand-tint); color: var(--brand-ink);
}
.pago-esp-info { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; }
.pago-esp-info strong {
  font-size: var(--t-xl); font-weight: 700; color: var(--brand-ink); line-height: 1.25;
}
.pago-esp-info span { font-size: var(--t-sm); color: var(--ink-soft); }

.pago-datos {
  list-style: none; padding: 1rem 0 0; margin: 1rem 0 0;
  border-top: 1px solid var(--line);
  display: flex; flex-direction: column; gap: 0.7rem;
}
.pago-dato {
  display: flex; align-items: center; gap: 0.75rem;
  font-size: var(--t-base); color: var(--ink); line-height: 1.4;
}
.pago-dato--clave { font-size: var(--t-lg); font-weight: 600; }
/* Ícono en un cuadro tintado: marca el tipo de dato sin escribir «Fecha» ni
   «Hora». El trazo va en --accent-strong: --accent sobre este tinte da 4.47. */
.pago-dato-ic {
  flex: none; width: 2rem; height: 2rem; border-radius: var(--r-sm);
  display: grid; place-items: center;
  background: var(--accent-tint); color: var(--accent-strong);
}

/* Retención: fondo tintado porque es lo único con plazo. Tinta de marca, no
   gris, sobre el tinte (sobre #e6f7f9: --ink 10.79). */
.pago-plazo {
  display: flex; align-items: flex-start; gap: 0.75rem;
  padding: 0.85rem 1rem; border-radius: var(--r-md);
  background: var(--accent-tint); color: var(--ink);
  font-size: var(--t-sm); line-height: 1.5; text-align: left;
}
.pago-plazo-ic { flex: none; color: var(--accent-strong); margin-top: 0.05rem; }

/* Precio: texto sin caja. Los montos en negrita; el del abono además con
   marcador, que es el número que el botón va a cobrar. --accent-strong sobre
   --accent-tint: 5.96. */
.pago-precio { display: flex; flex-direction: column; gap: 0.5rem; }
/* Sin la raya de `.res-row:not(:last-child)`: separaba el valor de su propia
   nota, como si fueran dos cosas. */
.pago .pago-valor { padding: 0; align-items: baseline; border-bottom: 0; }
.pago-valor strong { font-size: var(--t-xl); color: var(--brand-ink); }
.pago-nota-precio {
  font-size: var(--t-base); line-height: 1.6; color: var(--ink-soft);
  text-wrap: pretty;
}
.pago-monto { color: var(--ink); font-weight: 700; }
.pago-monto--abono {
  color: var(--accent-strong); background: var(--accent-tint);
  padding: 0.05em 0.35em; border-radius: 6px;
  -webkit-box-decoration-break: clone; box-decoration-break: clone;
}
/* Alineadas a la izquierda como el resto de la columna; centrada, «Queda un
   saldo…» era la única línea con otro eje entre el botón y la nota de Getnet. */
.pago .abono-nota { text-wrap: pretty; line-height: 1.5; text-align: left; }
/* La nota de cuándo se paga explica el monto del botón: parte de la decisión,
   no letra chica, y alineada con el precio que acompaña. */
.pago .abono-nota-total { font-size: var(--t-sm); text-align: left; }
.pago .modo-pago:last-of-type { margin-bottom: 0; }

/* Orden recibida: fila con check en círculo lleno, sin caja. Blanco sobre
   --ok: 6.15; --ok sobre blanco: 6.15. */
.pago-orden-ok {
  display: flex; align-items: center; justify-content: center; gap: 0.65rem;
  margin: 0 auto 2rem;
  font-size: var(--t-base); font-weight: 600; color: var(--ok); line-height: 1.4;
  text-align: left;
}
/* Va pegado al texto de la reserva: le quita a ese párrafo el aire que lo
   separa de la columna y lo pone debajo del acuse. */
.pago .abono-intro + .pago-orden-ok { margin-top: -1.1rem; }
.pago-orden-ok-ic {
  flex: none; width: 1.6rem; height: 1.6rem; border-radius: 50%;
  display: grid; place-items: center;
  background: var(--ok); color: #fff;
}

/* Acción: el botón es lo segundo más pesado de la pantalla, después del
   título. 3.4rem de alto, tamaño de dedo con holgura en el celular. */
.pago-grupo--accion { gap: 0.75rem; }
.pago-boton {
  max-width: none; min-height: 3.4rem; gap: 0.6rem;
  font-size: var(--t-lg); font-weight: 700;
}
.pago-boton:focus-visible { outline: 3px solid var(--accent-strong); outline-offset: 3px; }
.pago-seguro {
  display: flex; align-items: flex-start; gap: 0.5rem;
  font-size: var(--t-micro); line-height: 1.55; color: var(--ink-soft);
  text-align: left; text-wrap: pretty;
}
.pago-seguro-ic { flex: none; color: var(--accent-strong); margin-top: 0.1rem; }

/* Políticas como pie de la columna, separadas por una línea fina. */
.pago-politica { border-top: 1px solid var(--line); padding-top: 1.1rem; }
.pago-politica :deep(.politica) { margin-top: 0; max-width: none; }

/* Aviso de privacidad del paso de datos: informativo, no una alarma. Gris
   suave y tamaño de nota al pie — tiene que poder leerse sin competirle al
   formulario, que es lo que la persona vino a completar. */
.aviso-privacidad {
  margin: 0.85rem 0 0;
  font-size: 0.82rem;
  line-height: 1.55;
  color: var(--ink-soft);
}
.aviso-privacidad a { color: inherit; text-decoration: underline; }
.aviso-privacidad a:hover { color: var(--accent-strong, inherit); }
</style>
