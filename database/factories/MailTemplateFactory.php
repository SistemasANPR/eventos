<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MailTemplate;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MailTemplate> */
class MailTemplateFactory extends Factory
{
    protected $model = MailTemplate::class;

    /**
     * Nivel organización (`event_id` nulo). Acepta `organization_id` explícito; sin él usa la
     * organización en contexto y, sin contexto, crea una. La clave de notificación lleva un
     * sufijo único para poder crear varias filas de una organización sin chocar con los
     * índices únicos; las pruebas que necesitan una clave real la indican.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'organization_id' => fn (): string => CurrentOrganization::has()
                ? CurrentOrganization::id()
                : Organization::factory()->create()->id,
            'event_id' => null,
            'notification_key' => 'submission.received.'.Str::lower(Str::random(8)),
            'locale' => 'es',
            'subject' => 'Recibimos tu propuesta',
            'body' => 'Hola {{ author_name }}, recibimos tu propuesta.',
            'from_name' => null,
            'reply_to' => null,
        ];
    }
}
