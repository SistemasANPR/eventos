<?php

declare(strict_types=1);

namespace App\Support\Reviews;

/**
 * Avance de los evaluadores de un evento (plan 011, F6, CA-011-25 y 57), tal como lo devuelve
 * `ComputeReviewerProgress`: una fila por evaluador, en el orden pedido, y el avance global del evento con los
 * mismos filtros de eje y convocatoria.
 */
final readonly class ReviewerProgressReport
{
    /** @param  list<ReviewerProgressRow>  $rows */
    public function __construct(
        public array $rows,
        public ReviewerProgress $overall,
    ) {}
}
