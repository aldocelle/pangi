<?php

namespace App\Console\Commands;

use App\Services\Marca;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Deja una base de DEMO con la configuración de una clínica ficticia (PANGI):
 * servicios con sus precios y formas de cobro, el equipo con sus horarios,
 * protocolos preventivos, plantillas y las cuentas del equipo para entrar al
 * panel. Va ANTES de `demo:sembrar`, que llena los datos de uso (clientes,
 * mascotas, citas, ventas, caja) sobre esta configuración.
 *
 * Vacía TODO —configuración, datos y cuentas— y lo rehace desde cero, así que
 * hereda los candados de `demo:sembrar`: solo en una base cuyo nombre diga
 * lab/demo/prueba, y con `--confirmar=<base>`.
 *
 * Nada de esto es de una clínica real: nombres, correos `@pangi.demo` (un
 * dominio que no existe) y teléfonos `+56 9 0000 …` que WhatsappEnvio no usa.
 */
class DemoConfigurar extends Command
{
    protected $signature = 'demo:configurar
                            {--confirmar= : Nombre exacto de la base destino}
                            {--clave=demo1234 : Contraseña de las cuentas del equipo}';

    protected $description = 'Vacía la base de demo y crea la configuración de la clínica ficticia (servicios, equipo, horarios, cuentas)';

    public const DOMINIO_EQUIPO = 'pangi.demo';

    private string $ahoraUtc;

