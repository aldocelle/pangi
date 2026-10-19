<?php

namespace Tests\Feature;

use App\Services\ClaudeMensajes;
use App\Services\SlotsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * El asistente por WhatsApp, de punta a punta: webhook firmado → respuesta
 * enviada a la Graph API (falsa: Http::fake, nada sale a internet) → y con la IA
 * guionada, reserva por la misma puerta que el portal.
 *
 * Lo que se fija es lo propio del canal: la firma, que un reintento de Meta no
 * se conteste dos veces, que el teléfono del chat es el de la reserva, que el
 * tope de reservas es por número y no por IP (todo llega desde las IP de Meta),
 * y que los botones del portal llegan como enlace absoluto.
 */
#[RunTestsInSeparateProcesses]
class WhatsappTest extends TestCase
{
    private const UUID = '3f2b8a4e-1c2d-4e5f-8a9b-0c1d2e3f4a5b';

    private const CG = '11111111-1111-4111-8111-111111111111';

    private const CD = '22222222-2222-4222-8222-222222222222';

    private const NA = '44444444-4444-4444-8444-444444444444';

    private const ANA = '56912345678';

    private const SECRETO = 'secreto-de-prueba';

    public array $reservas = [];

    private int $wamid = 0;

    /**
     * El reloj de este archivo.
     *
     * La hora que se reserva acá es el jueves 17-sep-2026 10:30, y
     * `horaLibre()` —con razón— no deja reservar en el pasado. Acá se entra por
     * el WEBHOOK, así que `AgenteIA::responder()` recibe `$ahora = null` y usa
     * el reloj real: a diferencia de `AgenteIATest`, que le inyecta la fecha,
     * este archivo no tiene por dónde pasarla.
     *
     * Sin congelarlo los dos tests que reservan se ponen rojos solos al llegar
     * el 18-sep. Y pasó: el commit del 14-sep los dejó verdes y amanecieron en
     * rojo sin que nadie tocara una línea.
     */
    private static function ahora(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 9, 14, 9, 0, 0, 'America/Santiago');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(self::ahora());
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
        DB::table('servicios')->insert(['id' => self::CD, 'nombre' => 'Consulta Dermatológica', 'orden' => 1, 'modalidad_pago' => 'abono']);
        DB::table('especialistas')->insert(['id' => self::NA, 'nombre' => 'Dra. Rivas']);

