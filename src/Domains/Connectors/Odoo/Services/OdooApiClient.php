<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Services;

use Baka\Http\SafeUrl;
use Illuminate\Support\Facades\Http;
use Kanvas\Exceptions\ValidationException;

/**
 * Thin JSON-RPC wrapper over Odoo's External API (`POST {baseUrl}/jsonrpc`) — deliberately not a
 * third-party package. Odoo has already announced XML-RPC/JSON-RPC's `common`/`object` services
 * for removal in Odoo 22 (fall 2028) in favor of a new JSON-2 (HTTP + Bearer) API introduced in
 * Odoo 19, and every available PHP package targets the old protocol. A thin wrapper here means
 * swapping to JSON-2 later only touches this file, not every `Pull*Action` — same reasoning as
 * `SalesforceApiClient` not depending on an SDK.
 *
 * Unlike Salesforce's reusable Bearer token, every `execute_kw` call needs `db`/`uid`/`apiKey`
 * explicitly — there's no session/token to attach once and forget.
 */
final class OdooApiClient
{
    private const int PAGE_SIZE = 2000;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $database,
        private readonly int $uid,
        private readonly string $apiKey,
    ) {
    }

    /**
     * Resolves the `uid` a set of credentials authenticates as — `null` means the credentials
     * were rejected. Called once by `Client::getInstance()` and cached there; never call this
     * per-request.
     */
    public static function authenticate(string $baseUrl, string $database, string $username, string $apiKey): ?int
    {
        $result = self::call($baseUrl, 'common', 'authenticate', [$database, $username, $apiKey, []]);

        return is_int($result) ? $result : null;
    }

    /**
     * `$limit = 0` means unlimited here — matching Odoo's own convention where an *omitted*
     * `limit` kwarg means unlimited. Sending a literal `0` is a different thing to Odoo: it
     * means "return zero records", so it must never reach `execute_kw` as a real 0.
     *
     * @param array<int, mixed> $domain Odoo domain filter, e.g. [['is_company', '=', true]]
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    public function searchRead(string $model, array $domain, array $fields, int $limit = 0, int $offset = 0): array
    {
        $kwargs = ['fields' => $fields, 'offset' => $offset];
        if ($limit > 0) {
            $kwargs['limit'] = $limit;
        }

        $result = $this->executeKw($model, 'search_read', [$domain], $kwargs);

        return is_array($result) ? $result : [];
    }

    /**
     * Pages through `searchRead()` until a short page signals the end — every `PullAll*Action`
     * needs this same loop, so it lives here once instead of once per action.
     *
     * @param array<int, mixed> $domain
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    public function searchReadAll(string $model, array $domain, array $fields): array
    {
        $records = [];
        $offset = 0;

        do {
            $page = $this->searchRead($model, $domain, $fields, self::PAGE_SIZE, $offset);
            $records = [...$records, ...$page];
            $offset += self::PAGE_SIZE;
        } while (count($page) === self::PAGE_SIZE);

        return $records;
    }

    /**
     * Whether a record with this id is still there — Odoo has no per-id GET, so this is a
     * `search_read` narrowed to the id with the smallest possible payload.
     */
    public function exists(string $model, int $id): bool
    {
        return count($this->searchRead($model, [['id', '=', $id]], ['id'], 1)) > 0;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function create(string $model, array $values): int
    {
        return (int) $this->executeKw($model, 'create', [$values]);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function write(string $model, int $id, array $values): bool
    {
        return (bool) $this->executeKw($model, 'write', [[$id], $values]);
    }

    private function executeKw(string $model, string $method, array $args, array $kwargs = []): mixed
    {
        return self::call(
            $this->baseUrl,
            'object',
            'execute_kw',
            [$this->database, $this->uid, $this->apiKey, $model, $method, $args, $kwargs],
        );
    }

    private static function call(string $baseUrl, string $service, string $method, array $args): mixed
    {
        SafeUrl::assertSafe($baseUrl);

        $response = Http::timeout(30)->post(rtrim($baseUrl, '/') . '/jsonrpc', [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => $service,
                'method' => $method,
                'args' => $args,
            ],
            'id' => random_int(1, PHP_INT_MAX),
        ]);

        if ($response->failed()) {
            throw new ValidationException('Odoo API error (HTTP ' . $response->status() . '): ' . $response->body());
        }

        // JSON-RPC always answers 200 even on failure — the real error lives in the body's
        // `error` key, never in the HTTP status.
        $error = $response->json('error');
        if ($error !== null) {
            $message = is_array($error) ? ($error['data']['message'] ?? $error['message'] ?? json_encode($error)) : (string) $error;

            throw new ValidationException('Odoo API error: ' . $message);
        }

        return $response->json('result');
    }
}
