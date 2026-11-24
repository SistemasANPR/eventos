<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use App\Models\Submission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Entrada de «publicar resultados» (plan 012, F2, CA-012-19 y 22): la convocatoria elegida. La pantalla la
 * resuelve con el filtro de organización y evento (una de otra organización responde 404) y
 * `PublishResults` vuelve a autorizar con la Policy.
 */
final class PublishResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Submission::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'call_id' => ['required', 'uuid'],
        ];
    }
}
