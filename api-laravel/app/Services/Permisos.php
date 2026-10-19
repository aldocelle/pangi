<?php

namespace App\Services;

/**
 * La regla de permisos granulares, en UN solo sitio.
 *
 * POR QUÉ EXISTE ESTE SERVICIO Y NO ES SOLO UN MÉTODO DEL MIDDLEWARE
 *
 * `Autenticar` resuelve el caso fácil: una ruta entera pertenece a un área y
 * el portero decide antes de entrar. Pero hay rutas que **mezclan dos caminos
 * en la misma URL** —el staff ve cualquier ficha, el dueño solo la suya— y
 * ésas no las puede resolver un middleware: tienen que decidir dentro del
 * cuerpo, con la mascota ya en la mano.
 *
 * Ahí, en esos cinco lugares, la pregunta estaba mal hecha:
 *
 *     in_array($usuario->rol, ['veterinario', 'admin'], true)
 *
 * El rol, no el permiso. **No es la misma pregunta**: se salta la lista de
 * permisos entera. Con eso una cuenta de staff restringida a Caja abría la
 * ficha clínica completa de cualquier paciente, se bajaba sus radiografías y
 * leía el correo y el teléfono del tutor, aunque escribir en esa misma ficha
 * sí le estuviera vedado.
 *
 * Es el mismo arreglo que en Python (`tiene_permiso()` en `app/routes/auth.py`,
 * que usa también `require_permiso`), y va en el mismo orden: la regla en un
 * lugar, y los dos caminos —el middleware y el cuerpo— preguntándole a ella.
 * Si vuelven a divergir, un endpoint queda abierto sin que nada avise.
 *
 * Lo fija `migracion/arnes/modo_b_permisos_lectura.py`, que cuando se escribió
 * marcaba 12 de 30 en rojo contra este port, todos `Py=403 Lv=200`.
 */
class Permisos
{
    /**
     * El contrato de áreas con el frontend. Los mismos ids que usan el sidebar
     * y la pantalla de Equipo.
     */
    public const AREAS = [
        'agenda', 'reservas', 'consultas_web', 'personas', 'caja', 'ventas',
        'inventario', 'proveedores', 'compras', 'tareas', 'recordatorios',
        'config_consultas', 'config_especialistas', 'config_campos',
        'config_protocolos', 'estadisticas', 'asistencia', 'auditoria',
    ];

    /**
     * ¿Puede este usuario entrar al área?
     *
     * Un `admin` siempre pasa. Un `veterinario` pasa si el área está en su
     * lista. **`permisos` en NULL significa acceso total SALVO `auditoria`** —
     * así las cuentas viejas y las nuevas sin restringir conservan lo
     * operativo, y `auditoria` se otorga a mano. Leer ese NULL como «no puede
     * nada» dejaría a media clínica afuera: es el estado de casi todas.
     *
     * Cualquier otro rol (un `dueño` con token válido) no es staff y no pasa.
     */
    public static function tiene($usuario, string $area): bool
    {
        if ($usuario === null) {
            return false;
        }
        if ($usuario->rol === 'admin') {
            return true;
        }
        if ($usuario->rol !== 'veterinario') {
            return false;
        }

        $permisos = $usuario->permisos === null
            ? array_values(array_diff(self::AREAS, ['auditoria']))
            : (json_decode($usuario->permisos, true) ?: []);

        return in_array($area, $permisos, true);
    }

    /**
     * ¿Puede tocar la cita de cualquier cliente?
     *
     * Dos áreas y no una porque las dos pantallas que hacen esto son dos:
     * Reservas (`reservas`) y Agenda (`agenda`), y hay gente del equipo que
     * tiene una sola. Exigir una sola clave dejaría a media recepción sin
     * poder marcar una cita como completada.
     */
    public static function staffDeCitas($usuario): bool
    {
        return self::tiene($usuario, 'reservas') || self::tiene($usuario, 'agenda');
    }
}
