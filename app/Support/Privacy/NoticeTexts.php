<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Http\Requests\Privacy\StorePrivacyNoticeRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Valida y sanitiza el texto de un aviso por idioma: se valida la forma y el
 * HTML se limpia ANTES de guardarlo (CA-003-15). Los idiomas que quedan vacíos
 * tras limpiar se descartan.
 */
final class NoticeTexts
{
    public function __construct(private readonly NoticeSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, string> texto limpio por idioma
     *
     * @throws ValidationException
     */
    public function prepare(array $body): array
    {
        $validated = Validator::make(['body' => $body], StorePrivacyNoticeRequest::rulesFor())->validate();

        $clean = [];

        foreach ($validated['body'] as $locale => $html) {
            $text = $this->sanitizer->clean((string) $html);

            if ($text !== '') {
                $clean[(string) $locale] = $text;
            }
        }

        return $clean;
    }
}
