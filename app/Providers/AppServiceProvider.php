<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Responses\Auth\LoginResponse;
use App\Models\User;
use App\Policies\MembershipPolicy;
use App\Support\Auth\AuthorGuard;
use App\Support\Auth\AuthorSession;
use App\Support\Calls\SubmissionTrackUsage;
use App\Support\Calls\TrackUsageChecker;
use App\Support\Files\FileFieldRegistry;
use App\Support\Files\PublishedFormFileFields;
use App\Support\Files\SessionUploadTokenVerifier;
use App\Support\Files\UploadTokenVerifier;
use App\Support\Forms\EloquentPendingUploads;
use App\Support\Forms\EloquentSubmissionAnswerCounter;
use App\Support\Forms\IssuedUploadTokens;
use App\Support\Forms\PendingUploadRepository;
use App\Support\Forms\SessionIssuedUploadTokens;
use App\Support\Forms\SubmissionAnswerCounter;
use App\Support\Reviews\NoRecordedReviews;
use App\Support\Reviews\RubricUsageChecker;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Formulario público y propuestas (planes 005, 006 y 007).
        $this->app->bind(FileFieldRegistry::class, PublishedFormFileFields::class);
        $this->app->bind(IssuedUploadTokens::class, SessionIssuedUploadTokens::class);
        $this->app->bind(UploadTokenVerifier::class, SessionUploadTokenVerifier::class);
        $this->app->bind(PendingUploadRepository::class, EloquentPendingUploads::class);
        $this->app->bind(TrackUsageChecker::class, SubmissionTrackUsage::class);
        $this->app->bind(SubmissionAnswerCounter::class, EloquentSubmissionAnswerCounter::class);
        // Costura hacia las evaluaciones (plan 011, F4): hasta entonces ninguna rubrica tiene evaluaciones.
        $this->app->bind(RubricUsageChecker::class, NoRecordedReviews::class);
        // Acceso único (plan 017): cada persona llega a su portal, no al panel desde el que inició sesión.
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Detecta carga perezosa, atributos faltantes y asignación masiva (app/CLAUDE.md §5).
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->isolateQueuedJobsContext();

        // Guard del autor de una propuesta, sin cuenta y separado del panel (plan 006, DA-3).
        Auth::extend('author', static fn (Application $app): AuthorGuard => new AuthorGuard($app->make(AuthorSession::class)));

        // La gestión del equipo se autoriza sobre el usuario (el integrante) con una Policy propia.
        Gate::policy(User::class, MembershipPolicy::class);

        // Pantallas de solo lectura del panel de plataforma (plan 016). Deniega por defecto; no es un `Gate::before`.
        Gate::define('platform.access', static fn (User $user): bool => $user->is_platform_admin);

        // Contraseñas: mínimo 12 caracteres y fuera de la lista de expuestas (CA-001-31).
        // `uncompromised()` consulta un servicio externo y, si falla, rechaza (falla cerrado).
        Password::defaults(fn (): Password => Password::min(12)->uncompromised());
    }

    /**
     * El contexto de organización es estado del proceso y un worker vive
     * muchos jobs: cada job empieza y termina sin contexto heredado (CA-001-13);
     * establece el suyo con `CurrentOrganization::run()`. La conexión `sync`
     * se excluye: ahí el job corre dentro de quien lo despacha, que conserva
     * su propio contexto.
     */
    private function isolateQueuedJobsContext(): void
    {
        $forgetContext = static function (JobProcessing|JobProcessed|JobFailed|JobExceptionOccurred $event): void {
            if ($event->connectionName !== 'sync') {
                CurrentOrganization::forget();
            }
        };

        Queue::before($forgetContext);
        Queue::after($forgetContext);
        Queue::failing($forgetContext);
        Queue::exceptionOccurred($forgetContext);
        Queue::looping(static fn () => CurrentOrganization::forget());
    }
}
