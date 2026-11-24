<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Pages;

use App\Filament\Reviewer\Resources\AssignedSubmissions\AssignedSubmissionResource;
use App\Filament\Support\ActionErrors;
use App\Support\Reviews\ReviewerSummary;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Página de inicio del panel del evaluador (plan 011, H-011-3, CA-011-50 y 52): el resumen de SUS
 * asignaciones por estado (nunca el avance de otros evaluadores, CA-011-60) y si el periodo de evaluación
 * de cada uno de sus eventos está abierto. Delgada: los números salen de `ReviewerSummary`.
 */
class ReviewerDashboard extends Dashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('reviewer.dashboard.navigation');
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('reviewer.dashboard.title');
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.reviewer.dashboard')->viewData(fn (): array => $this->viewData()),
        ]);
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        $user = ActionErrors::actor();
        $periods = ReviewerSummary::periods($user);

        return [
            'counts' => ReviewerSummary::counts($user),
            'periods' => $periods,
            'closedPeriods' => array_values(array_filter($periods, static fn (array $period): bool => ! $period['open'])),
            'listUrl' => AssignedSubmissionResource::getUrl('index'),
        ];
    }
}
