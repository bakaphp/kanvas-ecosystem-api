<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Slack\Client;
use Throwable;

class SlackChannelResolverService
{
    private const int CACHE_TTL = 3600;

    public function __construct(
        private readonly Client $client,
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly string $teamId,
    ) {
    }

    /**
     * Cached because this runs once per inbound message and conversations.info is rate-limited.
     *
     * @return array{name: string, description: string}|null null when Slack could not tell us, so
     *         the caller keeps what it had rather than overwriting a good name with an id
     */
    public function resolve(string $slackChannelId): ?array
    {
        if ($slackChannelId === '') {
            return null;
        }

        // Workspace-scoped: reconnecting to a different Slack workspace can reuse a channel id.
        $cacheKey = 'slack:channel-info:' . $this->app->getId() . ':' . $this->company->getId()
            . ':' . $this->teamId . ':' . $slackChannelId;

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $channel = $this->client->conversationInfo($slackChannelId);
        } catch (Throwable) {
            // Left uncached so the next message retries instead of pinning a blip for the whole TTL.
            return null;
        }

        $name = trim((string) ($channel['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $resolved = [
            'name' => '#' . $name,
            'description' => $this->describe($channel) ?? 'Slack #' . $name,
        ];

        Cache::put($cacheKey, $resolved, self::CACHE_TTL);

        return $resolved;
    }

    private function describe(array $channel): ?string
    {
        foreach (['purpose', 'topic'] as $field) {
            $value = trim((string) ($channel[$field]['value'] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
