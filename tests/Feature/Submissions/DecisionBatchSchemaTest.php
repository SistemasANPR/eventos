<?php

declare(strict_types=1);

use App\Models\DecisionBatch;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/*
 * Integridad y aislamiento de los lotes de decisión masiva (plan 012, F2, CA-012-9 y 12): la base impide un
 * lote que cruce organizaciones y el modelo no se consulta sin organización en contexto.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function decisionBatchRow(Event $event, User $actor, array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
        'actor_user_id' => $actor->id,
        'filter' => json_encode(['mode' => 'selection', 'to' => 'accepted']),
        'total' => 2,
        'created_at' => now(),
        ...$overrides,
    ];
}

it('CA-012-9: the database refuses a batch that points to an event of another organization or has no proposals', function (): void {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $actor = User::factory()->create();

    DB::table('decision_batches')->insert(decisionBatchRow($event, $actor));

    expect(fn () => DB::table('decision_batches')->insert(decisionBatchRow($event, $actor, ['organization_id' => $other->id])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('decision_batches')->insert(decisionBatchRow($event, $actor, ['total' => 0])))
        ->toThrow(QueryException::class);
});

it('CA-012-12: a batch of another organization is invisible and a query without context fails instead of returning empty', function (): void {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();
    $batch = CurrentOrganization::run($organization, fn (): DecisionBatch => DecisionBatch::factory()->create());

    expect(CurrentOrganization::run($other, fn () => DecisionBatch::query()->find($batch->id)))->toBeNull()
        ->and(CurrentOrganization::run($organization, fn () => DecisionBatch::query()->find($batch->id)?->id))->toBe($batch->id)
        ->and(fn () => DecisionBatch::query()->count())->toThrow(MissingOrganizationContext::class);
});

it('CA-012-9: the organization of a batch cannot change', function (): void {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();
    $batch = CurrentOrganization::run($organization, fn (): DecisionBatch => DecisionBatch::factory()->create());

    expect(fn () => DB::table('decision_batches')->where('id', $batch->id)->update(['organization_id' => $other->id]))
        ->toThrow(QueryException::class);
});
