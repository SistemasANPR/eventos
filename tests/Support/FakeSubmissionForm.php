<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\CallForSubmissions;
use App\Support\Files\FileFieldRegistry;
use App\Support\Files\UploadTokenVerifier;

/**
 * Doble del formulario y de la sesión del formulario público, que llegan con los
 * planes 005 y 006. Acepta los campos de archivo y los tokens indicados; el
 * resto se rechaza, igual que haría la implementación real.
 */
final class FakeSubmissionForm implements FileFieldRegistry, UploadTokenVerifier
{
    /**
     * @param  list<string>  $fields
     * @param  list<string>  $tokens  tokens emitidos por el formulario; `null` acepta cualquiera
     */
    public function __construct(private readonly array $fields = ['presentation', 'poster'], private readonly ?array $tokens = null) {}

    /**
     * Sustituye las implementaciones por defecto (que rechazan todo) por este doble.
     *
     * @param  list<string>|null  $tokens
     */
    public static function install(?array $tokens = null): void
    {
        $fake = new self(tokens: $tokens);
        app()->instance(FileFieldRegistry::class, $fake);
        app()->instance(UploadTokenVerifier::class, $fake);
    }

    public function exists(CallForSubmissions $call, string $fieldKey): bool
    {
        return in_array($fieldKey, $this->fields, true);
    }

    public function isIssued(CallForSubmissions $call, string $token): bool
    {
        return $this->tokens === null || in_array($token, $this->tokens, true);
    }
}
