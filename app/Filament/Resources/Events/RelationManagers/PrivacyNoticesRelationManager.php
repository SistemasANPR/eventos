<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\RelationManagers;

use App\Actions\Privacy\CreatePrivacyNoticeDraft;
use App\Actions\Privacy\PublishPrivacyNotice;
use App\Actions\Privacy\UpdatePrivacyNoticeDraft;
use App\Filament\Support\ActionErrors;
use App\Http\Requests\Events\StoreEventRequest;
use App\Models\Event;
use App\Models\PrivacyNotice;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Aviso de privacidad DENTRO del evento (plan 003, H-003-2): versiones, nuevo
 * borrador (parte del texto publicado vigente), edición de borradores y
 * publicación. Delgado: las reglas viven en las Actions y las Policies deciden;
 * no hay forma de borrar una versión.
 */
class PrivacyNoticesRelationManager extends RelationManager
{
    protected static string $relationship = 'privacyNotices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('privacy.relation_title');
    }

    /** Solo quien gestiona los avisos ve la sección (el organizador no). */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('viewAny', PrivacyNotice::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('publisher'))
            ->columns([
                TextColumn::make('version')
                    ->label(__('privacy.columns.version'))
                    ->prefix('v')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('privacy.columns.status'))
                    ->state(fn (PrivacyNotice $record): string => $record->isPublished() ? __('privacy.status.published') : __('privacy.status.draft'))
                    ->badge()
                    ->color(fn (PrivacyNotice $record): string => $record->isPublished() ? 'success' : 'gray'),
                TextColumn::make('published_at')
                    ->label(__('privacy.columns.published_at'))
                    ->state(fn (PrivacyNotice $record): ?string => $record->published_at?->utc()->format('Y-m-d H:i'))
                    ->placeholder('—'),
                TextColumn::make('published_by')
                    ->label(__('privacy.columns.published_by'))
                    ->state(fn (PrivacyNotice $record): ?string => $record->publisher?->name)
                    ->placeholder('—'),
            ])
            ->defaultSort('version', 'desc')
            ->headerActions([$this->createDraftAction()])
            ->recordActions([$this->editAction(), $this->publishAction()])
            ->emptyStateHeading(__('privacy.empty_heading'))
            ->emptyStateDescription(__('privacy.empty_description'));
    }

    private function createDraftAction(): Action
    {
        return Action::make('createDraft')
            ->label(__('privacy.new_draft'))
            ->icon(Heroicon::OutlinedPlus)
            ->schema(fn (): array => $this->bodyFields())
            // Con una versión publicada, el nuevo borrador parte de su texto (CA-003-14).
            ->fillForm(fn (): array => ['body' => PrivacyNotice::currentPublished($this->owner())?->getTranslations('body') ?? []])
            ->visible(fn (): bool => ! $this->owner()->privacyNotices()->whereNull('published_at')->exists()
                && Gate::allows('create', [PrivacyNotice::class, $this->owner()]))
            ->action(fn (array $data) => $this->run(
                fn () => app(CreatePrivacyNoticeDraft::class)->handle(ActionErrors::actor(), $this->owner(), (array) ($data['body'] ?? [])),
                __('privacy.actions.saved'),
            ));
    }

    private function editAction(): Action
    {
        return Action::make('edit')
            ->label(__('privacy.edit_draft'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema(fn (): array => $this->bodyFields())
            ->fillForm(fn (PrivacyNotice $record): array => ['body' => $record->getTranslations('body')])
            ->visible(fn (PrivacyNotice $record): bool => ! $record->isPublished() && Gate::allows('update', $record))
            ->action(fn (PrivacyNotice $record, array $data) => $this->run(
                fn () => app(UpdatePrivacyNoticeDraft::class)->handle(ActionErrors::actor(), $record, (array) ($data['body'] ?? [])),
                __('privacy.actions.saved'),
            ));
    }

    private function publishAction(): Action
    {
        return Action::make('publish')
            ->label(__('privacy.actions.publish'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('privacy.actions.publish_confirm'))
            ->visible(fn (PrivacyNotice $record): bool => ! $record->isPublished() && Gate::allows('publish', $record))
            ->action(fn (PrivacyNotice $record) => $this->run(
                fn () => app(PublishPrivacyNotice::class)->handle(ActionErrors::actor(), $record),
                __('privacy.actions.published'),
            ));
    }

    /**
     * Un editor por idioma del evento, con solo las opciones de formato que acepta la lista blanca.
     * El texto se vuelve a sanitizar en el servidor: el editor no es una barrera de seguridad.
     *
     * @return list<RichEditor>
     */
    private function bodyFields(): array
    {
        $fields = [];

        foreach (StoreEventRequest::LOCALES as $locale) {
            $fields[] = RichEditor::make("body.{$locale}")
                ->label(__("privacy.fields.body_{$locale}"))
                ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']])
                ->visible(fn (): bool => in_array($locale, $this->owner()->locales, true));
        }

        return $fields;
    }

    private function owner(): Event
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event ? $owner : throw new \LogicException('El gestor del aviso necesita un evento.');
    }

    /** @param  callable(): mixed  $operation */
    private function run(callable $operation, string $successTitle): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('privacy.actions.failed'))
                ->body(ActionErrors::summary($exception))
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
