<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Actions\SubmissionExports\DownloadSubmissionExport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entrega el archivo de una exportación de propuestas (plan 009, CA-009-19):
 * `GET /admin/{organization:slug}/submission-exports/{export}?user=…` con URL firmada de 5 minutos.
 * La firma la exige el middleware `signed`; además el parámetro firmado `user` debe ser quien tiene
 * la sesión, así que la URL no sirve a otra persona aunque la reciba. Se entrega como adjunto con un
 * nombre generado por el servidor (nunca texto del usuario).
 */
final class SubmissionExportDownloadController
{
    public function __invoke(Request $request, Organization $organization, string $export, DownloadSubmissionExport $download): StreamedResponse
    {
        /** @var User $user `auth` garantiza la sesión */
        $user = $request->user();

        abort_unless($request->query('user') === $user->id, 403);

        $file = $download->handle($user, $export);

        return Storage::disk($file['disk'])->download($file['path'], $file['name']);
    }
}
