<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\ProgramSocialNetwork;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * La dirección de una red social de un ponente (plan 021, DA-17, CA-021-2): `https`, sin credenciales
 * incrustadas y con el dominio de la red elegida en el mismo elemento (`social_links.N.network`).
 * Si la red no es válida, no opina: ya falla su propia regla.
 */
final class SocialLinkUrl implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @param  array<string, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $network = ProgramSocialNetwork::tryFrom((string) data_get($this->data, preg_replace('/\.url$/', '.network', $attribute) ?? ''));

        if ($network === null) {
            return;
        }

        $parts = parse_url($value);

        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/\s/', $value) === 1) {
            $fail('program.speakers.errors.social_url_https')->translate();

            return;
        }

        if (! $network->acceptsHost($parts['host'])) {
            $fail('program.speakers.errors.social_url_domain')->translate(['network' => __("program.speakers.networks.{$network->value}")]);
        }
    }
}
