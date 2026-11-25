<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Models\Contact;
use App\Models\Speaker;
use App\Models\SubmissionParticipant;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Dónde está una persona dentro de LA organización en contexto: sus identificadores en cada módulo del
 * inventario (`docs/privacidad.md` §2). Se localiza por su correo normalizado, y por el vínculo con su
 * `Contact`. Solo contiene identificadores, nunca los datos: es lo que usan `ExportPersonData`,
 * `AnonymizePerson` y `ReapplyAnonymizations`.
 *
 * Exige la organización en contexto: sin ella las consultas fallan (ADR-0003). Lo ya anonimizado no
 * se vuelve a encontrar, porque su correo dejó de ser el de la persona.
 */
final class PersonRecords
{
    /**
     * @param  list<string>  $emails  correos normalizados de las filas encontradas (incluye el buscado)
     * @param  list<string>  $contactIds
     * @param  list<string>  $participantIds
     * @param  list<string>  $speakerIds
     * @param  list<string>  $submissionIds  propuestas donde participa
     * @param  list<string>  $primarySubmissionIds  propuestas donde es el participante principal (autor que las envió)
     */
    private function __construct(
        public readonly string $email,
        public readonly array $emails,
        public readonly array $contactIds,
        public readonly array $participantIds,
        public readonly array $speakerIds,
        public readonly array $submissionIds,
        public readonly array $primarySubmissionIds,
        public readonly ?string $userId,
    ) {}

    public static function find(string $email): self
    {
        $email = PersonIdentity::normalize($email);

        $contactIds = Contact::query()->whereRaw('lower(email) = ?', [$email])->pluck('id')->all();

        $participants = SubmissionParticipant::query()
            ->where(fn ($query) => $query->whereRaw('lower(email) = ?', [$email])->orWhereIn('contact_id', $contactIds))
            ->get(['id', 'contact_id', 'submission_id', 'is_primary', 'email']);

        $speakers = Speaker::withTrashed()
            ->where(fn ($query) => $query->whereRaw('lower(email) = ?', [$email])->orWhereIn('contact_id', $contactIds))
            ->get(['id', 'contact_id', 'email']);

        $contactIds = array_values(array_unique([
            ...$contactIds,
            ...$participants->pluck('contact_id')->all(),
            ...$speakers->pluck('contact_id')->filter()->all(),
        ]));

        $emails = collect([$email])
            ->merge(Contact::query()->whereKey($contactIds)->pluck('email'))
            ->merge($participants->pluck('email'))
            ->merge($speakers->pluck('email')->filter())
            ->map(fn (string $value): string => PersonIdentity::normalize($value))
            ->unique()
            ->values()
            ->all();

        $user = User::query()
            ->membersOf(CurrentOrganization::get())
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        return new self(
            email: $email,
            emails: $emails,
            contactIds: $contactIds,
            participantIds: $participants->pluck('id')->all(),
            speakerIds: $speakers->pluck('id')->all(),
            submissionIds: $participants->pluck('submission_id')->unique()->values()->all(),
            primarySubmissionIds: $participants->where('is_primary', true)->pluck('submission_id')->unique()->values()->all(),
            userId: $user?->id,
        );
    }

    public function isEmpty(): bool
    {
        return $this->contactIds === []
            && $this->participantIds === []
            && $this->speakerIds === []
            && $this->userId === null;
    }
}
