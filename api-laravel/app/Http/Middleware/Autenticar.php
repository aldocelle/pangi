<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Permisos;
use App\Services\Sesiones;
use App\Services\Sysop;

/**
 * Valida el JWT del header `Authorization: Bearer …` y deja el usuario en la
 * petición.
 *
 * **El token lo firma el backend Python y lo verifica este** (y al revés)
 * durante toda la migración: mientras conviven, una persona con sesión abierta
 * en el panel tiene que seguir trabajando si el DNS cambia de backend a mitad
 * del día. Eso exige tres cosas idénticas y no negociables:
 *
 *   · el mismo `SECRET_KEY`,
 *   · el mismo algoritmo (HS256),
 *   · el mismo payload: `{sub: email, rol, user_id, exp}`.
 *
 * Si alguna difiere, los tokens emitidos por un lado no valen en el otro y
 * todo el staff queda deslogueado en el corte — con la clínica atendiendo.
 */
class Autenticar
{
    public function handle(Request $request, Closure $next, ?string $area = null)
    {
        // `auth:opcional` — la sesión se aprovecha si viene y es válida, y si no
        // la petición sigue como anónima. Lo usa `/chatbot/mensaje`, que es
        // público (se puede reservar sin cuenta) pero que con sesión puede
        // gestionar las horas PROPIAS. Reusa `handle()` entero —las mismas
        // comprobaciones: firma, cuenta activa, versión de sesión— en vez de
        // copiarlas: una copia se queda atrás el día que se agregue una más.
        //
        // Un token vencido o revocado NO da 401 acá: un chat que se rompe
        // porque la sesión del portal caducó es peor que uno que atiende como
        // anónimo. Y no hay manera de que una sesión inválida deje `usuario`
        // puesto: `handle()` solo lo escribe cuando ya pasó todas las guardas.
        if ($area === 'opcional') {
            $this->handle($request, fn () => response('', 204));

            return $next($request);
        }

        $cabecera = $request->header('Authorization', '');
        if (! str_starts_with($cabecera, 'Bearer ')) {
            // 401 con este cuerpo exacto: es el de FastAPI cuando falta el
            // esquema Bearer, y los frontends distinguen 401 (reloguear) de
            // 403 (no tienes permiso).
            return response()->json(['detail' => 'Not authenticated'], 401);
        }

        try {
            $payload = JWT::decode(
                substr($cabecera, 7),
                new Key(config('app.jwt_secret'), 'HS256')
            );
        } catch (\Throwable $e) {
            // Token vencido, firma inválida o basura: todo es 401. No se
            // distingue el motivo a propósito — decirle a quien prueba tokens
            // si la firma era válida le regala información.
            return response()->json(['detail' => 'Token inválido o expirado'], 401);
        }

        $usuario = DB::table('usuarios')->where('id', $payload->user_id ?? '')->first();
        if (! $usuario) {
            // El token es válido pero la cuenta ya no existe: se borró
            // mientras la sesión estaba abierta.
            return response()->json(['detail' => 'Usuario no encontrado'], 401);
        }

        // **Desactivar a alguien corta el acceso YA**, no cuando se le venza el
        // token (12 h). Va acá y no en la comprobación de permisos porque este
        // es el único punto por el que pasan TODAS las rutas autenticadas,
        // incluido el portal del cliente.
        //
        // 401 y no 403: el frontend ya trata el 401 como «sesión terminada» y
        // manda a la pantalla de ingreso, que es lo que corresponde.
        //
        // `!= 1` y no `=== 0`: la columna es nullable y en la base hay filas
        // con NULL. Un NULL NO es una cuenta activa — Python usa
        // `if not usuario.es_activo`, que trata None como falso. Comparar solo
        // contra 0 dejaría entrar a esas cuentas.
        if ((int) ($usuario->es_activo ?? 0) !== 1) {
            return response()->json(['detail' => 'Esta cuenta está desactivada.'], 401);
        }

        // Token firmado antes de un cambio de clave o una desactivación. Uno
        // sin `sv` cuenta como 0: los emitidos antes de esta guarda siguen
        // valiendo mientras nadie haya revocado esa cuenta.
        if ((int) ($payload->sv ?? 0) !== Sesiones::version($usuario)) {
            return response()->json(['detail' => 'Token inválido o expirado'], 401);
        }

        $request->attributes->set('usuario', $usuario);

        // `auth:admin` NO es un área: es el port de `require_admin`, que exige
        // el rol y nada más. Va aparte porque su mensaje es OTRO («Solo un
        // administrador puede eliminar registros») y porque buscar 'admin' en
        // AREAS reventaría con índice indefinido. Lo usan los borrados
        // definitivos: una nota clínica, una venta.
        // `auth:staff` es el port de `require_staff`: exige ser del equipo
        // —veterinario o admin— sin pedir ningún área. Lo usa `GET /staff`,
        // porque cualquiera del equipo puede ver quién es quién, aunque solo
        // un admin pueda tocarlo.
        if ($area === 'staff') {
            if (! in_array($usuario->rol, ['veterinario', 'admin'], true)) {
                return response()->json(['detail' => 'Requiere una cuenta de staff'], 403);
            }
            return $next($request);
        }

        if ($area === 'admin') {
            if ($usuario->rol !== 'admin') {
                return response()->json(
                    ['detail' => 'Solo un administrador puede eliminar registros'], 403);
            }
            return $next($request);
        }

        // `auth:sysop`: un admin cuyo correo está en `SYSOP_EMAILS`. Va aparte de
        // `admin` porque la clínica puede tener admins propios, y lo que cuelga
        // de acá (importar datos, desconectar Google) no es para ellos.
        if ($area === 'sysop') {
            if (! Sysop::es($usuario)) {
                return response()->json(['detail' => 'Solo soporte técnico puede hacer esto'], 403);
            }
            return $next($request);
        }

        if ($area !== null && ! $this->tienePermiso($usuario, $area)) {
            return response()->json([
                'detail' => $usuario->rol === 'dueño'
                    ? 'Requiere una cuenta de staff'
                    : "No tienes acceso a «" . self::AREAS[$area] . "». Pídeselo a un administrador.",
            ], 403);
        }

        return $next($request);
    }

