<script setup>
import { ref, onMounted } from 'vue';
import { api, apiError } from '../api.js';

const equipo = ref([]);
const loading = ref(true);
const error = ref('');

const areas = ref([]); // [{id, label}], desde el backend — mismo catálogo que usa Auditoría

const modal = ref(false);
const editandoId = ref(null);
const form = ref({ nombre: '', email: '', telefono: '', rol: 'veterinario', cargo: '', es_activo: true, permisos: null });
// Acceso total (permisos=null) vs restringido a una lista explícita —
// aparte del form porque null y [] significan cosas muy distintas y un
// checkbox no puede representar "sin restricción" directamente.
const restringir = ref(false);
const guardando = ref(false);
const passGenerada = ref(null);
const creado = ref(null);

async function cargar() {
  loading.value = true;
  error.value = '';
  try {
    const [eq, ar] = await Promise.all([api.get('/staff'), api.get('/auditoria/areas')]);
    equipo.value = eq.data;
    // "auditoria" no se ofrece en el checklist: es la única área que ni
    // siquiera el default de acceso total incluye, se otorga aparte y
    // rara vez hace falta delegarla — evita que el checklist se llene de
    // opciones que casi nadie va a marcar.
    areas.value = ar.data.filter((a) => a.id !== 'auditoria');
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar el equipo.');
  } finally {
    loading.value = false;
  }
}
onMounted(cargar);

function abrirCrear() {
  editandoId.value = null;
  form.value = { nombre: '', email: '', telefono: '', rol: 'veterinario', cargo: '', es_activo: true, permisos: null };
  restringir.value = false;
  passGenerada.value = null;
  creado.value = null;
  modal.value = true;
}
function abrirEditar(u) {
  editandoId.value = u.id;
  form.value = {
    nombre: u.nombre, email: u.email, telefono: u.telefono || '', rol: u.rol, cargo: u.cargo || '',
    es_activo: u.es_activo, permisos: u.permisos,
  };
  restringir.value = Array.isArray(u.permisos);
  passGenerada.value = null;
  creado.value = null;
  modal.value = true;
}

function alternarRestringir(activo) {
  restringir.value = activo;
  if (activo && !Array.isArray(form.value.permisos)) form.value.permisos = [];
}

function tienePermiso(area) {
  return Array.isArray(form.value.permisos) && form.value.permisos.includes(area);
}
function alternarPermiso(area) {
  const actuales = form.value.permisos || [];
  form.value.permisos = actuales.includes(area)
    ? actuales.filter((a) => a !== area)
    : [...actuales, area];
}

async function guardar() {
  guardando.value = true;
  error.value = '';
  try {
    const esVeterinario = form.value.rol === 'veterinario';
    if (editandoId.value) {
      const payload = {
        nombre: form.value.nombre, email: form.value.email, telefono: form.value.telefono,
        rol: form.value.rol, cargo: form.value.cargo, es_activo: form.value.es_activo,
      };
      if (esVeterinario && restringir.value) payload.permisos = form.value.permisos;
      else if (esVeterinario) payload.limpiar_permisos = true; // volver a acceso total
      await api.patch(`/staff/${editandoId.value}`, payload);
      modal.value = false;
      await cargar();
    } else {
      const payload = {
        nombre: form.value.nombre, email: form.value.email, telefono: form.value.telefono, rol: form.value.rol,
        cargo: form.value.cargo,
      };
      if (esVeterinario && restringir.value) payload.permisos = form.value.permisos;
      const { data } = await api.post('/staff', payload);
      creado.value = data;
      passGenerada.value = data.password_temporal;
      await cargar();
    }
  } catch (e) {
    error.value = apiError(e, 'No se pudo guardar.');
  } finally {
    guardando.value = false;
  }
}

async function resetPassword(u) {
  if (!confirm(`¿Generar una contraseña ALEATORIA para ${u.nombre}?`)) return;
  try {
    const { data } = await api.post(`/staff/${u.id}/reset-password`);
    alert(`Nueva contraseña para ${u.nombre}:\n\n${data.password_temporal}\n\nNo volverá a mostrarse.`);
  } catch (e) {
    alert(apiError(e, 'No se pudo resetear.'));
  }
}

async function definirClave(u) {
  // Poner una clave ELEGIDA (distinto de "Resetear", que es aleatoria).
  const clave = prompt(`Nueva contraseña para ${u.nombre} (mínimo 8 caracteres):`);
  if (clave === null) return;               // canceló
  if (clave.trim().length < 8) {
    alert('La contraseña debe tener al menos 8 caracteres.');
    return;
  }
  try {
    await api.post(`/staff/${u.id}/reset-password`, { password: clave });
    alert(`Contraseña de ${u.nombre} actualizada.`);
  } catch (e) {
    alert(apiError(e, 'No se pudo cambiar la contraseña.'));
  }
}

async function toggleActivo(u) {
  try {
    await api.patch(`/staff/${u.id}`, { es_activo: !u.es_activo });
    await cargar();
  } catch (e) {
    alert(apiError(e, 'No se pudo actualizar.'));
  }
}

