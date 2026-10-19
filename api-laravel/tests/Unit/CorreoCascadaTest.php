<?php

namespace Tests\Unit;

use App\Services\Correo;
use App\Services\GoogleOAuth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * El correo no puede depender de un solo transporte. Caso real (14-sep-2026,
 * pruebas): la cuenta de Google conectada no tenía `gmail.send`, Gmail
 * respondía 403, y la cascada se cortaba ahí aunque hubiera otro transporte.
 */
#[RunTestsInSeparateProcesses]
class CorreoCascadaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ajustes', 'google_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('google_accounts', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email');
        });
        Schema::create('ajustes', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->string('google_account_id')->nullable();
        });
        DB::table('google_accounts')->insert(['id' => 'g', 'email' => 'clinica@gmail.com']);
        DB::table('ajustes')->insert(['id' => 1, 'google_account_id' => 'g']);

        $oauth = Mockery::mock(GoogleOAuth::class);
        $oauth->shouldReceive('token')->andReturn('tok');
        $this->app->instance(GoogleOAuth::class, $oauth);
    }

    public function test_si_gmail_rechaza_se_prueba_resend(): void
    {
        putenv('RESEND_API_KEY=re_test'); $_ENV['RESEND_API_KEY'] = 're_test'; $_SERVER['RESEND_API_KEY'] = 're_test';
        Http::fake([
            'gmail.googleapis.com/*' => Http::response(['error' => ['code' => 403]], 403),
            'api.resend.com/*' => Http::response(['id' => 'x'], 200),
        ]);

        $this->assertTrue(app(Correo::class)->enviar('tutor@gmail.com', 'Asunto', 'Cuerpo'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.resend.com'));
    }

    public function test_si_gmail_envia_no_se_toca_otro_transporte(): void
    {
        putenv('RESEND_API_KEY=re_test'); $_ENV['RESEND_API_KEY'] = 're_test'; $_SERVER['RESEND_API_KEY'] = 're_test';
        Http::fake(['gmail.googleapis.com/*' => Http::response(['id' => 'm'], 200)]);

        $this->assertTrue(app(Correo::class)->enviar('tutor@gmail.com', 'Asunto', 'Cuerpo'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.resend.com'));
    }

    public function test_la_conexion_de_google_pide_permiso_para_enviar(): void
    {
        $this->assertStringContainsString('auth/gmail.send', GoogleOAuth::SCOPES);
    }
}
