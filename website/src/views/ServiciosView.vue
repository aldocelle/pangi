<script setup>
import { ref, computed, onMounted } from 'vue';
import { RESERVA_URL, CLINICA, enlaceEspecialista, API_URL } from '../config.js';
import { api } from '../api.js';
import { agruparServicios } from '../agrupar.js';
import { useReveal } from '../useReveal.js';
import ServiciosHero from '../components/ServiciosHero.vue';
import IconServicio from '../components/IconServicio.vue';
import CtaAgendar from '../components/CtaAgendar.vue';

/* Lo que acompaña a cada consulta es lo mismo que muestra el portal de reservas
   y se ajusta en el panel (Ajustes → Tipos de consulta): la foto si la clínica
   subió una (`servicio.imagen`) y, si no, el «Ícono» elegido (`servicio.emoji`).
   Sin ninguno de los dos cae al icono de línea de siempre, así que ninguna fila
   queda vacía y el sitio no depende de que se haya cargado algo. */
function imagenServicio(s) {
  return s && s.imagen ? `${API_URL}${s.imagen}` : null;
}

const servicios = ref([]);
/* Las variantes de una misma prestación se listan una sola vez (agrupar.js):
   el control dermatológico no aparece aparte, va dentro de su grupo. */
const serviciosVisibles = computed(() => agruparServicios(servicios.value));
const especialidades = ref([]);
const cargando = ref(true);
const error = ref('');
const root = ref(null);

useReveal(root);

onMounted(async () => {
  try {
    const [s, e] = await Promise.all([
      api.get('/catalogo/servicios'),
      api.get('/catalogo/especialistas'),
    ]);
    servicios.value = s.data;
    /* Una entrada por especialidad, quedándose con quién la atiende: la
       clínica pidió que al hacer clic se llegue a reservar directamente con
       esa persona. Si dos comparten especialidad no se enlaza a ninguna —
       elegir por orden de llegada mandaría al tutor con quien no esperaba. */
    const porEspecialidad = new Map();
    for (const esp of e.data) {
      if (!esp.especialidad) continue;
      if (porEspecialidad.has(esp.especialidad)) {
        porEspecialidad.get(esp.especialidad).ambigua = true;
        continue;
      }
      porEspecialidad.set(esp.especialidad, { nombre: esp.nombre, slug: esp.slug, ambigua: false });
    }
    especialidades.value = [...porEspecialidad.entries()]
      .map(([especialidad, quien]) => ({
        especialidad,
        nombre: quien.nombre,
        // Ambigua = la atienden dos personas: se enlaza igual, pero a la
        // reserva general, sin prometer con quién.
        ...enlaceEspecialista(quien.ambigua ? null : quien.slug),
      }))
      .sort((a, b) => a.especialidad.localeCompare(b.especialidad, 'es'));
  } catch (err) {
    error.value = 'No pudimos cargar los servicios en este momento.';
  } finally {
    cargando.value = false;
  }
});
</script>

