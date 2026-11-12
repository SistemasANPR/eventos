<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\FormDefinition;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Carga la definición con `SELECT ... FOR UPDATE` (plan 005, CA-005-24). Una edición
 * del formulario y un envío de propuesta (plan 006, `SubmitSubmission`) pasan por aquí:
 * la segunda transacción espera a la primera y ve su resultado, así nunca se guarda una
 * respuesta que contradiga la versión de la definición registrada con ella.
 */
final class LockedFormDefinition
{
    /** Debe llamarse dentro de una transacción y de la organización del formulario. */
    public function handle(string $formDefinitionId): FormDefinition
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('LockedFormDefinition exige una transacción abierta.');
        }

        return FormDefinition::query()->lockForUpdate()->findOrFail($formDefinitionId);
    }
}
