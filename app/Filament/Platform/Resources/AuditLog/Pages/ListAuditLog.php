<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\AuditLog\Pages;

use App\Filament\Platform\Resources\AuditLog\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

/** Bitácora de auditoría de toda la plataforma (solo lectura). */
class ListAuditLog extends ListRecords
{
    protected static string $resource = AuditLogResource::class;
}
