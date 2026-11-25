<?php

declare(strict_types=1);

/*
 * Verificación en dos pasos obligatoria (plan 014, H-014-1). Encendida por defecto: con
 * `TWO_FACTOR_REQUIRED=false` ningún panel la exige (solo para entornos de pruebas y
 * desarrollo). Los demás planes la consultan con `TwoFactorRequirement::isEnforced()`:
 * por ejemplo, invitar a evaluadores externos se rechaza si esta bandera está apagada (CA-011-34).
 */
return [
    'required' => (bool) env('TWO_FACTOR_REQUIRED', true),
];
