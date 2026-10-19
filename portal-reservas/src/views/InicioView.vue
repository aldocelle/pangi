<script setup>
/* Portada del portal del cliente.
 *
 * QUÉ CAMBIÓ Y POR QUÉ
 *
 * Antes eran seis secciones idénticas —tarjeta blanca, `h2`, enlace «Ver
 * todo»— apiladas una tras otra. Con todo al mismo peso visual nada lidera, y
 * la pregunta que trae a alguien acá («¿cuándo tengo que ir?») quedaba en una
 * columna lateral, debajo de dos secciones sobre mascotas.
 *
 * Ahora la próxima cita es lo primero y ocupa la tarjeta grande, con el mismo
 * lenguaje del paso 4 de la reserva: banda tintada con la foto arriba, filas de
 * ícono abajo. Quien ya conoce ese flujo reconoce la forma.
 *
 * Y se fue «Resumen de salud», que era una segunda pasada por las MISMAS
 * mascotas para mostrar peso y alergias. Ese dato ahora viaja como chip dentro
 * de la tarjeta de cada mascota, donde corresponde: es un atributo del animal,
 * no una sección.
 */
import { onMounted, computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useAuthStore } from '../stores/auth.js';
import { useDatosStore, msParaPagar } from '../stores/datos.js';
import { fechaLarga, horaCorta, edadDesde, fotoMascota } from '../api.js';
import { CLINICA } from '../config.js';
import BaseIcon from '../components/BaseIcon.vue';
import CuentaPago from '../components/CuentaPago.vue';

const auth = useAuthStore();
const datos = useDatosStore();

onMounted(() => datos.cargar());

/* La primera va al lugar destacado; las demás, a la lista lateral. Separarlas
   acá y no en la plantilla evita repetir el `slice` en dos sitios. */
const proxima = computed(() => datos.proximasCitas[0] || null);
const siguientes = computed(() => datos.proximasCitas.slice(1, 3));
const esperaPago = (c) => !!c && msParaPagar(c, datos.ahora) !== null;

const historial = computed(() => datos.citasAnteriores.slice(0, 3));

function mascotaDe(cita) {
  return datos.mascotaPorId(cita.mascota_id) || null;
}
function fotoDe(mascota) {
  return fotoMascota(mascota);
}
function estadoChip(estado) {
  if (estado === 'Confirmada') return 'chip-ok';
  if (estado === 'Cancelada') return 'chip-danger';
  return 'chip-warn';
}
</script>

