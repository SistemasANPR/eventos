<?php

declare(strict_types=1);

namespace App\Support\Panel;

use App\Filament\Resources\Events\EventSectionsNavigation;
use App\Models\Event;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Str;

use function Filament\Support\original_request;

/**
 * Nombre que la barra superior del panel de organizadores muestra como marca (plan 020, CA-020-20).
 *
 * Fuera de un evento es el nombre de la plataforma; dentro de uno, el nombre del evento de la ruta en el
 * idioma activo, para que la persona sepa siempre en qué evento está. El evento se consulta con el filtro
 * de organización, así que el de otra organización nunca aparece. Devuelve texto plano: Blade lo escapa.
 */
final class EventBrandName
{
    private const REQUEST_KEY = 'eventos.event_brand_name';

    public static function resolve(): string
    {
        $request = original_request();

        if (! $request->attributes->has(self::REQUEST_KEY)) {
            $request->attributes->set(self::REQUEST_KEY, self::lookup());
        }

        /** @var string $name */
        $name = $request->attributes->get(self::REQUEST_KEY);

        return $name;
    }

    private static function lookup(): string
    {
        $default = (string) config('app.name');
        $eventId = EventSectionsNavigation::eventId();

        // Un id que no es uuid ni llega a la base (daría error de tipo en vez de 404); sin organización no hay evento que mostrar.
        if ($eventId === null || ! Str::isUuid($eventId) || ! CurrentOrganization::has()) {
            return $default;
        }

        $name = trim((string) Event::query()->whereKey($eventId)->first()?->name);

        return $name !== '' ? $name : $default;
    }
}
