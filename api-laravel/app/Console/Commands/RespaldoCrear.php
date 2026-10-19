<?php

namespace App\Console\Commands;

use App\Services\ArchivosDeFichas;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Respaldo diario: la base MÁS los dos directorios de archivos.
 *
 * POR QUÉ EXISTE, Y POR QUÉ NO ES `deploy/respaldo.sh`
 *
 * En Railway los respaldos los hacía el proveedor. Al salir de Railway esa red
 * desaparece y pasa a ser responsabilidad nuestra — es la condición que
 * `FASES.md` §6 le pone al apagado: «recién cuando el respaldo esté probado con
 * una restauración de verdad, no solo con un archivo generado».
 *
 * `deploy/respaldo.sh` tiene la lógica escrita pero es inservible acá: hace
 * `docker exec emr_postgres pg_dump` y monta volúmenes con `docker run`. En
 * el Hostinger compartido no hay Docker, no hay Postgres, y sobre todo **no hay
 * shell**: `exec`, `shell_exec` y `proc_open` están deshabilitadas, también en
 * la CLI (Fase 0). No se puede invocar `mysqldump` ni `tar`. Todo lo hace PHP.
 *
 * LAS TRES COSAS QUE RESPALDA, Y POR QUÉ LAS TRES
 *
 *   1. La base entera, como SQL restaurable.
 *   2. `uploads/` — fotos de especialistas.
 *   3. `documentos_clinicos/` — exámenes y radiografías de las fichas.
 *
 * Los dos directorios no son un extra. **La base no guarda las imágenes:**
 * `especialistas.foto` y `documentos_clinicos.nombre_archivo` guardan un
 * NOMBRE, y el contenido vive en el sistema de archivos. Se puede restaurar la
 * base con cero diferencias y dejar todas las fichas con los adjuntos rotos.
 * Ya pasó el 31-ago-2026: un despliegue se llevó las 7 fotos de los
 * especialistas y se recuperaron desde una copia local que existía por
 * casualidad, no por respaldo.
 *
 * DÓNDE ATERRIZA, QUE ES LA PARTE QUE MÁS FÁCIL SE HACE MAL
 *
 * **Nunca bajo `public_html`.** Un respaldo en el directorio servido por la web
 * es la base de datos de la clínica a un GET de distancia — y no es hipotético:
 * el 05-sep un zip de la aplicación quedó descargable un minuto por ese mismo
 * error. El destino sale de `RESPALDO_DIR` y el comando **se niega a escribir**
 * si detecta que cae dentro del document root.
 *
 * EL TOPE DE 360 SEGUNDOS
 *
 * `max_execution_time` no pasa de 360 s en este plan. Con los volúmenes de hoy
 * el respaldo cabe de sobra, pero eso es una casualidad del tamaño actual y no
 * una garantía: el comando lleva su propio presupuesto de tiempo y aborta
 * ordenado —dejando dicho por dónde iba— en vez de que PHP lo mate a mitad de
 * un archivo y deje un `.sql` truncado con pinta de estar completo.
 *
 *     php artisan respaldo:crear
 *     php artisan respaldo:crear --solo-base
 */
class RespaldoCrear extends TareaDeFondo
{
    protected $signature = 'respaldo:crear
                            {--solo-base : Omite los archivos, solo la base}
                            {--retencion=14 : Días de copias a conservar}';

    protected $description = 'Respalda la base y los archivos subidos';

    /** Filas por lote al volcar. Acota la memoria sin pagar una consulta por fila. */
    private const LOTE = 500;

    /** Margen sobre `max_execution_time`: se aborta ordenado antes de que PHP mate. */
    private const SEGUNDOS_PRESUPUESTO = 300;

    private float $inicio;

