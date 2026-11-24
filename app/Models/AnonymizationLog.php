<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AnonymizationLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Huella no reversible de una persona anonimizada en una organización (plan 014, DA-5): HMAC del correo
 * normalizado con la sal de la plataforma y el identificador de la organización. No guarda el correo ni
 * ningún dato personal; permite volver a anonimizarla tras restaurar un respaldo
 * (`app:reapply-anonymizations`). Solo se inserta: lo crea `AnonymizePerson`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $email_hash
 * @property Carbon $anonymized_at
 */
class AnonymizationLog extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<AnonymizationLogFactory> */
    use HasFactory, HasUuids;

    protected $table = 'anonymization_log';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['email_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['anonymized_at' => 'datetime'];
    }
}
