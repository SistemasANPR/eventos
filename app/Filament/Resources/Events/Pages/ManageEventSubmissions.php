<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Submissions\SubmissionResource;
use BackedEnum;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;

/**
 * Propuestas del evento (plan 020, F2; recurso del plan 009): la lista que antes se abría solo por URL
 * pasa a ser una sección del evento. La tabla viene de `SubmissionResource`, anidado bajo el evento;
 * el evento sale de la ruta, así que un evento de otra organización responde 404. Quién la ve lo
 * decide `SubmissionPolicy`. Solo lectura: sin acciones de cabecera.
 */
class ManageEventSubmissions extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    protected static string $relationship = 'submissions';

    protected static ?string $relatedResource = SubmissionResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    public static function getNavigationLabel(): string
    {
        return __('submission_panel.navigation');
    }

    /** La sección se llama como su entrada de menú, no «Administrar Propuestas Evento». */
    public function getTitle(): string
    {
        return __('submission_panel.navigation');
    }
}