async function eliminar(u) {
  if (!confirm(`¿Eliminar a ${u.nombre} del equipo? No se puede deshacer.`)) return;
  try {
    await api.delete(`/staff/${u.id}`);
    await cargar();
  } catch (e) {
    alert(apiError(e, 'No se pudo eliminar.'));
  }
}

function copiar(txt) { navigator.clipboard?.writeText(txt); }
function rolLabel(r) { return r === 'admin' ? 'Administrador' : 'Veterinario'; }
</script>

<template>
  <div class="page">
    <div class="head-row">
      <div>
        <h1 class="page-title">Equipo</h1>
        <p class="page-sub">Administra las cuentas del personal que accede al panel.</p>
      </div>
      <button class="btn btn-primary" @click="abrirCrear">+ Nuevo miembro</button>
    </div>

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="loading" class="empty">Cargando…</p>

    <div v-else class="tabla card">
      <div class="t-head t-row">
        <span>Miembro</span>
        <span>Rol</span>
        <span></span>
      </div>
      <div v-for="u in equipo" :key="u.id" class="t-row" :class="{ off: !u.es_activo }">
        <span class="fila-nombre">
          <span class="fila-ic">{{ (u.nombre[0] || 'V').toUpperCase() }}</span>
          <span class="m-info">
            <strong>{{ u.nombre }}</strong>
            <small>{{ u.email }}</small>
          </span>
        </span>
        <span class="m-tags">
          <span v-if="u.cargo" class="chip chip-cargo">{{ u.cargo }}</span>
          <span class="chip" :class="u.rol === 'admin' ? 'chip-brand' : 'chip-info'">{{ rolLabel(u.rol) }}</span>
          <span v-if="u.rol === 'veterinario' && Array.isArray(u.permisos)" class="chip chip-restringido">
            Acceso restringido
          </span>
          <span v-if="!u.es_activo" class="chip chip-off">Inactivo</span>
        </span>
        <span class="acciones">
          <button class="btn btn-ghost btn-sm" @click="abrirEditar(u)">Editar</button>
          <button class="btn btn-ghost btn-sm" @click="definirClave(u)">Cambiar clave</button>
          <button class="btn btn-ghost btn-sm" @click="resetPassword(u)">Resetear clave</button>
          <button class="btn btn-ghost btn-sm" @click="toggleActivo(u)">{{ u.es_activo ? 'Desactivar' : 'Activar' }}</button>
          <button class="btn btn-ghost btn-sm peligro" @click="eliminar(u)">Eliminar</button>
        </span>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="modal" class="backdrop" @click.self="modal = false">
      <div class="modal card">
        <template v-if="!passGenerada">
          <h2>{{ editandoId ? 'Editar miembro' : 'Nuevo miembro del equipo' }}</h2>
          <form @submit.prevent="guardar" class="form">
            <div class="field">
              <label>Nombre completo *</label>
              <input class="input" v-model="form.nombre" required />
            </div>
            <div class="field">
              <label>Correo electrónico *</label>
              <input class="input" type="email" v-model="form.email" required />
              <!-- El correo es también el usuario de acceso: si se edita, el
                   miembro debe iniciar sesión con el nuevo desde ese momento. -->
              <p v-if="editandoId" class="field-hint">Es también su usuario de acceso al panel.</p>
            </div>
            <div class="row-2">
              <div class="field">
                <label>Teléfono</label>
                <input class="input" v-model="form.telefono" />
              </div>
              <div class="field">
                <label>Rol</label>
                <select class="select" v-model="form.rol">
                  <option value="veterinario">Veterinario</option>
                  <option value="admin">Administrador</option>
                </select>
              </div>
            </div>
            <div class="field">
              <label>Cargo</label>
              <input class="input" v-model="form.cargo" placeholder="Secretaria, Peluquera, Dermatología…" />
              <p class="field-hint">Solo para identificarla en esta lista. El acceso lo define el Rol de abajo.</p>
            </div>

            <div v-if="form.rol === 'veterinario'" class="field permisos-field">
              <label>Acceso al panel</label>
              <div class="permisos-toggle">
                <button
                  type="button" class="toggle-btn" :class="{ activo: !restringir }"
                  @click="alternarRestringir(false)"
                >Todo</button>
                <button
                  type="button" class="toggle-btn" :class="{ activo: restringir }"
                  @click="alternarRestringir(true)"
                >Solo lo que marque</button>
              </div>
              <p class="field-hint">
                "Todo" incluye cualquier área que se agregue después. Auditoría
                no se delega desde acá: es exclusiva de un administrador.
              </p>
              <div v-if="restringir" class="permisos-lista">
                <label v-for="a in areas" :key="a.id" class="permiso-check">
                  <input type="checkbox" :checked="tienePermiso(a.id)" @change="alternarPermiso(a.id)" />
                  <span>{{ a.label }}</span>
                </label>
              </div>
            </div>

            <p v-if="error" class="error-text">{{ error }}</p>
            <div class="modal-acciones">
              <button type="button" class="btn btn-ghost" @click="modal = false">Cancelar</button>
              <button type="submit" class="btn btn-primary" :disabled="guardando">
                {{ guardando ? 'Guardando…' : (editandoId ? 'Guardar' : 'Crear miembro') }}
              </button>
            </div>
          </form>
        </template>

        <template v-else>
          <div class="exito">
            <span class="exito-ic">✓</span>
            <h2>Miembro creado</h2>
            <p class="muted">Comparte estas credenciales con {{ creado.nombre }}. La contraseña no volverá a mostrarse.</p>
            <div class="cred">
              <div class="cred-row"><span>Correo</span><code>{{ creado.email }}</code></div>
              <div class="cred-row">
                <span>Contraseña</span><code>{{ passGenerada }}</code>
                <button class="btn btn-ghost btn-sm" @click="copiar(passGenerada)">Copiar</button>
              </div>
            </div>
            <div class="modal-acciones">
              <button class="btn btn-primary" @click="modal = false">Listo</button>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.empty { text-align: center; color: var(--ink-soft); padding: 2rem; }
