<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventAssignmentRole;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\EventAssignmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignación de un usuario a un evento con un rol (plan 011, H-011-7): de ella
 * depende que un evaluador o coordinador vea solo su evento. Sin `$fillable`: se
 * crea y se cambia solo desde las Actions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $user_id
 * @property EventAssignmentRole $role
 */
class EventAssignment extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<EventAssignmentFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => EventAssignmentRole::class];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Si el usuario tiene asignación de evaluador en el evento (en la organización en contexto). */
    public static function isReviewerOf(Event $event, User $user): bool
    {
        return static::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->where('role', EventAssignmentRole::Reviewer)
            ->exists();
    }
}
