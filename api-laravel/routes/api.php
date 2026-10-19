<?php

use App\Http\Controllers\Api\ArchivosController;
use App\Http\Controllers\Api\AsistenciaController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\MisPagosController;
use App\Http\Controllers\Api\EstadoSistemaController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\CajaController;
use App\Http\Controllers\Api\CitasController;
use App\Http\Controllers\Api\ClientesController;
use App\Http\Controllers\Api\ContactoController;
use App\Http\Controllers\Api\EstadisticasController;
use App\Http\Controllers\Api\ExportacionController;
use App\Http\Controllers\Api\ImportacionController;
use App\Http\Controllers\Api\FichasController;
use App\Http\Controllers\Api\GoogleController;
use App\Http\Controllers\Api\InternalController;
use App\Http\Controllers\Api\InventarioController;
use App\Http\Controllers\Api\MascotasController;
use App\Http\Controllers\Api\PagosController;
use App\Http\Controllers\Api\PedidosController;
use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BuscarController;
use App\Http\Controllers\Api\ProtocolosController;
use App\Http\Controllers\Api\RecordatoriosController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\TareasController;
use App\Http\Controllers\Api\TareasRecurrentesController;
use App\Http\Controllers\Api\WhatsappController;
use App\Http\Controllers\Api\ProveedoresController;
use App\Http\Controllers\Api\TransaccionesController;
use App\Http\Controllers\Api\PlantillasRecordatorioController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\SysopController;
use App\Http\Controllers\Api\CorreosController;
use Illuminate\Support\Facades\Route;

/*
 * Las rutas se montan SIN el prefijo /api que Laravel pone por defecto.
 *
 * No es preferencia: el dominio y las rutas son contrato con cosas que no
 * controlamos. El bundle ya publicado del portal de reservas pide
 * `/catalogo/servicios`, y el webhook registrado en el panel de comercio de
 * Getnet apunta a `/pagos/notificacion`. Cambiar la forma de la URL obligaría
 * a recompilar los tres frontends y a reconfigurar Getnet — justo lo que la
 * migración quiere evitar, ya que el plan es cambiar el backend apuntando solo
 * el DNS.
 *
 * El prefijo se quita en bootstrap/app.php.
 */

// Las fotos de los especialistas: Python las monta como estáticos desde la app
// y el port no tenía equivalente — la URL que devolvía subir una foto daba 404.
// Ver `ArchivosController`. El nombre no admite barras, así que no hay forma de
// pedir algo fuera del directorio.
Route::get('/uploads/{archivo}', [ArchivosController::class, 'upload'])
    ->where('archivo', '[A-Za-z0-9._-]+');

Route::get('/catalogo/servicios', [CatalogoController::class, 'servicios']);
Route::get('/catalogo/especialistas', [CatalogoController::class, 'especialistas']);

Route::get('/citas/slots', [CitasController::class, 'slots']);
Route::post('/citas/reservar', [CitasController::class, 'reservar']);
// La cuarta escritura SIN token, y la única que acepta un archivo. Quien
// reserva no tiene sesión; lo que la acota está en el propio endpoint (una
// sola orden por cita, lista blanca, tope de tamaño y límite por IP).
Route::post('/citas/{id}/orden-examen', [CitasController::class, 'subirOrdenExamen']);
Route::post('/contacto/mensajes', [ContactoController::class, 'crear']);
// El listado y el marcado SÍ exigen sesión: el POST es del sitio público, pero
// las consultas recibidas llevan nombre y correo de quien escribió.
Route::get('/contacto/mensajes', [ContactoController::class, 'listar'])
    ->middleware('auth:consultas_web');
Route::put('/contacto/mensajes/{id}/leido', [ContactoController::class, 'marcarLeido'])
    ->middleware('auth:consultas_web');

// ── Staff ────────────────────────────────────────────────────────────────
// `auth:<area>` exige sesión Y el permiso granular de esa área. Los mismos
// ids que usa el sidebar del panel: cambiar uno es tocar los dos lados.
Route::get('/citas/pendientes-abono', [CitasController::class, 'pendientesAbono'])
    ->middleware('auth:reservas');
Route::get('/citas/reservas', [CitasController::class, 'reservas'])
    ->middleware('auth:reservas');
// Cambiar estado y reagendar: `auth` a secas, sin área. El TUTOR entra por
// acá —a cancelar su hora, a moverla—, así que el permiso se decide dentro del
// endpoint: un dueño solo toca lo suyo, y solo para cancelar.
// Las citas que ve el DUEÑO en su portal. `auth` sin área: el permiso lo
// decide el endpoint, que solo muestra lo suyo.
// Reservar con sesión. `/crear` es del CLIENTE (solo su mascota);
// `/crear-staff` es del mostrador, agenda a nombre del dueño real y puede
// forzar una hora fuera de la disponibilidad.
Route::post('/citas/crear', [CitasController::class, 'crear'])->middleware('auth');
Route::post('/citas/crear-staff', [CitasController::class, 'crearStaff'])
    ->middleware('auth:reservas');

Route::get('/citas/mias', [CitasController::class, 'mias'])->middleware('auth');
Route::get('/citas/mi_mascota/{id}', [CitasController::class, 'deMiMascota'])
    ->middleware('auth');

Route::patch('/citas/{id}/estado', [CitasController::class, 'cambiarEstado'])
    ->middleware('auth');
Route::patch('/citas/{id}/reagendar', [CitasController::class, 'reagendar'])
    ->middleware('auth');
