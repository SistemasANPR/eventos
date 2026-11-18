<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubmissionFileStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SubmissionFileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Ficha de un archivo privado de una propuesta (plan 007). El contenido vive en
 * el disco privado bajo `storage_path` (nombre generado); `original_name` es solo
 * un dato para mostrar, nunca una ruta ni un nombre de descarga.
 *
 * Sin `$fillable`: se crea solo desde las Actions, que fijan cada campo de forma
 * explícita. `upload_token_hash` es el hash del token de la sesión del formulario.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $call_id
 * @property string|null $submission_id
 * @property string $form_field_key
 * @property string $upload_token_hash
 * @property string $storage_path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property SubmissionFileStatus $status
 * @property Carbon $uploaded_at
 */
class SubmissionFile extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SubmissionFileFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['upload_token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubmissionFileStatus::class,
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
        ];
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
}
