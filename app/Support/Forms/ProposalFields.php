<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\Event;
use App\Models\Track;

/**
 * Las dos preguntas con función propia de una propuesta: su título y su tema (eje). Son campos normales del
 * formulario —se editan a la derecha, con su etiqueta y sus textos por idioma—, pero su respuesta no va en
 * `answers`: se guarda en las columnas `title` y `track_id` de la propuesta. Un campo las distingue por `role`.
 *
 * El formulario público las manda con los nombres `s.title` y `s.track_id` (`FormPreviewProps` reescribe la clave),
 * así el contrato del envío no cambia. Un formulario anterior, sin estos campos, sigue mostrando el bloque fijo
 * «Datos de la propuesta» (solo para la pregunta que le falte).
 */
final class ProposalFields
{
    public const ROLE_TITLE = 'title';

    public const ROLE_TRACK = 'track';

    public const ROLES = [self::ROLE_TITLE, self::ROLE_TRACK];

    /** Nombre con que el formulario público envía cada una (el bloque fijo usa el prefijo `s.`). */
    private const FIXED_KEYS = [self::ROLE_TITLE => 's.title', self::ROLE_TRACK => 's.track_id'];

    /** @param  array<string, mixed>  $field */
    public static function roleOf(array $field): ?string
    {
        $role = $field['role'] ?? null;

        return is_string($role) && in_array($role, self::ROLES, true) ? $role : null;
    }

    /** @param  array<string, mixed>  $field */
    public static function isRoleField(array $field): bool
    {
        return self::roleOf($field) !== null;
    }

    /** Nombre fijo con que el formulario envía el campo de esa función. */
    public static function fixedKey(string $role): string
    {
        return self::FIXED_KEYS[$role];
    }

    /**
     * Las dos preguntas con sus textos en los idiomas que ofrece el evento, listas para el editor.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaults(Event $event): array
    {
        $texts = static function (string $key) use ($event): array {
            $result = [];

            foreach ($event->locales as $locale) {
                $result[$locale] = (string) trans('public.submit.'.$key, [], $locale);
            }

            return $result;
        };

        return [
            ['key' => null, 'role' => self::ROLE_TITLE, 'type' => 'short_text', 'required' => true, 'position' => 0, 'identifiesAuthor' => false, 'label' => $texts('field_title'), 'help' => [], 'config' => ['maxLength' => 200], 'retiredAt' => null],
            ['key' => null, 'role' => self::ROLE_TRACK, 'type' => 'single_choice', 'required' => true, 'position' => 0, 'identifiesAuthor' => false, 'label' => $texts('field_track'), 'help' => [], 'config' => ['options' => []], 'retiredAt' => null],
        ];
    }

    /**
     * Antepone al formulario la pregunta de título o de tema que le falte (los formularios anteriores no las traen).
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    public static function withDefaults(array $fields, Event $event): array
    {
        $present = array_filter(array_map(static fn (array $field): ?string => self::roleOf($field), $fields));
        $missing = array_values(array_filter(self::defaults($event), static fn (array $field): bool => ! in_array($field['role'], $present, true)));

        return [...$missing, ...$fields];
    }

    /**
     * Campos tal como los dibuja el formulario público: los de título y tema con su nombre fijo y, el del tema, con los
     * ejes activos del evento como opciones.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    public static function forRenderer(array $fields, Event $event, string $locale): array
    {
        $options = null;

        return array_map(static function (array $field) use ($event, $locale, &$options): array {
            $role = self::roleOf($field);

            if ($role === null) {
                return $field;
            }

            $field['key'] = self::fixedKey($role);
            $field['required'] = true;

            if ($role === self::ROLE_TRACK) {
                $options ??= $event->tracks()->active()->orderBy('position')->get()
                    ->map(static fn (Track $track): array => ['value' => $track->id, 'label' => [$locale => $track->label($locale)]])
                    ->values()
                    ->all();
                $field['config'] = ['options' => $options];
            }

            return $field;
        }, $fields);
    }
}