<template>
  <div class="page">
    <header class="hola">
      <h1>Hola, {{ auth.primerNombre }} <BaseIcon name="pets" :size="22" class="hola-ic" /></h1>
      <p class="muted">Las visitas y la información de tus mascotas, en un solo lugar.</p>
    </header>

    <div class="grid">
      <div class="col-main">
        <!-- ── Tu próxima visita ──────────────────────────────────────────
             El bloque más grande de la página, porque es la pregunta que trae
             a alguien acá. Sigue el patrón del paso 4 de la reserva: banda
             tintada con la foto, y debajo las filas de ícono. -->
        <section class="visita" v-if="proxima">
          <h2 class="titulo-seccion">Tu próxima visita</h2>

          <article class="ficha">
            <header class="ficha-cab">
              <img class="ficha-foto" :src="fotoDe(mascotaDe(proxima))" alt="" />
              <div class="ficha-cab-txt">
                <strong>{{ mascotaDe(proxima)?.nombre || 'Tu mascota' }}</strong>
                <span>{{ proxima.servicio }}</span>
              </div>
              <span class="chip" :class="estadoChip(proxima.estado)">{{ esperaPago(proxima) ? 'Falta el abono' : proxima.estado }}</span>
            </header>

            <div class="ficha-filas">
              <p class="ficha-fila">
                <span class="ficha-ic" aria-hidden="true"><BaseIcon name="calendar" :size="18" /></span>
                <span class="cap">{{ fechaLarga(proxima.fecha_hora) }}</span>
              </p>
              <p class="ficha-fila">
                <span class="ficha-ic" aria-hidden="true"><BaseIcon name="clock" :size="18" /></span>
                <span>
                  {{ horaCorta(proxima.fecha_hora) }}
                  <template v-if="proxima.duracion_min"> · {{ proxima.duracion_min }} minutos</template>
                </span>
              </p>
            </div>

            <CuentaPago v-if="esperaPago(proxima)" :cita="proxima" class="ficha-cuenta" />

            <footer class="ficha-pie">
              <RouterLink to="/citas" class="btn btn-ghost btn-sm">Ver mis citas</RouterLink>
              <RouterLink to="/citas/nueva" class="btn btn-primary btn-sm">
                <BaseIcon name="calendar" :size="16" /> Reservar otra hora
              </RouterLink>
            </footer>
          </article>
        </section>

        <!-- Sin cita próxima, el hueco se usa para invitar a reservar en vez
             de dejar una tarjeta vacía diciendo "no hay nada". -->
        <section class="visita" v-else>
          <h2 class="titulo-seccion">Tu próxima visita</h2>
          <article class="ficha ficha-vacia">
            <span class="vacia-ic" aria-hidden="true"><BaseIcon name="calendar" :size="24" /></span>
            <div>
              <strong>No tienes horas agendadas</strong>
              <p class="muted">Cuando reserves, la verás acá con su fecha y hora.</p>
            </div>
            <RouterLink to="/citas/nueva" class="btn btn-primary btn-sm">Reservar una hora</RouterLink>
          </article>
        </section>

        <!-- ── Mis mascotas ───────────────────────────────────────────────
             Peso y alergia viajan acá como chips. Antes vivían en una sección
             aparte que recorría las mismas mascotas otra vez. -->
        <section class="bloque">
          <div class="bloque-cab">
            <h2 class="titulo-seccion">Mis mascotas</h2>
            <RouterLink to="/mascotas" class="link-accent">Ver todas</RouterLink>
          </div>

          <p v-if="datos.cargando" class="vacio">Cargando…</p>
          <p v-else-if="!datos.mascotas.length" class="vacio">
            Todavía no hay mascotas registradas. El equipo las agrega en tu próxima visita.
          </p>
          <div v-else class="mascotas">
            <RouterLink
              v-for="m in datos.mascotas" :key="m.id"
              :to="`/mascotas/${m.id}`" class="masc"
            >
              <img class="masc-foto" :src="fotoDe(m)" alt="" />
              <div class="masc-txt">
                <strong>{{ m.nombre }}</strong>
                <span class="masc-meta">
                  {{ m.especie }}<template v-if="m.raza"> · {{ m.raza }}</template>
                  <template v-if="m.fecha_nacimiento"> · {{ edadDesde(m.fecha_nacimiento) }}</template>
                </span>
                <span class="masc-chips">
                  <span v-if="m.peso_kg" class="chip chip-brand">{{ m.peso_kg }} kg</span>
                  <span v-if="m.alergias" class="chip chip-warn">Alergia: {{ m.alergias }}</span>
                </span>
              </div>
              <BaseIcon name="chevron" :size="18" class="masc-flecha" />
            </RouterLink>
          </div>
        </section>

        <!-- ── Historial ──────────────────────────────────────────────── -->
        <section class="bloque">
          <div class="bloque-cab">
            <h2 class="titulo-seccion">Atenciones anteriores</h2>
            <RouterLink to="/citas" class="link-accent">Ver todas</RouterLink>
          </div>
          <p v-if="!historial.length" class="vacio">Todavía no hay atenciones registradas.</p>
          <ul v-else class="hist">
            <li v-for="c in historial" :key="c.id" class="hist-item">
              <span class="hist-ic" aria-hidden="true"><BaseIcon name="stethoscope" :size="18" /></span>
              <div class="hist-txt">
                <strong>{{ c.servicio }}</strong>
                <span class="hist-meta">
                  <span class="cap">{{ fechaLarga(c.fecha_hora) }}</span>
                  <template v-if="mascotaDe(c)"> · {{ mascotaDe(c).nombre }}</template>
                </span>
              </div>
            </li>
          </ul>
        </section>
      </div>

      <!-- ── Lateral ──────────────────────────────────────────────────── -->
      <aside class="col-side">
        <section class="bloque" v-if="siguientes.length">
          <h2 class="titulo-seccion">Después de esa</h2>
          <ul class="prox">
            <li v-for="c in siguientes" :key="c.id" class="prox-item">
              <img class="prox-foto" :src="fotoDe(mascotaDe(c))" alt="" />
              <div class="prox-txt">
                <strong>{{ mascotaDe(c)?.nombre || 'Mascota' }}</strong>
                <span class="muted">{{ c.servicio }}</span>
                <span class="prox-fecha cap">{{ fechaLarga(c.fecha_hora) }} · {{ horaCorta(c.fecha_hora) }}</span>
              </div>
            </li>
          </ul>
        </section>

        <!-- En móvil el sidebar es un cajón que hay que abrir, así que estos
             accesos no son un duplicado ocioso: son la única navegación a la
             vista. En escritorio quedan como atajo. -->
        <section class="bloque">
          <h2 class="titulo-seccion">Accesos</h2>
          <div class="accesos">
            <RouterLink to="/citas/nueva" class="acceso">
              <span class="acceso-ic"><BaseIcon name="calendar" :size="20" /></span>Reservar
            </RouterLink>
            <a :href="`https://wa.me/${CLINICA.telefonoPlano}`" target="_blank" rel="noopener" class="acceso">
              <span class="acceso-ic"><BaseIcon name="wa" :size="20" /></span>WhatsApp
            </a>
            <RouterLink to="/mascotas" class="acceso">
              <span class="acceso-ic"><BaseIcon name="pets" :size="20" /></span>Mascotas
            </RouterLink>
            <RouterLink to="/recordatorios" class="acceso">
              <span class="acceso-ic"><BaseIcon name="bell" :size="20" /></span>Recordatorios
            </RouterLink>
          </div>
        </section>

        <section class="bloque">
          <h2 class="titulo-seccion">{{ CLINICA.nombre }}</h2>
          <ul class="contacto">
            <li>
              <span class="contacto-ic"><BaseIcon name="phone" :size="16" /></span>
              <a :href="`tel:+${CLINICA.telefonoPlano}`">{{ CLINICA.telefono }}</a>
            </li>
            <li>
              <span class="contacto-ic"><BaseIcon name="mail" :size="16" /></span>
              <a :href="`mailto:${CLINICA.email}`">{{ CLINICA.email }}</a>
            </li>
            <li>
              <span class="contacto-ic"><BaseIcon name="pin" :size="16" /></span>
              <address>
                <span v-for="l in CLINICA.direccion" :key="l">{{ l }}</span>
              </address>
            </li>
            <li>
              <span class="contacto-ic"><BaseIcon name="clock" :size="16" /></span>
              <dl class="horario">
                <template v-for="h in CLINICA.horarios" :key="h.dias">
                  <dt>{{ h.dias }}</dt><dd><span v-for="t in h.horas" :key="t" class="tramo">{{ t }}</span></dd>
                </template>
              </dl>
            </li>
          </ul>
        </section>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.hola { margin-bottom: 1.8rem; }
