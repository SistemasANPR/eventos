<?php

declare(strict_types=1);

namespace App\Support\Forms;

/**
 * Contrato con el plan 007 (archivos): comprueba que las cargas pendientes que un
 * formulario referencia existen y pertenecen a la sesión de envío. El plan 007 aporta la
 * implementación real; mientras tanto las pruebas usan un doble.
 */
interface PendingUploadRepository
{
    /**
     * `true` solo si TODAS las cargas existen, están ligadas a `$uploadToken` y son de `$organizationId`.
     *
     * @param  list<string>  $uploadIds
     */
    public function allBelongTo(array $uploadIds, string $uploadToken, string $organizationId): bool;
}
