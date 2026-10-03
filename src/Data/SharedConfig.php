<?php

declare(strict_types=1);

namespace TranslationAudit\Data;

use InvalidArgumentException;
use Laravel\AgentDetector\AgentDetector;
use TranslationAudit\Actions\DetectAppLocales;
use TranslationAudit\Enums\DisplayFormat;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\CommandOptionHelper;
use TranslationAudit\Support\DynamicKeyValues;
use TranslationAudit\Support\IgnoredKeys;

/** The config, and the options overriding it, shared by every command scanning the app for the translation keys it uses. */
readonly class SharedConfig {
    /**
     * @param  list<non-falsy-string>  $scan_paths
     * @param  list<non-falsy-string>  $ignore_paths
     * @param  list<non-falsy-string>  $ignore_links
     * @param  list<string>  $supported_locales
     * @param  list<non-falsy-string>  $ignore_locales
     * @param  list<non-falsy-string>  $unused_ignore_paths
     * @param  DisplayFormat  $display_format  The format in which to display the result, json for an agent.
     * @param  bool  $disable_progress_bar  Whether the progress bar is hidden when asked to or for an agent, regardless of the output being a terminal.
     * @param  bool  $disable_summary  Whether the result summary is hidden when asked to or for an agent.
     */
    public function __construct(
        public array $scan_paths,
        public array $ignore_paths,
        public array $ignore_links,
        public array $supported_locales,
        public array $ignore_locales,
        public array $unused_ignore_paths,
        public IgnoredKeys $ignore_keys,
        public DynamicKeyValues $dynamic_keys,
        public bool $follow_links,
        public bool $output_for_agent,
        public DisplayFormat $display_format,
        public bool $disable_progress_bar,
        public bool $disable_summary,
    ) {}

    /**
     * @throws InvalidConfigException
     */
    public static function fromInput(CommandOptionHelper $options_helper): self {
        $output_for_agent = AgentDetector::detect()->isAgent || $options_helper->boolean('for-agent', false);

        return new self(
            scan_paths: self::getScanPaths(),
            ignore_paths: self::getConfigStringList('ignore_paths'),
            ignore_links: self::getConfigStringList('ignore_links'),
            supported_locales: self::getSupportedLocales(),
            ignore_locales: self::getConfigStringList('ignore_locales'),
            unused_ignore_paths: self::getConfigStringList('unused_ignore_paths'),
            ignore_keys: self::getIgnoreKeys(),
            dynamic_keys: self::getDynamicKeys(),
            follow_links: $options_helper->booleanOrConfig('follow-links', 'translation-audit.always_follow_links', false),
            output_for_agent: $output_for_agent,
            display_format: $output_for_agent
                ? DisplayFormat::Json
                : $options_helper->enumOrConfig('display-format', 'translation-audit.display_format', DisplayFormat::class),
            disable_progress_bar: $options_helper->booleanOrConfig('no-progress', 'translation-audit.disable_progress_bar', false) || $output_for_agent,
            disable_summary: $options_helper->booleanOrConfig('no-summary', 'translation-audit.disable_summary', false) || $output_for_agent,
        );
    }

    /**
     * The supported locales which are not ignored.
     *
     * @return list<string>
     */
    public function locales(): array {
        return array_values(array_diff($this->supported_locales, $this->ignore_locales));
    }

    /**
     * @throws InvalidConfigException
     */
    private static function getIgnoreKeys(): IgnoredKeys {
        try {
            $values = config()->array('translation-audit.ignore_keys');
        } catch (InvalidArgumentException) {
            throw new InvalidConfigException('The "ignore_keys" config must be an array.');
        }

        return IgnoredKeys::fromConfig($values);
    }

    /**
     * @throws InvalidConfigException
     */
    private static function getDynamicKeys(): DynamicKeyValues {
        try {
            $values = config()->array('translation-audit.dynamic_keys');
        } catch (InvalidArgumentException) {
            throw new InvalidConfigException('The "dynamic_keys" config must be an array.');
        }

        return DynamicKeyValues::fromConfig($values);
    }

    /**
     * @return list<non-falsy-string>
     *
     * @throws InvalidConfigException
     */
    private static function getConfigStringList(string $key): array {
        try {
            $values = config()->array("translation-audit.{$key}");
        } catch (InvalidArgumentException) {
            throw new InvalidConfigException("The \"{$key}\" config must be an array.");
        }

        $strings = [];

        foreach ($values as $value) {
            if (! \is_string($value) || ! $value) {
                throw new InvalidConfigException("The \"{$key}\" config must contain only non-empty strings.");
            }

            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * @return list<non-falsy-string>
     *
     * @throws InvalidConfigException
     */
    private static function getScanPaths(): array {
        $scan_paths = self::getConfigStringList('scan_paths');

        throw_if($scan_paths === [], InvalidConfigException::class, 'Specify which paths to scan in the "scan_paths" config.');

        return $scan_paths;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidConfigException
     */
    private static function getSupportedLocales(): array {
        $supported_locales_config = self::getConfigStringList('supported_locales');

        throw_if($supported_locales_config === [], InvalidConfigException::class, 'The "supported_locales" config must be an array listing the app supported locales. Use "auto" as the first value of the array to autodetect locales from the "lang" folder');

        $is_auto = $supported_locales_config[0] === 'auto';

        if ($is_auto) {
            return app(DetectAppLocales::class)->handle();
        }

        return $supported_locales_config;
    }
}
