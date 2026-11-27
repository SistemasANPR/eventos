<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Extras\ExtraFields;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Http\UploadedFile;

/**
 * Campo «Datos adicionales» de los formularios de hospedajes y patrocinadores (plan 030, DA-7): lista de datos con
 * título libre y tipo texto o foto, hasta `ExtraFields::MAX`. Las fotos no se guardan en el disco del formulario: las sube
 * la Action por su contenido. Al editar, un dato con foto ya guardada la conserva si no se sube otra.
 */
final class ExtraFieldsInput
{
    public static function make(): Repeater
    {
        return Repeater::make('extra_fields')
            ->label(__('extra_fields.section'))
            ->helperText(__('extra_fields.help', ['max' => ExtraFields::MAX]))
            ->addActionLabel(__('extra_fields.add'))
            ->maxItems(ExtraFields::MAX)
            ->collapsible()
            ->itemLabel(function (array $state): string {
                $label = is_string($state['label'] ?? null) ? trim($state['label']) : '';

                return $label !== '' ? $label : __('extra_fields.untitled');
            })
            ->default([])
            ->schema([
                Hidden::make('id'),
                Hidden::make('has_image')->dehydrated(false),
                TextInput::make('label')->label(__('extra_fields.label'))->required()->maxLength(120),
                Select::make('type')
                    ->label(__('extra_fields.type'))
                    ->options([
                        ExtraFields::TYPE_TEXT => __('extra_fields.type_text'),
                        ExtraFields::TYPE_IMAGE => __('extra_fields.type_image'),
                    ])
                    ->default(ExtraFields::TYPE_TEXT)
                    ->required()
                    ->native(false)
                    ->live(),
                TextInput::make('value')
                    ->label(__('extra_fields.value'))
                    ->maxLength(500)
                    ->required(fn (Get $get): bool => $get('type') === ExtraFields::TYPE_TEXT)
                    ->visible(fn (Get $get): bool => $get('type') === ExtraFields::TYPE_TEXT),
                // No se guarda en el disco del formulario: la sube `StoreProgramImage`, que decide por el contenido.
                FileUpload::make('image')
                    ->label(__('extra_fields.image'))
                    ->helperText(fn (Get $get): string => $get('has_image') === true ? __('extra_fields.image_current') : __('extra_fields.image_help'))
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(2 * 1024)
                    ->storeFiles(false)
                    ->visible(fn (Get $get): bool => $get('type') === ExtraFields::TYPE_IMAGE),
            ]);
    }

    /**
     * Lo guardado, como estado del Repeater al editar (la ruta de la foto no sale: solo si ya tiene una).
     *
     * @param  array<int, mixed>|null  $stored
     * @return list<array<string, mixed>>
     */
    public static function fill(?array $stored): array
    {
        $items = [];

        foreach ($stored ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = [
                'id' => $item['id'] ?? null,
                'label' => $item['label'] ?? '',
                'type' => $item['type'] ?? ExtraFields::TYPE_TEXT,
                'value' => $item['value'] ?? null,
                'has_image' => is_string($item['path'] ?? null),
            ];
        }

        return $items;
    }

    /**
     * Estado del formulario → entrada de la Action: lista sin claves, con la foto subida (archivo temporal) tal cual.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function input(array $data): array
    {
        $raw = $data['extra_fields'] ?? [];
        $items = [];

        foreach (is_array($raw) ? array_values($raw) : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $image = $item['image'] ?? null;
            unset($item['image'], $item['has_image']);

            foreach (is_array($image) ? $image : [$image] as $file) {
                if ($file instanceof UploadedFile) {
                    $item['image'] = $file;
                    break;
                }
            }

            $items[] = $item;
        }

        $data['extra_fields'] = $items;

        return $data;
    }
}
