<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Actions\Submissions\DownloadSubmissionFile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Descarga de un archivo de propuesta por un organizador:
 * `GET /admin/{organization:slug}/submission-files/{file}`, mismo prefijo que el
 * panel de Filament. La organización sale de la ruta (`SetOrganizationFromRoute`),
 * nunca del cliente; la Action autoriza con la Policy y redirige a la URL temporal.
 */
final class SubmissionFileDownloadController
{
    public function __invoke(Request $request, Organization $organization, string $file, DownloadSubmissionFile $download): RedirectResponse
    {
        /** @var User $user `auth` garantiza la sesión */
        $user = $request->user();

        return redirect()->away($download->handle($user, $file));
    }
}
