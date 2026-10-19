<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Respaldo de datos de la clínica: un ZIP con el volcado SQL y un CSV por tabla.
 *
 * **En PHP puro, y no es una preferencia.** La versión de Python invoca
 * `pg_dump` y `psql` por `subprocess`; acá `exec`, `shell_exec` y `proc_open`
 * están deshabilitadas en el hosting compartido —comprobado en la Fase 1, y
 * también en la CLI— así que no hay forma de llamar a `mysqldump` ni aunque
 * estuviera instalado. Se genera el SQL leyendo las tablas.
 *
 * **Por qué importa más que antes.** En Railway el respaldo lo hacía la
 * plataforma. En hosting compartido pasa a ser responsabilidad nuestra (§11), y
 * este endpoint es la única vía por la que la clínica puede llevarse sus datos
 * sin depender de nadie. Sin él, el corte deja «Respaldo de datos» del panel
 * apuntando a una ruta que no existe.
 *
 * ⚠️ **El respaldo de la base NO alcanza.** Las fotos de especialistas
 * (`uploads/`) y los documentos clínicos (`documentos_clinicos/`) viven en el
 * sistema de archivos: un volcado de la base solo deja las fichas con adjuntos
 * rotos. Es lo mismo que explica la cabecera de `deploy/respaldo.sh`.
 */
class ExportacionController extends Controller
{
    /**
     * Las tablas de negocio que van como CSV.
     *
     * Se excluyen las puramente técnicas —`solicitudes_reset`,
     * `google_accounts`— que no tienen valor como respaldo de los datos de la
     * clínica. `google_accounts` además guarda tokens cifrados: no hay ninguna
     * razón para que viajen en un archivo que alguien se lleva.
     */
    private const TABLAS_CSV = [
        'usuarios', 'mascotas', 'fichas_clinicas', 'notas_clinicas',
        'documentos_clinicos', 'citas', 'especialistas', 'servicios',
        'items_inventario', 'transacciones', 'ventas',
    ];

    /** Filas por lote al volcar. Ni una consulta gigante ni una por fila. */
    private const LOTE = 500;

    public function __construct(private Auditoria $auditoria) {}

    public function completa(Request $request)
    {
        // Se registra ANTES de generar nada, como en `exportacion.py`: es la
        // operación más sensible del sistema (se lleva la base entera, con RUT,
        // dirección y teléfono de cada tutor), y si falla a la mitad igual tiene
        // que quedar escrito que alguien lo intentó — es el registro que hace
        // falta para investigar una fuga. El port lo había perdido.
        $this->auditoria->registrar($request->attributes->get('usuario'),
            'exportar', 'auditoria', 'base_completa', null, ['tablas_csv' => count(self::TABLAS_CSV)]);

        $tmp = tempnam(sys_get_temp_dir(), 'exp');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            return response()->json(
                ['detail' => 'No se pudo preparar el archivo de exportación.'], 500);
        }

        $zip->addFromString('dump_completo.sql', $this->volcado());

        foreach (self::TABLAS_CSV as $tabla) {
            try {
                $zip->addFromString("csv/{$tabla}.csv", $this->csv($tabla));
            } catch (\Throwable $e) {
                // Una tabla que falla no puede tumbar el ZIP entero: el resto
                // del respaldo sigue siendo válido y útil. Mismo criterio que
                // Python, que deja un `.error.txt` en su lugar.
                $zip->addFromString("csv/{$tabla}.error.txt",
                    mb_substr($e->getMessage(), 0, 500));
            }
        }

        $zip->close();
        $nombre = 'export_' . CarbonImmutable::now()->format('Ymd_His') . '.zip';

