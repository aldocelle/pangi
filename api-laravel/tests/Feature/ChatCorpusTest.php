<?php

namespace Tests\Feature;

use App\Services\ChatDeterminista;
use App\Services\CobroService;
use App\Services\SlotsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * Mide la capa sin IA contra `tests/Fixtures/chat_corpus.php`, con el catálogo
 * REAL del lab (13-sep-2026). Dos exigencias distintas, a propósito:
 *
 * - CERO respuestas equivocadas: contestar donde había que derivar, o contestar
 *   otra cosa. Esto no admite tolerancia.
 * - Todo lo demás del corpus también se exige, pero el mensaje separa los dos
 *   tipos para que se vea cuál es cuál.
 */
#[RunTestsInSeparateProcesses]
class ChatCorpusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ajustes', 'servicios', 'especialistas', 'servicio_especialista'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('ajustes', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->text('data')->nullable();
        });
        Schema::create('servicios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->integer('orden')->default(0); $t->string('grupo')->nullable(); $t->string('modalidad_pago')->nullable();
            $t->integer('precio_clp')->nullable(); $t->integer('precio_sabado_clp')->nullable();
            $t->text('precio_detalle')->nullable(); $t->integer('duracion_min')->default(30);
            $t->integer('abono_fijo_clp')->nullable();
        });
        Schema::create('especialistas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->boolean('reservable')->default(true); $t->boolean('solo_consulta_whatsapp')->default(false);
            $t->integer('orden')->default(0);
        });
        Schema::create('servicio_especialista', function (Blueprint $t) {
            $t->string('servicio_id'); $t->string('especialista_id');
        });

        $catalogo = [
            ['Consulta General', 1, 0, null, 'nada', 15000, 20000, 'Lunes a viernes: $15.000 · Sábado: $20.000'],
            ['Consulta Dermatológica', 1, 1, 'Dermatología', 'abono', 45000, 55000, 'Lunes a viernes: $45.000 · Sábado: $55.000'],
            ['Control Dermatológico', 1, 1, 'Dermatología', 'abono', 30000, 40000, 'Lunes a viernes: $30.000 · Sábado: $40.000'],
            ['Peluquería', 1, 2, null, 'abono', null, null, 'Abono fijo de $10.000 al reservar. El valor total depende del peso y del estado del pelaje: contáctanos si quieres un aproximado antes de venir.'],
            ['Interconsultas especialistas', 1, 3, null, 'abono', null, null, 'El valor depende de la especialidad.'],
            ['Ecografía', 1, 4, null, 'abono', 45000, null, '$45.000. Solo de lunes a viernes.'],
            ['Exámenes de sangre', 1, 5, null, 'nada', null, null, 'Valor según evaluación. Se cobra en la clínica.'],
            ['Radiología', 1, 5, null, 'abono', null, null, 'Desde $55.000. Se abonan $27.000 al reservar; la diferencia depende de la cantidad de radiografías y se paga en la clínica. Adjunta la orden de examen si quieres el presupuesto total. Solo de lunes a viernes.'],
            ['Consulta General a Domicilio', 1, 6, null, 'nada', 35000, null, '$35.000 en Puente Alto; fuera de Puente Alto el valor depende de la distancia. Se paga el día de la atención, en efectivo o con link de pago.'],
            ['Telemedicina general', 1, 7, null, 'total', 15000, 20000, null],
            ['Corte de uñas', 1, 8, null, 'nada', 6000, 10000, 'Lunes a viernes: $6.000 · Sábado: $10.000'],
            ['Telemedicina dermatológica', 1, 8, null, 'total', 45000, 55000, 'Lunes a viernes: $45.000 · Sábado: $55.000. Se paga completa antes de la consulta.'],
            ['Peluquería Canina', 0, 7, null, 'abono', 20000, null, 'Desde $20.000'],
        ];
        foreach ($catalogo as $i => [$n, $act, $ord, $g, $mod, $p, $ps, $det]) {
            DB::table('servicios')->insert(['id' => "s$i", 'nombre' => $n, 'activo' => $act, 'orden' => $ord,
                'grupo' => $g, 'modalidad_pago' => $mod, 'precio_clp' => $p, 'precio_sabado_clp' => $ps, 'precio_detalle' => $det]);
        }
        DB::table('especialistas')->insert(['id' => 'na', 'nombre' => 'Dra. Carla Rivas']);
    }

    public function test_la_lista_de_precios_no_pierde_la_condicion_del_valor(): void
    {
        $r = $this->app->make(\App\Services\RespuestaDirecta::class)->resolver('que valores tienen?');
        $this->assertStringContainsString('Consulta General a Domicilio: $35.000 en Puente Alto', $r['texto']);
        $this->assertStringContainsString('Radiología: Desde $55.000', $r['texto']);
    }

    /**
     * «¿Qué atienden?» nombra cada consulta, también las que comparten grupo.
     * Antes decía «Dermatología» a secas, como la tarjeta del paso 1; pero en
     * el chat no hay paso 2 que la divida, y la clínica pidió verlas separadas
     * (sep-2026). El resto del chat —valores y botones de reservar— ya lo hacía.
     */
    public function test_que_atienden_divide_las_consultas_de_un_grupo(): void
    {
        $r = $this->app->make(\App\Services\RespuestaDirecta::class)->resolver('¿Qué servicios tienen?');
        $this->assertStringContainsString('Consulta Dermatológica', $r['texto']);
        $this->assertStringContainsString('Control Dermatológico', $r['texto']);
        $this->assertStringNotContainsString(' Dermatología,', $r['texto']);
    }

    public function test_hacen_x_no_repite_el_nombre(): void
    {
        $r = $this->app->make(\App\Services\RespuestaDirecta::class)->resolver('hacen ecografias?');
        $this->assertSame('Sí, tenemos Ecografía: $45.000. Solo de lunes a viernes.', $r['texto']);
    }

    public function test_el_corpus(): void
    {
        $slots = Mockery::mock(SlotsService::class);
        $slots->shouldReceive('para')->andReturn(['2026-09-14' => ['10:00', '10:30']]);
        $this->app->instance(SlotsService::class, $slots);
        $cobro = Mockery::mock(CobroService::class);
        $cobro->shouldReceive('montoAbono')->andReturn(10000);
        $this->app->instance(CobroService::class, $cobro);

        $chat = $this->app->make(ChatDeterminista::class);
        $ahora = CarbonImmutable::create(2026, 9, 13, 10, 0);   // domingo, hora de la clínica

        $equivocadas = $faltan = [];
        $ok = 0;
        $corpus = require base_path('tests/Fixtures/chat_corpus.php');
        foreach ($corpus as [$texto, $esperado]) {
            $r = $chat->responder($texto, null, $ahora);
            $obtenido = $r === null ? 'deriva' : explode(':', $r['motivo'])[0];
            if ($obtenido === $esperado) {
                $ok++;
            } elseif ($obtenido === 'deriva') {
                $faltan[] = "  «{$texto}» → deriva (esperado {$esperado})";
            } else {
                $equivocadas[] = "  «{$texto}» → {$obtenido} (esperado {$esperado}): " . mb_substr($r['texto'], 0, 70);
            }
        }

        $total = count($corpus);
        $resumen = sprintf("\nCorpus: %d/%d bien · %d respuestas EQUIVOCADAS · %d sin contestar\n", $ok, $total, count($equivocadas), count($faltan))
            . ($equivocadas ? "EQUIVOCADAS:\n" . implode("\n", $equivocadas) . "\n" : '')
            . ($faltan ? "SIN CONTESTAR:\n" . implode("\n", $faltan) . "\n" : '');

        $this->assertSame([], $equivocadas, $resumen);
        $this->assertSame([], $faltan, $resumen);
    }
}
