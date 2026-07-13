<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kanvas\Connectors\Mercury\Client;
use Kanvas\Connectors\Mercury\Enums\ConfigurationEnum;

/**
 * Builds a Mercury Client backed by canned HTTP responses, so tests exercise the real request-building and
 * pagination path rather than a stubbed-out service.
 */
trait HasMercuryConfiguration
{
    protected function configureMercury(CompanyInterface $company): void
    {
        $company->set(ConfigurationEnum::API_TOKEN->value, 'test-mercury-token');
        $company->set(ConfigurationEnum::SYNC_ENABLED->value, '1');
    }

    /**
     * @param list<array<string, mixed>> $responses Decoded JSON bodies, returned in order.
     */
    protected function mercuryClientReturning(
        AppInterface $app,
        CompanyInterface $company,
        array $responses,
    ): Client {
        $mock = new MockHandler(array_map(
            fn (array $body): Response => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body)),
            $responses,
        ));

        return new Client(
            $app,
            $company,
            new GuzzleClient(['handler' => HandlerStack::create($mock)]),
        );
    }

    /**
     * A Mercury account payload with the fields the connector actually reads.
     *
     * EVERYTHING HERE IS SYNTHETIC, deliberately. Never paste a real account number, routing number, legal
     * name, or cardholder from our own Mercury org into a fixture — tests get read by contractors, land in
     * public CI logs, and live forever in git history. `000000000` is a reserved non-routable ABA, so it
     * can't accidentally address a real bank.
     */
    protected function mercuryAccountPayload(
        string $id,
        float $currentBalance = 10000.00,
        string $type = 'mercury',
        string $status = 'active',
    ): array {
        return [
            'id' => $id,
            'name' => 'Test Checking',
            'accountNumber' => '000000001234',
            'routingNumber' => '000000000',
            'status' => $status,
            'type' => $type,
            'createdAt' => '2026-01-01T00:00:00Z',
            'currentBalance' => $currentBalance,
            'availableBalance' => $currentBalance,
            'kind' => 'checking',
            'legalBusinessName' => 'Test Company LLC',
            'dashboardLink' => 'https://mercury.com/dashboard',
            'nickname' => null,
        ];
    }

    /**
     * A Mercury transaction payload. `amount` is SIGNED — negative is money out, exactly as the API sends it.
     */
    protected function mercuryTransactionPayload(
        string $id,
        string $accountId,
        float $signedAmount,
        string $kind = 'externalTransfer',
        string $status = 'sent',
        string $counterpartyName = 'Acme Vendor',
        string $postedAt = '2026-06-15T10:00:00Z',
    ): array {
        return [
            'id' => $id,
            'accountId' => $accountId,
            'amount' => $signedAmount,
            'status' => $status,
            'kind' => $kind,
            'createdAt' => $postedAt,
            'postedAt' => $postedAt,
            'counterpartyId' => 'cp_' . $id,
            'counterpartyName' => $counterpartyName,
            'note' => 'Test transaction',
            'externalMemo' => null,
            'dashboardLink' => 'https://mercury.com/transactions/' . $id,
        ];
    }
}
