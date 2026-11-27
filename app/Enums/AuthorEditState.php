<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qué puede hacer el autor con su propuesta ahora mismo, para decírselo en su pantalla (plan 034, DA-4).
 * Es solo una guía: `UpdateSubmissionByAuthor` vuelve a comprobarlo todo al guardar.
 */
enum AuthorEditState: string
{
    /** La propuesta está enviada y la convocatoria abierta: puede editar hasta el cierre. */
    case Editable = 'editable';

    /** La propuesta sigue enviada, pero la convocatoria ya no está abierta. */
    case CallClosed = 'call_closed';

    /** La propuesta ya no está «enviada»: en revisión, decidida o retirada. */
    case InProcess = 'in_process';
}
