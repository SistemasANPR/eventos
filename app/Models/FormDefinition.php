<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormDefinitionStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\FormDefinitionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Formulario de propuestas de un evento (plan 005, ADR-0010): su definición vive en
 * `fields` (jsonb) con la clave estable de cada campo. Un formulario con respuestas
 * no se borra ni se le cambia el tipo a un campo (se retira); eso lo hacen las Actions.
 *
 * Sin `$fillable`: se crea y se edita solo desde las Actions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $name
 * @property FormDefinitionStatus $status
 * @property list<array<string, mixed>> $fields
 * @property int $version
 * @property Carbon|null $published_at
 */
class FormDefinition extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<FormDefinitionFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => FormDefinitionStatus::class,
            'fields' => 'array',
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Claves de los campos que pueden delatar a quien envía la propuesta (DA-7). El plan
     * 011 las usa para ocultarlos al evaluador ciego. Incluye los campos retirados:
     * sus respuestas siguen guardadas y siguen delatando.
     *
     * @return list<string>
     */
    public function authorIdentifyingKeys(): array
    {
        $keys = [];

        foreach ($this->fields as $field) {
            if (($field['identifiesAuthor'] ?? false) === true) {
                $keys[] = (string) $field['key'];
            }
        }

        return $keys;
    }
}