// Registrar el abono cobrado por fuera de la pasarela: staff con `reservas`.
Route::patch('/citas/{id}/abono-confirmado', [CitasController::class, 'confirmarAbono'])
    ->middleware('auth:reservas');
// Horas pagadas y canceladas: la devolución la resuelve una persona.
Route::get('/citas/devoluciones-pendientes', [CitasController::class, 'devolucionesPendientes'])
    ->middleware('auth:reservas');
Route::patch('/citas/{id}/devolucion', [CitasController::class, 'resolverDevolucion'])
    ->middleware('auth:reservas');
// El enlace de pago de una hora ya tomada, para mandárselo al tutor.
Route::post('/citas/{id}/link-pago', [CitasController::class, 'linkPago'])
    ->middleware('auth:reservas');

Route::get('/citas/agenda', [CitasController::class, 'agenda'])
    ->middleware('auth:agenda');

Route::get('/clientes', [ClientesController::class, 'listar'])->middleware('auth:personas');

// **Las rutas de `/clientes/mascotas/...` van ANTES que `/clientes/{id}`.**
// Laravel resuelve por orden de declaración, así que declarada después,
// `GET /clientes/{id}` se comería `/clientes/mascotas/{id}/impacto` con
// id="mascotas". Es el mismo motivo por el que FastAPI las tiene arriba.
Route::patch('/clientes/mascotas/{id}', [ClientesController::class, 'editarMascota'])
    ->middleware('auth:personas');
Route::get('/clientes/mascotas/{id}/impacto', [ClientesController::class, 'impactoMascota'])
    ->middleware('auth:personas');
Route::post('/clientes/mascotas/{id}/mover', [ClientesController::class, 'moverMascota'])
    ->middleware('auth:personas');
Route::delete('/clientes/mascotas/{id}', [ClientesController::class, 'eliminarMascota'])
    ->middleware('auth:admin');

// `/solicitudes-reset` es literal: va antes que `/{id}`, que se la comería.
Route::get('/clientes/solicitudes-reset', [ClientesController::class, 'solicitudesReset'])
    ->middleware('auth:personas');
// La cola de derechos sobre datos personales. VA ANTES de `/clientes/{id}`:
// Laravel resuelve en orden de declaración y una ruta literal declarada
// después de una con parámetro nunca se alcanza.
Route::get('/clientes/solicitudes-datos', [ClientesController::class, 'solicitudesDatos'])
    ->middleware('auth:personas');
Route::patch('/clientes/solicitudes-datos/{solicitudId}',
    [ClientesController::class, 'resolverSolicitudDatos'])->middleware('auth:personas');

Route::get('/clientes/{id}', [ClientesController::class, 'ver'])->middleware('auth:personas');
Route::post('/clientes/{id}/reset-password', [ClientesController::class, 'resetPassword'])
    ->middleware('auth:personas');
Route::post('/clientes', [ClientesController::class, 'crear'])->middleware('auth:personas');
Route::patch('/clientes/{id}', [ClientesController::class, 'editar'])->middleware('auth:personas');
Route::post('/clientes/{id}/mascotas', [ClientesController::class, 'agregarMascota'])
    ->middleware('auth:personas');
Route::get('/clientes/{id}/impacto', [ClientesController::class, 'impactoCliente'])
    ->middleware('auth:personas');
// Borrar arrastra el historial clínico entero: solo admin.
Route::delete('/clientes/{id}', [ClientesController::class, 'eliminarCliente'])
    ->middleware('auth:admin');

// `/proveedores` va ANTES que cualquier `/{id}` para que no lo capture como id.
Route::get('/inventario/proveedores', [InventarioController::class, 'proveedores'])
    ->middleware('auth:inventario');
// Va ANTES de `/inventario/{id}` si alguna vez existe esa ruta: si no, «alertas»
// se leeria como un id. Hoy no hay GET por id, pero el orden se deja bien puesto.
Route::get('/inventario/alertas', [InventarioController::class, 'alertas'])
    ->middleware('auth:inventario');


// Escrituras de inventario: permiso `inventario`. El DELETE no borra, DESACTIVA
// (el ítem sigue referenciado por cada venta que lo cobró), y aun así es solo
// admin, igual que en FastAPI.
Route::post('/inventario', [InventarioController::class, 'crear'])
    ->middleware('auth:inventario');
Route::patch('/inventario/{id}', [InventarioController::class, 'actualizar'])
    ->middleware('auth:inventario');
Route::delete('/inventario/{id}', [InventarioController::class, 'eliminar'])
    ->middleware('auth:admin');


// Proveedores. Todo con permiso `proveedores`, incluido el DELETE —que
// desactiva, no borra— a diferencia de inventario, que para eso exige admin.
Route::get('/proveedores', [ProveedoresController::class, 'listar'])
    ->middleware('auth:proveedores');
Route::post('/proveedores', [ProveedoresController::class, 'crear'])
    ->middleware('auth:proveedores');
Route::get('/proveedores/{id}/pedido-sugerido', [ProveedoresController::class, 'pedidoSugerido'])
    ->middleware('auth:proveedores');
Route::patch('/proveedores/{id}', [ProveedoresController::class, 'actualizar'])
    ->middleware('auth:proveedores');
Route::delete('/proveedores/{id}', [ProveedoresController::class, 'eliminar'])
    ->middleware('auth:proveedores');

// Pedidos/Compras. `/resumen` ANTES de `/{id}`, o la ruta con parámetro se lo
// come. No hay anulación a propósito: revertir una factura recibida exigiría
// descontar stock que puede haberse vendido ya.
Route::post('/pedidos', [PedidosController::class, 'recibir'])
    ->middleware('auth:compras');
