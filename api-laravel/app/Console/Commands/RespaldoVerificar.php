<?php

namespace App\Console\Commands;

use App\Services\ArchivosDeFichas;
use Illuminate\Console\Command;

/**
 * Comprueba que cada fila que apunta a un archivo tenga su archivo.
 *
 * Se corre solo después de restaurar (`respaldo:restaurar` lo invoca), y a mano
 * cuando se quiera confirmar que el sistema está sano:
 *
 *     php artisan respaldo:verificar
 *
 * Sale con fallo si falta algo, para que el cron o el guion de restauración se
 * enteren. Una restauración que aplicó todas las sentencias sin error y dejó
 * las radiografías fuera **no está completa**, y nada más lo diría.
 */
class RespaldoVerificar extends Command
{
    protected $signature = 'respaldo:verificar';

    protected $description = 'Comprueba que los archivos de las fichas estén todos';

    public function handle(): int
    {
        $faltan = ArchivosDeFichas::faltantes();

        if ($faltan === []) {
            $this->info('Todo en su sitio: cada fila tiene su archivo.');
            return self::SUCCESS;
        }

        $this->error(count($faltan) . ' fila(s) apuntan a archivos que no existen:');
        foreach (array_slice($faltan, 0, 20) as $f) {
            $this->line('  · ' . $f);
        }
        if (count($faltan) > 20) {
            $this->line('  … y ' . (count($faltan) - 20) . ' más.');
        }
        return self::FAILURE;
    }
}
