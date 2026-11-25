<?php

declare(strict_types=1);

namespace App\Enums;

/** Por qué una página pública no existe (plan 030): la respuesta de `PublicPageAvailability`. */
enum PublicPageUnavailability: string
{
    /** El evento se archivó: el público ve «ya no está disponible» (410). */
    case Archived = 'archived';

    /** El evento aún no se publica: el público ve 404 y el panel lo explica. */
    case DraftEvent = 'draft_event';

    /** La convocatoria sigue en borrador: no tiene página pública. */
    case DraftCall = 'draft_call';
}