Route::get('/pedidos/resumen', [PedidosController::class, 'resumen'])
    ->middleware('auth:compras');
Route::get('/pedidos', [PedidosController::class, 'listar'])
    ->middleware('auth:compras');
Route::get('/pedidos/{id}', [PedidosController::class, 'ver'])
    ->middleware('auth:compras');
Route::patch('/pedidos/{id}/pago', [PedidosController::class, 'actualizarPago'])
    ->middleware('auth:compras');

// Asistencia del equipo. Marcar lo propio, `/mi-estado` y `/mias` van con
// `auth:staff` —cualquiera del equipo ficha su entrada y su salida, sin pedir
// área—; `/resumen` y el DELETE, que tocan las horas de OTROS, con
// `auth:asistencia`. `/marcar` mira el permiso DENTRO del cuerpo para el caso
// de corregir a otra persona.
//
// El kiosco —`/roster`, `/marcar-kiosco`— NO lleva JWT: es el tablet compartido
// de recepción, sin sesión, y se identifica con el token de dispositivo
// (`X-Fichaje-Token`, middleware `fichaje`). Sin `FICHAJE_DEVICE_TOKEN` en el
// entorno el kiosco está apagado y las dos rutas responden 401.
//
// Las literales van antes de `{marcaje}` para que ninguna se lea como id.
Route::post('/asistencia/marcar', [AsistenciaController::class, 'marcar'])
    ->middleware('auth:staff');
Route::get('/asistencia/mi-estado', [AsistenciaController::class, 'miEstado'])
    ->middleware('auth:staff');
Route::get('/asistencia/mias', [AsistenciaController::class, 'mias'])
    ->middleware('auth:staff');
Route::get('/asistencia/resumen', [AsistenciaController::class, 'resumen'])
    ->middleware('auth:asistencia');
Route::get('/asistencia/roster', [AsistenciaController::class, 'roster'])
    ->middleware('fichaje');
// Vista previa del kiosco DENTRO del panel, para configurarlo sin tablet ni
// FICHAJE_DEVICE_TOKEN a mano: mismo método `roster()` que usa el tablet real
// —mismos datos, mismo estado—, pero autenticado con el staff que ya puede
// ver las horas del equipo. No hay un `marcar-kiosco` equivalente: la vista
// previa no ficha a nadie, solo muestra cómo se ve.
Route::get('/asistencia/vista-previa', [AsistenciaController::class, 'roster'])
    ->middleware('auth:asistencia');
Route::post('/asistencia/marcar-kiosco', [AsistenciaController::class, 'marcarKiosco'])
    ->middleware('fichaje');
Route::delete('/asistencia/{marcaje}', [AsistenciaController::class, 'eliminar'])
    ->middleware('auth:asistencia');

// Tareas internas del equipo.
Route::get('/tareas', [TareasController::class, 'listar'])->middleware('auth:tareas');
Route::post('/tareas', [TareasController::class, 'crear'])->middleware('auth:tareas');
Route::patch('/tareas/{id}', [TareasController::class, 'actualizar'])->middleware('auth:tareas');
Route::get('/tareas-recurrentes', [TareasRecurrentesController::class, 'listar'])->middleware('auth:tareas');
Route::post('/tareas-recurrentes', [TareasRecurrentesController::class, 'crear'])->middleware('auth:tareas');
Route::patch('/tareas-recurrentes/{id}', [TareasRecurrentesController::class, 'actualizar'])->middleware('auth:tareas');
Route::delete('/tareas-recurrentes/{id}', [TareasRecurrentesController::class, 'eliminar'])->middleware('auth:tareas');
Route::delete('/tareas/{id}', [TareasController::class, 'eliminar'])->middleware('auth:tareas');

// Recordatorios clínicos (medicina preventiva).
//
// `/mios` y `/resumen` van ANTES de `/{id}` — y `/mios` es la excepción de
// permiso del módulo: lleva `auth` a secas porque es lo que el DUEÑO ve en su
// portal, no el staff. El endpoint filtra por su propio usuario.
Route::get('/recordatorios/resumen', [RecordatoriosController::class, 'resumen'])
    ->middleware('auth:recordatorios');
Route::get('/recordatorios/mios', [RecordatoriosController::class, 'mios'])
    ->middleware('auth');
// Paridad con ACVet (oct-2026): plantillas, vista previa, envío masivo y el
// conteo por canal del panel de Mensajes. Todo ANTES de `/{id}`.
Route::get('/recordatorios/plantillas', [PlantillasRecordatorioController::class, 'listar'])
    ->middleware('auth:recordatorios');
Route::post('/recordatorios/plantillas', [PlantillasRecordatorioController::class, 'crear'])
    ->middleware('auth:recordatorios');
Route::patch('/recordatorios/plantillas/{id}', [PlantillasRecordatorioController::class, 'actualizar'])
    ->middleware('auth:recordatorios');
Route::delete('/recordatorios/plantillas/{id}', [PlantillasRecordatorioController::class, 'eliminar'])
    ->middleware('auth:recordatorios');
Route::get('/recordatorios/variables', [PlantillasRecordatorioController::class, 'variables'])
    ->middleware('auth:recordatorios');
Route::post('/recordatorios/vista-previa', [PlantillasRecordatorioController::class, 'vistaPrevia'])
    ->middleware('auth:recordatorios');
