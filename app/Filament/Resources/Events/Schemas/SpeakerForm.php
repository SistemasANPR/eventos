<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Schemas;

use App\Enums\ProgramSocialNetwork;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Speaker;
use App\Models\SpeakerCategory;
use App\Support\Files\MediaThumbnail;
use App\Support\Forms\Countries;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Image;
use Illuminate\Database\Eloquent\Model;
use Locale;

/**
 * Formulario de la ficha de un ponente en el panel (plan 021, F2). Solo presenta y limita lo que el
 * servidor volverá a validar: las reglas viven en `StoreSpeakerRequest` y las comprueba la Action.
 * La biografía usa solo las herramientas que la lista blanca de `RichTextSanitizer` conserva.
 */
final class SpeakerForm
{
    /** Redes en el orden de la lista. */
    private const NETWORKS = ['linkedin', 'x', 'instagram', 'facebook', 'youtube', 'tiktok', 'website'];

    /** @return array<int, mixed> */
    public static function components(Event $event): array
    {
        return [
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                TextInput::make('first_name')->label(__('program.speakers.fields.first_name'))->required()->maxLength(100),
                TextInput::make('last_name')->label(__('program.speakers.fields.last_name'))->required()->maxLength(100),
                TextInput::make('company')->label(__('program.speakers.fields.company'))->maxLength(160),
                TextInput::make('job_title')->label(__('program.speakers.fields.job_title'))->maxLength(160),
                TextInput::make('email')->label(__('program.speakers.fields.email'))->email()->maxLength(254),
                Select::make('country')
                    ->label(__('program.speakers.fields.country'))
                    ->options(self::countryOptions())
                    ->searchable(),
            ]),
            TextInput::make('tagline')
                ->label(__('program.speakers.fields.tagline'))
                ->helperText(__('program.speakers.fields.tagline_help'))
                ->maxLength(160),
            RichEditor::make('bio')
                ->label(__('program.speakers.fields.bio'))
                ->toolbarButtons([
                    ['bold', 'italic', 'link'],
                    ['h2', 'h3', 'blockquote'],
                    ['bulletList', 'orderedList'],
                    ['undo', 'redo'],
                ]),
            Image::make(
                fn (?Model $record): string => $record instanceof Speaker ? (string) MediaThumbnail::dataUri($record->photo_path, 160) : '',
                fn (?Model $record): string => $record instanceof Speaker ? $record->full_name : '',
            )
                ->imageSize(80)
                ->visible(fn (?Model $record): bool => $record instanceof Speaker && MediaThumbnail::dataUri($record->photo_path, 160) !== null),
            // No se guarda en el disco del formulario: la sube `StoreProgramImage`, que decide por el contenido.
            FileUpload::make('photo')
                ->label(__('program.speakers.fields.photo'))
                ->helperText(__('program.speakers.fields.photo_help'))
                ->image()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(5 * 1024)
                ->storeFiles(false),
            Toggle::make('remove_photo')
                ->label(__('program.speakers.fields.remove_photo'))
                ->visible(fn (?Model $record): bool => $record instanceof Speaker && $record->photo_path !== null),
            Repeater::make('social_links')
                ->label(__('program.speakers.fields.social_links'))
                ->schema([
                    Select::make('network')
                        ->label(__('program.speakers.fields.social_network'))
                        ->options(self::networkOptions())
                        ->required(),
                    TextInput::make('url')
                        ->label(__('program.speakers.fields.social_url'))
                        ->url()
                        ->required()
                        ->maxLength(255),
                ])
                ->columns(['default' => 1, 'sm' => 2])
                ->maxItems(count(ProgramSocialNetwork::cases()))
                ->addActionLabel(__('program.speakers.fields.add_social_link'))
                ->defaultItems(0),
            Select::make('category_ids')
                ->label(__('program.speakers.fields.categories'))
                ->options(fn (): array => self::categoryOptions($event))
                ->multiple()
                ->preload(),
            // Asignar cambia las actividades: `SyncSpeakerActivities` exige también `activities.manage` (hoy lo tienen
            // los mismos roles que `speakers.manage`) y un permiso ausente se propaga como 403, no se oculta.
            Select::make('activity_ids')
                ->label(__('program.speakers.fields.activities'))
                ->options(fn (): array => self::activityOptions($event))
                ->multiple()
                ->preload()
                ->searchable(),
            Toggle::make('is_published')
                ->label(__('program.speakers.fields.is_published'))
                ->default(true),
        ];
    }

    /** @return array<string, string> */
    public static function categoryOptions(Event $event): array
    {
        return SpeakerCategory::query()
            ->where('event_id', $event->id)
            ->orderBy('position')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, string> actividades DEL EVENTO (id => título) */
    public static function activityOptions(Event $event): array
    {
        return EventActivity::query()
            ->where('event_id', $event->id)
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    /** @return array<string, string> */
    private static function networkOptions(): array
    {
        $options = [];

        foreach (self::NETWORKS as $network) {
            $options[$network] = __("program.speakers.networks.{$network}");
        }

        return $options;
    }

    /** @return array<string, string> código ISO => nombre en el idioma de la interfaz */
    private static function countryOptions(): array
    {
        $options = [];

        foreach (Countries::CODES as $code) {
            $name = Locale::getDisplayRegion("-{$code}", app()->getLocale());
            $options[$code] = $name !== '' ? $name : $code;
        }

        asort($options);

        return $options;
    }
}
