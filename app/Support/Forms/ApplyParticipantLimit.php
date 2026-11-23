<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\CallForSubmissions;
use App\Models\FormDefinition;

/**
 * Lleva el límite de personas del formulario a las convocatorias que lo usan: el envío de propuestas lee el
 * máximo de la convocatoria. Un formulario sin límite (nulo) no cambia nada. Si el nuevo máximo queda por
 * debajo del mínimo de la convocatoria, el mínimo baja con él. Se ejecuta con la organización en contexto.
 */
final class ApplyParticipantLimit
{
    public function toCallsOf(FormDefinition $form): void
    {
        if ($form->max_participants === null) {
            return;
        }

        CallForSubmissions::query()
            ->where('form_definition_id', $form->id)
            ->get()
            ->each(fn (CallForSubmissions $call) => $this->toCall($form, $call));
    }

    public function toCall(FormDefinition $form, CallForSubmissions $call): void
    {
        if ($form->max_participants === null) {
            return;
        }

        $call->forceFill([
            'max_participants' => $form->max_participants,
            'min_participants' => min($call->min_participants, $form->max_participants),
        ])->save();
    }
}
