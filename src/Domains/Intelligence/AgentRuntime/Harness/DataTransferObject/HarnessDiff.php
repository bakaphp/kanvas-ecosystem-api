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
     * @param list<string> $protectedPaths glob-ish prefixes from the repository's rules of engagement
     * @return list<string>
     */
    public function touchedProtectedPaths(array $protectedPaths): array
    {
        $hits = [];

        foreach ($this->paths() as $path) {
            foreach ($protectedPaths as $protected) {
                if (fnmatch(rtrim($protected, '/') . '*', $path)) {
                    $hits[] = $path;

                    break;
                }
            }
        }

        return $hits;
    }
}