    /**
     * ¿Puede este usuario entrar al área?
     *
     * Un `admin` siempre pasa. Un `veterinario` pasa si el área está en su
     * lista. **`permisos` en NULL significa acceso total SALVO auditoría** —
     * así las cuentas viejas y las nuevas sin restringir conservan lo
     * operativo, y `auditoria` se otorga a mano.
     *
     * Cualquier otro rol (un `dueño` con token válido) no es staff y no pasa.
     */
    private function tienePermiso($usuario, string $area): bool
    {
        if ($usuario->rol === 'admin') {
            return true;
        }
        if ($usuario->rol !== 'veterinario') {
            return false;
        }
        // Delega en el servicio: la regla vive en UN solo sitio. Antes estaba
        // acá y repetida a mano en cinco controladores como `rol in
        // (veterinario, admin)` —que no es la misma pregunta— y por eso el EMR
        // quedaba abierto a cualquier staff. Ver App\Services\Permisos.
        return Permisos::tiene($usuario, $area);
    }

    /**
     * El contrato de áreas con el frontend. Los mismos ids que usan el sidebar
     * y la pantalla de Equipo: agregar un área es tocar los dos lados.
     */
    public const AREAS = [
        'agenda' => 'Agenda del día',
        'reservas' => 'Reservas',
        'consultas_web' => 'Consultas desde el sitio web',
        'personas' => 'Tutores y pacientes',
        'caja' => 'Caja',
        'ventas' => 'Ventas',
        'inventario' => 'Inventario',
        'proveedores' => 'Proveedores',
        'compras' => 'Pedidos y compras',
        'tareas' => 'Tareas',
        // Área propia y no parte de «fichas» a propósito: llamar a los dueños
        // para que traigan a vacunar es trabajo de recepción, y no exige poder
        // leer el historial médico del animal.
        'recordatorios' => 'Recordatorios',
        'config_consultas' => 'Setup: tipo de consultas',
        'config_especialistas' => 'Setup: especialistas',
        'config_campos' => 'Setup: campos de formulario',
        'config_protocolos' => 'Setup: protocolos preventivos',
        'estadisticas' => 'Estadísticas',
        // Ver las horas de TODO el equipo. Marcar las propias NO necesita
        // permiso —cualquiera del staff ficha su entrada y su salida— y por
        // eso esa ruta lleva `auth:staff` y no `auth:asistencia`: exigir el
        // área dejaría sin poder fichar a quien no la tiene, que es lo
        // contrario de para lo que existe.
        'asistencia' => 'Asistencia del equipo',
        'auditoria' => 'Auditoría',
    ];
}
