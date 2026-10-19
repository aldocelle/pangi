<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ExportacionController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * Llevarse la base entera queda escrito en auditoría, y ANTES de generar nada:
 * si el volcado falla a la mitad, igual consta quién lo intentó. Python lo hacía
 * (`exportacion.py`) y el port lo había perdido sin que nada avisara.
 *
 * Acá el volcado falla a propósito —SQLite no tiene `SHOW TABLES`—, que es
 * justo el caso que el registro previo protege.
 */
#[RunTestsInSeparateProcesses]
class ExportacionAuditadaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('logs_actividad');
        Schema::create('logs_actividad', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id')->nullable(); $t->string('usuario_nombre');
            $t->string('usuario_rol')->nullable(); $t->string('accion'); $t->string('area');
            $t->string('entidad')->nullable(); $t->string('entidad_id')->nullable(); $t->text('detalle')->nullable();
            $t->string('created_at');
        });
    }

    public function test_exportar_queda_auditado_aunque_el_volcado_falle(): void
    {
        $request = Request::create('/exportacion/completa');
        $request->attributes->set('usuario', (object) ['id' => 'adm-1', 'nombre' => 'Marta', 'rol' => 'admin']);

        try {
            $this->app->make(ExportacionController::class)->completa($request);
        } catch (\Throwable) {
            // El volcado no corre en SQLite; lo que importa es lo de abajo.
        }

        $log = DB::table('logs_actividad')->first();
        $this->assertNotNull($log, 'la exportación no dejó rastro en auditoría');
        $this->assertSame(['exportar', 'auditoria', 'base_completa', 'adm-1', 'Marta'],
            [$log->accion, $log->area, $log->entidad, $log->usuario_id, $log->usuario_nombre]);
        $this->assertSame(11, json_decode($log->detalle, true)['tablas_csv']);
    }
}
