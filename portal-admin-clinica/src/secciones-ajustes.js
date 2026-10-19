/* Secciones de Ajustes. Viven acá y no dentro de AjustesView porque las leen
   dos lugares: la propia pantalla y el menú «Administración» de la barra
   superior. Con una sola lista, una sección nueva aparece en los dos. */

/* Todo lo configurable, en una sola lista y en un solo lugar. Antes vivía
   partido en dos: cinco pestañas dentro del modal de la tuerca, y seis
   pantallas sueltas en el grupo «Configuración» del sidebar. Nadie recordaba
   de qué lado quedaba cada cosa.

   `general` marca las cinco que sirve AjustesModal embebido; el resto monta
   la pantalla que ya existía, sin tocarla. */
export const SECCIONES_AJUSTES = [
  /* Las cinco `general` no llevan restricción propia: son las que servía el
     modal de la tuerca, que cualquiera del equipo podía abrir y leer. El
     propio AjustesModal deshabilita los campos y esconde el botón de guardar
     a quien no es admin. Ponerles `adminOnly` acá las escondería del todo y
     eso sería quitarle a media clínica un acceso que ya tenía. */
  { id: 'general',        label: 'Datos de la clínica', grupo: 'La clínica', general: true },
  { id: 'wording',        label: 'Textos del sitio',    grupo: 'La clínica', general: true },
  { id: 'integraciones',  label: 'Integraciones',       grupo: 'La clínica', general: true },
  { id: 'datos',          label: 'Respaldo de datos',   grupo: 'La clínica', general: true },
  /* Solo muestra correos de ejemplo: la ve todo el equipo, sin permiso propio. */
  { id: 'correos',        label: 'Correos',             grupo: 'La clínica', todos: true },
  /* Paridad con ACVet. Inventario y ventas la VE cualquiera con Ventas o
     Inventario (para entender por qué el mostrador frena una venta); guardar
     es de admin y lo exige el backend. */
  { id: 'ventas',         label: 'Inventario y ventas', grupo: 'La clínica', areas: ['ventas', 'inventario'] },

  { id: 'consultas',      label: 'Tipos de consulta',   grupo: 'La atención', area: 'config_consultas' },
  { id: 'servicios',      label: 'Quién atiende cada consulta', grupo: 'La atención', general: true },
  { id: 'especialistas',  label: 'Especialistas y horarios',    grupo: 'La atención', area: 'config_especialistas' },
  { id: 'campos',         label: 'Campos del formulario',       grupo: 'La atención', area: 'config_campos' },
  { id: 'protocolos',     label: 'Protocolos preventivos',      grupo: 'La atención', area: 'config_protocolos' },
  { id: 'recordatorios',  label: 'Recordatorios',               grupo: 'La atención', area: 'recordatorios' },

  { id: 'equipo',         label: 'Equipo y permisos',   grupo: 'El equipo', adminOnly: true },
  /* WhatsApp, Google y la IA, que fallan en silencio, y a quién le llega la
     alerta. Solo admin: muestra la cuenta de Google y el gasto de la IA. */
  { id: 'estado',         label: 'Estado del sistema',  grupo: 'El equipo', adminOnly: true },
  { id: 'asistente',      label: 'Asistente IA',        grupo: 'El equipo', adminOnly: true },
  { id: 'auditoria',      label: 'Auditoría',           grupo: 'El equipo', area: 'auditoria' },
];

export function puedeVerSeccion(auth, s) {
  if (s.general) return true;      // ver sí; editar lo decide AjustesModal
  if (s.todos) return true;
  if (s.adminOnly) return auth.user?.rol === 'admin';
  if (s.areas) return s.areas.some((a) => auth.puede(a));
  return auth.puede(s.area);
}
