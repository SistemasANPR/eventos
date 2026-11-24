<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Resources\AssignedSubmissions\Pages;

use App\Actions\Reviews\SubmitReview;
use App\Enums\CriterionKind;
use App\Enums\ReviewAssignmentStatus;
use App\Exceptions\Domain\NoPublishedRubric;
use App\Exceptions\Domain\ReviewPeriodClosed;
use App\Filament\Resources\Submissions\Support\SubmissionDetail;
use App\Filament\Reviewer\Resources\AssignedSubmissions\Actions\DeclareConflictAction;
use App\Filament\Reviewer\Resources\AssignedSubmissions\AssignedSubmissionResource;
use App\Filament\Support\ActionErrors;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Support\Reviews\PublishedRubrics;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Detalle de la propuesta asignada y formulario de evaluación (plan 011, H-011-3, CA-011-12 a 15). Delgada:
 * el guardado, la validación contra la rúbrica y el periodo los hace `SubmitReview`; aquí solo se arma el
 * formulario con los criterios de la rúbrica publicada vigente (escala y texto libre) y se muestran sus
 * errores junto a cada campo. La asignación se busca en `ReviewerAssignments`: una ajena responde 404.
 *
 * Sin formulario cuando no se puede calificar (conflicto declarado, periodo cerrado, sin rúbrica): se
 * muestra el detalle y el motivo, nunca un botón que la Action rechazaría.
 */
class EvaluateSubmission extends EditRecord
{
    protected static string $resource = AssignedSubmissionResource::class;

