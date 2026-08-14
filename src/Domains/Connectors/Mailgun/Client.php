<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun;

use Baka\Contracts\AppInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    private const string BASE_URL = 'https://api.mailgun.net';
    private const int TIMEOUT_SECONDS = 15;

    protected string $apiKey;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->apiKey = (string) ($this->app->get(ConfigurationEnum::API_KEY->value));

        if (empty($this->apiKey)) {
            throw new ValidationException('Mailgun API key is not configured for app: ' . $this->app->name);
        }
    }

    public function validateAddress(string $email): array
    {
        return $this->assertOk(
            $this->request()->get('/v4/address/validate', ['address' => $email]),
            'validate ' . $email
        )->json() ?? [];
    }

    /**
     * The domain as Mailgun knows it — the check that a customer-supplied domain is actually on
     * their account before we hand agents addresses on it.
     *
     * @return array<string, mixed>
     */
    public function getDomain(string $domain): array
    {
        $response = $this->request()->get('/v3/domains/' . rawurlencode($domain));

        if ($response->notFound()) {
            throw new ValidationException(
                'Domain ' . $domain . ' is not on this Mailgun account. Add and verify it in Mailgun first.'
            );
        }

        return $this->assertOk($response, 'read domain ' . $domain)->json('domain') ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRoutes(int $limit = 1000): array
    {
        $items = $this->assertOk(
            $this->request()->get('/v3/routes', ['limit' => $limit]),
            'list routes'
        )->json('items');

        return is_array($items) ? $items : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRouteByRecipient(string $address): ?array
    {
        $expression = self::matchRecipientExpression($address);

        foreach ($this->listRoutes() as $route) {
            if (($route['expression'] ?? null) === $expression) {
                return $route;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed> the created route
     */
    public function createRoute(string $address, string $forwardUrl, string $description): array
    {
        return $this->assertOk(
            $this->request()->asMultipart()->post('/v3/routes', $this->routeParts($address, $forwardUrl, $description)),
            'create route for ' . $address
        )->json('route') ?? [];
    }

    /**
     * @return array<string, mixed> the updated route
     */
    public function updateRoute(string $routeId, string $address, string $forwardUrl, string $description): array
    {
        $response = $this->assertOk(
            $this->request()->asMultipart()->put(
                '/v3/routes/' . rawurlencode($routeId),
                $this->routeParts($address, $forwardUrl, $description)
            ),
            'update route ' . $routeId
        );

        // PUT answers with the route's fields at the top level, POST nests them under `route`.
        return $response->json('route') ?? $response->json() ?? [];
    }

    public function deleteRoute(string $routeId): bool
    {
        $response = $this->request()->delete('/v3/routes/' . rawurlencode($routeId));

        // A route already gone is the state we wanted.
        return $response->successful() || $response->notFound();
    }

    /**
     * Send through Mailgun's API rather than SMTP: the From has to be the agent's own address, and
     * only the account that owns the domain can authorize that — an SMTP relay configured for some
     * other company domain would have the mail rejected or spam-foldered on arrival.
     *
     * @param array<string, string> $headers extra RFC-822 headers (In-Reply-To, References, …)
     *
     * @return string the queued Message-Id, needed to thread anything that replies to this
     */
    public function sendMessage(
        string $domain,
        string $from,
        string $to,
        string $subject,
        string $text,
        ?string $html = null,
        array $headers = [],
    ): string {
        $parts = [
            ['name' => 'from', 'contents' => $from],
            ['name' => 'to', 'contents' => $to],
            ['name' => 'subject', 'contents' => $subject],
            ['name' => 'text', 'contents' => $text],
        ];

        if ($html !== null && $html !== '') {
            $parts[] = ['name' => 'html', 'contents' => $html];
        }

        foreach ($headers as $name => $value) {
            if ($value === '') {
                continue;
            }

            $parts[] = ['name' => 'h:' . $name, 'contents' => $value];
        }

        return (string) $this->assertOk(
            $this->request()->asMultipart()->post('/v3/' . rawurlencode($domain) . '/messages', $parts),
            'send mail from ' . $from
        )->json('id');
    }

    public static function matchRecipientExpression(string $address): string
    {
        return 'match_recipient("' . strtolower(trim($address)) . '")';
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function routeParts(string $address, string $forwardUrl, string $description): array
    {
        return [
            // Below Mailgun's default 0 so a catch-all a customer already runs can't swallow the
            // agent's mail before it reaches us.
            ['name' => 'priority', 'contents' => '0'],
            ['name' => 'description', 'contents' => $description],
            ['name' => 'expression', 'contents' => self::matchRecipientExpression($address)],
            ['name' => 'action', 'contents' => 'forward("' . $forwardUrl . '")'],
            ['name' => 'action', 'contents' => 'stop()'],
        ];
    }

    private function request(): PendingRequest
    {
        return Http::withBasicAuth('api', $this->apiKey)
            ->baseUrl(self::BASE_URL)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS);
    }

    private function assertOk(Response $response, string $operation): Response
    {
        if ($response->failed()) {
            throw new ValidationException(
                'Mailgun could not ' . $operation . ' (HTTP ' . $response->status() . '): '
                . ($response->json('message') ?? $response->body())
            );
        }

        return $response;
    }
}
