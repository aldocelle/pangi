<?php

namespace Tests\Feature;

use App\Services\AgenteIA;
use App\Services\ClaudeMensajes;
use App\Services\SlotsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * La IA del chat, con el modelo GUIONADO: ningún test sale a internet ni gasta
 * (§10). Lo que se fija no es lo que el modelo dice, sino lo que el código
 * garantiza aunque el modelo se equivoque: la reserva exige dos mensajes y pasa
 * por CitasController::reservar, una hora inventada no se prepara, sin enlaces
 * ni ids en el texto, apagada por defecto, y respaldo sin IA si falla.
 */
#[RunTestsInSeparateProcesses]
class AgenteIATest extends TestCase
{
    /** Lo que recibió el modelo en cada llamada. */
    private array $llamadas = [];

    private const UUID = '3f2b8a4e-1c2d-4e5f-8a9b-0c1d2e3f4a5b';

    private const CG = '11111111-1111-4111-8111-111111111111';

    private const CD = '22222222-2222-4222-8222-222222222222';

    private const ECO = '33333333-3333-4333-8333-333333333333';

    private const NA = '44444444-4444-4444-8444-444444444444';

    /** Lo que recibió `CitasController::reservar`, si se llamó. */
    public array $reservas = [];

    /** Las instrucciones de sistema de cada llamada al modelo. */
    public array $sistemas = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['servicios', 'especialistas', 'servicio_especialista', 'ajustes',
            'chatbot_conversations', 'uso_asistente', 'chatbot_preguntas'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('servicios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->integer('orden')->default(0); $t->string('modalidad_pago')->default('nada');
            $t->integer('duracion_min')->default(30); $t->text('precio_detalle')->nullable();
            $t->boolean('requiere_orden_examen')->default(false); $t->string('grupo')->nullable();
            $t->integer('precio_clp')->nullable(); $t->integer('precio_sabado_clp')->nullable();
        });
        Schema::create('especialistas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->boolean('reservable')->default(true); $t->boolean('solo_consulta_whatsapp')->default(false);
            $t->integer('orden')->default(0);
        });
        Schema::create('servicio_especialista', function (Blueprint $t) {
            $t->string('servicio_id'); $t->string('especialista_id');
        });
        Schema::create('ajustes', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->text('data')->nullable();
        });
        Schema::create('chatbot_conversations', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id')->nullable(); $t->string('telefono')->nullable();
            $t->boolean('es_activa')->nullable(); $t->text('messages')->nullable(); $t->text('estado')->nullable();
            $t->dateTime('created_at')->nullable(); $t->dateTime('updated_at')->nullable();
        });
        Schema::create('uso_asistente', function (Blueprint $t) {
            $t->date('fecha')->primary(); $t->integer('llamadas'); $t->integer('tokens_entrada'); $t->integer('tokens_salida');
        });
        Schema::create('chatbot_preguntas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('texto'); $t->string('motivo')->nullable();
            $t->boolean('respondida'); $t->string('canal'); $t->dateTime('created_at');
        });

        DB::table('servicios')->insert(['id' => self::CG, 'nombre' => 'Consulta General']);
        DB::table('servicios')->insert(['id' => self::CD, 'nombre' => 'Consulta Dermatológica', 'orden' => 1]);
        DB::table('servicios')->insert(['id' => self::ECO, 'nombre' => 'Ecografía', 'orden' => 2, 'requiere_orden_examen' => true]);
        DB::table('especialistas')->insert(['id' => self::NA, 'nombre' => 'Dra. Rivas']);

        config([
            'emr.asistente_ia_activo' => true,
            'emr.anthropic_api_key' => 'clave-de-prueba',
            'emr.asistente_tokens_dia' => 300000,
        ]);

        $slots = Mockery::mock(SlotsService::class);
        $slots->shouldReceive('para')->andReturn(['2026-09-17' => ['10:30', '11:00']]);
        $cobro = Mockery::mock(\App\Services\CobroService::class);
        $cobro->shouldReceive('montoAbono')->andReturn(22500);
        $this->app->instance(\App\Services\CobroService::class, $cobro);
        $this->app->instance(SlotsService::class, $slots);
    }

    /** Respuestas del modelo, en orden. Cada una: stop_reason + bloques, o una excepción. */
    private function guion(array|\Throwable ...$respuestas): void
    {
        $test = $this;
        $this->app->instance(ClaudeMensajes::class, new class($respuestas, $test) extends ClaudeMensajes {
            public function __construct(private array $cola, private $test)
            {
            }

            public function crear(array $system, array $tools, array $messages): object
            {
                $this->test->anotar($messages);
                $this->test->sistemas[] = $system;
                $r = array_shift($this->cola);
                if ($r instanceof \Throwable) {
                    throw $r;
                }

                return json_decode(json_encode([
                    'stopReason' => $r[0],
                    'content' => $r[1],
                    'usage' => ['inputTokens' => 100, 'outputTokens' => 20],
                ]));
            }
        });
    }

    public function anotar(array $messages): void
    {
        $this->llamadas[] = $messages;
    }

    private static function herramienta(string $nombre, array $input, string $id = 't1'): array
    {
        return ['type' => 'tool_use', 'id' => $id, 'name' => $nombre, 'input' => $input];
    }

    private static function texto(string $t): array
    {
        return ['end_turn', [['type' => 'text', 'text' => $t]]];
    }

    private function agente(): AgenteIA
    {
        return $this->app->make(AgenteIA::class);
    }

    private static function jueves(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 9, 14, 9, 0, 0, 'America/Santiago');
    }

    private const DATOS = ['tutor' => 'Ana Pérez', 'correo' => 'Ana@Correo.cl', 'telefono' => '+56 9 1234 5678', 'mascota' => 'Toby'];

    private function preparar(string $hora = '10:30', string $servicio = self::CD): array
    {
        return ['tool_use', [self::herramienta('preparar_reserva', [
            'servicio_id' => $servicio, 'especialista_id' => self::NA, 'fecha' => '2026-09-17', 'hora' => $hora,
        ] + self::DATOS)]];
    }

    private function citasFalso(int $status = 200, array $cuerpo = []): void
    {
        $test = $this;
        $falso = Mockery::mock(\App\Http\Controllers\Api\CitasController::class);
        $falso->shouldReceive('reservar')->andReturnUsing(function ($request) use ($test, $status, $cuerpo) {
            $test->reservas[] = $request->all();

            return response()->json($cuerpo ?: ['id' => self::UUID, 'estado' => 'Agendada', 'monto_abono_clp' => 22500], $status);
        });
        $this->app->instance(\App\Http\Controllers\Api\CitasController::class, $falso);
    }

    public function test_reserva_en_dos_mensajes_y_por_la_misma_puerta_que_el_portal(): void
    {
        $this->citasFalso();
        $this->guion(
            $this->preparar(),
            // El modelo intenta confirmar en la MISMA respuesta: se le niega.
            ['tool_use', [self::herramienta('confirmar_reserva', [], 't2')]],
            self::texto('Consulta Dermatológica, jueves 17 a las 10:30. ¿Confirmo la reserva?'),
            ['tool_use', [self::herramienta('confirmar_reserva', [], 't3')]],
            self::texto('Listo, tu hora quedó reservada.'),
        );

        $r1 = $this->agente()->responder('esa, soy Ana', null, self::jueves());
        $negada = json_decode($this->llamadas[2][4]['content'][0]['content'], true);
        $this->assertArrayHasKey('error', $negada);
        $this->assertSame([], $this->reservas);

        $r2 = $this->agente()->responder('sí, confirma', $r1['conversation_id'], self::jueves());

        $this->assertCount(1, $this->reservas);
        $this->assertSame('ana@correo.cl', $this->reservas[0]['correo']);
        $this->assertSame('2026-09-17T10:30:00', $this->reservas[0]['fecha_hora']);
        $this->assertSame(self::NA, $this->reservas[0]['especialista_id']);
        $this->assertSame([['texto' => 'Pagar abono de $22.500', 'ruta' => '/pago/' . self::UUID]], $r2['acciones']);

        // Los datos de contacto no se quedan guardados en la conversación.
        $estado = DB::table('chatbot_conversations')->where('id', $r1['conversation_id'])->value('estado');
        $this->assertStringNotContainsString('correo.cl', $estado);
    }

    public function test_tope_de_reservas_por_ip_aunque_abra_conversaciones_nuevas(): void
    {
        $this->citasFalso();
        $guion = [];
        for ($i = 0; $i < 4; $i++) {
            // El «sí» lo confirma el código: el modelo solo prepara.
            array_push($guion, $this->preparar(), self::texto('¿Confirmo?'));
        }
        // La cuarta choca con el tope y la explica el modelo.
        $guion[] = self::texto('Por hoy no puedo hacer más reservas por acá.');
        $this->guion(...$guion);

        for ($i = 0; $i < 4; $i++) {
            $r = $this->agente()->responder('esa', null, self::jueves());
            $this->agente()->responder('sí', $r['conversation_id'], self::jueves());
        }

        $this->assertCount(3, $this->reservas);
    }

    public function test_el_bloque_estable_del_prompt_se_cachea(): void
    {
        /**
         * `self::SISTEMA` es una constante PHP: igual en cada llamada hasta el
         * próximo deploy. El segundo bloque (fecha/hora, estado de la
         * conversación) cambia en cada turno y por eso NO debe llevar el
         * marcador — cachearlo pagaría una escritura nueva siempre, nunca una
         * lectura. Medido en consola: sin este marcador en ningún bloque,
         * `cacheCreationInputTokens`/`cacheReadInputTokens` quedaban en 0.
         */
        $this->guion(self::texto('Atendemos de lunes a viernes.'));

        $this->agente()->responder('¿cuál es el horario?', null, self::jueves());

        $this->assertNotEmpty($this->sistemas);
        $primero = $this->sistemas[0];
        $this->assertSame(['type' => 'ephemeral'], $primero[0]['cache_control'] ?? null,
            'el bloque estable del system prompt perdió el marcador de caché');
        $this->assertArrayNotHasKey('cache_control', $primero[1],
            'el bloque dinámico (fecha/estado) no debe cachearse: cambia en cada turno');
    }

    public function test_un_si_a_otra_pregunta_no_confirma_un_borrador_viejo(): void
    {
        // Lab, 29-sep-2026: el borrador de Benito quedó de una prueba de la
        // tarde; horas después, en la misma conversación, un «sí» a «¿tus datos
        // son correctos?» para OTRA mascota lo confirmaba por el atajo.
        $this->citasFalso();
        $this->guion(
            $this->preparar(), self::texto('¿Confirmo la reserva?'),
            self::texto('Claro, ¿para qué día quieres la de Luna?'),
            self::texto('Confirmo tus datos: Ana, correo [correo]. ¿Es correcto?'),
            self::texto('Perfecto, busco horas para Luna.'),
        );

        $r1 = $this->agente()->responder('esa, soy Ana, ana@correo.cl', null, self::jueves());
        $this->agente()->responder('otra cosa: quiero hora para Luna', $r1['conversation_id'], self::jueves());
        $r3 = $this->agente()->responder('el lunes', $r1['conversation_id'], self::jueves());
        $r4 = $this->agente()->responder('sí', $r1['conversation_id'], self::jueves());

        $this->assertSame([], $this->reservas);
        $this->assertSame('Perfecto, busco horas para Luna.', $r4['texto']);
        // Y el aviso «hay una reserva preparada» ya no le llega al modelo.
        $this->assertStringNotContainsString('Hay una reserva preparada', json_encode(end($this->sistemas), JSON_UNESCAPED_UNICODE));
        // La marca del historial anonimizado no llega a la persona.
        $this->assertStringContainsString('correo ana@correo.cl', $r3['texto']);
    }

    public function test_el_historial_no_guarda_el_contacto_del_resumen(): void
    {
        $this->guion($this->preparar(), self::texto('Reserva para Toby, correo ana@correo.cl, teléfono +56 9 1234 5678. ¿Confirmo?'));

        $r = $this->agente()->responder('esa', null, self::jueves());

        $guardado = DB::table('chatbot_conversations')->where('id', $r['conversation_id'])->value('messages');
        $this->assertStringNotContainsString('ana@correo.cl', $guardado);
        $this->assertStringNotContainsString('1234 5678', $guardado);
        // A la persona sí se le muestra entero.
        $this->assertStringContainsString('ana@correo.cl', $r['texto']);
    }

    public function test_hora_inventada_no_se_prepara(): void
    {
        $this->guion($this->preparar('16:00'), self::texto('Esa no está.'));

        $this->agente()->responder('a las 4', null, self::jueves());

        $resultado = json_decode($this->llamadas[1][2]['content'][0]['content'], true);
        $this->assertStringContainsString('no está libre', $resultado['error']);
    }

    public function test_consulta_con_orden_medica_no_se_prepara_sin_la_orden_tampoco_en_el_portal(): void
    {
        // Antes el chat del portal mandaba estas consultas al formulario porque
        // no recibía archivos. Desde el 07-oct los recibe (AdjuntosWeb), pero la
        // regla es la misma que la del paso 4 del portal: sin el archivo no se
        // prepara la reserva. Y ya no se manda a ningún lado: se pide la orden.
        $this->guion($this->preparar('10:30', self::ECO), self::texto('Mándame la orden.'));

        $r = $this->agente()->responder('ecografía', null, self::jueves());

        $resultado = json_decode($this->llamadas[1][2]['content'][0]['content'], true);
        $this->assertStringContainsString('requiere la orden médica', $resultado['error']);
        $this->assertStringContainsString('el clip', $resultado['error']);
        $this->assertSame([], $r['acciones']);
    }

    public function test_un_dato_pedido_aparte_no_pierde_los_anteriores(): void
    {
        // Lab, 29-sep-2026: el teléfono llegó en un mensaje aparte y para
        // entonces el correo del mensaje anterior solo existía en el historial
        // como «[correo]» (se guarda anonimizado). El bot terminaba diciendo
        // «tu correo no es válido» a quien lo había escrito bien.
        $this->citasFalso();
        $base = ['servicio_id' => self::CD, 'especialista_id' => self::NA, 'fecha' => '2026-09-17', 'hora' => '10:30'];
        $this->guion(
            ['tool_use', [self::herramienta('preparar_reserva',
                $base + ['tutor' => 'Ana Pérez', 'correo' => 'Ana@Correo.cl', 'mascota' => 'Toby'])]],
            self::texto('Me falta tu teléfono.'),
            ['tool_use', [self::herramienta('preparar_reserva',
                $base + ['tutor' => 'Ana Pérez', 'correo' => '[correo]', 'mascota' => 'Toby', 'telefono' => '+56 9 1234 5678'])]],
            self::texto('Consulta Dermatológica, jueves 17 a las 10:30. ¿Confirmo la reserva?'),
            ['tool_use', [self::herramienta('confirmar_reserva', [], 't2')]],
            self::texto('Listo, tu hora quedó reservada.'),
        );

        $r1 = $this->agente()->responder('esa, soy Ana Pérez, Ana@Correo.cl, mi perro es Toby', null, self::jueves());
        $faltan = json_decode($this->llamadas[1][2]['content'][0]['content'], true);
        $this->assertStringContainsString('Faltan datos: telefono', $faltan['error']);
        $this->assertStringContainsString('Ya tengo', $faltan['error']);

        // El historial que ve el modelo ya no trae el correo.
        $this->agente()->responder('+56 9 1234 5678', $r1['conversation_id'], self::jueves());
        $this->assertStringNotContainsString('correo.cl', json_encode($this->llamadas[2]));
        $resumen = json_decode($this->llamadas[3][4]['content'][0]['content'], true);
        $this->assertSame('ana@correo.cl', $resumen['resumen']['correo']);

        $this->agente()->responder('sí', $r1['conversation_id'], self::jueves());
        $this->assertSame('ana@correo.cl', $this->reservas[0]['correo']);
        $this->assertSame('+56 9 1234 5678', $this->reservas[0]['telefono']);
        // Reservada, lo recordado se va junto con el borrador.
        $estado = DB::table('chatbot_conversations')->where('id', $r1['conversation_id'])->value('estado');
        $this->assertStringNotContainsString('correo.cl', $estado);
        $this->assertStringNotContainsString('1234', $estado);
    }

    public function test_el_correo_se_guarda_aunque_el_modelo_no_llame_a_la_herramienta(): void
    {
        // La segunda prueba del lab, mismo día: con los datos sin teléfono el
        // modelo NO llamó preparar_reserva (contestó «me falta tu teléfono»),
        // así que no quedaba nada que recordar y al siguiente mensaje volvió
        // a pedir el correo.
        $this->citasFalso();
        $this->guion(
            self::texto('Solo me falta tu teléfono.'),
            ['tool_use', [self::herramienta('preparar_reserva', [
                'servicio_id' => self::CD, 'especialista_id' => self::NA, 'fecha' => '2026-09-17', 'hora' => '10:30',
                'tutor' => 'Ana Pérez', 'correo' => '', 'mascota' => 'Toby', 'telefono' => '+56 9 1234 5678',
            ])]],
            self::texto('Consulta Dermatológica, jueves 17 a las 10:30. ¿Confirmo la reserva?'),
        );

        $r1 = $this->agente()->responder('Las 10:30. Soy Ana Pérez, Ana@Correo.cl, el perro es Toby.', null, self::jueves());
        $this->agente()->responder('+56 9 1234 5678', $r1['conversation_id'], self::jueves());

        // Al modelo se le avisa QUÉ está guardado, nunca el valor.
        $sistema = json_encode($this->sistemas[1], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Ya quedó guardado el correo', $sistema);
        $this->assertStringNotContainsString('correo.cl', $sistema);

        $resumen = json_decode($this->llamadas[2][4]['content'][0]['content'], true);
        $this->assertSame('ana@correo.cl', $resumen['resumen']['correo']);
    }

    public function test_una_fecha_o_un_rut_no_se_guardan_como_telefono(): void
    {
        $this->guion(self::texto('¿Me das tu teléfono?'));

        $r = $this->agente()->responder('El 2026-10-02 a las 11, mi RUT es 12.345.678-9', null, self::jueves());

        $estado = DB::table('chatbot_conversations')->where('id', $r['conversation_id'])->value('estado');
        $this->assertStringNotContainsString('telefono', $estado);
    }

    public function test_el_si_lo_confirma_el_codigo_sin_preguntarle_al_modelo(): void
    {
        // Lab, 29-sep-2026: tres veces el modelo contestó «reserva confirmada»
        // al «sí» sin llamar confirmar_reserva. Ahora el «sí» ni le llega.
        $this->citasFalso();
        $this->guion($this->preparar(), self::texto('¿Confirmo la reserva?'));

        $r1 = $this->agente()->responder('esa, soy Ana', null, self::jueves());
        $r2 = $this->agente()->responder('Sí, confirma', $r1['conversation_id'], self::jueves());

        $this->assertCount(2, $this->llamadas);   // las dos del primer mensaje; el «sí», ninguna
        $this->assertCount(1, $this->reservas);
        $this->assertStringContainsString('quedó reservada: jueves 17 de septiembre a las 10:30 para Toby', $r2['texto']);
        $this->assertStringContainsString('retenida 15 minutos y se confirma al pagar con el botón «Pagar»', $r2['texto']);
        $this->assertSame([['texto' => 'Pagar abono de $22.500', 'ruta' => '/pago/' . self::UUID]], $r2['acciones']);
    }

    public function test_tras_reservar_pregunta_por_otra_hora_y_el_no_se_despide_sin_modelo(): void
    {
        $this->citasFalso();
        $this->guion($this->preparar(), self::texto('¿Confirmo la reserva?'), self::texto('¿Para qué mascota?'));

        $r1 = $this->agente()->responder('esa, soy Ana', null, self::jueves());
        $r2 = $this->agente()->responder('Sí, confirma', $r1['conversation_id'], self::jueves());
        $this->assertStringEndsWith(AgenteIA::PREGUNTA_OTRA_HORA, $r2['texto']);

        $r3 = $this->agente()->responder('no, eso es todo, gracias', $r2['conversation_id'], self::jueves());
        $this->assertSame(AgenteIA::DESPEDIDA, $r3['texto']);
        $this->assertCount(2, $this->llamadas);   // ni el «sí» ni el «no» pasan por el modelo
    }

    public function test_un_no_con_contenido_o_un_si_siguen_al_modelo(): void
    {
        $this->citasFalso();
        $this->guion($this->preparar(), self::texto('¿Confirmo la reserva?'), self::texto('¿Para qué mascota?'));

        $r1 = $this->agente()->responder('esa, soy Ana', null, self::jueves());
        $r2 = $this->agente()->responder('Sí, confirma', $r1['conversation_id'], self::jueves());
        $r3 = $this->agente()->responder('no, pero quiero otra para Luna el viernes', $r2['conversation_id'], self::jueves());

        $this->assertSame('¿Para qué mascota?', $r3['texto']);
        $this->assertCount(3, $this->llamadas);
    }

    public function test_un_si_repetido_no_reserva_dos_veces(): void
    {
        // Quien toca «sí» dos veces (el WhatsApp no confirmó a tiempo y el
        // cliente reintenta) no puede quedar con dos citas. El segundo «sí»
        // ya no tiene un resumen que confirmar: no reserva nada.
        $this->citasFalso();
        $this->guion($this->preparar(), self::texto('¿Confirmo la reserva?'), self::texto('Tu hora ya quedó reservada.'));

        $r1 = $this->agente()->responder('esa, soy Ana', null, self::jueves());
        $r2 = $this->agente()->responder('Sí, confirma', $r1['conversation_id'], self::jueves());
        $r3 = $this->agente()->responder('Sí', $r2['conversation_id'], self::jueves());

        $this->assertCount(1, $this->reservas);
        $this->assertStringContainsString('quedó reservada', $r2['texto']);
        $this->assertNotNull($r3);
    }

    public function test_buscar_horas_entiende_fecha_como_desde(): void
    {
        // Lab, 29-sep-2026: {fecha: 2026-10-02, dias: 1} buscaba desde HOY un
        // día y el bot decía que el viernes estaba lleno con horas libres.
        $slots = Mockery::mock(SlotsService::class);
        $slots->shouldReceive('para')
            ->withArgs(fn ($svc, $e, $inicio, $dias) => $inicio->format('Y-m-d') === '2026-09-17' && $dias === 1)
            ->andReturn(['2026-09-17' => ['10:30', '11:00']]);
        $this->app->instance(SlotsService::class, $slots);
        $this->guion(
            ['tool_use', [self::herramienta('buscar_horas',
                ['servicio_id' => self::CD, 'especialista_id' => self::NA, 'fecha' => '2026-09-17', 'dias' => 1])]],
            self::texto('El jueves 17 hay 10:30 y 11:00.'),
        );

        $this->agente()->responder('¿hay hora el jueves 17?', null, self::jueves());

        $r = json_decode($this->llamadas[1][2]['content'][0]['content'], true);
        $this->assertSame(['2026-09-17' => ['10:30', '11:00']], $r['resultado'][0]['horas']);
    }

    public function test_consulta_y_especialista_se_resuelven_por_nombre(): void
    {
        // El modelo prueba primero con nombres («dermatologica», «rivas»):
        // antes era «no existe» + ver_consultas + reintento.
        $this->guion(
            ['tool_use', [self::herramienta('buscar_horas',
                ['servicio_id' => 'dermatologia', 'especialista_id' => 'dra_rivas'])]],
            self::texto('Hay horas.'),
        );

        $this->agente()->responder('dermatología con la Dra. Rivas', null, self::jueves());

        $r = json_decode($this->llamadas[1][2]['content'][0]['content'], true);
        $this->assertSame('Consulta Dermatológica', $r['servicio']);
        $this->assertSame(self::NA, $r['resultado'][0]['especialista_id']);
    }

    public function test_un_nombre_ambiguo_devuelve_las_opciones_con_su_id(): void
    {
        $control = '55555555-5555-4555-8555-555555555555';
        DB::table('servicios')->insert(['id' => $control, 'nombre' => 'Control Dermatológico', 'orden' => 3]);
        $this->guion(
            ['tool_use', [self::herramienta('buscar_horas', ['servicio_id' => 'dermatologica'])]],
            ['tool_use', [self::herramienta('buscar_horas', ['servicio_id' => 'consulta dermatológica'], 't2')]],
            ['tool_use', [self::herramienta('buscar_horas', ['servicio_id' => '99999999-9999-4999-8999-999999999999'], 't3')]],
            self::texto('Listo.'),
        );

        $this->agente()->responder('dermatología', null, self::jueves());

        $ambiguo = json_decode($this->llamadas[1][2]['content'][0]['content'], true);
        $this->assertStringContainsString('Consulta Dermatológica (servicio_id ' . self::CD . ')', $ambiguo['error']);
        $this->assertStringContainsString('Control Dermatológico (servicio_id ' . $control . ')', $ambiguo['error']);
        // Con el nombre completo gana el exacto.
        $exacto = json_decode($this->llamadas[2][4]['content'][0]['content'], true);
        $this->assertSame('Consulta Dermatológica', $exacto['servicio']);
        // Un id con forma de id que no existe no se adivina.
        $inventado = json_decode($this->llamadas[3][6]['content'][0]['content'], true);
        $this->assertStringContainsString('no existe', $inventado['error']);
    }

    public function test_que_cuenta_como_un_si(): void
    {
        $esSi = new \ReflectionMethod(AgenteIA::class, 'esSi');
        foreach (['sí', 'Si!', 'dale', 'ok, confírmala', 'sí por favor', 'ya', 'de acuerdo', 'perfecto, gracias', 'Sí 👍'] as $si) {
            $this->assertTrue($esSi->invoke(null, $si), "«{$si}» debería contar como sí");
        }
        foreach (['sí, pero a las 12', 'no', 'no, sí', 'mejor el viernes', 'gracias', '', 'si puedes cámbiala al sábado'] as $no) {
            $this->assertFalse($esSi->invoke(null, $no), "«{$no}» no debería contar como sí");
        }
    }

    public function test_no_anuncia_una_reserva_sin_haber_reservado(): void
    {
        // Lo que pasó en el lab el 29-sep-2026: el «sí» llega, el modelo no
        // llama confirmar_reserva y aun así dice que quedó reservada.
        $this->citasFalso();
        $this->guion(
            $this->preparar(), self::texto('¿Confirmo la reserva?'),
            self::texto('Excelente, reserva confirmada. Te llegará un correo.'),
        );

        // Un «sí» con cambios va al modelo (el «sí» a secas lo confirma el
        // código); si el modelo lo da por reservado sin reservar, se corrige.
        $r1 = $this->agente()->responder('esa', null, self::jueves());
        $r2 = $this->agente()->responder('sí, pero que sea a las 11:00', $r1['conversation_id'], self::jueves());

        $this->assertSame([], $this->reservas);
        $this->assertStringContainsString('todavía no quedó reservada', $r2['texto']);
    }

    public function test_sin_resumen_previo_tampoco_se_anuncia(): void
    {
        $this->guion(self::texto('Listo, tu hora quedó reservada para mañana.'));

        $r = $this->agente()->responder('mismo de antes', null, self::jueves());

        $this->assertStringContainsString('todavía no hay ninguna hora reservada', $r['texto']);
    }

    public function test_despues_de_reservar_de_verdad_si_puede_decirlo(): void
    {
        $this->citasFalso();
        $this->guion(
            $this->preparar(), self::texto('¿Confirmo?'),
            self::texto('Sí, la reserva está confirmada en el sistema.'),
        );

        $r1 = $this->agente()->responder('esa', null, self::jueves());
        $r2 = $this->agente()->responder('sí', $r1['conversation_id'], self::jueves());
        $r3 = $this->agente()->responder('¿quedó agendado?', $r1['conversation_id'], self::jueves());

        $this->assertStringContainsString('quedó reservada', $r2['texto']);
        $this->assertSame('Sí, la reserva está confirmada en el sistema.', $r3['texto']);
    }

    public function test_hora_tomada_mientras_tanto_no_se_da_por_reservada(): void
    {
        $this->citasFalso(409, ['detail' => 'Ese horario ya no está disponible. Elige otro.']);
        $this->guion(
            $this->preparar(), self::texto('¿Confirmo?'),
            self::texto('Se tomó, te busco otra.'),
        );

        $r1 = $this->agente()->responder('esa', null, self::jueves());
        $r2 = $this->agente()->responder('sí', $r1['conversation_id'], self::jueves());

        // El código intentó confirmar; el modelo recibe el porqué del fallo.
        $ultimo = end($this->llamadas[2]);
        $this->assertStringContainsString('se acaba de tomar', $ultimo['content']);
        $this->assertSame('Se tomó, te busco otra.', $r2['texto']);
        $this->assertSame([], $r2['acciones']);
    }

    public function test_el_texto_no_lleva_enlaces_ni_ids_aunque_el_modelo_los_escriba(): void
    {
        $this->guion(self::texto('Reserva en https://reservas.x.cl/reservar?servicio=cd con id ' . self::UUID . ' **ya**.'));

        $r = $this->agente()->responder('hola', null, self::jueves());

        $this->assertStringNotContainsString('http', $r['texto']);
        $this->assertStringNotContainsString(self::UUID, $r['texto']);
        $this->assertStringNotContainsString('**', $r['texto']);
    }

    /** En vivo (lab, 05-oct, Haiku): «La consulta dermatológica cuesta 80 mil pesos», y es $45.000 / $55.000. */
    public function test_un_monto_que_no_esta_en_la_base_no_sale(): void
    {
        DB::table('servicios')->where('id', self::CD)->update(['precio_clp' => 45000, 'precio_sabado_clp' => 55000]);
        $this->guion(self::texto('La consulta dermatológica cuesta 80 mil pesos. No tenemos descuentos en este momento.'));

        $r = $this->agente()->responder('valores', null, self::jueves(), '56911112222');

        $this->assertStringNotContainsString('80', $r['texto']);
        $this->assertStringContainsString('Consulta Dermatológica: $45.000 (sábado $55.000)', $r['texto']);
    }

    public function test_un_monto_de_la_base_o_de_una_herramienta_si_sale(): void
    {
        DB::table('servicios')->where('id', self::CD)->update(['precio_clp' => 45000, 'precio_sabado_clp' => 55000]);
        $this->guion(self::texto('La dermatológica vale $45.000 de lunes a viernes y 55 mil el sábado.'));
        // Con una especialista nombrada va al modelo (sin ella lo contesta el atajo).
        $r = $this->agente()->responder('¿cuánto vale la dermatológica con la Dra. Rivas?', null, self::jueves());
        $this->assertSame('La dermatológica vale $45.000 de lunes a viernes y 55 mil el sábado.', $r['texto']);

        // 22.500 no está en `servicios`: es el abono que calculó la herramienta.
        DB::table('servicios')->where('id', self::CD)->update(['modalidad_pago' => 'abono']);
        $this->guion($this->preparar(), self::texto('Te preparé la reserva. El abono es de $22.500. ¿Confirmo la reserva?'));
        $r = $this->agente()->responder('a las 10:30', null, self::jueves());
        $this->assertStringContainsString('$22.500', $r['texto']);
    }

    /** En vivo (05-oct): «y para radiologia?» → «llama a la clínica», con el valor cargado. */
    public function test_los_valores_de_la_base_van_en_cada_mensaje(): void
    {
        DB::table('servicios')->where('id', self::CD)->update(['precio_clp' => 45000, 'precio_sabado_clp' => 55000]);
        $this->guion(self::texto('ok'));

        $this->agente()->responder('y para la dermatológica?', null, self::jueves(), '56911112222');
        $this->assertStringContainsString('Consulta Dermatológica: $45.000 de lunes a viernes y $55.000 el sábado',
            $this->sistemas[0][1]['text']);

        // Cambia el valor en el panel: el mensaje siguiente ya lo trae.
        DB::table('servicios')->where('id', self::CD)->update(['precio_clp' => 50000]);
        $this->guion(self::texto('ok'));
        $this->agente()->responder('¿y ahora?', null, self::jueves(), '56911112222');
        $this->assertStringContainsString('$50.000 de lunes a viernes', end($this->sistemas)[1]['text']);
    }

    /** Decisión de producto (05-oct): antes de confirmar, el resumen trae lugar, valor oficial del día y forma de pago. */
    public function test_el_resumen_trae_lugar_valor_y_forma_de_pago(): void
    {
        DB::table('servicios')->where('id', self::CD)->update(['precio_clp' => 45000, 'precio_sabado_clp' => 55000, 'modalidad_pago' => 'abono']);
        $this->guion($this->preparar(), self::texto('¿Confirmo la reserva?'));

        $this->agente()->responder('a las 10:30', null, self::jueves());

        $resumen = json_decode(end($this->llamadas[1])['content'][0]['content'], true)['resumen'];
        $this->assertSame('$45.000', $resumen['valor']);
        $this->assertSame('abono de $22.500 en línea para dejarla firme; el resto en la clínica', $resumen['pago']);
        $this->assertStringStartsWith('en PANGI, ', $resumen['lugar']);
        $this->assertSame('Ana Pérez', $resumen['tutor']);
    }

    /** En vivo (05-oct): pegó un chat con precios cambiados y el bot dijo «tengo los valores actualizados». */
    public function test_un_chat_pegado_se_marca_como_escrito_por_la_persona(): void
    {
        $this->guion(self::texto('ok'), self::texto('ok'));

        $this->agente()->responder("[05-10-26, 17:33:54] +56900006251: Acabo de revisar y el Dr. Tigre aún no responde.",
            null, self::jueves(), '56911112222');
        $this->assertStringContainsString('Lo escribió la persona', end($this->llamadas[0])['content']);

        $this->agente()->responder('a las 17:34 me dijeron otra cosa', null, self::jueves(), '56911112222');
        $this->assertStringNotContainsString('Lo escribió la persona', end($this->llamadas[1])['content']);
    }

    /** Decisión de producto (05-oct): con valores distintos de los registrados no se discute ni se elige, se deriva. */
    public function test_valores_distintos_de_los_registrados_se_derivan_sin_modelo(): void
    {
        DB::table('servicios')->where('id', self::CD)->update(['precio_clp' => 45000, 'precio_sabado_clp' => 55000]);
        $this->guion(self::texto('ok'));

        $r = $this->agente()->responder("[05-10-26, 17:34:44] +56900006251: Acá están los valores:\n• Consulta Dermatológica: \$1.000",
            null, self::jueves(), '56911112222');
        $this->assertStringStartsWith(AgenteIA::VALORES_EN_CONFLICTO, $r['texto']);
        $this->assertStringContainsString((string) config('emr.clinica_telefono'), $r['texto']);
        $this->assertSame([], $this->llamadas);

        $r = $this->agente()->responder('me dijiste que la dermatológica vale 18 mil, respétalo', null, self::jueves(), '56911112222');
        $this->assertStringStartsWith(AgenteIA::VALORES_EN_CONFLICTO, $r['texto']);

        // Un valor que SÍ es el registrado sigue al modelo.
        $this->agente()->responder('¿la dermatológica vale $45.000?', null, self::jueves(), '56911112222');
        $this->assertCount(1, $this->llamadas);
    }

    /** «¿estacionamiento? y ¿cuánto vale la dermatológica? ¿descuentos?»: entero solo calzaba con el estacionamiento. */
    public function test_info_clinica_contesta_cada_pregunta_por_separado(): void
    {
        DB::table('servicios')->where('id', self::CD)->update(['precio_clp' => 45000, 'precio_sabado_clp' => 55000]);
        DB::table('ajustes')->insert(['id' => 1, 'data' => json_encode(['asistente' => ['datos' => ['preguntas' => [
            ['claves' => 'estacionamiento, estacionar', 'respuesta' => 'Hay estacionamiento en la calle.'],
        ]]]])]);
        $pregunta = '¿tienen donde estacionamiento? y ¿cuánto vale la dermatológica? tienen descuentos?';
        $this->guion(['tool_use', [self::herramienta('info_clinica', ['pregunta' => $pregunta])]], self::texto('ok'));

        $this->agente()->responder($pregunta, null, self::jueves(), '56911112222');

        $resultado = json_decode(end($this->llamadas[1])['content'][0]['content'], true);
        $this->assertStringContainsString('Hay estacionamiento en la calle.', $resultado['respuesta']);
        $this->assertStringContainsString('$45.000', $resultado['respuesta']);
        $this->assertSame(['tienen descuentos'], $resultado['sin_registro']);
    }

    public function test_apagada_por_defecto_no_llama_al_modelo(): void
    {
        $this->guion(); // cola vacía: si llamara, array_shift daría null y fallaría
        foreach ([['emr.asistente_ia_activo' => false], ['emr.anthropic_api_key' => '']] as $cfg) {
            config(['emr.asistente_ia_activo' => true, 'emr.anthropic_api_key' => 'x']);
            config($cfg);
            $this->assertNull($this->agente()->responder('quiero hora', null, self::jueves()));
        }
        $this->assertSame([], $this->llamadas);

        // Y el default del config, sin variables en el entorno, es apagado.
        $this->assertFalse(filter_var(env('ASISTENTE_IA_ACTIVO_NO_EXISTE', false), FILTER_VALIDATE_BOOLEAN));
    }

    public function test_tope_diario_alcanzado_no_llama(): void
    {
        $this->guion();
        DB::table('uso_asistente')->insert([
            'fecha' => CarbonImmutable::now('America/Santiago')->format('Y-m-d'),
            'llamadas' => 50, 'tokens_entrada' => 250000, 'tokens_salida' => 60000,
        ]);

        $this->assertNull($this->agente()->responder('quiero hora', null, self::jueves()));
        $this->assertSame([], $this->llamadas);
    }

    public function test_registra_el_gasto_y_guarda_la_conversacion_anonimizada(): void
    {
        $this->guion(self::texto('¿Para qué necesitas la hora?'), self::texto('Perfecto.'));

        $r = $this->agente()->responder('hola soy ana@correo.cl', null, self::jueves());
        $this->agente()->responder('para dermatologia', $r['conversation_id'], self::jueves());

        $fila = DB::table('uso_asistente')->first();
        $this->assertSame(2, (int) $fila->llamadas);
        $this->assertSame(200, (int) $fila->tokens_entrada);

        $msgs = json_decode(DB::table('chatbot_conversations')->where('id', $r['conversation_id'])->value('messages'), true);
        $this->assertCount(4, $msgs);
        $this->assertStringNotContainsString('ana@correo.cl', $msgs[0]['content']);
        // El segundo mensaje llegó al modelo con el primero como historial.
        $this->assertSame('¿Para qué necesitas la hora?', $this->llamadas[1][1]['content']);
    }

    public function test_rechazo_del_clasificador_no_deja_la_burbuja_en_blanco(): void
    {
        $this->guion(['refusal', []]);

        $r = $this->agente()->responder('algo raro', null, self::jueves());

        $this->assertStringContainsString('WhatsApp', $r['texto']);
    }

    public function test_controlador_pedir_hora_va_a_la_ia(): void
    {
        $this->guion(self::texto('¿Para qué es la hora?'));

        $res = $this->postJson('/chatbot/mensaje', ['texto' => 'Quiero reservar una hora'])->assertOk();

        $this->assertSame('¿Para qué es la hora?', $res->json('respuesta'));
        $this->assertNotSame('', $res->json('conversation_id'));
        $this->assertSame('ia', DB::table('chatbot_preguntas')->value('motivo'));
    }

    public function test_controlador_apagada_queda_igual_que_antes(): void
    {
        config(['emr.asistente_ia_activo' => false]);
        $this->guion();

        $res = $this->postJson('/chatbot/mensaje', ['texto' => 'Quiero reservar una hora'])->assertOk();

        $this->assertSame('', $res->json('conversation_id'));
        $this->assertNotEmpty($res->json('acciones'));   // la respuesta de AsistenteReservas
        $this->assertSame([], $this->llamadas);
    }

    public function test_controlador_si_el_modelo_falla_responde_la_capa_sin_ia(): void
    {
        $this->guion(new \RuntimeException('sin red'));

        $res = $this->postJson('/chatbot/mensaje', ['texto' => 'Quiero reservar una hora'])->assertOk();

        $this->assertSame('', $res->json('conversation_id'));
        $this->assertNotEmpty($res->json('acciones'));
    }

    public function test_conversacion_vieja_no_se_continua(): void
    {
        DB::table('chatbot_conversations')->insert([
            'id' => self::UUID, 'messages' => json_encode([['role' => 'user', 'content' => 'x'], ['role' => 'assistant', 'content' => 'y']]),
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->assertFalse($this->agente()->conversacionViva(self::UUID));
        $this->assertFalse($this->agente()->conversacionViva('no-es-uuid'));
    }
}
