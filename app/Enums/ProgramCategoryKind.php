<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\ActivityCategory;
use App\Models\SpeakerCategory;

/**
 * Los dos catálogos de categorías de un evento (plan 021, DA-7): el de ponentes y el de actividades.
 * Son modelos y tablas distintos; esta enumeración deja que sus Actions y Form Requests compartan el
 * código sin duplicarlo. `value` es también el prefijo de los permisos (`speakers.manage`, `activities.delete`).
 */
enum ProgramCategoryKind: string
{
    case Speaker = 'speakers';
    case Activity = 'activities';

    /** @return class-string<SpeakerCategory|ActivityCategory> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Speaker => SpeakerCategory::class,
            self::Activity => ActivityCategory::class,
        };
    }

    /** Prefijo de los nombres de auditoría: `speaker_category_created`. */
    public function auditPrefix(): string
    {
        return match ($this) {
            self::Speaker => 'speaker_category',
            self::Activity => 'activity_category',
        };
    }

    public static function of(SpeakerCategory|ActivityCategory $category): self
    {
        return $category instanceof SpeakerCategory ? self::Speaker : self::Activity;
    }
}
