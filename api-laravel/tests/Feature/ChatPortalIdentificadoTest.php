<?php

namespace Tests\Feature;

use App\Services\AgenteIA;
use App\Services\ClaudeMensajes;
use App\Services\SlotsService;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * El chat del portal con SESIÓN y con archivo (07-oct-2026).
 *
 * Hasta acá el chat del portal era anónimo: no podía ver ni tocar ninguna hora y
 * no recibía archivos. Ahora, con la sesión de la cuenta, hace lo que WhatsApp
 * hace con el número verificado, y recibe la orden médica por el mismo POST que
 * el texto. Lo que se fija es lo que el CÓDIGO garantiza aunque el modelo se
 * equivoque o alguien lo empuje:
 *
 *  · la sesión sale del middleware, nunca del cuerpo, y una inválida no rompe el
 *    chat: atiende como anónimo;
 *  · cada cuenta ve y toca SOLO sus horas;
 *  · una conversación es de quien la abrió: un id ajeno no la abre;
 *  · el archivo se acepta por lo que ES (no por su nombre), con tope, y sin IA
 *    no se guarda nada;
 *  · con sesión, la reserva sale con los datos de la CUENTA y no con los que
 *    ponga el modelo;
 *  · lo del especialista de coordinar sigue siendo solo de WhatsApp.
 */
#[RunTestsInSeparateProcesses]
class ChatPortalIdentificadoTest extends TestCase
{
    private const SECRETO_JWT = 'secreto-de-test-con-largo-suficiente-para-hs256';

    private const CG = '11111111-1111-4111-8111-111111111111';

    private const ECO = '33333333-3333-4333-8333-333333333333';

    private const NA = '44444444-4444-4444-8444-444444444444';

    private array $llamadas = [];

