<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\CardNet;

use Kanvas\Connectors\CardNet\DataTransferObject\CardNetPurchaseRequest;
use Tests\TestCase;

final class CardNetServiceTest extends TestCase
{
    use HasCardNetConfiguration;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('CardNet integration tests are skipped in CI');
        }

        $privateKey = env('CARDNET_PRIVATE_KEY');

        if (empty($privateKey)) {
            $this->markTestSkipped('CardNet credentials not configured (CARDNET_PRIVATE_KEY)');
        }

        $this->setUpCardNetConfiguration();
    }

    public function testCreateCustomer(): void
    {
        $service = $this->getCardNetService();
        $email = 'test-' . time() . '@cardnettest.com';

        $response = $service->createCustomer(
            email: $email,
            firstName: 'Test',
            lastName: 'User',
        );

        $this->assertArrayHasKey('CustomerId', $response);
        $this->assertNotEmpty($response['CustomerId']);
    }

    public function testGetCustomer(): void
    {
        $service = $this->getCardNetService();
        $email = 'test-' . time() . '@cardnettest.com';

        $created = $service->createCustomer(
            email: $email,
            firstName: 'John',
            lastName: 'Doe',
        );

        $customerId = (int) $created['CustomerId'];

        $retrieved = $service->getCustomer($customerId);

        $this->assertArrayHasKey('CustomerId', $retrieved);
        $this->assertEquals($customerId, (int) $retrieved['CustomerId']);
        $this->assertArrayHasKey('Email', $retrieved);
        $this->assertEquals($email, $retrieved['Email']);
    }

    public function testTokenizeDirect(): void
    {
        $service = $this->getCardNetService();

        $customer = $this->createTestCustomer();
        $customerId = (int) $customer['CustomerId'];

        $cardDetail = $this->getTestCardDetail($customerId);
        $response = $service->tokenizeDirect($cardDetail);

        $this->assertArrayHasKey('TokenId', $response);
        $this->assertNotEmpty($response['TokenId']);
    }

    public function testPurchaseWithToken(): void
    {
        $service = $this->getCardNetService();
        $token = $this->tokenizeTestCard();

        $purchaseRequest = new CardNetPurchaseRequest(
            trxToken: $token,
            order: 'TEST-ORDER-' . time(),
            amount: 100,
            currency: 'DOP',
            invoice: 'INV-' . time(),
        );

        $result = $service->purchase($purchaseRequest);

        $this->assertTrue($result->isApproved());
        $this->assertGreaterThan(0, $result->getPurchaseId());
    }

    public function testHoldAndCommit(): void
    {
        $service = $this->getCardNetService();
        $token = $this->tokenizeTestCard();

        $holdRequest = new CardNetPurchaseRequest(
            trxToken: $token,
            order: 'TEST-HOLD-' . time(),
            amount: 100,
            currency: 'DOP',
            invoice: 'INV-HOLD-' . time(),
        );

        $holdResult = $service->hold($holdRequest);

        $this->assertTrue($holdResult->isApproved() || $holdResult->isPreauthorized());
        $purchaseId = $holdResult->getPurchaseId();
        $this->assertGreaterThan(0, $purchaseId);

        $commitResult = $service->commit($purchaseId);

        $this->assertTrue($commitResult->isApproved());
    }

    public function testRefund(): void
    {
        $service = $this->getCardNetService();
        $token = $this->tokenizeTestCard();

        $purchaseRequest = new CardNetPurchaseRequest(
            trxToken: $token,
            order: 'TEST-REFUND-' . time(),
            amount: 100,
            currency: 'DOP',
            invoice: 'INV-REFUND-' . time(),
        );

        $purchaseResult = $service->purchase($purchaseRequest);
        $this->assertTrue($purchaseResult->isApproved());

        $purchaseId = $purchaseResult->getPurchaseId();
        $refundResult = $service->refund($purchaseId);

        $this->assertTrue($refundResult->isApproved());
    }

    public function testGetPurchase(): void
    {
        $service = $this->getCardNetService();
        $token = $this->tokenizeTestCard();

        $purchaseRequest = new CardNetPurchaseRequest(
            trxToken: $token,
            order: 'TEST-GET-' . time(),
            amount: 100,
            currency: 'DOP',
            invoice: 'INV-GET-' . time(),
        );

        $purchaseResult = $service->purchase($purchaseRequest);
        $this->assertTrue($purchaseResult->isApproved());

        $purchaseId = $purchaseResult->getPurchaseId();

        $retrieved = $service->getPurchase($purchaseId);

        $this->assertEquals($purchaseId, $retrieved->getPurchaseId());
    }

    private function tokenizeTestCard(): string
    {
        $service = $this->getCardNetService();
        $customer = $this->createTestCustomer();
        $customerId = (int) $customer['CustomerId'];

        $cardDetail = $this->getTestCardDetail($customerId);
        $response = $service->tokenizeDirect($cardDetail);

        $this->assertNotEmpty($response['TokenId'], 'Tokenization failed: ' . ($response['Error']['Message'] ?? 'unknown'));

        return $response['TokenId'];
    }
}