/* Escala con contraste real entre pasos: 1.9rem el saludo contra 1.02rem los
   títulos de sección. Antes iban 1.7 y 1.05, casi el mismo tamaño, y por eso
   ninguna sección parecía más importante que otra. */
.hola h1 { font-size: clamp(1.5rem, 4vw, 1.9rem); color: var(--brand); text-wrap: balance; }
.hola .muted { margin-top: 0.25rem; }

.grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 1.5rem; align-items: start; }
.col-main, .col-side { display: flex; flex-direction: column; gap: 1.5rem; min-width: 0; }

.titulo-seccion {
  font-size: var(--t-lg); font-weight: 700; color: var(--brand); letter-spacing: -0.01em;
}
.bloque-cab { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; margin-bottom: 0.9rem; }
/* El mismo aire para las secciones sin cabecera (Después de esa, Accesos, la
   clínica): el margen vivía solo en `.bloque-cab`, y esas tres dejaban el
   contenido pegado al título. */
.bloque > .titulo-seccion { margin-bottom: 0.9rem; }
.bloque {
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--r-lg); padding: 1.2rem;
}
.vacio { color: var(--ink-soft); font-size: var(--t-sm); line-height: 1.5; }

/* ── La ficha de la próxima visita ─────────────────────────────────────
   Mismo lenguaje que `.cita-card` del paso 4 de la reserva: borde fino,
   `overflow: hidden` para que la banda tintada llegue al canto, y filas de
   ícono con ancho fijo para que el texto quede alineado. */
