<?php

declare(strict_types=1);

namespace TranslationAudit\Support;

use BackedEnum;
use Illuminate\Support\Collection;
use TranslationAudit\Data\DynamicTranslationKey;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Exceptions\InvalidConfigException;

/**
 * The values the dynamic part of the dynamic keys can take, by the pattern of the dynamic keys.
 *
 * @internal
 */
final readonly class DynamicKeyValues {
    /**
     * @param  array<string, non-empty-list<non-empty-string>>  $values  The values of each pattern.
     */
    private function __construct(private array $values) {}

    /**
     * Build the values from the `dynamic_keys` config, which maps each pattern, with an asterisk in place of its dynamic part,
     * to a backed enum class, whose case values are the values, or to the list of the values.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws InvalidConfigException
     */
    public static function fromConfig(array $config): self {
        $values = [];

        foreach ($config as $pattern => $pattern_values) {
            if (! \is_string($pattern) || substr_count($pattern, '*') !== 1 || $pattern === '*') {
                throw new InvalidConfigException('The "dynamic_keys" config must map each pattern, with a single asterisk in place of the dynamic part and some static text, to its values.');
            }

            $values[$pattern] = self::getPatternValues($pattern, $pattern_values);
        }

        return new self($values);
    }

    /**
     * Replace each dynamic key whose pattern has values with a key for each value, used in the same file.
     *
     * @param  Collection<int, UsedTranslationKey>  $translation_keys
     * @return Collection<int, UsedTranslationKey>
     */
    public function expand(Collection $translation_keys): Collection {
        return $translation_keys
            ->flatMap($this->expandKey(...))
            ->values();
    }

    /**
     * The key for each value of the dynamic key, or the key itself when it is not dynamic or its pattern has no values.
     *
     * @return list<UsedTranslationKey>
     */
    private function expandKey(UsedTranslationKey $key): array {
        if (! $key->value instanceof DynamicTranslationKey || ! isset($this->values[$key->value->pattern()])) {
            return [$key];
        }

        // the pattern has a single dynamic part, between the two segments.
        [$prefix, $suffix] = $key->value->segments;
        $expanded_keys = [];

        foreach ($this->values[$key->value->pattern()] as $value) {
            // the pattern has some static text, so no key is falsy.
            $expanded_key = $prefix.$value.$suffix;

            if ($expanded_key) {
                $expanded_keys[] = new UsedTranslationKey($key->file, $expanded_key);
            }
        }

        return $expanded_keys;
    }

    /**
     * @return non-empty-list<non-empty-string>
     *
     * @throws InvalidConfigException
     */
    private static function getPatternValues(string $pattern, mixed $pattern_values): array {
        $error = "The values of the \"{$pattern}\" pattern in the \"dynamic_keys\" config must be a backed enum class, or a non-empty list of non-empty strings or integers.";

        if (\is_string($pattern_values)) {
            throw_unless(is_subclass_of($pattern_values, BackedEnum::class), InvalidConfigException::class, $error);

            $pattern_values = array_map(fn (BackedEnum $case): int|string => $case->value, $pattern_values::cases());
        }

        if (! \is_array($pattern_values) || $pattern_values === [] || ! array_is_list($pattern_values)) {
            throw new InvalidConfigException($error);
        }

        $values = [];

        foreach ($pattern_values as $value) {
            if (! \is_int($value) && (! \is_string($value) || $value === '')) {
                throw new InvalidConfigException($error);
            }

            $values[] = (string) $value;
        }

        return $values;
    }
}
