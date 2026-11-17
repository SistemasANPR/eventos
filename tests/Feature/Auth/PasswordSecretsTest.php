<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('CA-001-32: the password only exists as a hash in the database and in serialized output', function (): void {
    $plain = 'plain-secret-passphrase';
    $user = User::factory()->create(['password' => $plain]);

    $row = (array) DB::table('users')->where('id', $user->id)->first();

    expect($row['password'])->not->toBe($plain)
        ->and(Hash::isHashed($row['password']))->toBeTrue()
        ->and(Hash::check($plain, $row['password']))->toBeTrue()
        ->and(json_encode($row))->not->toContain($plain)
        ->and($user->toArray())->not->toHaveKey('password')
        ->and($user->toJson())->not->toContain($plain)
        ->and($user->toJson())->not->toContain($row['password']);
});

it('CA-001-32: audit records of access flows never contain the password or its hash', function (): void {
    $plain = 'plain-secret-passphrase';
    $user = User::factory()->create(['password' => $plain]);
    activity('auth')->causedBy($user)->event('password_reset')->log('password_reset');

    $logs = json_encode(Activity::query()->get()->toArray());

    expect($logs)->not->toContain($plain);
    expect($logs)->not->toContain($user->password);
});
