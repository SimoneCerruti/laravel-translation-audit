<?php

declare(strict_types=1);

namespace TranslationAudit\Contracts;

/**
 * Resolve the translation keys the app builds at runtime by custom logic, which the scan cannot detect.
 * The resolvers are listed in the `resolvers` config, and resolved from the container.
 */
interface TranslationKeyResolver {
    /**
     * The translation keys used at runtime, e.g. the keys built from the cases of an enum.
     * They are the keys, not their translations.
     *
     * @return iterable<non-falsy-string>
     */
    public function resolve(): iterable;

    /**
     * The dynamic keys replaced by the resolved keys, so they are not audited by their pattern:
     * the glob pattern of the files using them, relative to the project root, mapped to the pattern of the dynamic keys, or to the list of patterns,
     * with an asterisk in place of each dynamic part, e.g. ['app/Enums/Concerns/HasLabel.php' => 'status.*'].
     *
     * @return array<non-falsy-string, non-falsy-string|list<non-falsy-string>>
     */
    public function covers(): array;
}
