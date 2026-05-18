<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe\Fakes;

use LogicException;
use PHPUnit\Framework\TestCase;
use Stripe\Customer;
use Stripe\PaymentIntent;

final class FakeStripeClientTest extends TestCase
{
    public function testInstantiatesWithoutNetworkAccess(): void
    {
        $client = new FakeStripeClient();

        $this->assertInstanceOf(FakeStripeClient::class, $client);
        $this->assertSame('sk_test_fake', $client->getApiKey());
    }

    public function testQueuedResponseIsReturnedAndCallIsRecorded(): void
    {
        $client = new FakeStripeClient();

        $fakeIntent = PaymentIntent::constructFrom([
            'id' => 'pi_test_001',
            'status' => 'requires_payment_method',
            'amount' => 1000,
            'currency' => 'usd',
        ]);

        $client->getPaymentIntents()->queueResponse('create', $fakeIntent);

        $result = $client->paymentIntents->create([
            'amount' => 1000,
            'currency' => 'usd',
            'payment_method_types' => ['card'],
        ]);

        $this->assertSame('pi_test_001', $result->id);
        $this->assertSame('requires_payment_method', $result->status);

        $calls = $client->getPaymentIntents()->getCalls('create');
        $this->assertCount(1, $calls);
        $this->assertSame(1000, $calls[0]['params']['amount']);
    }

    public function testThrowsWhenNoResponseQueued(): void
    {
        $client = new FakeStripeClient();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/FakePaymentIntentsService::create called without a queued response/');

        $client->paymentIntents->create(['amount' => 500, 'currency' => 'usd']);
    }

    public function testMultipleQueuedResponsesConsumedInOrder(): void
    {
        $client = new FakeStripeClient();
        $service = $client->getCustomers();

        $service->queueResponse('create', Customer::constructFrom(['id' => 'cus_first', 'email' => 'a@test.com']));
        $service->queueResponse('create', Customer::constructFrom(['id' => 'cus_second', 'email' => 'b@test.com']));

        $first = $client->customers->create(['email' => 'a@test.com']);
        $second = $client->customers->create(['email' => 'b@test.com']);

        $this->assertSame('cus_first', $first->id);
        $this->assertSame('cus_second', $second->id);
    }
}
