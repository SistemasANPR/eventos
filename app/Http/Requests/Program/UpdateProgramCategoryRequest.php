<?php

declare(strict_types=1);

namespace App\Http\Requests\Program;

use App\Enums\ProgramCategoryKind;
use App\Models\ActivityCategory;
use App\Models\Event;
use App\Models\SpeakerCategory;
use Illuminate\Support\Facades\Gate;

/** Mismas reglas que al crear; editar no cambia el evento ni la posición, y la categoría no choca consigo misma. */
class UpdateProgramCategoryRequest extends StoreProgramCategoryRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return ($category instanceof SpeakerCategory || $category instanceof ActivityCategory) && Gate::allows('update', $category);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $event = $this->route('event');
        $category = $this->route('category');

        if (! $event instanceof Event || ! ($category instanceof SpeakerCategory || $category instanceof ActivityCategory)) {
            return [];
        }

        return self::rulesFor($event, ProgramCategoryKind::of($category), $category->id);
    }
}
