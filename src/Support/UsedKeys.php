<?php

declare(strict_types=1);

namespace TranslationAudit\Support;

use Illuminate\Support\Collection;
use TranslationAudit\Data\DynamicTranslationKey;
use TranslationAudit\Data\UsedTranslationKey;

/**
 * The translation keys used in the scanned files, each literal or built at runtime.
 *
 * @internal
 */
final readonly class UsedKeys {
    /**
     * @param  array<string, true>  $keys  The literal keys, as array keys for constant-time lookups.
     * @param  list<DynamicTranslationKey>  $dynamic_keys
     */
    private function __construct(
        private array $keys,
        private array $dynamic_keys,
    ) {}

    /** @param  Collection<int, UsedTranslationKey>  $translation_keys */
    public static function fromTranslationKeys(Collection $translation_keys): self {
        $keys = [];
        $dynamic_keys = [];

        foreach ($translation_keys as $translation_key) {
            if ($translation_key->value instanceof DynamicTranslationKey) {
                // the same dynamic key used in many files is matched once.
                $dynamic_keys[json_encode($translation_key->value->segments, JSON_THROW_ON_ERROR)] = $translation_key->value;
            } else {
                $keys[$translation_key->value] = true;
            }
        }

        return new self($keys, array_values($dynamic_keys));
    }

    /**
     * The same keys without their leading slashes, e.g. `admin/form.title` for `/admin/form.title`,
     * like the translator does when it loads the PHP translation file of the group.
     */
    public function withoutLeadingSlashes(): self {
        $keys = [];

        foreach (array_keys($this->keys) as $key) {
            $keys[ltrim((string) $key, '/')] = true;
        }

        $dynamic_keys = array_map(
            fn (DynamicTranslationKey $key): DynamicTranslationKey => new DynamicTranslationKey([ltrim($key->segments[0], '/'), ...\array_slice($key->segments, 1)]),
            $this->dynamic_keys,
        );

        return new self($keys, $dynamic_keys);
    }

    /** Whether the key is used literally, or matches a dynamic key, which can take its value at runtime. */
    public function has(string $key): bool {
        if (isset($this->keys[$key])) {
            return true;
        }

        return array_any($this->dynamic_keys, fn (DynamicTranslationKey $dynamic_key): bool => $dynamic_key->matches($key));
    }
}
