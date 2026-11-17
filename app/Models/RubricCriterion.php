<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CriterionKind;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\RubricCriterionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * Criterio de una rúbrica (plan 011, H-011-1): etiqueta traducible, tipo
 * (calificación o texto), escala entera y peso para el promedio ponderado.
 *
 * Sin `$fillable`: se crea y se cambia solo desde las Actions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $rubric_id
 * @property int $position
 * @property CriterionKind $kind
 * @property int $scale_min
 * @property int $scale_max
 * @property string $weight
 */
class RubricCriterion extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<RubricCriterionFactory> */
    use HasFactory, HasTranslations, HasUuids;

    protected $table = 'rubric_criteria';

    /** @var list<string> */
    public array $translatable = ['label'];

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => CriterionKind::class,
            'scale_min' => 'integer',
            'scale_max' => 'integer',
            'weight' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Rubric, $this> */
    public function rubric(): BelongsTo
    {
        return $this->belongsTo(Rubric::class);
    }

    /** Etiqueta en el idioma pedido; si falta, cae al idioma base de la aplicación. */
    public function labelFor(string $locale): string
    {
        return (string) $this->getTranslation('label', $locale, true);
    }
}
