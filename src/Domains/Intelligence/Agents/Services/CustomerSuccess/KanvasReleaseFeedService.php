<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services\CustomerSuccess;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Github\Actions\PullGithubReleasesAction;
use Kanvas\Connectors\Github\Client;
use Kanvas\Connectors\Github\DataTransferObject\GithubRelease;
use Kanvas\Intelligence\Agents\Enums\KanvasReleaseFeedEnum;

/**
 * Kanvas's OWN release feed — what we tell customers we shipped.
 *
 * This is the only place that knows which repositories are ours. The GitHub connector underneath is
 * generic on purpose: a tenant integrating their own GitHub uses the same client, and must never be
 * able to widen or narrow what this feed reads.
 */
class KanvasReleaseFeedService
{
    /**
     * The fallback when a tenant has configured nothing. Customer-facing features ship from more than
     * this one repo, and a release the agent never sees is a feature the customer is never told about
     * — add the rest here, or per-app via the REPOSITORIES setting.
     */
    private const array DEFAULT_REPOSITORIES = ['bakaphp/kanvas-ecosystem-api'];

    public function __construct(
        private readonly Apps $app,
        private readonly ?Client $client = null,
    ) {
    }

    /**
     * @return array<int, GithubRelease>
     */
    public function publishedSince(?Carbon $since): array
    {
        return new PullGithubReleasesAction(
            $this->app,
            $this->repositories(),
            $this->client,
        )->publishedSince($since);
    }

    /**
     * @return array<int, string>
     */
    public function repositories(): array
    {
        $configured = Str::trimToNull($this->app->get(KanvasReleaseFeedEnum::REPOSITORIES->value));

        if ($configured === null) {
            return self::DEFAULT_REPOSITORIES;
        }

        $repositories = array_values(array_filter(
            array_map(
                fn (string $repository): ?string => Str::trimToNull($repository),
                explode(',', $configured)
            )
        ));

        return $repositories === []
            ? self::DEFAULT_REPOSITORIES
            : array_map(Client::normalizeRepository(...), $repositories);
    }
}
