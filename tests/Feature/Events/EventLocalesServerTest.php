<?php

declare(strict_types=1);

use App\Actions\Calls\CreateCallForSubmissions;
use App\Actions\Calls\CreateSubmissionType;
use App\Actions\Calls\CreateTrack;
use App\Actions\Calls\RenameSubmissionType;
use App\Actions\Calls\UpdateTrack;
use App\Actions\Events\UpdateEvent;
use App\Actions\Forms\CreateFormDefinition;
use App\Actions\Forms\UpdateFormDefinition;
use App\Actions\Privacy\CreatePrivacyNoticeDraft;
use App\Actions\Privacy\PublishPrivacyNotice;
use App\Actions\Privacy\UpdatePrivacyNoticeDraft;
use App\Actions\Reviews\SaveRubric;
use App\Exceptions\Domain\EventLocaleInUse;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\Rubric;
use App\Models\SubmissionType;
use App\Models\Track;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\SubmissionTypes;

/*
 * plan 025 F2: validación en el servidor con los idiomas del evento, descarte de claves de idiomas
 * no ofrecidos, conservación de los textos de un idioma quitado y confirmación al quitar un idioma en uso.
 */
uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/**
 * @param  list<string>  $locales
 * @return array{0: Event, 1: User} evento con los idiomas indicados (por defecto es) y su administrador, con la organización en contexto
 */
function localeScenario(array $locales = ['es']): array
{
    [$organization, $event, , $admin] = formScenario();
    $event->forceFill(['locales' => $locales, 'default_locale' => 'es'])->save();
    CurrentOrganization::set($organization);

    return [$event->refresh(), $admin];
}

/**
 * @param  list<string>  $locales
 * @return array<string, mixed>
 */
function localeEventData(Event $event, array $locales): array
{
    return [
        'name' => $event->getTranslations('name'),
        'slug' => $event->slug,
        'type' => 'congress',
        'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-12 18:00',
        'timezone' => 'America/Mexico_City',
        'locales' => $locales,
        'default_locale' => 'es',
        'currency' => 'MXN',
    ];
}

/**
 * Lo que cada pantalla manda con un idioma que el sistema conoce pero el evento no ofrece (o con uno desconocido).
 *
 * @return array<string, Closure(Event, User, string): mixed>
 */
function localeEntryPoints(): array
{
    return [
        'track' => fn (Event $event, User $admin, string $extra): mixed => app(CreateTrack::class)->handle($admin, $event, [
            'name' => ['es' => 'Eje', $extra => 'Track'],
            'description' => ['es' => 'Descripción', $extra => 'Description'],
        ]),
        'submission type' => fn (Event $event, User $admin, string $extra): mixed => app(CreateSubmissionType::class)->handle($admin, $event, [
            'name' => ['es' => 'Taller', $extra => 'Workshop'],
        ]),
        'rubric' => fn (Event $event, User $admin, string $extra): mixed => app(SaveRubric::class)->handle($admin, $event, [
            'submission_type_id' => SubmissionTypes::for($event)->id,
            'min_reviewers' => 2,
            'discrepancy_threshold' => 2,
            'criteria' => [['label' => ['es' => 'Relevancia', $extra => 'Relevance'], 'kind' => 'scale', 'scale_min' => 1, 'scale_max' => 5, 'weight' => 1]],
        ]),
        'privacy notice' => fn (Event $event, User $admin, string $extra): mixed => app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, [
            'es' => '<p>Aviso</p>', $extra => '<p>Notice</p>',
        ]),
        'form field' => fn (Event $event, User $admin, string $extra): mixed => app(CreateFormDefinition::class)->handle($admin, $event, [
            'name' => 'Formulario',
            'fields' => [formField('single_choice', [
                'label' => ['es' => 'Eje', $extra => 'Track'],
                'help' => ['es' => 'Ayuda', $extra => 'Help'],
                'config' => ['options' => [['label' => ['es' => 'Sí', $extra => 'Yes']], ['label' => ['es' => 'No', $extra => 'No']]]],
            ])],
        ]),
    ];
}

/**
 * Todos los idiomas de lo guardado por la entrada.
 *
 * @return list<string>
 */
function localesStored(mixed $saved): array
{
    $texts = match (true) {
        $saved instanceof Track => [$saved->getTranslations('name'), $saved->getTranslations('description')],
        $saved instanceof SubmissionType => [$saved->getTranslations('name')],
        $saved instanceof Rubric => [$saved->criteria->first()->getTranslations('label')],
        $saved instanceof PrivacyNotice => [$saved->getTranslations('body')],
        $saved instanceof FormDefinition => [
            $saved->fields[0]['label'], $saved->fields[0]['help'], $saved->fields[0]['config']['options'][0]['label'],
        ],
        default => throw new LogicException('Entrada desconocida.'),
    };

    return collect($texts)->flatMap(fn (array $text): array => array_keys($text))->unique()->values()->all();
}