<template>
  <div ref="root" class="pagina">
    <ServiciosHero :reserva-url="RESERVA_URL" :clinica="CLINICA" />

    <p v-if="error" class="carga-error">{{ error }}</p>
    <p v-else-if="cargando" class="carga-msg">Cargando servicios…</p>

    <template v-else>
      <!-- Bloque destacado: misma foto y tratamiento que la home, para que
           la página de servicios no sea solo listas de texto. -->
      <section class="seccion especialidad-sec">
        <div class="wrap">
          <article class="especialidad" data-reveal>
            <img
              class="esp-foto"
              src="/medicina-preventiva.webp"
              alt="Un veterinario golden revisando las orejas de un beagle sobre la mesa de consulta"
              loading="lazy" decoding="async" width="1200" height="960"
            />
            <div class="esp-cont">
              <p class="esp-marca">Lo más pedido</p>
              <h2>Medicina preventiva</h2>
              <p class="esp-desc">
                Vacunas, desparasitación y controles al día, con un recordatorio
                cuando se acerca la próxima fecha.
              </p>
            </div>
          </article>
        </div>
      </section>

      <!-- Servicios generales -->
      <section class="seccion">
        <div class="wrap">
          <div class="bloque-titulo" data-reveal>
            <h2>Servicios generales</h2>
          </div>
          <!-- Misma fila que la home. Sin precios: la tarifa depende del día
               y del tamaño del paciente, así que una cifra suelta en el sitio
               se lee como promesa y después no calza. El valor se muestra al
               reservar, que es donde ya está el detalle completo. -->
          <ul class="lista">
            <li v-for="s in serviciosVisibles" :key="s.id" data-reveal>
              <a :href="RESERVA_URL" target="_blank" rel="noopener" class="fila" data-glow>
                <span class="fila-ic" :class="{ 'con-img': imagenServicio(s), 'con-emoji': !imagenServicio(s) && s.emoji }">
                  <img v-if="imagenServicio(s)" :src="imagenServicio(s)" alt="" width="96" height="96" loading="lazy" decoding="async" />
                  <span v-else-if="s.emoji" aria-hidden="true">{{ s.emoji }}</span>
                  <IconServicio v-else :nombre="s.nombre" />
                </span>
                <span class="fila-txt">
                  <span class="fila-tit">{{ s.nombre }}</span>
                  <span v-if="s.descripcion" class="fila-desc">{{ s.descripcion }}</span>
                </span>
                <svg class="fila-flecha" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M5 12h13M13 6l6 6-6 6" />
                </svg>
              </a>
            </li>
          </ul>
        </div>
      </section>

      <!-- Especialidades -->
      <section v-if="especialidades.length" class="seccion espec-sec">
        <div class="wrap">
          <div class="bloque-titulo" data-reveal>
            <h2>Especialidades médicas</h2>
            <p class="bloque-sub">
              {{ especialidades.length }} especialidades atendidas por el equipo de la clínica.
            </p>
          </div>
          <!-- Solo nombres: con muchas especialidades, una tarjeta por cada una
               llena la página de cajas iguales y no agrega información.
               Cada una lleva a reservar con quien la atiende; el nombre de la
               persona va en el enlace y no a la vista para no duplicar la
               sección Equipo, pero sí en el title y en el texto accesible,
               que es donde importa saber con quién se está agendando. -->
          <ul class="especs" data-reveal>
            <li v-for="e in especialidades" :key="e.especialidad">
              <a
                :href="e.url" target="_blank" rel="noopener"
                class="espec-link"
                :title="e.directo ? `Reservar con ${e.nombre}` : 'Agendar una hora'"
              >
                <span class="espec-punto" aria-hidden="true"></span>{{ e.especialidad }}
                <span class="sr-only">
                  <template v-if="e.directo"> — reservar con {{ e.nombre }}</template>
                  <template v-else> — agendar una hora</template>
                </span>
              </a>
            </li>
          </ul>
        </div>
      </section>
    </template>

    <CtaAgendar :reserva-url="RESERVA_URL" />
  </div>
</template>

<style scoped>
.seccion { padding: clamp(3rem, 6vw, 4.5rem) 0; background: transparent; }
.espec-sec { background: var(--bg); }
.bloque-titulo { margin-bottom: 2.2rem; }

/* ── Bloque de especialidad (mismo lenguaje visual que la home) ── */
.especialidad-sec { padding-bottom: clamp(1.5rem, 3vw, 2rem); }
.especialidad {
  position: relative;
  border-radius: var(--r-lg);
  overflow: hidden;
  min-height: 420px;
  display: flex;
  isolation: isolate;
}
.esp-foto {
  position: absolute; inset: 0;
  width: 100%; height: 100%; object-fit: cover;
  object-position: 62% center;
  transition: transform var(--dur-slow) var(--ease);
}
.especialidad::after {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(0deg,
    var(--azul-oscuro) 4%,
    rgba(26, 35, 80, 0.82) 34%,
    rgba(26, 35, 80, 0.34) 62%,
    rgba(26, 35, 80, 0.06) 100%);
}
.especialidad:hover .esp-foto { transform: scale(1.04); }

.esp-cont {
  position: relative; z-index: 1;
  margin-top: auto;
  padding: clamp(1.5rem, 3vw, 2.2rem);
  max-width: 46ch;
}
.esp-marca {
  display: flex; align-items: center; gap: 0.6rem;
  color: #fff;
  font-weight: 600; font-size: 0.9rem;
  margin-bottom: 0.55rem;
}
.esp-marca::before {
  content: ''; width: 26px; height: 2px; border-radius: 2px;
  background: var(--acento-claro);
}
.especialidad h2 {
  color: #fff;
  font-size: clamp(1.6rem, 3.2vw, 2.1rem);
  margin-bottom: 0.5rem;
}
.esp-desc {
  color: rgba(255, 255, 255, 0.9);
  font-size: 1rem;
  line-height: 1.65;
}

@media (max-width: 540px) {
  .especialidad { min-height: 380px; }
  .esp-foto { object-position: 55% 38%; }
}

@media (prefers-reduced-motion: reduce) {
  .esp-foto { transition: none; }
  .especialidad:hover .esp-foto { transform: none; }
}
.bloque-titulo h2 { color: var(--azul); font-size: clamp(1.6rem, 3.6vw, 2.2rem); }
.bloque-sub { color: var(--ink-soft); margin-top: 0.6rem; font-size: 1rem; }

/* ── Lista de servicios (mismo lenguaje que la home) ── */
.lista {
  list-style: none; margin: 0; padding: 0;
  /* min() en el mínimo: sin él, en pantallas de 320px la columna exige
     340px y desborda el viewport en horizontal. */
  display: grid; grid-template-columns: repeat(auto-fit, minmax(min(340px, 100%), 1fr));
  column-gap: clamp(1.2rem, 3vw, 2.4rem);
}
/* Filete inferior en todas: con auto-fit el número de columnas cambia solo,
   y un borde superior dejaría la primera fila descuadrada según dónde caiga
   el corte de columna. */
