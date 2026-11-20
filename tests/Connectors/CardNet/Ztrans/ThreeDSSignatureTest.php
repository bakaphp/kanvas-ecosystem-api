<?php

declare(strict_types=1);

namespace Tests\Connectors\CardNet\Ztrans;

use Kanvas\Connectors\CardNet\Ztrans\Support\ThreeDSSignature;
use PHPUnit\Framework\TestCase;

class ThreeDSSignatureTest extends TestCase
{
    public function testAuthenticationSignatureConcatenatesFieldsInDocumentedOrder(): void
    {
        $signature = ThreeDSSignature::forAuthentication(
            integratorCode: '100000001',
            cardExpiryDate: '2212',
            integratorTxId: 'tx-1',
            purchaseAmount: '500',
            apiKey: 'key'
        );

        $this->assertSame(hash('sha512', '1000000012212tx-1500key'), $signature);
        $this->assertSame(128, strlen($signature));
    }

    public function testStatusSignatureOmitsCardExpiry(): void
    {
        $signature = ThreeDSSignature::forStatus(
            integratorCode: '100000001',
            integratorTxId: 'tx-1',
            purchaseAmount: '500',
            apiKey: 'key'
        );

        $this->assertSame(hash('sha512', '100000001tx-1500key'), $signature);
    }
}
