<?php

declare(strict_types=1);

namespace App\Support\SubmissionExport;

/**
 * Defensa contra la inyección de fórmulas en hojas de cálculo (plan 009, CA-009-12; OWASP «CSV
 * Injection»). Una celda que empieza con `=`, `+`, `-`, `@`, tabulador o retorno de carro puede
 * ejecutarse como fórmula al abrir el archivo (`=HYPERLINK(...)`, `\t=cmd|...`). Se antepone un
 * apóstrofo para que Excel y LibreOffice la traten como texto. Se aplica a TODA celda, también a
 * los encabezados, porque las etiquetas de los campos también las escribe un usuario.
 */
final class FormulaGuard
{
    /** @var list<string> */
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function neutralize(string $value): string
    {
        if ($value !== '' && in_array($value[0], self::TRIGGERS, true)) {
            return "'".$value;
        }

        return $value;
    }
}
