<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Models\EventActivity;
use App\Models\Organization;
use App\Support\Files\MediaDisk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vista previa del icono o la imagen de una actividad para el organizador (plan 021, DA-11):
 * `GET /admin/{organization:slug}/activities/{activity}/media/{icon|image}`, mismo prefijo que el
 * panel. El disco `media` es privado, así que el archivo no tiene URL pública: lo sirve esta ruta
 * autenticada. La organización sale de la ruta (`SetOrganizationFromRoute`), una actividad de otra
 * organización responde 404 y quien no tiene `activities.manage` recibe 403 (`EventActivityPolicy`).
 * No es la ruta pública del programa: esa comprueba `is_published` y llega con la integración.
 */
final class ActivityMediaController
{
    public function __invoke(Request $request, Organization $organization, string $activity, string $type): StreamedResponse
    {
        abort_unless(Str::isUuid($activity), 404);

        $model = EventActivity::query()->findOrFail($activity);

        Gate::authorize('view', $model);

        $path = $type === 'icon' ? $model->icon_path : $model->image_path;

        abort_if($path === null, 404);

        $disk = Storage::disk(MediaDisk::name());

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