.error-text { color: var(--danger); margin-bottom: 0.8rem; }

/* El patrón de listado vive en app.css; aquí solo lo propio de la vista. */
.t-row { grid-template-columns: 1.6fr 0.9fr auto; min-width: 820px; }
.t-row.off { opacity: 0.6; }
.m-info { display: flex; flex-direction: column; min-width: 0; line-height: 1.3; }
.m-info strong { color: var(--brand); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.m-info small { color: var(--ink-soft); font-size: var(--t-micro); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.m-tags { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.chip { padding: 0.2em 0.7em; border-radius: 999px; font-size: var(--t-micro); font-weight: 600; }
.chip-brand { background: var(--brand-tint); color: var(--brand); }
.chip-info { background: var(--info-tint); color: var(--info); }
.chip-off { background: var(--danger-tint); color: var(--danger); }
.chip-restringido { background: var(--warn-tint); color: var(--warn-ink); }
.chip-cargo { background: var(--line); color: var(--ink-soft); }

.btn-danger-ghost { color: var(--danger); }
.btn-danger-ghost:hover { border-color: var(--danger) !important; background: var(--danger-tint) !important; }

/* Modal */
.backdrop { position: fixed; inset: 0; background: rgba(26, 35, 80, 0.5); display: grid; place-items: center; z-index: 400; padding: 1rem; }
.modal { padding: 1.6rem; width: 100%; max-width: 460px; max-height: 90vh; overflow-y: auto; }

.permisos-field { gap: 0.5rem; }
.permisos-toggle { display: flex; border: 1.5px solid var(--line-strong); border-radius: var(--r-full); padding: 0.2rem; width: fit-content; }
.toggle-btn {
  padding: 0.35em 0.9em; border-radius: var(--r-full); font-size: var(--t-micro); font-weight: 600;
  color: var(--ink-soft); transition: background 0.15s, color 0.15s;
}
.toggle-btn.activo { background: var(--accent); color: #fff; }
.permisos-lista {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: 0.2rem 0.6rem;
  border: 1px solid var(--line); border-radius: var(--r-md);
  padding: 0.6rem 0.7rem; background: var(--bg);
}
.permiso-check {
  display: flex; align-items: center; gap: 0.5rem; cursor: pointer;
  font-size: var(--t-sm); padding: 0.3rem 0.2rem;
}
.permiso-check input { cursor: pointer; flex: none; }
.modal h2 { color: var(--brand); }
.form { display: flex; flex-direction: column; gap: 0.9rem; margin-top: 1rem; }
.row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
.field label { font-size: var(--t-sm); font-weight: 600; }
.field-hint { color: var(--ink-soft); font-size: var(--t-micro); margin-top: 0.3rem; }
.modal-acciones { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 0.4rem; }

.exito { text-align: center; }
.exito-ic { display: inline-grid; place-items: center; width: 56px; height: 56px; border-radius: 50%; background: var(--ok-tint); color: var(--ok); font-size: 1.8rem; margin-bottom: 0.8rem; }
.exito h2 { color: var(--brand); }
.exito .muted { max-width: 40ch; margin: 0.4rem auto 1.2rem; font-size: var(--t-sm); }
.cred { background: var(--bg); border-radius: var(--r-md); padding: 1rem; text-align: left; margin-bottom: 1.2rem; display: flex; flex-direction: column; gap: 0.7rem; }
.cred-row { display: flex; align-items: center; gap: 0.7rem; }
.cred-row span { width: 90px; color: var(--ink-soft); font-size: var(--t-sm); }
.cred-row code { flex: 1; background: var(--surface); border: 1px solid var(--line); border-radius: 6px; padding: 0.4em 0.7em; font-family: monospace; font-size: var(--t-base); }

@media (max-width: 720px) { .t-row { min-width: 0; } }
</style>