Route::post('/recordatorios/envio-masivo', [RecordatoriosController::class, 'envioMasivo'])
    ->middleware('auth:recordatorios');
Route::get('/recordatorios/resumen-envios', [RecordatoriosController::class, 'resumenEnvios'])
    ->middleware('auth:recordatorios');
Route::get('/recordatorios', [RecordatoriosController::class, 'listar'])
    ->middleware('auth:recordatorios');
Route::post('/recordatorios', [RecordatoriosController::class, 'crear'])
    ->middleware('auth:recordatorios');
Route::patch('/recordatorios/{id}', [RecordatoriosController::class, 'actualizar'])
    ->middleware('auth:recordatorios');
Route::delete('/recordatorios/{id}', [RecordatoriosController::class, 'eliminar'])
    ->middleware('auth:recordatorios');

// Equipo. LISTAR es de cualquier staff; todo lo demás SOLO ADMIN, y a
// propósito fuera del sistema de permisos granular: gestionar el equipo
// —incluido a quién se le dan permisos— es atribución exclusiva del admin.
Route::get('/staff', [StaffController::class, 'listar'])->middleware('auth:staff');
Route::post('/staff', [StaffController::class, 'crear'])->middleware('auth:admin');
Route::patch('/staff/{id}', [StaffController::class, 'editar'])->middleware('auth:admin');
Route::post('/staff/{id}/reset-password', [StaffController::class, 'resetPassword'])
    ->middleware('auth:admin');
Route::delete('/staff/{id}', [StaffController::class, 'eliminar'])->middleware('auth:admin');

// Protocolos preventivos. DOS permisos distintos: las REGLAS son configuración
// (`config_protocolos`), pero el estado y las aplicaciones de un paciente son
// trabajo clínico (`personas`).
Route::get('/protocolos', [ProtocolosController::class, 'listar'])
    ->middleware('auth:config_protocolos');
Route::post('/protocolos', [ProtocolosController::class, 'crear'])
    ->middleware('auth:config_protocolos');
Route::get('/protocolos/mascota/{id}/estado', [ProtocolosController::class, 'estadoPorMascota'])
    ->middleware('auth:personas');
Route::post('/protocolos/mascota/{id}/aplicaciones', [ProtocolosController::class, 'registrarAplicacion'])
    ->middleware('auth:personas');
Route::patch('/protocolos/{id}', [ProtocolosController::class, 'actualizar'])
    ->middleware('auth:config_protocolos');
Route::delete('/protocolos/{id}', [ProtocolosController::class, 'eliminar'])
    ->middleware('auth:config_protocolos');

// Auditoría: SOLO LECTURA. No hay endpoint para editar ni borrar una fila, ni
// siquiera para admin — un registro que su propio sujeto puede modificar no
// sirve para auditar nada. `/areas` antes que nada por claridad.
Route::get('/auditoria/areas', [AuditoriaController::class, 'areas'])
    ->middleware('auth:auditoria');
Route::get('/auditoria', [AuditoriaController::class, 'listar'])
    ->middleware('auth:auditoria');

// Buscador global: `auth` a secas, porque el permiso se decide POR CATEGORÍA
// dentro del endpoint. Un veterinario limitado a Agenda no encuentra clientes.
// SOLO PARA EL ARNÉS: reinicia los contadores de intentos. El límite por IP
// es correcto en los dos backends, pero una tanda de pruebas lo agota en la
// primera decena de casos y convierte todo lo demás en 429 — que es un rojo
// que no corresponde a ningún bug. Se monta únicamente con ARNES_EFECTOS=1,
// así que en producción esta ruta no existe.
if (env('ARNES_EFECTOS') === '1') {
    Route::post('/__arnes/reiniciar-intentos', function () {
        \Illuminate\Support\Facades\Cache::flush();

        return ['ok' => true];
    });

    // A DÓNDE apunta este proceso para cobrar. El modo C reemplaza Getnet por
    // un recolector local, y necesita poder COMPROBAR que Laravel lo está
    // usando en vez de salir a internet — no deducirlo.
    //
    // No es paranoia: `artisan serve` filtra las variables de entorno que le
    // pasa al proceso hijo (`$passthroughVariables`), así que `GETNET_BASE_URL`
    // no llegaba y Laravel usaba la del `.env`, o sea el sandbox REAL, con las
    // credenciales de la clínica. Se descubrió porque el recolector anotó un
    // pedido en vez de dos — después de que la llamada ya había salido.
    //
    // Devuelve la URL, NUNCA la clave.
    Route::get('/__arnes/getnet', fn () => [
        'base_url' => (string) env('GETNET_BASE_URL'),
        'login' => (string) env('GETNET_LOGIN'),
        'tiene_clave' => (bool) env('GETNET_SECRET_KEY'),
    ]);
}

// Mascotas. `/` es del DUEÑO (solo las suyas) y `/todas` del staff con
// permiso `personas`. `/todas` va antes que cualquier `{id}`.
Route::get('/mascotas/todas', [MascotasController::class, 'todas'])
    ->middleware('auth:personas');
