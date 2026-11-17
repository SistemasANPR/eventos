<?php

declare(strict_types=1);

namespace App\Http\Requests\Program;

use App\Enums\ProgramSocialNetwork;
use App\Models\Event;
use App\Models\Speaker;
use App\Models\SpeakerCategory;
use App\Rules\SocialLinkUrl;
use App\Support\Forms\Countries;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reglas de la ficha de un ponente (plan 021, CA-021-1, CA-021-2, DA-5, DA-17), reutilizadas por las
 * Actions y por Filament: solo nombre y apellido son obligatorios; el correo es único en el evento sin
 * distinguir mayúsculas entre fichas no borradas; las redes no se repiten y cada dirección es `https`
 * del dominio de su red; las categorías son del catálogo de ESTE evento. La fotografía se valida por
 * contenido en `StoreProgramImage`; aquí solo se exige que sea un archivo. Las comprobaciones contra
 * la base corren en la organización del evento (la consulta pasa por el filtro de organización).
 */
class StoreSpeakerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('create', [Speaker::class, $event]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $event = $this->route('event');

        return $event instanceof Event ? self::rulesFor($event) : [];
    }

    /**
     * @param  string|null  $ignoreId  ponente que se edita: no choca consigo mismo
     * @return array<string, mixed>
     */
    public static function rulesFor(Event $event, ?string $ignoreId = null): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'max:254', 'email:rfc', self::uniqueEmailInEvent($event, $ignoreId)],
            'country' => ['nullable', 'string', Rule::in(Countries::CODES)],
            'company' => ['nullable', 'string', 'max:160'],
            'job_title' => ['nullable', 'string', 'max:160'],
            'tagline' => ['nullable', 'string', 'max:160'],
            // HTML del editor; se sanea al guardar. El tope protege la base, no es el largo del texto visible.
            'bio' => ['nullable', 'string', 'max:20000'],
            'photo' => ['nullable', 'file'],
            'social_links' => ['nullable', 'array', 'max:'.count(ProgramSocialNetwork::cases()), self::distinctNetworks()],
            'social_links.*' => ['array:network,url'],
            'social_links.*.network' => ['required', Rule::enum(ProgramSocialNetwork::class)],
            'social_links.*.url' => ['required', 'string', 'max:255', new SocialLinkUrl],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['string', 'distinct', self::categoryOfEvent($event)],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }

    private static function uniqueEmailInEvent(Event $event, ?string $ignoreId): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($event, $ignoreId): void {
            if (! is_string($value)) {
                return;
            }

            // `lower()` en ambos lados: la misma función que el índice único de la base. El borrado lógico
            // ya excluye las fichas eliminadas: una ficha borrada libera su correo.
            $taken = Speaker::query()
                ->where('event_id', $event->id)
                ->whereRaw('lower(email) = lower(?)', [$value])
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();

            if ($taken) {
                $fail('program.speakers.errors.email_taken')->translate();
            }
        };
    }

    private static function distinctNetworks(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $networks = array_filter(array_map(
                static fn (mixed $link): mixed => is_array($link) ? ($link['network'] ?? null) : null,
                $value,
            ), 'is_string');

            if (count($networks) !== count(array_unique($networks))) {
                $fail('program.speakers.errors.social_network_repeated')->translate();
            }
        };
    }

    private static function categoryOfEvent(Event $event): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($event): void {
            if (! is_string($value)) {
                return;
            }

            // Un identificador mal formado no llega a la base; uno de otro evento u organización no se ve.
            $belongs = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1
                && SpeakerCategory::query()->where('event_id', $event->id)->whereKey($value)->exists();

            if (! $belongs) {
                $fail('program.speakers.errors.category_invalid')->translate();
            }
        };
    }
}
