<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\DataTransferObject;

use Baka\Support\Str;
use Spatie\LaravelData\Data;

/**
 * One repository an agent is allowed to work on.
 *
 * Read today from the agent's own allow-list custom field; a table replaces that when repository
 * memory needs a stable id to hang off. Either way the LLM only ever names a **slug** and this object
 * supplies the URL — a free-typed clone URL would let a prompt-injected agent aim a checkout, and later
 * a push, at a repository nobody approved.
 */
class CodingRepository extends Data
{
    /**
     * @param list<string> $protectedPaths
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $cloneUrl,
        public readonly string $baseBranch = 'main',
        public readonly string $branchPrefix = 'agent/',
        public readonly ?string $rules = null,
        public readonly array $protectedPaths = [],
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function fromAllowListEntry(array $entry): ?self
    {
        $slug = Str::trimToNull((string) ($entry['slug'] ?? ''));
        $url = Str::trimToNull((string) ($entry['url'] ?? $entry['clone_url'] ?? ''));

        if ($slug === null || $url === null) {
            return null;
        }

        $protected = $entry['protected_paths'] ?? [];

        return new self(
            slug: $slug,
            cloneUrl: $url,
            baseBranch: Str::trimToNull((string) ($entry['base_branch'] ?? '')) ?? 'main',
            branchPrefix: Str::trimToNull((string) ($entry['branch_prefix'] ?? '')) ?? 'agent/',
            rules: Str::trimToNull((string) ($entry['rules'] ?? '')),
            protectedPaths: is_array($protected) ? array_values(array_filter($protected, 'is_string')) : [],
        );
    }
}