Route::get('/mascotas/', [MascotasController::class, 'mias'])->middleware('auth');
Route::get('/mascotas', [MascotasController::class, 'mias'])->middleware('auth');
Route::post('/mascotas/', [MascotasController::class, 'crear'])->middleware('auth');
Route::post('/mascotas', [MascotasController::class, 'crear'])->middleware('auth');
Route::patch('/mascotas/{id}', [MascotasController::class, 'actualizar'])->middleware('auth');
// Borra solo la mascota propia y sin historial; el resto es de staff, por
// `/clientes/mascotas/{id}`, que audita y pide el nombre exacto.
Route::delete('/mascotas/{id}', [MascotasController::class, 'eliminarMia'])->middleware('auth');
// Foto de perfil: el dueño la de su mascota, el staff con `personas` cualquiera
// (la comprobación va adentro, como en el PATCH).
Route::post('/mascotas/{id}/foto', [MascotasController::class, 'subirFoto'])->middleware('auth');
Route::delete('/mascotas/{id}/foto', [MascotasController::class, 'quitarFoto'])->middleware('auth');
// Vacunas y desparasitaciones para la ficha del portal: mismo par de
// audiencias que la foto (dueño la suya, staff con `personas`).
Route::get('/mascotas/{id}/preventivo', [ProtocolosController::class, 'delTutor'])->middleware('auth');

// ── Agente de WhatsApp (backend-to-backend) ─────────────────────────────
// Clave de servicio, NUNCA JWT: el bot no tiene la contraseña de nadie. Actúa
// en nombre de un usuario ya resuelto por su teléfono, y cada endpoint filtra
// por el `usuario_id` que recibe — no expone nada que ese dueño no pudiera ver
// ya en su portal.
// El prefijo es `/internal/whatsapp`, no `/internal`: así lo monta `main.py`.
// Con el prefijo corto, las tres rutas de lectura respondían 200 en Laravel y
// 404 en Python — y ese verde habría sido una ruta que el bot nunca llama.
Route::middleware('servicio')->prefix('internal/whatsapp')->group(function () {
    Route::post('/resolve-user', [InternalController::class, 'resolveUser']);
    Route::post('/resolve-user-by-email', [InternalController::class, 'resolveUserByEmail']);
    Route::get('/catalogo', [InternalController::class, 'catalogo']);
    Route::get('/slots', [InternalController::class, 'slots']);
    Route::post('/citas', [InternalController::class, 'crearCita']);
    Route::get('/citas', [InternalController::class, 'citas']);
    Route::patch('/citas/{id}/cancelar', [InternalController::class, 'cancelarCita']);
    Route::get('/mascotas', [InternalController::class, 'mascotas']);
    Route::get('/pagos', [InternalController::class, 'pagos']);
});

// Google Calendar de la clínica. `/callback` es PÚBLICO —lo abre el navegador
// tras el consentimiento— y su autenticidad depende del `state` firmado, no de
// una sesión. Desconectar es solo de admin: afecta a todos los especialistas.
Route::get('/google/status', [GoogleController::class, 'status'])->middleware('auth:config_especialistas');
Route::get('/google/auth-url', [GoogleController::class, 'authUrl'])->middleware('auth:config_especialistas');
Route::get('/google/callback', [GoogleController::class, 'callback']);
Route::get('/google/calendars', [GoogleController::class, 'calendars'])->middleware('auth:config_especialistas');
Route::put('/google/calendario-reservas', [GoogleController::class, 'elegirCalendario'])
    ->middleware('auth:config_especialistas');
// Calendario de reservas PROPIO de un especialista, en la misma cuenta.
Route::post('/google/calendario-especialista/{id}', [GoogleController::class, 'crearCalendarioEspecialista'])
    ->middleware('auth:config_especialistas');
// SOLO sysop: soltar la cuenta corta Calendar y la sincronización de todos los
// especialistas a la vez, y la clínica casi nunca lo necesita.
Route::delete('/google/desconectar', [GoogleController::class, 'desconectar'])
    ->middleware('auth:sysop');

// Webhook de WhatsApp. Los dos son PÚBLICOS por necesidad —los llama Meta— y
// cada uno tiene su propia autenticación: el GET compara el verify token, el
// POST valida la firma HMAC del cuerpo crudo.
Route::get('/whatsapp/webhook', [WhatsappController::class, 'verificar']);
Route::post('/whatsapp/webhook', [WhatsappController::class, 'recibir']);

// Asistente conversacional. `/mensaje` acepta sesión OPCIONAL: un anónimo puede
// agendar identificándose por correo, pero no acceder a datos personales. Con
// sesión del portal, el asistente gestiona las horas de ESA cuenta
// (`auth:opcional`, ver Autenticar).
Route::post('/chatbot/mensaje', [ChatbotController::class, 'mensaje'])
    ->middleware('auth:opcional');
Route::get('/chatbot/admin/conversaciones', [ChatbotController::class, 'conversaciones'])
    ->middleware('auth');
// Detalle de una conversación (mensajes completos) para depurar/analizar desde
// el panel. Mismo criterio de acceso que el listado: admin, no solo staff.
Route::get('/chatbot/admin/conversaciones/{id}', [ChatbotController::class, 'conversacion'])
    ->middleware('auth');
// Lo que el chat no supo contestar (sin datos personales). Solo admin, igual que
// las conversaciones: el middleware pide sesión y el controlador exige el rol.
Route::get('/chatbot/preguntas', [ChatbotController::class, 'preguntas'])
    ->middleware('auth');
// Vista previa del modal «Enseñarle»: no guarda nada. Solo admin, mismo criterio.
Route::post('/chatbot/probar', [ChatbotController::class, 'probar'])
    ->middleware('auth');

