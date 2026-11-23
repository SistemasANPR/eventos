<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Models\Organization;

/**
 * Enlace al tablero del evaluador que lleva el correo `reviewer.assignment` (plan 011, DA-8).
 *
 * PROVISIONAL: el panel `Reviewer` y su tablero llegan en F4. Hasta entonces el enlace apunta a la raíz
 * de la organización en el panel de organizadores (`/admin/{organización}`), a donde un evaluador con
 * sesión llega pero no ve nada de organizador (CA-011-16, F4). F4 cambia SOLO este método para que
 * devuelva la ruta del tablero del panel `Reviewer`; ningún otro código arma ese enlace.
 */
final class ReviewerDashboardUrl
{
    public static function for(Organization $organization): string
    {
        return url('/admin/'.$organization->slug);
    }
}
