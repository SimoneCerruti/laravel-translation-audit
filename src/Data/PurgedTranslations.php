<?php

declare(strict_types=1);

namespace TranslationAudit\Data;

use Illuminate\Support\Collection;

readonly class PurgedTranslations {
    /**
     * @param  Collection<string, string>  $purged  The removed translations, or the ones that would be removed on a dry run, keyed like the given keys.
     * @param  Collection<string, string>  $not_purged  The reason why each translation cannot be removed, keyed like the given keys.
     */
    public function __construct(
        public Collection $purged,
        public Collection $not_purged = new Collection,
    ) {}
}
