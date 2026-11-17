<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\AuditLog;

use App\Filament\Platform\Resources\AuditLog\Pages\ListAuditLog;
use App\Filament\Platform\Support\AuditChanges;
use App\Filament\Platform\Support\ReadOnlyResource;
use App\Models\Activity;
use App\Models\Organization;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bitácora de auditoría de toda la plataforma, de solo lectura e inmutable
 * (plan 016, H-016-5). Incluye los saltos del filtro de organización (quién
 * consultó qué modelo). Los registros de acceso (`auth`) no muestran IP, hash
 * de correo ni dispositivo: lo decide `AuditChanges`. `Activity` es de plataforma.
 */
class AuditLogResource extends ReadOnlyResource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 40;

    // Sin esto Filament la publicaría en `/audit-log/audit-logs` (deriva la ruta del espacio de nombres).
    protected static ?string $slug = 'audit';

    public static function getNavigationLabel(): string
    {
        return __('platform.audit.plural');
    }

    public static function getModelLabel(): string
    {
        return __('platform.audit.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('platform.audit.plural');
    }

    /** @return Builder<Activity> */
    public static function getEloquentQuery(): Builder
    {
        return Activity::query()->with(['causer', 'organization']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('platform.audit.columns.date'))
                    ->state(fn (Activity $record): string => $record->created_at?->utc()->format('Y-m-d H:i:s').' UTC')
                    ->sortable(),
                TextColumn::make('event')
                    ->label(__('platform.audit.columns.event'))
                    ->state(fn (Activity $record): string => (string) ($record->event ?? $record->description))
                    ->weight('medium'),
                TextColumn::make('causer')
                    ->label(__('platform.audit.columns.actor'))
                    ->state(fn (Activity $record): ?string => $record->causer instanceof User ? $record->causer->name : null)
                    ->placeholder('—'),
                TextColumn::make('organization.name')
                    ->label(__('platform.audit.columns.organization'))
                    ->placeholder('—'),
                TextColumn::make('subject')
                    ->label(__('platform.audit.columns.subject'))
                    ->state(fn (Activity $record): ?string => $record->subject_type === null ? null : class_basename($record->subject_type))
                    ->placeholder('—'),
                TextColumn::make('changes')
                    ->label(__('platform.audit.columns.changes'))
                    ->state(fn (Activity $record): string => AuditChanges::describe($record))
                    ->limit(90)
                    ->tooltip(fn (Activity $record): string => AuditChanges::describe($record))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('organization_id')
                    ->label(__('platform.audit.columns.organization'))
                    ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('event')
                    ->label(__('platform.audit.columns.event'))
                    ->options(fn (): array => Activity::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event', 'event')->all()),
                SelectFilter::make('causer_id')
                    ->label(__('platform.audit.columns.actor'))
                    ->options(fn (): array => User::query()
                        ->whereIn('id', Activity::query()->whereNotNull('causer_id')->select('causer_id'))
                        ->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('created_at')
                    ->label(__('platform.audit.filters.date'))
                    ->schema([
                        DatePicker::make('from')->label(__('platform.audit.filters.from')),
                        DatePicker::make('until')->label(__('platform.audit.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $from): Builder => $q->whereDate('created_at', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $q, string $until): Builder => $q->whereDate('created_at', '<=', $until))),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('platform.audit.empty'));
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return ['index' => ListAuditLog::route('/')];
    }
}