    protected function ejecutar(): string
    {
        $this->inicio = microtime(true);
        $destino = $this->destino();
        $sello = date('Ymd_His');
        $partes = [];

        // ── 1. La base ────────────────────────────────────────────────────
        $sql = "{$destino}/respaldo_db_{$sello}.sql";
        [$tablas, $filas] = $this->volcarBase($sql);
        $partes[] = sprintf('base: %d tablas, %s filas, %s',
            $tablas, number_format($filas, 0, ',', '.'), $this->peso($sql));

        // ── 2. Los archivos ───────────────────────────────────────────────
        if (! $this->option('solo-base')) {
            foreach ([
                'uploads' => config('emr.uploads_dir'),
                'documentos' => config('emr.documentos_dir'),
            ] as $nombre => $dir) {
                $zip = "{$destino}/respaldo_{$nombre}_{$sello}.zip";
                $n = $this->empaquetar($dir, $zip);
                $partes[] = "{$nombre}: {$n} archivos, " . $this->peso($zip);
            }
        }

        // ── 3. La verificación, que es lo que lo hace un respaldo ─────────
        $faltan = ArchivosDeFichas::faltantes();
        if ($faltan !== []) {
            // No se aborta: el respaldo que ya se generó sirve igual, y ocultar
            // el problema sería peor. Pero se DICE, y el comando sale con
            // fallo para que el cron lo reporte.
            $lista = implode(', ', array_slice($faltan, 0, 5));
            throw new \RuntimeException(
                sprintf('Respaldo generado, PERO %d fila(s) apuntan a archivos que no existen '
                    . '(%s%s). Restaurar esto dejaría fichas con adjuntos rotos.',
                    count($faltan), $lista, count($faltan) > 5 ? '…' : ''));
        }

        $partes[] = $this->purgar($destino, (int) $this->option('retencion'));
        $partes[] = sprintf('%.1fs', microtime(true) - $this->inicio);

        return 'Respaldo ' . $sello . ' — ' . implode(' · ', $partes);
    }

