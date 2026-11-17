<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Enums\ProgramImageType;
use App\Http\Requests\Program\StoreActivityRequest;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Program\ActivityWrites;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Da de alta una actividad A MANO en un evento (plan 021, DA-4, CA-021-4): nombre, fecha de inicio,
 * fecha de fin, hora de inicio y hora de fin son obligatorios (las actividades que nacen de una
 * propuesta, fase F5, se crean sin ellos por otra vía). Nace publicada. Icono e imagen se guardan con
 * `StoreProgramImage`; si algo falla después, los archivos recién guardados se borran. Se ejecuta en
 * la organización DEL EVENTO, dentro de una transacción, y deja auditoría con ids (sin texto libre).
 *
 * `description` se guarda tal cual llega: el saneado del HTML (`RichTextSanitizer`, DA-16) se añade
 * aquí en la fase F3b, antes de `forceFill`.
 *
 * @throws AuthorizationException si quien crea no tiene `activities.manage` en la organización del evento
 * @throws ValidationException si falta un dato obligatorio, el fin es anterior al inicio, una categoría no es del evento o un archivo no es aceptable
 */
final class CreateActivity
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data): EventActivity
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data): EventActivity {
            $this->authorizeActor($actor, 'create', EventActivity::class, $event);

            $validated = Validator::make(ActivityWrites::trimmed($data), StoreActivityRequest::rulesFor($event))->validate();

            $stored = [];

            try {
                $icon = $validated['icon'] ?? null;
                $image = $validated['image'] ?? null;

                $iconPath = $icon instanceof UploadedFile ? $stored[] = ActivityWrites::storeImage($icon, $event, ProgramImageType::Icon, 'icon') : null;
                $imagePath = $image instanceof UploadedFile ? $stored[] = ActivityWrites::storeImage($image, $event, ProgramImageType::Image, 'image') : null;

                return DB::transaction(function () use ($actor, $event, $validated, $iconPath, $imagePath): EventActivity {
                    $activity = new EventActivity;
                    $activity->event_id = $event->id;
                    $activity->forceFill([
                        'title' => $validated['title'],
                        'starts_on' => $validated['starts_on'],
                        'ends_on' => $validated['ends_on'],
                        'starts_at' => ActivityWrites::time($validated['starts_at']),
                        'ends_at' => ActivityWrites::time($validated['ends_at']),
                        'summary' => $validated['summary'] ?? null,
                        'description' => $validated['description'] ?? null,
                        'icon_path' => $iconPath,
                        'image_path' => $imagePath,
                        'is_published' => $validated['is_published'] ?? true,
                    ])->save();

                    ActivityWrites::syncCategories($activity, array_values($validated['category_ids'] ?? []));

                    activity()
                        ->causedBy($actor)
                        ->performedOn($activity)
                        ->event('activity_created')
                        ->withProperties([
                            'organization_id' => $activity->organization_id,
                            'event_id' => $event->id,
                            'is_published' => $activity->is_published,
                        ])
                        ->log('activity_created');

                    return $activity;
                });
            } catch (Throwable $exception) {
                foreach ($stored as $path) {
                    app(DeleteProgramImage::class)->handle($event, $path);
                }

                throw $exception;
            }
        });
    }
}