// Cobro con Getnet. TODO este bloque es público y tiene que serlo: quien
// reserva sin cuenta no tiene token, y el cita_id es un UUID v4 que solo conoce
// quien acaba de reservar. Lo que se expone va recortado (correo enmascarado,
// sin teléfono ni dirección). El webhook no depende de eso: valida firma.
Route::get('/pagos/config', [PagosController::class, 'config']);
// «Pagos y facturas» del portal del tutor: solo lo suyo, recortado (ver el
// controlador). Va antes de `/pagos/cita/{id}` por legibilidad; no colisiona.
Route::get('/pagos/mios', [MisPagosController::class, 'mios'])->middleware('auth');
Route::get('/pagos/cita/{id}', [PagosController::class, 'estado']);
Route::post('/pagos/cita/{id}/sesion', [PagosController::class, 'crearSesion']);
Route::post('/pagos/notificacion', [PagosController::class, 'notificacion']);

// Login y perfil propio. El token que emite este endpoint DEBE ser
// intercambiable con el de FastAPI: los dos backends conviven durante la
// migración y una sesión abierta en uno tiene que valer en el otro.
// Registro y recuperación. Los cuatro son PÚBLICOS y ninguno revela si un
// correo existe: responden igual exista o no la cuenta, para que nadie pueda
// enumerar qué correos son clientes de la clínica probando uno por uno.
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/confirmar', [AuthController::class, 'confirmar']);
Route::post('/auth/reenviar-confirmacion', [AuthController::class, 'reenviarConfirmacion']);
Route::post('/auth/solicitar-reset', [AuthController::class, 'solicitarReset']);
Route::post('/auth/restablecer', [AuthController::class, 'restablecer']);
Route::post('/auth/login', [AuthController::class, 'login']);
// «Sign in with Google» para CLIENTES. Sin token: el ID token que llega en el
// cuerpo se verifica contra Google dentro del endpoint.
Route::post('/auth/google', [AuthController::class, 'loginConGoogle']);
// Y para el EQUIPO, aparte: solo cuentas del equipo que ya existen.
Route::post('/auth/google/equipo', [AuthController::class, 'loginEquipoConGoogle']);
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth');
Route::patch('/auth/me', [AuthController::class, 'actualizarMe'])->middleware('auth');
Route::post('/auth/me/foto', [AuthController::class, 'subirFoto'])->middleware('auth');
Route::delete('/auth/me/foto', [AuthController::class, 'quitarFoto'])->middleware('auth');
Route::post('/auth/aceptar-privacidad', [AuthController::class, 'aceptarPrivacidad'])
    ->middleware('auth');

// Derechos del titular (ley 21.719). `auth` a secas: son datos de quien
// pregunta, no de un área del panel. Acceso y portabilidad salen por
// `mis-datos`; supresión, oposición y bloqueo abren una solicitud.
Route::get('/auth/mis-datos', [AuthController::class, 'misDatos'])->middleware('auth');
Route::post('/auth/solicitudes', [AuthController::class, 'crearSolicitud'])
    ->middleware('auth');

Route::get('/buscar', [BuscarController::class, 'buscar'])->middleware('auth');

// Catálogo — lecturas. Las PÚBLICAS (sin token) alimentan el sitio de
// marketing y el portal de reservas; las `/todos` son del panel e incluyen lo
// inactivo. `/especialistas/todos` es además el único listado que devuelve
// correo y teléfono del profesional (§9.13).
//
// `/slug/{slug}` y `/todos` van antes que `/{id}`, o la ruta con parámetro se
// los come.
Route::get('/catalogo/ajustes', [CatalogoController::class, 'ajustes']);
// Guardar es de ADMIN: acá se cambian los datos de la clínica y los textos
// que ve el público.
Route::put('/catalogo/ajustes', [CatalogoController::class, 'guardarAjustes'])
    ->middleware('auth:admin');
// El asistente se sirve APARTE del resto de ajustes y con sesión: ahí van las
// instrucciones que gobiernan al modelo. Ver es de cualquier staff; guardar,
// solo admin.
Route::get('/catalogo/asistente', [CatalogoController::class, 'verAsistente'])
    ->middleware('auth:staff');
Route::put('/catalogo/asistente', [CatalogoController::class, 'guardarAsistente'])
    ->middleware('auth:admin');
// Config → Estado del sistema: WhatsApp, Google y la IA, y a quién le llegan
// las alertas. Solo admin (muestra la cuenta de Google y el gasto de la IA).
Route::get('/estado-sistema', [EstadoSistemaController::class, 'ver'])->middleware('auth:admin');
Route::put('/estado-sistema/alertas', [EstadoSistemaController::class, 'guardarAlertas'])->middleware('auth:admin');
// Configuración operativa (recordatorios, ventas, impresión): leer, cualquier
// staff —el modal de venta tiene que conocer las reglas antes de cobrar—;
// guardar lo decide el controlador por sección.
Route::get('/configuracion/{seccion}', [ConfiguracionController::class, 'ver'])
    ->middleware('auth:staff');
Route::put('/configuracion/{seccion}', [ConfiguracionController::class, 'guardar'])
    ->middleware('auth:staff');
Route::get('/catalogo/form-fields', [CatalogoController::class, 'formFields']);
Route::get('/catalogo/form-fields/todos', [CatalogoController::class, 'formFieldsTodos'])
    ->middleware('auth:staff');
Route::get('/catalogo/equipo', [CatalogoController::class, 'equipo'])
    ->middleware('auth:staff');
Route::get('/catalogo/servicios/todos', [CatalogoController::class, 'serviciosTodos'])
    ->middleware('auth:staff');
Route::get('/catalogo/especialistas/todos', [CatalogoController::class, 'especialistasTodos'])
    ->middleware('auth:staff');
