<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Models\Event;
use App\Models\Organization;
use App\Support\Files\PublicProgramImage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Logotipo de un evento para el público (plan 027, DA-4, CA-027-10 y 11):
 * `/{organization:slug}/events/{event:slug}/logo`. Solo se sirve si el evento está publicado y tiene
 * logotipo; borrador, archivado, sin logotipo o con el archivo ausente responden el mismo 404. La
 * organización queda en contexto por `SetOrganizationFromRoute`: un evento de otra organización no se ve.
 */
final class ShowEventLogoController
{
    public function __invoke(Request $request, Organization $organization, Event $event): StreamedResponse
    {
        abort_unless($event->isPublished() && is_string($event->logo_path), 404);

        return PublicProgramImage::response($event->logo_path) ?? abort(404);
    }
}
