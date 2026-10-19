<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * La regla de cobro: cuánto se congela al reservar.
 *
 * Vive aparte porque la usan tres sitios —el catálogo (para marcar «derivar a
 * WhatsApp»), la reserva (que congela el monto) y /pagos (que lo cobra)— y si
 * cada uno la escribiera por su cuenta, el portal mostraría una cifra y se
 * cobraría otra.
 *
 * Puerto de `_precio_consulta`, `_monto_abono`, `_monto_total` y
 * `_estado_inicial` de `backend/app/routes/citas.py`.
 */
class CobroService
{
    /**
     * Valor completo de la consulta. null si no se cobra nada en línea.
     *
     * Manda la tarifa del SERVICIO cuando la tiene, y ese orden importa: la
     * tarifa del especialista es su valor de INTERCONSULTA. Con la prioridad
     * invertida, ponerle precio a la odontóloga cobraría sus $60.000 a
     * cualquiera que reservara una ecografía con ella.
     */
    public function precioConsulta(object $e, object $svc, ?CarbonImmutable $cuando = null): ?int
    {
        // Sábado: hay consultas que ese día valen distinto (Consulta General
        // $15.000 de lunes a viernes, $20.000 el sábado).
        if ($cuando !== null && $cuando->dayOfWeekIso === 6 && $svc->precio_sabado_clp) {
            return (int) $svc->precio_sabado_clp;
        }
        if ($svc->precio_clp) {
            return (int) $svc->precio_clp;
        }
        // Sin precio propio hay dos casos y los separa la bandera del servicio:
        // en Interconsultas manda la tarifa del especialista; en los de «valor
        // según evaluación» no se cobra nada en línea.
        if ($svc->tarifa_por_especialista) {
            return $e->precio_clp ? (int) $e->precio_clp : null;
        }
        return null;
    }

    /**
     * 50% del valor, congelado al reservar. null si no se cobra nada online.
     *
     * `abono_fijo_clp` manda sobre todo y NO se cruza con el precio: es el caso
     * en que no hay un total del cual sacar la mitad (Radiología cobra $27.000
     * sobre un precio que arranca en $55.000). Va ANTES de calcular el precio
     * porque Radiología no tiene ninguno.
     */
    public function montoAbono(object $e, object $svc, ?CarbonImmutable $cuando = null): ?int
    {
        if (($svc->modalidad_pago ?: 'abono') === 'nada') {
            return null;   // se cobra en el mostrador
        }
        if ($svc->abono_fijo_clp) {
            return (int) $svc->abono_fijo_clp;
        }
        $precio = $this->precioConsulta($e, $svc, $cuando);
        // `intdiv` y no `round`: PHP redondea .5 hacia arriba y Python al par.
        // Con un precio impar el portal prometería un peso distinto del que se
        // cobra, y ese número es el que ve el cliente antes del checkout.
        return $precio ? intdiv($precio, 2) : null;
    }

    /**
     * Total congelado. null cuando NO se conoce.
     *
     * Es el precio salvo en los servicios de abono fijo, donde el precio
     * cargado es un PISO y no un total (Peluquería tiene $20.000 y el valor
     * real depende del peso y el pelaje). Guardar ese piso sería peor que no
     * guardar nada: el portal lo mostraría como «Valor», ofrecería pagar el
     * total por esa cifra y calcularía el saldo restándola — las tres cosas
     * mintiendo, y la tercera dando negativo en Radiología.
     *
     * Y desde el 05-sep-2026 tampoco en los servicios cuya tarifa la pone el
     * ESPECIALISTA. La clínica pidió quitar de ahí la opción de pagar el total,
     * y este es el sitio donde se apaga: `monto_total_clp = NULL` es el sentinel
     * que todo el flujo ya respeta —solo se puede cobrar el abono, el portal no
     * dibuja la elección, `PagoView` tampoco y el saldo viaja como null—, así
     * que apagarlo acá lo apaga en los cuatro sitios sin una bandera nueva.
     *
     * Es «Interconsultas especialistas», el único con la bandera encendida. Y
     * NULL no miente: el número cargado es la tarifa de interconsulta de ese
     * profesional, no el total de la atención.
     */
    public function montoTotal(object $e, object $svc, ?CarbonImmutable $cuando = null): ?int
    {
        if ($svc->abono_fijo_clp || $svc->tarifa_por_especialista) {
            return null;
        }
        return $this->precioConsulta($e, $svc, $cuando);
    }

    /**
     * Con qué estado nace la cita.
     *
     * «Confirmada» significa «la hora quedó firme», y lo que la hace firme es
     * el pago. Pero las consultas de `modalidad_pago='nada'` se cobran en el
     * mostrador y no congelan abono: ahí no hay pago que esperar, y dejarlas en
     * «Agendada» anunciaba un trámite que no existe mientras el correo ya decía
     * «Tu visita está confirmada».
     *
     * Se decide por el MONTO y no por `modalidad_pago` porque el monto es el
     * sentinel que usa todo el flujo de pago para «esto no se cobra en línea».
     */
    public function estadoInicial(?int $montoAbono): string
    {
        return $montoAbono ? 'Agendada' : 'Confirmada';
    }
}
