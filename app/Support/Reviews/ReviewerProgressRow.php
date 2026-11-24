<?php

declare(strict_types=1);

namespace App\Support\Reviews;

/** Un evaluador en la página de avance (plan 011, F6, CA-011-25): quién es y cuánto lleva. Sin calificaciones. */
final readonly class ReviewerProgressRow
{
    public function __construct(
        public string $reviewerId,
        public string $name,
        public string $email,
        public ReviewerProgress $progress,
    ) {}
}
