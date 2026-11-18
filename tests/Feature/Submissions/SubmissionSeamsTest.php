<?php

declare(strict_types=1);

use App\Enums\FormDefinitionStatus;
use App\Models\CallForSubmissions;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\Track;
use App\Support\Calls\TrackUsageChecker;
use App\Support\Files\FileFieldRegistry;
use App\Support\Files\UploadToken;
use App\Support\Files\UploadTokenVerifier;
use App\Support\Forms\IssuedUploadTokens;
use App\Support\Forms\PendingUploadRepository;
use App\Support\Forms\SubmissionAnswerCounter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

function seamsOrganization(): Organization
{
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization);

    return $organization;
}

it('CA-006-17: a track with submissions is in use and an unused one is not', function (): void {
    seamsOrganization();
    $used = Track::factory()->create();
    $unused = Track::factory()->create(['event_id' => $used->event_id]);
    Submission::factory()->create(['event_id' => $used->event_id, 'track_id' => $used->id]);

    $checker = app(TrackUsageChecker::class);

    expect($checker->isInUse($used))->toBeTrue()
        ->and($checker->isInUse($unused))->toBeFalse();
});

it('a form has answers only when a submission was sent against it', function (): void {
    seamsOrganization();
    $form = FormDefinition::factory()->create();
    $other = FormDefinition::factory()->create(['event_id' => $form->event_id]);
    Submission::factory()->create(['event_id' => $form->event_id, 'form_definition_id' => $form->id]);

    $counter = app(SubmissionAnswerCounter::class);

    expect($counter->hasAnswers($form))->toBeTrue()
        ->and($counter->hasAnswers($other))->toBeFalse();
});

it('an option is in use for single and multiple choice answers, and only for that field', function (): void {
    seamsOrganization();
    $form = FormDefinition::factory()->create();
    Submission::factory()->create(['event_id' => $form->event_id, 'form_definition_id' => $form->id, 'answers' => ['f_single' => 'o_a', 'f_multi' => ['o_b', 'o_c']]]);

    $counter = app(SubmissionAnswerCounter::class);

    expect($counter->optionIsUsed($form, 'f_single', 'o_a'))->toBeTrue()
        ->and($counter->optionIsUsed($form, 'f_multi', 'o_c'))->toBeTrue()
        ->and($counter->optionIsUsed($form, 'f_single', 'o_b'))->toBeFalse()
        ->and($counter->optionIsUsed($form, 'f_multi', 'o_a'))->toBeFalse();
});

it('pending uploads belong only with the right token, organization, status and existence', function (): void {
    $organization = seamsOrganization();
    $hash = UploadToken::hash('tok-1');
    $one = SubmissionFile::factory()->create(['upload_token_hash' => $hash]);
    $two = SubmissionFile::factory()->create(['upload_token_hash' => $hash, 'call_id' => $one->call_id]);
    $attached = SubmissionFile::factory()->attachedTo(Submission::factory()->create()->id)->create(['upload_token_hash' => $hash, 'call_id' => $one->call_id]);
    $foreign = SubmissionFile::factory()->create(['upload_token_hash' => UploadToken::hash('tok-2'), 'call_id' => $one->call_id]);

    $repository = app(PendingUploadRepository::class);

    expect($repository->allBelongTo([$one->id, $two->id], 'tok-1', $organization->id))->toBeTrue()
        ->and($repository->allBelongTo([$one->id, $foreign->id], 'tok-1', $organization->id))->toBeFalse()
        ->and($repository->allBelongTo([$one->id, $attached->id], 'tok-1', $organization->id))->toBeFalse()
        ->and($repository->allBelongTo([$one->id, 'no-existe'], 'tok-1', $organization->id))->toBeFalse()
        ->and($repository->allBelongTo([$one->id], 'tok-1', Organization::factory()->create()->id))->toBeFalse();
});

it('an issued upload token is verified from the session and only its hash is stored', function (): void {
    seamsOrganization();
    $call = CallForSubmissions::factory()->create();
    $otherCall = CallForSubmissions::factory()->create(['event_id' => $call->event_id]);

    $token = app(IssuedUploadTokens::class)->issue($call);
    $verifier = app(UploadTokenVerifier::class);

    expect($verifier->isIssued($call, $token))->toBeTrue()
        ->and($verifier->isIssued($call, 'inventado'))->toBeFalse()
        ->and($verifier->isIssued($call, ''))->toBeFalse()
        ->and($verifier->isIssued($otherCall, $token))->toBeFalse()
        ->and(json_encode(session()->all()))->not->toContain($token);
});

it('a file field exists only if it is a file field of the published form of the call', function (): void {
    seamsOrganization();
    $fields = [
        ['key' => 'f_file', 'type' => 'file'],
        ['key' => 'f_text', 'type' => 'short_text'],
    ];
    $published = FormDefinition::factory()->create(['status' => FormDefinitionStatus::Published, 'fields' => $fields]);
    $draft = FormDefinition::factory()->create(['event_id' => $published->event_id, 'fields' => $fields]);
    $call = CallForSubmissions::factory()->create(['event_id' => $published->event_id, 'form_definition_id' => $published->id]);
    $draftCall = CallForSubmissions::factory()->create(['event_id' => $published->event_id, 'form_definition_id' => $draft->id]);
    $noFormCall = CallForSubmissions::factory()->create(['event_id' => $published->event_id, 'form_definition_id' => null]);

    $registry = app(FileFieldRegistry::class);

    expect($registry->exists($call, 'f_file'))->toBeTrue()
        ->and($registry->exists($call, 'f_text'))->toBeFalse()
        ->and($registry->exists($call, 'f_missing'))->toBeFalse()
        ->and($registry->exists($draftCall, 'f_file'))->toBeFalse()
        ->and($registry->exists($noFormCall, 'f_file'))->toBeFalse();
});
