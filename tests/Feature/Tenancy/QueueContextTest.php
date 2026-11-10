<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\ProbeTenantJob;

uses(RefreshDatabase::class);

/** Job falso: el proveedor de contexto de log de Laravel lee su `payload()` al procesar. */
function fakeQueueJob(): Job
{
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);

    return $job;
}

beforeEach(function (): void {
    config(['queue.default' => 'database']);
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('CA-001-13: a queued job without context fails visibly with MissingOrganizationContext', function (): void {
    ProbeTenantJob::dispatch();

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    $failed = DB::table('failed_jobs')->get();
    expect($failed)->toHaveCount(1)
        ->and($failed->first()->exception)->toContain('MissingOrganizationContext');
});

it('CA-001-13: a job does not inherit the context left in the worker process', function (): void {
    CurrentOrganization::set(Organization::factory()->create());
    ProbeTenantJob::dispatch();

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(CurrentOrganization::has())->toBeFalse();
});

it('CA-001-13: the context set by a job is cleared when the job ends', function (): void {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization);

    event(new JobProcessed('database', fakeQueueJob()));

    expect(CurrentOrganization::has())->toBeFalse();
});

it('CA-001-13: the synchronous connection keeps the context of the caller', function (): void {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization);
    $job = fakeQueueJob();

    event(new JobProcessing('sync', $job));
    event(new JobProcessed('sync', $job));

    expect(CurrentOrganization::id())->toBe($organization->id);
});
