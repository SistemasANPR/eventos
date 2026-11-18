<?php

declare(strict_types=1);

namespace App\Support\SubmissionExport;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * Hoja con las propuestas exportadas (plan 009, H-009-3). Todo se escribe como TEXTO
 * (`StringValueBinder`): el valor por omisión convertiría en fórmula cualquier cadena que empiece
 * con `=` y en número las que parecen números (perdería ceros a la izquierda). Las celdas ya llegan
 * saneadas con `FormulaGuard`.
 */
final class SubmissionsSheet extends StringValueBinder implements FromArray, WithCustomValueBinder, WithHeadings
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string>>  $rows
     */
    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
    ) {}

    /** @return list<string> */
    public function headings(): array
    {
        return $this->headings;
    }

    /** @return list<list<string>> */
    public function array(): array
    {
        return $this->rows;
    }
}