    /**
     * Dónde se escribe, con la comprobación que impide publicarlo sin querer.
     */
    private function destino(): string
    {
        $dir = env('RESPALDO_DIR') ?: self::porDefecto(base_path());

        if ($motivo = self::motivoParaRechazar($dir, public_path())) {
            throw new \RuntimeException(
                "El destino del respaldo ({$dir}) {$motivo} "
                . 'Ahí el respaldo es la base de la clínica a un GET de distancia. '
                . 'Ponlo en RESPALDO_DIR, fuera de public_html.');
        }

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException("No se pudo crear {$dir}");
        }
        return $dir;
    }

    /**
     * El destino por defecto: el primer directorio POR ENCIMA de `public_html`.
     *
     * 🔴 EL DEFECTO QUE ESTO ARREGLA — encontrado el 07-sep-2026, sin desplegar
     *
     * Acá decía `dirname(base_path()) . '/respaldos'`. En este plan
     * `base_path()` es `…/public_html/lab`, así que ese `dirname` es
     * **`public_html`** — el document root del dominio padre. El respaldo
     * entero de la clínica habría aterrizado en
     * `ejemplo.cl/respaldos/respaldo_db_<sello>.sql`.
     *
     * Y la comprobación de al lado NO lo veía: miraba solo `public_path()`,
     * que es `public_html/lab/public`, el document root del SUBdominio.
     * `public_html/respaldos` no cae ahí adentro, así que pasaba limpio.
     *
     * MEDIDO, no deducido. Contra el servidor:
     *
     *   - `/assets/index-7KJr9Wvc.js` → 200 `application/x-javascript`: un
     *     archivo que existe bajo `public_html` se entrega crudo.
     *   - `/respaldos/respaldo_db_x.sql` → 200 pero `text/html`, y el cuerpo es el
     *     `index.html` del sitio: eso es el fallback de la SPA porque el
     *     archivo NO existe. El día que exista, se entrega.
     *
     * O sea que no había directory listing que lo delatara, y el nombre lleva
     * un sello de tiempo — pero el cron corre a una hora fija, así que adivinar
     * `respaldo_db_20260907_0300<ss>.sql` son unas decenas de intentos. Con
     * `--retencion=14` habría catorce copias esperando ahí. Adentro va el RUT,
     * la dirección y el teléfono de ~2.700 tutores.
     *
     * Los 0600 del volcado no protegen de esto: en un hosting compartido el
     * servidor web corre como el mismo usuario, así que el modo del archivo lo
     * defiende de los VECINOS, no de un GET.
     *
     * Es el §13.7 otra vez —el zip que quedó descargable un minuto— y el
     * incidente del 06-sep —la aplicación del laboratorio alcanzable por el
     * dominio principal—: en este plan **todo cuelga de `public_html`**, y una
     * ruta «al lado de la app» está publicada salvo que se demuestre lo
     * contrario.
     */
    private static function porDefecto(string $base): string
    {
        $base = rtrim(str_replace('\\', '/', $base), '/');
        // Se corta en el PRIMER `public_html` y se sube por encima. Si no hay
        // ninguno (desarrollo local), el hermano de la app sirve igual.
        if (preg_match('#^(.*?)/public_html(?:/|$)#', $base, $m)) {
            return $m[1] . '/respaldos';
        }
        return dirname($base) . '/respaldos';
    }

    /**
     * Por qué NO se puede escribir ahí, o `null` si se puede.
     *
     * Dos reglas, y la segunda es a propósito más ancha que el defecto (§13.8):
     * cualquier ruta con un tramo `public_html` queda fuera, sin discutir caso
     * por caso. En este hosting no tiene falsos negativos —`public_html` ES la
     * raíz servida— y una regla que exige criterio en cada revisión deja de
     * aplicarse. La primera sigue existiendo porque cubre el document root del
     * subdominio aunque algún día no cuelgue de `public_html`.
     */
    private static function motivoParaRechazar(string $dir, string $publico): ?string
    {
        $normal = rtrim(str_replace('\\', '/', $dir), '/');

        $raiz = rtrim(str_replace('\\', '/', realpath($publico) ?: $publico), '/');
        // La barra al final importa: sin ella `…/public-respaldos` —que está
        // FUERA— se rechazaría por empezar igual que `…/public`. Un falso
        // positivo acá es lo que empuja a alguien a desactivar la comprobación.
        if (str_starts_with($normal . '/', $raiz . '/')) {
            return 'cae dentro del directorio servido por el subdominio.';
        }

        if (preg_match('#(^|/)public_html(/|$)#', $normal)) {
            return 'cuelga de public_html, que es la raíz servida por el dominio.';
        }

        return null;
    }

    /**
     * Vuelca la base a SQL, tabla por tabla y en lotes.
     *
     * `FOREIGN_KEY_CHECKS=0` al principio y 1 al final: son 47 claves foráneas
     * y ordenarlas topológicamente para insertar sin violarlas es un problema
     * que `mysqldump` resuelve así desde siempre. Con el orden alfabético y las
     * comprobaciones apagadas, la restauración no depende de acertar el orden.
     */
    private function volcarBase(string $ruta): array
    {
        // Se escribe en `.parcial` y se renombra SOLO al terminar entero. Antes
        // se escribía directo en el nombre final: un corte a mitad (el límite
        // de 300 s del plan, un error de la base) dejaba un `respaldo_db_*.sql`
        // truncado con cara de respaldo bueno, y `respaldo:restaurar` lo habría
        // aplicado sin quejarse — media base restaurada en silencio.
        $parcial = $ruta . '.parcial';
        try {
            $resultado = $this->volcarEn($parcial);
        } catch (\Throwable $e) {
            @unlink($parcial);
            throw $e;
        }
        if (! rename($parcial, $ruta)) {
            @unlink($parcial);
            throw new \RuntimeException("No se pudo dejar el respaldo en {$ruta}");
        }
        return $resultado;
    }

    private function volcarEn(string $ruta): array
    {
        $fh = fopen($ruta, 'w');
        if ($fh === false) {
            throw new \RuntimeException("No se pudo escribir {$ruta}");
        }
        // 0600: el respaldo tiene RUT, direcciones y fichas clínicas. En un
        // hosting compartido el modo del archivo es parte de la protección.
        @chmod($ruta, 0600);

        $pdo = DB::connection()->getPdo();
        $base = DB::connection()->getDatabaseName();

        fwrite($fh, "-- Respaldo de {$base} — " . date('c') . "\n");
        fwrite($fh, "-- Generado por `php artisan respaldo:crear`. Restaurar con `respaldo:restaurar`.\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tablas = array_map(
            fn ($f) => array_values((array) $f)[0],
            DB::select('SHOW TABLES'));
        sort($tablas);

        $totalFilas = 0;
        foreach ($tablas as $tabla) {
            $this->presupuesto("volcando {$tabla}");

            $crear = (array) DB::selectOne("SHOW CREATE TABLE `{$tabla}`");
            fwrite($fh, "\nDROP TABLE IF EXISTS `{$tabla}`;\n"
                . array_values($crear)[1] . ";\n");

            $n = 0;
            $offset = 0;
            while (true) {
                // Ordenado por la PK para que el volcado sea reproducible: dos
                // respaldos de la misma base tienen que dar el mismo archivo,
                // o comparar dos copias no dice nada. (§9.14, otra vez.)
                $filas = DB::select(
                    "SELECT * FROM `{$tabla}` ORDER BY 1 LIMIT " . self::LOTE . " OFFSET {$offset}");
                if ($filas === []) {
                    break;
                }
                foreach ($filas as $fila) {
                    $vals = array_map(
                        fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                        (array) $fila);
                    $cols = implode('`,`', array_keys((array) $fila));
                    fwrite($fh, "INSERT INTO `{$tabla}` (`{$cols}`) VALUES ("
                        . implode(',', $vals) . ");\n");
                }
                $n += count($filas);
                $offset += self::LOTE;
                $this->presupuesto("volcando {$tabla} ({$n} filas)");
            }
            $totalFilas += $n;
        }

        fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        return [count($tablas), $totalFilas];
    }

    /** Empaqueta un directorio con ZipArchive: no hay `tar` que invocar. */
    private function empaquetar(string $dir, string $ruta): int
    {
        $zip = new ZipArchive();
        if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("No se pudo crear {$ruta}");
        }
        $n = 0;
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $nombre) {
                if ($nombre === '.' || $nombre === '..') {
                    continue;
                }
                $completo = $dir . '/' . $nombre;
                if (is_file($completo)) {
                    $zip->addFile($completo, $nombre);
                    $n++;
                }
                $this->presupuesto("empaquetando " . basename($dir));
            }
        }
        $zip->close();
        @chmod($ruta, 0600);
        return $n;
    }

    /** Borra lo más viejo que la retención. Nunca la copia de hoy. */
    private function purgar(string $destino, int $dias): string
    {
        if ($dias <= 0) {
            return 'sin purga';
        }
        $limite = time() - $dias * 86400;
        $borrados = 0;
        foreach (glob("{$destino}/respaldo_*") ?: [] as $archivo) {
            if (filemtime($archivo) < $limite) {
                @unlink($archivo);
                $borrados++;
            }
        }
        return "purga: {$borrados} archivo(s) de más de {$dias} días";
    }

    /**
     * Aborta ordenado antes de que PHP mate el proceso.
     *
     * Sin esto, pasarse del tope deja un `.sql` a medias que **parece
     * completo**: no hay error, no hay marca, y el día que alguien lo restaure
     * descubre que la base termina a mitad de una tabla. Un respaldo truncado
     * en silencio es peor que ninguno, porque además da tranquilidad.
     */
    private function presupuesto(string $donde): void
    {
        if (microtime(true) - $this->inicio > self::SEGUNDOS_PRESUPUESTO) {
            throw new \RuntimeException(
                sprintf('Se agotó el presupuesto de %ds %s. El respaldo quedó INCOMPLETO: '
                    . 'no usarlo. Correr con --solo-base, o partir el volcado.',
                    self::SEGUNDOS_PRESUPUESTO, $donde));
        }
    }

    private function peso(string $ruta): string
    {
        $b = @filesize($ruta) ?: 0;
        return $b > 1048576
            ? round($b / 1048576, 1) . ' MB'
            : round($b / 1024) . ' KB';
    }
}
