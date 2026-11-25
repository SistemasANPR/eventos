<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Models\Organization;
use Filament\Facades\Filament;

/**
 * Enlace al tablero del evaluador que lleva el correo `reviewer.assignment` (plan 011, DA-8): la página de
 * inicio del panel `Reviewer` de la organización (`/reviewer/{organización}`). Ningún otro código arma ese enlace.
 */
final class ReviewerDashboardUrl
{
    public static function for(Organization $organization): string
    {
        return (string) Filament::getPanel('reviewer')->getUrl($organization);
    }
}
