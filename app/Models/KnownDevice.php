<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $last_seen_at
 *
 * Dispositivo conocido de un usuario, identificado solo por una huella en hash.
 * Ámbito plataforma (plan 001 §5): pertenece al usuario, no a una organización.
 */
#[Fillable(['user_id', 'fingerprint_hash', 'last_seen_at'])]
class KnownDevice extends Model
{
    use HasUuids;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
