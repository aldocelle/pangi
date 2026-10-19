<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\CitasController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Espejo de las pruebas de `_estado_al_reagendar` en
 * backend/tests/test_reagendar.py: mover la hora no agrega nada que esperar.
 */
class EstadoAlReagendarTest extends TestCase
{
    public static function casos(): array
    {
        return [
            'confirmada sigue confirmada (sin cobro en línea)' => ['Confirmada', null, 'Confirmada'],
            'agendada con abono pendiente sigue agendada' => ['Agendada', 'pending', 'Agendada'],
            'agendada de staff sin cobro sigue agendada' => ['Agendada', null, 'Agendada'],
            'pago aprobado confirma aunque viniera agendada' => ['Agendada', 'approved', 'Confirmada'],
        ];
    }

    #[DataProvider('casos')]
    public function test_estado_al_reagendar(string $antes, ?string $pago, string $esperado): void
    {
        $cita = (object) ['estado' => $antes, 'payment_status' => $pago];
        $this->assertSame($esperado, CitasController::estadoAlReagendar($cita));
    }
}