it('CA-025-3: a text in a language the event does not offer is discarded and only Spanish is kept', function (string $entry): void {
    [$event, $admin] = localeScenario(['es']);

    $saved = localeEntryPoints()[$entry]($event, $admin, 'en');

    expect(localesStored($saved))->toBe(['es']);
})->with(['track', 'submission type', 'rubric', 'privacy notice', 'form field']);

it('CA-025-3: the offered languages are kept and the others are discarded', function (): void {
    [$event, $admin] = localeScenario(['es', 'en']);

    $track = app(CreateTrack::class)->handle($admin, $event, ['name' => ['es' => 'Eje', 'en' => 'Track', 'pt' => 'Eixo']]);

    expect($track->getTranslations('name'))->toBe(['es' => 'Eje', 'en' => 'Track']);
});

it('CA-025-4: a language key that is not es, en or pt is rejected', function (string $entry): void {
    [$event, $admin] = localeScenario(['es', 'en']);

    expect(fn () => localeEntryPoints()[$entry]($event, $admin, 'fr'))->toThrow(ValidationException::class);
})->with(['track', 'submission type', 'rubric', 'privacy notice', 'form field']);

it('CA-025-4: the form of an event only in Spanish asks for the Spanish text only', function (): void {
    [$event, $admin] = localeScenario(['es']);

    expect(fn () => app(CreateTrack::class)->handle($admin, $event, ['name' => ['en' => 'Track']]))->toThrow(ValidationException::class);
});

it('CA-025-7 and CA-025-8: the texts of a language the event stopped offering are kept and come back when it is offered again', function (): void {
    [$event, $admin] = localeScenario(['es', 'en']);
    $track = app(CreateTrack::class)->handle($admin, $event, ['name' => ['es' => 'Eje', 'en' => 'Track'], 'description' => ['es' => 'Desc', 'en' => 'Description']]);
    $type = app(CreateSubmissionType::class)->handle($admin, $event, ['name' => ['es' => 'Taller', 'en' => 'Workshop']]);
    $notice = app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Aviso</p>', 'en' => '<p>Notice</p>']);

    $event->forceFill(['locales' => ['es']])->save();
    $event->refresh();

    // El panel ya no trae el inglés en el estado del formulario: guardar no debe perderlo.
    app(UpdateTrack::class)->handle($admin, $track, ['name' => ['es' => 'Eje nuevo'], 'description' => ['es' => 'Otra']]);
    app(RenameSubmissionType::class)->handle($admin, $type, ['name' => ['es' => 'Taller nuevo']]);
    app(UpdatePrivacyNoticeDraft::class)->handle($admin, $notice, ['es' => '<p>Aviso nuevo</p>']);

    expect($track->fresh()->getTranslations('name'))->toEqual(['es' => 'Eje nuevo', 'en' => 'Track'])
        ->and($track->fresh()->getTranslations('description'))->toEqual(['es' => 'Otra', 'en' => 'Description'])
        ->and($type->fresh()->getTranslations('name'))->toEqual(['es' => 'Taller nuevo', 'en' => 'Workshop'])
        ->and($notice->fresh()->getTranslations('body'))->toEqual(['es' => '<p>Aviso nuevo</p>', 'en' => '<p>Notice</p>']);

    // Un idioma ofrecido se controla por completo desde el cliente: vaciarlo lo borra.
    $event->forceFill(['locales' => ['es', 'en']])->save();
    app(UpdateTrack::class)->handle($admin, $track, ['name' => ['es' => 'Eje nuevo', 'en' => 'Track again']]);

    expect($track->fresh()->getTranslation('name', 'en'))->toBe('Track again');
});

it('CA-025-7 and CA-025-8: the texts of a form field and its options in a removed language survive a save from the panel', function (): void {
    [$event, $admin] = localeScenario(['es', 'en']);
    $form = app(CreateFormDefinition::class)->handle($admin, $event, ['name' => 'Formulario', 'fields' => [formField('single_choice')]]);
    $event->forceFill(['locales' => ['es']])->save();

    // Lo que manda el panel: los mismos campos pero solo con los idiomas que el evento ofrece.
    $panelFields = array_map(static function (array $field): array {
        $field['label'] = ['es' => $field['label']['es'].' editado'];
        $field['help'] = array_intersect_key($field['help'], ['es' => true]);
        $field['config']['options'] = array_map(static fn (array $option): array => [...$option, 'label' => ['es' => $option['label']['es']]], $field['config']['options']);

        return $field;
    }, $form->fields);

    $saved = app(UpdateFormDefinition::class)->handle($admin, $form, ['name' => 'Formulario', 'fields' => $panelFields]);

    expect($saved->fields[0]['label'])->toEqual(['es' => 'Campo single_choice editado', 'en' => 'Field single_choice'])
        ->and($saved->fields[0]['config']['options'][0]['label'])->toEqual(['es' => 'Sí', 'en' => 'Sí (en)']);
});