        config([
            'emr.whatsapp_app_secret' => self::SECRETO,
            'emr.whatsapp_access_token' => 'token-de-prueba',
            'emr.whatsapp_phone_number_id' => '999',
            'emr.portal_reservas_url' => 'https://reservas.test',
            'emr.clinica_telefono' => '+569 0000 0000',
            'emr.asistente_ia_activo' => false,
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.salida']]])]);

        $slots = Mockery::mock(SlotsService::class);
        $slots->shouldReceive('para')->andReturn(['2026-09-17' => ['10:30', '11:00']]);
        $cobro = Mockery::mock(\App\Services\CobroService::class);
        $cobro->shouldReceive('montoAbono')->andReturn(22500);
        $this->app->instance(\App\Services\CobroService::class, $cobro);
        $this->app->instance(SlotsService::class, $slots);
    }

    // ── Ayudas ────────────────────────────────────────────────────────────

    /** Un POST de Meta firmado con el App Secret. */
    private function entrante(array $mensaje, ?string $firma = null, string $numero = '999')
    {
        return $this->entrantes([$mensaje], $firma, $numero);
    }

    /** Lo mismo, con los mensajes que Meta agrupó en un solo POST. */
    private function entrantes(array $mensajes, ?string $firma = null, string $numero = '999')
    {
        $cuerpo = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $numero],
            'contacts' => [['profile' => ['name' => 'Ana']]],
            'messages' => $mensajes,
        ]]]]]]);

        $res = $this->call('POST', '/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $firma ?? 'sha256=' . hash_hmac('sha256', $cuerpo, self::SECRETO),
        ], $cuerpo);

        // `afterResponse()` registra un callback de terminación en la APLICACIÓN,
        // y Application::terminate() no los borra. En el servidor da igual —un
        // proceso por request—, pero acá la aplicación sobrevive entre requests y
        // el trabajo del primero se volvería a ejecutar al terminar el segundo.
        (new \ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);

        return $res;
    }

    private function texto(string $t, string $de = self::ANA, ?string $wamid = null)
    {
        return $this->entrante(['from' => $de, 'id' => $wamid ?? 'wamid.' . (++$this->wamid), 'type' => 'text', 'text' => ['body' => $t]]);
    }

    /** Solo los mensajes enviados a una persona (no el «leído / escribiendo…»). */
    private function enviados(): array
    {
        return Http::recorded(fn ($req) => isset($req->data()['to']))->map(fn ($par) => $par[0]->data())->values()->all();
    }

    private function guion(array ...$respuestas): void
    {
        config(['emr.asistente_ia_activo' => true, 'emr.anthropic_api_key' => 'clave-de-prueba', 'emr.asistente_tokens_dia' => 300000]);
        $this->app->instance(ClaudeMensajes::class, new class($respuestas) extends ClaudeMensajes {
            public function __construct(private array $cola)
            {
            }

            public function crear(array $system, array $tools, array $messages): object
            {
                $r = array_shift($this->cola);

                return json_decode(json_encode([
                    'stopReason' => $r[0], 'content' => $r[1],
                    'usage' => ['inputTokens' => 100, 'outputTokens' => 20],
                ]));
            }
        });
    }

    private function citasFalso(): void
    {
        $test = $this;
        $falso = Mockery::mock(\App\Http\Controllers\Api\CitasController::class);
        $falso->shouldReceive('reservar')->andReturnUsing(function ($request) use ($test) {
            $test->reservas[] = $request->all();

            return response()->json(['id' => self::UUID, 'estado' => 'Agendada', 'monto_abono_clp' => 22500]);
        });
        $this->app->instance(\App\Http\Controllers\Api\CitasController::class, $falso);
    }

    private static function preparar(): array
    {
        // Sin teléfono: en WhatsApp lo pone el código.
        return ['tool_use', [['type' => 'tool_use', 'id' => 't1', 'name' => 'preparar_reserva', 'input' => [
            'servicio_id' => self::CD, 'especialista_id' => self::NA, 'fecha' => '2026-09-17', 'hora' => '10:30',
            'tutor' => 'Ana Pérez', 'correo' => 'ana@correo.cl', 'mascota' => 'Toby',
        ]]]];
    }

    private static function confirmar(): array
    {
        return ['tool_use', [['type' => 'tool_use', 'id' => 't2', 'name' => 'confirmar_reserva', 'input' => []]]];
    }

    private static function dice(string $t): array
    {
        return ['end_turn', [['type' => 'text', 'text' => $t]]];
    }

    // ── Webhook ───────────────────────────────────────────────────────────

    public function test_firma_invalida_no_contesta(): void
    {
        $this->entrante(['from' => self::ANA, 'id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'hola']], 'sha256=falsa')
            ->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_reintento_de_meta_no_se_contesta_dos_veces(): void
    {
        $this->texto('hola', self::ANA, 'wamid.repetido')->assertOk();
        $this->texto('hola', self::ANA, 'wamid.repetido')->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertCount(1, $this->enviados());
    }

    public function test_los_mensajes_que_meta_agrupa_en_un_post_se_contestan_todos(): void
    {
        // `messages` es un array porque Meta junta lo que llega seguido. Con
        // solo el [0] atendido, quien escribe en ráfaga recibe una respuesta y
        // silencio al resto.
        $this->entrantes([
            ['from' => self::ANA, 'id' => 'wamid.a', 'type' => 'text', 'text' => ['body' => 'Quiero reservar una hora']],
            ['from' => self::ANA, 'id' => 'wamid.b', 'type' => 'text', 'text' => ['body' => '¿aceptan perros de raza peligrosa en la sala de espera?']],
        ])->assertOk()->assertJson(['status' => 'received']);

        $this->assertCount(2, $this->enviados());
    }

    public function test_mensaje_a_otro_numero_de_la_app_se_ignora(): void
    {
        $this->entrante(['from' => self::ANA, 'id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'hola']], null, '123')
            ->assertOk()->assertJson(['status' => 'ignored']);

        Http::assertNothingSent();
    }

    public function test_foto_recibe_aviso_fijo(): void
    {
        $this->entrante(['from' => self::ANA, 'id' => 'wamid.1', 'type' => 'image', 'image' => ['id' => 'x']])->assertOk();

        $enviado = $this->enviados()[0];
        $this->assertSame('text', $enviado['type']);
        $this->assertStringContainsString('no alcancé a ver la imagen', $enviado['text']['body']);
    }

    public function test_apagado_desde_el_panel_contesta_el_aviso_sin_modelo(): void
    {
        DB::table('ajustes')->insert(['id' => 1, 'data' => json_encode(['asistente' => ['activo_whatsapp' => false]])]);
        $this->guion(); // cola vacía: si llamara al modelo, fallaría

        $this->texto('quiero una hora')->assertOk();

        $this->assertStringContainsString('no estoy disponible', $this->enviados()[0]['text']['body']);
        $this->assertSame(0, DB::table('uso_asistente')->count());
    }

    public function test_sin_credenciales_de_envio_no_revienta(): void
    {
        config(['emr.whatsapp_access_token' => '']);

        $this->texto('hola')->assertOk();

        Http::assertNothingSent();
    }

    // ── Sin IA ────────────────────────────────────────────────────────────

    public function test_sin_ia_pedir_hora_llega_con_un_enlace_absoluto_al_portal(): void
    {
        $this->texto('Quiero reservar una hora')->assertOk();

        $e = $this->enviados()[0];
        $this->assertSame('cta_url', $e['interactive']['type']);
        $this->assertSame('https://reservas.test/reservar', $e['interactive']['action']['parameters']['url']);
        // Varias consultas: se listan en el texto, un solo enlace.
        $this->assertStringContainsString('Consulta Dermatológica', $e['interactive']['body']['text']);
        $this->assertSame('whatsapp', DB::table('chatbot_preguntas')->value('canal'));
    }

    public function test_sin_ia_lo_que_no_sabe_deriva_a_llamar_y_no_a_whatsapp(): void
    {
        $this->texto('¿aceptan perros de raza peligrosa en la sala de espera?')->assertOk();

        $cuerpo = $this->enviados()[0]['text']['body'];
        $this->assertStringContainsString('Llámanos', $cuerpo);
        $this->assertStringNotContainsString('por WhatsApp', $cuerpo);
    }

    // ── Con IA ────────────────────────────────────────────────────────────

    public function test_con_ia_reserva_con_el_telefono_del_chat_y_sigue_la_conversacion_por_numero(): void
    {
        $this->citasFalso();
        $this->guion(self::preparar(), self::dice('Consulta Dermatológica, jueves 17 a las 10:30. ¿Confirmo?'),
            self::confirmar(), self::dice('Listo, tu hora quedó reservada.'));

        $this->texto('Quiero reservar dermatología el jueves 10:30, soy Ana Pérez, ana@correo.cl, mi perro Toby')->assertOk();
        $this->assertSame([], $this->reservas);

        // Sin conversation_id: la conversación es el número.
        $this->texto('sí')->assertOk();

        $this->assertCount(1, $this->reservas);
        $this->assertSame('+' . self::ANA, $this->reservas[0]['telefono']);
        $this->assertSame('WhatsApp (asistente IA)', $this->reservas[0]['respuestas']['Canal']);

        $pago = $this->enviados()[1];
        $this->assertSame('https://reservas.test/pago/' . self::UUID, $pago['interactive']['action']['parameters']['url']);
        // «Pagar abono de $22.500» no cabe en 20 caracteres: el monto va en el cuerpo, entero.
        $this->assertStringContainsString('$22.500', $pago['interactive']['body']['text']);
        $this->assertSame('Pagar', $pago['interactive']['action']['parameters']['display_text']);

        $this->assertSame(1, DB::table('chatbot_conversations')->where('telefono', self::ANA)->count());
    }

    public function test_el_tope_de_reservas_es_por_numero_y_no_por_ip(): void
    {
        $this->citasFalso();
        // El «sí» lo confirma el código: el modelo solo prepara.
        $ciclo = fn () => [self::preparar(), self::dice('¿Confirmo?')];
        $this->guion(...array_merge($ciclo(), $ciclo(), $ciclo(), $ciclo()));
        $telefonos = ['56911111111', '56922222222', '56933333333', '56944444444'];

        // Cuatro personas, una IP (la de Meta). El tope es 3 por día: contado por
        // IP, la cuarta se quedaría sin hora por culpa de las otras tres.
        foreach ($telefonos as $tel) {
            $this->texto('Quiero reservar', $tel);
            $this->texto('sí', $tel);
        }

        $this->assertSame(array_map(fn ($t) => '+' . $t, $telefonos), array_column($this->reservas, 'telefono'));
    }
}
