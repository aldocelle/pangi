<?php

namespace Tests\Feature;

use App\Console\Commands\EstadoRevisar;
use App\Services\Correo;
use App\Services\EstadoSistema;
use App\Services\GoogleCalendar;
use App\Services\GoogleOAuth;
use App\Services\Jwt;
use App\Services\WhatsappEnvio;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * Config → Estado del sistema y sus alertas (decisión de producto, 05-oct): las
 * tres cosas que fallan en silencio —token de WhatsApp, Google, IA sin cupo o
 * sin saldo— se ven en el panel y avisan por correo.
 */
#[RunTestsInSeparateProcesses]
class EstadoSistemaTest extends TestCase
{
    private array $correos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::create(2026, 10, 5, 21, 0, 0, 'America/Santiago'));
        config([
            'app.jwt_secret' => 'secreto-de-test-con-largo-suficiente-para-hs256',
            'emr.whatsapp_access_token' => 'token', 'emr.whatsapp_phone_number_id' => '999',
            'emr.asistente_ia_activo' => true, 'emr.anthropic_api_key' => 'clave', 'emr.asistente_tokens_dia' => 1000,
        ]);
        Cache::flush();
        foreach (['ajustes', 'usuarios', 'uso_asistente'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('ajustes', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->text('data')->nullable();
            $t->string('google_account_id')->nullable(); $t->string('calendario_reservas')->nullable();
        });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email')->nullable(); $t->string('nombre')->nullable();
            $t->string('rol'); $t->boolean('es_activo')->default(true); $t->text('permisos')->nullable();
            $t->integer('sesion_version')->default(0);
        });
        Schema::create('uso_asistente', function (Blueprint $t) {
            $t->string('fecha')->primary(); $t->integer('llamadas')->default(0);
            $t->integer('tokens_entrada')->default(0); $t->integer('tokens_salida')->default(0);
        });
        DB::table('ajustes')->insert(['id' => 1, 'data' => '{}']);
        DB::table('usuarios')->insert([
            ['id' => 'jefe', 'email' => 'jefe@x.cl', 'rol' => 'admin', 'es_activo' => true],
            ['id' => 'ex', 'email' => 'ex@x.cl', 'rol' => 'admin', 'es_activo' => false],
            ['id' => 'vet', 'email' => 'vet@x.cl', 'rol' => 'veterinario', 'es_activo' => true],
        ]);

        // Google conectado y sano, salvo que el test diga otra cosa.
        $this->google(accesible: true);
        $correo = Mockery::mock(Correo::class);
        $correo->shouldReceive('enviar')->andReturnUsing(function ($para, $asunto) {
            $this->correos[] = [$para, $asunto];

            return true;
        });
        $this->app->instance(Correo::class, $correo);
    }

    private function google(bool $accesible = true, bool $token = true): void
    {
        $oauth = Mockery::mock(GoogleOAuth::class);
        $oauth->shouldReceive('estaConfigurado')->andReturn(true);
        $oauth->shouldReceive('token')->andReturn($token ? 'tok' : null);
        $this->app->instance(GoogleOAuth::class, $oauth);
        $cal = Mockery::mock(GoogleCalendar::class);
        $cal->shouldReceive('calendarioClinica')->andReturn([(object) ['email' => 'clinica@gmail.com'], 'cal-1']);
        $cal->shouldReceive('calendarioAccesible')->andReturn($accesible);
        $this->app->instance(GoogleCalendar::class, $cal);
    }

    /** Lo que contesta Meta. Un solo fake con estado: `Http::fake` acumula y el primero gana. */
    private array $respuestaMeta = [200, ['display_phone_number' => '+56 9 0000 6251']];

    private function meta(int $status = 200, array $cuerpo = ['display_phone_number' => '+56 9 0000 6251']): void
    {
        $this->respuestaMeta = [$status, $cuerpo];
        static $armado = false;
        if (! $armado) {
            $armado = true;
            Http::fake([
                'graph.facebook.com/*' => fn () => Http::response($this->respuestaMeta[1], $this->respuestaMeta[0]),
                'api.anthropic.com/*' => Http::response(['data' => []]),
            ]);
        }
    }

    private function pieza(array $r, string $id): array
    {
        return collect($r['servicios'])->firstWhere('id', $id);
    }

    public function test_todo_bien(): void
    {
        $this->meta();
        $r = app(EstadoSistema::class)->revisar(true);

        $this->assertSame('ok', $r['peor']);
        $this->assertSame('Conectado (+56 9 0000 6251)', $this->pieza($r, 'whatsapp')['resumen']);
        $this->assertSame('Conectado (clinica@gmail.com)', $this->pieza($r, 'google')['resumen']);
        $this->assertSame('ok', $this->pieza($r, 'ia')['estado']);
    }

    public function test_token_de_whatsapp_vencido_es_error_con_que_hacer(): void
    {
        $this->meta(401, ['error' => ['code' => 190, 'message' => 'Session has expired']]);
        $w = $this->pieza(app(EstadoSistema::class)->revisar(true), 'whatsapp');

        $this->assertSame('error', $w['estado']);
        $this->assertSame('Token vencido: el bot no puede responder', $w['resumen']);
        $this->assertStringContainsString('WHATSAPP_ACCESS_TOKEN', $w['que_hacer']);
    }

    public function test_un_envio_rechazado_con_190_es_error_aunque_no_se_sondee(): void
    {
        Cache::put(WhatsappEnvio::CLAVE_ERROR, ['at' => now()->toIso8601String(), 'codigo' => 190,
            'explicacion' => WhatsappEnvio::explicarError(190)]);
        $this->assertSame('error', $this->pieza(app(EstadoSistema::class)->revisar(false), 'whatsapp')['estado']);
    }

    /** Lo que pasaba en producción desde el 2-oct, visible solo en el log. */
    public function test_google_sin_acceso_al_calendario_o_sincronizacion_detenida_es_error(): void
    {
        $this->meta();
        $this->google(accesible: false);
        $g = $this->pieza(app(EstadoSistema::class)->revisar(true), 'google');
        $this->assertSame('La cuenta no ve el calendario', $g['resumen']);
        $this->assertStringContainsString('Config → Integraciones', $g['que_hacer']);

        $this->google(accesible: true);
        Cache::forever(EstadoSistema::GOOGLE_SINCRONIZACION, ['ok' => false, 'motivo' => 'detenida', 'at' => now()->toIso8601String()]);
        $this->assertSame('Sincronización detenida', $this->pieza(app(EstadoSistema::class)->revisar(true), 'google')['resumen']);
    }

    public function test_google_revocado_pide_reconectar(): void
    {
        $this->meta();
        $this->google(token: false);
        Cache::put(EstadoSistema::GOOGLE_ERROR, ['at' => now()->toIso8601String(), 'error' => 'invalid_grant']);
        $g = $this->pieza(app(EstadoSistema::class)->revisar(true), 'google');

        $this->assertSame('No se pudo renovar el acceso', $g['resumen']);
        $this->assertStringContainsString('revocó', $g['detalle']);
    }

    /** Lab, 05-oct, 20:01: el cupo se acabó y nadie supo por qué el bot «entendía menos». */
    public function test_ia_sin_cupo_casi_sin_cupo_sin_saldo_y_apagada(): void
    {
        $this->meta();
        DB::table('uso_asistente')->insert(['fecha' => '2026-10-05', 'tokens_entrada' => 850, 'tokens_salida' => 0]);
        $this->assertSame('Cupo casi agotado', $this->pieza(app(EstadoSistema::class)->revisar(true), 'ia')['resumen']);

        DB::table('uso_asistente')->update(['tokens_entrada' => 1000]);
        $this->assertSame('Sin cupo por hoy', $this->pieza(app(EstadoSistema::class)->revisar(true), 'ia')['resumen']);

        DB::table('uso_asistente')->update(['tokens_entrada' => 10]);
        Cache::forever(EstadoSistema::IA_OK, now()->subHour()->toIso8601String());
        Cache::put(EstadoSistema::IA_ERROR, ['at' => now()->toIso8601String(),
            'mensaje' => 'Your credit balance is too low to access the Anthropic API.']);
        $this->assertSame('Sin saldo en Anthropic', $this->pieza(app(EstadoSistema::class)->revisar(true), 'ia')['resumen']);

        // Un éxito posterior deja atrás el error.
        Cache::forever(EstadoSistema::IA_OK, now()->addMinute()->toIso8601String());
        $this->assertSame('ok', $this->pieza(app(EstadoSistema::class)->revisar(true), 'ia')['estado']);

        config(['emr.asistente_ia_activo' => false]);
        $r = app(EstadoSistema::class)->revisar(true);
        $this->assertSame('apagado', $this->pieza($r, 'ia')['estado']);
        $this->assertSame('ok', $r['peor'], 'apagada a propósito no es un problema');
    }

    public function test_alerta_a_la_segunda_revision_recuerda_cada_24_horas_y_avisa_la_vuelta(): void
    {
        $this->meta(401, ['error' => ['code' => 190]]);

        $this->artisan('estado:revisar')->assertSuccessful();
        $this->assertSame([], $this->correos, 'un corte de una sola revisión no avisa');

        $this->artisan('estado:revisar')->assertSuccessful();
        $this->assertCount(1, $this->correos, 'solo admins activos');
        $this->assertSame('jefe@x.cl', $this->correos[0][0]);
        $this->assertStringContainsString('WhatsApp — Token vencido', $this->correos[0][1]);

        $this->artisan('estado:revisar')->assertSuccessful();
        $this->assertCount(1, $this->correos, 'sigue caído: no se repite enseguida');

        $this->travel(25)->hours();
        $this->artisan('estado:revisar')->assertSuccessful();
        $this->assertCount(2, $this->correos, 'recordatorio a las 24 h');

        $this->meta();
        $this->artisan('estado:revisar')->assertSuccessful();
        $this->assertCount(3, $this->correos);
        $this->assertStringContainsString('WhatsApp volvió a funcionar', $this->correos[2][1]);
        $this->assertSame(1, Cache::get(EstadoRevisar::ULTIMA_ALERTA)['enviados']);
    }

    public function test_el_panel_lo_ve_solo_un_admin_y_guarda_a_quien_avisar(): void
    {
        $this->meta();
        $token = fn ($id) => app(Jwt::class)->firmar(['sub' => "{$id}@x.cl", 'rol' => DB::table('usuarios')->where('id', $id)->value('rol'), 'user_id' => $id]);
        $h = fn ($id) => ['Authorization' => 'Bearer ' . $token($id)];
        DB::table('usuarios')->insert(['id' => 'prueba', 'email' => 'admin@clinica.test', 'rol' => 'admin', 'es_activo' => true]);

        $this->getJson('/estado-sistema', $h('vet'))->assertForbidden();

        $r = $this->getJson('/estado-sistema?sondear=1', $h('jefe'))->assertOk();
        $this->assertTrue($r->json('alertas.activo'), 'por defecto, encendidas');
        $this->assertSame(['admin@clinica.test', 'jefe@x.cl'], $r->json('alertas.destinatarios'), 'todos los admins activos');

        $this->putJson('/estado-sistema/alertas', ['activo' => true, 'extra' => ['no-es-correo'], 'apagados' => []], $h('jefe'))
            ->assertUnprocessable();

        // Decisión de producto (05-oct): apagar a uno (el admin de pruebas) y sumar la casilla de la clínica.
        $r = $this->putJson('/estado-sistema/alertas', ['activo' => true, 'extra' => [' Clinica@X.cl '],
            'apagados' => ['ADMIN@clinica.test']], $h('jefe'))->assertOk();
        $this->assertSame(['jefe@x.cl', 'clinica@x.cl'], EstadoSistema::destinatarios());
        $this->assertFalse(collect($r->json('candidatos'))->firstWhere('correo', 'admin@clinica.test')['recibe']);
        $this->assertSame('extra', collect($r->json('candidatos'))->firstWhere('correo', 'clinica@x.cl')['origen']);

        // El interruptor general: nadie.
        $this->putJson('/estado-sistema/alertas', ['activo' => false, 'extra' => ['clinica@x.cl'], 'apagados' => []], $h('jefe'))->assertOk();
        $this->assertSame([], EstadoSistema::destinatarios());
    }

    /** Apagadas no sale nada; al encenderlas con algo todavía caído, el aviso sale en la revisión siguiente. */
    public function test_con_las_alertas_apagadas_no_manda_nada_y_al_encender_avisa_lo_que_sigue_caido(): void
    {
        DB::table('ajustes')->update(['data' => json_encode(['alertas' => ['activo' => false, 'extra' => [], 'apagados' => []]])]);
        $this->meta(401, ['error' => ['code' => 190]]);

        $this->artisan('estado:revisar')->assertSuccessful();
        $this->artisan('estado:revisar')->assertSuccessful();
        $this->assertSame([], $this->correos);

        DB::table('ajustes')->update(['data' => json_encode(['alertas' => ['activo' => true, 'extra' => [], 'apagados' => []]])]);
        $this->artisan('estado:revisar')->assertSuccessful();
        $this->assertCount(1, $this->correos);
        $this->assertStringContainsString('WhatsApp — Token vencido', $this->correos[0][1]);
    }

    /** Decisión de producto (05-oct): indicadores en Estado del sistema. Hoy = 5-oct 21:00 en Chile = 6-oct 00:00 UTC. */
    public function test_indicadores(): void
    {
        foreach (['citas', 'chatbot_conversations'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('citas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('estado'); $t->string('fecha_hora');
            $t->text('respuestas')->nullable(); $t->string('google_event_id')->nullable(); $t->string('created_at')->nullable();
        });
        Schema::create('chatbot_conversations', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('telefono')->nullable(); $t->string('updated_at')->nullable();
        });
        $wa = json_encode(['Canal' => 'WhatsApp (asistente IA)']);
        DB::table('citas')->insert([
            // Del bot, esta semana (UTC); una con el formato con espacio del backend Python.
            ['id' => 'a', 'estado' => 'Agendada', 'fecha_hora' => '2026-10-09 16:00:00', 'respuestas' => $wa, 'google_event_id' => 'ev', 'created_at' => '2026-10-05 22:59:00'],
            ['id' => 'b', 'estado' => 'Cancelada', 'fecha_hora' => '2026-10-01 10:00:00', 'respuestas' => '{"Canal": "WhatsApp (acordada con el especialista)"}', 'google_event_id' => null, 'created_at' => '2026-10-01 12:00:00'],
            // Del bot, semana anterior.
            ['id' => 'c', 'estado' => 'Completada', 'fecha_hora' => '2026-09-26 10:00:00', 'respuestas' => $wa, 'google_event_id' => null, 'created_at' => '2026-09-25 12:00:00'],
            // Del portal, futura y SIN evento en Google.
            ['id' => 'd', 'estado' => 'Confirmada', 'fecha_hora' => '2026-10-07 12:00:00', 'respuestas' => '{"Canal":"Portal"}', 'google_event_id' => null, 'created_at' => '2026-10-04 12:00:00'],
        ]);
        DB::table('chatbot_conversations')->insert([
            ['id' => '1', 'telefono' => '569', 'updated_at' => '2026-10-05 23:30:00'],   // 20:30 en Chile: hoy
            ['id' => '2', 'telefono' => '568', 'updated_at' => '2026-10-05 02:00:00'],   // 4-oct 23:00 en Chile: ayer
            ['id' => '3', 'telefono' => null, 'updated_at' => '2026-10-05 23:30:00'],    // chat web, no WhatsApp
        ]);
        DB::table('uso_asistente')->insert(['fecha' => '2026-10-05', 'llamadas' => 7, 'tokens_entrada' => 400, 'tokens_salida' => 20]);
        Cache::forever(EstadoRevisar::HISTORIAL, [
            ['at' => '2026-10-05T10:00:00-03:00', 'whatsapp' => 'ok', 'google' => 'error', 'ia' => 'apagado'],
            ['at' => '2026-10-05T10:15:00-03:00', 'whatsapp' => 'aviso', 'google' => 'ok', 'ia' => 'apagado'],
            ['at' => '2026-10-05T10:30:00-03:00', 'whatsapp' => 'ok', 'google' => 'ok', 'ia' => 'apagado'],
            ['at' => '2026-10-05T10:45:00-03:00', 'whatsapp' => 'error', 'google' => 'ok', 'ia' => 'apagado'],
        ]);
        Cache::forever(EstadoRevisar::ENVIADAS, ['2026-09-20T10:00:00-03:00', '2026-10-05T10:45:00-03:00']);

        $k = app(EstadoSistema::class)->kpis();

        $this->assertSame(1, $k['conversaciones_hoy']);
        $this->assertSame(2, $k['reservas_bot_7d']);
        $this->assertSame(1, $k['reservas_bot_7d_antes']);
        $this->assertNull($k['especialistas_esperando'], 'sin la tabla, «—» y no un 500');
        $this->assertSame(['sin_evento' => 1, 'proximas' => 2], $k['fuera_de_google']);
        $this->assertSame(['tokens' => 420, 'llamadas' => 7, 'tope' => 1000, 'activa' => true], $k['ia_hoy']);
        $this->assertSame(1, $k['alertas_7d']);
        $this->assertSame(75.0, $k['disponibilidad_7d']['whatsapp']['porcentaje'], 'aviso cuenta como funcionando');
        $this->assertSame(75.0, $k['disponibilidad_7d']['google']['porcentaje']);
        $this->assertNull($k['disponibilidad_7d']['ia']['porcentaje'], 'apagada no cuenta');
    }

    public function test_cada_revision_queda_en_el_historial(): void
    {
        $this->meta();
        $this->artisan('estado:revisar')->assertSuccessful();
        $this->artisan('estado:revisar')->assertSuccessful();
        $h = Cache::get(EstadoRevisar::HISTORIAL);
        $this->assertCount(2, $h);
        $this->assertSame('ok', $h[1]['whatsapp']);
    }
}
