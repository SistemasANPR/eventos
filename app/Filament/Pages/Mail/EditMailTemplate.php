<?php

declare(strict_types=1);

namespace App\Filament\Pages\Mail;

use App\Actions\Notifications\PreviewMailTemplate;
use App\Actions\Notifications\ResolveMailTemplate;
use App\Actions\Notifications\RestoreMailTemplate;
use App\Actions\Notifications\UpdateMailTemplate;
use App\Filament\Support\ActionErrors;
use App\Models\MailTemplate;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Notifications\NotificationDescriptor;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Edita la plantilla de una notificación en un idioma, con vista previa y restaurar
 * (plan 008, H-008-4). Se llega por la clave del catálogo: una clave desconocida responde 404
 * y las plantillas de otra organización no existen para esta pantalla (scope de organización).
 * Quien solo tiene `mail-templates.view` la ve sin poder cambiarla. Es presentación: guardar,
 * restaurar y previsualizar llaman a Actions, que vuelven a autorizar y validar.
 *
 * @property Schema $form
 */
class EditMailTemplate extends Page
{
    protected static ?string $slug = 'mail-templates/{key}';

    protected static bool $shouldRegisterNavigation = false;

    public string $key = '';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array{subject: string, html: string}|null vista previa con datos de ejemplo */
    public ?array $preview = null;

    /** `organization` si el idioma abierto tiene plantilla propia; `system` si usa la predeterminada. */
    public string $source = 'system';

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', MailTemplate::class);
    }

    public function mount(string $key): void
    {
        abort_unless(app(NotificationCatalog::class)->has($key), 404);

        $this->key = $key;
        $descriptor = $this->descriptor();
        $locale = in_array(app()->getLocale(), $descriptor->locales, true) ? app()->getLocale() : $descriptor->locales[0];

        $this->loadTemplate($locale);
    }

    public function getTitle(): string|Htmlable
    {
        return __('mail_admin.edit.title', ['name' => $this->descriptor()->name()]);
    }

    public function canManage(): bool
    {
        return Gate::allows('update', MailTemplate::class);
    }

    public function form(Schema $schema): Schema
    {
        $descriptor = $this->descriptor();

        return $schema
            ->components([
                Section::make(__('mail_admin.edit.sections.content'))
                    ->description(fn (): string => $this->canManage()
                        ? __('mail_admin.edit.source.'.$this->source)
                        : __('mail_admin.edit.read_only'))
                    ->schema([
                        Select::make('locale')
                            ->label(__('mail_admin.edit.fields.locale'))
                            ->options(collect($descriptor->locales)->mapWithKeys(fn (string $locale): array => [$locale => strtoupper($locale)])->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (?string $state) => $this->loadTemplate((string) $state)),
                        TextInput::make('subject')
                            ->label(__('mail_admin.edit.fields.subject'))
                            ->helperText(__('mail_admin.edit.help.subject'))
                            ->required()
                            ->maxLength(200)
                            ->disabled(fn (): bool => ! $this->canManage()),
                        Textarea::make('body')
                            ->label(__('mail_admin.edit.fields.body'))
                            ->helperText(__('mail_admin.edit.help.body').' '.__('mail_admin.edit.help.variables', ['variables' => $this->variableList($descriptor)]))
                            ->required()
                            ->rows(12)
                            ->maxLength(20000)
                            ->disabled(fn (): bool => ! $this->canManage()),
                    ]),
                Section::make(__('mail_admin.edit.sections.sender'))
                    ->schema([
                        TextInput::make('from_name')
                            ->label(__('mail_admin.edit.fields.from_name'))
                            ->helperText(__('mail_admin.edit.help.from_name'))
                            ->maxLength(100)
                            ->disabled(fn (): bool => ! $this->canManage()),
                        TextInput::make('reply_to')
                            ->label(__('mail_admin.edit.fields.reply_to'))
                            ->helperText(__('mail_admin.edit.help.reply_to'))
                            ->email()
                            ->maxLength(254)
                            ->disabled(fn (): bool => ! $this->canManage()),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
            View::make('filament.mail.preview')
                ->viewData(fn (): array => ['preview' => $this->preview])
                ->visible(fn (): bool => $this->preview !== null),
        ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())->key('form-actions'),
            ]);
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('mail_admin.actions.save'))
                ->submit('save')
                ->visible(fn (): bool => $this->canManage()),
            Action::make('preview')
                ->label(__('mail_admin.actions.preview'))
                ->color('gray')
                ->action(fn () => $this->preview())
                ->visible(fn (): bool => $this->canManage()),
            Action::make('restore')
                ->label(__('mail_admin.actions.restore'))
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('mail_admin.confirm.restore_heading'))
                ->modalDescription(__('mail_admin.confirm.restore_locale_body'))
                ->action(fn () => $this->restore())
                ->visible(fn (): bool => $this->canManage() && $this->source === 'organization'),
            Action::make('back')
                ->label(__('mail_admin.actions.back'))
                ->color('gray')
                ->url(fn (): string => MailCatalog::getUrl()),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        try {
            app(UpdateMailTemplate::class)->handle(ActionErrors::actor(), CurrentOrganization::get(), $this->key, (string) $data['locale'], $data);
        } catch (ValidationException $exception) {
            throw ActionErrors::forForm($exception);
        }

        Notification::make()->success()->title(__('mail_admin.notifications.saved'))->send();

        $this->loadTemplate((string) $data['locale']);
    }

    public function preview(): void
    {
        $data = $this->form->getRawState();

        try {
            $rendered = app(PreviewMailTemplate::class)->handle(
                ActionErrors::actor(),
                CurrentOrganization::get(),
                $this->key,
                (string) ($data['locale'] ?? ''),
                (string) ($data['subject'] ?? ''),
                (string) ($data['body'] ?? ''),
            );
        } catch (ValidationException $exception) {
            $this->preview = null;
            Notification::make()->danger()->title(__('mail_admin.notifications.invalid'))->body(ActionErrors::summary($exception))->send();

            return;
        }

        $this->preview = ['subject' => $rendered->subject, 'html' => $rendered->html];
    }

    public function restore(): void
    {
        $locale = (string) ($this->data['locale'] ?? '');
        $removed = app(RestoreMailTemplate::class)->handle(ActionErrors::actor(), CurrentOrganization::get(), $this->key, $locale);

        Notification::make()
            ->success()
            ->title(__($removed ? 'mail_admin.notifications.restored' : 'mail_admin.notifications.nothing_to_restore'))
            ->send();

        $this->loadTemplate($locale);
    }

    /** Carga en el formulario la plantilla propia del idioma o, si no hay, la predeterminada del sistema. */
    public function loadTemplate(string $locale): void
    {
        $template = app(ResolveMailTemplate::class)->handle($this->key, $locale, CurrentOrganization::get());

        $this->source = $template->source;
        $this->preview = null;
        $this->form->fill([
            'locale' => $locale,
            'subject' => $template->subject,
            'body' => $template->body,
            'from_name' => $template->fromName,
            'reply_to' => $template->replyTo,
        ]);
    }

    private function descriptor(): NotificationDescriptor
    {
        return app(NotificationCatalog::class)->get($this->key);
    }

    private function variableList(NotificationDescriptor $descriptor): string
    {
        return implode(', ', array_map(fn (string $variable): string => '{{ '.$variable.' }}', $descriptor->variables));
    }
}
