<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject;

use Spatie\LaravelData\Data;

class HarnessDiff extends Data
{
    /**
     * @param list<array{file: string, additions: int, deletions: int, status: string}> $files
     */
    public function __construct(
        public readonly array $files = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->files === [];
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(static fn (array $file): string => $file['file'], $this->files);
    }

    public function summary(): string
    {
        if ($this->isEmpty()) {
            return 'no changes';
        }

        $additions = array_sum(array_column($this->files, 'additions'));
        $deletions = array_sum(array_column($this->files, 'deletions'));

        return count($this->files) . ' file(s), +' . $additions . '/-' . $deletions;
    }

    /**
     * @param list<string> $protectedPaths
     * @return list<string>
     */
    public function touchedProtectedPaths(array $protectedPaths): array
    {
        return array_values(array_filter(
            $this->paths(),
            static fn (string $path): bool => self::pathMatches($path, $protectedPaths)
        ));
    }

    /**
     * Each entry is a file or directory from the repository root, or a glob — matched on whole path
     * segments, never as a bare prefix, so `.env` does not catch `.env.example` and `.github/` does not
     * catch `.githubfoo`.
     *
     * @param list<string> $entries
     */
    public static function pathMatches(string $path, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (strpbrk($entry, '*?[') !== false) {
                if (fnmatch($entry, $path)) {
                    return true;
                }

                continue;
            }

            $entry = rtrim($entry, '/');

            if ($entry !== '' && ($path === $entry || str_starts_with($path, $entry . '/'))) {
                return true;
            }
        }

        return false;
    }
}
