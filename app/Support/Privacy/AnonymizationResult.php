<?php

declare(strict_types=1);

namespace App\Support\Privacy;

/**
 * Lo que dejó una anonimización: cuántas filas cambió cada módulo, qué se conservó por obligación legal y
 * las notas que la persona operadora debe conocer. Solo contiene totales y códigos: nunca datos personales
 * (también va a la auditoría).
 */
final readonly class AnonymizationResult
{
    /** Cuenta de acceso conservada porque la persona también es miembro de otra organización. */
    public const NOTE_ACCOUNT_KEPT_OTHER_ORGANIZATIONS = 'account_kept_other_organizations';

    /** La persona ya estaba anonimizada en esta organización: no se cambió nada. */
    public const NOTE_ALREADY_ANONYMIZED = 'already_anonymized';

    /**
     * @param  array<string, int>  $counts  filas cambiadas por módulo
     * @param  list<string>  $retained  categorías conservadas por obligación legal (p. ej. `billing`); vacía mientras no haya datos de facturación
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $counts = [],
        public array $retained = [],
        public array $notes = [],
    ) {}

    public static function alreadyAnonymized(): self
    {
        return new self(notes: [self::NOTE_ALREADY_ANONYMIZED]);
    }

    public function isAlreadyAnonymized(): bool
    {
        return in_array(self::NOTE_ALREADY_ANONYMIZED, $this->notes, true);
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    /** @return array{counts: array<string, int>, retained: list<string>, notes: list<string>} */
    public function toArray(): array
    {
        return ['counts' => $this->counts, 'retained' => $this->retained, 'notes' => $this->notes];
    }
}
