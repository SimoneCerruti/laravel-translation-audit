<?php

declare(strict_types=1);

namespace TranslationAudit\Support;

use TranslationAudit\Enums\AuditType;
use TranslationAudit\Exceptions\InvalidConfigException;

/**
 * The values of a list config, like the glob patterns of the ignored paths, each applying to every audit or only to the audit it is mapped to.
 *
 * @internal
 */
final readonly class AuditScopedList {
    /**
     * @param  list<array{non-falsy-string, AuditType|null}>  $values  Each value, with the audit it is limited to, or null for every audit.
     */
    private function __construct(private array $values) {}

    /**
     * Build the values from the config, which lists the values applying to every audit, or maps them to an AuditType case, or its value, to apply only to that audit.
     *
     * @param  non-falsy-string  $name  The name of the config, for the error.
     * @param  array<array-key, mixed>  $config
     *
     * @throws InvalidConfigException
     */
    public static function fromConfig(string $name, array $config): self {
        $values = [];

        foreach ($config as $key => $value) {
            if (\is_int($key) && \is_string($value) && $value) {
                $values[] = [$value, null];

                continue;
            }

            $audit = AuditType::fromConfig($value);

            if (! \is_string($key) || ! $key || ! $audit instanceof AuditType) {
                throw new InvalidConfigException("The \"{$name}\" config must contain only non-empty strings, or non-empty strings mapped to an AuditType case or its value.");
            }

            $values[] = [$key, $audit];
        }

        return new self($values);
    }

    /**
     * The values applying to every audit.
     *
     * @return list<non-falsy-string>
     */
    public function forEveryAudit(): array {
        return $this->filter(fn (?AuditType $value_audit): bool => ! $value_audit instanceof AuditType);
    }

    /**
     * The values limited to the audit.
     *
     * @return list<non-falsy-string>
     */
    public function onlyFor(AuditType $audit): array {
        return $this->filter(fn (?AuditType $value_audit): bool => $value_audit === $audit);
    }

    /**
     * The values applying to the audit: those applying to every audit, and those limited to it.
     *
     * @return list<non-falsy-string>
     */
    public function for(AuditType $audit): array {
        return $this->filter(fn (?AuditType $value_audit): bool => ! $value_audit instanceof AuditType || $value_audit === $audit);
    }

    /**
     * @param  callable(AuditType|null): bool  $applies
     * @return list<non-falsy-string>
     */
    private function filter(callable $applies): array {
        $values = [];

        foreach ($this->values as [$value, $value_audit]) {
            if ($applies($value_audit) && ! \in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
