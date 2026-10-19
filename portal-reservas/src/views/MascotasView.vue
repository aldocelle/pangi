<script setup>
/* Mis mascotas.
 *
 * QUÉ CAMBIÓ Y POR QUÉ
 *
 * 1. La tarjeta era un `RouterLink` con un `<button>` de Editar ADENTRO. Eso es
 *    HTML inválido y un problema real de accesibilidad: un control dentro de
 *    otro control. Funcionaba de milagro, a fuerza de `@click.stop.prevent`.
 *    Ahora la tarjeta es un `<article>` y los dos actos —ver ficha y editar—
 *    son hermanos en el pie, cada uno con su etiqueta.
 *
 * 2. Se fue el chip «Activo». Lo mostraban TODAS las mascotas, siempre: un
 *    adorno que ocupa el lugar donde el ojo busca un estado real.
 *
 * 3. Se fue la tarjeta punteada «Agregar nueva mascota». La cabecera ya tiene
 *    ese botón; dos llamados a la misma acción compiten en vez de sumar. El
 *    caso que la justificaba —no tener ninguna mascota— lo cubre el estado
 *    vacío, que ahora sí invita.
 *
 * 4. La tarjeta pasa al lenguaje del paso 4 de la reserva: banda tintada con la
 *    foto arriba, filas de ícono debajo, panel aparte para la próxima cita.
 */
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useDatosStore } from '../stores/datos.js';
import { api, apiError, edadDesde, fechaLarga, horaCorta, fotoMascota } from '../api.js';
import BaseIcon from '../components/BaseIcon.vue';
import EditorFoto from '../components/EditorFoto.vue';

const datos = useDatosStore();
onMounted(() => datos.cargar());

function proximaCita(mascotaId) {
  return datos
    .citasDeMascota(mascotaId)
    .filter((c) => new Date(c.fecha_hora) >= new Date() && c.estado !== 'Cancelada')
    .sort((a, b) => new Date(a.fecha_hora) - new Date(b.fecha_hora))[0];
}
function sexoTexto(s) {
  if (s === 'M') return 'Macho';
  if (s === 'F') return 'Hembra';
  return null;
}

/* Crear / editar mascota propia — el backend ya lo permite
   (POST /mascotas/, PATCH /mascotas/{id}). */
const modal = ref(false);
const editandoId = ref(null);
const form = ref(vacio());
const guardando = ref(false);
const errorForm = ref('');

function vacio() {
  return { nombre: '', especie: 'perro', raza: '', sexo: '', fecha_nacimiento: '', peso_kg: '', alergias: '' };
}

function abrirCrear() {
  editandoId.value = null;
  form.value = vacio();
  errorForm.value = '';
  modal.value = true;
}

function abrirEditar(m) {
  editandoId.value = m.id;
  form.value = {
    nombre: m.nombre || '',
    especie: m.especie || 'perro',
    raza: m.raza || '',
    sexo: m.sexo || '',
    fecha_nacimiento: m.fecha_nacimiento || '',
    peso_kg: m.peso_kg ?? '',
    alergias: m.alergias || '',
  };
  errorForm.value = '';
  modal.value = true;
}

/* Borrar. Vive DENTRO del modal de edición y no como tercer botón en cada
 * tarjeta: un borrado no debería estar a un toque de distancia, y tres acciones
 * por tarjeta compiten entre sí.
 *
 * El backend solo lo permite mientras la mascota no tenga historial (ver
 * `eliminar_mi_mascota` en routes/mascotas.py). Cuando ya fue atendida devuelve
 * 409 con el motivo, y ese texto se muestra tal cual: explica que la ficha es
 * parte del registro de la clínica y hacia dónde ir. Inventar acá un mensaje
 * propio lo dejaría desincronizado el día que la regla cambie. */
const eliminando = ref(false);

async function eliminar() {
  const m = datos.mascotas.find((x) => x.id === editandoId.value);
  const nombre = m?.nombre || 'esta mascota';
  if (!confirm(`¿Eliminar a ${nombre}? Esta acción no se puede deshacer.`)) return;

  eliminando.value = true;
  errorForm.value = '';
  try {
    await api.delete(`/mascotas/${editandoId.value}`);
    modal.value = false;
    await datos.cargar(true);
  } catch (e) {
    errorForm.value = apiError(e, 'No se pudo eliminar la mascota.');
  } finally {
    eliminando.value = false;
  }
}