    private string $datos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::create(2026, 9, 14, 9, 0, 0, 'America/Santiago'));
        config(['app.jwt_secret' => self::SECRETO_JWT]);

        $this->datos = sys_get_temp_dir() . '/emr-chat-portal-' . bin2hex(random_bytes(4));
        mkdir($this->datos);
        config([
            'emr.documentos_dir' => $this->datos . '/documentos_clinicos',
            'emr.clinica_telefono' => '+569 0000 0000',
            'emr.asistente_ia_activo' => true,
            'emr.anthropic_api_key' => 'clave-de-prueba',
            'emr.asistente_tokens_dia' => 300000,
        ]);

        foreach (['servicios', 'especialistas', 'servicio_especialista', 'ajustes', 'chatbot_conversations',
            'uso_asistente', 'chatbot_preguntas', 'usuarios', 'mascotas', 'citas'] as $t) {
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
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email'); $t->string('nombre')->nullable();
            $t->string('telefono')->nullable(); $t->string('rol'); $t->boolean('es_activo')->default(true);
        });
        Schema::create('mascotas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id'); $t->string('nombre');
        });
        Schema::create('citas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('mascota_id'); $t->string('usuario_dueno_id');
            $t->string('fecha_hora'); $t->string('servicio')->nullable(); $t->string('servicio_id')->nullable();
            $t->string('especialista_id')->nullable(); $t->string('estado'); $t->text('respuestas')->nullable();
            $t->integer('monto_abono_clp')->nullable(); $t->integer('monto_total_clp')->nullable();
            $t->string('payment_status')->nullable(); $t->string('pago_modalidad_requerida')->nullable();
            $t->string('pago_modalidad')->nullable(); $t->string('created_at')->nullable();
        });

        DB::table('servicios')->insert(['id' => self::CG, 'nombre' => 'Consulta General']);
        DB::table('servicios')->insert(['id' => self::ECO, 'nombre' => 'Ecografía', 'requiere_orden_examen' => true, 'orden' => 1]);
        DB::table('especialistas')->insert(['id' => self::NA, 'nombre' => 'Dra. Rivas']);
        DB::table('usuarios')->insert([
            ['id' => 'ana', 'email' => 'ana@x.cl', 'nombre' => 'Ana Pérez', 'telefono' => '+56 9 1234 5678', 'rol' => 'dueño'],
            ['id' => 'beto', 'email' => 'beto@x.cl', 'nombre' => 'Beto Soto', 'telefono' => '+56 9 8888 7777', 'rol' => 'dueño'],
        ]);
        DB::table('mascotas')->insert([
            ['id' => 'toby', 'usuario_id' => 'ana', 'nombre' => 'Toby'],
            ['id' => 'luna', 'usuario_id' => 'beto', 'nombre' => 'Luna'],
        ]);
        foreach ([['c-ana', 'toby', 'ana'], ['c-beto', 'luna', 'beto']] as [$id, $m, $u]) {
            DB::table('citas')->insert([
                'id' => $id, 'mascota_id' => $m, 'usuario_dueno_id' => $u, 'fecha_hora' => '2026-09-17 10:00:00',
                'servicio' => 'Consulta General', 'servicio_id' => self::CG, 'especialista_id' => self::NA,
                'estado' => 'Agendada', 'respuestas' => '{}', 'created_at' => '2026-09-10 15:00:00.000000',
            ]);
        }

        $slots = Mockery::mock(SlotsService::class);
        $slots->shouldReceive('para')->andReturn(['2026-09-17' => ['10:30', '11:00']]);
        $this->app->instance(SlotsService::class, $slots);
        $cobro = Mockery::mock(\App\Services\CobroService::class);
        $cobro->shouldReceive('montoAbono')->andReturn(22500);
        $this->app->instance(\App\Services\CobroService::class, $cobro);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->datos)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->datos, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->datos);
        }
        parent::tearDown();
    }

    // ── Ayudas ────────────────────────────────────────────────────────────

    private function guion(array ...$respuestas): void
    {
        $test = $this;
        $this->app->instance(ClaudeMensajes::class, new class($respuestas, $test) extends ClaudeMensajes {
            public function __construct(private array $cola, private $test)
            {
            }

            public function crear(array $system, array $tools, array $messages): object
            {
                $this->test->anotar($tools, $messages, $system);
                $r = array_shift($this->cola);
                if ($r === null) {
                    throw new \RuntimeException('el guion no esperaba otra llamada');
                }

                return json_decode(json_encode([
                    'stopReason' => $r[0], 'content' => $r[1],
                    'usage' => ['inputTokens' => 100, 'outputTokens' => 20],
                ]));
            }
        });
    }

    public function anotar(array $tools, array $messages, array $system = []): void
    {
        $this->llamadas[] = ['tools' => array_column($tools, 'name'), 'messages' => $messages,
            'system' => implode(' ', array_column($system, 'text'))];
    }

    private function resultado(int $llamada): array
    {
        $ultimo = end($this->llamadas[$llamada]['messages']);

        return json_decode($ultimo['content'][0]['content'], true);
    }

    private static function usa(string $nombre, array $input = []): array
    {
        return ['tool_use', [['type' => 'tool_use', 'id' => 't' . mt_rand(), 'name' => $nombre, 'input' => $input ?: new \stdClass]]];
    }

    private static function dice(string $t): array
    {
        return ['end_turn', [['type' => 'text', 'text' => $t]]];
    }

    private static function token(string $id, string $email): string
    {
        return JWT::encode(['sub' => $email, 'rol' => 'dueño', 'user_id' => $id, 'exp' => time() + 3600],
            self::SECRETO_JWT, 'HS256');
    }

    /** POST al chat. `$sesion`: null (anónimo) o [id, email]. Multipart si hay archivo. */
    private function chat(array $datos, ?array $sesion = null)
    {
        $cabeceras = ['Accept' => 'application/json'];
        if ($sesion) {
            $cabeceras['Authorization'] = 'Bearer ' . self::token(...$sesion);
        }

        return $this->withHeaders($cabeceras)->post('/chatbot/mensaje', $datos);
    }

    private function estado(string $conversacion): array
    {
        return json_decode((string) DB::table('chatbot_conversations')->where('id', $conversacion)->value('estado'), true) ?: [];
    }

    private function pdf(string $nombre = 'orden.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function pendientes(): array
    {
        return glob($this->datos . '/documentos_clinicos/whatsapp-pendientes/*') ?: [];
    }

    // ── La sesión ─────────────────────────────────────────────────────────

    public function test_sin_sesion_el_chat_atiende_como_anonimo(): void
    {
        $this->guion(self::dice('Hola.'));

        $this->chat(['texto' => 'hola'])->assertOk();

        $this->assertNotContains('mis_citas', $this->llamadas[0]['tools']);
    }

    public function test_un_token_invalido_no_rompe_el_chat_y_atiende_como_anonimo(): void
    {
        // Una sesión del portal que caducó no puede dejar mudo al asistente.
        $this->guion(self::dice('Hola.'));

        $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer basura.que.no.es.jwt'])
            ->post('/chatbot/mensaje', ['texto' => 'hola'])->assertOk();

        $this->assertNotContains('mis_citas', $this->llamadas[0]['tools']);
    }

    public function test_el_usuario_en_el_cuerpo_no_cuenta_solo_el_token(): void
    {
        $this->guion(self::dice('Hola.'));

        $this->chat(['texto' => 'hola', 'usuario_id' => 'ana', 'usuario' => 'ana'])->assertOk();

        $this->assertNotContains('mis_citas', $this->llamadas[0]['tools']);
    }

    public function test_con_sesion_existen_las_herramientas_de_horas_propias_pero_no_las_de_coordinar(): void
    {
        $this->guion(self::dice('Hola.'));

        $this->chat(['texto' => 'hola'], ['ana', 'ana@x.cl'])->assertOk();

        foreach (['mis_citas', 'preparar_cambio', 'confirmar_cambio', 'link_pago', 'adjuntar_orden'] as $t) {
            $this->assertContains($t, $this->llamadas[0]['tools']);
        }
        // El intermediario con el especialista habla por teléfono: solo WhatsApp.
        foreach (['preparar_contacto', 'confirmar_contacto', 'mensaje_al_especialista'] as $t) {
            $this->assertNotContains($t, $this->llamadas[0]['tools']);
        }
    }

    public function test_cada_cuenta_ve_solo_sus_horas(): void
    {
        $this->guion(self::usa('mis_citas'), self::dice('Tienes una hora.'));

        $this->chat(['texto' => 'mis horas'], ['ana', 'ana@x.cl'])->assertOk();

        $r = $this->resultado(1);
        $this->assertCount(1, $r['citas']);
        $this->assertSame('Toby', $r['citas'][0]['mascota']);
        $this->assertStringNotContainsString('Luna', json_encode($r));
    }

    public function test_el_estado_verificado_de_la_cuenta_no_incluye_las_horas_de_otra(): void
    {
        $this->guion(self::dice('Hola.'));

        $this->chat(['texto' => 'hola'], ['beto', 'beto@x.cl'])->assertOk();

        $sistema = json_encode($this->llamadas[0]['messages']);
        // El estado verificado va en las instrucciones de sistema, no en los
        // mensajes; lo que sí se comprueba es que Beto no vea a Toby por ningún lado.
        $this->assertStringNotContainsString('Toby', $sistema);
    }

    /**
     * En vivo (lab, 12-oct): un tutor copió de Recordatorios «Vacuna triple
     * felina Venció hace 5 días» y el asistente contestó que no tenía
     * registrado si esa vacuna se aplicaba. Ahora ve los cuidados de su cuenta.
     */
    public function test_el_asistente_ve_los_cuidados_programados_de_la_cuenta(): void
    {
        Schema::dropIfExists('recordatorios');
        Schema::create('recordatorios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('mascota_id'); $t->string('usuario_dueno_id');
            $t->string('titulo'); $t->string('fecha_programada'); $t->string('estado');
        });
        DB::table('recordatorios')->insert([
            ['id' => 'r1', 'mascota_id' => 'toby', 'usuario_dueno_id' => 'ana', 'titulo' => 'Vacuna triple felina',
                'fecha_programada' => '2026-09-09', 'estado' => 'pendiente'],
            // De hace años (import de ACVet): ya no es un pendiente real.
            ['id' => 'r2', 'mascota_id' => 'toby', 'usuario_dueno_id' => 'ana', 'titulo' => 'Vacuna antirrábica',
                'fecha_programada' => '2024-03-01', 'estado' => 'pendiente'],
            ['id' => 'r3', 'mascota_id' => 'toby', 'usuario_dueno_id' => 'ana', 'titulo' => 'Desparasitación interna',
                'fecha_programada' => '2026-09-01', 'estado' => 'completado'],
            ['id' => 'r4', 'mascota_id' => 'luna', 'usuario_dueno_id' => 'beto', 'titulo' => 'Vacuna séxtuple',
                'fecha_programada' => '2026-09-20', 'estado' => 'pendiente'],
        ]);
        $this->guion(self::dice('Para Toby te busco hora.'));

        $this->chat(['texto' => 'Vacuna triple felina Venció hace 5 días'], ['ana', 'ana@x.cl'])->assertOk();

        $sistema = $this->llamadas[0]['system'];
        $this->assertStringContainsString('Vacuna triple felina de Toby, venció el miércoles 9 de septiembre', $sistema);
        $this->assertStringContainsString('nunca digas que no sabes si se hace', $sistema);
        $this->assertStringNotContainsString('antirrábica', $sistema);
        $this->assertStringNotContainsString('Desparasitación', $sistema);
        $this->assertStringNotContainsString('séxtuple', $sistema, 'los de otra cuenta no');
    }

    /**
     * La pregunta frecuente de las vacunas (12-oct) contesta a todos sin
     * modelo, salvo a quien nombra un cuidado de SU cuenta: ese sabe para qué
     * mascota es, y lo atiende el modelo para buscarle hora.
     */
    public function test_un_cuidado_propio_va_al_modelo_y_la_pregunta_de_vacunas_contesta_al_resto(): void
    {
        Schema::dropIfExists('recordatorios');
        Schema::create('recordatorios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('mascota_id'); $t->string('usuario_dueno_id');
            $t->string('titulo'); $t->string('fecha_programada'); $t->string('estado');
        });
        DB::table('recordatorios')->insert(['id' => 'r1', 'mascota_id' => 'toby', 'usuario_dueno_id' => 'ana',
            'titulo' => 'Vacuna triple felina', 'fecha_programada' => '2026-09-09', 'estado' => 'pendiente']);
        DB::table('ajustes')->insert(['id' => 1, 'data' => json_encode(['asistente' => ['datos' => ['preguntas' => [
            ['claves' => 'triple felina, vacunar, la vacuna', 'respuesta' => 'Las vacunas se aplican aquí en la clínica, en una Consulta General.'],
        ]]]])]);
        $this->guion(self::dice('¿Te busco hora para Toby?'));

        // El anónimo primero: withHeaders() deja pegada la sesión para las peticiones siguientes.
        $anonimo = $this->chat(['texto' => 'Vacuna triple felina Venció hace 5 días'])->assertOk();
        $conAna = $this->chat(['texto' => 'Vacuna triple felina Venció hace 5 días'], ['ana', 'ana@x.cl'])->assertOk();

        $this->assertCount(1, $this->llamadas, 'solo Ana pasa por el modelo');
        $this->assertSame('¿Te busco hora para Toby?', $conAna->json('respuesta'));
        $this->assertStringContainsString('en una Consulta General', $anonimo->json('respuesta'));
    }

    public function test_no_se_puede_cambiar_la_hora_de_otra_cuenta_aunque_el_modelo_la_nombre(): void
    {
        // El modelo intenta tocar la hora «1» sin haber llamado mis_citas, o con
        // la referencia de otra cuenta: la tabla de referencias se arma solo con
        // las horas de quien escribe.
        $this->guion(self::usa('preparar_cambio', ['cita' => 'c-ana', 'accion' => 'cancelar']),
            self::dice('No encuentro esa hora.'));

        $this->chat(['texto' => 'cancela la de Ana'], ['beto', 'beto@x.cl'])->assertOk();

        $this->assertStringContainsString('No encuentro esa hora', $this->resultado(1)['error']);
    }

    // ── La conversación es de quien la abrió ──────────────────────────────

    public function test_una_conversacion_con_sesion_no_la_retoma_otra_cuenta_ni_un_anonimo(): void
    {
        $this->guion(self::dice('Uno.'), self::dice('Dos.'), self::dice('Tres.'), self::dice('Cuatro.'));

        $conv = $this->chat(['texto' => 'hola'], ['ana', 'ana@x.cl'])->assertOk()->json('conversation_id');
        $this->assertNotSame('', $conv);
        $this->assertSame('ana', DB::table('chatbot_conversations')->where('id', $conv)->value('usuario_id'));

        // Beto manda el id de la conversación de Ana: se abre una NUEVA.
        $deBeto = $this->chat(['texto' => 'hola', 'conversation_id' => $conv], ['beto', 'beto@x.cl'])->json('conversation_id');
        $this->assertNotSame($conv, $deBeto);

        // Un anónimo, igual.
        $anonima = $this->chat(['texto' => 'hola', 'conversation_id' => $conv])->json('conversation_id');
        $this->assertNotSame($conv, $anonima);

        // La propia dueña sí la retoma.
        $this->assertSame($conv, $this->chat(['texto' => 'sigo', 'conversation_id' => $conv], ['ana', 'ana@x.cl'])->json('conversation_id'));
    }

    public function test_una_conversacion_anonima_no_la_retoma_una_sesion(): void
    {
        $this->guion(self::dice('Uno.'), self::dice('Dos.'));

        $conv = $this->chat(['texto' => 'hola'])->assertOk()->json('conversation_id');
        $this->assertNull(DB::table('chatbot_conversations')->where('id', $conv)->value('usuario_id'));

        $otra = $this->chat(['texto' => 'hola', 'conversation_id' => $conv], ['ana', 'ana@x.cl'])->json('conversation_id');

        $this->assertNotSame($conv, $otra);
    }

    // ── La reserva con sesión ─────────────────────────────────────────────

    public function test_con_sesion_la_reserva_sale_con_los_datos_de_la_cuenta_y_no_los_del_modelo(): void
    {
        // El modelo pone un correo y un nombre ajenos: no cuentan.
        $this->guion(
            self::usa('preparar_reserva', ['servicio_id' => self::CG, 'especialista_id' => self::NA, 'fecha' => '2026-09-17',
                'hora' => '10:30', 'tutor' => 'Otro Nombre', 'correo' => 'victima@x.cl', 'mascota' => 'Toby']),
            self::dice('Resumen. ¿Confirmo?'),
        );

        $this->chat(['texto' => 'quiero hora el jueves a las 10:30 para Toby'], ['ana', 'ana@x.cl'])->assertOk();

        $resumen = $this->resultado(1)['resumen'];
        $this->assertSame('ana@x.cl', $resumen['correo']);
        $this->assertSame('Ana Pérez', $resumen['tutor']);
        $this->assertStringNotContainsString('victima@x.cl', json_encode($this->resultado(1)));
    }

    public function test_sin_sesion_la_reserva_sigue_pidiendo_los_datos(): void
    {
        $this->guion(
            self::usa('preparar_reserva', ['servicio_id' => self::CG, 'especialista_id' => self::NA, 'fecha' => '2026-09-17',
                'hora' => '10:30', 'mascota' => 'Toby']),
            self::dice('¿Me das tu nombre y tu correo?'),
        );

        $this->chat(['texto' => 'quiero hora'])->assertOk();

        $this->assertStringContainsString('Faltan datos', $this->resultado(1)['error']);
    }

    // ── La orden médica por el chat ───────────────────────────────────────

    public function test_el_archivo_viaja_con_el_mensaje_y_queda_guardado_en_la_conversacion(): void
    {
        $this->guion(self::dice('Recibí la orden. ¿Para qué mascota es?'));

        $r = $this->chat(['texto' => 'te mando la orden', 'archivo' => $this->pdf()], ['ana', 'ana@x.cl'])->assertOk();

        $estado = $this->estado($r->json('conversation_id'));
        $this->assertSame('orden.pdf', $estado['adjunto']['nombre']);
        $this->assertSame('pdf', $estado['adjunto']['ext']);
        $this->assertCount(1, $this->pendientes());
        $this->assertFileExists($estado['adjunto']['ruta']);
        // Dentro de documentos_clinicos, que no sirve nadie (§9.16).
        $this->assertStringStartsWith($this->datos . '/documentos_clinicos/', $estado['adjunto']['ruta']);
    }

    public function test_el_archivo_solo_sin_texto_tambien_se_atiende(): void
    {
        $this->guion(self::dice('Recibí la orden.'));

        $r = $this->chat(['archivo' => $this->pdf()])->assertOk();

        $this->assertNotSame('', $r->json('conversation_id'));
        $this->assertCount(1, $this->pendientes());
        // «Te mando un archivo.» no es una pregunta que enseñar.
        $this->assertSame(0, DB::table('chatbot_preguntas')->count());
    }

    public function test_un_ejecutable_renombrado_a_pdf_se_rechaza_por_su_contenido(): void
    {
        $falso = UploadedFile::fake()->createWithContent('orden.pdf', "MZ\x90\x00 esto es un ejecutable");

        $r = $this->chat(['texto' => 'la orden', 'archivo' => $falso])->assertOk();

        $this->assertStringContainsString('foto o como PDF', $r->json('respuesta'));
        $this->assertSame([], $this->pendientes());
        $this->assertSame(0, DB::table('chatbot_conversations')->count());
    }

    public function test_un_archivo_de_mas_de_10_mb_se_rechaza_con_un_mensaje_claro(): void
    {
        $grande = UploadedFile::fake()->create('orden.pdf', 11000, 'application/pdf'); // KB

        $r = $this->chat(['texto' => 'la orden', 'archivo' => $grande])->assertOk();

        $this->assertStringContainsString('más de 10 MB', $r->json('respuesta'));
        $this->assertSame([], $this->pendientes());
    }

    public function test_sin_ia_el_archivo_no_se_guarda(): void
    {
        config(['emr.asistente_ia_activo' => false]);

        $r = $this->chat(['texto' => 'la orden', 'archivo' => $this->pdf()])->assertOk();

        $this->assertStringContainsString('adjuntarla al reservar', $r->json('respuesta'));
        $this->assertSame([], $this->pendientes());
    }

    public function test_un_segundo_archivo_reemplaza_al_primero_y_no_se_acumulan(): void
    {
        $this->guion(self::dice('Uno.'), self::dice('Dos.'));

        $conv = $this->chat(['texto' => 'la orden', 'archivo' => $this->pdf('uno.pdf')])->json('conversation_id');
        $this->chat(['texto' => 'mejor esta', 'archivo' => $this->pdf('dos.pdf'), 'conversation_id' => $conv])->assertOk();

        $this->assertCount(1, $this->pendientes());
        $this->assertSame('dos.pdf', $this->estado($conv)['adjunto']['nombre']);
    }

    public function test_con_la_orden_recibida_la_consulta_que_la_exige_si_se_prepara(): void
    {
        $this->guion(
            self::usa('preparar_reserva', ['servicio_id' => self::ECO, 'especialista_id' => self::NA, 'fecha' => '2026-09-17',
                'hora' => '10:30', 'tutor' => 'x', 'correo' => 'x@x.cl', 'mascota' => 'Toby']),
            self::dice('Resumen. ¿Confirmo?'),
        );

        $this->chat(['texto' => 'ecografía para Toby, te mando la orden', 'archivo' => $this->pdf()], ['ana', 'ana@x.cl'])->assertOk();

        $resumen = $this->resultado(1)['resumen'];
        $this->assertSame('se adjunta orden.pdf', $resumen['orden_medica']);
    }

    public function test_sin_la_orden_la_consulta_que_la_exige_no_se_prepara(): void
    {
        $this->guion(
            self::usa('preparar_reserva', ['servicio_id' => self::ECO, 'especialista_id' => self::NA, 'fecha' => '2026-09-17',
                'hora' => '10:30', 'tutor' => 'x', 'correo' => 'x@x.cl', 'mascota' => 'Toby']),
            self::dice('Mándame la orden.'),
        );

        $this->chat(['texto' => 'ecografía para Toby'], ['ana', 'ana@x.cl'])->assertOk();

        $this->assertStringContainsString('requiere la orden médica', $this->resultado(1)['error']);
    }
}
