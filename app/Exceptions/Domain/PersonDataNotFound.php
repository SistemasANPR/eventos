<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Models\Contact;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * No hay datos de esa persona en la organización en contexto (plan 014, CA-014-23). Es un
 * `ModelNotFoundException`, así que la capa web responde 404: igual que si la persona fuera de otra
 * organización, sin distinguir los casos.
 *
 * @extends ModelNotFoundException<Contact>
 */
final class PersonDataNotFound extends ModelNotFoundException
{
    public function __construct()
    {
        parent::__construct('No hay datos de esa persona en la organización.');

        $this->setModel(Contact::class);
    }
}
