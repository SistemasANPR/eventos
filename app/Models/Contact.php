<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Persona de contacto de una organización (autores, ponentes). Los datos de
 * contacto son de la organización: una misma persona en dos organizaciones son
 * dos registros (ADR-0003, DA-3).
 */
#[Fillable(['full_name', 'email', 'country'])]
class Contact extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ContactFactory> */
    use HasFactory, HasUuids;
}
