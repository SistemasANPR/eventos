<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ExtraFieldDefinitionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pregunta del formulario de hospedajes o de patrocinadores de un evento (plan 030, DA-7): se define una vez por
 * evento y módulo (`scope`) y todos los registros del evento la muestran. Sin `$fillable`: se crea y edita solo desde
 * `SaveExtraFieldDefinitions`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $scope `lodging` o `sponsor`
 * @property string $label
 * @property string $type uno de `TYPES`
 * @property bool $required
 * @property array<string, mixed>|null $config opciones de una pregunta de elección (`options`: lista de `{value, label}`) o `min`/`max` de un número
 * @property int $position
 */
class ExtraFieldDefinition extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ExtraFieldDefinitionFactory> */
    use HasFactory, HasUuids;

    public const SCOPE_LODGING = 'lodging';

    public const SCOPE_SPONSOR = 'sponsor';

    public const SCOPES = [self::SCOPE_LODGING, self::SCOPE_SPONSOR];

    public const TYPE_SHORT_TEXT = 'short_text';

    public const TYPE_LONG_TEXT = 'long_text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_DATE = 'date';

    public const TYPE_CHECKBOX = 'checkbox';

    public const TYPE_SINGLE_CHOICE = 'single_choice';

    public const TYPE_MULTIPLE_CHOICE = 'multiple_choice';

    public const TYPE_PHOTO = 'photo';

    public const TYPE_FILE = 'file';

    /** Los siete primeros comparten nombre con `FieldType` del formulario de la convocatoria; foto y archivo (PDF) son propios. */
    public const TYPES = [
        self::TYPE_SHORT_TEXT, self::TYPE_LONG_TEXT, self::TYPE_NUMBER, self::TYPE_DATE, self::TYPE_CHECKBOX,
        self::TYPE_SINGLE_CHOICE, self::TYPE_MULTIPLE_CHOICE, self::TYPE_PHOTO, self::TYPE_FILE,
    ];

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer', 'required' => 'boolean', 'config' => 'array'];
    }

    /** La respuesta es un archivo subido (foto o PDF), no un valor del formulario. */
    public function isFile(): bool
    {
        return $this->type === self::TYPE_PHOTO || $this->type === self::TYPE_FILE;
    }

    public function hasOptions(): bool
    {
        return $this->type === self::TYPE_SINGLE_CHOICE || $this->type === self::TYPE_MULTIPLE_CHOICE;
    }

    /**
     * Opciones de una pregunta de elección: valor guardado => texto que se muestra.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach (is_array($this->config['options'] ?? null) ? $this->config['options'] : [] as $option) {
            if (is_array($option) && is_string($option['value'] ?? null) && is_string($option['label'] ?? null)) {
                $options[$option['value']] = $option['label'];
            }
        }

        return $options;
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
