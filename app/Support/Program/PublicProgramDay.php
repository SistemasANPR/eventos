<?php

declare(strict_types=1);

namespace App\Support\Program;

use App\Models\ActivityCategory;
use App\Models\EventActivity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Resultado de `ListPublicProgramDay`: los días con actividades visibles, el día mostrado, sus
 * actividades (una página) y las categorías que se usan ese día. Son modelos con sus relaciones ya
 * cargadas; a las props se pasan por `PublicActivityData`.
 */
final readonly class PublicProgramDay
{
    /**
     * @param  list<string>  $days  `AAAA-MM-DD`, en orden ascendente
     * @param  LengthAwarePaginator<int, EventActivity>  $activities
     * @param  Collection<int, ActivityCategory>  $categories
     */
    public function __construct(
        public array $days,
        public ?string $day,
        public LengthAwarePaginator $activities,
        public Collection $categories,
    ) {}
}
