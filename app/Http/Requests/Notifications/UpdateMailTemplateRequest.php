<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use App\Models\MailTemplate;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guardar la plantilla de correo de una organización (plan 008, CA-008-5 y CA-008-18).
 *
 * Las reglas viven en un método estático para que las use también la Action
 * `UpdateMailTemplate` y los campos de Filament, que no tienen petición HTTP. El asunto,
 * el nombre del remitente y el responder-a no admiten saltos de línea (inyección de
 * cabeceras). Que las variables del asunto y del cuerpo sean de la lista blanca de la
 * notificación lo comprueba `RenderMailTemplate::validate()`.
 */
final class UpdateMailTemplateRequest extends FormRequest
{
    public const SUBJECT_MAX = 200;

    public const BODY_MAX = 20000;

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
     * @param  list<string>  $locales  idiomas que admite la notificación
     * @return array<string, list<mixed>>
     */
    public static function fieldRules(array $locales = ['es', 'en', 'pt']): array
    {
        return [
            'locale' => ['required', 'string', 'in:'.implode(',', $locales)],
            'subject' => ['required', 'string', 'max:'.self::SUBJECT_MAX, 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:'.self::BODY_MAX],
            'from_name' => ['nullable', 'string', 'max:100', 'not_regex:/[\r\n]/'],
            'reply_to' => ['nullable', 'string', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'],
        ];
    }
}
