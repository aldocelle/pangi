<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\CajaController;
use App\Http\Controllers\Api\PedidosController;
use App\Http\Controllers\Api\TransaccionesController;
use App\Services\CobroService;
use App\Services\ConfigClinica;
use App\Services\Fechas;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Llena la base de DEMO o de PRUEBAS con datos inventados que cubren todos los
 * casos, como una clínica en uso: ventas pagadas, parciales, pendientes,
 * anuladas, en borrador, con devolución y sin cargo; citas en todos los
 * estados; caja abierta y cerradas, una con descuadre; recordatorios vencidos
 * y próximos; protocolos al día y atrasados.
 *
 * Una base de pruebas con datos de personas reales sigue sujeta a la ley
 * 21.719; con esto no hace falta tener ninguno. La configuración (servicios,
 * equipo, horarios) la pone `demo:configurar`, que va antes.
 *
 * **La caja y las ventas pasan por el código real** (CajaController,
 * TransaccionesController, PedidosController) con el reloj movido a cada
 * fecha. No es comodidad: el esperado de una caja, el stock y los puntos los
 * calcula ese código, y una copia de la fórmula acá se desalinearía en el
 * primer cambio. Así la caja cuadra porque la cuadró el sistema.
 *
 * Nada de esto puede llegar a una persona: correos `@correo.test` (TLD
 * reservado, el servicio de correo ya no le envía), teléfonos `+56 9 0000 0…`
 * (WhatsappEnvio no les escribe) y RUT válidos pero en el rango 33 millones,
 * que hoy no tiene personas.
 *
 * Candados: solo corre en una base cuyo nombre diga lab/demo/prueba y nunca en
 * las de producción; exige `--confirmar=<base>`; respalda antes de vaciar; y se
 * detiene si encuentra una tabla que no esté clasificada como configuración o
 * como datos (una tabla nueva tiene que decidirse, no vaciarse por descarte).
 */
class DemoSembrar extends Command
{
    protected $signature = 'demo:sembrar
                            {--confirmar= : Nombre exacto de la base destino}
                            {--semilla=2026 : Semilla del azar (los datos dependen también de la fecha de hoy)}
                            {--meses=14 : Meses de historia de ventas}
                            {--clientes=100 : Cuántos clientes inventar}
                            {--clave-cliente=demo1234 : Clave de la cuenta de muestra del portal del cliente}
                            {--sin-red : No respaldar antes de vaciar (no recomendado)}';

    protected $description = 'Vacía los DATOS de la base de pruebas (conserva configuración y equipo activo) y la llena con datos inventados';

    /** Nunca, aunque el nombre calzara con el patrón. */
    private const BASES_PROHIBIDAS = ['emr_produccion', 'sitio_produccion'];

    private const PATRON_PRUEBAS = '/(lab|demo|prueba)/i';

    /** Se conservan tal cual. */
    public const CONFIG = [
        'ajustes', 'especialistas', 'servicios', 'servicio_especialista', 'horarios',
        'horario_bloques', 'disponibilidad_especialistas', 'google_accounts', 'form_fields',
        'protocolos_preventivos', 'plantillas_recordatorio',
    ];

    /** Se vacían. `usuarios` va aparte: se conserva el equipo activo. */
    public const DATOS = [
        'chatbot_conversations', 'chatbot_preguntas', 'citas', 'documentos_clinicos',
        'facturas_compra', 'lineas_factura_compra', 'fichas_clinicas', 'items_inventario',
        'logs_actividad', 'marcajes_asistencia', 'mascotas', 'mensajes_contacto',
        'movimientos_caja', 'notas_clinicas', 'protocolo_aplicaciones', 'proveedores',
        'recordatorios', 'sesiones_caja', 'solicitudes_especialista', 'solicitudes_reset',
        'solicitudes_titular', 'tareas', 'tareas_corridas', 'tareas_recurrentes', 'transacciones', 'uso_asistente', 'ventas',
    ];

