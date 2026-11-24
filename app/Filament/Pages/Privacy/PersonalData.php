<?php

declare(strict_types=1);

namespace App\Filament\Pages\Privacy;

use App\Actions\Privacy\AnonymizePerson;
use App\Actions\Privacy\ExportPersonData;
use App\Enums\PersonDataRequestReason;
use App\Exceptions\Domain\AnonymizationSaltMissing;
use App\Exceptions\Domain\LastOrganizationAdmin;
use App\Exceptions\Domain\PersonCannotBeAnonymized;
use App\Exceptions\Domain\PersonDataNotFound;
use App\Exceptions\Domain\PersonFileDeletionFailed;
use App\Filament\Support\ActionErrors;
use App\Http\Requests\ExportPersonDataRequest;
use App\Models\Contact;
use App\Support\Privacy\AnonymizationResult;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Exportar y anonimizar los datos de una persona (plan 014, H-014-4). Es solo presentación: quién puede
 * entrar lo decide `ContactPolicy` (`personal_data.manage`, por defecto solo `org_admin`) y las acciones llaman a
 * `ExportPersonData` y `AnonymizePerson`, que vuelven a autorizar y validar (ocultar la página no es autorizar).
 * Anonimizar pide una confirmación fuerte: un modal con aviso en el que hay que volver a escribir el correo.
 *
 * @property Schema $form
 */
class PersonalData extends Page
{
    protected static ?string $slug = 'personal-data';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 90;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.organization');
    }

    public static function getNavigationLabel(): string
    {
        return __('privacy_admin.navigation.personal_data');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('export', Contact::class);
    }

    public function mount(): void
    {
        $this->form->fill(['reason' => PersonDataRequestReason::DataSubjectRequest->value]);
    }

    public function getTitle(): string|Htmlable
    {
        return __('privacy_admin.personal_data.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('privacy_admin.personal_data.subtitle');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('email')
                    ->label(__('privacy_admin.personal_data.fields.email'))
                    ->helperText(__('privacy_admin.personal_data.fields.email_help'))
                    ->email()
                    ->required()
                    ->maxLength(254)
                    ->autocomplete(false),
                Select::make('reason')
                    ->label(__('privacy_admin.personal_data.fields.reason'))
                    ->options(collect(PersonDataRequestReason::cases())->mapWithKeys(
                        fn (PersonDataRequestReason $reason): array => [$reason->value => $reason->label()],
                    )->all())
                    ->required()
                    ->rules(ExportPersonDataRequest::fieldRules()['reason']),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('export')
            ->footer([
                Actions::make($this->getFormActions())->key('form-actions'),
            ]);
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [
            Action::make('export')
                ->label(__('privacy_admin.personal_data.actions.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->submit('export'),
            Action::make('anonymize')
                ->label(__('privacy_admin.personal_data.actions.anonymize'))
                ->icon(Heroicon::OutlinedEyeSlash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('privacy_admin.personal_data.actions.anonymize_heading'))
                ->modalDescription(__('privacy_admin.personal_data.actions.anonymize_body'))
                ->modalSubmitActionLabel(__('privacy_admin.personal_data.actions.anonymize_submit'))
                ->schema([
                    TextInput::make('confirmation')
                        ->label(__('privacy_admin.personal_data.fields.confirmation'))
                        ->helperText(__('privacy_admin.personal_data.fields.confirmation_help'))
                        ->required()
                        ->maxLength(254),
                ])
                ->action(fn (array $data) => $this->anonymize($data)),
        ];
    }

    public function export(): void
    {
        $state = $this->form->getState();

        try {
            $export = app(ExportPersonData::class)->handle(ActionErrors::actor(), $state);
        } catch (PersonDataNotFound) {
            Notification::make()->warning()->title(__('privacy_admin.personal_data.notifications.not_found'))->send();

            return;
        } catch (ValidationException $exception) {
            throw ActionErrors::forForm($exception);
        }

        Notification::make()
            ->success()
            ->title(__('privacy_admin.personal_data.notifications.exported'))
            ->body(__('privacy_admin.personal_data.notifications.exported_body', ['minutes' => ExportPersonData::URL_MINUTES]))
            ->actions([
                Action::make('download')
                    ->label(__('privacy_admin.personal_data.actions.download'))
                    ->url($export->url, shouldOpenInNewTab: true)
                    ->button(),
            ])
            ->persistent()
            ->send();
    }

    /** @param  array<string, mixed>  $data  `confirmation`, del modal */
    public function anonymize(array $data): void
    {
        $state = $this->form->getState();

        try {
            $result = app(AnonymizePerson::class)->handle(ActionErrors::actor(), [...$state, 'confirmation' => $data['confirmation'] ?? null]);
        } catch (PersonDataNotFound) {
            Notification::make()->warning()->title(__('privacy_admin.personal_data.notifications.not_found'))->send();

            return;
        } catch (PersonCannotBeAnonymized $exception) {
            $this->fail($exception->reason === PersonCannotBeAnonymized::OWN_ACCOUNT ? 'own_account' : 'platform_account');

            return;
        } catch (LastOrganizationAdmin) {
            $this->fail('last_admin');

            return;
        } catch (PersonFileDeletionFailed) {
            $this->fail('files_failed');

            return;
        } catch (AnonymizationSaltMissing) {
            $this->fail('salt_missing');

            return;
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title(ActionErrors::summary($exception))->send();

            return;
        }

        $this->reportAnonymization($result);
        $this->form->fill(['reason' => $state['reason'] ?? PersonDataRequestReason::DataSubjectRequest->value]);
    }

    private function reportAnonymization(AnonymizationResult $result): void
    {
        if ($result->isAlreadyAnonymized()) {
            Notification::make()->info()->title(__('privacy_admin.personal_data.notifications.already_anonymized'))->send();

            return;
        }

        $body = __('privacy_admin.personal_data.notifications.anonymized_body', [
            'total' => $result->total() - ($result->counts['files_deleted'] ?? 0),
            'files' => $result->counts['files_deleted'] ?? 0,
        ]);

        if (in_array(AnonymizationResult::NOTE_ACCOUNT_KEPT_OTHER_ORGANIZATIONS, $result->notes, true)) {
            $body .= ' '.__('privacy_admin.personal_data.notifications.account_kept');
        }

        Notification::make()->success()->title(__('privacy_admin.personal_data.notifications.anonymized'))->body($body)->send();
    }

    private function fail(string $key): void
    {
        Notification::make()->danger()->title(__("privacy_admin.personal_data.notifications.{$key}"))->send();
    }
}
