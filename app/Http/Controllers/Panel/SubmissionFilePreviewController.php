<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Actions\Submissions\DownloadSubmissionFile;
use App\Models\Organization;
use App\Models\User;
use App\Support\Files\PrivateUploads;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Imagen de una propuesta mostrada dentro del detalle del panel:
 * `GET /admin/{organization:slug}/submission-files/{file}/preview`. La Action autoriza igual que la descarga y
 * solo deja pasar JPG y PNG. Se sirve desde la aplicación con el tipo guardado, `nosniff` y sin caché compartida;
 * el archivo sigue siendo privado.
 */
final class SubmissionFilePreviewController
{
    public function __invoke(Request $request, Organization $organization, string $file, DownloadSubmissionFile $download, PrivateUploads $uploads): StreamedResponse
    {
        /** @var User $user `auth` garantiza la sesión */
        $user = $request->user();
        $record = $download->preview($user, $file);
        $disk = $uploads->disk();

        abort_unless($disk instanceof FilesystemAdapter && $disk->exists($record->storage_path), 404);

        return $disk->response($record->storage_path, $record->id.'.'.pathinfo($record->storage_path, PATHINFO_EXTENSION), [
            'Content-Type' => $record->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