.visita .titulo-seccion { margin-bottom: 0.9rem; }
.ficha {
  border: 1px solid var(--line); border-radius: var(--r-lg);
  overflow: hidden; background: var(--surface);
}
.ficha-cab {
  display: flex; align-items: center; gap: 0.85rem;
  padding: 1rem 1.2rem; background: var(--brand-tint);
}
.ficha-foto {
  width: 52px; height: 52px; border-radius: 50%; object-fit: cover; flex: none;
  background: var(--surface);
}
.ficha-cab-txt { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; flex: 1; }
.ficha-cab-txt strong { color: var(--brand-ink); font-size: var(--t-lg); }
.ficha-cab-txt span { font-size: var(--t-sm); color: var(--ink-soft); }
.ficha-filas { display: flex; flex-direction: column; gap: 0.55rem; padding: 1.1rem 1.2rem; }
.ficha-fila { display: flex; align-items: center; gap: 0.6rem; margin: 0; font-size: var(--t-base); }
/* Centrado con grid: adentro va un <svg>, no un glifo cuyo alto de línea
   hiciera el trabajo. El ancho fijo mantiene alineada la columna de texto. */
.ficha-ic { flex: none; width: 1.4em; display: grid; place-items: center; color: var(--ink-soft); }
/* La pata del saludo acompaña al nombre, así que va con el color del título. */
.hola-ic { vertical-align: -0.15em; color: var(--accent); }
.ficha-cuenta { margin: 0 1.2rem 1rem; }
.ficha-pie {
  display: flex; gap: 0.6rem; justify-content: flex-end; flex-wrap: wrap;
  padding: 0.9rem 1.2rem; border-top: 1px solid var(--line); background: var(--bg);
}

.ficha-vacia {
  display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
  padding: 1.3rem 1.2rem;
}
.ficha-vacia > div { flex: 1; min-width: 12rem; }
.ficha-vacia strong { display: block; color: var(--brand-ink); }
.ficha-vacia .muted { font-size: var(--t-sm); margin-top: 0.15rem; }
.vacia-ic {
  width: 46px; height: 46px; flex: none; display: grid; place-items: center;
  border-radius: 50%; background: var(--brand-tint); color: var(--brand-ink);
}

/* ── Mascotas ──────────────────────────────────────────────────────────
   `auto-fit` en vez de tres columnas fijas: con una sola mascota ya no queda
   una tarjeta angosta y dos huecos, y con cuatro no se aprieta. */
