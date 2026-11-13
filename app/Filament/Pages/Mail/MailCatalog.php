<?php

declare(strict_types=1);

namespace App\Filament\Pages\Mail;

use App\Actions\Notifications\RestoreMailTemplate;
use App\Actions\Notifications\SetNotificationEnabled;
use App\Exceptions\Domain\CriticalNotificationCannotBeDisabled;
use App\Filament\Support\ActionErrors;
use App\Models\MailTemplate;
use App\Support\Notifications\NotificationOverview;
use App\Support\Tenancy\CurrentOrganization;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;

/**
 * Catálogo de correos de la organización con su estado (plan 008, H-008-3 y H-008-5):
 * predeterminado, personalizado o desactivado. Es solo presentación: quién puede verlo y
 * operarlo lo decide `MailTemplatePolicy`, y las acciones llaman a las Actions, que vuelven
 * a autorizar (ocultar un botón no es autorizar). Las notificaciones críticas no ofrecen
 * desactivar, y la Action además lo rechaza.
 */
class MailCatalog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'mail-templates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    public static function getNavigationLabel(): string
    {
        return __('mail_admin.navigation');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', MailTemplate::class);
    }

    public function getTitle(): string|Htmlable
    {
        return __('mail_admin.catalog.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('mail_admin.catalog.subtitle');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => app(NotificationOverview::class)->forOrganization(CurrentOrganization::get()))
            ->paginated(false)
            ->emptyStateHeading(__('mail_admin.catalog.empty'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('mail_admin.catalog.columns.name'))
                    ->weight('medium')
                    ->description(fn (array $record): string => $record['description'])
                    ->wrap(),
                TextColumn::make('recipient')
                    ->label(__('mail_admin.catalog.columns.recipient'))
                    ->visibleFrom('lg')
                    ->wrap(),
                TextColumn::make('critical')
                    ->label(__('mail_admin.catalog.columns.critical'))
                    ->badge()
                    ->visibleFrom('md')
                    ->state(fn (array $record): string => $record['critical'] ? __('mail_admin.catalog.critical_yes') : __('mail_admin.catalog.critical_no'))
                    ->color(fn (array $record): string => $record['critical'] ? 'warning' : 'gray'),
                TextColumn::make('status')
                    ->label(__('mail_admin.catalog.columns.status'))
                    ->badge()
                    ->state(fn (array $record): string => __('mail_admin.status.'.$record['status']))
                    ->color(fn (array $record): string => match ($record['status']) {
                        'disabled' => 'danger',
                        'customized' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('languages')
                    ->label(__('mail_admin.catalog.columns.languages'))
                    ->visibleFrom('lg')
                    ->state(fn (array $record): string => $record['customized_locales'] === []
                        ? __('mail_admin.catalog.no_languages')
                        : strtoupper(implode(', ', $record['customized_locales']))),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(fn (): string => Gate::allows('update', MailTemplate::class) ? __('mail_admin.actions.edit') : __('mail_admin.actions.view'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (array $record): string => EditMailTemplate::getUrl(['key' => $record['key']])),
                Action::make('disable')
                    ->label(__('mail_admin.actions.disable'))
                    ->icon(Heroicon::OutlinedBellSlash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('mail_admin.confirm.disable_heading'))
                    ->modalDescription(__('mail_admin.confirm.disable_body'))
                    ->visible(fn (array $record): bool => ! $record['critical'] && $record['enabled'] && Gate::allows('toggle', MailTemplate::class))
                    ->action(fn (array $record) => $this->toggle($record['key'], false)),
                Action::make('enable')
                    ->label(__('mail_admin.actions.enable'))
                    ->icon(Heroicon::OutlinedBell)
                    ->requiresConfirmation()
                    ->modalHeading(__('mail_admin.confirm.enable_heading'))
                    ->modalDescription(__('mail_admin.confirm.enable_body'))
                    ->visible(fn (array $record): bool => ! $record['critical'] && ! $record['enabled'] && Gate::allows('toggle', MailTemplate::class))
                    ->action(fn (array $record) => $this->toggle($record['key'], true)),
                Action::make('restore')
                    ->label(__('mail_admin.actions.restore'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(__('mail_admin.confirm.restore_heading'))
                    ->modalDescription(__('mail_admin.confirm.restore_body'))
                    ->visible(fn (array $record): bool => $record['customized_locales'] !== [] && Gate::allows('restore', MailTemplate::class))
                    ->action(fn (array $record) => $this->restore($record['key'], $record['customized_locales'])),
            ]);
    }

    private function toggle(string $key, bool $enabled): void
    {
        try {
            app(SetNotificationEnabled::class)->handle(ActionErrors::actor(), CurrentOrganization::get(), $key, $enabled);
        } catch (CriticalNotificationCannotBeDisabled) {
            Notification::make()->danger()->title(__('mail_admin.notifications.critical'))->send();

            return;
        }

        // Los registros de la tabla se guardan en caché durante la petición: sin esto la fila se
        // pinta con el estado anterior y el botón Activar/Desactivar no cambia.
        $this->flushCachedTableRecords();

        Notification::make()->success()->title(__($enabled ? 'mail_admin.notifications.enabled' : 'mail_admin.notifications.disabled'))->send();
    }

    /** @param  list<string>  $locales  idiomas con personalización */
    private function restore(string $key, array $locales): void
    {
        $restore = app(RestoreMailTemplate::class);

        foreach ($locales as $locale) {
            $restore->handle(ActionErrors::actor(), CurrentOrganization::get(), $key, $locale);
        }

        $this->flushCachedTableRecords();

        Notification::make()->success()->title(__('mail_admin.notifications.restored'))->send();
    }
}
