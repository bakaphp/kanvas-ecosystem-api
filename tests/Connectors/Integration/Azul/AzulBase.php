<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Azul;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Azul\DataTransferObject\AzulPaymentRequest;
use Kanvas\Connectors\Azul\Enums\TransactionTypeEnum;
use Kanvas\Connectors\Azul\Services\AzulService;
use Tests\TestCase;

class AzulBase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Azul integration tests are skipped in CI');
        }

        if (empty(env('TEST_AZUL_AUTH1'))) {
            $this->markTestSkipped('Azul test credentials not configured');
        }

        if (empty(env('TEST_AZUL_CERT_PATH')) || empty(env('TEST_AZUL_KEY_PATH'))) {
            $this->markTestSkipped('Azul mTLS certificate paths not configured (TEST_AZUL_CERT_PATH, TEST_AZUL_KEY_PATH)');
        }
    }

    protected function getCredentials(): array
    {
        return [
            'auth1'     => env('TEST_AZUL_AUTH1'),
            'auth2'     => env('TEST_AZUL_AUTH2'),
            'store'     => env('TEST_AZUL_STORE'),
            'channel'   => env('TEST_AZUL_CHANNEL', 'EC'),
            'cert_path' => env('TEST_AZUL_CERT_PATH'),
            'key_path'  => env('TEST_AZUL_KEY_PATH'),
        ];
    }

    protected function getService(): AzulService
    {
        $app     = app(Apps::class);
        $company = Companies::first();

        return new AzulService($app, $company, $this->getCredentials());
    }

    protected function buildSaleRequest(array $overrides = []): AzulPaymentRequest
    {
        $credentials    = $this->getCredentials();
        $dataVaultToken = $overrides['dataVaultToken'] ?? null;

        return new AzulPaymentRequest(
            channel:         $credentials['channel'],
            store:           $credentials['store'],
            trxType:         TransactionTypeEnum::SALE,
            amount:          '100',
            itbis:           '18',
            customOrderId:   'TEST-' . time(),
            cardNumber:      $dataVaultToken !== null ? null : '6011000000000004',
            expiration:      $dataVaultToken !== null ? null : '202812',
            cvc:             $dataVaultToken !== null ? null : '818',
            forceNo3DS:      '1',
            saveToDataVault: $overrides['saveToDataVault'] ?? '0',
            dataVaultToken:  $dataVaultToken,
        );
    }
}
