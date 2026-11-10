<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubmissionFileStatus;
use App\Models\CallForSubmissions;
use App\Models\SubmissionFile;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SubmissionFile> */
class SubmissionFileFactory extends Factory
{
    protected $model = SubmissionFile::class;

    /**
     * Archivo, convocatoria y evento comparten organización (lo exige la base).
     * Acepta `organization_id` explícito (crea la convocatoria en ella), `call_id`
     * explícito (toma su organización y evento) o ninguno (usa la organización en
     * contexto y, sin contexto, crea una). Nace `pending`; no escribe en el disco.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = (string) Str::uuid();

        return [
            'id' => $id,
            'organization_id' => function (array $attributes): string {
                if (is_string($attributes['call_id'] ?? null)) {
                    return (string) CallForSubmissions::query()->withoutGlobalScopes()->whereKey($attributes['call_id'])->value('organization_id');
                }

                return CurrentOrganization::has()
                    ? CurrentOrganization::id()
                    : CallForSubmissions::factory()->create()->organization_id;
            },
            'call_id' => fn (array $attributes): string => CallForSubmissions::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'event_id' => fn (array $attributes): string => (string) CallForSubmissions::query()
                ->withoutGlobalScopes()->whereKey($attributes['call_id'])->value('event_id'),
            'submission_id' => null,
            'form_field_key' => 'presentation',
            'upload_token_hash' => hash('sha256', Str::random(40)),
            'storage_path' => fn (array $attributes): string => "organizations/{$attributes['organization_id']}/events/{$attributes['event_id']}/files/{$id}.pdf",
            'original_name' => 'presentacion.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', $id),
            'status' => SubmissionFileStatus::Pending,
            'uploaded_at' => now(),
        ];
    }

    /** Ligado a una propuesta (sin clave foránea hasta el plan 006). */
    public function attachedTo(string $submissionId): static
    {
        return $this->state([
            'status' => SubmissionFileStatus::Attached,
            'submission_id' => $submissionId,
        ]);
    }
}
