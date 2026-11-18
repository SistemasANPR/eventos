<?php

declare(strict_types=1);

use App\Actions\Calls\CreateCallForSubmissions;
use App\Enums\OrganizationRole;
use App\Enums\TimeFormat;
use App\Filament\Resources\Events\Pages\ManageEventActivities;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\CallsRelationManager;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Support\Memberships;
use Tests\Support\SubmissionTypes;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
    app()->setLocale('es');
});

/**
 * Evento con su organización en el panel, con la hora local de México y el formato pedido.
 *
 * @return array{0: Event, 1: User}
 */
function screensEvent(TimeFormat $format): array
{
    app()->setLocale('es');
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = Event::factory()->create([
        'organization_id' => $organization->id,
        'timezone' => 'America/Mexico_City',
        'time_format' => $format,
        'starts_at' => '2027-03-10 15:00:00',
        'ends_at' => '2027-03-12 23:00:00',
    ]);

    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return [$event, $admin];
}

it('CA-022-8: the event cards show the dates with the format of the event', function (TimeFormat $format, array $contains, string $absent): void {
    [$event] = screensEvent($format);

    $text = EventsTable::displayDates($event);

    expect($text)->toContain(...$contains);
    expect($text)->not->toContain($absent);
})->with([
    '12 hours' => [TimeFormat::TwelveHour, ['9:00 a. m.', '5:00 p. m.'], '09:00 h'],
    '24 hours' => [TimeFormat::TwentyFourHour, ['09:00 h', '17:00 h'], 'a. m.'],
]);

it('CA-022-8: the period of the calls follows the format of the event', function (TimeFormat $format, string $expected): void {
    [$event, $admin] = screensEvent($format);
    app(CreateCallForSubmissions::class)->handle($admin, $event, [
        'submission_type_id' => SubmissionTypes::for($event)->id,
        'title' => ['es' => 'Convocatoria de sesiones'],
        'locales' => ['es'],
        'opens_at' => '2027-01-15 09:00',
        'closes_at' => '2027-02-20 23:00',
    ]);
    CurrentOrganization::set($event->organization);

    Livewire::test(CallsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class])
        ->assertSeeText($expected);
})->with([
    '12 hours' => [TimeFormat::TwelveHour, '15/01/2027 9:00 a. m. – 20/02/2027 11:00 p. m.'],
    '24 hours' => [TimeFormat::TwentyFourHour, '15/01/2027 09:00 h – 20/02/2027 23:00 h'],
]);

it('CA-022-8: the hours of the activities follow the format of the event', function (TimeFormat $format, string $expected): void {
    [$event] = screensEvent($format);
    CurrentOrganization::run($event->organization, fn () => EventActivity::factory()->scheduled()->create([
        'event_id' => $event->id,
        'title' => 'Conferencia',
        'starts_at' => '14:30:00',
        'ends_at' => '16:00:00',
    ]));

    Livewire::test(ManageEventActivities::class, ['record' => $event->id])->assertSeeText($expected);
})->with([
    '12 hours' => [TimeFormat::TwelveHour, '2:30 p. m. – 4:00 p. m.'],
    '24 hours' => [TimeFormat::TwentyFourHour, '14:30 h – 16:00 h'],
]);
