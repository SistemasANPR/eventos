<?php

declare(strict_types=1);

namespace App\Support\SubmissionExport;

use App\Actions\SubmissionExports\DownloadSubmissionExport;
use App\Jobs\ExportSubmissionsJob;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\Models\Activity;

/**
 * Exportaciones de propuestas que `$user` pidió en `$organization` en las últimas 24 horas y cuyo
 * archivo sigue en el disco (plan 009, CA-009-19). Cada una trae una URL firmada de 5 minutos que lleva
 * al usuario: solo le sirve a quien la pidió. Se arma al mostrar la lista, nunca se guarda.
 */
final class RecentExports
{
    /** Cuántas se muestran. */
    private const LIMIT = 10;

    /**
     * @return list<array{date: string, rows: int, format: string, url: string}>
     */
    public static function for(User $user, Organization $organization): array
    {
        $disk = Storage::disk((string) config('filesystems.private_uploads_disk'));

        $recent = Activity::query()
            ->where('event', 'submissions_exported')
            ->where('causer_id', $user->id)
            ->where('properties->organization_id', $organization->id)
            ->where('created_at', '>', now()->subHours(DownloadSubmissionExport::FILE_HOURS))
            ->latest()
            ->limit(self::LIMIT)
            ->get();

        $exports = [];

        foreach ($recent as $entry) {
            $exportId = $entry->properties['export_id'] ?? null;
            $format = $entry->properties['format'] ?? null;

            if (! is_string($exportId) || ! is_string($format) || ! $disk->exists(ExportSubmissionsJob::pathFor($organization->id, $exportId, $format))) {
                continue;
            }

            $exports[] = [
                'date' => (string) $entry->created_at?->isoFormat('L LT'),
                'rows' => (int) ($entry->properties['rows'] ?? 0),
                'format' => strtoupper($format),
                'url' => URL::temporarySignedRoute(
                    'submission-exports.download',
                    now()->addMinutes(5),
                    ['organization' => $organization->slug, 'export' => $exportId, 'user' => $user->id],
                ),
            ];
        }

        return $exports;
    }
}
