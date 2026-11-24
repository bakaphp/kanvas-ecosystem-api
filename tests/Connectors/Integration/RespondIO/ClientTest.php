<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\RespondIO;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\RespondIO\Client;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class ClientTest extends TestCase
{
    public function testThrowsValidationExceptionWhenTokenIsStoredAsFalsyNumber(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        // Settings values round-trip through json_decode, so a token written as false/''
        // reads back as int 0 — this used to TypeError on Client::$bearerToken.
        $company->set(ConfigurationEnum::BEARER_TOKEN->value, 0);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Respond.io bearer token is not set.');

        new Client($app, $company);
    }

    /**
     * A rate limit is a refusal like any other: the caller hears about it at once.
     */
    public function testARateLimitIsRefusedWithoutWaiting(): void
    {
        $company = Companies::factory()->create();
        $company->set(ConfigurationEnum::BEARER_TOKEN->value, 'token-for-429');
        Http::fake(['api.respond.io/*' => Http::response([], 429, ['Retry-After' => '10'])]);

        $startedAt = microtime(true);

        try {
            new Client(app(Apps::class), $company)->sendMessage('+18095550100', 'hello');
            $this->fail('A 429 must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Retry after 10 seconds', $exception->getMessage());
        }

        $this->assertLessThan(5, microtime(true) - $startedAt);
    }

    public function testAcceptsNumericTokenDecodedAsInt(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        $company->set(ConfigurationEnum::BEARER_TOKEN->value, '123456789');

        Http::fake([
            'api.respond.io/v2/contact/*' => Http::response(['messageId' => 1], 200),
        ]);

        new Client($app, $company)->sendMessage('+18095550100', 'hello');

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer 123456789'));
    }
}
