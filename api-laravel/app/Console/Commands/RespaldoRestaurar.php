<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

/**
 * Restaura un respaldo. La mitad que normalmente falta.
 *
 * POR QUÉ ESTE COMANDO ES EL QUE IMPORTA
 *
 * Generar un archivo es la parte fácil y la que da tranquilidad falsa.
 * `FASES.md` §6 condiciona el apagado de Railway a que el respaldo esté
 * «probado con una restauración de verdad, no solo con un archivo generado», y
 * eso es exactamente lo que este comando hace posible: un respaldo que nadie
 * restauró nunca es una hipótesis.
 *
 * LOS DOS CANDADOS, Y POR QUÉ NO SOBRAN
 *
 * Restaurar es DESTRUCTIVO: hace `DROP TABLE` de todo antes de recrear. Un
 * comando así, disponible en la misma CLI donde alguien depura a las once de
 * la noche, necesita más que buenas intenciones.
 *
 *   1. **Se niega a correr contra la base de producción** salvo que se lo
 *      pidan por su nombre exacto, con `--confirmar=<base>`. Es el mismo
 *      criterio que `tests/conftest.py`, que aborta si la base no se llama
 *      `emr_test`, y que `seed_testing_users.py`, que exige host local.
 *      Ninguno de los dos es paranoia: los dos existen porque el error
 *      contrario ya se cometió.
 *   2. **Toma su propio respaldo antes de destruir nada**, salvo que se le
 *      diga que no. Restaurar una copia vieja sobre datos buenos es la forma
 *      más común de perder información con una herramienta de recuperación.
 *
 * QUÉ RESTAURA
 *
 * La base y, si se le pasan, los ZIP de archivos. Los tres van juntos por la
 * misma razón que van juntos al respaldar: la base guarda NOMBRES de archivo,
 * no contenidos, así que restaurar solo la base deja las fichas con los
 * adjuntos rotos y sin ninguna señal de error.
 *
 *     php artisan respaldo:restaurar --sql=/ruta/respaldo_db_20260906_030000.sql \
 *         --uploads=/ruta/respaldo_uploads_20260906_030000.zip \
 *         --documentos=/ruta/respaldo_documentos_20260906_030000.zip \
 *         --confirmar=emr_lab
 */
class RespaldoRestaurar extends Command
{
    protected $signature = 'respaldo:restaurar
                            {--sql= : Archivo .sql a restaurar}
                            {--uploads= : ZIP de fotos de especialistas}
                            {--documentos= : ZIP de documentos clínicos}
                            {--confirmar= : Nombre exacto de la base destino}
                            {--sin-red : No tomar respaldo previo (no recomendado)}';

    protected $description = 'Restaura un respaldo sobre la base actual (DESTRUCTIVO)';

