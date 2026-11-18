<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Speaker;
use App\Support\Files\PublicProgramImage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fotografía de un ponente para el público (plan 021, CA-021-18):
 * `/{organization:slug}/events/{event:slug}/speakers/{speaker}/photo`. Solo se sirve si el evento está
 * publicado y el ponente es de ese evento, está publicado, no está borrado y tiene fotografía; en
 * cualquier otro caso responde 404, igual que uno inexistente. La organización queda en contexto por
 * `SetOrganizationFromRoute`, así que un ponente de otra organización no se ve.
 */
final class ShowSpeakerPhotoController
{
    public function __invoke(Request $request, Organization $organization, Event $event, string $speaker): StreamedResponse
    {
        abort_unless($event->isPublished() && Str::isUuid($speaker), 404);

        $path = Speaker::query()
            ->where('event_id', $event->id)
            ->where('is_published', true)
            ->whereKey($speaker)
            ->value('photo_path');

        abort_unless(is_string($path), 404);

        return PublicProgramImage::response($path) ?? abort(404);
    }
}
