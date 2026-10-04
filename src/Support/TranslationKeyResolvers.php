<?php

declare(strict_types=1);

namespace TranslationAudit\Support;

use Illuminate\Support\Collection;
use RuntimeException;
use Symfony\Component\Finder\Glob;
use Throwable;
use TranslationAudit\Contracts\TranslationKeyResolver;
use TranslationAudit\Data\DynamicTranslationKey;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Exceptions\InvalidConfigException;
use UnexpectedValueException;

use function Safe\preg_match;

/** The custom resolvers of the translation keys built at runtime, which the scan cannot detect. */
final readonly class TranslationKeyResolvers {
    private const string INVALID_COVERS = 'the covers must map each glob pattern of the files to the pattern, or the list of patterns, of the dynamic keys.';

    /**
     * @param  list<class-string<TranslationKeyResolver>>  $resolvers
     */
    private function __construct(private array $resolvers) {}

    /**
     * Build the resolvers from the `resolvers` config, which lists the classes implementing the TranslationKeyResolver contract.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws InvalidConfigException
     */
    public static function fromConfig(array $config): self {
        $resolvers = [];

        foreach ($config as $resolver) {
            if (! \is_string($resolver) || ! is_subclass_of($resolver, TranslationKeyResolver::class)) {
                throw new InvalidConfigException('The "resolvers" config must contain only classes implementing the '.TranslationKeyResolver::class.' contract.');
            }

            $resolvers[] = $resolver;
        }

        return new self($resolvers);
    }

    /**
     * Replace the dynamic keys covered by the resolvers with the keys they resolve, used in the file named after the resolver class.
     *
     * @param  Collection<int, UsedTranslationKey>  $translation_keys
     * @return Collection<int, UsedTranslationKey>
     *
     * @throws RuntimeException Naming the resolver failing to resolve its keys.
     */
    public function apply(Collection $translation_keys): Collection {
        $covered = [];
        $resolved_keys = new Collection;

        foreach ($this->resolvers as $class) {
            try {
                $resolver = app($class);
                array_push($covered, ...$this->getCovers($resolver->covers()));

                foreach ($this->getKeys($resolver->resolve()) as $key) {
                    $resolved_keys->push(new UsedTranslationKey($class, $key));
                }
            } catch (Throwable $e) {
                throw new RuntimeException("Unable to resolve the translation keys with {$class}: {$e->getMessage()}", (int) $e->getCode(), previous: $e);
            }
        }

        return $translation_keys
            ->reject(fn (UsedTranslationKey $key): bool => $key->value instanceof DynamicTranslationKey && $this->isCovered($key->file, $key->value, $covered))
            ->concat($resolved_keys)
            ->values();
    }

    /**
     * Validate the covers returned by a resolver, which may break the contract.
     *
     * @param  array<array-key, mixed>  $covers
     * @return list<array{string, list<string>}> The glob pattern of the covered files, with the patterns of the covered dynamic keys.
     *
     * @throws UnexpectedValueException
     */
    private function getCovers(array $covers): array {
        $valid_covers = [];

        foreach ($covers as $glob => $patterns) {
            if (! \is_string($glob) || ! $glob) {
                throw new UnexpectedValueException(self::INVALID_COVERS);
            }

            $valid_covers[] = [$glob, $this->getCoveredPatterns($patterns)];
        }

        return $valid_covers;
    }

    /**
     * Validate the pattern, or the list of patterns, of the dynamic keys covered in a file.
     *
     * @return non-empty-list<non-falsy-string>
     *
     * @throws UnexpectedValueException
     */
    private function getCoveredPatterns(mixed $patterns): array {
        if (\is_string($patterns)) {
            $patterns = [$patterns];
        }

        if (! \is_array($patterns) || $patterns === [] || ! array_is_list($patterns)) {
            throw new UnexpectedValueException(self::INVALID_COVERS);
        }

        $valid_patterns = [];

        foreach ($patterns as $pattern) {
            if (! \is_string($pattern) || ! $pattern) {
                throw new UnexpectedValueException(self::INVALID_COVERS);
            }

            $valid_patterns[] = $pattern;
        }

        return $valid_patterns;
    }

    /**
     * Validate the keys returned by a resolver, which may break the contract.
     *
     * @param  iterable<mixed>  $resolved_keys
     * @return list<non-falsy-string>
     *
     * @throws UnexpectedValueException
     */
    private function getKeys(iterable $resolved_keys): array {
        $keys = [];

        foreach ($resolved_keys as $key) {
            if (! \is_string($key) || ! $key) {
                throw new UnexpectedValueException('the resolved keys must be non-empty strings.');
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /** @param  list<array{string, list<string>}>  $covered */
    private function isCovered(string $file, DynamicTranslationKey $key, array $covered): bool {
        foreach ($covered as [$glob, $patterns]) {
            if (\in_array($key->pattern(), $patterns, true) && preg_match(Glob::toRegex($glob), $file) === 1) {
                return true;
            }
        }

        return false;
    }
}
