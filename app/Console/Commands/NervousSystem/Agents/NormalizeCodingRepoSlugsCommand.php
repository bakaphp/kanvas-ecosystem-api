<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents;

use Illuminate\Console\Command;
use Kanvas\Connectors\Github\Client as GitHubClient;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\CodingRepositoryMemory;

/**
 * Rewrites stored repository identifiers to canonical `owner/name`.
 *
 * A coding task accepts a URL, an SSH clone line or `owner/name` — all correct, and all stored
 * verbatim until now. Since that string is the key a repository's memories are filed under, one
 * repository ended up with its lessons split across two buckets that could not see each other, and
 * which half the agent remembered depended on how the task happened to be phrased.
 *
 * Merging buckets can collide on the `(apps_id, companies_id, repo_slug, content_hash)` unique key —
 * the same lesson reported under both spellings. Those are folded together and their counts summed,
 * because the alternative is losing whichever row loses the race.
 *
 * A bare project name with no owner is not a GitHub reference. It is left exactly as it is.
 */
class NormalizeCodingRepoSlugsCommand extends Command
{
    protected $signature = 'kanvas:coding:normalize-repo-slugs
        {--dry-run : Report what would change without writing}';

    protected $description = 'Canonicalise agent_task_sessions.repo_slug and coding memory keys to owner/name.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $sessions = $this->normalizeSessions($dryRun);
        [$moved, $merged] = $this->normalizeMemories($dryRun);

        $this->info(sprintf(
            '%s %d session(s), moved %d memory row(s), merged %d duplicate(s).',
            $dryRun ? 'Would rewrite' : 'Rewrote',
            $sessions,
            $moved,
            $merged,
        ));

        return self::SUCCESS;
    }

    private function normalizeSessions(bool $dryRun): int
    {
        $changed = 0;

        foreach ($this->rewrites(AgentTaskSession::query()->distinct()->pluck('repo_slug')) as $from => $to) {
            $count = AgentTaskSession::query()->where('repo_slug', $from)->count();
            $this->line(sprintf('session  %-45s -> %-30s (%d)', $from, $to, $count));
            $changed += $count;

            if (! $dryRun) {
                AgentTaskSession::query()->where('repo_slug', $from)->update(['repo_slug' => $to]);
            }
        }

        return $changed;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function normalizeMemories(bool $dryRun): array
    {
        $moved = 0;
        $merged = 0;

        foreach ($this->rewrites(CodingRepositoryMemory::query()->distinct()->pluck('repo_slug')) as $from => $to) {
            $rows = CodingRepositoryMemory::query()->where('repo_slug', $from)->get();
            $this->line(sprintf('memory   %-45s -> %-30s (%d)', $from, $to, $rows->count()));

            foreach ($rows as $row) {
                $existing = CodingRepositoryMemory::query()
                    ->where('apps_id', $row->apps_id)
                    ->where('companies_id', $row->companies_id)
                    ->where('repo_slug', $to)
                    ->where('content_hash', $row->content_hash)
                    ->first();

                if ($existing === null) {
                    $moved++;

                    if (! $dryRun) {
                        $row->repo_slug = $to;
                        $row->saveQuietly();
                    }

                    continue;
                }

                $merged++;

                if (! $dryRun) {
                    $existing->times_reported += $row->times_reported;
                    $existing->saveQuietly();
                    $row->forceDelete();
                }
            }
        }

        return [$moved, $merged];
    }

    /**
     * Stored value => canonical value, for the ones that actually differ.
     *
     * @param iterable<int, mixed> $stored
     * @return array<string, string>
     */
    private function rewrites(iterable $stored): array
    {
        $rewrites = [];

        foreach ($stored as $value) {
            $slug = trim((string) $value);

            if ($slug === '') {
                continue;
            }

            $canonical = GitHubClient::tryNormalizeRepository($slug);

            if ($canonical !== null && $canonical !== $slug) {
                $rewrites[$slug] = $canonical;
            }
        }

        return $rewrites;
    }
}