    /** Tablas propias de Laravel que el sistema no usa para datos de la clínica. */
    private const INTERNAS = ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'sessions', 'migrations', 'password_reset_tokens', 'users'];

    public const DOMINIO = 'correo.test';

    public const PREFIJO_TELEFONO = '+56 9 0000 ';

    /** La cuenta del portal del cliente con la que se muestra la demo. */
    public const CLIENTE_MUESTRA = 'cliente@pangi.demo';

    private const STAFF = ['admin', 'veterinario'];

    private const MOTIVOS = ['Control anual', 'Prurito generalizado', 'Otitis recurrente', 'Vómitos de 2 días',
        'Cojera miembro posterior', 'Control dermatológico', 'Caída de pelo en flancos', 'Vacunación',
        'Diarrea intermitente', 'Decaimiento y falta de apetito', 'Control post operatorio', 'Tos seca',
        'Revisión de piel antes de vacuna', 'Esterilización: evaluación previa', 'Lagrimeo ojo derecho'];

    private const DIAGNOSTICOS = ['Dermatitis atópica', 'Otitis externa bacteriana', 'Gastroenteritis aguda',
        'Sano, sin hallazgos', 'Pioderma superficial', 'Alergia alimentaria', 'Esguince leve',
        'Conjuntivitis', 'Enfermedad periodontal grado 2', 'Traqueobronquitis', 'Herida en cicatrización normal'];

    private const TRATAMIENTOS = ['Shampoo dermatológico 2 veces por semana', 'Limpiador ótico cada 12 h por 10 días',
        'Dieta blanda por 3 días y control', 'Antiinflamatorio oral por 5 días', 'Sin tratamiento',
        'Colirio cada 8 h por 7 días', 'Antibiótico oral por 10 días', 'Limpieza dental programada'];

    private object $firma;

    private array $cfgVentas = [];

    private string $tz;

    /**
     * La hora REAL, tomada una sola vez al empezar. Todo lo que pregunte «qué
     * hora es» para decidir datos (qué cita ya pasó, hasta dónde vender hoy)
     * lee esto y no `now()`: `now()` es el reloj movido con que se registran
     * compras y ventas en el pasado, y leerlo ahí deja citas de hace tres días
     * como futuras.
     */
    private CarbonImmutable $ahora;

    private array $resumen = [];

    public function handle(CobroService $cobro): int
    {
        $base = DB::connection()->getDatabaseName();
        if (! self::esBaseDePruebas($base)) {
            $this->error("«{$base}» no es una base de pruebas. Este comando BORRA datos: solo corre "
                . 'en una base cuyo nombre diga lab, demo o prueba, y nunca en producción.');
            return self::FAILURE;
        }
        if ($this->option('confirmar') !== $base) {
            $this->error("Esto va a VACIAR los datos de «{$base}» (conserva configuración y equipo activo).");
            $this->line('Si es lo que quieres, repite el comando con:');
            $this->line("    --confirmar={$base}");
            return self::FAILURE;
        }

        $sinClasificar = self::tablasSinClasificar();
        if ($sinClasificar) {
            $this->error('Hay tablas que no están clasificadas como configuración ni como datos: '
                . implode(', ', $sinClasificar) . '. Agrégalas a CONFIG o DATOS en DemoSembrar antes de seguir.');
            return self::FAILURE;
        }

        $equipo = DB::table('usuarios')->whereIn('rol', self::STAFF)->where('es_activo', 1)
            ->orderByRaw("rol = 'admin' desc")->orderBy('nombre')->orderBy('id')->get();
        if ($equipo->isEmpty()) {
            $this->error('No hay ninguna cuenta del equipo activa: se necesita una para firmar ventas y notas.');
            return self::FAILURE;
        }
        $this->firma = $equipo->first();

        if (! $this->option('sin-red')) {
            $this->info('Respaldando la base antes de vaciarla…');
            if ($this->call('respaldo:crear', ['--solo-base' => true]) !== self::SUCCESS) {
                $this->error('El respaldo falló: no se vacía nada.');
                return self::FAILURE;
            }
        }

        $this->tz = config('app.timezone');
        $this->ahora = CarbonImmutable::now($this->tz);
        $this->cfgVentas = ConfigClinica::leer('ventas');
        mt_srand((int) $this->option('semilla'));

        try {
            $this->vaciar();
            $this->sembrar($cobro, $equipo);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        $this->line('');
        $this->info('Listo. Firmado por ' . $this->firma->nombre . ' <' . $this->firma->email . '>.');
        foreach ($this->resumen as $k => $v) {
            $this->line(sprintf('  %-28s %s', $k, $v));
        }
        return self::SUCCESS;
    }

    /** Tablas de la base que no están en CONFIG ni en DATOS: hay que decidirlas antes de vaciar. */
    public static function tablasSinClasificar(): array
    {
        return array_values(array_diff(
            array_map(fn ($t) => $t['name'], Schema::getTables()),
            self::CONFIG, self::DATOS, ['usuarios'], self::INTERNAS));
    }

    /** El candado principal: el nombre dice lab/demo/prueba y no es una de producción. */
    public static function esBaseDePruebas(string $base): bool
    {
        return ! in_array($base, self::BASES_PROHIBIDAS, true) && preg_match(self::PATRON_PRUEBAS, $base) === 1;
    }

    // ── Vaciar ───────────────────────────────────────────────────────────────

    private function vaciar(): void
    {
        $fuera = DB::table('usuarios')->where(fn ($q) => $q
            ->whereNotIn('rol', self::STAFF)->orWhereNull('rol')
            ->orWhere('es_activo', '!=', 1)->orWhereNull('es_activo'));
        $this->resumen['Cuentas borradas'] = number_format((clone $fuera)->count(), 0, ',', '.')
            . ' (clientes y cuentas desactivadas)';

        Schema::disableForeignKeyConstraints();
        try {
            foreach (self::DATOS as $t) {
                if (Schema::hasTable($t)) {
                    DB::table($t)->truncate();   // TRUNCATE: el N.º de venta vuelve a 1
                }
            }
            $fuera->delete();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // Los adjuntos de las fichas viven en disco: sin sus filas quedan
        // huérfanos y algunos traen datos de personas. Las fotos de
        // especialistas (uploads/) son configuración y no se tocan.
        $dir = rtrim(config('emr.documentos_dir'), '/');
        $n = 0;
        foreach (glob($dir . '/*') ?: [] as $archivo) {
            if (is_file($archivo) && @unlink($archivo)) {
                $n++;
            }
        }
        $this->resumen['Adjuntos borrados del disco'] = (string) $n;
    }

    // ── Sembrar ──────────────────────────────────────────────────────────────

    private function sembrar(CobroService $cobro, $equipo): void
    {
        $hoy = $this->ahora->startOfDay();
        $inicio = $hoy->subMonths((int) $this->option('meses'))->startOfMonth();

        $servicios = DB::table('servicios')->where('activo', 1)->orderBy('orden')->orderBy('nombre')->orderBy('id')->get();
        $especialistas = DB::table('especialistas')->where('activo', 1)->orderBy('orden')->orderBy('nombre')->orderBy('id')->get();
        if ($servicios->isEmpty() || $especialistas->isEmpty()) {
            throw new \RuntimeException('La configuración no tiene servicios o especialistas activos: no hay con qué sembrar.');
        }
        $quienAtiende = DB::table('servicio_especialista')->get()->groupBy('servicio_id');

        $proveedores = $this->proveedores();
        $items = $this->inventario($proveedores, $inicio);
        $this->compras($proveedores, $items, $inicio, $hoy);
        [$clientes, $mascotas] = $this->clientes($inicio);
        $fichas = $this->fichas($mascotas, $especialistas, $inicio, $hoy);
        $this->protocolos($mascotas, $hoy);
        $this->recordatorios($mascotas, $hoy);
        $this->citas($cobro, $mascotas, $servicios, $especialistas, $quienAtiende, $fichas, $hoy);
        $this->ventasYCaja($cobro, $mascotas, $servicios, $especialistas, $quienAtiende, $inicio, $hoy);
        $this->equipo($equipo, $hoy);
        $this->contactoYSolicitudes($clientes, $hoy);
    }

    private function proveedores(): array
    {
        $out = [];
        foreach ([
            ['Distribuidora Veterinaria Andina', 'Marcela Ortiz'],
            ['Nutrición Animal del Sur', 'Jorge Molina'],
            ['Insumos Clínicos Cordillera', 'Andrea Fuenzalida'],
        ] as $i => [$nombre, $contacto]) {
            $id = $this->uuid();
            DB::table('proveedores')->insert([
                'id' => $id, 'nombre' => $nombre, 'contacto_nombre' => $contacto,
                'telefono' => $this->telefono(900 + $i), 'email' => 'proveedor' . ($i + 1) . '@' . self::DOMINIO,
                'notas' => null, 'activo' => 1,
                'created_at' => $this->utc($this->ahora), 'updated_at' => $this->utc($this->ahora),
            ]);
            $out[] = (object) ['id' => $id, 'nombre' => $nombre];
        }
        $this->resumen['Proveedores'] = (string) count($out);
        return $out;
    }

    private function inventario(array $proveedores, CarbonImmutable $inicio): array
    {
        // [nombre, tipo, costo, venta, mínimo, uso interno]
        $catalogo = [
            ['Alimento perro adulto 15 kg', 'Alimento', 32000, 45990, 4, 0],
            ['Alimento perro cachorro 3 kg', 'Alimento', 11000, 15990, 4, 0],
            ['Alimento gato adulto 7,5 kg', 'Alimento', 24000, 33990, 3, 0],
            ['Alimento gato esterilizado 1,5 kg', 'Alimento', 7000, 10490, 4, 0],
            ['Dieta hipoalergénica perro 2 kg', 'Alimento', 16000, 23990, 2, 0],
            ['Antipulgas perro 10-20 kg (3 pipetas)', 'Farmaco', 14000, 21990, 5, 0],
            ['Antipulgas gato (3 pipetas)', 'Farmaco', 11000, 17490, 5, 0],
            ['Antiparasitario interno perro (4 comp.)', 'Farmaco', 4500, 7990, 6, 0],
            ['Antiparasitario interno gato (2 comp.)', 'Farmaco', 3500, 6490, 6, 0],
            ['Antiinflamatorio oral 50 mg (20 comp.)', 'Farmaco', 9000, 14990, 3, 0],
            ['Antibiótico oral 250 mg (14 comp.)', 'Farmaco', 7500, 12490, 3, 0],
            ['Antipruriginoso oral 16 mg (20 comp.)', 'Farmaco', 38000, 54990, 2, 0],
            ['Shampoo dermatológico 200 ml', 'Farmaco', 8000, 13990, 4, 0],
            ['Limpiador ótico 120 ml', 'Farmaco', 6000, 10490, 4, 0],
            ['Collar isabelino talla M', 'Pet-shop', 2500, 5990, 3, 0],
            ['Juguete mordedor', 'Pet-shop', 1800, 4490, 5, 0],
            ['Arena sanitaria 10 kg', 'Pet-shop', 5500, 8990, 4, 0],
            ['Cepillo de dientes mascota', 'Pet-shop', 1200, 3490, 5, 0],
            ['Snack dental (7 unid.)', 'Pet-shop', 3000, 5490, 6, 0],
            ['Hemograma (reactivo)', 'Laboratorio', 6000, 14000, 5, 1],
            ['Perfil bioquímico (reactivo)', 'Laboratorio', 9000, 22000, 5, 1],
            ['Jeringa 3 ml (caja 100)', 'Insumo', 9000, 0, 2, 1],
            ['Guantes de examen (caja 100)', 'Insumo', 6500, 0, 3, 1],
            ['Gasa estéril (paquete)', 'Clinico', 1500, 2990, 10, 0],
            ['Venda cohesiva 5 cm', 'Clinico', 1300, 2990, 10, 0],
            ['Sutura absorbible 3-0', 'Quirurgico', 3500, 0, 6, 1],
        ];
        $out = [];
        foreach ($catalogo as $i => [$nombre, $tipo, $costo, $venta, $minimo, $interno]) {
            $id = $this->uuid();
            // Casos a la vista: uno vencido, uno por vencer, el resto lejos.
            $vence = match ($i) {
                9 => $inicio->addMonths(13)->subDays(20),            // ya vencido hoy
                10 => $this->ahora->addDays(25),   // vence pronto
                default => $this->ahora->addMonths(mt_rand(8, 30)),
            };
            DB::table('items_inventario')->insert([
                'id' => $id, 'nombre' => $nombre, 'descripcion' => null,
                'tipo' => $tipo, 'unidad' => '1', 'precio_costo' => $costo, 'precio_venta' => $venta,
                'stock_actual' => 0, 'stock_minimo' => $minimo, 'lote' => 'L-' . (2400 + $i * 13),
                'fecha_vencimiento' => $vence->format('Y-m-d'), 'activo' => 1,
                'proveedor' => $proveedores[$i % count($proveedores)]->nombre, 'bodega' => 'Principal',
                'uso_interno' => $interno,
                'created_at' => $this->utc($inicio), 'updated_at' => $this->utc($inicio),
            ]);
            $out[] = (object) ['id' => $id, 'nombre' => $nombre, 'tipo' => $tipo, 'costo' => $costo,
                'venta' => $venta, 'interno' => (bool) $interno, 'proveedor' => $i % count($proveedores)];
        }
        $this->resumen['Productos'] = (string) count($out);
        return $out;
    }

    /** Facturas de proveedor por PedidosController: es lo que suma el stock. */
    private function compras(array $proveedores, array $items, CarbonImmutable $inicio, CarbonImmutable $hoy): void
    {
        $pedidos = app(PedidosController::class);
        $fechas = [$inicio];
        for ($f = $inicio->addMonths(2); $f->lessThan($hoy->subDays(20)); $f = $f->addMonths(2)) {
            $fechas[] = $f;
        }
        array_push($fechas, $hoy->subDays(12), $hoy->subDays(3));
        $n = 0;
        foreach ($fechas as $k => $fecha) {
            foreach ($proveedores as $p => $prov) {
                $lineas = [];
                foreach ($items as $i => $it) {
                    // Tres productos no se reponen nunca: así la alerta de stock
                    // bajo el mínimo tiene algo real que mostrar.
                    $sinReponer = in_array($i, [6, 13, 18], true);
                    if ($it->proveedor === $p && ($k === 0 || ! $sinReponer)) {
                        $lineas[] = ['item_id' => $it->id, 'cantidad' => $k === 0 ? ($sinReponer ? 12 : mt_rand(30, 45)) : mt_rand(18, 32),
                            'costo_unitario' => $it->costo, 'lote' => 'L-' . $fecha->format('ym') . '-' . ($p + 1),
                            'fecha_vencimiento' => $fecha->addMonths(mt_rand(12, 30))->format('Y-m-d')];
                    }
                }
                if (! $lineas) {
                    continue;
                }
                $this->reloj($fecha->setTime(10, 0));
                $this->llamar($pedidos, 'recibir', [
                    'proveedor_id' => $prov->id, 'numero_factura' => (string) (48210 + 37 * (++$n)),
                    'fecha_recibo' => $fecha->format('Y-m-d'), 'fecha_emision' => $fecha->format('Y-m-d'),
                    // La última queda por pagar y una del medio, vencida.
                    'fecha_vencimiento_pago' => $fecha->addDays(30)->format('Y-m-d'),
                    'notas' => null, 'lineas' => $lineas,
                ]);
            }
        }
        // Todas nacen sin pagar: se pagan las viejas y quedan dos pendientes
        // (una vencida y otra en plazo), que es lo que mira «a quién pagar hoy».
        $facturas = DB::table('facturas_compra')->orderBy('fecha_recibo')->orderBy('id')->get();
        foreach ($facturas as $i => $f) {
            if ($i < $facturas->count() - 4) {
                $this->llamar($pedidos, 'actualizarPago', ['estado_pago' => 'pagada'], [$f->id]);
            }
        }
        $this->resumen['Facturas de proveedor'] = $facturas->count() . ' (las últimas sin pagar)';
    }

    private function clientes(CarbonImmutable $inicio): array
    {
        $nombres = ['Camila', 'Valentina', 'Javiera', 'Catalina', 'Fernanda', 'Constanza', 'Daniela',
            'Francisca', 'Antonia', 'Josefa', 'Martina', 'Isidora', 'Sofía', 'Florencia', 'Carolina',
            'Paula', 'Macarena', 'Trinidad', 'Ignacia', 'Bárbara', 'Matías', 'Benjamín', 'Vicente',
            'Joaquín', 'Tomás', 'Sebastián', 'Diego', 'Nicolás', 'Felipe', 'Cristóbal', 'Agustín',
            'Martín', 'Lucas', 'Gabriel', 'Rodrigo', 'Andrés', 'Pablo', 'Ignacio', 'Gonzalo', 'Esteban',
            'Claudia', 'Patricia', 'Verónica', 'Alejandra', 'Marcela', 'Loreto', 'Pamela', 'Paz', 'Emilia',
            'Renata', 'Mauricio', 'Cristián', 'Jaime', 'Álvaro', 'Hernán', 'Patricio', 'Ricardo', 'Luis',
            'Juan Pablo', 'Francisco'];
        $apellidos = ['González', 'Muñoz', 'Rojas', 'Díaz', 'Pérez', 'Soto', 'Contreras', 'Silva',
            'Martínez', 'Sepúlveda', 'Morales', 'Rodríguez', 'López', 'Fuentes', 'Hernández', 'Torres',
            'Araya', 'Flores', 'Espinoza', 'Valenzuela', 'Castillo', 'Tapia', 'Reyes', 'Gutiérrez',
            'Vargas', 'Cortés', 'Núñez', 'Jara', 'Carrasco', 'Vera', 'Bravo', 'Olivares', 'Cáceres', 'Pizarro'];
        // La clínica está en Puente Alto: la mayoría de los clientes, también.
        $comunas = ['Puente Alto', 'Puente Alto', 'Puente Alto', 'Puente Alto', 'La Florida', 'La Pintana',
            'Pirque', 'San Bernardo', 'Peñalolén'];
        $calles = ['Calle Los Aromos', 'Pasaje El Roble', 'Av. Las Palmas', 'Calle Las Acacias', 'Av. Los Pinos',
            'Pasaje Los Castaños', 'Calle Los Olmos', 'Pasaje Las Dalias', 'Av. El Peñón', 'Calle Los Quillayes'];
        $perros = ['Luna', 'Max', 'Rocky', 'Kira', 'Toby', 'Maya', 'Simba', 'Lola', 'Coco', 'Bruno',
            'Nala', 'Thor', 'Canela', 'Oso', 'Frida', 'Zeus', 'Mía', 'Bobby', 'Pelusa', 'Chester',
            'Pancho', 'Cachupín', 'Kiara', 'Benito', 'Lupe', 'Rufo', 'Mora', 'Lucky', 'Princesa', 'Bigotes'];
        $gatos = ['Michi', 'Garfield', 'Salem', 'Pantera', 'Nube', 'Tom', 'Kitty', 'Félix', 'Mostaza',
            'Oliver', 'Pimienta', 'Luz', 'Sombra', 'Tigre', 'Misha', 'Romeo', 'Cleo', 'Manchas'];
        $razasPerro = ['Mestizo', 'Labrador', 'Poodle', 'Bulldog Francés', 'Golden Retriever', 'Beagle',
            'Schnauzer', 'Pastor Alemán', 'Yorkshire', 'Shih Tzu', 'Border Collie', 'Dachshund'];
        $razasGato = ['Mestizo', 'Siamés', 'Persa', 'Maine Coon', 'Bengalí'];

        $hash = Hash::make(Str::random(48));   // nadie conoce esta clave: no se entra con ella
        $clientes = [];
        $mascotas = [];
        $ahora = $this->ahora;
        $total = max(10, (int) $this->option('clientes'));
        for ($i = 0; $i < $total; $i++) {
            $nombre = $this->elegir($nombres);
            $apellido = $this->elegir($apellidos) . ' ' . $this->elegir($apellidos);
            $id = $this->uuid();
            $alta = $inicio->addDays(mt_rand(0, max(1, Fechas::diasEntre($inicio, $ahora) - 30)));
            $acepto = $i === 0 || $i % 3 !== 0;   // la cuenta de muestra ya aceptó: el aviso no tapa la demo
            DB::table('usuarios')->insert([
                'id' => $id,
                // El primero es la cuenta de muestra del portal del cliente: con
                // clave conocida, para poder entrar a mostrarlo.
                'email' => $i === 0 ? self::CLIENTE_MUESTRA
                    : Str::slug($nombre . '.' . explode(' ', $apellido)[0], '.') . '.' . ($i + 1) . '@' . self::DOMINIO,
                'password_hash' => $i === 0 ? Hash::make((string) $this->option('clave-cliente')) : $hash,
                'nombre' => "{$nombre} {$apellido}",
                'telefono' => $this->telefono($i + 1), 'rol' => 'dueño', 'es_activo' => 1,
                'rut' => $this->rut(33000000 + $i * 7919), 'direccion' => $this->elegir($calles) . ' ' . mt_rand(100, 2999),
                'ciudad' => $this->elegir($comunas), 'region' => 'Metropolitana',
                'email_verificado' => 1, 'puntos_fidelizacion' => 0,
                'privacy_accepted' => $acepto ? 1 : 0, 'privacy_accepted_at' => $acepto ? $this->utc($alta) : null,
                'privacy_version' => $acepto ? '2026-09' : null,
                'marketing_consent' => $i % 5 === 0 ? 1 : 0,
                'marketing_consent_at' => $i % 5 === 0 || $i === 7 ? $this->utc($alta) : null,
                // Uno que consintió y después se retiró: el caso que la ley pide poder demostrar.
                'marketing_consent_retirado_at' => $i === 7 ? $this->utc($alta->addMonths(2)) : null,
                'created_at' => $this->utc($alta), 'updated_at' => $this->utc($alta),
            ]);
            $clientes[] = (object) ['id' => $id, 'nombre' => "{$nombre} {$apellido}"];

            $cuantas = $i % 4 === 0 ? 2 : ($i % 13 === 0 ? 3 : 1);
            for ($k = 0; $k < $cuantas; $k++) {
                $esPerro = mt_rand(1, 100) <= 65;
                $especie = $i === $total - 1 && $k === 0 ? 'conejo' : ($esPerro ? 'perro' : 'gato');
                $mid = $this->uuid();
                $nac = mt_rand(1, 100) <= 8 ? null : $ahora->subMonths(mt_rand(3, 168));
                $fallecida = count($mascotas) === 11;
                DB::table('mascotas')->insert([
                    'id' => $mid, 'usuario_id' => $id,
                    'nombre' => $especie === 'conejo' ? 'Copito' : ($esPerro ? $this->elegir($perros) : $this->elegir($gatos)),
                    'especie' => $especie,
                    'raza' => $especie === 'conejo' ? 'Belier' : ($esPerro ? $this->elegir($razasPerro) : $this->elegir($razasGato)),
                    'sexo' => mt_rand(0, 1) ? 'M' : 'F',
                    'fecha_nacimiento' => $nac?->format('Y-m-d'),
                    'peso_kg' => $esPerro ? mt_rand(30, 380) / 10 : mt_rand(25, 65) / 10,
                    'alergias' => mt_rand(1, 100) <= 15 ? 'Alergia alimentaria a la proteína de pollo' : null,
                    'esterilizado' => mt_rand(0, 1),
                    'estado' => $fallecida ? 'Fallecido' : 'Vivo',
                    'fecha_fallecido' => $fallecida ? $ahora->subMonths(2)->format('Y-m-d') : null,
                    'created_at' => $this->utc($alta), 'updated_at' => $this->utc($alta),
                ]);
                $mascotas[] = (object) ['id' => $mid, 'cliente' => $clientes[$i], 'especie' => $especie,
                    'nacimiento' => $nac, 'viva' => ! $fallecida, 'alta' => $alta];
            }
        }
        $this->resumen['Clientes'] = (string) count($clientes);
        $this->resumen['Mascotas'] = count($mascotas) . ' (1 fallecida, 1 conejo, algunas sin fecha de nacimiento)';
        return [$clientes, $mascotas];
    }

    private function fichas(array $mascotas, $especialistas, CarbonImmutable $inicio, CarbonImmutable $hoy): array
    {
        [$motivos, $diagnosticos, $tratamientos] = [self::MOTIVOS, self::DIAGNOSTICOS, self::TRATAMIENTOS];
        $fichas = [];
        $notas = 0;
        $docs = 0;
        $dir = rtrim(config('emr.documentos_dir'), '/');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);   // un volumen recién creado se monta vacío (§9.16)
        }
        foreach ($mascotas as $j => $m) {
            $fid = $this->uuid();
            DB::table('fichas_clinicas')->insert([
                'id' => $fid, 'mascota_id' => $m->id,
                'antecedentes' => $j % 3 === 0 ? 'Sin cirugías previas.' : ($j % 5 === 0 ? 'Esterilizada/o. Sin otras cirugías.' : null),
                'vacunas' => $m->especie === 'perro' ? 'Séxtuple y antirrábica' : ($m->especie === 'gato' ? 'Triple felina' : null),
                'condiciones_cronicas' => $j % 7 === 0 ? 'Dermatitis atópica' : null,
                'caracter' => $j % 9 === 0 ? 'Agresivo' : null,
                'created_at' => $this->utc($m->alta), 'updated_at' => $this->utc($m->alta),
            ]);
            $fichas[$m->id] = $fid;
            $cuantas = $j % 6 === 0 ? 0 : mt_rand(1, 4);
            for ($k = 0; $k < $cuantas; $k++) {
                $fecha = $this->entre($m->alta, $hoy->subDays(1))->setTime(mt_rand(10, 18), [0, 30][mt_rand(0, 1)]);
                $nid = $this->uuid();
                DB::table('notas_clinicas')->insert([
                    'id' => $nid, 'ficha_id' => $fid, 'veterinario_id' => $this->firma->id,
                    'especialista_id' => $this->elegir($especialistas->all())->id,
                    'motivo' => $this->elegir($motivos),
                    'examen' => 'FC: ' . mt_rand(80, 140) . ', FR: ' . mt_rand(18, 36) . ', Temp: ' . (mt_rand(380, 393) / 10),
                    'diagnostico' => $this->elegir($diagnosticos), 'tratamiento' => $this->elegir($tratamientos),
                    'indicaciones' => 'Control en 15 días si no mejora.',
                    'proximo_control' => mt_rand(0, 1) ? $fecha->addDays(15)->format('Y-m-d') : null,
                    'temperatura' => mt_rand(380, 393) / 10, 'frecuencia_cardiaca' => mt_rand(80, 140),
                    'frecuencia_respiratoria' => mt_rand(18, 36),
                    'created_at' => $this->utc($fecha),
                ]);
                $notas++;
                if ($docs < 12 && $k === 0 && $j % 5 === 1) {
                    $this->documento($fid, $nid, $docs, $fecha, $dir);
                    $docs++;
                }
            }
        }
        $this->resumen['Notas clínicas'] = "{$notas} (y {$docs} documentos con su archivo)";
        return $fichas;
    }

    /** Un PDF mínimo pero válido: el panel lo abre y respaldo:verificar lo encuentra. */
    private function documento(string $fichaId, string $notaId, int $n, CarbonImmutable $fecha, string $dir): void
    {
        $tipos = ['examen', 'rx', 'receta', 'examen', 'otro', 'examen'];
        $titulos = ['Hemograma completo', 'Radiografia de torax', 'Receta medica', 'Perfil bioquimico',
            'Consentimiento informado', 'Examen coproparasitario'];
        $texto = $titulos[$n % count($titulos)] . ' - documento de ejemplo con datos ficticios';
        $stream = "BT /F1 14 Tf 60 760 Td ({$texto}) Tj ET";
        $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $pdf = "%PDF-1.4\n";
        $offs = [];
        foreach ($objs as $i => $o) {
            $offs[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n{$o}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offs as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        $archivo = 'doc-' . $fichaId . '-' . substr(bin2hex(random_bytes(8)), 0, 10) . '.pdf';
        file_put_contents($dir . '/' . $archivo, $pdf);
        DB::table('documentos_clinicos')->insert([
            'id' => $this->uuid(), 'ficha_id' => $fichaId, 'nota_id' => $notaId,
            'subido_por_id' => $this->firma->id, 'tipo' => $tipos[$n % count($tipos)],
            'descripcion' => str_replace(['Radiografia de torax', 'Receta medica', 'Perfil bioquimico'],
                ['Radiografía de tórax', 'Receta médica', 'Perfil bioquímico'], $titulos[$n % count($titulos)]),
            'nombre_original' => Str::slug($titulos[$n % count($titulos)]) . '.pdf',
            'nombre_archivo' => $archivo, 'mime_type' => 'application/pdf', 'tamano_bytes' => strlen($pdf),
            'created_at' => $this->utc($fecha),
        ]);
    }

    private function protocolos(array $mascotas, CarbonImmutable $hoy): void
    {
        $protocolos = DB::table('protocolos_preventivos')->where('activo', 1)->get();
        $n = 0;
        foreach ($mascotas as $j => $m) {
            if (! $m->viva) {
                continue;
            }
            foreach ($protocolos as $p) {
                if ($p->especie && $p->especie !== $m->especie) {
                    continue;
                }
                // Un tercio nunca aplicado (pendiente), el resto repartido entre
                // al día, próximo a vencer y vencido.
                $caso = ($j + strlen($p->nombre)) % 4;
                if ($caso === 0) {
                    continue;
                }
                $frec = max(1, (int) $p->frecuencia_meses);
                $ultima = match ($caso) {
                    1 => $hoy->subDays(mt_rand(10, 40)),                         // al día
                    2 => $hoy->subMonths($frec)->addDays(mt_rand(3, 20)),        // vence pronto
                    default => $hoy->subMonths($frec)->subDays(mt_rand(20, 90)), // vencido
                };
                DB::table('protocolo_aplicaciones')->insert([
                    'id' => $this->uuid(), 'protocolo_id' => $p->id, 'mascota_id' => $m->id,
                    'fecha_aplicacion' => $ultima->format('Y-m-d'),
                    'proxima_fecha' => $ultima->addMonths($frec)->format('Y-m-d'),
                    'registrado_por_id' => $this->firma->id, 'notas' => null,
                    'created_at' => $this->utc($ultima),
                ]);
                $n++;
            }
        }
        $this->resumen['Dosis preventivas'] = "{$n} (al día, por vencer y vencidas)";
    }

    private function recordatorios(array $mascotas, CarbonImmutable $hoy): void
    {
        $titulos = ['Vacuna antirrábica', 'Desparasitación interna', 'Control dermatológico',
            'Control post operatorio', 'Vacuna séxtuple', 'Vacuna triple felina'];
        $n = 0;
        foreach ($mascotas as $j => $m) {
            if ($j % 2 !== 0 || ! $m->viva) {
                continue;
            }
            $fecha = $hoy->addDays(mt_rand(-45, 60));
            $estado = $fecha->lessThan($hoy->subDays(20)) && $j % 3 === 0 ? 'completado'
                : ($j % 11 === 0 ? 'cancelado' : ($fecha->lessThan($hoy) && $j % 4 === 0 ? 'enviado' : 'pendiente'));
            DB::table('recordatorios')->insert([
                'id' => $this->uuid(), 'mascota_id' => $m->id, 'usuario_dueno_id' => $m->cliente->id,
                'titulo' => $this->elegir($titulos), 'categoria' => 'Preventiva',
                'mensaje' => 'Le corresponde: agendar una hora.', 'fecha_programada' => $fecha->format('Y-m-d'),
                'estado' => $estado, 'enviado_at' => $estado === 'enviado' ? $this->utc($fecha) : null,
                'canal' => $j % 3 ? 'email' : 'telefono', 'creado_por_id' => $this->firma->id,
                'created_at' => $this->utc($fecha->subDays(30)), 'updated_at' => $this->utc($fecha->subDays(30)),
            ]);
            $n++;
        }
        $this->resumen['Recordatorios'] = "{$n} (vencidos, próximos, enviados, completados, cancelados)";
    }

    /**
     * Citas insertadas directo, con el cobro de CobroService (el mismo de la
     * reserva). No pasan por la ruta de reserva porque esa manda correos y crea
     * eventos de Google: acá solo se quiere el dato.
     */
    private function citas(CobroService $cobro, array $mascotas, $servicios, $especialistas, $quienAtiende,
        array $fichas, CarbonImmutable $hoy): void
    {
        $vivas = array_values(array_filter($mascotas, fn ($m) => $m->viva));
        $ocupado = [];
        $conteo = [];
        // Las tres primeras del catálogo (consulta, control, vacuna) son lo más
        // pedido: pesan cuatro veces más que el resto.
        $ponderados = [];
        foreach ($servicios as $k => $s) {
            array_push($ponderados, ...array_fill(0, $k < 3 ? 4 : 1, $s));
        }
        $cuantas = max(70, (int) round(count($mascotas) * 1.9));
        $pasadas = (int) round($cuantas * 0.62);
        for ($i = 0; $i < $cuantas; $i++) {
            // Más densas cerca de hoy, como una agenda real: un tercio de las
            // pasadas en las dos últimas semanas y la mitad de las futuras en
            // los próximos diez días. Así la vista de semana nunca sale vacía.
            $dias = $i < $pasadas ? -mt_rand(1, $i % 3 === 0 ? 14 : 75)
                : ($i < $pasadas + 10 ? 0 : mt_rand(1, $i % 2 ? 10 : 35));
            $dia = $hoy->addDays($dias);
            // Hoy se atiende aunque caiga domingo: la demo tiene que mostrar un día de trabajo.
            if ($dia->isSunday() && $dias !== 0) {
                $dia = $dia->addDay();
            }
            $svc = $this->elegir($ponderados);
            $esp = $this->especialistaPara($svc, $especialistas, $quienAtiende);
            do {
                $hora = $dia->setTime(mt_rand(10, 17), [0, 30][mt_rand(0, 1)]);
                $clave = $esp->id . $hora->format('YmdHi');
            } while (isset($ocupado[$clave]));
            $ocupado[$clave] = true;
            // La cuenta de muestra (sus mascotas van primero) tiene una hora hoy y la
            // primera de las próximas: así su portal nunca se ve vacío.
            $m = $i === $pasadas || $i === $pasadas + 10 ? $vivas[0] : $this->elegir($vivas);

            $abono = $cobro->montoAbono($esp, $svc, $hora);
            $total = $cobro->montoTotal($esp, $svc, $hora);
            $pasada = $hora->lessThan($this->ahora);
            $suerte = mt_rand(1, 100);
            if ($pasada) {
                $estado = $suerte <= 84 ? 'Completada' : ($suerte <= 93 ? 'Cancelada' : ($abono ? 'Expirada' : 'Cancelada'));
            } else {
                $estado = $abono && $suerte <= 35 ? 'Agendada' : ($suerte > 95 ? 'Cancelada' : 'Confirmada');
            }
            $pagado = $abono && in_array($estado, ['Completada', 'Confirmada'], true);
            $id = $this->uuid();
            DB::table('citas')->insert([
                'id' => $id, 'mascota_id' => $m->id, 'usuario_dueno_id' => $m->cliente->id,
                'fecha_hora' => $hora->format('Y-m-d H:i:s'), 'servicio' => $svc->nombre,
                'servicio_id' => $svc->id, 'especialista_id' => $esp->id, 'duracion_min' => $svc->duracion_min,
                'respuestas' => json_encode(['Motivo' => $this->elegir(self::MOTIVOS),
                    'Origen' => $this->elegir(['Portal de reservas', 'Portal de reservas', 'Teléfono', 'Mostrador'])],
                    JSON_UNESCAPED_UNICODE),
                'estado' => $estado,
                'payment_status' => $abono ? ($pagado ? 'approved' : 'pending') : null,
                'monto_abono_clp' => $abono, 'monto_total_clp' => $total,
                'pago_modalidad_requerida' => $svc->modalidad_pago ?: 'abono',
                'pago_modalidad' => $pagado ? 'abono' : null,
                'pago_referencia' => $pagado ? 'RES-' . strtoupper(substr(str_replace('-', '', $id), 0, 12)) : null,
                'pago_actualizado_at' => $pagado ? $this->utc($hora->subDays(2)) : null,
                // Las pasadas se dan por avisadas: que el barrido no las tome.
                'recordatorio_24h_enviado_at' => $pasada ? $this->utc($hora->subDay()) : null,
                'recordatorio_2h_enviado_at' => $pasada ? $this->utc($hora->subHours(2)) : null,
                'created_at' => $this->utc($hora->subDays(mt_rand(2, 20))),
            ]);
            if ($estado === 'Completada' && $i % 3 === 0) {
                DB::table('notas_clinicas')->insert([
                    'id' => $this->uuid(), 'ficha_id' => $fichas[$m->id], 'veterinario_id' => $this->firma->id,
                    'cita_id' => $id, 'especialista_id' => $esp->id, 'motivo' => $svc->nombre,
                    'diagnostico' => $this->elegir(self::DIAGNOSTICOS), 'tratamiento' => $this->elegir(self::TRATAMIENTOS),
                    'created_at' => $this->utc($hora->addMinutes(40)),
                ]);
            }
            $conteo[$estado] = ($conteo[$estado] ?? 0) + 1;
        }
        ksort($conteo);
        $this->resumen['Citas'] = implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($conteo), $conteo));
    }

    /**
     * Ventas y caja por el código real, día por día: se abre la caja, se vende,
     * a veces sale un egreso, y se cierra declarando lo que el sistema espera
     * (salvo dos días, que quedan con descuadre a propósito). Hoy la caja queda
     * ABIERTA, como en un día de trabajo.
     */
    private function ventasYCaja(CobroService $cobro, array $mascotas, $servicios, $especialistas, $quienAtiende,
        CarbonImmutable $inicio, CarbonImmutable $hoy): void
    {
        $caja = app(CajaController::class);
        $tx = app(TransaccionesController::class);
        // Por FECHA y desde el mediodía: en Chile el día del cambio de hora no
        // tiene medianoche, y sumar días desde las 00:00 corría el reloj una
        // hora para siempre — «hoy» no llegaba a coincidir y quedaba fuera.
        $dias = [];
        $hoyTxt = $hoy->format('Y-m-d');
        $recientes = $hoy->subMonths(3)->format('Y-m-d');
        for ($d = $inicio->setTime(12, 0); $d->format('Y-m-d') <= $hoyTxt; $d = $d->addDay()) {
            if ($d->isSunday() && $d->format('Y-m-d') !== $hoyTxt) {
                continue;
            }
            // Más movimiento en los meses recientes, como una clínica que crece.
            $p = $d->format('Y-m-d') > $recientes ? 75 : 50;
            if ($d->format('Y-m-d') === $hoyTxt || mt_rand(1, 100) <= $p) {
                $dias[] = $d->startOfDay();
            }
        }
        $descuadres = [count($dias) - 9, count($dias) - 40];
        $vivas = array_values(array_filter($mascotas, fn ($m) => $m->viva));
        $productos = array_values(array_filter(
            DB::table('items_inventario')->get()->all(), fn ($i) => (float) $i->precio_venta > 0 && ! $i->uso_interno));

        $n = 0;
        $casos = ['anuladas' => 0, 'devoluciones' => 0, 'borradores' => 0, 'medio de pago corregido' => 0];
        foreach ($dias as $k => $dia) {
            $esHoy = $dia->format('Y-m-d') === $hoyTxt;
            // Hoy la caja abre antes de la hora real (si el comando corre
            // temprano, a las 9:00 todavía no habría abierto).
            $apertura = $dia->setTime(9, mt_rand(0, 20));
            if ($esHoy && $apertura->greaterThan($this->ahora->subMinutes(150))) {
                $apertura = $this->ahora->subMinutes(150);
            }
            $this->reloj($apertura);
            $this->llamar($caja, 'abrir', ['monto_inicial' => [30000, 50000, 50000, 80000][mt_rand(0, 3)]]);

            $ventasDia = $esHoy ? 4 : mt_rand(2, 6);
            $horas = [];
            for ($v = 0; $v < $ventasDia; $v++) {
                $horas[] = mt_rand(10 * 60, 18 * 60 + 30);
            }
            sort($horas);
            $idsDelDia = [];
            foreach ($horas as $h) {
                $momento = $dia->setTime(intdiv($h, 60), $h % 60, mt_rand(0, 59));
                if ($esHoy) {
                    // Entre la apertura y la hora real, nunca en el futuro.
                    $momento = $apertura->addMinutes(mt_rand(5, 140));
                }
                $this->reloj($momento);
                $venta = $this->armarVenta($cobro, $vivas, $productos, $servicios, $especialistas, $quienAtiende, $momento);
                $r = $this->llamar($tx, 'crear', $venta);
                $idsDelDia[] = $r['id'] ?? DB::table('transacciones')->orderByDesc('numero')->value('id');
                $n++;
            }

            // Casos que no salen de una venta normal, repartidos en la historia.
            if ($k === count($dias) - 25 || $k === count($dias) - 70) {
                $this->reloj($dia->setTime(18, 50));
                $t = DB::table('transacciones')->where('id', $idsDelDia[0])->first();
                $efectivo = (float) $t->pago_efectivo;
                $this->llamar($tx, 'anular', ['motivo' => 'Error de digitación',
                    'plata_entro' => $efectivo > 0, 'efectivo_devuelto' => $efectivo], [$t->id]);
                $casos['anuladas']++;
            }
            if ($k === count($dias) - 15) {
                $linea = DB::table('ventas')->whereIn('transaccion_id', $idsDelDia)->whereNotNull('item_id')
                    ->orderBy('id')->first();
                if ($linea) {
                    $this->reloj($dia->setTime(18, 55));
                    $this->llamar($tx, 'devolucion', ['motivo' => 'Producto devuelto sin abrir',
                        'lineas' => [['venta_id' => $linea->id, 'cantidad' => 1]], 'efectivo_devuelto' => 0],
                        [$linea->transaccion_id]);
                    $casos['devoluciones']++;
                }
            }
            if ($k === count($dias) - 6) {
                // «Se tipeó efectivo y era débito»: lo único que el sistema deja
                // corregir de un pago. Cobrar DESPUÉS un saldo pendiente no
                // existe todavía (total_pendiente solo se escribe al crear), así
                // que no se inventa acá.
                $t = DB::table('transacciones')->whereIn('id', $idsDelDia)->where('pago_efectivo', '>', 0)
                    ->where('pago_debito', 0)->where('estado_venta', 'confirmada')->orderBy('id')->first();
                if ($t) {
                    $this->reloj($dia->setTime(18, 58));
                    $this->llamar($tx, 'cambiarPago', ['pago_efectivo' => 0, 'pago_debito' => (float) $t->pago_efectivo,
                        'pago_credito' => (float) $t->pago_credito, 'pago_transferencia' => (float) $t->pago_transferencia,
                        'pago_otro' => (float) $t->pago_otro], [$t->id]);
                    $casos['medio de pago corregido']++;
                }
            }
            if (mt_rand(1, 100) <= 12) {
                $this->reloj($dia->setTime(13, mt_rand(0, 59)));
                $this->llamar($caja, 'crearMovimiento', ['tipo' => 'egreso', 'monto' => [3500, 5000, 12000][mt_rand(0, 2)],
                    'comentario' => $this->elegir(['Compra de insumos de aseo', 'Pago de despacho', 'Compra de agua y café'])]);
            }

            if ($esHoy) {
                // Dos borradores abiertos: la cola de «por cobrar». NO se
                // confirma ninguno: hoy (6-oct-2026) `confirmar` cambia el estado
                // sin registrar el pago ni el saldo, y la venta queda «Pagada»
                // con $0 cobrado. Sembrarlo pondría en pruebas una venta pagada
                // que nadie pagó. Cuando confirmar pase por el cobro, se agrega.
                foreach ([0, 1] as $b) {
                    $this->reloj($apertura->addMinutes(141 + $b * 2));
                    $venta = $this->armarVenta($cobro, $vivas, $productos, $servicios, $especialistas, $quienAtiende,
                        $this->ahora, true);
                    $this->llamar($tx, 'crear', $venta);
                    $casos['borradores']++;
                }
                break;   // la caja de hoy queda abierta
            }

            $this->reloj($dia->setTime(19, mt_rand(0, 30)));
            $esperado = (float) ($this->llamar($caja, 'estado')['efectivo_esperado'] ?? 0);
            $declarado = in_array($k, $descuadres, true) ? $esperado - [2000, 5000][$k % 2] : $esperado;
            $this->llamar($caja, 'cerrar', ['monto_declarado' => $declarado,
                'notas' => $declarado != $esperado ? 'Diferencia en efectivo: se revisa mañana' : null]);
        }

        $estados = DB::table('transacciones')->select('estado_venta', DB::raw('count(*) n'))
            ->groupBy('estado_venta')->pluck('n', 'estado_venta')->all();
        ksort($estados);
        $this->resumen['Ventas'] = DB::table('transacciones')->count() . ' en ' . count($dias) . ' días ('
            . implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($estados), $estados)) . ')';
        $this->resumen['Casos especiales'] = implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($casos), $casos));
        $this->resumen['Cajas'] = DB::table('sesiones_caja')->whereNotNull('cerrada_at')->count()
            . ' cerradas (2 con descuadre) y ' . DB::table('sesiones_caja')->whereNull('cerrada_at')->count() . ' abierta';
    }

    private function armarVenta(CobroService $cobro, array $vivas, array $productos, $servicios, $especialistas,
        $quienAtiende, CarbonImmutable $cuando, bool $borrador = false): array
    {
        $exigePaciente = ! empty($this->cfgVentas['exigir_paciente']);
        $sinCliente = ! $borrador && ! $exigePaciente && mt_rand(1, 100) <= 15;
        $m = $sinCliente ? null : $this->elegir($vivas);
        $lineas = [];
        $especialista = null;

        if ($m && mt_rand(1, 100) <= 60) {
            $svc = $servicios[mt_rand(0, $servicios->count() - 1)];
            $especialista = $this->especialistaPara($svc, $especialistas, $quienAtiende);
            $precio = $cobro->precioConsulta($especialista, $svc, $cuando) ?? (int) ($svc->precio_clp ?? 0);
            // Un control sin costo de vez en cuando: el caso «Sin cargo».
            $precio = mt_rand(1, 100) <= 6 ? 0 : $precio;
            $lineas[] = ['nombre' => $svc->nombre, 'servicio_id' => $svc->id, 'tipo' => 'Servicio',
                'cantidad' => 1, 'precio_costo' => 0, 'precio_venta' => $precio];
        }
        $cuantos = $lineas ? mt_rand(0, 2) : mt_rand(1, 3);
        for ($i = 0; $i < $cuantos; $i++) {
            $p = $this->elegir($productos);
            $stock = (int) DB::table('items_inventario')->where('id', $p->id)->value('stock_actual');
            if ($stock < 2) {
                continue;   // no forzar stock negativo: la regla puede estar encendida
            }
            $descuento = empty($this->cfgVentas['clave_descuentos']) && mt_rand(1, 100) <= 5
                ? round((float) $p->precio_venta * 0.1) : 0;
            $lineas[] = ['nombre' => $p->nombre, 'item_id' => $p->id, 'tipo' => $p->tipo,
                'cantidad' => mt_rand(1, 2), 'precio_costo' => (float) $p->precio_costo,
                'precio_venta' => (float) $p->precio_venta, 'descuento' => $descuento];
        }
        if (! $lineas) {
            $p = $productos[0];
            $lineas[] = ['nombre' => $p->nombre, 'item_id' => $p->id, 'tipo' => $p->tipo, 'cantidad' => 1,
                'precio_costo' => (float) $p->precio_costo, 'precio_venta' => (float) $p->precio_venta];
        }
        foreach ($lineas as &$l) {
            $l['propietario_id'] = $m?->cliente->id;
            $l['mascota_id'] = $m?->id;
            $l['especialista_id'] = $especialista?->id;
        }
        unset($l);

        $total = array_sum(array_map(fn ($l) => max(0, $l['precio_venta'] * $l['cantidad'] - ($l['descuento'] ?? 0)), $lineas));
        $venta = ['estado_venta' => $borrador ? 'borrador' : 'confirmada', 'propietario_id' => $m?->cliente->id,
            'especialista_id' => $especialista?->id, 'ventas' => $lineas];
        if ($borrador) {
            return $venta;
        }
        // Cómo se paga: casi todo entero, a veces mitad y mitad, y unas pocas
        // quedan debiendo (parcial o entera).
        $suerte = mt_rand(1, 100);
        $pendiente = $suerte > 97 ? $total : ($suerte > 91 ? round($total / 2) : 0);
        $pagado = $total - $pendiente;
        $medios = ['pago_efectivo', 'pago_debito', 'pago_credito', 'pago_transferencia'];
        $medio = $medios[mt_rand(0, 3)];
        if ($pagado > 0 && mt_rand(1, 100) <= 10) {
            $mitad = round($pagado / 2);
            $venta['pago_efectivo'] = $mitad;
            $venta['pago_debito'] = $pagado - $mitad;
        } elseif ($pagado > 0) {
            $venta[$medio] = $pagado;
        }
        $venta['total_pendiente'] = $pendiente;
        return $venta;
    }

    private function equipo($equipo, CarbonImmutable $hoy): void
    {
        $marcajes = 0;
        foreach ($equipo as $i => $u) {
            for ($d = 6; $d >= 0; $d--) {
                $dia = $hoy->subDays($d);
                if ($dia->isSunday()) {
                    continue;
                }
                $entra = $dia->setTime(8, 45 + mt_rand(0, 25));
                DB::table('marcajes_asistencia')->insert(['id' => $this->uuid(), 'usuario_id' => $u->id,
                    'tipo' => 'entrada', 'momento' => $entra->format('Y-m-d H:i:s'), 'registrado_por_id' => $u->id]);
                $marcajes++;
                // Hoy sigue adentro, y a uno se le olvidó marcar la salida hace 3 días.
                if ($d === 0 || ($i === 0 && $d === 3)) {
                    continue;
                }
                DB::table('marcajes_asistencia')->insert(['id' => $this->uuid(), 'usuario_id' => $u->id,
                    'tipo' => 'salida', 'momento' => $dia->setTime(18, mt_rand(0, 50))->format('Y-m-d H:i:s'),
                    'registrado_por_id' => $u->id]);
                $marcajes++;
            }
        }
        foreach ([['Revisar stock de antipulgas', 2, false], ['Llamar a proveedor por factura vencida', -1, false],
            ['Ordenar bodega', -5, true], ['Actualizar precios de alimentos', 7, false]] as $k => [$titulo, $dias, $hecha]) {
            DB::table('tareas')->insert(['id' => $this->uuid(), 'titulo' => $titulo, 'descripcion' => null,
                'asignado_a_id' => $equipo[$k % $equipo->count()]->id, 'fecha_vencimiento' => $hoy->addDays($dias)->format('Y-m-d'),
                'completada' => $hecha ? 1 : 0, 'completada_at' => $hecha ? $this->utc($hoy->subDays(4)) : null,
                'creado_por_id' => $this->firma->id, 'created_at' => $this->utc($hoy->subDays(10))]);
        }
        $this->resumen['Marcajes de asistencia'] = "{$marcajes} (con un olvido de salida)";
        foreach ([['Limpieza y desinfección de box', 'diaria', 'Clínica'], ['Cuadrar caja al cierre', 'diaria', 'Caja'],
            ['Revisar vencimientos de farmacia', 'semanal', 'Farmacia']] as [$titulo, $frecuencia, $area]) {
            DB::table('tareas_recurrentes')->insert(['id' => $this->uuid(), 'titulo' => $titulo, 'frecuencia' => $frecuencia,
                'area' => $area, 'activa' => 1, 'creado_por_id' => $this->firma->id, 'created_at' => $this->utc($hoy->subDays(30))]);
        }
        $this->resumen['Tareas'] = '4 (una vencida, una hecha) y 3 recurrentes';
    }

    private function contactoYSolicitudes(array $clientes, CarbonImmutable $hoy): void
    {
        foreach ([
            ['Rocío Navarrete', '¿Atienden exóticos?', 'Hola, tengo un hurón y quería saber si lo pueden ver para una vacuna.', true],
            ['Héctor Salinas', 'Consulta por precio de dermatología',
                'Mi perro se rasca mucho las orejas, ¿cuánto sale la consulta dermatológica?', false],
            ['Paz Cifuentes', 'Quiero cambiar mi hora', 'Tengo hora el jueves y no voy a poder llegar, ¿la puedo pasar al sábado?', false],
        ] as $k => [$nombre, $asunto, $mensaje, $leido]) {
            DB::table('mensajes_contacto')->insert(['id' => $this->uuid(), 'nombre' => $nombre,
                'email' => Str::slug($nombre, '.') . '@' . self::DOMINIO, 'asunto' => $asunto,
                'mensaje' => $mensaje, 'leido' => $leido ? 1 : 0,
                'leido_at' => $leido ? $this->utc($hoy->subDays(2)) : null,
                'created_at' => $this->utc($hoy->subDays(3 - $k))]);
        }
        $c = $clientes[5];
        DB::table('solicitudes_titular')->insert(['id' => $this->uuid(), 'usuario_id' => $c->id,
            'email' => DB::table('usuarios')->where('id', $c->id)->value('email'), 'tipo' => 'rectificacion',
            'detalle' => 'Me cambié de casa, por favor corrijan mi dirección.', 'estado' => 'pendiente',
            'created_at' => $this->utc($hoy->subDays(1))]);
        $this->resumen['Contacto y solicitudes'] = '3 mensajes (1 leído) y 1 solicitud de datos pendiente';
    }

    // ── Utilidades ───────────────────────────────────────────────────────────

    /** Llama a un controlador como lo haría el panel, y falla con su mensaje si responde error. */
    private function llamar(object $controlador, string $metodo, array $datos = [], array $args = []): array
    {
        $req = Request::create('/demo', 'POST', $datos);
        $req->attributes->set('usuario', $this->firma);
        $res = $controlador->{$metodo}($req, ...$args);
        if ($res instanceof JsonResponse) {
            $data = $res->getData(true);
            if ($res->getStatusCode() >= 400) {
                throw new \RuntimeException(class_basename($controlador) . "::{$metodo} respondió "
                    . $res->getStatusCode() . ': ' . ($data['detail'] ?? $res->getContent()));
            }
            return is_array($data) ? $data : [];
        }
        return is_array($res) ? $res : (array) $res;
    }

    private function reloj(CarbonImmutable $momento): void
    {
        Carbon::setTestNow($momento);
        CarbonImmutable::setTestNow($momento);
    }

    private function especialistaPara(object $svc, $especialistas, $quienAtiende): object
    {
        $ids = ($quienAtiende[$svc->id] ?? collect())->pluck('especialista_id')->all();
        $candidatos = $ids ? $especialistas->whereIn('id', $ids)->values() : $especialistas;
        return $candidatos->isEmpty() ? $especialistas->first() : $candidatos[mt_rand(0, $candidatos->count() - 1)];
    }

    private function entre(CarbonImmutable $desde, CarbonImmutable $hasta): CarbonImmutable
    {
        if ($hasta->lessThanOrEqualTo($desde)) {
            return $desde;
        }
        return $desde->addDays(mt_rand(0, Fechas::diasEntre($desde, $hasta)));
    }

    private function elegir(array $lista)
    {
        return $lista[mt_rand(0, count($lista) - 1)];
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    private function utc(CarbonImmutable $momento): string
    {
        return $momento->setTimezone('UTC')->format('Y-m-d H:i:s.u');
    }

    public static function telefono(int $n): string
    {
        return self::PREFIJO_TELEFONO . '0' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    /** RUT con dígito verificador correcto, en un rango que hoy no tiene personas. */
    public static function rut(int $numero): string
    {
        $suma = 0;
        $factor = 2;
        for ($n = $numero; $n > 0; $n = intdiv($n, 10)) {
            $suma += ($n % 10) * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }
        $dv = 11 - ($suma % 11);
        $dv = $dv === 11 ? '0' : ($dv === 10 ? 'K' : (string) $dv);
        return number_format($numero, 0, ',', '.') . '-' . $dv;
    }
}
