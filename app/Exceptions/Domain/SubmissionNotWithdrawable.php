<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/**
 * La propuesta ya no se puede retirar porque salió del estado «enviada» (plan 006, DA-7): el equipo
 * organizador ya decidió algo sobre ella y revertirlo le corresponde a él (plan 012).
 */
final class SubmissionNotWithdrawable extends DomainException
{
    public const CODE = 'SUBMISSION_NOT_WITHDRAWABLE';

    public function __construct()
    {
        parent::__construct(__('author.submission_not_withdrawable').' ('.self::CODE.')');
    }
}
