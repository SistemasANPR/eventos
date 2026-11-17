<?php

declare(strict_types=1);

namespace Tests\Support;

use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;

/** Ayudas de prueba: lo que una página de lista de Filament expone sobre su tabla. */
final class PlatformTables
{
    /**
     * @param  Testable<Component>  $page
     * @return list<int|string>
     */
    public static function pageOptions(Testable $page): array
    {
        return array_values(self::table($page)->getTable()->getPaginationPageOptions());
    }

    /**
     * Mayor tamaño de página ofrecido (los valores como «all» no son un número).
     *
     * @param  Testable<Component>  $page
     */
    public static function maxPageSize(Testable $page): int
    {
        return (int) max(array_filter(self::pageOptions($page), 'is_int'));
    }

    /**
     * @param  Testable<Component>  $page
     * @return Collection<int|string, mixed>
     */
    public static function records(Testable $page): Collection
    {
        $result = self::table($page)->getTableRecords();

        /** @var Collection<int|string, mixed> $records */
        $records = $result instanceof Paginator ? collect($result->items()) : collect($result);

        return $records;
    }

    /** @param  Testable<Component>  $page */
    private static function table(Testable $page): HasTable
    {
        $instance = $page->instance();

        return $instance instanceof HasTable ? $instance : throw new \LogicException('La página no es una lista de Filament.');
    }
}
