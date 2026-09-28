<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Github\DataTransferObject;

use Baka\Support\Str;
use Illuminate\Support\Carbon;

/**
 * One published GitHub release. `body` is the release notes verbatim — it is already customer-facing
 * copy, and it is the only thing the agent is permitted to describe as shipped.
 */
class GithubRelease
{
    public function __construct(
        public readonly string $repository,
        public readonly string $tag,
        public readonly ?string $name,
        public readonly string $body,
        public readonly ?Carbon $publishedAt,
        public readonly bool $isDraft,
        public readonly bool $isPrerelease,
        public readonly ?string $url,
    ) {
    }

    /**
     * @param array<string, mixed> $payload a single element of the GitHub releases response
     */
    public static function fromApiPayload(string $repository, array $payload): self
    {
        $publishedAt = Str::trimToNull($payload['published_at'] ?? null);

        return new self(
            repository: $repository,
            tag: (string) ($payload['tag_name'] ?? ''),
            name: Str::trimToNull($payload['name'] ?? null),
            body: (string) ($payload['body'] ?? ''),
            publishedAt: $publishedAt !== null ? Carbon::parse($publishedAt) : null,
            isDraft: (bool) ($payload['draft'] ?? false),
            isPrerelease: (bool) ($payload['prerelease'] ?? false),
            url: Str::trimToNull($payload['html_url'] ?? null),
        );
    }

    /**
     * A release with no notes says nothing to a customer, so it is not worth handing to the agent.
     */
    public function isPublishedAndUsable(): bool
    {
        return ! $this->isDraft
            && ! $this->isPrerelease
            && $this->publishedAt !== null
            && Str::trimToNull($this->body) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAgentPayload(): array
    {
        return [
            'repository' => $this->repository,
            'tag' => $this->tag,
            'name' => $this->name,
            'published_at' => $this->publishedAt?->toIso8601String(),
            'url' => $this->url,
            'notes' => $this->body,
        ];
    }
}
