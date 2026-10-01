<?php

declare(strict_types=1);

namespace TranslationAudit\Data;

use Illuminate\Support\Collection;

readonly class AuditResult {
    /**
     * @param  Collection<int, Translation>  $missing
     * @param  Collection<int, Translation>|null  $unused  The unused translations, null when they are not audited.
     */
    public function __construct(
        public Collection $missing,
        public ?Collection $unused = null,
    ) {}

    /**
     * @param  Collection<int, Translation>  $unused
     */
    public function withUnused(Collection $unused): self {
        return new self($this->missing, $unused);
    }

    /**
     * The missing locales of each translation key, grouped by the path of the file using the key.
     *
     * @return Collection<array-key, Collection<array-key, Collection<int, string>>>
     */
    public function missingByFile(): Collection {
        return $this->missing
            ->groupBy('file')
            ->map(fn (Collection $translations): Collection => $translations
                ->groupBy('key')
                ->map(fn (Collection $locales): Collection => $locales->map(fn (Translation $translation): string => $translation->locale)));
    }

    /**
     * The unused translation of each key, grouped by locale and by the path of the translation file defining it.
     *
     * @return Collection<array-key, Collection<array-key, Collection<string, string|null>>>
     */
    public function unusedByLocale(): Collection {
        return ($this->unused ?? new Collection)
            ->groupBy('locale')
            ->map(fn (Collection $translations): Collection => $translations
                ->groupBy('file')
                ->map(fn (Collection $keys): Collection => $keys->mapWithKeys(fn (Translation $translation): array => [$translation->key => $translation->value])));
    }
}
