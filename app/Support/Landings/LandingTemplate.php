<?php

declare(strict_types=1);

namespace App\Support\Landings;

use App\Enums\LandingBlockType;
use App\Models\Event;
use Illuminate\Support\Str;
use LogicException;

/**
 * Plantilla predeterminada de la landing de un evento (plan 022, DA-3, DA-6): portada, datos del
 * evento, convocatorias y ejes. La portada toma el nombre del evento en cada uno de sus idiomas; los
 * demás bloques no guardan copia de datos. Cada llamada genera identificadores nuevos.
 */
final class LandingTemplate
{
    /** @return list<array<string, mixed>> */
    public static function blocks(Event $event): array
    {
        $title = [];

        foreach ($event->offeredLocales() as $locale) {
            $name = (string) $event->getTranslation('name', $locale, false);

            if ($name !== '') {
                $title[$locale] = Str::limit($name, 160, '');
            }
        }

        // Sin un nombre en el idioma base no hay título válido: se usa el que tenga el evento.
        $title[$event->default_locale] ??= Str::limit((string) $event->getTranslation('name', $event->default_locale, true), 160, '');

        return [
            self::block($event, LandingBlockType::Hero, ['title' => $title]),
            self::block($event, LandingBlockType::EventInfo, []),
            self::block($event, LandingBlockType::Calls, []),
            self::block($event, LandingBlockType::Tracks, []),
        ];
    }

    /**
     * Plantilla de la landing de una convocatoria (plan 033): portada con estado y cuenta regresiva, fechas, ejes,
     * requisitos, pasos, preguntas y anuncio de otras convocatorias. Todo lo que cambia con la convocatoria (título,
     * fechas, ejes, formulario, otras convocatorias) se lee de ella al mostrar la página; aquí solo van los textos
     * de relleno, genéricos y traducidos, que el organizador reescribe a su gusto. No inventa datos.
     *
     * @return list<array<string, mixed>>
     */
    public static function callBlocks(Event $event): array
    {
        $text = static function (string $key) use ($event): array {
            $out = [];

            foreach ($event->offeredLocales() as $locale) {
                $out[$locale] = (string) __("landings.call_template.{$key}", [], $locale);
            }

            return $out;
        };

        $steps = [];

        foreach (['prepare' => 'doc', 'send' => 'enviar', 'review' => 'lupa', 'results' => 'premio', 'present' => 'micro'] as $key => $icon) {
            $steps[] = ['icon' => $icon, 'title' => $text("step_{$key}_title"), 'text' => $text("step_{$key}_text")];
        }

        return [
            self::block($event, LandingBlockType::CallHero, []),
            self::block($event, LandingBlockType::CallDates, []),
            self::block($event, LandingBlockType::CallAxes, ['title' => $text('axes_title'), 'intro' => $text('axes_intro')]),
            self::block($event, LandingBlockType::CallRequirements, [
                'title' => $text('requirements_title'),
                'intro' => $text('requirements_intro'),
                'items' => [['text' => $text('requirement_one')], ['text' => $text('requirement_two')]],
                'buttonLabel' => $text('requirements_button'),
            ]),
            self::block($event, LandingBlockType::CallSteps, ['title' => $text('steps_title'), 'items' => $steps]),
            self::block($event, LandingBlockType::Faq, [
                'title' => $text('faq_title'),
                'items' => [
                    ['question' => $text('faq_one_question'), 'answer' => $text('faq_one_answer')],
                    ['question' => $text('faq_two_question'), 'answer' => $text('faq_two_answer')],
                ],
            ]),
            self::block($event, LandingBlockType::CallPromos, ['buttonLabel' => $text('promo_button')]),
        ];
    }

    /**
     * Cada bloque pasa por las reglas de su tipo: la plantilla nunca produce algo que el editor rechazaría.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function block(Event $event, LandingBlockType $type, array $content): array
    {
        $checked = LandingBlockRules::check($type, $content, $event->offeredLocales(), $event->default_locale);

        if ($checked['errors'] !== []) {
            throw new LogicException('La plantilla de la landing no cumple las reglas de «'.$type->value.'»: '.json_encode($checked['errors']));
        }

        return ['id' => (string) Str::uuid(), 'type' => $type->value, ...$checked['content']];
    }
}
