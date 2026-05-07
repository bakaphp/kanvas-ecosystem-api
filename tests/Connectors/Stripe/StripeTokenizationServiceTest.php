<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\CustomFieldEnum;
use Kanvas\Connectors\Stripe\Services\StripeTokenizationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Users\Models\Users;
use Stripe\Customer;
use Stripe\Exception\InvalidRequestException as StripeInvalidRequestException;
use Stripe\PaymentMethod as StripePaymentMethod;
use Tests\Connectors\Stripe\Fakes\FakeStripeClient;
use Tests\TestCase;

class StripeTokenizationServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected Apps $kanvasApp;
    protected Companies $company;
    protected Users $kanvasUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasUser = $this->createUser();
        $this->actingAs($this->kanvasUser, 'api');
        $this->company = $this->kanvasUser->getCurrentCompany();
    }

    private function buildService(FakeStripeClient $client): StripeTokenizationService
    {
        return new StripeTokenizationService($this->kanvasApp, $this->company, $client);
    }

    private function compositeCustomerKey(): string
    {
        return CustomFieldEnum::STRIPE_CUSTOMER_ID->value
            . '-' . $this->kanvasApp->getId()
            . '-' . $this->company->getId();
    }

    private function fakePaymentMethod(string $pmId, string $last4 = '4242', string $brand = 'visa', int $expYear = 2030, int $expMonth = 12): StripePaymentMethod
    {
        return StripePaymentMethod::constructFrom([
            'id' => $pmId,
            'object' => 'payment_method',
            'type' => 'card',
            'card' => [
                'last4' => $last4,
                'brand' => $brand,
                'exp_year' => $expYear,
                'exp_month' => $expMonth,
            ],
        ]);
    }

    private function fakeCustomer(string $customerId, string $email = 'test@example.com'): Customer
    {
        return Customer::constructFrom([
            'id' => $customerId,
            'object' => 'customer',
            'email' => $email,
        ]);
    }

    public function testNewCustomerHappyPath(): void
    {
        $pmId = 'pm_test_new_' . uniqid();
        $customerId = 'cus_test_' . uniqid();

        $client = new FakeStripeClient();
        $client->getCustomers()->queueResponse('create', $this->fakeCustomer($customerId));
        $client->getPaymentMethods()->queueResponse('retrieve', $this->fakePaymentMethod($pmId));
        $client->getPaymentMethods()->queueResponse('attach', $this->fakePaymentMethod($pmId));

        $result = $this->buildService($client)->tokenize(
            ['stripe_payment_method_id' => $pmId],
            $this->kanvasUser
        );

        $this->assertTrue($result->success);
        $this->assertSame($pmId, $result->token);
        $this->assertSame('4242', $result->lastFour);
        $this->assertSame('visa', $result->brand);

        $compositeKey = $this->compositeCustomerKey();
        $this->assertSame($customerId, $this->kanvasUser->get($compositeKey));

        $createCalls = $client->getCustomers()->getCalls('create');
        $this->assertCount(1, $createCalls);

        $row = PaymentMethods::where('users_id', $this->kanvasUser->getId())
            ->where('processor', 'stripe')
            ->where('is_deleted', 0)
            ->latest('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('4242', $row->payment_ending_numbers);
        $this->assertSame('visa', $row->payment_methods_brand);
    }

    public function testExistingCustomerIsReused(): void
    {
        $pmId = 'pm_test_existing_' . uniqid();
        $customerId = 'cus_existing_' . uniqid();

        $compositeKey = $this->compositeCustomerKey();
        $this->kanvasUser->set($compositeKey, $customerId);

        $client = new FakeStripeClient();
        $client->getCustomers()->queueResponse('retrieve', $this->fakeCustomer($customerId));
        $client->getPaymentMethods()->queueResponse('retrieve', $this->fakePaymentMethod($pmId));
        $client->getPaymentMethods()->queueResponse('attach', $this->fakePaymentMethod($pmId));

        $result = $this->buildService($client)->tokenize(
            ['stripe_payment_method_id' => $pmId],
            $this->kanvasUser
        );

        $this->assertTrue($result->success);

        $createCalls = $client->getCustomers()->getCalls('create');
        $this->assertCount(0, $createCalls);

        $retrieveCalls = $client->getCustomers()->getCalls('retrieve');
        $this->assertCount(1, $retrieveCalls);
    }

    public function testExistingCustomerDeletedAtStripeRecreatesCustomer(): void
    {
        $pmId = 'pm_test_recreate_' . uniqid();
        $staleCustomerId = 'cus_stale_' . uniqid();
        $newCustomerId = 'cus_new_' . uniqid();

        $compositeKey = $this->compositeCustomerKey();
        $this->kanvasUser->set($compositeKey, $staleCustomerId);

        $notFoundError = StripeInvalidRequestException::factory(
            'No such customer: ' . $staleCustomerId,
            404,
            null,
            null,
            null,
            null,
        );

        $client = new FakeStripeClient();
        $client->getCustomers()->queueResponse('retrieve', $notFoundError);
        $client->getCustomers()->queueResponse('create', $this->fakeCustomer($newCustomerId));
        $client->getPaymentMethods()->queueResponse('retrieve', $this->fakePaymentMethod($pmId));
        $client->getPaymentMethods()->queueResponse('attach', $this->fakePaymentMethod($pmId));

        $result = $this->buildService($client)->tokenize(
            ['stripe_payment_method_id' => $pmId],
            $this->kanvasUser
        );

        $this->assertTrue($result->success);

        $createCalls = $client->getCustomers()->getCalls('create');
        $this->assertCount(1, $createCalls);

        $this->assertSame($newCustomerId, $this->kanvasUser->get($compositeKey));
    }

    public function testDedupeOnRetokenize(): void
    {
        $pmId = 'pm_test_dedupe_' . uniqid();
        $customerId = 'cus_dedupe_' . uniqid();

        $client = new FakeStripeClient();
        $client->getCustomers()->queueResponse('create', $this->fakeCustomer($customerId));
        $client->getPaymentMethods()->queueResponse('retrieve', $this->fakePaymentMethod($pmId));
        $client->getPaymentMethods()->queueResponse('attach', $this->fakePaymentMethod($pmId));

        $this->buildService($client)->tokenize(
            ['stripe_payment_method_id' => $pmId],
            $this->kanvasUser
        );

        $client2 = new FakeStripeClient();
        $client2->getCustomers()->queueResponse('retrieve', $this->fakeCustomer($customerId));
        $client2->getPaymentMethods()->queueResponse('retrieve', $this->fakePaymentMethod($pmId));
        $client2->getPaymentMethods()->queueResponse('attach', $this->fakePaymentMethod($pmId));

        $result2 = $this->buildService($client2)->tokenize(
            ['stripe_payment_method_id' => $pmId],
            $this->kanvasUser
        );

        $this->assertTrue($result2->success);

        $rowCount = PaymentMethods::where('users_id', $this->kanvasUser->getId())
            ->where('processor', 'stripe')
            ->where('is_deleted', 0)
            ->whereJsonContains('metadata->stripe_payment_method_id', $pmId)
            ->count();

        $this->assertSame(1, $rowCount);
    }

    public function testStripeAttachFailureSurfacesAsValidationExceptionWithNoRowCreated(): void
    {
        $pmId = 'pm_test_fail_' . uniqid();
        $customerId = 'cus_fail_' . uniqid();

        $apiError = StripeInvalidRequestException::factory(
            'This payment method cannot be attached.',
            400,
            null,
            null,
            null,
            null,
        );

        $client = new FakeStripeClient();
        $client->getCustomers()->queueResponse('create', $this->fakeCustomer($customerId));
        $client->getPaymentMethods()->queueResponse('retrieve', $this->fakePaymentMethod($pmId));
        $client->getPaymentMethods()->queueResponse('attach', $apiError);

        $this->expectException(ValidationException::class);

        try {
            $this->buildService($client)->tokenize(
                ['stripe_payment_method_id' => $pmId],
                $this->kanvasUser
            );
        } finally {
            $rowCount = PaymentMethods::where('users_id', $this->kanvasUser->getId())
                ->where('processor', 'stripe')
                ->whereJsonContains('metadata->stripe_payment_method_id', $pmId)
                ->count();

            $this->assertSame(0, $rowCount);
        }
    }

    public function testDeleteTokenWithAlreadyDetachedPmSucceeds(): void
    {
        $pmId = 'pm_test_delete_' . uniqid();
        $customerId = 'cus_delete_' . uniqid();

        $client = new FakeStripeClient();
        $client->getCustomers()->queueResponse('create', $this->fakeCustomer($customerId));
        $client->getPaymentMethods()->queueResponse('retrieve', $this->fakePaymentMethod($pmId));
        $client->getPaymentMethods()->queueResponse('attach', $this->fakePaymentMethod($pmId));

        $service = $this->buildService($client);
        $service->tokenize(['stripe_payment_method_id' => $pmId], $this->kanvasUser);

        $alreadyDetached = StripeInvalidRequestException::factory(
            'No such payment method: ' . $pmId,
            404,
            null,
            null,
            null,
            null,
        );

        $deleteClient = new FakeStripeClient();
        $deleteClient->getPaymentMethods()->queueResponse('detach', $alreadyDetached);

        $result = $this->buildService($deleteClient)->deleteToken($pmId);

        $this->assertTrue($result);

        $rowCount = PaymentMethods::where('users_id', $this->kanvasUser->getId())
            ->where('processor', 'stripe')
            ->where('is_deleted', 0)
            ->whereJsonContains('metadata->stripe_payment_method_id', $pmId)
            ->count();

        $this->assertSame(0, $rowCount);
    }
}
