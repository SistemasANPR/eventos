<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use App\Models\MailTemplate;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Vista previa de una plantilla sin guardarla ni enviarla (plan 008, CA-008-16). Mismas
 * reglas de asunto y cuerpo que al guardar: lo que no se podría guardar, tampoco se previsualiza.
 */
final class PreviewMailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('update', MailTemplate::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return self::fieldRules();
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, list<mixed>>
     */
    public static function fieldRules(array $locales = ['es', 'en', 'pt']): array
    {
        return [
            'locale' => UpdateMailTemplateRequest::fieldRules($locales)['locale'],
            'subject' => UpdateMailTemplateRequest::fieldRules($locales)['subject'],
            'body' => UpdateMailTemplateRequest::fieldRules($locales)['body'],
        ];
    }
}