    public function handle(): int
    {
        $base = DB::connection()->getDatabaseName();
        if (! DemoSembrar::esBaseDePruebas($base)) {
            $this->error("«{$base}» no es una base de demo. Este comando BORRA todo: solo corre en una base "
                . 'cuyo nombre diga lab, demo o prueba.');
            return self::FAILURE;
        }
        if ($this->option('confirmar') !== $base) {
            $this->error("Esto va a BORRAR TODO lo que hay en «{$base}» y crear la clínica de demo.");
            $this->line("Si es lo que quieres, repite el comando con:  --confirmar={$base}");
            return self::FAILURE;
        }
        $sinClasificar = DemoSembrar::tablasSinClasificar();
        if ($sinClasificar) {
            $this->error('Hay tablas sin clasificar en DemoSembrar: ' . implode(', ', $sinClasificar));
            return self::FAILURE;
        }

        $this->ahoraUtc = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

        Schema::disableForeignKeyConstraints();
        try {
            foreach ([...DemoSembrar::CONFIG, ...DemoSembrar::DATOS, 'usuarios'] as $t) {
                if (Schema::hasTable($t)) {
                    DB::table($t)->truncate();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        DB::transaction(function () {
            $this->ajustes();
            $servicios = $this->servicios();
            $this->equipoClinico($servicios);
            $this->protocolos();
            $this->plantillas();
            $this->cuentas();
        });

        $this->info('Clínica de demo «' . Marca::nombre() . '» configurada.');
        $this->line('  Servicios      ' . DB::table('servicios')->count());
        $this->line('  Especialistas  ' . DB::table('especialistas')->count());
        $this->line('  Cuentas        ' . DB::table('usuarios')->count() . ' (clave: ' . $this->option('clave') . ')');

        return self::SUCCESS;
    }

    private function ajustes(): void
    {
        $nombre = Marca::nombre();
        $data = [
            'nombre' => $nombre,
            'url_slug' => Str::slug($nombre),
            'idioma' => 'Español',
            'zona_horaria' => 'America/Santiago',
            'wording' => [
                'global' => "Reserva tu hora en {$nombre} en unos minutos, sin llamar.",
            ],
            'asistente' => [
                'datos' => [
                    'direccion' => (string) (config('emr.clinica_direccion') ?: 'Av. Principal 1234, local 5, Puente Alto'),
                    'horario' => 'Lunes a viernes de 10:00 a 14:00 y de 15:00 a 19:00; sábados de 10:00 a 14:00.',
                    'preguntas' => [
                        ['claves' => 'estacionamiento, estacionar',
                            'respuesta' => 'Sí, hay estacionamiento para clientes frente a la clínica.'],
                        ['claves' => 'conejo, huron, exotico, exoticos',
                            'respuesta' => 'Atendemos perros y gatos, y conejos en consulta general. Para otras especies escríbenos antes de venir.'],
                        ['claves' => 'urgencia nocturna, noche, madrugada',
                            'respuesta' => 'No tenemos atención nocturna. Fuera de horario, acude al servicio de urgencias veterinarias más cercano.'],
                    ],
                ],
            ],
            'recordatorios' => ['dias_anticipacion' => 3, 'firma' => "Equipo {$nombre}"],
            'impresion' => ['mensaje_recibo' => "Gracias por confiar en {$nombre}."],
        ];
        DB::table('ajustes')->insert(['id' => 1, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    /** @return array<string, string> nombre → id */
    private function servicios(): array
    {
        // [nombre, descripción, emoji, min, precio, sábado, detalle, modalidad, abono fijo, orden médica, grupo]
        $catalogo = [
            ['Consulta General', 'Evaluación completa de salud, diagnóstico y tratamiento.', '🩺', 30, 20000, 24000,
                '$20.000 de lunes a viernes y $24.000 los sábados.', 'nada', null, false, null],
            ['Control', 'Seguimiento de un tratamiento o de una consulta anterior.', '📋', 30, 12000, null, null, 'nada', null, false, null],
            ['Vacunación', 'Aplicación de vacunas según calendario, con registro en la ficha.', '💉', 30, 15000, null,
                'Valor de la aplicación; la vacuna se cobra aparte según el tipo.', 'nada', null, false, null],
            ['Consulta Dermatológica', 'Piel, pelo y oídos: picazón, alergias, otitis a repetición.', '🔍', 30, 38000, null,
                null, 'abono', null, false, 'Dermatología'],
            ['Control Dermatológico', 'Seguimiento de un tratamiento dermatológico.', '🔍', 30, 25000, null,
                null, 'abono', null, false, 'Dermatología'],
            ['Consulta Felina', 'Atención pensada para gatos, sin perros en la sala.', '🐈', 30, 24000, null, null, 'nada', null, false, null],
            ['Evaluación Prequirúrgica', 'Revisión antes de una cirugía o esterilización.', '🩹', 30, 22000, null,
                'Incluye la indicación de exámenes previos.', 'nada', null, false, null],
            ['Ecografía Abdominal', 'Diagnóstico por imágenes en la clínica.', '🖥️', 30, 40000, null,
                'Requiere orden médica veterinaria.', 'abono', null, true, null],
            ['Radiografía', 'Rayos X digitales con informe.', '🦴', 30, 45000, null,
                'Desde $45.000; el valor final depende de cuántas placas se tomen.', 'abono', 20000, true, null],
            ['Exámenes de Sangre', 'Hemograma y perfil bioquímico, resultados en 24 h.', '🧪', 30, 28000, null,
                'Requiere orden médica veterinaria.', 'nada', null, true, null],
            ['Peluquería', 'Baño, corte y limpieza de oídos.', '✂️', 90, 25000, null,
                'Abono fijo de $10.000; el total depende del tamaño y el pelaje.', 'abono', 10000, false, null],
            ['Telemedicina', 'Videollamada con un veterinario para dudas y controles.', '💻', 30, 15000, null,
                null, 'total', null, false, null],
            ['Consulta a Domicilio', 'Atención en tu casa, en Puente Alto y comunas cercanas.', '🏠', 90, 35000, null,
                '$35.000 en Puente Alto; fuera de la comuna el valor depende de la distancia.', 'nada', null, false, null],
        ];
        $ids = [];
        foreach ($catalogo as $i => [$nombre, $desc, $emoji, $min, $precio, $sabado, $detalle, $modalidad, $abonoFijo, $orden, $grupo]) {
            $id = (string) Str::uuid();
            DB::table('servicios')->insert([
                'id' => $id, 'nombre' => $nombre, 'descripcion' => $desc, 'emoji' => $emoji,
                'duracion_min' => $min, 'duracion_visible_min' => $min, 'precio_clp' => $precio,
                'precio_sabado_clp' => $sabado, 'precio_detalle' => $detalle, 'modalidad_pago' => $modalidad,
                'abono_fijo_clp' => $abonoFijo, 'requiere_orden_examen' => $orden ? 1 : 0,
                'tarifa_por_especialista' => 0, 'coordinar_whatsapp' => 0, 'grupo' => $grupo,
                'activo' => 1, 'orden' => $i + 1,
            ]);
            $ids[$nombre] = $id;
        }
        return $ids;
    }

    /** Especialistas, qué atiende cada uno y sus horarios. */
    private function equipoClinico(array $servicios): void
    {
        // dia_semana: 0 = lunes … 5 = sábado. `hasta` es la ÚLTIMA hora de
        // inicio, no el cierre (SlotsService ofrece `cursor <= limite`).
        $semana = [0, 1, 2, 3, 4];
        $equipo = [
            ['Dra. Marta Soto', 'Medicina General', '👩‍⚕️',
                'Directora médica. Más de doce años en clínica de perros y gatos.',
                ['Consulta General', 'Control', 'Vacunación', 'Evaluación Prequirúrgica', 'Telemedicina', 'Consulta a Domicilio'],
                [[$semana, '10:00', '13:30'], [$semana, '15:00', '18:00'], [[5], '10:00', '13:00']]],
            ['Dr. Tomás Fuentes', 'Cirugía y Traumatología', '👨‍⚕️',
                'Cirugías programadas, esterilizaciones y traumatología.',
                ['Evaluación Prequirúrgica', 'Control', 'Consulta General'],
                [[[1, 3], '15:00', '18:00'], [[0, 2, 4], '10:00', '12:30'], [[5], '10:00', '12:30']]],
            ['Dra. Carla Rivas', 'Dermatología', '👩‍⚕️',
                'Dermatología veterinaria: alergias, otitis y enfermedades de la piel.',
                ['Consulta Dermatológica', 'Control Dermatológico', 'Telemedicina'],
                [[[1, 3], '10:00', '13:30'], [[1, 3], '15:00', '18:00'], [[5], '10:00', '13:00']]],
            ['Dra. Javiera Paredes', 'Medicina Felina', '👩‍⚕️',
                'Atención exclusiva de gatos, con manejo amable y sin estrés.',
                ['Consulta Felina', 'Vacunación', 'Control'],
                [[[0, 2, 4], '15:00', '18:30'], [[5], '10:00', '13:00']]],
            ['Dr. Ignacio Morales', 'Imagenología', '👨‍⚕️',
                'Ecografía y radiología digital, con informe el mismo día.',
                ['Ecografía Abdominal', 'Radiografía'],
                [[$semana, '10:00', '13:00']]],
            ['Dra. Valentina Rojas', 'Medicina General y Laboratorio', '👩‍⚕️',
                'Medicina preventiva, vacunas y exámenes de laboratorio.',
                ['Consulta General', 'Control', 'Vacunación', 'Exámenes de Sangre'],
                [[$semana, '15:00', '18:30'], [[2], '10:00', '13:30']]],
            ['Camila Herrera', 'Peluquería Canina y Felina', '✂️',
                'Peluquera con formación en manejo de pacientes nerviosos.',
                ['Peluquería'],
                [[[1, 2, 3, 4, 5], '10:00', '16:30']]],
        ];
        foreach ($equipo as $i => [$nombre, $especialidad, $emoji, $desc, $atiende, $bloques]) {
            $id = (string) Str::uuid();
            $slug = Str::slug(preg_replace('/^(Dra?\.)\s+/u', '', $nombre));
            DB::table('especialistas')->insert([
                'id' => $id, 'nombre' => $nombre, 'especialidad' => $especialidad, 'emoji' => $emoji,
                'activo' => 1, 'orden' => $i + 1, 'slug' => $slug, 'descripcion' => $desc,
                'zona_horaria' => 'America/Santiago', 'disponibilidad_modo' => 'todos',
                'calendarios_conflicto' => '[]', 'solo_consulta_whatsapp' => 0, 'precio_clp' => null,
                'email' => $slug . '@' . self::DOMINIO_EQUIPO, 'telefono' => null,
                'recibe_avisos' => 0, 'reservable' => 1, 'publico' => 1,
            ]);
            foreach ($atiende as $s) {
                DB::table('servicio_especialista')->insert(['servicio_id' => $servicios[$s], 'especialista_id' => $id]);
            }
            $hid = (string) Str::uuid();
            DB::table('horarios')->insert([
                'id' => $hid, 'especialista_id' => $id, 'nombre' => 'Horario 1', 'orden' => 0,
                'todos_servicios' => 1, 'servicio_ids' => '[]',
            ]);
            foreach ($bloques as [$dias, $desde, $hasta]) {
                foreach ($dias as $d) {
                    DB::table('horario_bloques')->insert([
                        'id' => (string) Str::uuid(), 'horario_id' => $hid,
                        'dia_semana' => $d, 'hora_inicio' => $desde, 'hora_fin' => $hasta,
                    ]);
                }
            }
        }
    }

    private function protocolos(): void
    {
        // [nombre, categoría, especie (null = todas), cada cuántos meses, desde qué edad en meses]
        $reglas = [
            ['Vacuna séxtuple', 'Vacuna', 'perro', 12, 2],
            ['Vacuna antirrábica', 'Vacuna', 'perro', 12, 3],
            ['Vacuna triple felina', 'Vacuna', 'gato', 12, 2],
            ['Vacuna antirrábica felina', 'Vacuna', 'gato', 12, 3],
            ['Desparasitación interna', 'Desparasitación', null, 3, 2],
            ['Antipulgas', 'Desparasitación', null, 3, 2],
        ];
        foreach ($reglas as $i => [$nombre, $categoria, $especie, $meses, $desde]) {
            DB::table('protocolos_preventivos')->insert([
                'id' => (string) Str::uuid(), 'nombre' => $nombre, 'categoria' => $categoria, 'especie' => $especie,
                'frecuencia_meses' => $meses, 'edad_inicio_meses' => $desde, 'edad_fin_meses' => null,
                'descripcion' => null, 'activo' => 1, 'orden' => $i + 1,
                'created_at' => $this->ahoraUtc, 'updated_at' => $this->ahoraUtc,
            ]);
        }
    }

    private function plantillas(): void
    {
        if (! Schema::hasTable('plantillas_recordatorio')) {
            return;
        }
        foreach ([
            ['Vacuna anual', 'Hola @propietario_nombre, a @mascota_nombre le corresponde su vacuna anual. Agenda tu hora cuando quieras.', 365],
            ['Desparasitación', 'Hola @propietario_nombre, se acerca la desparasitación de @mascota_nombre. Te esperamos.', 90],
            ['Control post operatorio', 'Hola @propietario_nombre, recuerda el control de @mascota_nombre después de su cirugía.', null],
        ] as $i => [$titulo, $mensaje, $dias]) {
            DB::table('plantillas_recordatorio')->insert([
                'id' => (string) Str::uuid(), 'titulo' => $titulo, 'mensaje' => $mensaje,
                'dias_repeticion' => $dias, 'orden' => $i + 1,
                'created_at' => $this->ahoraUtc, 'updated_at' => $this->ahoraUtc,
            ]);
        }
    }

    /** Las cuentas para entrar al panel. Permisos NULL = todo salvo auditoría. */
    private function cuentas(): void
    {
        $hash = password_hash((string) $this->option('clave'), PASSWORD_BCRYPT);
        $recepcion = ['agenda', 'reservas', 'consultas_web', 'personas', 'caja', 'ventas', 'recordatorios', 'tareas'];
        foreach ([
            ['admin', 'Daniela Castro', 'admin', 'Administración', null],
            ['marta.soto', 'Dra. Marta Soto', 'veterinario', 'Directora médica', null],
            ['tomas.fuentes', 'Dr. Tomás Fuentes', 'veterinario', 'Cirujano', ['agenda', 'reservas', 'personas', 'tareas', 'recordatorios']],
            ['recepcion', 'Sebastián Lagos', 'veterinario', 'Recepción', $recepcion],
        ] as [$usuario, $nombre, $rol, $cargo, $permisos]) {
            DB::table('usuarios')->insert([
                'id' => (string) Str::uuid(), 'email' => $usuario . '@' . self::DOMINIO_EQUIPO,
                'password_hash' => $hash, 'nombre' => $nombre, 'rol' => $rol, 'cargo' => $cargo,
                'permisos' => $permisos === null ? null : json_encode($permisos),
                'es_activo' => 1, 'email_verificado' => 1, 'puntos_fidelizacion' => 0,
                'created_at' => $this->ahoraUtc, 'updated_at' => $this->ahoraUtc,
            ]);
        }
    }
}