.mascotas { display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 0.8rem; }
.masc {
  display: flex; align-items: center; gap: 0.8rem;
  padding: 0.8rem; border: 1px solid var(--line); border-radius: var(--r-md);
  color: inherit; min-width: 0;
  transition: border-color 0.2s var(--ease), background 0.2s var(--ease);
}
.masc:hover { border-color: var(--accent); background: var(--accent-tint); }
.masc-foto { width: 52px; height: 52px; border-radius: 50%; object-fit: cover; flex: none; background: var(--brand-tint); }
.masc-txt { display: flex; flex-direction: column; gap: 0.2rem; min-width: 0; flex: 1; }
.masc-txt strong { color: var(--brand-ink); font-size: var(--t-base); }
.masc-meta { font-size: var(--t-micro); color: var(--ink-soft); text-transform: capitalize; }
.masc-chips { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.masc-flecha { flex: none; color: var(--ink-soft); }

/* ── Historial ─────────────────────────────────────────────────────── */
.hist { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; }
.hist-item {
  display: flex; align-items: center; gap: 0.75rem;
  padding: 0.7rem 0; border-top: 1px solid var(--line-tabla);
}
.hist-item:first-child { border-top: none; padding-top: 0; }
.hist-ic {
  width: 34px; height: 34px; flex: none; display: grid; place-items: center;
  border-radius: 10px; background: var(--brand-tint); color: var(--brand-ink);
}
.hist-txt { display: flex; flex-direction: column; min-width: 0; }
.hist-txt strong { font-size: var(--t-base); }
.hist-meta { font-size: var(--t-micro); color: var(--ink-soft); }

/* ── Lateral ───────────────────────────────────────────────────────── */
.prox { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; }
.prox-item { display: flex; gap: 0.7rem; padding: 0.75rem 0; border-top: 1px solid var(--line-tabla); }
.prox-item:first-child { border-top: none; padding-top: 0; }
.prox-foto { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; flex: none; background: var(--brand-tint); }
.prox-txt { display: flex; flex-direction: column; gap: 0.05rem; min-width: 0; }
.prox-txt strong { font-size: var(--t-base); }
.prox-txt .muted { font-size: var(--t-micro); }
.prox-fecha { font-size: var(--t-micro); color: var(--ink-soft); }

.accesos { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; }
.acceso {
  display: flex; flex-direction: column; align-items: center; gap: 0.45rem;
  padding: 0.85rem 0.4rem; border: 1px solid var(--line); border-radius: var(--r-md);
  font-size: var(--t-micro); font-weight: 600; text-align: center; color: var(--ink);
  transition: border-color 0.2s var(--ease), background 0.2s var(--ease);
}
.acceso:hover { border-color: var(--accent); background: var(--accent-tint); color: var(--accent-strong); }
.acceso-ic {
  width: 38px; height: 38px; border-radius: 11px; flex: none;
  background: var(--brand-tint); color: var(--brand); display: grid; place-items: center;
}

.contacto { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.8rem; }
.contacto li {
  display: flex; gap: 0.7rem; align-items: flex-start;
  font-size: var(--t-sm); color: var(--ink-soft); line-height: 1.45;
}
.contacto-ic {
  width: 28px; height: 28px; border-radius: 8px; flex: none;
  background: var(--brand-tint); color: var(--brand); display: grid; place-items: center;
}
/* Una línea de texto mide ~20 px y el ícono 28: se baja el texto para que su
   primera línea quede centrada con el ícono, también en las filas de dos. */
.contacto li > a, .contacto address, .horario { padding-top: 0.2rem; min-width: 0; }
.contacto a { color: var(--ink); font-weight: 600; overflow-wrap: anywhere; }
.contacto a:hover { color: var(--accent-strong); text-decoration: underline; }
.contacto address { font-style: normal; display: flex; flex-direction: column; }
.horario { margin: 0; display: grid; grid-template-columns: auto auto; gap: 0.1rem 0.8rem; }
.horario dt { font-weight: 400; }
.horario dd { margin: 0; color: var(--ink); font-weight: 600; font-variant-numeric: tabular-nums; }
.horario .tramo { display: block; }

@media (max-width: 1080px) {
  .grid { grid-template-columns: 1fr; }
  /* El lateral NO se sube arriba: la próxima visita es lo primero que hay que
     ver, y antes los accesos rápidos se le ponían por delante. */
  .col-side { order: 1; }
}
@media (max-width: 560px) {
  .ficha-pie { justify-content: stretch; }
  .ficha-pie > * { flex: 1; justify-content: center; }
}

@media (prefers-reduced-motion: reduce) {
  .masc, .acceso { transition: none; }
}
</style>
