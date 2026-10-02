<?php

declare(strict_types=1);

namespace TranslationAudit\Data;

use TranslationAudit\Enums\SaveFormat;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\CommandOptionHelper;

/** The config of the translation audit, and the options overriding it. */
readonly class AuditTranslationsConfig {
    /**
     * @param  ?SaveTarget  $save_target  Where to save the audit result, null when it is not saved.
     */
    public function __construct(
        public ?SaveTarget $save_target,
        public bool $audit_unused,
    ) {}

    /**
     * @throws InvalidConfigException
     */
    public static function fromInput(CommandOptionHelper $options_helper): self {
        return new self(
            save_target: self::getSaveTarget($options_helper),
            audit_unused: $options_helper->booleanOrConfig('unused', 'translation-audit.audit_unused', false),
        );
    }

    /**
     * The options are resolved only when the result is saved, so that the invalid ones fail only then.
     *
     * @throws InvalidConfigException
     */
    private static function getSaveTarget(CommandOptionHelper $options_helper): ?SaveTarget {
        if (! $options_helper->booleanOrConfig('save', 'translation-audit.always_save', false)) {
            return null;
        }

        return new SaveTarget(
            $options_helper->enumOrConfig('save-format', 'translation-audit.save_format', SaveFormat::class),
            $options_helper->nonEmptyStringOrConfig('save-path', 'translation-audit.save_path'),
            $options_helper->nonEmptyStringOrConfig('save-name', 'translation-audit.save_name'),
        );
    }
}