it('CA-025-6: removing a language used by a call without confirming saves nothing and lists what is affected', function (): void {
    [$event, $admin] = localeScenario(['es', 'en']);
    app(CreateCallForSubmissions::class)->handle($admin, $event, [
        'submission_type_id' => SubmissionTypes::for($event)->id,
        'title' => ['es' => 'Convocatoria de sesiones', 'en' => 'Call for sessions'],
        'locales' => ['es', 'en'],
        'opens_at' => '2027-01-10 09:00',
        'closes_at' => '2027-02-15 23:00',
    ]);

    try {
        app(UpdateEvent::class)->handle($admin, $event, localeEventData($event, ['es']));
        $this->fail('Se esperaba EventLocaleInUse.');
    } catch (EventLocaleInUse $exception) {
        expect($exception->getMessage())->toContain('Convocatoria de sesiones')->toContain('en')->toContain(EventLocaleInUse::CODE)
            ->and($exception->removedLocales)->toBe(['en']);
    }

    expect($event->fresh()->locales)->toBe(['es', 'en']);
});

it('CA-025-6: removing a language that the published privacy notice uses needs confirmation too', function (): void {
    [$event, $admin] = localeScenario(['es', 'en']);
    app(PublishPrivacyNotice::class)->handle($admin, app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Aviso</p>', 'en' => '<p>Notice</p>']));

    expect(fn () => app(UpdateEvent::class)->handle($admin, $event, localeEventData($event, ['es'])))
        ->toThrow(EventLocaleInUse::class, 'aviso de privacidad v1');
});

it('CA-025-6: removing a language nothing uses needs no confirmation', function (): void {
    [$event, $admin] = localeScenario(['es', 'en']);

    $updated = app(UpdateEvent::class)->handle($admin, $event, localeEventData($event, ['es']));

    expect($updated->locales)->toBe(['es']);
});

it('CA-025-7: confirming saves the event, keeps the texts and audits the removed and added languages', function (): void {
    [$event, $admin] = localeScenario(['es', 'en']);
    $track = app(CreateTrack::class)->handle($admin, $event, ['name' => ['es' => 'Eje', 'en' => 'Track']]);
    app(CreateCallForSubmissions::class)->handle($admin, $event, [
        'submission_type_id' => SubmissionTypes::for($event)->id,
        'title' => ['es' => 'Convocatoria', 'en' => 'Call'],
        'locales' => ['es', 'en'],
        'opens_at' => '2027-01-10 09:00',
        'closes_at' => '2027-02-15 23:00',
    ]);

    $updated = app(UpdateEvent::class)->handle($admin, $event, localeEventData($event, ['es', 'pt']), confirmLocaleRemoval: true);

    $entry = Activity::query()->where('event', 'event_updated')->latest('id')->firstOrFail();

    expect($updated->locales)->toBe(['es', 'pt'])
        ->and($track->fresh()->getTranslation('name', 'en'))->toBe('Track')
        ->and($entry->properties->all())->toMatchArray(['removed_locales' => ['en'], 'added_locales' => ['pt']]);
});

it('CA-025-9: the default language cannot be removed, even with confirmation', function (): void {
    [$event, $admin] = localeScenario(['es', 'en']);

    expect(fn () => app(UpdateEvent::class)->handle($admin, $event, [...localeEventData($event, ['en']), 'default_locale' => 'es'], confirmLocaleRemoval: true))
        ->toThrow(ValidationException::class);

    expect($event->fresh()->locales)->toBe(['es', 'en']);
});

it('CA-025-6: the edit screen warns what is affected and asks for the confirmation before removing a language in use', function (): void {
    [$event, $admin] = localeScenario(['es', 'en']);
    app(CreateCallForSubmissions::class)->handle($admin, $event, [
        'submission_type_id' => SubmissionTypes::for($event)->id,
        'title' => ['es' => 'Convocatoria de sesiones', 'en' => 'Call for sessions'],
        'locales' => ['es', 'en'],
        'opens_at' => '2027-01-10 09:00',
        'closes_at' => '2027-02-15 23:00',
    ]);
    $organization = Organization::query()->findOrFail($event->organization_id);
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->fillForm(['locales' => ['es']])
        ->call('save')
        ->assertHasFormErrors(['locales']);

    expect($event->fresh()->locales)->toBe(['es', 'en']);

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->fillForm(['locales' => ['es'], 'confirm_locale_removal' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->fresh()->locales)->toBe(['es']);
});
