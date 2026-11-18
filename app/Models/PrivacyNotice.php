<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PrivacyNoticeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * Una versión del aviso de privacidad de un evento (plan 003, DA-4). Cada
 * versión contiene todos los idiomas. Al publicarse queda inmutable (también
 * en la base): editarla crea una versión nueva; las propuestas guardan la
 * versión que la persona aceptó (plan 006).
 *
 * Sin `$fillable`: se crea y se publica solo desde las Actions, que fijan cada
 * campo de forma explícita.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property int $version
 * @property Carbon|null $published_at
 * @property string|null $published_by
 */
class PrivacyNotice extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<PrivacyNoticeFactory> */
    use HasFactory, HasTranslations, HasUuids;

    /** @var list<string> */
    public array $translatable = ['body'];

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * Versión vigente de un evento: la última publicada, aunque haya un borrador
     * más nuevo (el borrador no vale hasta publicarse). Sin ninguna publicada
     * devuelve `null`: de ahí depende la regla del plan 004 de no abrir una
     * convocatoria sin aviso. Requiere la organización en contexto.
     */
    public static function currentPublished(Event $event): ?self
    {
        return static::query()
            ->where('event_id', $event->id)
            ->whereNotNull('published_at')
            ->orderByDesc('version')
            ->first();
    }
}
