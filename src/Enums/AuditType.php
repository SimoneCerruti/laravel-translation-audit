<?php

declare(strict_types=1);

namespace TranslationAudit\Enums;

/**
 * The audits a config entry, like an ignored path, locale or key, can be limited to.
 *
 * @internal
 */
enum AuditType: string {
    case Missing = 'missing';
    case Unused = 'unused';

    /** The audit given in the config, as a case or its value, or null for any other value. */
    public static function fromConfig(mixed $value): ?self {
        if ($value instanceof self) {
            return $value;
        }

        return \is_string($value) ? self::tryFrom($value) : null;
    }
}
