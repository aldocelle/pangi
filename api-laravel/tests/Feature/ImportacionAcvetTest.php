<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ImportacionController;
use App\Services\Esquema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * Importador incremental de los CSV de ACVet. Lo que se fija acá es lo que lo
 * hace seguro de correr sobre una base YA en uso: idempotente, no pisa lo que
 * la clínica corrigió, la vista previa no escribe, y lo importado no entra a la
 * caja del sistema nuevo.
 */
#[RunTestsInSeparateProcesses]
class ImportacionAcvetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Esquema::olvidar();
        foreach (['usuarios', 'mascotas', 'items_inventario', 'transacciones', 'ventas', 'notas_clinicas',
            'fichas_clinicas', 'especialistas', 'logs_actividad'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('usuarios', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('email')->unique(); $t->string('password_hash')->nullable();
            $t->string('nombre'); $t->string('telefono')->nullable()->unique(); $t->string('rol')->nullable();
            $t->integer('es_activo')->nullable(); $t->string('rut')->nullable(); $t->string('direccion')->nullable();
            $t->string('ciudad')->nullable(); $t->string('region')->nullable(); $t->integer('puntos_fidelizacion')->nullable();
            $t->string('id_legado')->nullable(); $t->string('created_at')->nullable(); $t->string('updated_at')->nullable();
        });
        Schema::create('mascotas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id'); $t->string('nombre'); $t->string('especie')->nullable();
            $t->string('raza')->nullable(); $t->string('sexo')->nullable(); $t->string('fecha_nacimiento')->nullable();
            $t->decimal('peso_kg', 5, 2)->nullable(); $t->string('microchip_codigo')->nullable(); $t->string('foto_url')->nullable();
            $t->integer('esterilizado')->nullable(); $t->string('estado')->nullable(); $t->string('fecha_fallecido')->nullable();
            $t->string('id_legado')->nullable(); $t->string('created_at')->nullable(); $t->string('updated_at')->nullable();
        });
        Schema::create('items_inventario', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->string('descripcion')->nullable(); $t->string('tipo');
            $t->string('unidad')->nullable(); $t->decimal('precio_costo', 12, 2)->nullable(); $t->decimal('precio_venta', 12, 2)->nullable();
            $t->integer('stock_actual')->nullable(); $t->integer('stock_minimo')->nullable(); $t->string('lote')->nullable();
            $t->string('fecha_vencimiento')->nullable(); $t->integer('activo')->nullable(); $t->string('id_legado')->nullable();
            $t->string('created_at')->nullable(); $t->string('updated_at')->nullable();
        });
        Schema::create('transacciones', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('propietario_id')->nullable(); $t->string('especialista_id')->nullable();
            $t->string('fecha'); $t->string('documento_id')->nullable();
            foreach (['efectivo', 'debito', 'credito', 'transferencia', 'otro'] as $m) { $t->decimal("pago_$m", 12, 2)->nullable(); }
            $t->decimal('total_pendiente', 12, 2)->nullable(); $t->string('id_legado')->nullable(); $t->string('created_at')->nullable();
            $t->integer('cuenta_en_caja')->default(1);
        });
        Schema::create('ventas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('transaccion_id')->nullable(); $t->string('item_id')->nullable();
            $t->string('propietario_id')->nullable(); $t->string('especialista_id')->nullable(); $t->string('fecha');
            $t->string('nombre'); $t->string('tipo')->nullable(); $t->integer('cantidad')->nullable();
            $t->decimal('precio_costo', 12, 2)->nullable(); $t->decimal('precio_venta', 12, 2)->nullable();
            $t->decimal('descuento', 12, 2)->nullable(); $t->string('id_legado')->nullable(); $t->string('created_at')->nullable();
        });
        Schema::create('fichas_clinicas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('mascota_id'); $t->string('created_at')->nullable(); $t->string('updated_at')->nullable();
        });
        Schema::create('notas_clinicas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('ficha_id'); $t->string('veterinario_id'); $t->string('especialista_id')->nullable();
            $t->string('motivo')->nullable(); $t->text('examen')->nullable(); $t->text('diagnostico')->nullable();
            $t->text('tratamiento')->nullable(); $t->text('indicaciones')->nullable(); $t->text('sanitario')->nullable();
            $t->string('id_legado')->nullable(); $t->string('created_at')->nullable();
        });
        Schema::create('especialistas', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('nombre'); $t->integer('activo')->nullable(); $t->integer('reservable')->default(1);
        });
        Schema::create('logs_actividad', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('usuario_id')->nullable(); $t->string('usuario_nombre');
            $t->string('usuario_rol')->nullable(); $t->string('accion'); $t->string('area');
            $t->string('entidad')->nullable(); $t->string('entidad_id')->nullable(); $t->text('detalle')->nullable();
            $t->string('created_at');
        });
        DB::table('usuarios')->insert(['id' => 'adm', 'email' => 'adm@x.cl', 'nombre' => 'Marta', 'rol' => 'admin', 'es_activo' => 1]);
    }

    private function lote(string $tipo, array $filas, array $extra = []): array
    {
        $req = Request::create('/x', 'POST', ['filas' => $filas] + $extra);
        $req->attributes->set('usuario', (object) ['id' => 'adm', 'nombre' => 'Marta', 'rol' => 'admin']);
        $res = $this->app->make(ImportacionController::class)->lote($req, $tipo);
        return is_array($res) ? $res : json_decode($res->getContent(), true);
    }

    public function test_propietarios_es_idempotente_y_no_pisa_lo_existente(): void
    {
        $f = [['id' => '10', 'email' => 'Ana@X.cl', 'nombre' => 'Ana', 'celular' => '+5691', 'puntos' => '5']];
        $this->assertSame(1, $this->lote('propietarios', $f)['creados']);
        $this->assertSame('ana@x.cl', DB::table('usuarios')->where('id_legado', '10')->value('email'));

        // La clínica corrige el nombre; volver a subir el archivo NO lo deshace.
        DB::table('usuarios')->where('id_legado', '10')->update(['nombre' => 'Ana Pérez']);
        $r = $this->lote('propietarios', $f);
        $this->assertSame([0, 0, 1], [$r['creados'], $r['actualizados'], $r['omitidos']]);
        $this->assertSame('Ana Pérez', DB::table('usuarios')->where('id_legado', '10')->value('nombre'));

        // Solo con «actualizar» se pisa.
        $this->assertSame(1, $this->lote('propietarios', $f, ['actualizar' => true])['actualizados']);
        $this->assertSame('Ana', DB::table('usuarios')->where('id_legado', '10')->value('nombre'));
    }

    public function test_una_cuenta_del_sistema_nuevo_con_el_mismo_correo_se_vincula_no_se_duplica(): void
    {
        DB::table('usuarios')->insert(['id' => 'u1', 'email' => 'luis@x.cl', 'nombre' => 'Luis', 'rol' => 'dueño']);
        $r = $this->lote('propietarios', [['id' => '20', 'email' => 'LUIS@x.cl', 'nombre' => 'Luis R']]);
        $this->assertSame(0, $r['creados']);
        $this->assertSame(1, $r['vinculados']);
        $this->assertSame('20', DB::table('usuarios')->where('id', 'u1')->value('id_legado'));
        $this->assertSame('Luis', DB::table('usuarios')->where('id', 'u1')->value('nombre'));
    }

    public function test_la_vista_previa_cuenta_pero_no_escribe_nada(): void
    {
        $r = $this->lote('propietarios', [['id' => '30', 'email' => 'a@x.cl', 'nombre' => 'A']], ['simular' => true]);
        $this->assertSame(1, $r['creados']);
        $this->assertTrue($r['simulado']);
        $this->assertSame(0, DB::table('usuarios')->where('id_legado', '30')->count());
        $this->assertSame(0, DB::table('logs_actividad')->count());
    }

    public function test_ficha_duplicada_deja_alias_y_sus_mascotas_encuentran_al_dueno(): void
    {
        $this->lote('propietarios', [
            ['id' => '1', 'email' => 'a@x.cl', 'nombre' => 'A'],
            ['id' => '2', 'email' => 'a@x.cl', 'nombre' => 'A', 'dup_de' => '1'],
        ]);
        $this->assertSame(1, DB::table('usuarios')->where('rol', 'dueño')->count());

        $r = $this->lote('mascotas', [
            ['id' => 'm1', 'propietario_id' => '2', 'fullname' => 'Luna', 'especie' => 'Canino', 'sexo' => 'Hembra',
                'peso' => '12.5', 'fecha_nacimiento' => '03/02/2020 00:00'],
            ['id' => 'm2', 'propietario_id' => '999', 'fullname' => 'Huérfana'],
        ]);
        $this->assertSame([1, 1], [$r['creados'], $r['sin_relacion']]);
        $m = DB::table('mascotas')->where('id_legado', 'm1')->first();
        $this->assertSame(['perro', 'F', '2020-02-03'], [$m->especie, $m->sexo, $m->fecha_nacimiento]);
        $this->assertSame(DB::table('usuarios')->where('id_legado', '1')->value('id'), $m->usuario_id);
    }

    public function test_el_telefono_unico_de_otro_tutor_no_rompe_el_lote(): void
    {
        DB::table('usuarios')->insert(['id' => 'u1', 'email' => 'x@x.cl', 'nombre' => 'X', 'rol' => 'dueño', 'telefono' => '+569']);
        $r = $this->lote('propietarios', [['id' => '40', 'email' => 'y@x.cl', 'nombre' => 'Y', 'celular' => '+569']]);
        $this->assertSame(1, $r['creados']);
        $this->assertSame(1, $r['avisos']['telefono_repetido']);
        $this->assertNull(DB::table('usuarios')->where('id_legado', '40')->value('telefono'));
    }

    public function test_el_aviso_de_telefono_repetido_solo_cuenta_filas_que_se_escriben(): void
    {
        // Dos tutores que comparten teléfono (pareja). Uno ya está cargado: se
        // salta y NO debe contar como «teléfono que quedó vacío».
        DB::table('usuarios')->insert([
            ['id' => 'u1', 'email' => 'a@x.cl', 'nombre' => 'A', 'rol' => 'dueño', 'telefono' => '+569', 'id_legado' => '1'],
        ]);
        $r = $this->lote('propietarios', [
            ['id' => '1', 'email' => 'a@x.cl', 'nombre' => 'A', 'celular' => '+569'],
            ['id' => '2', 'email' => 'b@x.cl', 'nombre' => 'B', 'celular' => '+569'],
        ]);
        $this->assertSame([1, 1], [$r['creados'], $r['omitidos']]);
        $this->assertSame(1, $r['avisos']['telefono_repetido']);   // solo la B, que sí se crea sin teléfono
    }

    public function test_lo_importado_no_entra_a_la_caja_y_las_lineas_huerfanas_no_se_cuelan(): void
    {
        $t = $this->lote('transacciones', [
            ['id' => 't1', 'fecha' => '30/09/2026 19:00', 'pago_efectivo' => '1000', 'eliminado' => '0'],
            ['id' => 't2', 'fecha' => '30/09/2026 19:00', 'eliminado' => '1'],
        ]);
        $this->assertSame([1, 1], [$t['creados'], $t['omitidos']]);
        $this->assertSame(0, (int) DB::table('transacciones')->where('id_legado', 't1')->value('cuenta_en_caja'));

        $v = $this->lote('ventas', [
            ['id' => 'v1', 'transaccion_id' => 't1', 'nombre' => 'Consulta', 'fecha' => '30/09/2026 19:00', 'precio_venta' => '15000'],
            ['id' => 'v2', 'transaccion_id' => 'no-existe', 'nombre' => 'Suelta', 'fecha' => '30/09/2026 19:00'],
        ]);
        $this->assertSame([1, 1], [$v['creados'], $v['sin_relacion']]);
    }

    public function test_casos_clinicos_crean_ficha_y_un_medico_inactivo(): void
    {
        $this->lote('propietarios', [['id' => '1', 'email' => 'a@x.cl', 'nombre' => 'A']]);
        $this->lote('mascotas', [['id' => 'm1', 'propietario_id' => '1', 'fullname' => 'Luna']]);
        $r = $this->lote('casos_clinicos', [['id' => 'c1', 'mascota_id' => 'm1', 'descripcion' => 'Control',
            'medico_id' => '6358', 'fc' => '120', 'fecha' => '30/09/2026 18:55']]);
        $this->assertSame(1, $r['creados']);
        $nota = DB::table('notas_clinicas')->where('id_legado', 'c1')->first();
        $this->assertSame('FC: 120', $nota->examen);
        $this->assertSame('adm', $nota->veterinario_id);
        $this->assertSame(0, (int) DB::table('especialistas')->where('id', $nota->especialista_id)->value('activo'));
    }

    /* Las notas iban al «primer admin» por orden de id: al azar. En el import
       anterior las 7.119 quedaron a nombre de admin@clinica.test, una cuenta de
       prueba de un dominio ajeno. Con más de una cuenta activa se exige elegir. */
    private function conMascota(): void
    {
        $this->lote('propietarios', [['id' => '1', 'email' => 'a@x.cl', 'nombre' => 'A']]);
        $this->lote('mascotas', [['id' => 'm1', 'propietario_id' => '1', 'fullname' => 'Luna']]);
    }

    public function test_con_varias_cuentas_del_equipo_se_exige_elegir_y_no_se_escribe_nada(): void
    {
        DB::table('usuarios')->insert(['id' => 'cli', 'email' => 'clinica@x.cl', 'nombre' => 'Clínica', 'rol' => 'admin', 'es_activo' => 1]);
        $this->conMascota();
        $r = $this->lote('casos_clinicos', [['id' => 'c1', 'mascota_id' => 'm1']]);
        $this->assertStringContainsString('Elige a qué cuenta', $r['detail']);
        $this->assertSame(0, DB::table('notas_clinicas')->count());
    }

    public function test_las_notas_van_a_la_cuenta_elegida(): void
    {
        DB::table('usuarios')->insert(['id' => 'cli', 'email' => 'clinica@x.cl', 'nombre' => 'Clínica', 'rol' => 'admin', 'es_activo' => 1]);
        $this->conMascota();
        $r = $this->lote('casos_clinicos', [['id' => 'c1', 'mascota_id' => 'm1']], ['atribuir_a' => 'cli']);
        $this->assertSame(1, $r['creados']);
        $this->assertSame('cli', DB::table('notas_clinicas')->where('id_legado', 'c1')->value('veterinario_id'));
    }

    public function test_una_cuenta_desactivada_no_firma_notas_ni_cuenta_como_opcion(): void
    {
        DB::table('usuarios')->insert(['id' => 'vieja', 'email' => 'admin@clinica.test', 'nombre' => 'Prueba', 'rol' => 'admin', 'es_activo' => 0]);
        $this->conMascota();
        // Elegida a propósito: se rechaza.
        $r = $this->lote('casos_clinicos', [['id' => 'c1', 'mascota_id' => 'm1']], ['atribuir_a' => 'vieja']);
        $this->assertStringContainsString('desactivada', $r['detail']);
        // Sin elegir: la desactivada no cuenta, así que queda una sola activa y se usa esa.
        $r = $this->lote('casos_clinicos', [['id' => 'c1', 'mascota_id' => 'm1']]);
        $this->assertSame('adm', DB::table('notas_clinicas')->where('id_legado', 'c1')->value('veterinario_id'));
        // Y la pantalla no la ofrece.
        $casos = collect($this->app->make(ImportacionController::class)->tipos())->firstWhere('clave', 'casos_clinicos');
        $this->assertSame(['adm'], array_map(fn ($c) => $c->id, $casos['cuentas']));
    }

    public function test_un_archivo_equivocado_se_rechaza_con_mensaje(): void
    {
        $res = $this->lote('mascotas', [['id' => '1', 'email' => 'a@x.cl']]);
        $this->assertStringContainsString('no parece ser «Mascotas»', $res['detail']);
    }

    public function test_el_primer_lote_deja_rastro_en_auditoria(): void
    {
        $this->lote('inventario', [['id' => 'i1', 'nombre' => 'Arena', 'precio_venta' => '5000', 'stock_virtual_bodega' => '3']],
            ['primero' => true, 'archivo' => 'inventario_respaldo.csv']);
        $log = DB::table('logs_actividad')->first();
        $this->assertSame(['importar', 'inventario', 'Marta'], [$log->accion, $log->entidad_id, $log->usuario_nombre]);
        $this->assertSame(3, (int) DB::table('items_inventario')->where('id_legado', 'i1')->value('stock_actual'));
    }
}
