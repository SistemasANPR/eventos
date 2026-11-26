<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Landings\LandingBlocks;
use Database\Factories\EventLandingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Landing de un evento: su página pública armada con bloques, con borrador y versión publicada
 * (plan 022). Una por evento. Sin `$fillable`: se crea y edita solo desde las Actions de
 * `App\Actions\Landings`, que validan los bloques con `LandingBlocks` antes de guardarlos.
 * `version` sube con cada cambio guardado y es la base del control de concurrencia optimista.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string|null $call_id
 * @property list<array<string, mixed>> $draft_blocks
 * @property list<array<string, mixed>>|null $published_blocks
 * @property int $version
 * @property Carbon|null $published_at
 * @property string|null $published_by
 */
class EventLanding extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<EventLandingFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'draft_blocks' => 'array',
            'published_blocks' => 'array',
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * La landing del evento SI tiene versión publicada; si no, `null` y la página pública cae a la
     * plantilla fija anterior (plan 022, CA-022-22). Un fallo de base se propaga: no se disfraza de «sin landing».
     */
    public static function currentPublished(Event $event): ?self
    {
        return self::query()->where('event_id', $event->id)->whereNull('call_id')->whereNotNull('published_blocks')->first();
    }

    /** La landing PROPIA de una convocatoria, si ya tiene versión publicada (plan 033); si no, `null`. */
    public static function currentPublishedForCall(CallForSubmissions $call): ?self
    {
        return self::query()->where('call_id', $call->id)->whereNotNull('published_blocks')->first();
    }

    /**
     * Identificadores de imagen que usa la versión publicada (las únicas que se sirven al público).
     *
     * @return list<string>
     */
    public function publishedImageIds(): array
    {
        return LandingBlocks::imageIds($this->published_blocks ?? []);
    }

    /** ¿Es la landing de una convocatoria (y no la del evento)? */
    public function isForCall(): bool
    {
        return $this->call_id !== null;
    }

    public function isPublished(): bool
    {
        return $this->published_blocks !== null;
    }

    /** El borrador difiere de lo publicado (o nunca se publicó). */
    public function hasUnpublishedChanges(): bool
    {
        return ! LandingBlocks::same($this->published_blocks, $this->draft_blocks);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<CallForSubmissions, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(CallForSubmissions::class, 'call_id');
    }

    /** @return HasMany<LandingImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(LandingImage::class);
    }
}
