<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Resources\AssignedSubmissions\Pages;

use App\Filament\Reviewer\Resources\AssignedSubmissions\AssignedSubmissionResource;
use App\Filament\Support\ActionErrors;
use App\Support\Reviews\ReviewerSummary;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Lista de las propuestas asignadas al evaluador (plan 011, CA-011-51 y 52). Con algún periodo cerrado
 * avisa en el encabezado que ya no se puede calificar.
 */
class ListAssignedSubmissions extends ListRecords
{
    protected static string $resource = AssignedSubmissionResource::class;

    #[Unexposed]
    public function getSubheading(): string|Htmlable|null
    {
        $closed = array_filter(ReviewerSummary::periods(ActionErrors::actor()), static fn (array $period): bool => ! $period['open']);

        return $closed === [] ? null : __('reviewer.list.period_closed_notice', ['events' => implode(', ', array_column($closed, 'name'))]);
    }
}
