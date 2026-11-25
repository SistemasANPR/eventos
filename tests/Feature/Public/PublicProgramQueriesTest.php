<?php

declare(strict_types=1);

use App\Actions\Program\FindPublicActivity;
use App\Actions\Program\FindPublicSpeaker;
use App\Actions\Program\ListPublicProgramDay;
use App\Actions\Program\ListPublicSpeakers;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Speaker;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use App\Support\Tenancy\Exceptions\OrganizationMismatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/public-program-helpers.php';

uses(RefreshDatabase::class);

/*
 * Plan 029, F1: capa de consulta pública del programa (scopes `visibleToPublic` y Actions de
 * lectura). Visibilidad, aislamiento por organización y evento, falla sin contexto, orden,
 * paginación y consultas constantes. Las Actions reciben el evento ya acotado por el binding.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('CA-029-1: lists the days in ascending order and only the activities of the shown day ordered by start, end, title and id', function (): void {
    $event = ppEvent();
    ppActivity($event, ['title' => 'Cierre', 'starts_on' => '2027-03-12', 'ends_on' => '2027-03-12']);
    ppActivity($event, ['title' => 'Segunda', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '11:00:00', 'ends_at' => '12:00:00']);
    ppActivity($event, ['title' => 'Bravo', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '09:00:00', 'ends_at' => '10:00:00']);
    ppActivity($event, ['title' => 'Alfa', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '09:00:00', 'ends_at' => '10:00:00']);
    ppActivity($event, ['title' => 'Corta', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '09:00:00', 'ends_at' => '09:30:00']);

    $result = ppIn($event, fn () => app(ListPublicProgramDay::class)->handle($event, null));

    expect($result->days)->toBe(['2027-03-10', '2027-03-12'])
        ->and($result->day)->toBe('2027-03-10')
        ->and($result->activities->getCollection()->pluck('title')->all())->toBe(['Corta', 'Alfa', 'Bravo', 'Segunda'])
        ->and(ppDayTitles($event, '2027-03-12'))->toBe(['Cierre']);
});

it('CA-029-1: breaks ties of start, end and title by id so that pages neither repeat nor lose rows', function (): void {
    $event = ppEvent();
    $ids = collect(range(1, 5))->map(fn (): string => ppActivity($event, ['title' => 'Igual'])->id)->sort()->values()->all();

    $first = ppIn($event, fn () => app(ListPublicProgramDay::class)->handle($event, null, 1, 2));
    $second = ppIn($event, fn () => app(ListPublicProgramDay::class)->handle($event, null, 2, 2));
    $third = ppIn($event, fn () => app(ListPublicProgramDay::class)->handle($event, null, 3, 2));

    $seen = [...$first->activities->pluck('id')->all(), ...$second->activities->pluck('id')->all(), ...$third->activities->pluck('id')->all()];

    expect($seen)->toBe($ids)->and($first->activities->lastPage())->toBe(3);
});

it('CA-029-1: an activity that crosses midnight appears only under its start day', function (): void {
    $event = ppEvent();
    ppActivity($event, ['title' => 'Noche', 'starts_on' => '2027-03-20', 'ends_on' => '2027-03-21', 'starts_at' => '22:00:00', 'ends_at' => '02:00:00']);
    ppActivity($event, ['title' => 'Dia siguiente', 'starts_on' => '2027-03-21', 'ends_on' => '2027-03-21']);

    $result = ppIn($event, fn () => app(ListPublicProgramDay::class)->handle($event, '2027-03-20'));

    expect($result->days)->toBe(['2027-03-20', '2027-03-21'])
        ->and(ppDayTitles($event, '2027-03-20'))->toBe(['Noche'])
        ->and(ppDayTitles($event, '2027-03-21'))->toBe(['Dia siguiente']);
});

it('CA-029-1: a day that is not in the program, or that is not a date, falls back to the first day without failing', function (string $requested): void {
    $event = ppEvent();
    ppActivity($event, ['title' => 'Primera', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10']);
    ppActivity($event, ['title' => 'Segunda', 'starts_on' => '2027-03-11', 'ends_on' => '2027-03-11']);

    $result = ppIn($event, fn () => app(ListPublicProgramDay::class)->handle($event, $requested));

    expect($result->day)->toBe('2027-03-10')->and(ppDayTitles($event, $requested))->toBe(['Primera']);
})->with(['empty day' => '2027-03-15', 'not a date' => 'manana', 'injection' => "2027-03-10' OR '1'='1"]);

it('CA-029-1: an event with nothing visible has no days, no day and no activities', function (): void {
    $event = ppEvent();

    $result = ppIn($event, fn () => app(ListPublicProgramDay::class)->handle($event, '2027-03-10'));

    expect($result->days)->toBe([])->and($result->day)->toBeNull()->and($result->activities->total())->toBe(0);
});

it('CA-029-2: unpublished, hidden by reversal, deleted and other-event activities never show up anywhere', function (): void {
    $event = ppEvent();
    $other = ppEvent($event->organization);
    $speaker = ppSpeaker($event);

    $visible = ppActivity($event, ['title' => 'Visible']);
    $hidden = [
        'unpublished' => ppActivity($event, ['title' => 'No publicada', 'is_published' => false]),
        'reversal' => ppActivity($event, ['title' => 'Revertida', 'is_published' => true, 'hidden_by_reversal_at' => now()]),
        'deleted' => tap(ppActivity($event, ['title' => 'Borrada']), fn (EventActivity $a) => ppIn($event, fn () => $a->delete())),
        'other event' => ppActivity($other, ['title' => 'De otro evento']),
    ];
    ppAssign($visible, [$speaker]);
    foreach ($hidden as $name => $activity) {
        ppAssign($activity, [$name === 'other event' ? ppSpeaker($other) : $speaker]);
    }

    $program = ppDayTitles($event);
    $profile = ppIn($event, fn () => app(FindPublicSpeaker::class)->handle($event, $speaker->id));

    expect($program)->toBe(['Visible'])
        ->and($profile?->activities->pluck('title')->all())->toBe(['Visible']);

    foreach ($hidden as $activity) {
        expect(ppIn($event, fn () => app(FindPublicActivity::class)->handle($event, $activity->id)))->toBeNull();
    }

    expect(ppIn($event, fn () => app(FindPublicActivity::class)->handle($event, $visible->id)))->not->toBeNull();
});

it('CA-029-2: an activity hidden by reversal stays hidden even if it is still marked as published', function (): void {
    $event = ppEvent();
    $activity = ppActivity($event, ['is_published' => true, 'hidden_by_reversal_at' => now()]);

    expect(ppDayTitles($event))->toBe([])
        ->and(ppIn($event, fn () => app(FindPublicActivity::class)->handle($event, $activity->id)))->toBeNull();
});

it('CA-029-3: a published activity without date and time is not listed, not found and not in the speaker profile', function (): void {
    $event = ppEvent();
    $speaker = ppSpeaker($event);
    $unscheduled = ppActivity($event, ['title' => 'Sin programar', 'is_published' => true, 'starts_on' => null, 'ends_on' => null, 'starts_at' => null, 'ends_at' => null]);
    ppAssign($unscheduled, [$speaker]);

    $result = ppIn($event, fn () => app(ListPublicProgramDay::class)->handle($event, null));
    $profile = ppIn($event, fn () => app(FindPublicSpeaker::class)->handle($event, $speaker->id));

    expect($result->days)->toBe([])
        ->and($result->activities->total())->toBe(0)
        ->and(ppIn($event, fn () => app(FindPublicActivity::class)->handle($event, $unscheduled->id)))->toBeNull()
        ->and($profile?->activities)->toHaveCount(0);
});

it('CA-029-5: two organizations with equal titles and names never see each other and a foreign uuid is not found', function (): void {
    $eventA = ppEvent();
    $eventB = ppEvent();
    $eventA->forceFill(['slug' => 'mismo'])->save();
    $eventB->forceFill(['slug' => 'mismo'])->save();
    $sameOrgOtherEvent = ppEvent($eventA->organization);

    $activityA = ppActivity($eventA, ['title' => 'Charla igual']);
    $activityB = ppActivity($eventB, ['title' => 'Charla igual']);
    $activityOther = ppActivity($sameOrgOtherEvent, ['title' => 'Charla igual']);
    $speakerA = ppSpeaker($eventA, ['first_name' => 'Ana', 'last_name' => 'Igual']);
    $speakerB = ppSpeaker($eventB, ['first_name' => 'Ana', 'last_name' => 'Igual']);
    $speakerOther = ppSpeaker($sameOrgOtherEvent, ['first_name' => 'Ana', 'last_name' => 'Igual']);

    $listA = ppIn($eventA, fn () => app(ListPublicProgramDay::class)->handle($eventA, null));
    $speakersA = ppIn($eventA, fn () => app(ListPublicSpeakers::class)->handle($eventA));

    expect($listA->activities->pluck('id')->all())->toBe([$activityA->id])
        ->and($speakersA->pluck('id')->all())->toBe([$speakerA->id]);

    foreach ([$activityB, $activityOther] as $foreign) {
        expect(ppIn($eventA, fn () => app(FindPublicActivity::class)->handle($eventA, $foreign->id)))->toBeNull();
    }
    foreach ([$speakerB, $speakerOther] as $foreign) {
        expect(ppIn($eventA, fn () => app(FindPublicSpeaker::class)->handle($eventA, $foreign->id)))->toBeNull();
    }
});

it('CA-029-5: an event that is not of the organization in context is refused instead of read', function (): void {
    $eventA = ppEvent();
    $eventB = ppEvent();

    expect(fn () => ppIn($eventA, fn () => app(ListPublicProgramDay::class)->handle($eventB, null)))
        ->toThrow(OrganizationMismatch::class);
});

it('CA-029-6: every public query Action throws without organization in context instead of returning an empty list', function (): void {
    $event = ppEvent();
    ppActivity($event);
    ppSpeaker($event);
    CurrentOrganization::forget();

    $calls = [
        'program' => fn () => app(ListPublicProgramDay::class)->handle($event, null),
        'speakers' => fn () => app(ListPublicSpeakers::class)->handle($event),
        'speaker' => fn () => app(FindPublicSpeaker::class)->handle($event, (string) Str::uuid()),
        'activity' => fn () => app(FindPublicActivity::class)->handle($event, (string) Str::uuid()),
    ];

    foreach ($calls as $call) {
        expect($call)->toThrow(MissingOrganizationContext::class);
    }
});

it('CA-029-8: lists only visible speakers ordered by last and first name regardless of case and Spanish accents', function (): void {
    $event = ppEvent();
    $other = ppEvent($event->organization);
    foreach ([['Zoe', 'Zúñiga'], ['Ana', 'Zuniga'], ['Luis', 'Álvarez'], ['Eva', 'alvarez'], ['Ada', 'Álvarez'], ['Raúl', 'Ñandú'], ['Nora', 'Nava'], ['Omar', 'de la Cruz'], ['Rosa', 'Ávila'], ['Max', 'Avalos']] as [$first, $last]) {
        ppSpeaker($event, ['first_name' => $first, 'last_name' => $last]);
    }
    ppSpeaker($event, ['first_name' => 'Oculto', 'last_name' => 'A', 'is_published' => false]);
    ppSpeaker($event, ['first_name' => 'Revertido', 'last_name' => 'A', 'is_published' => true, 'hidden_by_reversal_at' => now()]);
    ppSpeaker($event, ['first_name' => 'Borrado', 'last_name' => 'A'])->delete();
    ppSpeaker($other, ['first_name' => 'Ajeno', 'last_name' => 'A']);

    $page = ppIn($event, fn () => app(ListPublicSpeakers::class)->handle($event));

    expect($page->getCollection()->map(fn (Speaker $s): string => "{$s->first_name} {$s->last_name}")->all())->toBe([
        'Eva alvarez', 'Ada Álvarez', 'Luis Álvarez', 'Max Avalos', 'Rosa Ávila', 'Omar de la Cruz', 'Raúl Ñandú', 'Nora Nava', 'Ana Zuniga', 'Zoe Zúñiga',
    ]);
});

it('CA-029-8: speaker pages default to 48, never exceed 100 and keep an order that neither repeats nor loses rows', function (): void {
    $event = ppEvent();
    foreach (range(1, 105) as $number) {
        ppSpeaker($event, ['first_name' => 'Persona', 'last_name' => 'Apellido']);
    }

    $default = ppIn($event, fn () => app(ListPublicSpeakers::class)->handle($event));
    $capped = ppIn($event, fn () => app(ListPublicSpeakers::class)->handle($event, 1, 500));
    $second = ppIn($event, fn () => app(ListPublicSpeakers::class)->handle($event, 2, 100));
    $tiny = ppIn($event, fn () => app(ListPublicSpeakers::class)->handle($event, 1, 0));

    expect(Speaker::PUBLIC_PER_PAGE)->toBe(48)->and(Speaker::PUBLIC_MAX_PER_PAGE)->toBe(100)
        ->and($default->count())->toBe(48)->and($default->total())->toBe(105)->and($default->lastPage())->toBe(3)
        ->and($capped->count())->toBe(100)
        ->and($second->count())->toBe(5)
        ->and($tiny->count())->toBe(1)
        ->and(array_intersect($capped->pluck('id')->all(), $second->pluck('id')->all()))->toBe([]);
});

it('CA-029-1: activity pages use the same page size limits', function (): void {
    expect(EventActivity::PUBLIC_PER_PAGE)->toBe(48)->and(EventActivity::PUBLIC_MAX_PER_PAGE)->toBe(100);
});

it('CA-029-9: the speaker profile has only visible activities in day and time order and the categories in position order', function (): void {
    $event = ppEvent();
    $speaker = ppSpeaker($event);
    ppAssign(ppActivity($event, ['title' => 'Tarde', 'starts_on' => '2027-03-11', 'ends_on' => '2027-03-11']), [$speaker]);
    ppAssign(ppActivity($event, ['title' => 'Manana', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10']), [$speaker]);
    ppAssign(ppActivity($event, ['title' => 'Oculta', 'is_published' => false]), [$speaker]);
    DB::table('speaker_speaker_category')->insert([
        ['speaker_id' => $speaker->id, 'speaker_category_id' => ppSpeakerCategory($event, 'Segunda', 2)->id, 'organization_id' => $event->organization_id, 'event_id' => $event->id],
        ['speaker_id' => $speaker->id, 'speaker_category_id' => ppSpeakerCategory($event, 'Primera', 1)->id, 'organization_id' => $event->organization_id, 'event_id' => $event->id],
    ]);

    $profile = ppIn($event, fn () => app(FindPublicSpeaker::class)->handle($event, $speaker->id));

    expect($profile?->activities->pluck('title')->all())->toBe(['Manana', 'Tarde'])
        ->and($profile->categories->pluck('name')->all())->toBe(['Primera', 'Segunda']);
});

it('CA-029-9: an unpublished, hidden, deleted, foreign or malformed speaker identifier is not found', function (): void {
    $event = ppEvent();
    $other = ppEvent($event->organization);
    $cases = [
        ppSpeaker($event, ['is_published' => false])->id,
        ppSpeaker($event, ['hidden_by_reversal_at' => now()])->id,
        tap(ppSpeaker($event), fn (Speaker $s) => $s->delete())->id,
        ppSpeaker($other)->id,
        'no-es-un-uuid',
        '1; drop table speakers',
        '',
    ];

    foreach ($cases as $id) {
        expect(ppIn($event, fn () => app(FindPublicSpeaker::class)->handle($event, $id)))->toBeNull();
    }
    expect(ppIn($event, fn () => app(FindPublicSpeaker::class)->handle($event, ppSpeaker($event)->id)))->not->toBeNull();
});

it('CA-029-10: an activity shows only its visible speakers in position order and stays visible', function (): void {
    $event = ppEvent();
    $activity = ppActivity($event, ['title' => 'Panel']);
    $first = ppSpeaker($event, ['first_name' => 'Zeta', 'last_name' => 'Primero']);
    $hidden = ppSpeaker($event, ['is_published' => false]);
    $third = ppSpeaker($event, ['first_name' => 'Alfa', 'last_name' => 'Tercero']);
    ppAssign($activity, [$third, $hidden, $first]);

    $found = ppIn($event, fn () => app(FindPublicActivity::class)->handle($event, $activity->id));
    $listed = ppIn($event, fn () => app(ListPublicProgramDay::class)->handle($event, null))->activities->firstWhere('id', $activity->id);

    expect($found?->speakers->pluck('id')->all())->toBe([$third->id, $first->id])
        ->and($listed?->speakers->pluck('id')->all())->toBe([$third->id, $first->id])
        ->and($found->title)->toBe('Panel');
});

it('CA-029-10: an activity shows its categories in position order, then by name', function (): void {
    $event = ppEvent();
    $activity = ppActivity($event);
    foreach ([ppCategory($event, 'Zeta', 1), ppCategory($event, 'Beta', 2), ppCategory($event, 'Alfa', 2)] as $category) {
        ppTag($activity, $category);
    }

    $found = ppIn($event, fn () => app(FindPublicActivity::class)->handle($event, $activity->id));

    expect($found?->categories->pluck('name')->all())->toBe(['Zeta', 'Alfa', 'Beta']);
});

it('CA-029-11: a published speaker without visible activities is listed and its profile has no activities', function (): void {
    $event = ppEvent();
    $speaker = ppSpeaker($event);
    ppAssign(ppActivity($event, ['is_published' => false]), [$speaker]);

    $page = ppIn($event, fn () => app(ListPublicSpeakers::class)->handle($event));
    $profile = ppIn($event, fn () => app(FindPublicSpeaker::class)->handle($event, $speaker->id));

    expect($page->pluck('id')->all())->toBe([$speaker->id])->and($profile?->activities)->toHaveCount(0);
});

it('CA-029-1: reports only the categories used by the activities of the shown day', function (): void {
    $event = ppEvent();
    $other = ppEvent($event->organization);
    $used = ppCategory($event, 'Usada', 2);
    $first = ppCategory($event, 'Primera', 1);
    ppCategory($event, 'Sin usar');
    $otherDay = ppCategory($event, 'De otro dia');
    ppCategory($other, 'De otro evento');
    $today = ppActivity($event, ['starts_on' => '2027-03-10', 'ends_on' => '2027-03-10']);
    $hiddenToday = ppActivity($event, ['starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'is_published' => false]);
    $tomorrow = ppActivity($event, ['starts_on' => '2027-03-11', 'ends_on' => '2027-03-11']);
    ppTag($today, $used);
    ppTag($today, $first);
    ppTag($hiddenToday, ppCategory($event, 'Solo oculta'));
    ppTag($tomorrow, $otherDay);

    $result = ppIn($event, fn () => app(ListPublicProgramDay::class)->handle($event, '2027-03-10'));

    expect($result->categories->pluck('name')->all())->toBe(['Primera', 'Usada']);
});

it('CA-029-24: the number of queries does not grow with the number of activities or speakers', function (): void {
    $small = ppEvent();
    $big = ppEvent();
    $seed = function (Event $event, int $activities, int $speakers): void {
        $people = collect(range(1, $speakers))->map(fn (): Speaker => ppSpeaker($event))->all();
        $category = ppCategory($event, 'Categoria');
        foreach (range(1, $activities) as $number) {
            $activity = ppActivity($event);
            ppAssign($activity, array_slice($people, 0, min(3, $speakers)));
            ppTag($activity, $category);
        }
        foreach ($people as $person) {
            ppAssign(ppActivity($event), [$person]);
        }
    };
    $seed($small, 2, 2);
    $seed($big, 50, 20);

    $count = function (Event $event, Closure $call): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ppIn($event, static fn (): mixed => $call());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $bigSpeaker = Speaker::query()->withoutGlobalScopes()->where('event_id', $big->id)->value('id');
    $smallSpeaker = Speaker::query()->withoutGlobalScopes()->where('event_id', $small->id)->value('id');
    $bigActivity = EventActivity::query()->withoutGlobalScopes()->where('event_id', $big->id)->value('id');
    $smallActivity = EventActivity::query()->withoutGlobalScopes()->where('event_id', $small->id)->value('id');

    $program = fn (Event $event): Closure => fn () => app(ListPublicProgramDay::class)->handle($event, null, 1, 100);
    $speakers = fn (Event $event): Closure => fn () => app(ListPublicSpeakers::class)->handle($event);
    $profile = fn (Event $event, string $id): Closure => fn () => app(FindPublicSpeaker::class)->handle($event, $id);
    $detail = fn (Event $event, string $id): Closure => fn () => app(FindPublicActivity::class)->handle($event, $id);

    expect($count($big, $program($big)))->toBe($count($small, $program($small)))
        ->and($count($big, $speakers($big)))->toBe($count($small, $speakers($small)))
        ->and($count($big, $profile($big, $bigSpeaker)))->toBe($count($small, $profile($small, $smallSpeaker)))
        ->and($count($big, $detail($big, $bigActivity)))->toBe($count($small, $detail($small, $smallActivity)));
});
