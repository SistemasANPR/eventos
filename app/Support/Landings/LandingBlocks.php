<?php

declare(strict_types=1);

namespace App\Support\Landings;

use App\Enums\LandingBlockType;
use App\Models\Event;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Valida y normaliza la lista completa de bloques que llega del editor (plan 022, CA-022-9, 10, 14).
 * Un bloque es `{id, type, ...campos}` (la forma del borrador del editor). Todo o nada: un error en
 * cualquier bloque rechaza la lista entera, con un error por campo (`blocks.2.title.es`). Los
 * identificadores los fija el servidor: el del cliente solo se conserva si ya existe en el borrador
 * actual (para que un bloque mantenga su identidad entre guardados); cualquier otro se descarta y se
 * genera uno nuevo.
 */
final class LandingBlocks
{
    /**
     * @param  list<string>  $currentIds  identificadores del borrador guardado
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function validate(Event $event, mixed $blocks, array $currentIds = []): array
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            throw ValidationException::withMessages(['blocks' => __('landings.errors.invalid')]);
        }

        if (count($blocks) > LandingBlockRules::MAX_BLOCKS) {
            throw ValidationException::withMessages(['blocks' => __('landings.errors.too_many_blocks', ['max' => LandingBlockRules::MAX_BLOCKS])]);
        }

        $locales = $event->offeredLocales();
        $errors = [];
        $clean = [];
        $used = [];

        foreach ($blocks as $index => $block) {
            $path = "blocks.{$index}";

            if (! is_array($block) || array_is_list($block)) {
                $errors[$path] = __('landings.errors.invalid');

                continue;
            }

            $type = is_string($block['type'] ?? null) ? LandingBlockType::tryFrom($block['type']) : null;

            if ($type === null) {
                $errors["{$path}.type"] = __('landings.errors.block_type');

                continue;
            }

            $checked = LandingBlockRules::check($type, array_diff_key($block, ['id' => 1, 'type' => 1]), $locales, $event->default_locale);

            foreach ($checked['errors'] as $field => $message) {
                $errors["{$path}.{$field}"] = $message;
            }

            $clean[] = ['id' => self::resolveId($block['id'] ?? null, $currentIds, $used), 'type' => $type->value, ...$checked['content']];
        }

        if ($errors === []) {
            $errors = self::limits($clean);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * El borrador tal como lo edita el cliente (`LandingBlockDraft[]`): textos completos por idioma, imágenes
     * con su URL de editor y claves de lista.
     *
     * @param  array<array-key, mixed>  $blocks
     * @param  list<string>  $locales
     * @param  Closure(string): ?string  $imageUrl
     * @return list<array<string, mixed>>
     */
    public static function forEditor(array $blocks, array $locales, Closure $imageUrl): array
    {
        $out = [];

        foreach ($blocks as $block) {
            $block = (array) $block;
            $type = LandingBlockType::tryFrom((string) ($block['type'] ?? ''));

            if ($type !== null) {
                $id = (string) $block['id'];
                $out[] = ['id' => $id, 'type' => $type->value, ...LandingBlockRules::present($type, $block, $locales, $imageUrl, $id)];
            }
        }

        return $out;
    }

    /**
     * ¿Dos listas de bloques dicen lo mismo? `jsonb` no conserva el orden de las claves de un objeto, así
     * que se compara en forma canónica (claves ordenadas; el orden de las listas sí cuenta).
     *
     * @param  array<array-key, mixed>|null  $a
     * @param  array<array-key, mixed>|null  $b
     */
    public static function same(?array $a, ?array $b): bool
    {
        return $a !== null && $b !== null && self::canonical($a) === self::canonical($b);
    }

    /**
     * Identificadores de imagen que referencian los bloques ya normalizados.
     *
     * @param  array<array-key, mixed>  $blocks
     * @return list<string>
     */
    public static function imageIds(array $blocks): array
    {
        $ids = [];

        foreach ($blocks as $block) {
            $block = (array) $block;
            $type = LandingBlockType::tryFrom((string) ($block['type'] ?? ''));

            if ($type !== null) {
                $ids = [...$ids, ...LandingBlockRules::imageIds($type, $block)];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $clean
     * @return array<string, string>
     */
    private static function limits(array $clean): array
    {
        if (count(self::imageIds($clean)) > LandingBlockRules::MAX_IMAGES) {
            return ['blocks' => __('landings.errors.too_many_images', ['max' => LandingBlockRules::MAX_IMAGES])];
        }

        if (strlen(json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > LandingBlockRules::MAX_DRAFT_BYTES) {
            return ['blocks' => __('landings.errors.too_large', ['max' => intdiv(LandingBlockRules::MAX_DRAFT_BYTES, 1024)])];
        }

        return [];
    }

    /**
     * @param  list<string>  $currentIds
     * @param  array<string, true>  $used
     */
    private static function resolveId(mixed $sent, array $currentIds, array &$used): string
    {
        $id = is_string($sent) && in_array($sent, $currentIds, true) && ! isset($used[$sent]) ? $sent : (string) Str::uuid();
        $used[$id] = true;

        return $id;
    }
}
