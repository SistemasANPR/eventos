<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Landings\CreateEventLanding;
use App\Actions\Landings\DiscardLandingDraft;
use App\Actions\Landings\PublishEventLanding;
use App\Actions\Landings\ResolveLandingBlocks;
use App\Actions\Landings\SaveLandingDraft;
use App\Actions\Landings\UploadLandingImage;
use App\Exceptions\Domain\LandingVersionConflict;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use App\Models\EventLanding;
use App\Models\Organization;
use App\Support\Landings\LandingBlocks;
use App\Support\Landings\LandingEditorProps;
use App\Support\Landings\LandingImageUrls;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Renderless;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use LogicException;

/**
 * Editor visual de la landing del evento (plan 022, F4, ADR-0010): una pantalla del panel a pantalla
 * completa que monta el editor de React (`LandingEditor`) y le da su persistencia por Livewire (`$wire`).
 * El editor React es el único dueño del estado; esta página solo guarda: `saveDraft`, `publish`
 * («Actualizar»: guardar y publicar), `discard` y `uploadImage`, que llaman a las Actions y devuelven un
 * resultado plano (`LandingSaveResult` y compañía) en vez de lanzar, salvo la falta de permiso (403) y los
 * fallos de base o de disco, que se propagan.
 *
 * Quién entra lo decide `EventPolicy::viewLanding` (`events.manage`): sin el permiso, 403; un evento de otra
 * organización responde 404 (el registro sale del filtro de organización del panel). Un evento anterior al
 * plan recibe su landing, con la plantilla y solo en borrador, la primera vez que se abre (CA-022-2).
 */
class EditEventLanding extends ViewRecord
{
    use WithFileUploads;

    protected static string $resource = EventResource::class;

    /** Archivo temporal que sube el editor con `$wire.upload('imageUpload', ...)`; `uploadImage()` lo consume. */
    public ?TemporaryUploadedFile $imageUpload = null;

    public function getTitle(): string|Htmlable
    {
        return __('landings.editor.title', ['name' => EventsTable::displayName($this->event())]);
    }

    /** Sin encabezado ni migas: el editor ocupa la pantalla y trae su propia barra superior. */
    public function getHeading(): string|Htmlable
    {
        return '';
    }

    /** @return array<array-key, string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    /** `events.manage` sobre el evento, no el permiso de ver el evento a secas (CA-022-34). */
    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('viewLanding', $this->event()), 403);
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Creación perezosa (CA-022-2): solo la primera vez, con la plantilla y sin publicar.
        $this->ensureLanding();
    }

    /** Crea la landing de este editor si no existe (la del evento; la de una convocatoria lo cambia, plan 033). */
    protected function ensureLanding(): void
    {
        app(CreateEventLanding::class)->handle(ActionErrors::actor(), $this->event());
    }

    /**
     * Lo que recibe el editor al montarse.
     *
     * @return array<string, mixed>
     */
    protected function editorProps(): array
    {
        return LandingEditorProps::from($this->event(), $this->organization(), $this->landing(), app(ResolveLandingBlocks::class));
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.landings.editor')->viewData(fn (): array => [
                'available' => is_file(resource_path('js/landing-editor.tsx')),
                'props' => $this->editorProps(),
            ]),
        ]);
    }

    /**
     * «Guardar borrador».
     *
     * @param  array<array-key, mixed>  $blocks
     * @return array<string, mixed>
     */
    #[Renderless]
    public function saveDraft(array $blocks, int $version): array
    {
        return $this->save(
            fn (EventLanding $landing): EventLanding => app(SaveLandingDraft::class)->handle(ActionErrors::actor(), $landing, $blocks, $version),
            __('landings.editor.saved'),
        );
    }

    /**
     * «Actualizar»: guarda y publica en una sola operación.
     *
     * @param  array<array-key, mixed>  $blocks
     * @return array<string, mixed>
     */
    #[Renderless]
    public function publish(array $blocks, int $version): array
    {
        return $this->save(
            fn (EventLanding $landing): EventLanding => app(PublishEventLanding::class)->handle(ActionErrors::actor(), $landing, $blocks, $version),
            __('landings.editor.published'),
        );
    }

    /**
     * «Descartar»: el borrador vuelve a la versión publicada.
     *
     * @return array<string, mixed>
     */
    #[Renderless]
    public function discard(): array
    {
        $landing = $this->landing();

        try {
            $discarded = app(DiscardLandingDraft::class)->handle(ActionErrors::actor(), $landing, $landing->version);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'reason' => 'invalid', 'errors' => $exception->errors()];
        }

        Notification::make()->title(__('landings.editor.discarded'))->success()->send();

        return ['ok' => true, 'version' => $discarded->version, 'blocks' => $this->blocksFor($discarded)];
    }

    /**
     * Sube la imagen que el editor dejó en `imageUpload`.
     *
     * @return array<string, mixed>
     */
    #[Renderless]
    public function uploadImage(): array
    {
        $file = $this->imageUpload;

        if (! $file instanceof TemporaryUploadedFile) {
            return ['ok' => false, 'errors' => [__('files.upload_failed')]];
        }

        try {
            $image = app(UploadLandingImage::class)->handle(ActionErrors::actor(), $this->landing(), $file);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => array_values(array_merge(...array_values($exception->errors())))];
        } finally {
            $file->delete();
            $this->imageUpload = null;
        }

        $url = LandingImageUrls::forEditor($this->event(), $this->organization(), $this->landing())($image->id);

        return ['ok' => true, 'image' => ['id' => $image->id, 'url' => (string) $url]];
    }

    /**
     * @param  callable(EventLanding): EventLanding  $operation
     * @return array<string, mixed>
     */
    private function save(callable $operation, string $successTitle): array
    {
        try {
            $saved = $operation($this->landing());
        } catch (ValidationException $exception) {
            return ['ok' => false, 'reason' => 'invalid', 'errors' => $exception->errors()];
        } catch (LandingVersionConflict) {
            // El editor ofrece recargar lo guardado por la otra edición o conservar lo propio.
            $current = $this->landing();

            return ['ok' => false, 'reason' => 'conflict', 'version' => $current->version, 'blocks' => $this->blocksFor($current)];
        }

        Notification::make()->title($successTitle)->success()->send();

        return ['ok' => true, 'version' => $saved->version, 'blocks' => $this->blocksFor($saved)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function blocksFor(EventLanding $landing): array
    {
        return LandingBlocks::forEditor(
            $landing->draft_blocks,
            $this->event()->offeredLocales(),
            LandingImageUrls::forEditor($this->event(), $this->organization(), $landing),
        );
    }

    /** La landing se vuelve a leer en cada llamada: nunca se confía en una copia que el cliente pudiera haber desactualizado. */
    protected function landing(): EventLanding
    {
        return EventLanding::query()->where('event_id', $this->event()->id)->whereNull('call_id')->firstOrFail();
    }

    protected function organization(): Organization
    {
        return CurrentOrganization::get();
    }

    protected function event(): Event
    {
        $record = $this->getRecord();

        return $record instanceof Event ? $record : throw new LogicException('El editor de la landing necesita un evento.');
    }
}
