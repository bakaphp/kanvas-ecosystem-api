<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Github\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Github\Client;
use Kanvas\Connectors\Github\DataTransferObject\GithubRelease;

class PullGithubReleasesAction
{
    /**
     * An account that has never been written to gets the last 30 days, never the whole history — the
     * alternative is a first email containing every release Kanvas has ever cut.
     */
    public const int FIRST_SEND_WINDOW_DAYS = 30;
    private const int MAX_PAGES = 5;

    /**
     * @param array<int, string> $repositories `owner/repo` names. The caller decides whose repos these
     *                                          are — this action has no opinion about it.
     */
    public function __construct(
        private readonly Apps $app,
        private readonly array $repositories,
        private readonly ?Client $client = null,
    ) {
    }

    /**
     * Releases published after $since across every configured repository, **oldest first** so a digest
     * reads chronologically.
     *
     * Filtered and ordered on `published_at`, never the tag: "v1.99.10" sorts before "v1.99.9"
     * lexically, and that bug stays invisible until the tenth patch release of a minor.
     *
     * Two repositories are one merged timeline, not two feeds — a frontend and a backend release in the
     * same week belong in the same update, interleaved by date.
     *
     * @return array<int, GithubRelease>
     */
    public function publishedSince(?Carbon $since): array
    {
        $client = $this->client ?? Client::getInstanceByApp($this->app);
        $cutoff = $since ?? now()->subDays(self::FIRST_SEND_WINDOW_DAYS);
        $releases = [];

        foreach ($this->repositories as $repository) {
            foreach ($this->forRepository($client, $repository, $cutoff) as $release) {
                $releases[] = $release;
            }
        }

        usort(
            $releases,
            fn (GithubRelease $a, GithubRelease $b): int => $a->publishedAt <=> $b->publishedAt
        );

        return $releases;
    }

    /**
     * @return array<int, GithubRelease>
     */
    private function forRepository(Client $client, string $repository, Carbon $cutoff): array
    {
        $releases = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $batch = $client->releases($repository, $page);

            if ($batch === []) {
                return $releases;
            }

            foreach ($batch as $payload) {
                $release = GithubRelease::fromApiPayload($repository, (array) $payload);

                if (! $release->isPublishedAndUsable()) {
                    continue;
                }

                // GitHub returns newest first, so the first release at or before the cutoff means
                // every remaining page for this repository is older still.
                if ($release->publishedAt->lessThanOrEqualTo($cutoff)) {
                    return $releases;
                }

                $releases[] = $release;
            }
        }

        return $releases;
    }
}
