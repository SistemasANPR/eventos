<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Http\Requests\Submissions\StoreSubmissionRequest;

/**
 * Ajustes de un formulario de propuestas que no son sus preguntas (`form_definitions.settings`):
 *
 *  - qué preguntas de los participantes se muestran y cuáles son obligatorias (`participant_fields`);
 *  - si el bloque de participantes va antes o después de las demás preguntas (`participants_position`);
 *  - el título de la página de envío (`title`) y el del apartado de preguntas (`extra_section_title`),
 *    por idioma; vacíos, se usan los textos por defecto de la plataforma.
 *
 * Un formulario sin ajustes (los anteriores) se comporta como siempre: todo visible, con los mismos
 * obligatorios de antes y los participantes primero. Nombre, apellido paterno, correo y país no se pueden
 * apagar ni volver opcionales: identifican a la persona y reciben la confirmación.
 */
final class FormSettings
{
    public const POSITION_TOP = 'top';

    public const POSITION_BOTTOM = 'bottom';

    /** Preguntas de un participante, en el orden en que se muestran. */
    public const PARTICIPANT_FIELDS = [
        'first_name', 'last_name', 'second_last_name', 'email', 'alt_email', 'phone', 'company',
        'job_title', 'country', 'state', 'city', 'bio', 'photo',
    ];

    /** Siempre visibles y obligatorias. */
    public const LOCKED = ['first_name', 'last_name', 'email', 'country'];

    /** Opcionales por defecto; el resto es obligatorio. */
    private const OPTIONAL_BY_DEFAULT = ['second_last_name', 'alt_email', 'photo'];

    /**
     * Cada pregunta del participante con si se muestra y si es obligatoria.
     *
     * @param  array<string, mixed>|null  $settings
     * @return array<string, array{enabled: bool, required: bool}>
     */
    public static function participantFields(?array $settings): array
    {
        $stored = is_array($settings['participant_fields'] ?? null) ? $settings['participant_fields'] : [];
        $fields = [];

        foreach (self::PARTICIPANT_FIELDS as $key) {
            if (in_array($key, self::LOCKED, true)) {
                $fields[$key] = ['enabled' => true, 'required' => true];

                continue;
            }

            $own = is_array($stored[$key] ?? null) ? $stored[$key] : [];
            $enabled = ($own['enabled'] ?? true) !== false;

            $fields[$key] = [
                'enabled' => $enabled,
                'required' => $enabled && ($own['required'] ?? ! in_array($key, self::OPTIONAL_BY_DEFAULT, true)) === true,
            ];
        }

        return $fields;
    }

    /** @param  array<string, mixed>|null  $settings */
    public static function position(?array $settings): string
    {
        return ($settings['participants_position'] ?? null) === self::POSITION_BOTTOM ? self::POSITION_BOTTOM : self::POSITION_TOP;
    }

    /**
     * Texto propio del formulario en el idioma pedido; si falta, en el idioma base; si tampoco hay, `null`
     * (la pantalla usa entonces el texto por defecto de la plataforma).
     *
     * @param  array<string, mixed>|null  $settings
     */
    public static function text(?array $settings, string $key, string $locale, string $fallbackLocale): ?string
    {
        $texts = is_array($settings[$key] ?? null) ? $settings[$key] : [];

        foreach ([$locale, $fallbackLocale] as $candidate) {
            if (is_string($texts[$candidate] ?? null) && trim($texts[$candidate]) !== '') {
                return trim($texts[$candidate]);
            }
        }

        return null;
    }

    /**
     * Lo que se guarda: solo las claves conocidas, con las preguntas fijas forzadas y textos recortados.
     * `null` si no queda ningún ajuste distinto de los de siempre.
     *
     * @param  array<string, mixed>  $validated  `settings` ya validado
     * @return array<string, mixed>|null
     */
    public static function normalize(array $validated): ?array
    {
        $settings = [
            'participants_position' => self::position($validated),
            'participant_fields' => self::participantFields($validated),
        ];

        foreach (['title', 'extra_section_title'] as $key) {
            $texts = is_array($validated[$key] ?? null) ? array_filter(
                array_map(static fn (mixed $text): string => is_string($text) ? trim($text) : '', $validated[$key]),
                static fn (string $text): bool => $text !== '',
            ) : [];

            if ($texts !== []) {
                $settings[$key] = $texts;
            }
        }

        return $settings === ['participants_position' => self::POSITION_TOP, 'participant_fields' => self::participantFields(null)] ? null : $settings;
    }

    /**
     * Reglas de las preguntas configurables de los participantes: apagada = no se acepta; encendida y
     * obligatoria = `required`; encendida y opcional = `nullable`. Las preguntas fijas no pasan por aquí.
     *
     * @param  array<string, mixed>|null  $settings
     * @return array<string, list<mixed>>
     */
    public static function participantRules(?array $settings): array
    {
        $shapes = [
            'second_last_name' => ['string', 'max:100', 'regex:/^[^\p{Cc}]+$/u'],
            'alt_email' => ['string', 'email:rfc', 'max:254'],
            'phone' => ['string', 'regex:'.StoreSubmissionRequest::PHONE_PATTERN],
            'company' => ['string', 'max:160'],
            'job_title' => ['string', 'max:120'],
            'state' => ['string', 'max:120'],
            'city' => ['string', 'max:120'],
            'bio' => ['string', 'max:2000'],
            'photo_file_id' => ['uuid'],
        ];
        $config = self::participantFields($settings);
        $rules = [];

        foreach ($shapes as $key => $shape) {
            $field = $config[$key === 'photo_file_id' ? 'photo' : $key];
            $presence = ! $field['enabled'] ? ['prohibited'] : [$field['required'] ? 'required' : 'nullable'];

            $rules["participants.*.{$key}"] = $field['enabled'] ? [...$presence, ...$shape] : $presence;
        }

        return $rules;
    }
}