    public function handle(): int
    {
        $sql = $this->option('sql');
        if (! $sql || ! is_file($sql)) {
            $this->error('Falta --sql, o el archivo no existe.');
            return self::FAILURE;
        }

        // Un volcado de `respaldo:crear` termina SIEMPRE en su línea de cierre.
        // Si dice venir de ahí y no la tiene, está truncado: aplicarlo dejaría
        // media base sin ningún aviso. Se mira solo el principio y el final,
        // sin leerlo entero (puede pesar decenas de MB).
        if ($motivo = self::incompleto($sql)) {
            $this->error("No se restaura: {$motivo}");
            return self::FAILURE;
        }

        $base = DB::connection()->getDatabaseName();

        // Candado 1: hay que nombrar la base. Escribirla es el acto que
        // convierte «me equivoqué de terminal» en «sé dónde estoy».
        if ($this->option('confirmar') !== $base) {
            $this->error("Esto va a BORRAR y recrear todas las tablas de «{$base}».");
            $this->line('Si es lo que quieres, repite el comando con:');
            $this->line("    --confirmar={$base}");
            return self::FAILURE;
        }

        // Candado 2: la red debajo. Restaurar una copia vieja sobre datos
        // buenos es la forma más común de perder información con una
        // herramienta de recuperación.
        // Una base SIN tablas no tiene nada que proteger, y es justo el caso de
        // un desastre o de una base nueva. Antes el respaldo previo reventaba
        // ahí («Table … especialistas doesn't exist») y el comando decía «falló,
        // no se restaura nada», empujando a usar --sin-red a ciegas. Medido el
        // 6-oct-2026 restaurando el respaldo de producción en una MariaDB 11.8
        // local vacía. Con UNA sola tabla, la red sigue siendo obligatoria.
        if (! $this->option('sin-red') && Schema::getTables() === []) {
            $this->info("«{$base}» está vacía: no hay nada que respaldar antes, se restaura directo.");
        } elseif (! $this->option('sin-red')) {
            $this->info('Tomando un respaldo de lo que hay AHORA, antes de tocar nada…');
            $codigo = $this->call('respaldo:crear', ['--solo-base' => true]);
            if ($codigo !== self::SUCCESS) {
                $this->error('El respaldo previo falló. No se restaura nada.');
                $this->line('Si de verdad quieres seguir sin red: --sin-red');
                return self::FAILURE;
            }
        }

        $this->info("Restaurando {$sql} sobre «{$base}»…");
        $sentencias = $this->ejecutarSql($sql);
        $this->info("  {$sentencias} sentencias aplicadas.");

        foreach ([
            'uploads' => [$this->option('uploads'), config('emr.uploads_dir')],
            'documentos' => [$this->option('documentos'), config('emr.documentos_dir')],
        ] as $nombre => [$zip, $dir]) {
            if (! $zip) {
                continue;
            }
            $n = $this->desempaquetar($zip, $dir);
            $this->info("  {$nombre}: {$n} archivo(s) restaurados en {$dir}");
        }

        // La comprobación que cierra el círculo: si después de restaurar hay
        // filas apuntando a archivos que no están, la restauración NO quedó
        // completa aunque no haya fallado ninguna sentencia.
        $this->line('');
        $this->info('Comprobando que cada fila tenga su archivo…');
        $codigo = $this->call('respaldo:verificar');

        return $codigo === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    /** Motivo por el que el volcado está incompleto, o null si se ve entero. */
    public static function incompleto(string $ruta): ?string
    {
        $fh = fopen($ruta, 'r');
        $inicio = $fh === false ? '' : (string) fread($fh, 512);
        if ($fh !== false) {
            fseek($fh, max(0, filesize($ruta) - 512));
            $fin = (string) fread($fh, 512);
            fclose($fh);
        }
        if (! str_contains($inicio, 'php artisan respaldo:crear')) {
            return null;   // no es un volcado nuestro: no hay cierre que exigir
        }
        return str_ends_with(rtrim($fin ?? ''), 'SET FOREIGN_KEY_CHECKS=1;')
            ? null
            : 'el archivo está truncado (le falta el cierre que escribe respaldo:crear). '
              . 'Usa el respaldo del día anterior.';
    }

    /**
     * Aplica el .sql sentencia por sentencia.
     *
     * Se lee por líneas y no de un `file_get_contents`: el volcado puede pesar
     * decenas de MB y cargarlo entero a memoria es justamente lo que no se
     * puede hacer en un plan compartido. El separador es `;` a fin de línea,
     * que basta porque el volcado lo genera `respaldo:crear` con un INSERT por
     * línea — no es un parser de SQL general y no pretende serlo.
     */
    private function ejecutarSql(string $ruta): int
    {
        $fh = fopen($ruta, 'r');
        if ($fh === false) {
            throw new \RuntimeException("No se pudo leer {$ruta}");
        }

        $pdo = DB::connection()->getPdo();
        $buffer = '';
        $n = 0;
        while (($linea = fgets($fh)) !== false) {
            $limpia = rtrim($linea);
            if ($limpia === '' || str_starts_with($limpia, '--')) {
                continue;
            }
            $buffer .= $linea;
            if (str_ends_with($limpia, ';')) {
                $pdo->exec($buffer);
                $buffer = '';
                $n++;
            }
        }
        fclose($fh);
        return $n;
    }

    private function desempaquetar(string $ruta, string $dir): int
    {
        if (! is_file($ruta)) {
            throw new \RuntimeException("No existe el ZIP {$ruta}");
        }
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException("No se pudo crear {$dir}");
        }
        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new \RuntimeException("No se pudo abrir {$ruta}");
        }
        $n = $zip->numFiles;
        $zip->extractTo($dir);
        $zip->close();
        return $n;
    }
}
