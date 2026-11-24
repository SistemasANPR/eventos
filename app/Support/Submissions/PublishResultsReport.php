<?php

declare(strict_types=1);

namespace App\Support\Submissions;

/**
 * Resultado de publicar los resultados de una convocatoria (plan 012, F2, DA-2): cuántos autores se avisaron
 * ahora, cuántos resultados fallaron (quedan sin publicar y una nueva publicación los reintenta; la decisión
 * no se deshace, CA-012-18) y si la convocatoria ya los había publicado antes. Sin datos personales.
 */
final readonly class PublishResultsReport
{
    public function __construct(
        public int $published,
        public int $failed,
        public bool $alreadyPublished,
    ) {}
}
