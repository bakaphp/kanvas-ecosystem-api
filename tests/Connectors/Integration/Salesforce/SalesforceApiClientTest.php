<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Salesforce\Services\SalesforceApiClient;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class SalesforceApiClientTest extends TestCase
{
    use DatabaseTransactions;

    // Distinct from `HasSalesforceConfiguration::SALESFORCE_INSTANCE_URL` on purpose — this test
    // instantiates `SalesforceApiClient` directly (no OAuth exchange needed) and the proactive
    // throttle key is derived from the instance URL, so sharing the trait's URL would let this
    // file's request count bleed into other Salesforce tests' rate-limit budget.
    private const string INSTANCE_URL = 'https://fake-apiclient.salesforce.test';
    private const string ACCESS_TOKEN = 'test-access-token';
    private const string API_VERSION = 'v60.0';

    public function testQueryMoreFollowsPaginationAcrossTwoPages(): void
    {
        Http::fake([
            // `?*` (not a bare `*`) so this doesn't also swallow the page-2 URL below — a bare
            // `.../query*` matches `.../query/01gXX-2000` just as well as `.../query?q=...`, which
            // fed queryMore() its own first page forever instead of the page-2 fake.
            self::INSTANCE_URL . '/services/data/' . self::API_VERSION . '/query?*' => Http::response([
                'totalSize' => 2,
                'done' => false,
                'nextRecordsUrl' => '/services/data/' . self::API_VERSION . '/query/01gXX-2000',
                'records' => [['Id' => '001xx0000000001AAA', 'Name' => 'Acme']],
            ], 200),
            self::INSTANCE_URL . '/services/data/' . self::API_VERSION . '/query/01gXX-2000' => Http::response([
                'totalSize' => 2,
                'done' => true,
                'records' => [['Id' => '001xx0000000002AAA', 'Name' => 'Globex']],
            ], 200),
        ]);

        $client = $this->makeClient();

        $firstPage = $client->query('SELECT Id, Name FROM Account');
        $this->assertFalse($firstPage['done']);
        $this->assertCount(1, $firstPage['records']);

        $secondPage = $client->queryMore($firstPage['nextRecordsUrl']);
        $this->assertTrue($secondPage['done']);
        $this->assertCount(1, $secondPage['records']);
        $this->assertSame('001xx0000000002AAA', $secondPage['records'][0]['Id']);

        Http::assertSent(function ($request) {
            return $request->url() === self::INSTANCE_URL . '/services/data/' . self::API_VERSION . '/query/01gXX-2000'
                && $request->method() === 'GET';
        });
    }

    public function testRequestLimitExceededInBodyIsRetriedInsteadOfThrown(): void
    {
        Http::fake([
            self::INSTANCE_URL . '/services/data/' . self::API_VERSION . '/query*' => Http::sequence()
                ->push([['message' => 'Request limit exceeded', 'errorCode' => 'REQUEST_LIMIT_EXCEEDED']], 403)
                ->push(['totalSize' => 0, 'done' => true, 'records' => []], 200),
        ]);

        $waited = [];
        $client = $this->makeClient(function (int $seconds) use (&$waited) {
            $waited[] = $seconds;
        });

        $result = $client->query('SELECT Id FROM Account');

        $this->assertTrue($result['done']);
        $this->assertSame([], $result['records']);
        $this->assertNotEmpty($waited, 'A rate-limit body error should trigger a wait before retrying.');

        Http::assertSentCount(2);
    }

    public function testRequestLimitExceededThrowsAfterExhaustingRetries(): void
    {
        Http::fake([
            self::INSTANCE_URL . '/services/data/' . self::API_VERSION . '/query*' => Http::response(
                [['message' => 'Request limit exceeded', 'errorCode' => 'REQUEST_LIMIT_EXCEEDED']],
                403,
            ),
        ]);

        $client = $this->makeClient(fn (int $seconds) => null);

        $this->expectException(ValidationException::class);

        $client->query('SELECT Id FROM Account');
    }

    public function testNonRateLimitErrorBodyIsThrownImmediately(): void
    {
        Http::fake([
            self::INSTANCE_URL . '/services/data/' . self::API_VERSION . '/query*' => Http::response(
                [['message' => 'Malformed SOQL', 'errorCode' => 'MALFORMED_QUERY']],
                400,
            ),
        ]);

        $client = $this->makeClient(fn (int $seconds) => null);

        $this->expectException(ValidationException::class);

        $client->query('SELECT Id FROM Account');
    }

    private function makeClient(?callable $sleeper = null): SalesforceApiClient
    {
        return new SalesforceApiClient(
            self::INSTANCE_URL,
            self::ACCESS_TOKEN,
            self::API_VERSION,
            sleeper: $sleeper ?? fn (int $seconds) => null,
        );
    }
}
