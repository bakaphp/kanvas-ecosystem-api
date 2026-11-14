<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mailgun\Client;
use Tests\TestCase;

final class ClientTest extends TestCase
{
    private const string DOMAIN = 'agents.kanvas.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mailgun.secret' => 'key-test']);
    }

    public function testCreatesTheWebhookWhenNoneExistsYet(): void
    {
        Http::fake([
            'api.mailgun.net/v3/domains/*/webhooks/opened' => Http::response([], 404),
            'api.mailgun.net/v3/domains/*/webhooks' => Http::response(['webhook' => ['id' => 'opened']]),
        ]);

        new Client(app(Apps::class))->createOrUpdateWebhook(self::DOMAIN, 'opened', 'https://kanvas.test/v1/receiver/abc');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/v3/domains/' . self::DOMAIN . '/webhooks')
            && str_contains((string) $request->body(), 'name="id"')
            && str_contains((string) $request->body(), 'https://kanvas.test/v1/receiver/abc'));
    }

    public function testUpdatesTheWebhookWhenItAlreadyExists(): void
    {
        Http::fake([
            'api.mailgun.net/v3/domains/*/webhooks/opened' => Http::response(['webhook' => ['id' => 'opened']], 200),
        ]);

        new Client(app(Apps::class))->createOrUpdateWebhook(self::DOMAIN, 'opened', 'https://kanvas.test/v1/receiver/new');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/v3/domains/' . self::DOMAIN . '/webhooks/opened')
            && str_contains((string) $request->body(), 'https://kanvas.test/v1/receiver/new'));
    }
}
