<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use InvalidArgumentException;

/** Ya existe una organización con ese `slug`. */
final class OrganizationAlreadyExists extends InvalidArgumentException
{
    public const CODE = 'ORGANIZATION_ALREADY_EXISTS';

    public function __construct(string $slug)
    {
        parent::__construct("Ya existe una organización con el identificador «{$slug}» (código ".self::CODE.').');
    }
}