Route::get('/catalogo/especialistas/slug/{slug}', [CatalogoController::class, 'especialistaPorSlug']);
// El detalle va DESPUÉS de `/todos` y `/slug/{slug}`, por lo mismo de arriba.
Route::get('/catalogo/especialistas/{id}', [CatalogoController::class, 'verEspecialista'])
    ->middleware('auth:staff');

// Catálogo — escrituras. Tres permisos distintos, uno por área de Ajustes.
// Son PUT y no PATCH: los schemas no usan `exclude_unset`, así que un campo
// ausente vuelve a su default. El panel manda el objeto entero.
Route::post('/catalogo/servicios', [CatalogoController::class, 'crearServicio'])
    ->middleware('auth:config_consultas');
Route::put('/catalogo/servicios/{id}', [CatalogoController::class, 'editarServicio'])
    ->middleware('auth:config_consultas');
Route::delete('/catalogo/servicios/{id}', [CatalogoController::class, 'eliminarServicio'])
    ->middleware('auth:config_consultas');

Route::post('/catalogo/especialistas', [CatalogoController::class, 'crearEspecialista'])
    ->middleware('auth:config_especialistas');
Route::put('/catalogo/especialistas/{id}', [CatalogoController::class, 'editarEspecialista'])
    ->middleware('auth:config_especialistas');
Route::delete('/catalogo/especialistas/{id}', [CatalogoController::class, 'eliminarEspecialista'])
    ->middleware('auth:config_especialistas');
// `/impacto` es de cualquier staff (solo cuenta); la foto exige el permiso.
Route::get('/catalogo/especialistas/{id}/impacto', [CatalogoController::class, 'impactoEspecialista'])
    ->middleware('auth:staff');
// La imagen del tipo de consulta. Mismo permiso que editar el servicio
// (`config_consultas`), no el de especialistas: son dos cosas distintas y quien
// administra el catálogo no necesariamente administra al equipo.
Route::post('/catalogo/servicios/{id}/imagen', [CatalogoController::class, 'subirImagenServicio'])
    ->middleware('auth:config_consultas');
Route::delete('/catalogo/servicios/{id}/imagen', [CatalogoController::class, 'quitarImagenServicio'])
    ->middleware('auth:config_consultas');

Route::post('/catalogo/especialistas/{id}/foto', [CatalogoController::class, 'subirFoto'])
    ->middleware('auth:config_especialistas');
Route::delete('/catalogo/especialistas/{id}/foto', [CatalogoController::class, 'quitarFoto'])
    ->middleware('auth:config_especialistas');

Route::post('/catalogo/form-fields', [CatalogoController::class, 'crearCampo'])
    ->middleware('auth:config_campos');
Route::put('/catalogo/form-fields/{id}', [CatalogoController::class, 'editarCampo'])
    ->middleware('auth:config_campos');
Route::delete('/catalogo/form-fields/{id}', [CatalogoController::class, 'eliminarCampo'])
    ->middleware('auth:config_campos');

Route::get('/inventario', [InventarioController::class, 'listar'])
    ->middleware('auth:inventario');

// `/categorias` antes que `/{id}`, o la ruta con parámetro se lo come.
Route::get('/transacciones/categorias', [TransaccionesController::class, 'categorias'])
    ->middleware('auth:ventas');
Route::get('/transacciones', [TransaccionesController::class, 'listar'])
    ->middleware('auth:ventas');
Route::get('/transacciones/puntos/{usuarioId}', [TransaccionesController::class, 'puntos'])
    ->middleware('auth:ventas');
Route::post('/transacciones', [TransaccionesController::class, 'crear'])
    ->middleware('auth:ventas');
Route::get('/transacciones/resumen', [TransaccionesController::class, 'resumen'])
    ->middleware('auth:ventas');
Route::get('/transacciones/lineas', [TransaccionesController::class, 'lineas'])
    ->middleware('auth:ventas');
Route::get('/transacciones/{id}', [TransaccionesController::class, 'ver'])
    ->middleware('auth:ventas');
Route::patch('/transacciones/{id}/pago', [TransaccionesController::class, 'cambiarPago'])
    ->middleware('auth:ventas');
Route::post('/transacciones/{id}/confirmar', [TransaccionesController::class, 'confirmar'])
    ->middleware('auth:ventas');
Route::post('/transacciones/{id}/devolucion', [TransaccionesController::class, 'devolucion'])
    ->middleware('auth:ventas');
// Anular y eliminar son de ADMIN, no de `ventas`: la primera reescribe el
// cuadre del turno y la segunda destruye una fila.
Route::post('/transacciones/{id}/anular', [TransaccionesController::class, 'anular'])
    ->middleware('auth:admin');
Route::delete('/transacciones/{id}', [TransaccionesController::class, 'eliminar'])
    ->middleware('auth:admin');

Route::get('/caja', [CajaController::class, 'estado'])->middleware('auth:caja');
Route::post('/caja/abrir', [CajaController::class, 'abrir'])->middleware('auth:caja');
Route::post('/caja/cerrar', [CajaController::class, 'cerrar'])->middleware('auth:caja');
Route::post('/caja/cambio-turno', [CajaController::class, 'cambioTurno'])->middleware('auth:caja');
Route::get('/caja/transacciones', [CajaController::class, 'transaccionesDelDia'])
    ->middleware('auth:caja');
Route::get('/caja/egresos', [CajaController::class, 'egresos'])->middleware('auth:caja');
Route::get('/caja/sesiones', [CajaController::class, 'sesiones'])->middleware('auth:caja');
Route::post('/caja/movimientos', [CajaController::class, 'crearMovimiento'])
    ->middleware('auth:caja');
