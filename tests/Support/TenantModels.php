<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Descubre los modelos de `app/Models` y los clasifica según el aislamiento
 * entre organizaciones (ADR-0003).
 *
 * Los datasets de Pest se evalúan antes de arrancar la aplicación, por eso la
 * ruta se calcula a partir de este archivo y no con `app_path()`.
 */
final class TenantModels
{
    /**
     * Modelos que NO pertenecen a una organización (datos globales de la plataforma).
     * Añadir uno aquí es una decisión de seguridad: justificarlo en el pull request.
     *
     * @var list<class-string<Model>>
     */
    public const GLOBAL_MODELS = [
        Organization::class,
        User::class,
    ];

    /** @return list<class-string<Model>> */
    public static function all(): array
    {
        $directory = dirname(__DIR__, 2).'/app/Models';
        if (! is_dir($directory)) {
            return [];
        }

        $models = [];
        $finder = (new Finder)->files()->in($directory)->name('*.php')->notPath('Concerns');

        foreach ($finder as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $class = 'App\\Models\\'.$relative;

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    }

    /** @return list<class-string<Model>> */
    public static function scoped(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (string $class): bool => in_array(BelongsToOrganization::class, class_uses_recursive($class), true),
        ));
    }

    /**
     * @param  list<class-string<Model>>  $classes
     * @return array<class-string<Model>, array{0: class-string<Model>}>
     */
    public static function dataset(array $classes): array
    {
        return array_combine($classes, array_map(static fn (string $class): array => [$class], $classes));
    }
}
