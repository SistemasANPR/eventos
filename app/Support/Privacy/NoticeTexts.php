<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Http\Requests\Privacy\StorePrivacyNoticeRequest;
use App\Models\Event;
use App\Support\Events\OfferedLocaleTexts;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Valida y sanitiza el texto de un aviso por idioma: se valida la forma y el
 * HTML se limpia ANTES de guardarlo (CA-003-15). Los idiomas que quedan vacíos
 * tras limpiar se descartan. Solo se aceptan los idiomas que ofrece el evento: los
 * demás se descartan sin error (plan 024, DA-5) y los textos ya guardados de un
 * idioma que el evento dejó de ofrecer se conservan (DA-1).
 */
final class NoticeTexts
{
    public function __construct(private readonly NoticeSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $body
     * @param  array<array-key, mixed>  $stored  texto ya guardado del aviso, del que se conservan los idiomas no ofrecidos
     * @return array<string, string> texto limpio por idioma
     *
     * @throws ValidationException
     */
    public function prepare(array $body, Event $event, array $stored = []): array
    {
        $validated = Validator::make(['body' => OfferedLocaleTexts::discardUnoffered($body, $event)], StorePrivacyNoticeRequest::rulesFor($event))->validate();

        $clean = [];

        foreach ($validated['body'] as $locale => $html) {
            $text = $this->sanitizer->clean((string) $html);

            if ($text !== '') {
                $clean[(string) $locale] = $text;
            }
        }

        return OfferedLocaleTexts::withUnoffered($clean, $stored, $event);
    }
}
