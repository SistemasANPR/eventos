<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\RubricStatus;
use App\Models\Rubric;
use App\Models\Submission;
use App\Models\SubmissionFile;

/**
 * Regla de la revisión ciega (plan 011, DA-4, CA-011-18 a 20): una única fuente de verdad, en el servidor, de
 * si el evaluador puede ver a los autores de una propuesta y de qué campos los delatan. La usan el detalle
 * del evaluador (`SubmissionDetail::forReviewer`) y la Policy de descarga de archivos; ninguna vista decide.
 *
 * El modo lo fija la rúbrica PUBLICADA más reciente del tipo de la propuesta en su evento (`blind_review`).
 * Sin rúbrica publicada se trata como ciega: el evaluador no puede calificar todavía y, hasta que la
 * rúbrica diga lo contrario, no hay motivo para enseñarle a los autores (se falla cerrado). Los campos que
 * delatan al autor son los marcados con `identifiesAuthor` en la instantánea del formulario con que se envió
 * la propuesta. Exige la organización en contexto.
 *
 * No filtra el título ni los campos de texto libre que el organizador no marcó (riesgo declarado, §8 del
 * plan), ni los metadatos internos de PDF y PPTX.
 */
final class BlindReview
{
    public static function appliesTo(Submission $submission): bool
    {
        $blind = Rubric::query()
            ->where('event_id', $submission->event_id)
            ->where('submission_type_id', $submission->submission_type_id)
            ->where('status', RubricStatus::Published)
            ->orderByDesc('version')
            ->value('blind_review');

        return $blind === null || (bool) $blind;
    }

    /**
     * Claves de los campos que identifican al autor. Cualquier valor distinto de «falso» cuenta: un dato
     * inesperado no destapa un campo.
     *
     * @return list<string>
     */
    public static function identifyingFieldKeys(Submission $submission): array
    {
        $fields = is_array($submission->form_snapshot['fields'] ?? null) ? $submission->form_snapshot['fields'] : [];
        $keys = [];

        foreach ($fields as $field) {
            if (is_array($field) && is_string($field['key'] ?? null) && ($field['identifiesAuthor'] ?? false) !== false) {
                $keys[] = $field['key'];
            }
        }

        return $keys;
    }

    /** Si el archivo es de un campo que identifica al autor y la propuesta se evalúa a ciegas. */
    public static function hidesFile(Submission $submission, SubmissionFile $file): bool
    {
        return self::appliesTo($submission) && in_array($file->form_field_key, self::identifyingFieldKeys($submission), true);
    }
}
