<?php

declare(strict_types=1);

namespace App\Filament\Pages\Privacy;

use App\Actions\Privacy\SetRetentionPolicies;
use App\Enums\RetentionCategory;
use App\Filament\Support\ActionErrors;
use App\Http\Requests\SetRetentionPoliciesRequest;
use App\Models\RetentionPolicy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Plazos de retención de la organización (plan 014, H-014-6). Es solo presentación: quién puede entrar lo decide
 * `RetentionPolicyPolicy` (`retention.manage`, por defecto solo `org_admin`) y guardar llama a
 * `SetRetentionPolicies`, que vuelve a autorizar y validar. Sin valores por defecto: un campo vacío es una
 * categoría sin plazo, que el trabajo programado no toca.
 *
 * @property Schema $form
 */
class RetentionSettings extends Page
{
    protected static ?string $slug = 'retention';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 91;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.organization');
    }

    public static function getNavigationLabel(): string
    {
        return __('privacy_admin.navigation.retention');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('manage', RetentionPolicy::class);
    }

    public function mount(): void
    {
        $this->form->fill($this->currentPolicies());
    }

    public function getTitle(): string|Htmlable
    {
        return __('privacy_admin.retention.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('privacy_admin.retention.subtitle');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(collect(RetentionCategory::cases())->map(
                fn (RetentionCategory $category): TextInput => TextInput::make($category->value)
                    ->label($category->label())
                    ->helperText(__("privacy_admin.retention.descriptions.{$category->value}").' '.__('privacy_admin.retention.fields.days_help'))
                    ->suffix(__('privacy_admin.retention.fields.days'))
                    ->numeric()
                    ->integer()
                    ->minValue(SetRetentionPoliciesRequest::MIN_DAYS)
                    ->maxValue(SetRetentionPoliciesRequest::MAX_DAYS),
            )->all())
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('privacy_admin.retention.actions.save'))
                        ->submit('save'),
                ])->key('form-actions'),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        try {
            app(SetRetentionPolicies::class)->handle(ActionErrors::actor(), $state);
        } catch (ValidationException $exception) {
            throw ActionErrors::forForm($exception);
        }

        Notification::make()->success()->title(__('privacy_admin.retention.notifications.saved'))->send();
    }

    /** @return array<string, int|null> días por categoría; nulo donde no hay plazo */
    private function currentPolicies(): array
    {
        $current = RetentionPolicy::query()->get()->mapWithKeys(
            fn (RetentionPolicy $policy): array => [$policy->category->value => $policy->retention_days],
        );

        return collect(RetentionCategory::cases())
            ->mapWithKeys(fn (RetentionCategory $category): array => [$category->value => $current[$category->value] ?? null])
            ->all();
    }
}
