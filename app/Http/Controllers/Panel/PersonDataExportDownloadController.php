<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Actions\Privacy\DownloadPersonDataExport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entrega el archivo de una exportación de datos de una persona (plan 014, CA-014-20):
 * `GET /admin/{organization:slug}/person-data-exports/{export}?user=…` con URL firmada de 10 minutos. La firma
 * la exige el middleware `signed`; además el parámetro firmado `user` debe ser quien tiene la sesión, así que
 * la URL no sirve a otra persona aunque la reciba. Se entrega como adjunto con un nombre generado por el servidor.
 */
final class PersonDataExportDownloadController
{
    public function __invoke(Request $request, Organization $organization, string $export, DownloadPersonDataExport $download): StreamedResponse
    {
        /** @var User $user `auth` garantiza la sesión */
        $user = $request->user();

        abort_unless($request->query('user') === $user->id, 403);

        $file = $download->handle($user, $export);

        return Storage::disk($file['disk'])->download($file['path'], $file['name']);
    }
}
