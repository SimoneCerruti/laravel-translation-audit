<?php

declare(strict_types=1);

namespace TranslationAudit\Data;

use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\CommandOptionHelper;

/** The config of the unused translations purge, and the options overriding it. */
readonly class PurgeUnusedTranslationsConfig {
    public function __construct(
        public bool $is_dry_run,
    ) {}

    /**
     * @throws InvalidConfigException
     */
    public static function fromInput(CommandOptionHelper $options_helper): self {
        return new self(
            is_dry_run: $options_helper->boolean('dry-run', false),
        );
    }
}
