<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Support\Files\PublicProgramImage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Icono o imagen de una actividad para el público (plan 021, CA-021-18):
 * `/{organization:slug}/events/{event:slug}/activities/{activity}/{icon|image}`. Solo se sirve si el
 * evento está publicado y la actividad es de ese evento, está publicada, no está borrada y tiene ese
 * archivo; en cualquier otro caso responde 404. La organización queda en contexto por
 * `SetOrganizationFromRoute`, así que una actividad de otra organización no se ve.
 */
final class ShowActivityMediaController
{
    public function __invoke(Request $request, Organization $organization, Event $event, string $activity, string $type): StreamedResponse
    {
        abort_unless($event->isPublished() && Str::isUuid($activity), 404);

        $path = EventActivity::query()
            ->where('event_id', $event->id)
            ->where('is_published', true)
            ->whereKey($activity)
            ->value($type === 'icon' ? 'icon_path' : 'image_path');

        abort_unless(is_string($path), 404);

        return PublicProgramImage::response($path) ?? abort(404);
    }
}