    /** Hasta este tamaño de escala se ofrecen botones; más amplia, un campo numérico. */
    private const MAX_BUTTON_SCALE = 10;

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return $this->assignment()->submission->title;
    }

    #[Unexposed]
    public function getBreadcrumb(): string
    {
        return __('reviewer.evaluate.breadcrumb');
    }

    /** Sin acciones de borrado ni de vista del recurso base; solo declarar conflicto (CA-011-17). */
    protected function getHeaderActions(): array
    {
        return [DeclareConflictAction::make()];
    }

    /** Carga lo ya guardado (borrador o evaluación enviada) en los criterios de la rúbrica vigente. */
    protected function fillForm(): void
    {
        $criteria = $this->rubric()?->criteria->keyBy('id') ?? collect();
        $answers = [];
        $review = $this->review();

        foreach ($review === null ? [] : $review->answers as $answer) {
            $criterion = $criteria->get($answer->rubric_criterion_id);

            if ($criterion instanceof RubricCriterion) {
                $answers[$criterion->id] = $this->storedValue($criterion, $answer);
            }
        }

        $this->form->fill(['answers' => $answers]);
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->components(array_map(
            fn (RubricCriterion $criterion) => $this->field($criterion),
            $this->rubric()?->criteria->all() ?? [],
        ));
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.reviewer.assignment-detail')->viewData(fn (): array => $this->detail()),
            $this->canGrade()
                ? Section::make(__('reviewer.evaluate.heading'))
                    ->description(__('reviewer.evaluate.description'))
                    ->schema([
                        Form::make([EmbeddedSchema::make('form')])
                            ->id('review-form')
                            ->livewireSubmitHandler('submitReview')
                            ->footer([Actions::make([$this->submitAction(), $this->draftAction()])->key('review-actions')]),
                    ])
                : View::make('filament.reviewer.cannot-grade')->viewData(fn (): array => ['reason' => $this->cannotGradeReason()]),
        ]);
    }

    /** Enviar la evaluación (o enviarla de nuevo si ya estaba enviada). */
    #[Exposed]
    public function submitReview(): void
    {
        $this->persist(false);
    }

    /** Guardar lo avanzado sin enviarlo. */
    #[Exposed]
    public function saveDraft(): void
    {
        $this->persist(true);
    }

    private function draftAction(): Action
    {
        return Action::make('saveDraft')
            ->label(__('reviewer.evaluate.save_draft'))
            ->color('gray')
            ->action('saveDraft');
    }

    private function submitAction(): Action
    {
        return Action::make('submitReview')
            ->label(fn (): string => __($this->assignment()->status === ReviewAssignmentStatus::Submitted ? 'reviewer.evaluate.resubmit' : 'reviewer.evaluate.submit'))
            ->submit('submitReview');
    }

    private function persist(bool $asDraft): void
    {
        $state = $this->form->getState();
        $answers = is_array($state['answers'] ?? null) ? $state['answers'] : [];

        try {
            app(SubmitReview::class)->handle(ActionErrors::actor(), $this->assignment(), $answers, $asDraft);
        } catch (ValidationException $exception) {
            // Los errores de cada criterio se pintan junto a su campo.
            throw ActionErrors::forForm($exception);
        } catch (ReviewPeriodClosed|NoPublishedRubric $exception) {
            Notification::make()->danger()->title($exception->getMessage())->persistent()->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__($asDraft ? 'reviewer.evaluate.draft_saved' : 'reviewer.evaluate.submitted'))
            ->send();

        $this->redirect(AssignedSubmissionResource::getUrl($asDraft ? 'evaluate' : 'index', $asDraft ? ['record' => $this->assignment()] : []));
    }

    private function field(RubricCriterion $criterion): ToggleButtons|TextInput|Textarea
    {
        $name = "answers.{$criterion->id}";
        $label = $criterion->labelFor(app()->getLocale());

        if ($criterion->kind === CriterionKind::Text) {
            return Textarea::make($name)->label($label)->rows(4)->maxLength(5000);
        }

        $hint = __('reviewer.evaluate.scale_hint', ['min' => $criterion->scale_min, 'max' => $criterion->scale_max]);

        if ($criterion->scale_max - $criterion->scale_min < self::MAX_BUTTON_SCALE) {
            $values = range($criterion->scale_min, $criterion->scale_max);

            return ToggleButtons::make($name)->label($label)->options(array_combine($values, array_map(strval(...), $values)))->inline()->helperText($hint);
        }

        return TextInput::make($name)->label($label)->numeric()->integer()->minValue($criterion->scale_min)->maxValue($criterion->scale_max)->helperText($hint);
    }

    private function storedValue(RubricCriterion $criterion, ReviewAnswer $answer): int|string|null
    {
        return $criterion->kind === CriterionKind::Scale ? $answer->value_int : $answer->value_text;
    }

    /** @return array<string, mixed> */
    private function detail(): array
    {
        $assignment = $this->assignment();

        return [
            'assignment' => $assignment,
            'submission' => $assignment->submission,
            'event' => $assignment->event,
            'detail' => SubmissionDetail::forReviewer($assignment->submission, CurrentOrganization::get()),
        ];
    }

    private function canGrade(): bool
    {
        return $this->cannotGradeReason() === null;
    }

    /** Por qué no se puede calificar, o `null` si se puede. */
    private function cannotGradeReason(): ?string
    {
        $assignment = $this->assignment();

        return match (true) {
            ! Gate::allows('submit', [Review::class, $assignment]) => __('reviewer.evaluate.cannot.conflict'),
            ! $assignment->event->reviewPeriodIsOpen() => __('reviewer.evaluate.cannot.period_closed'),
            $this->rubric() === null => __('reviewer.evaluate.cannot.no_rubric'),
            default => null,
        };
    }

    private function rubric(): ?Rubric
    {
        $assignment = $this->assignment();

        return PublishedRubrics::latestFor($assignment->event_id, $assignment->submission->submission_type_id);
    }

    private function review(): ?Review
    {
        return Review::query()->where('review_assignment_id', $this->assignment()->id)->with('answers')->first();
    }

    private function assignment(): ReviewAssignment
    {
        $record = $this->getRecord();

        return $record instanceof ReviewAssignment ? $record : throw new LogicException('La página no tiene una asignación.');
    }
}
