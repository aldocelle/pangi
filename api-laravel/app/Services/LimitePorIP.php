<?php

namespace App\Services;

/**
 * El tope por IP de las rutas públicas, con la MISMA forma que en Python.
 *
 * POR QUÉ EXISTE ESTA CLASE Y NO DOS COPIAS
 *
 * El contacto y la orden de examen llevaban cada uno su copia, y las dos tenían
 * la misma divergencia con `app/services/rate_limit.py`: un contador entero con
 * TTL, en vez de una ventana deslizante de marcas de tiempo.
 *
 * La diferencia no es teórica. Con el contador, **cada intento aceptado empuja
 * el vencimiento otros 15 minutos**: alguien que sube una orden cada diez
 * minutos nunca deja que el contador expire, y a la duodécima queda bloqueado
 * aunque en Python haría rato que se le liberaron las primeras. Y al revés, los
 * doce se le liberan **de golpe** en vez de uno a uno. Python cuenta cuántos
 * intentos hubo en los últimos 15 minutos; esto contaba cuántos hubo desde que
 * el contador nació.
 *
 * Se vio corriendo el arnés: Python volvió a aceptar subidas y el servidor
 * siguió respondiendo 429 varios minutos más, con los mismos intentos.
 *
 * UN INTENTO RECHAZADO NO SE ANOTA
 *
 * Igual que en Python, y por una razón que este proyecto ya aprendió con la
 * cuota de conexiones de Hostinger: si el rechazo contara, sondear «¿ya puedo?»
 * empujaría el bloqueo hacia adelante y no se saldría nunca.
 */
class LimitePorIP
{
    /**
     * ¿`$clave` ya gastó su cupo de intentos en la ventana?
     *
     * La caché y no la memoria del proceso: en hosting compartido atienden
     * varios PHP y un contador en memoria no vería los intentos de los otros —
     * el tope sería N veces más alto de lo que dice. Python puede usar memoria
     * porque es un solo proceso.
     */
    public static function demasiados(string $clave, int $maxIntentos, int $ventanaSeg): bool
    {
        $ahora = microtime(true);

        $intentos = array_values(array_filter(
            (array) cache()->get($clave, []),
            static fn ($t) => is_numeric($t) && ($ahora - (float) $t) < $ventanaSeg
        ));

        $lleno = count($intentos) >= $maxIntentos;
        if (! $lleno) {
            $intentos[] = $ahora;
        }

        // El TTL es solo para que la caché se limpie sola: quien decide es la
        // lista de marcas, no el vencimiento de la entrada.
        cache()->put($clave, $intentos, $ventanaSeg);

        return $lleno;
    }
}