.lista li { border-bottom: 1px solid var(--line); }

.fila {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 1rem;
  padding: clamp(0.95rem, 1.8vw, 1.2rem) 0.9rem;
  border-radius: var(--r-md);
  /* transform y no padding-left: desplaza en la GPU sin recalcular layout
     en cada hover de cada fila (misma fila que ServiciosSection.vue). */
  transition: background var(--dur-fast) var(--ease), transform var(--dur-fast) var(--ease), box-shadow var(--dur-fast) var(--ease);
}
.fila-ic {
  width: 42px; height: 42px; padding: 9px;
  border-radius: var(--r-md);
  background: var(--azul-tint); color: var(--azul);
  transition: background var(--dur-fast) var(--ease), color var(--dur-fast) var(--ease);
}
.fila-ic.con-emoji { display: grid; place-items: center; padding: 0; font-size: 1.3rem; line-height: 1; }
.fila-ic.con-img { width: 48px; height: 48px; padding: 5px; background: transparent; }
.fila-ic img { display: block; width: 100%; height: 100%; object-fit: contain; }
.fila-txt { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
.fila-tit {
  font-family: 'Poppins', 'Open Sans', system-ui, sans-serif;
  font-weight: 600; font-size: 1.05rem; color: var(--azul);
  transition: color var(--dur-fast) var(--ease);
}
.fila-desc {
  color: var(--ink-soft); font-size: 0.9rem; line-height: 1.45;
  white-space: pre-line;
  display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
}
.fila-flecha {
  width: 20px; height: 20px; color: var(--acento);
  opacity: 0; transform: translateX(-6px);
  transition: opacity var(--dur-fast) var(--ease), transform var(--dur-fast) var(--ease);
}

.fila:hover, .fila:focus-visible {
  background: var(--bg); transform: translateX(0.4rem); box-shadow: var(--shadow);
}
.fila:hover .fila-ic, .fila:focus-visible .fila-ic { background: var(--acento-suave); color: var(--acento-fuerte); }
.fila:hover .fila-ic.con-img, .fila:focus-visible .fila-ic.con-img { background: transparent; }.fila:hover .fila-tit, .fila:focus-visible .fila-tit { color: var(--acento-fuerte); }
.fila:hover .fila-flecha, .fila:focus-visible .fila-flecha { opacity: 1; transform: none; }
.fila:focus-visible { outline: 2px solid var(--acento); outline-offset: 2px; }

/* ── Especialidades: solo tipografía ── */
.especs {
  list-style: none; margin: 0; padding: 0;
  columns: 3; column-gap: clamp(1.5rem, 4vw, 3rem);
}
.especs li {
  break-inside: avoid;
  display: flex; align-items: baseline; gap: 0.7rem;
  padding: 0.62rem 0;
  border-bottom: 1px solid var(--line);
  color: var(--azul);
  font-family: 'Poppins', 'Open Sans', system-ui, sans-serif;
  font-weight: 500; font-size: 0.98rem;
}
.espec-punto {
  flex: none; width: 6px; height: 6px; border-radius: 50%;
  background: var(--acento); transform: translateY(-2px);
}

/* El enlace ocupa toda la fila para que el área de clic sea la línea entera
   y no las dos palabras del nombre. Hereda color: que se vea igual que las
   especialidades sin enlace, y que la diferencia la marque el hover — así la
   lista se sigue leyendo como una lista y no como un menú de links. */
.espec-link {
  flex: 1; display: flex; align-items: baseline; gap: 0.7rem;
  color: inherit; text-decoration: none;
  transition: color 0.18s;
}
.espec-link:hover, .espec-link:focus-visible { color: var(--acento-fuerte); }
.espec-link:hover .espec-punto { background: var(--acento-fuerte); }

.sr-only {
  position: absolute; width: 1px; height: 1px;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap;
}

@media (prefers-reduced-motion: reduce) { .espec-link { transition: none; } }

.carga-msg, .carga-error { text-align: center; padding: 3rem 1rem; color: var(--ink-soft); }
.carga-error { color: var(--acento-fuerte); }

/* El CTA de cierre vive ahora en CtaAgendar.vue, con sus propios estilos. */

@media (max-width: 1040px) { .especs { columns: 2; } }
@media (max-width: 620px) {
  .especs { columns: 1; }
  .fila { gap: 0.8rem; padding-left: 0.6rem; padding-right: 0.6rem; }
  .fila-ic { width: 38px; height: 38px; padding: 8px; }
}

@media (prefers-reduced-motion: reduce) {
  .fila, .fila-ic, .fila-tit, .fila-flecha { transition: none; }
  .fila:hover, .fila:focus-visible { transform: none; }
  .fila-flecha { opacity: 1; transform: none; }
}
</style>
