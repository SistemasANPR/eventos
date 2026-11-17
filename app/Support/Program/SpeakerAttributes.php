<?php

declare(strict_types=1);

namespace App\Support\Program;

use App\Support\Html\RichTextSanitizer;
use Illuminate\Http\UploadedFile;

/**
 * Prepara los datos de la ficha de un ponente (plan 021) para `CreateSpeaker` y `UpdateSpeaker`:
 * limpia espacios y convierte los vacíos en nulos ANTES de validar, y sanea la biografía y ordena
 * las redes DESPUÉS de validar. Solo toca las claves que llegan: una clave ausente no se modifica.
 */
final class SpeakerAttributes
{
    private const TEXT_FIELDS = ['first_name', 'last_name', 'email', 'company', 'job_title', 'tagline', 'country', 'bio'];

    /**
     * Antes de validar: texto sin espacios sobrantes, vacío como nulo, país en mayúsculas.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function trimmed(array $data): array
    {
        $result = [];

        foreach (self::TEXT_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $result[$field] = self::text($data[$field], $field === 'country');
            }
        }

        if (array_key_exists('social_links', $data)) {
            $result['social_links'] = is_array($data['social_links']) ? array_values(array_map(
                static fn (mixed $link): mixed => is_array($link)
                    ? ['network' => self::text($link['network'] ?? null), 'url' => self::text($link['url'] ?? null)]
                    : $link,
                $data['social_links'],
            )) : $data['social_links'];
        }

        if (array_key_exists('category_ids', $data)) {
            $result['category_ids'] = is_array($data['category_ids']) ? array_values($data['category_ids']) : $data['category_ids'];
        }

        foreach (['is_published', 'remove_photo'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $result[$flag] = $data[$flag];
            }
        }

        if (($data['photo'] ?? null) instanceof UploadedFile) {
            $result['photo'] = $data['photo'];
        }

        return $result;
    }

    /**
     * Después de validar: la biografía pasa por el saneador (sin texto visible, nulo) y las redes
     * quedan como lista `[{network, url}]`.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function forStorage(array $validated): array
    {
        if (array_key_exists('bio', $validated)) {
            $clean = is_string($validated['bio']) ? RichTextSanitizer::clean($validated['bio']) : '';
            $validated['bio'] = $clean === '' ? null : $clean;
        }

        if (array_key_exists('social_links', $validated)) {
            $validated['social_links'] = is_array($validated['social_links']) ? array_values($validated['social_links']) : [];
        }

        return $validated;
    }

    /**
     * Lo que recibe `sync()` del pivote con categorías: cada una con la organización y el evento
     * que la base exige en `speaker_speaker_category`.
     *
     * @return array<string, array{organization_id: string, event_id: string}>
     */
    public static function categoryLinks(mixed $categoryIds, string $organizationId, string $eventId): array
    {
        $links = [];

        foreach (is_array($categoryIds) ? $categoryIds : [] as $id) {
            if (is_string($id)) {
                $links[$id] = ['organization_id' => $organizationId, 'event_id' => $eventId];
            }
        }

        return $links;
    }

    private static function text(mixed $value, bool $upper = false): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return $upper ? strtoupper($value) : $value;
    }
}
