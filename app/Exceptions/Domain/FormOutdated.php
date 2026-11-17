<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/** La `form_version` enviada ya no es la vigente del formulario; responde 409 (plan 006, CA-006-12). */
final class FormOutdated extends DomainException
{
    public const CODE = 'FORM_OUTDATED';

    public function __construct()
    {
        parent::__construct(__('submission_form.form_outdated').' ('.self::CODE.')');
    }
}
