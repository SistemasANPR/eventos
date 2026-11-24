<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Privacy\ExportAnonymizationLog;
use Illuminate\Console\Command;

/**
 * Guarda el registro de anonimizaciones antes de restaurar un respaldo (plan 014, DA-5, `docs/operacion.md` §5).
 * El archivo solo tiene huellas no reversibles, fechas e identificadores de organización, pero es un archivo de
 * plataforma: se crea con permisos solo para su dueño, nunca dentro de `public/`, y se borra al terminar.
 */
final class ExportAnonymizationLogCommand extends Command
{
    protected $signature = 'app:export-anonymization-log {path : Archivo JSON de destino, fuera de public/}';

    protected $description = 'Guarda el registro de anonimizaciones de todas las organizaciones para conservarlo al restaurar un respaldo';

    public function handle(ExportAnonymizationLog $export): int
    {
        $path = (string) $this->argument('path');
        $directory = realpath(dirname($path));

        if ($directory === false || ! is_dir($directory) || ! is_writable($directory)) {
            $this->error('La carpeta de destino no existe o no se puede escribir.');

            return self::FAILURE;
        }

        if (str_starts_with($directory.DIRECTORY_SEPARATOR, (string) realpath(public_path()).DIRECTORY_SEPARATOR)) {
            $this->error('El archivo no puede guardarse dentro de public/.');

            return self::FAILURE;
        }

        $payload = $export->handle();
        $target = $directory.DIRECTORY_SEPARATOR.basename($path);

        if (file_put_contents($target, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false) {
            $this->error('No se pudo escribir el archivo.');

            return self::FAILURE;
        }

        chmod($target, 0600);
        $this->info('Huellas guardadas: '.count($payload['entries']).'. Borra el archivo cuando termine la restauracion.');

        return self::SUCCESS;
    }
}