        return response()->download($tmp, $nombre, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Los datasets de la pantalla de respaldo de ACVet (captura 8 del dossier):
     * cada uno se baja por separado, en CSV, y por PÁGINAS. Las páginas no son
     * estética: el hosting compartido corta un request largo, y 15.410 ventas
     * de una vez no caben en ese tiempo. El panel pide página tras página,
     * muestra el avance («Exportando Ventas… página 3 de 31») y arma el
     * archivo en el navegador.
     *
     * `tabla`, `fecha` (para «últimos 2 años») y, si hace falta, un filtro fijo.
     * Ventas y transacciones llevan el tope de 2 años de ACVet; lo demás va
     * entero.
     */
    private const DATASETS = [
        'propietarios' => ['titulo' => 'Propietarios', 'tabla' => 'usuarios', 'donde' => ['rol', 'dueño'],
            'detalle' => 'Datos de contacto y perfil de todos los propietarios registrados'],
        'mascotas' => ['titulo' => 'Mascotas', 'tabla' => 'mascotas',
            'detalle' => 'Información completa de todas las mascotas y sus características'],
        'casos_clinicos' => ['titulo' => 'Casos clínicos', 'tabla' => 'notas_clinicas',
            'detalle' => 'Historial médico: las notas clínicas de todas las mascotas'],
        'agenda' => ['titulo' => 'Agenda', 'tabla' => 'citas',
            'detalle' => 'Citas reservadas, con su estado y el formulario de reserva'],
        'inventario' => ['titulo' => 'Inventario', 'tabla' => 'items_inventario',
            'detalle' => 'Catálogo de productos y stock disponible'],
        'ventas' => ['titulo' => 'Ventas', 'tabla' => 'ventas', 'fecha' => 'fecha',
            'detalle' => 'Líneas de venta de los últimos 2 años'],
        'transacciones' => ['titulo' => 'Transacciones', 'tabla' => 'transacciones', 'fecha' => 'fecha',
            'detalle' => 'Cobros (cabecera de cada venta) de los últimos 2 años'],
        'preventiva' => ['titulo' => 'Preventiva', 'tabla' => 'protocolo_aplicaciones',
            'detalle' => 'Vacunas, desparasitaciones y dosis preventivas aplicadas'],
        'recordatorios' => ['titulo' => 'Recordatorios', 'tabla' => 'recordatorios',
            'detalle' => 'Recordatorios clínicos programados y su estado de aviso'],
    ];

    /** Filas por página de dataset: de a 1.000, bajo el corte de tiempo del hosting. */
    private const POR_PAGINA = 1000;

    /**
     * Columnas que NUNCA salen en un dataset, aunque estén en la tabla: un hash
     * de contraseña o un token no le sirven a la clínica en una planilla y sí a
     * quien se la lleve (mismo criterio que `/auth/mis-datos`, §14).
     */
    private const COLUMNA_SECRETA = '/password|token|secret|hash/i';

    /** GET /exportacion/datasets — la lista con cuántas filas trae cada uno. */
    public function datasets()
    {
        $out = [];
        foreach (self::DATASETS as $clave => $d) {
            try {
                $total = $this->consultaDataset($d)->count();
            } catch (\Throwable) {
                $total = null;   // la tabla no existe en esta base: se muestra como no disponible
            }
            $out[] = ['clave' => $clave, 'titulo' => $d['titulo'], 'detalle' => $d['detalle'], 'total' => $total,
                'paginas' => $total === null ? 0 : max(1, (int) ceil($total / self::POR_PAGINA))];
        }

        return $out;
    }

    /**
     * GET /exportacion/dataset/{clave}?pagina=N — una página en CSV. La página
     * 1 trae la cabecera; las demás, solo filas, para concatenarlas tal cual.
     * La auditoría se registra en la página 1, antes de leer nada.
     */
    public function dataset(Request $request, string $clave)
    {
        $d = self::DATASETS[$clave] ?? null;
        if (! $d) {
            return response()->json(['detail' => 'Ese dataset no existe.'], 404);
        }
        $pagina = max(1, (int) $request->query('pagina', 1));
        if ($pagina === 1) {
            $this->auditoria->registrar($request->attributes->get('usuario'),
                'exportar', 'auditoria', 'dataset', $clave, ['tabla' => $d['tabla']]);
        }

        $cols = array_values(array_filter(DB::getSchemaBuilder()->getColumnListing($d['tabla']),
            fn ($c) => ! preg_match(self::COLUMNA_SECRETA, $c)));
        $q = $this->consultaDataset($d);
        $total = (clone $q)->count();
        $filas = $q->select($cols)->orderBy('id')
            ->offset(($pagina - 1) * self::POR_PAGINA)->limit(self::POR_PAGINA)->get();

        $salida = fopen('php://temp', 'r+');
        if ($pagina === 1) {
            fputcsv($salida, $cols);
        }
        foreach ($filas as $f) {
            fputcsv($salida, array_map(fn ($c) => $f->$c, $cols));
        }
        rewind($salida);
        $csv = stream_get_contents($salida);
        fclose($salida);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Total-Filas' => (string) $total,
            'X-Total-Paginas' => (string) max(1, (int) ceil($total / self::POR_PAGINA)),
            'Access-Control-Expose-Headers' => 'X-Total-Filas, X-Total-Paginas',
        ]);
    }

    private function consultaDataset(array $d)
    {
        $q = DB::table($d['tabla']);
        if (isset($d['donde'])) {
            $q->where($d['donde'][0], $d['donde'][1]);
        }
        if (isset($d['fecha'])) {
            $q->where($d['fecha'], '>=', CarbonImmutable::now()->subYears(2)->format('Y-m-d 00:00:00'));
        }

        return $q;
    }

    /**
     * El volcado SQL, restaurable con `mysql < dump_completo.sql`.
     *
     * Lleva `SET FOREIGN_KEY_CHECKS = 0` porque las tablas se vuelcan en orden
     * alfabético y no topológico: sin eso, restaurar falla en la primera fila
     * que apunte a una tabla que todavía no se cargó. Con las 47 claves
     * foráneas puestas eso es casi la primera.
     */
    private function volcado(): string
    {
        $sql = "-- Respaldo de " . \App\Services\Marca::nombre() . "\n"
             . '-- Generado el ' . CarbonImmutable::now()->format('Y-m-d H:i:s') . "\n"
             . "-- Restaurar con:  mysql -u USUARIO -p BASE < dump_completo.sql\n\n"
             . "SET FOREIGN_KEY_CHECKS = 0;\n"
             . "SET NAMES utf8mb4;\n\n";

        foreach ($this->tablas() as $tabla) {
            $sql .= "-- ── {$tabla} ──\n";
            foreach ($this->filas($tabla) as $fila) {
                $sql .= $this->insert($tabla, (array) $fila);
            }
            $sql .= "\n";
        }

        return $sql . "SET FOREIGN_KEY_CHECKS = 1;\n";
    }

    /**
     * Las filas de una tabla, de a lotes y en orden determinista.
     *
     * **No todas las tablas tienen `id`.** `servicio_especialista` es una M:N
     * con clave compuesta, y `lazyById('id')` revienta ahí con un
     * «Unknown column 'id'» que tumba el respaldo entero — pasó al primer
     * intento. Para esas se ordena por todas sus columnas: es igual de
     * determinista y no supone una PK que no existe.
     */
    private function filas(string $tabla): iterable
    {
        $cols = DB::getSchemaBuilder()->getColumnListing($tabla);

        if (in_array('id', $cols, true)) {
            // `lazyById` no carga la tabla entera en memoria, que es lo que
            // importa con 15.410 ventas y un `memory_limit` compartido.
            return DB::table($tabla)->orderBy('id')->lazyById(self::LOTE, 'id');
        }

        $q = DB::table($tabla);
        foreach ($cols as $c) {
            $q->orderBy($c);
        }
        return $q->lazy(self::LOTE);
    }

    /** Todas las tablas de la base, en orden estable. */
    private function tablas(): array
    {
        $base = DB::getDatabaseName();
        return array_map(fn ($r) => $r->TABLE_NAME ?? $r->table_name,
            DB::select(
                'SELECT TABLE_NAME FROM information_schema.tables '
                . 'WHERE table_schema = ? AND table_type = ? ORDER BY TABLE_NAME',
                [$base, 'BASE TABLE']));
    }

    private function insert(string $tabla, array $fila): string
    {
        $cols = implode('`, `', array_keys($fila));
        $vals = implode(', ', array_map(fn ($v) => $this->literal($v), $fila));

        return "INSERT INTO `{$tabla}` (`{$cols}`) VALUES ({$vals});\n";
    }

    /**
     * Un valor como literal SQL.
     *
     * Se escapa con el PDO de la conexión y no a mano: un apellido con apóstrofo
     * —«O'Brien»— o una nota clínica con comillas rompen un volcado escrito con
     * concatenación, y el archivo solo falla al RESTAURARLO, que es el peor
     * momento para descubrirlo.
     */
    private function literal($v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        return DB::connection()->getPdo()->quote((string) $v);
    }

    /** Una tabla como CSV, con encabezado. */
    private function csv(string $tabla): string
    {
        $salida = fopen('php://temp', 'r+');
        $primera = true;

        foreach ($this->filas($tabla) as $fila) {
            $fila = (array) $fila;
            if ($primera) {
                fputcsv($salida, array_keys($fila));
                $primera = false;
            }
            // Los booleanos como 1/0 y no como '' / '1', que es lo que sale por
            // defecto y hace ilegible la columna en una planilla.
            fputcsv($salida, array_map(
                fn ($v) => is_bool($v) ? (int) $v : $v, array_values($fila)));
        }

        if ($primera) {
            // Tabla vacía: igual va con sus encabezados, para que se note que
            // se exportó y está vacía y no que se olvidó.
            $cols = DB::getSchemaBuilder()->getColumnListing($tabla);
            fputcsv($salida, $cols);
        }

        rewind($salida);
        $csv = stream_get_contents($salida);
        fclose($salida);

        return $csv;
    }
}
