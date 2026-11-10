<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SubmissionTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * Tipo de propuesta de un evento (plan 004, DA-7): sesión, panel, póster… Lo
 * define el organizador para su evento. No se borra: un tipo archivado deja de
 * ofrecerse en convocatorias nuevas y se conserva en las existentes.
 *
 * Sin `$fillable`: se crea y se edita solo desde las Actions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property int $position
 * @property Carbon|null $archived_at
 */
class SubmissionType extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SubmissionTypeFactory> */
    use HasFactory, HasTranslations, HasUuids;

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @param  Builder<SubmissionType>  $query
     * @return Builder<SubmissionType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Nombre en el idioma pedido; si falta, cae al idioma base de la aplicación. */
    public function label(string $locale): string
    {
        return (string) $this->getTranslation('name', $locale, true);
    }
}
