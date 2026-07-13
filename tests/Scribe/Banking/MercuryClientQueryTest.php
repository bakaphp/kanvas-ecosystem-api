<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Kanvas\Connectors\Mercury\Client;
use Psr\Http\Message\RequestInterface;
use Tests\Connectors\Traits\HasMercuryConfiguration;
use Tests\Scribe\ScribeTestCase;

/**
 * Guards the exact bug that cost us a day: Guzzle serializes an array query param as `accountId[0]=x`, and
 * Mercury SILENTLY IGNORES that form — 200 OK, unfiltered results. Asking for one account's transactions
 * returned every account's, and the caller had no way to tell. Savings and credit-card movements landed on
 * the checking account's bank row, posting their cash to the wrong GL account.
 *
 * It was also load-dependent, which is what made it so nasty: at a small `limit` the first page came back
 * clean, so it looked correct right up until it wasn't.
 */
final class MercuryClientQueryTest extends ScribeTestCase
{
    use HasMercuryConfiguration;

    protected function afterScribeSetUp(): void
    {
        $this->configureMercury($this->company);
    }

    public function testArrayParamsAreSerializedAsRepeatedKeysNotBracketIndexes(): void
    {
        $uri = $this->captureRequestUri([
            'accountId' => ['acct-a', 'acct-b'],
            'status' => ['sent'],
            'limit' => 1000,
        ]);

        $this->assertStringContainsString('accountId=acct-a', $uri);
        $this->assertStringContainsString('accountId=acct-b', $uri);
        $this->assertStringContainsString('status=sent', $uri);
        $this->assertStringContainsString('limit=1000', $uri);

        // The whole point. Any bracket here means Mercury drops the filter on the floor.
        $this->assertStringNotContainsString('%5B', $uri, 'Bracket-indexed arrays are silently ignored by Mercury.');
        $this->assertStringNotContainsString('[', $uri);
    }

    public function testScalarParamsStillSerializeNormally(): void
    {
        $uri = $this->captureRequestUri(['limit' => 50, 'order' => 'asc']);

        $this->assertStringContainsString('limit=50', $uri);
        $this->assertStringContainsString('order=asc', $uri);
    }

    public function testEmptyValuesAreDroppedRatherThanSentAsBlanks(): void
    {
        $uri = $this->captureRequestUri(['limit' => 10, 'postedStart' => null, 'search' => '']);

        $this->assertStringContainsString('limit=10', $uri);
        $this->assertStringNotContainsString('postedStart', $uri);
        $this->assertStringNotContainsString('search', $uri);
    }

    public function testTimestampsAreEncodedSafely(): void
    {
        // Mercury rejects a '+00:00' offset outright (malformedDateParam), so we send the Zulu form — and it
        // must survive URL encoding intact.
        $uri = $this->captureRequestUri(['postedStart' => '2026-01-14T00:56:19Z']);

        $this->assertStringContainsString('postedStart=2026-01-14T00%3A56%3A19Z', $uri);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function captureRequestUri(array $query): string
    {
        $captured = null;

        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"transactions":[]}'),
        ]));
        $stack->push(Middleware::tap(function (RequestInterface $request) use (&$captured): void {
            $captured = (string) $request->getUri();
        }));

        new Client(
            $this->kanvasApp,
            $this->company,
            new GuzzleClient(['handler' => $stack]),
        )->get('transactions', $query);

        return (string) $captured;
    }
}
