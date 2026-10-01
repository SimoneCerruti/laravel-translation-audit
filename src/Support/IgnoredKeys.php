<?php

declare(strict_types=1);

namespace TranslationAudit\Support;

use TranslationAudit\Exceptions\InvalidConfigException;

/** The translation keys to skip in the audit, each in all the locales or only in the listed ones. */
final readonly class IgnoredKeys {
    /**
     * @param  array<non-empty-string, list<non-empty-string>|null>  $keys  The ignored keys mapped to their ignored locales, null for all locales.
     */
    private function __construct(private array $keys) {}

    /**
     * Build the ignored keys from the `ignore_keys` config, which lists keys ignored in all locales, or keys mapped to the list of their ignored locales.
     *
     * @param  array<array-key, mixed>  $values
     *
     * @throws InvalidConfigException
     */
    public static function fromConfig(array $values): self {
        $keys = [];

        foreach ($values as $key => $value) {
            if (\is_int($key) && \is_string($value) && $value) {
                $keys[$value] = null;

                continue;
            }

            if (! \is_string($key) || ! $key || ! \is_array($value) || ! array_is_list($value)) {
                throw new InvalidConfigException('The "ignore_keys" config must contain only keys, or keys mapped to a list of locales.');
            }

            foreach ($value as $locale) {
                if (! \is_string($locale) || ! $locale) {
                    throw new InvalidConfigException("The locales of the \"{$key}\" key in the \"ignore_keys\" config must be non-empty strings.");
                }
            }

            if (! \array_key_exists($key, $keys)) {
                $keys[$key] = $value;
            }
        }

        return new self($keys);
    }

    /** Whether the key is ignored in the locale. */
    public function has(string $key, string $locale): bool {
        if (! \array_key_exists($key, $this->keys)) {
            return false;
        }

        $locales = $this->keys[$key];

        return $locales === null || \in_array($locale, $locales, true);
    }
}
