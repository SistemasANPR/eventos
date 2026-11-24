<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RetentionCategory;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\RetentionPolicyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Plazo de retención de una categoría de datos en una organización (plan 014, H-014-6). Sin fila no hay
 * plazo y el trabajo programado no actúa sobre esa categoría. Sin `$fillable`: lo escribe solo
 * `SetRetentionPolicies`.
 *
 * @property string $id
 * @property string $organization_id
 * @property RetentionCategory $category
 * @property int $retention_days
 */
class RetentionPolicy extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<RetentionPolicyFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => RetentionCategory::class,
            'retention_days' => 'integer',
        ];
    }
}
