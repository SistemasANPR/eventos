<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Motivo de una exportación o anonimización de los datos de una persona (plan 014, H-014-4). Es una
 * lista cerrada a propósito: un texto libre acabaría en la auditoría con datos personales.
 */
enum PersonDataRequestReason: string
{
    case DataSubjectRequest = 'data_subject_request';
    case ConsentWithdrawn = 'consent_withdrawn';
    case LegalOrder = 'legal_order';
    case Other = 'other';

    public function label(): string
    {
        return __("privacy_admin.reasons.{$this->value}");
    }
}