Route::delete('/caja/movimientos/{id}', [CajaController::class, 'eliminarMovimiento'])
    ->middleware('auth:caja');
// Respaldo de datos: SOLO admin. Devuelve la base entera en un ZIP, así que
// quien pueda pedirlo se lleva las fichas clínicas de todos los pacientes.
Route::get('/exportacion/completa', [ExportacionController::class, 'completa'])
    ->middleware('auth:admin');
// Datasets sueltos y paginados (paridad con ACVet): mismo nivel que el ZIP,
// solo admin — es la misma base, de a pedazos.
Route::get('/exportacion/datasets', [ExportacionController::class, 'datasets'])
    ->middleware('auth:admin');
Route::get('/exportacion/dataset/{clave}', [ExportacionController::class, 'dataset'])
    ->middleware('auth:admin');
// Importador de los CSV de ACVet, espejo del export: SOLO sysop (escribe fichas,
// ventas y datos personales de los tutores). Era `auth:admin`; se sube un nivel
// porque solo se usa en una migración o una restauración, y un admin de la
// clínica no tiene motivo para llegar acá.
Route::get('/importacion/tipos', [ImportacionController::class, 'tipos'])
    ->middleware('auth:sysop');
Route::post('/importacion/{tipo}', [ImportacionController::class, 'lote'])
    ->middleware('auth:sysop');

// Ajustes → Correos: cada correo del sistema armado con su código real y datos
// de ejemplo. No envía nada (CorreoCaptura) y no lee datos de clientes.
Route::get('/ajustes/correos', [CorreosController::class, 'vistaPrevia'])->middleware('auth:staff');
Route::get('/ajustes/correos/paleta', [CorreosController::class, 'paleta'])->middleware('auth:staff');

// Sysop → Tareas programadas: corridas de los crons y «Ejecutar ahora» para las
// tareas cortas que no le escriben a clientes (§13.17: una tarea que no corre
// tiene que verse desde el panel).
Route::get('/sysop/tareas', [SysopController::class, 'tareas'])->middleware('auth:sysop');
Route::get('/sysop/tareas/{tarea}/corridas', [SysopController::class, 'corridas'])
    ->where('tarea', '[a-z:]+')->middleware('auth:sysop');
Route::post('/sysop/tareas/{tarea}/ejecutar', [SysopController::class, 'ejecutar'])
    ->where('tarea', '[a-z:]+')->middleware('auth:sysop');

Route::get('/estadisticas/resumen', [EstadisticasController::class, 'resumen'])
    ->middleware('auth:estadisticas');

// Fichas: `auth` SIN área — cualquier staff ve cualquier ficha, y un dueño solo
// las de sus mascotas. El portal del cliente usa estas mismas rutas.
Route::get('/fichas/mascota/{id}', [FichasController::class, 'verFicha'])->middleware('auth');
Route::get('/fichas/mascota/{id}/paciente', [FichasController::class, 'verPaciente'])
    ->middleware('auth');

// El historial: citas y compras de la mascota, en dos listas que NO se cruzan
// —no hay vínculo en la base entre una cita y la transacción que la cobró.
Route::get('/fichas/mascota/{id}/historial', [FichasController::class, 'historial'])
    ->middleware('auth');
// Notas clínicas. Crear y editar: cualquier staff con permiso `personas`.
// Borrar: solo admin — es definitivo.
Route::post('/fichas/mascota/{id}/notas', [FichasController::class, 'crearNota'])
    ->middleware('auth:personas');
Route::patch('/fichas/notas/{id}', [FichasController::class, 'editarNota'])
    ->middleware('auth:personas');
Route::delete('/fichas/notas/{id}', [FichasController::class, 'eliminarNota'])
    ->middleware('auth:admin');

// Datos permanentes de la ficha. Vaciarla es DEFINITIVO (borra notas y
// documentos, y los archivos del disco): solo admin, y pide el nombre exacto
// del paciente en `?confirmar=`.
Route::patch('/fichas/mascota/{id}', [FichasController::class, 'actualizarFicha'])
    ->middleware('auth:personas');
Route::delete('/fichas/mascota/{id}', [FichasController::class, 'vaciarFicha'])
    ->middleware('auth:admin');

// Documentos clínicos. SUBIR y BORRAR: staff con permiso `personas`.
// DESCARGAR: `auth` a secas — el permiso lo decide `puedeVer()`, porque el
// tutor baja los documentos de SUS mascotas desde el portal. El archivo no se
// sirve estático: esta ruta es la única puerta (§9.16).
Route::post('/fichas/mascota/{id}/documentos', [FichasController::class, 'subirDocumento'])
    ->middleware('auth:personas');
Route::get('/fichas/documentos/{id}/archivo', [FichasController::class, 'descargarDocumento'])
    ->middleware('auth');
Route::delete('/fichas/documentos/{id}', [FichasController::class, 'eliminarDocumento'])
    ->middleware('auth:personas');
// Los documentos de todas las mascotas de quien pregunta (portal del cliente).
// `auth` a secas: filtra por el usuario del token, no por un área del panel.
Route::get('/fichas/mis-documentos', [FichasController::class, 'misDocumentos'])
    ->middleware('auth');

// `/health` responde sin tocar la base, igual que en FastAPI: es la sonda que
// mira Railway/Hostinger y no debe caerse si la base está lenta.
Route::get('/health', fn () => ['status' => 'ok']);
