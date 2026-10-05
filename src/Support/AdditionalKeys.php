<?php

declare(strict_types=1);

namespace TranslationAudit\Support;

use Illuminate\Support\Collection;
use TranslationAudit\Data\DynamicTranslationKey;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Exceptions\InvalidConfigException;

/** The translation keys to audit as used even though the scan cannot detect them. */
final readonly class AdditionalKeys {
    /** The file the additional keys are audited as used in. */
    private const string FILE = 'config/translation-audit.php';

    /**
     * @param  list<non-falsy-string|DynamicTranslationKey>  $keys
     */
    private function __construct(private array $keys) {}

    /**
     * Build the keys from the `additional_keys` config, which lists the keys, or the patterns of the dynamic keys,
     * with an asterisk in place of each dynamic part and some static text.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws InvalidConfigException
     */
    public static function fromConfig(array $config): self {
        $keys = [];

        foreach ($config as $key) {
            if (! \is_string($key) || ! $key || trim($key, '*') === '') {
                throw new InvalidConfigException('The "additional_keys" config must contain only keys, or patterns of dynamic keys with an asterisk in place of each dynamic part and some static text.');
            }

            $keys[] = str_contains($key, '*') ? new DynamicTranslationKey(explode('*', $key)) : $key;
        }

        return new self($keys);
    }

    /**
     * Add the keys to the keys used in the scanned files, as used in the config file.
     *
     * @param  Collection<int, UsedTranslationKey>  $translation_keys
     * @return Collection<int, UsedTranslationKey>
     */
    public function apply(Collection $translation_keys): Collection {
        return $translation_keys
            ->concat(array_map(fn (string|DynamicTranslationKey $key): UsedTranslationKey => new UsedTranslationKey(self::FILE, $key), $this->keys))
            ->values();
    }
}
