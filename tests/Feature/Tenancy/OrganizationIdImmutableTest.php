<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantModels;

uses(RefreshDatabase::class);

/*
 * Segunda barrera de CA-001-4: el modelo ya lo impide, pero un UPDATE por
 * builder o por SQL no pasa por los eventos de Eloquent. La base lo rechaza.
 */

it('CA-001-4: the database refuses to move a row to another organization with a raw update', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $contact = Contact::factory()->create(['organization_id' => $organizationA->id]);

    // Savepoint: PostgreSQL aborta la transacción de la prueba tras el error.
    expect(fn () => DB::transaction(
        fn () => DB::table('contacts')->where('id', $contact->id)->update(['organization_id' => $organizationB->id]),
    ))->toThrow(QueryException::class, 'organization_id');

    expect(DB::table('contacts')->where('id', $contact->id)->value('organization_id'))->toBe($organizationA->id);
});

it('CA-001-4: the database refuses the change through an Eloquent builder update too', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $contact = Contact::factory()->create(['organization_id' => $organizationA->id]);

    expect(fn () => Contact::query()->withoutGlobalScopes()->whereKey($contact->id)->update(['organization_id' => $organizationB->id]))
        ->toThrow(QueryException::class, 'organization_id');
});

it('CA-001-4: updating other columns still works', function (): void {
    $contact = Contact::factory()->create();

    DB::table('contacts')->where('id', $contact->id)->update(['full_name' => 'Nombre nuevo']);

    expect(DB::table('contacts')->where('id', $contact->id)->value('full_name'))->toBe('Nombre nuevo');
});

it('CA-001-7: every table of a scoped model has the organization_id immutability trigger', function (string $model): void {
    $table = (new $model)->getTable();

    $triggers = DB::table('pg_trigger')
        ->whereRaw('tgrelid = ?::regclass', [$table])
        ->where('tgname', 'like', '%organization_id_immutable')
        ->count();

    expect($triggers)->toBe(1);
})->with(fn (): array => TenantModels::dataset(TenantModels::scoped()));