async function guardar() {
  guardando.value = true;
  errorForm.value = '';
  try {
    const payload = { ...form.value };
    payload.peso_kg = payload.peso_kg === '' ? null : Number(payload.peso_kg);
    payload.fecha_nacimiento = payload.fecha_nacimiento || null;
    payload.sexo = payload.sexo || null;

    if (editandoId.value) {
      await api.patch(`/mascotas/${editandoId.value}`, payload);
    } else {
      await api.post('/mascotas/', payload);
    }
    modal.value = false;
    await datos.cargar(true);
  } catch (e) {
    errorForm.value = apiError(e, 'No se pudo guardar la mascota.');
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Mis mascotas</h1>
        <p class="muted">Su información y sus próximas visitas.</p>
      </div>
      <button type="button" class="btn btn-primary" @click="abrirCrear">
        <BaseIcon name="plus" :size="18" /> Agregar mascota
      </button>
    </header>

    <p v-if="datos.cargando" class="vacio">Cargando…</p>

    <!-- Estado vacío: es la primera pantalla de quien recién entra, así que
         dice qué hacer en vez de constatar que no hay nada. -->
    <div v-else-if="!datos.mascotas.length" class="ficha ficha-vacia">
      <span class="vacia-ic" aria-hidden="true"><BaseIcon name="pets" :size="26" /></span>
      <div>
        <strong>Todavía no hay mascotas registradas</strong>
        <p class="muted">Agrégalas acá y sus fichas quedarán a mano, con su historial y sus horas.</p>
      </div>
      <button type="button" class="btn btn-primary btn-sm" @click="abrirCrear">Agregar la primera</button>
    </div>

    <div v-else class="mascotas">
      <article v-for="m in datos.mascotas" :key="m.id" class="ficha">
        <header class="ficha-cab">
          <!-- La foto se cambia acá mismo, sin entrar a la ficha: es donde se
               ven todas juntas y donde se nota la que falta. -->
          <EditorFoto
            :src="fotoMascota(m)" :tiene-foto="!!m.foto_url" :nombre="m.nombre" :size="56" compacto
            :subir="(archivo) => datos.subirFotoMascota(m.id, archivo)" :quitar="() => datos.quitarFotoMascota(m.id)"
          />
          <div class="ficha-cab-txt">
            <strong>{{ m.nombre }}</strong>
            <span>{{ m.raza || m.especie }}</span>
          </div>
        </header>

        <div class="ficha-filas">
          <p class="ficha-fila">
            <span class="ficha-ic" aria-hidden="true"><BaseIcon name="calendar" :size="18" /></span>
            <span>{{ m.fecha_nacimiento ? edadDesde(m.fecha_nacimiento) : 'Edad no registrada' }}</span>
          </p>
          <!-- ♀/♂ se quedan como signo: son tipografía con significado propio
               —no emoji de color— y el catálogo de BaseIcon no tiene equivalente.
               Sustituirlos por un ícono genérico perdería el dato. -->
          <p class="ficha-fila" v-if="sexoTexto(m.sexo)">
            <span class="ficha-ic ficha-ic--signo" aria-hidden="true">{{ m.sexo === 'F' ? '♀' : '♂' }}</span>
            <span>{{ sexoTexto(m.sexo) }}</span>
          </p>
          <p class="ficha-fila" v-if="m.peso_kg">
            <span class="ficha-ic" aria-hidden="true"><BaseIcon name="scale" :size="18" /></span>
            <span>{{ m.peso_kg }} kg</span>
          </p>
          <p class="ficha-fila" v-if="m.alergias">
            <span class="ficha-ic" aria-hidden="true"><BaseIcon name="alert" :size="18" /></span>
            <span class="alergia">{{ m.alergias }}</span>
          </p>
        </div>

        <!-- La próxima cita va en panel aparte, como el aviso del día en el
             paso 4: es la información con fecha, no un atributo del animal. -->
        <p class="ficha-cita" v-if="proximaCita(m.id)">
          <BaseIcon name="bell" :size="18" />
          <span class="cap">
            {{ fechaLarga(proximaCita(m.id).fecha_hora) }} · {{ horaCorta(proximaCita(m.id).fecha_hora) }}
          </span>
        </p>

        <footer class="ficha-pie">
          <button type="button" class="btn btn-ghost btn-sm" @click="abrirEditar(m)">Editar</button>
          <RouterLink :to="`/mascotas/${m.id}`" class="btn btn-primary btn-sm">Ver ficha</RouterLink>
        </footer>
      </article>
    </div>

    <!-- Modal crear/editar -->
    <div v-if="modal" class="modal-backdrop" @click.self="modal = false">
      <div class="modal card" role="dialog" aria-modal="true" aria-labelledby="mv-titulo">
        <h2 id="mv-titulo">{{ editandoId ? 'Editar mascota' : 'Agregar mascota' }}</h2>
        <form class="masc-form" @submit.prevent="guardar">
          <div class="field">
            <label for="mv-nombre">Nombre</label>
            <input id="mv-nombre" class="input" v-model="form.nombre" required />
          </div>
          <div class="grid-2">
            <div class="field">
              <label for="mv-especie">Especie</label>
              <select id="mv-especie" class="input" v-model="form.especie">
                <option value="perro">Perro</option>
                <option value="gato">Gato</option>
                <option value="otro">Otro</option>
              </select>
            </div>
            <div class="field">
              <label for="mv-sexo">Sexo</label>
              <select id="mv-sexo" class="input" v-model="form.sexo">
                <option value="">Sin especificar</option>
                <option value="M">Macho</option>
                <option value="F">Hembra</option>
              </select>
            </div>
          </div>
          <div class="grid-2">
            <div class="field">
              <label for="mv-raza">Raza</label>
              <input id="mv-raza" class="input" v-model="form.raza" />
            </div>
            <div class="field">
              <label for="mv-peso">Peso (kg)</label>
              <input id="mv-peso" class="input" type="number" step="0.1" min="0" v-model="form.peso_kg" />
            </div>
          </div>
          <div class="field">
            <label for="mv-nac">Fecha de nacimiento</label>
            <input id="mv-nac" class="input" type="date" v-model="form.fecha_nacimiento" />
          </div>
          <div class="field">
            <label for="mv-alergias">Alergias</label>
            <input id="mv-alergias" class="input" v-model="form.alergias" placeholder="Ninguna conocida" />
          </div>

          <p v-if="errorForm" class="form-error">{{ errorForm }}</p>

          <div class="modal-acciones">
            <!-- A la izquierda y separado del par Cancelar/Guardar: es
                 destructivo, así que no comparte vecindad con el botón que la
                 gente toca por costumbre. -->
            <button
              v-if="editandoId"
              type="button" class="btn btn-ghost btn-borrar"
              :disabled="eliminando || guardando"
              @click="eliminar"
            >
              {{ eliminando ? 'Eliminando…' : 'Eliminar' }}
            </button>
            <span class="modal-espacio"></span>
            <button type="button" class="btn btn-ghost" @click="modal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="guardando || eliminando">
              {{ guardando ? 'Guardando…' : 'Guardar mascota' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page-head {
  display: flex; justify-content: space-between; align-items: flex-start;
  gap: 1rem; margin-bottom: 1.6rem; flex-wrap: wrap;
}
/* Sin el ícono en cuadrito que llevaba antes: compite con las fotos de las
   mascotas, que son el elemento gráfico real de esta pantalla. */
.page-head h1 { font-size: clamp(1.35rem, 4vw, 1.6rem); color: var(--brand); letter-spacing: -0.01em; }
.page-head .muted { margin-top: 0.2rem; }
.vacio { color: var(--ink-soft); font-size: var(--t-base); }

/* `auto-fill` con un mínimo generoso: la tarjeta lleva banda, cuatro filas y
   pie, así que por debajo de 19rem el texto empieza a partirse. */
.mascotas { display: grid; grid-template-columns: repeat(auto-fill, minmax(19rem, 1fr)); gap: 1.1rem; }

/* ── La tarjeta, con el lenguaje del paso 4 de la reserva ─────────────── */
.ficha {
  display: flex; flex-direction: column;
  border: 1px solid var(--line); border-radius: var(--r-lg);
  overflow: hidden; background: var(--surface);
  transition: border-color 0.2s var(--ease), box-shadow 0.2s var(--ease);
}
.ficha:hover { border-color: var(--accent); box-shadow: var(--shadow-lg); }
.ficha-cab {
  display: flex; align-items: center; gap: 0.85rem;
  padding: 1rem 1.15rem; background: var(--brand-tint);
}
.ficha-cab-txt { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; }
.ficha-cab-txt strong { color: var(--brand-ink); font-size: var(--t-lg); }
.ficha-cab-txt span { font-size: var(--t-sm); color: var(--ink-soft); text-transform: capitalize; }

.ficha-filas { display: flex; flex-direction: column; gap: 0.5rem; padding: 1rem 1.15rem; flex: 1; }
.ficha-fila { display: flex; align-items: flex-start; gap: 0.6rem; margin: 0; font-size: var(--t-base); }
/* Centrado con grid y no con `text-align`: adentro ya no va un glifo cuyo alto
   de línea hacía el trabajo, va un <svg>. El ancho fijo mantiene alineada la
   columna de texto de todas las filas. */
.ficha-ic {
  flex: none; width: 1.4em; display: grid; place-items: center;
  color: var(--ink-soft);
}
/* La única fila que sigue siendo texto (♀/♂) necesita su tamaño. */
.ficha-ic--signo { font-size: var(--t-lg); }
/* La alergia se lee, no se decora: color de texto con contraste, no un chip
   amarillo que obligue a entrecerrar los ojos. */
.alergia { color: var(--warn-ink); font-weight: 600; }

.ficha-cita {
  display: flex; align-items: center; gap: 0.5rem; margin: 0;
  padding: 0.7rem 1.15rem;
  border-top: 1px solid var(--line);
  background: var(--accent-tint);
  font-size: var(--t-sm); color: var(--ink);
}

.ficha-pie {
  display: flex; gap: 0.5rem; justify-content: flex-end;
  padding: 0.8rem 1.15rem; border-top: 1px solid var(--line); background: var(--bg);
}

/* ── Estado vacío ────────────────────────────────────────────────────── */
.ficha-vacia {
  flex-direction: row; align-items: center; gap: 1rem; flex-wrap: wrap;
  padding: 1.4rem 1.3rem;
}
.ficha-vacia:hover { border-color: var(--line); box-shadow: none; }
.ficha-vacia > div { flex: 1; min-width: 13rem; }
.ficha-vacia strong { display: block; color: var(--brand-ink); }
.ficha-vacia .muted { font-size: var(--t-sm); margin-top: 0.2rem; line-height: 1.5; }
.vacia-ic {
  width: 48px; height: 48px; flex: none; display: grid; place-items: center;
  font-size: 1.4rem; border-radius: 50%; background: var(--brand-tint);
}

/* ── Modal ───────────────────────────────────────────────────────────── */
.modal-backdrop {
  position: fixed; inset: 0; z-index: 60;
  background: rgba(16, 26, 61, 0.5);
  display: flex; align-items: center; justify-content: center; padding: 1rem;
  overscroll-behavior: contain;
}
.modal { width: 100%; max-width: 480px; padding: 1.4rem; }
.modal h2 { font-size: var(--t-xl); color: var(--brand); margin-bottom: 1rem; }
.masc-form { display: flex; flex-direction: column; gap: 0.9rem; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem; }
.form-error {
  background: var(--danger-tint); color: var(--danger);
  padding: 0.7rem 1rem; border-radius: var(--r-md); font-size: var(--t-sm);
}
.modal-acciones { display: flex; align-items: center; gap: 0.6rem; margin-top: 0.2rem; }
.modal-espacio { flex: 1; }
.btn-borrar { color: var(--danger); }
.btn-borrar:hover:not(:disabled) { background: var(--danger-tint); }
.btn-borrar:disabled { opacity: 0.5; cursor: not-allowed; }

@media (max-width: 480px) {
  .grid-2 { grid-template-columns: 1fr; }
  .ficha-pie { justify-content: stretch; }
  .ficha-pie > * { flex: 1; justify-content: center; }
}
@media (prefers-reduced-motion: reduce) {
  .ficha { transition: none; }
}
</style>
