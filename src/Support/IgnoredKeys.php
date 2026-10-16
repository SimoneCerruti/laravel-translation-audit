<?php

declare(strict_types=1);

namespace TranslationAudit\Support;

use TranslationAudit\Enums\AuditType;
use TranslationAudit\Exceptions\InvalidConfigException;

/**
 * The translation keys to skip in the audit, each in all the locales or only in the listed ones, and in every audit or only in one.
 *
 * @internal
 */
final readonly class IgnoredKeys {
    private const string INVALID_ENTRY = 'The "ignore_keys" config must contain only keys, or keys mapped to an AuditType case, or its value, or to a list of locales.';

    /**
     * @param  array<non-empty-string, non-empty-list<array{list<non-empty-string>|null, AuditType|null}>>  $keys  The ignored keys mapped to where they are ignored: the locales, null for all locales, and the audit, null for every audit.
     */
    private function __construct(private array $keys) {}

    /**
     * Build the ignored keys from the `ignore_keys` config, which lists keys ignored in all locales and audits,
     * or maps keys to an AuditType case, or its value, to ignore them in all locales but only in that audit,
     * or to the list of their ignored locales, along with an optional AuditType case to ignore them only in that audit.
     *
     * @param  array<array-key, mixed>  $values
     *
     * @throws InvalidConfigException
     */
    public static function fromConfig(array $values): self {
        $keys = [];

        foreach ($values as $key => $value) {
            if (\is_int($key) && \is_string($value) && $value) {
                $keys[$value][] = [null, null];

                continue;
            }

            if (! \is_string($key) || ! $key) {
                throw new InvalidConfigException(self::INVALID_ENTRY);
            }

            $keys[$key][] = self::getRule($key, $value);
        }

        return new self($keys);
    }

    /** Whether the key is ignored in the locale by the audit. */
    public function has(string $key, string $locale, AuditType $audit): bool {
        foreach ($this->keys[$key] ?? [] as [$locales, $rule_audit]) {
            if (($locales === null || \in_array($locale, $locales, true)) && ($rule_audit === null || $rule_audit === $audit)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the key is ignored, from the AuditType case or value it is mapped to, or from the list of its locales, with an optional AuditType case.
     *
     * @param  non-empty-string  $key
     * @return array{list<non-empty-string>|null, AuditType|null}
     *
     * @throws InvalidConfigException
     */
    private static function getRule(string $key, mixed $value): array {
        $audit = AuditType::fromConfig($value);

        if ($audit instanceof AuditType) {
            return [null, $audit];
        }

        if (! \is_array($value) || ! array_is_list($value)) {
            throw new InvalidConfigException(self::INVALID_ENTRY);
        }

        $locales = [];

        foreach ($value as $locale) {
            if ($locale instanceof AuditType && ! $audit instanceof AuditType) {
                $audit = $locale;

                continue;
            }

            if (! \is_string($locale) || ! $locale) {
                throw new InvalidConfigException("The locales of the \"{$key}\" key in the \"ignore_keys\" config must be non-empty strings, along with at most one AuditType case.");
            }

            $locales[] = $locale;
        }

        if ($audit instanceof AuditType && $locales === []) {
            throw new InvalidConfigException("The \"{$key}\" key in the \"ignore_keys\" config must list its locales along with the AuditType case, or be mapped to the AuditType case alone.");
        }

        return [$locales, $audit];
    }
}
