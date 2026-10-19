<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ChatbotController;
use App\Services\AsistenteReservas;
use App\Services\SlotsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * El chat como asistente de reservas: lleva al portal, nunca reserva, y ante la
 * duda no contesta. Sobre SQLite en memoria; las horas libres, simuladas.
 */
#[RunTestsInSeparateProcesses]
class AsistenteReservasTest extends TestCase
{
    private array $pedidos = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['servicios', 'especialistas', 'servicio_especialista'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('servicios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->integer('orden')->default(0); $t->string('modalidad_pago')->default('nada');
        });
        Schema::create('especialistas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->boolean('reservable')->default(true); $t->boolean('solo_consulta_whatsapp')->default(false);
            $t->integer('orden')->default(0);
        });
        Schema::create('servicio_especialista', function (Blueprint $t) {
            $t->string('servicio_id'); $t->string('especialista_id');
        });
        foreach ([
            ['cg', 'Consulta General', 0], ['cd', 'Consulta Dermatológica', 1], ['ctd', 'Control Dermatológico', 1],
            ['dom', 'Consulta General a Domicilio', 5], ['td', 'Telemedicina dermatológica', 6], ['uc', 'Corte de uñas', 7],
        ] as [$id, $n, $o]) {
            DB::table('servicios')->insert(['id' => $id, 'nombre' => $n, 'orden' => $o]);
        }
        DB::table('especialistas')->insert(['id' => 'na', 'nombre' => 'Dra. Rivas']);
        DB::table('especialistas')->insert(['id' => 'wa', 'nombre' => 'Dr. Solo WhatsApp', 'solo_consulta_whatsapp' => true]);
    }

    private function asistente(array $horas = []): AsistenteReservas
    {
        $slots = Mockery::mock(SlotsService::class);
        $slots->shouldReceive('para')->andReturnUsing(function ($svc, $esp, $desde, $dias) use ($horas) {
            $this->pedidos[] = [$svc->id, $esp->id, $desde->format('Y-m-d H:i'), $dias];

            return $horas;
        });
        $this->app->instance(SlotsService::class, $slots);

        return $this->app->make(AsistenteReservas::class);
    }

    private function r(string $msg, array $horas = []): ?array
    {
        // Un jueves 10-sep-2026 a las 09:00, hora de la clínica.
        return $this->asistente($horas)->resolver($msg, CarbonImmutable::create(2026, 9, 10, 9, 0));
    }

    public function test_cancelar_lleva_a_mis_citas_y_no_cancela(): void
    {
        $r = $this->r('hola quiero cancelar mi hora');
        $this->assertSame('mis_citas', $r['motivo']);
        $this->assertSame('/citas', $r['acciones'][0]['ruta']);
    }

    public function test_lo_que_no_es_reservar_sigue_de_largo(): void
    {
        foreach (['a que hora abren?', 'cuanto cuesta la consulta general', 'mi perro vomita'] as $m) {
            $this->assertNull($this->r($m), $m);
        }
    }

    public function test_pedir_hora_sin_consulta_ofrece_todas(): void
    {
        $r = $this->r('quiero una hora');
        $this->assertSame('reservar:elegir', $r['motivo']);
        $this->assertCount(6, $r['acciones']);
        $this->assertSame('/reservar?servicio=cg', $r['acciones'][0]['ruta']);
    }

    public function test_consulta_nombrada_va_directo(): void
    {
        $r = $this->r('quiero agendar consulta dermatologica');
        $this->assertSame('reservar:consulta', $r['motivo']);
        $this->assertSame(['/reservar?servicio=cd'], array_column($r['acciones'], 'ruta'));
    }

    public function test_dermatologia_a_secas_ofrece_las_variantes(): void
    {
        $r = $this->r('necesito hora para dermatologia');
        $this->assertEqualsCanonicalizing(['cd', 'ctd', 'td'],
            array_map(fn ($x) => substr($x, strlen('/reservar?servicio=')), array_column($r['acciones'], 'ruta')));
    }

    public function test_gana_la_que_calza_con_mas_palabras(): void
    {
        $r = $this->r('quiero reservar telemedicina dermatologica');
        $this->assertSame(['/reservar?servicio=td'], array_column($r['acciones'], 'ruta'));
    }

    public function test_domicilio_le_gana_a_consulta_general_por_puntaje(): void
    {
        $r = $this->r('hora para consulta general a domicilio');
        $this->assertSame(['/reservar?servicio=dom'], array_column($r['acciones'], 'ruta'));
    }

    public function test_manana_trae_horas_reales_con_el_enlace_completo(): void
    {
        $r = $this->r('tienen hora mañana para consulta general?', ['2026-09-11' => ['10:00', '10:30']]);
        $this->assertSame('reservar:horas', $r['motivo']);
        $this->assertSame('/reservar?servicio=cg&especialista=na&fecha=2026-09-11&hora=10%3A00', $r['acciones'][0]['ruta']);
        $this->assertStringContainsString('vie 11 · 10:00 · Dra. Rivas', $r['acciones'][0]['texto']);
        $this->assertStringNotContainsString('reserv', mb_strtolower($r['texto']), 'no puede decir que reservó');
        // Pidió mañana: desde el inicio del viernes, un día. Y sin el especialista solo-WhatsApp.
        $this->assertSame([['cg', 'na', '2026-09-11 00:00', 1]], $this->pedidos);
    }

    public function test_en_la_manana_es_la_hora_del_dia_no_manana(): void
    {
        $r = $this->r('quiero hora para consulta general en la mañana');
        $this->assertSame('reservar:consulta', $r['motivo']);
    }

    public function test_sin_horas_ofrece_ver_todas(): void
    {
        $r = $this->r('hay hora hoy para corte de uñas', []);
        $this->assertSame('reservar:sin_horas', $r['motivo']);
        $this->assertSame('/reservar?servicio=uc', $r['acciones'][0]['ruta']);
    }

    public function test_cancelar_en_chile_tambien_es_pagar(): void
    {
        foreach (['cancelo en efectivo o con tarjeta?', 'donde cancelo el abono', 'se puede cancelar con transferencia'] as $m) {
            $this->assertNotSame('mis_citas', $this->r($m)['motivo'] ?? null, $m);
        }
    }

    public function test_quien_ya_tiene_hora_va_a_mis_citas(): void
    {
        foreach (['a que hora era mi cita?', 'tengo una reserva para el viernes', 'quiero ver mi hora'] as $m) {
            $this->assertSame('mis_citas', $this->r($m)['motivo'] ?? null, $m);
        }
        $this->assertSame('reservar:elegir', $this->r('quiero agendar mi hora')['motivo'],
            'pedir «mi hora» es reservar, no mirar una que ya tiene');
    }

    /** En vivo (05-oct): «Tengo reservas?» caía en «reserva» y ofrecía el catálogo para pedir otra. */
    public function test_tengo_reservas_en_plural_es_mirar_las_suyas(): void
    {
        foreach (['Tengo reservas?', 'Tengo reservas anteriores?', 'tengo citas esta semana?', 'cuales son mis citas'] as $m) {
            $this->assertSame('mis_citas', $this->r($m)['motivo'] ?? null, $m);
        }
        $this->assertSame('reservar:elegir', $this->r('quiero reservar')['motivo']);
    }

    /** En vivo (05-oct, sin IA): el nombre copiado de la lista del catálogo recibía «no logro entender». */
    public function test_el_nombre_de_una_consulta_a_secas_lleva_a_reservarla(): void
    {
        foreach (['Consulta general a domicilio', 'Consulta a domicilio', 'corte de uñas'] as $m) {
            $this->assertNull($this->r($m), "{$m}: resolver() sigue exigiendo intención; lo toma soloConsulta");
        }
        $this->assertSame(['/reservar?servicio=dom'],
            array_column($this->asistente()->soloConsulta('Consulta general a domicilio')['acciones'], 'ruta'));
        $this->assertSame(['/reservar?servicio=dom'],
            array_column($this->asistente()->soloConsulta('Consulta a domicilio')['acciones'], 'ruta'));
        // Con algo que el catálogo no explica, no se contesta: no es el nombre a secas.
        foreach (['consulta general a domicilio en maipú', 'mi perro vomita', 'Soy Pedro', 'Partamos de nuevo'] as $m) {
            $this->assertNull($this->asistente()->soloConsulta($m), $m);
        }
    }

    public function test_unas_horas_no_es_corte_de_unas(): void
    {
        $r = $this->r('quiero reservar unas horas para mis dos perros');
        $this->assertSame('reservar:elegir', $r['motivo']);

        foreach (['hora para cortarle las uñas', 'quiero agendar corte de uñas'] as $m) {
            $this->assertSame(['/reservar?servicio=uc'], array_column($this->r($m)['acciones'], 'ruta'), $m);
        }
    }

    public function test_disponible_sin_consulta_nombrada_no_es_reservar(): void
    {
        // Farmacia y pet shop: «disponible» y «cupo» hablan de stock tanto como de agenda.
        foreach (['tienen disponible apoquel?', 'hay cupo en el taller?'] as $m) {
            $this->assertNull($this->r($m), $m);
        }
        $this->assertSame('reservar:horas',
            $this->r('hay disponibilidad para consulta general', ['2026-09-10' => ['15:00']])['motivo']);
    }

    public function test_mas_de_dos_consultas_con_cuando_pide_elegir_sin_buscar(): void
    {
        $r = $this->r('necesito hora para dermatologia mañana', ['2026-09-11' => ['10:00']]);
        $this->assertSame('reservar:consulta', $r['motivo']);
        $this->assertCount(3, $r['acciones']);
        $this->assertSame([], $this->pedidos, 'no puede buscar horas de algunas y callar las otras');
    }

    public function test_anonimizar_saca_correo_telefono_y_rut(): void
    {
        $t = ChatbotController::anonimizar('soy juan@gmail.com, mi fono +56 9 8790 1080 y rut 12.345.678-9');
        $this->assertStringNotContainsString('juan@', $t);
        $this->assertStringNotContainsString('8790', $t);
        $this->assertStringNotContainsString('345', $t);
        $this->assertStringContainsString('[correo]', $t);
    }

    public function test_anonimizar_deja_el_telefono_publico_de_la_clinica(): void
    {
        // Lab, 05-oct: tapado, el modelo se disculpaba por un número «que no salió».
        config(['emr.clinica_telefono' => '+569 0000 0000']);
        $t = ChatbotController::anonimizar('Llama a la clínica al +569 0000 0000. Tu número +56 9 8790 1080 no se comparte.');

        $this->assertStringContainsString('+569 0000 0000', $t);
        $this->assertStringNotContainsString('8790', $t);
        $this->assertStringContainsString('[telefono]', $t);
    }
}
