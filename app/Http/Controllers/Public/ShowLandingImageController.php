<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\CallStatus;
use App\Models\Event;
use App\Models\EventLanding;
use App\Models\LandingImage;
use App\Models\Organization;
use App\Support\Files\PublicProgramImage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Imagen de la landing de un evento para el público (plan 022, CA-022-32):
 * `/{organization:slug}/events/{event:slug}/landing/images/{image}`. Solo se sirve si el evento está
 * publicado, la imagen figura en una landing publicada (la del evento o la de una convocatoria que no es borrador), la imagen es de ese evento, no está borrada y figura en
 * `published_blocks`; una imagen solo del borrador responde 404, igual que una inexistente. La
 * organización queda en contexto por `SetOrganizationFromRoute`: una imagen de otra no se ve.
 */
final class ShowLandingImageController
{
    public function __invoke(Request $request, Organization $organization, Event $event, string $image): StreamedResponse
    {
        abort_unless($event->isPublished() && Str::isUuid($image), 404);

        // La imagen es de la landing del evento o de la de una de sus convocatorias (plan 033): se busca su landing.
        $row = LandingImage::query()->where('event_id', $event->id)->whereKey($image)->first();
        $landing = $row === null ? null : EventLanding::query()->whereKey($row->event_landing_id)->whereNotNull('published_blocks')->first();

        abort_unless($landing !== null && in_array($image, $landing->publishedImageIds(), true), 404);

        // Las imágenes de la landing de una convocatoria solo se ven si la convocatoria no es un borrador.
        abort_if($landing->call_id !== null && $landing->call?->status === CallStatus::Draft, 404);

        $path = $row?->path;

        abort_unless(is_string($path), 404);

        return PublicProgramImage::response($path) ?? abort(404);
    }
}
