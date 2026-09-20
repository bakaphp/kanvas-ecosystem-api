<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrowserUse;

use Baka\Support\Str;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Mcp\Services\McpCredentialService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/**
 * Browser Use's REST API, for what its MCP server does not expose: workspaces, the files a job wrote,
 * and the files the browser downloaded. Uses the agent's own MCP credential.
 */
class Client
{
    public const string BASE_URL = 'https://api.browser-use.com/api/v3';

    public function __construct(private readonly string $apiKey)
    {
    }

    public static function forAgent(Agent $agent, Integrations $integration): ?self
    {
        $key = new McpCredentialService($agent, $integration)->rawToken();

        return $key === null ? null : new self($key);
    }

    public function ensureWorkspace(string $name): ?string
    {
        $response = $this->request()->post(self::BASE_URL . '/workspaces', ['name' => $name]);

        return $response->successful() ? Str::trimmedStringOrNull($response->json('id')) : null;
    }

    /**
     * The workspace is per company and permanent, so the caller must bound this to the run it collects for.
     *
     * @return list<array{url: string, name: string, modified_at: CarbonImmutable|null}>
     */
    public function workspaceFiles(string $workspaceId): array
    {
        return $this->filesFrom(
            self::BASE_URL . '/workspaces/' . $workspaceId . '/files',
            ['includeUrls' => 'true', 'limit' => 100]
        );
    }

    /**
     * What the browser downloaded, which hangs off the BROWSER session — a different id from the agent's.
     *
     * @return list<array{url: string, name: string, modified_at: CarbonImmutable|null}>
     */
    public function browserDownloads(string $agentSessionId): array
    {
        $browserSessionId = $this->browserSessionFor($agentSessionId);

        return $browserSessionId === null
            ? []
            : $this->filesFrom(
                self::BASE_URL . '/browsers/' . $browserSessionId . '/downloads',
                ['includeUrls' => 'true', 'limit' => 100]
            );
    }

    private function browserSessionFor(string $agentSessionId): ?string
    {
        $response = $this->request()->get(self::BASE_URL . '/browsers', [
            'agentSessionId' => $agentSessionId,
            'pageSize' => 1,
        ]);

        return $response->successful()
            ? Str::trimmedStringOrNull($response->json('items.0.id'))
            : null;
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array{url: string, name: string, modified_at: CarbonImmutable|null}>
     */
    private function filesFrom(string $url, array $query): array
    {
        $response = $this->request()->get($url, $query);

        if (! $response->successful()) {
            return [];
        }

        $files = [];

        foreach ((array) $response->json('files', []) as $file) {
            $path = is_array($file) ? Str::trimmedStringOrNull($file['path'] ?? null) : null;
            $downloadUrl = is_array($file) ? Str::trimmedStringOrNull($file['url'] ?? null) : null;

            if ($path === null || $downloadUrl === null) {
                continue;
            }

            $files[] = [
                'url' => $downloadUrl,
                'name' => basename(str_replace('\\', '/', $path)),
                'modified_at' => $this->timeOrNull($file['lastModified'] ?? null),
            ];
        }

        return $files;
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['X-Browser-Use-API-Key' => $this->apiKey])
            ->acceptJson()
            ->timeout(30);
    }

    private function timeOrNull(mixed $value): ?CarbonImmutable
    {
        try {
            $time = Str::trimmedStringOrNull($value);

            return $time === null ? null : CarbonImmutable::parse($time);
        } catch (Throwable) {
            return null;
        }
    }
}
