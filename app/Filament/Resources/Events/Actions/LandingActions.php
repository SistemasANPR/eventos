<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Actions;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use Closure;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * «Diseñar landing»: lleva al editor visual de la landing del evento (plan 022, CA-022-34). La misma
 * acción vive en la pantalla del evento, en el menú de su tarjeta y en la fila de cada convocatoria, y
 * las tres llevan a la misma pantalla. Se ofrece solo con `events.manage`; ocultarla no autoriza: la
 * ruta del editor se niega por su Policy (`viewLanding`).
 */
final class LandingActions
{
    /** Desde un evento: su pantalla o su tarjeta (el registro de la acción es el evento). */
    public static function design(): Action
    {
        return self::base(
            fn (Event $record): string => EventResource::getUrl('landing', ['record' => $record]),
            fn (Event $record): bool => Gate::allows('updateLanding', $record),
        );
    }

    /**
     * Desde la fila de una convocatoria: el evento es el de su gestor, no el de la fila.
     *
     * @param  Closure(): Event  $event
     */
    public static function designFor(Closure $event): Action
    {
        return self::base(
            fn (): string => EventResource::getUrl('landing', ['record' => $event()]),
            fn (): bool => Gate::allows('updateLanding', $event()),
        );
    }

    private static function base(Closure $url, Closure $visible): Action
    {
        return Action::make('designLanding')
            ->label(__('landings.editor.navigation'))
            ->icon(Heroicon::OutlinedPaintBrush)
            ->url($url)
            ->visible($visible);
    }
}
