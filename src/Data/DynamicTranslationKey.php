<?php

declare(strict_types=1);

namespace TranslationAudit\Data;

use function Safe\preg_match;

/** A translation key built at runtime, e.g. `__("payments.{$method}")`, known only by the static texts around its dynamic parts. */
readonly class DynamicTranslationKey {
    private string $regex;

    /**
     * @param  non-empty-list<string>  $segments  The static texts of the key, with a dynamic part between each of them, e.g. ['payments.', ''] for `"payments.{$method}"`.
     */
    public function __construct(public array $segments) {
        $this->regex = '/\A'.implode('.*', array_map(fn (string $segment): string => preg_quote($segment, '/'), $segments)).'\z/s';
    }

    /**
     * The key with an asterisk in place of each dynamic part, e.g. `payments.*` for `"payments.{$method}"`.
     *
     * @return non-falsy-string
     */
    public function pattern(): string {
        return implode('*', $this->segments);
    }

    /** Whether the key can be the value of this dynamic key, whatever the values of its dynamic parts. */
    public function matches(string $key): bool {
        return preg_match($this->regex, $key) === 1;
    }
}
