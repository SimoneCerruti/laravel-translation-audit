<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Support\Collection;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\Glob;
use Symfony\Component\Finder\SplFileInfo;

use function Safe\preg_match;

final class FindFilesToScan {
    /**
     * Find the project files matching the scan paths and none of the ignored paths, sorted by name.
     * The symbolic links are followed only when asked to, skipping the ones matching the ignored links.
     *
     * @param  array<string>  $scan_paths  The glob patterns of the files to scan, relative to the project root.
     * @param  array<string>  $ignore_paths  The glob patterns of the files to skip, relative to the project root.
     * @param  array<string>  $ignore_links  The glob patterns of the symbolic links never to follow, relative to the project root.
     * @return Collection<int, SplFileInfo>
     */
    public function handle(array $scan_paths, array $ignore_paths, bool $follow_links, array $ignore_links): Collection {
        $finder = Finder::create()->files()->in(base_path());

        if ($follow_links) {
            $finder->followLinks()->filter(fn (SplFileInfo $file): bool => ! $this->isIgnoredLink($file, $ignore_links), prune: true);
        }

        foreach ($scan_paths as $scan_path) {
            $finder->path(Glob::toRegex($scan_path));
        }

        foreach ($ignore_paths as $ignore_path) {
            $finder->notPath(Glob::toRegex($ignore_path));
        }

        return collect($finder->sortByName())->values();
    }

    /** @param  array<string>  $ignore_links */
    private function isIgnoredLink(SplFileInfo $file, array $ignore_links): bool {
        if (! $file->isLink()) {
            return false;
        }

        $relative_path = str_replace('\\', '/', $file->getRelativePathname());

        return array_any($ignore_links, fn (string $ignore_link): bool => preg_match(Glob::toRegex($ignore_link), $relative_path) === 1);
    }
}
