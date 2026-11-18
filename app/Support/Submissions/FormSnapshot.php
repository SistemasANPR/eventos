<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Exceptions\Domain\CallNotReady;
use App\Models\FormDefinition;

/**
 * Copia de la definición con que se envió o se editó una propuesta (plan 006, CA-006-13): las ediciones
 * posteriores de las etiquetas no cambian el significado de lo ya enviado. Máximo 64 KB.
 */
final class FormSnapshot
{
    private const MAX_BYTES = 65536;

    /**
     * @return array<string, mixed>
     *
     * @throws CallNotReady si la copia supera el máximo
     */
    public static function of(FormDefinition $form): array
    {
        $snapshot = ['id' => $form->id, 'name' => $form->name, 'version' => $form->version, 'fields' => $form->fields];

        if (strlen((string) json_encode($snapshot)) > self::MAX_BYTES) {
            throw new CallNotReady;
        }

        return $snapshot;
    }
}
