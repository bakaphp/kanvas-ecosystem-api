<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Kanvas\Connectors\Mercury\Client;
use Kanvas\Connectors\Mercury\Services\MercuryTransactionService;
use Tests\Connectors\Traits\HasMercuryConfiguration;
use Tests\Scribe\ScribeTestCase;

/**
 * Webhook hydration — the `GET` behind every `transaction.created` / `transaction.updated` nudge.
 *
 * It was pointed at the PLURAL `transactions/{id}`, which is the collection route: Mercury 404s it for every
 * id, so 100% of transaction webhooks died in the Guzzle exception and only the nightly poll kept the feed
 * alive. Two things are pinned here — the singular path, and that a genuine 404 is an answer rather than a
 * crash, because the nudge can outrun the record and a cancelled authorization vanishes for good.
 */
final class MercuryTransactionHydrationTest extends ScribeTestCase
{
    use HasMercuryConfiguration;

    private const string TRANSACTION_ID = '9309a960-9eef-11f1-ad73-33329462cf9d';

    protected function afterScribeSetUp(): void
    {
        $this->configureMercury($this->company);
    }

    public function testItHydratesFromTheSingularTransactionEndpoint(): void
    {
        $history = [];

        $transaction = $this->serviceReturning(
            [new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(
                $this->mercuryTransactionPayload(self::TRANSACTION_ID, 'acct-1', -125.50)
            ))],
            $history,
        )->find(self::TRANSACTION_ID);

        $this->assertNotNull($transaction);
        $this->assertSame(self::TRANSACTION_ID, $transaction->id);

        $uri = (string) $history[0]['request']->getUri();
        $this->assertStringEndsWith('transaction/' . self::TRANSACTION_ID, $uri);
        $this->assertStringNotContainsString('transactions/', $uri, 'The plural is the collection route and 404s on an id.');
    }

    public function testAMissingTransactionIsNullRatherThanAnException(): void
    {
        $history = [];

        $service = $this->serviceReturning(
            [new Response(404, ['Content-Type' => 'application/json'], '{"errors":{"notFound":["We could not find the data"]}}')],
            $history,
        );

        $this->assertNull($service->find(self::TRANSACTION_ID));
    }

    public function testARealApiFaultStillThrows(): void
    {
        $history = [];

        $service = $this->serviceReturning(
            [new Response(401, ['Content-Type' => 'application/json'], '{"errors":{"message":"Unauthorized"}}')],
            $history,
        );

        // A revoked token is not "no such transaction" — swallowing it would silently stop the whole feed.
        $this->expectException(ClientException::class);

        $service->find(self::TRANSACTION_ID);
    }

    /**
     * @param list<Response> $responses
     * @param list<array<string, mixed>> $history
     */
    private function serviceReturning(array $responses, array &$history): MercuryTransactionService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new MercuryTransactionService(
            $this->kanvasApp,
            $this->company,
            new Client(
                $this->kanvasApp,
                $this->company,
                new GuzzleClient([
                    'base_uri' => 'https://api.mercury.com/api/v1/',
                    'handler' => $stack,
                ]),
            ),
        );
    }
}
