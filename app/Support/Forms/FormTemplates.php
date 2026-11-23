<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\Event;
use App\Support\Events\OfferedLocaleTexts;
use InvalidArgumentException;

/**
 * Plantillas de formulario del sistema (plan 005, H-005-5): sesión, panel y póster.
 * Viven en código, como el catálogo de notificaciones (ADR-0009): son de solo lectura,
 * viajan versionadas con la aplicación y no necesitan una tabla global. Una plantilla
 * nunca se edita; `CreateFormFromTemplate` la copia a un formulario propio del evento.
 * Cada texto va en los tres idiomas de la plataforma: el evento puede tener cualquiera
 * como idioma base y el campo exige su etiqueta en ese idioma. `definitionFor` los reduce
 * a los idiomas del evento.
 */
final class FormTemplates
{
    public const SESSION = 'session';

    public const PANEL = 'panel';

    public const POSTER = 'poster';

    /** @return list<string> */
    public static function names(): array
    {
        return [self::SESSION, self::PANEL, self::POSTER];
    }

    /**
     * La plantilla con sus textos solo en los idiomas que ofrece el evento (plan 026, DA-2, CA-026-5).
     *
     * @return array{name: array<string, string>, fields: list<array<string, mixed>>}
     */
    public static function definitionFor(string $template, Event $event): array
    {
        $definition = self::definition($template);

        return [
            'name' => OfferedLocaleTexts::for($definition['name'], $event),
            'fields' => array_map(static fn (array $field): array => self::restrict($field, $event), $definition['fields']),
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private static function restrict(array $field, Event $event): array
    {
        foreach (['label', 'help'] as $key) {
            if (isset($field[$key])) {
                $field[$key] = OfferedLocaleTexts::for($field[$key], $event);
            }
        }

        foreach ($field['config']['options'] ?? [] as $index => $option) {
            $field['config']['options'][$index]['label'] = OfferedLocaleTexts::for($option['label'], $event);
        }

        return $field;
    }

    /**
     * Nombre de la plantilla y campos listos para `FormDefinitionRules` (sin claves: las genera el servidor).
     *
     * @return array{name: array<string, string>, fields: list<array<string, mixed>>}
     */
    public static function definition(string $template): array
    {
        return match ($template) {
            self::SESSION => [
                'name' => ['es' => 'Formulario de sesión', 'en' => 'Session form', 'pt' => 'Formulário de sessão'],
                'fields' => [
                    self::title(),
                    self::longText('Descripción', 'Description', 'Descrição', 2000),
                    self::longText('Objetivos', 'Objectives', 'Objetivos', 1500),
                    self::longText('Justificación', 'Rationale', 'Justificativa', 1500, required: false),
                    self::language(),
                    self::keywords(),
                    self::image(),
                ],
            ],
            self::PANEL => [
                'name' => ['es' => 'Formulario de panel', 'en' => 'Panel form', 'pt' => 'Formulário de painel'],
                'fields' => [
                    self::title(),
                    self::longText('Descripción', 'Description', 'Descrição', 2000),
                    self::longText('Objetivos', 'Objectives', 'Objetivos', 1500),
                    [
                        'type' => 'number',
                        'required' => true,
                        'label' => ['es' => 'Número de panelistas', 'en' => 'Number of panelists', 'pt' => 'Número de painelistas'],
                        'config' => ['min' => 2, 'max' => 8],
                    ],
                    [
                        'type' => 'short_text',
                        'required' => false,
                        'label' => ['es' => 'Moderador o moderadora', 'en' => 'Moderator', 'pt' => 'Moderador ou moderadora'],
                        'config' => ['maxLength' => 200],
                    ],
                    self::language(),
                    self::image(),
                ],
            ],
            self::POSTER => [
                'name' => ['es' => 'Formulario de póster', 'en' => 'Poster form', 'pt' => 'Formulário de pôster'],
                'fields' => [
                    self::title(),
                    self::longText('Resumen', 'Abstract', 'Resumo', 1500),
                    self::keywords(),
                    [
                        'type' => 'short_text',
                        'required' => true,
                        // Puede delatar a la autoría: se oculta en la revisión ciega (DA-7).
                        'identifiesAuthor' => true,
                        'label' => ['es' => 'Institución', 'en' => 'Institution', 'pt' => 'Instituição'],
                        'config' => ['maxLength' => 200],
                    ],
                    [
                        'type' => 'file',
                        'required' => false,
                        'label' => ['es' => 'Archivo del póster', 'en' => 'Poster file', 'pt' => 'Arquivo do pôster'],
                        'config' => ['accept' => ['pdf', 'png', 'jpg'], 'maxFiles' => 1],
                    ],
                    self::image(),
                ],
            ],
            default => throw new InvalidArgumentException("Plantilla de formulario desconocida: {$template}."),
        };
    }

    /**
     * Imagen opcional de la propuesta (JPG o PNG), presente en todas las plantillas; se ve en el detalle de la propuesta.
     *
     * @return array<string, mixed>
     */
    private static function image(): array
    {
        return [
            'type' => 'file',
            'required' => false,
            'label' => ['es' => 'Imagen', 'en' => 'Image', 'pt' => 'Imagem'],
            'help' => ['es' => 'JPG o PNG.', 'en' => 'JPG or PNG.', 'pt' => 'JPG ou PNG.'],
            'config' => ['accept' => ['jpg', 'png'], 'maxFiles' => 1],
        ];
    }

    /** @return array<string, mixed> */
    private static function title(): array
    {
        return [
            'type' => 'short_text',
            'required' => true,
            'label' => ['es' => 'Título', 'en' => 'Title', 'pt' => 'Título'],
            'config' => ['maxLength' => 200],
        ];
    }

    /** @return array<string, mixed> */
    private static function longText(string $es, string $en, string $pt, int $maxLength, bool $required = true): array
    {
        return [
            'type' => 'long_text',
            'required' => $required,
            'label' => ['es' => $es, 'en' => $en, 'pt' => $pt],
            'config' => ['maxLength' => $maxLength],
        ];
    }

    /** @return array<string, mixed> */
    private static function keywords(): array
    {
        return [
            'type' => 'short_text',
            'required' => false,
            'label' => ['es' => 'Palabras clave', 'en' => 'Keywords', 'pt' => 'Palavras-chave'],
            'help' => ['es' => 'Separadas por comas.', 'en' => 'Separated by commas.', 'pt' => 'Separadas por vírgulas.'],
            'config' => ['maxLength' => 200],
        ];
    }

    /** @return array<string, mixed> */
    private static function language(): array
    {
        return [
            'type' => 'single_choice',
            'required' => true,
            'label' => ['es' => 'Idioma de la presentación', 'en' => 'Language of the presentation', 'pt' => 'Idioma da apresentação'],
            'config' => ['options' => [
                ['label' => ['es' => 'Español', 'en' => 'Spanish', 'pt' => 'Espanhol']],
                ['label' => ['es' => 'Inglés', 'en' => 'English', 'pt' => 'Inglês']],
                ['label' => ['es' => 'Portugués', 'en' => 'Portuguese', 'pt' => 'Português']],
            ]],
        ];
    }
}
