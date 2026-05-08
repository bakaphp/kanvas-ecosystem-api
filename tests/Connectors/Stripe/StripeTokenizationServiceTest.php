<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\CustomFieldEnum;
use Kanvas\Connectors\Stripe\Services\StripeTokenizationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Payments\DataTransferObjet\PaymentMethod as PaymentMethodData;
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

    private function fakePaymentMethod(
        string $pmId,
        string $last4 = '4242',
        string $brand = 'visa',
        int $expYear = 2030,
        int $expMonth = 12,
    ): StripePaymentMethod {
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
        $client->getPaymentMethods()->queueResponse('attach', $this->fakePaymentMethod($pmId));
        $client->getPaymentMethods()->queueResponse('retrieve', $this->fakePaymentMethod($pmId));

        $result = $this->buildService($client)->tokenize(
            ['stripe_payment_method_id' => $pmId],
            $this->kanvasUser
        );

        $this->assertTrue($result->success);
        $this->assertSame($pmId, $result->token);
        $this->assertSame('4242', $result->lastFour);
        $this->assertSame('visa', $result->brand);
        $this->assertSame($customerId, $result->raw['stripe_customer_id']);
        $this->assertSame($pmId, $result->raw['stripe_payment_method_id']);
        $this->assertSame($customerId, $this->kanvasUser->get($this->compositeCustomerKey()));
        $this->assertCount(1, $client->getCustomers()->getCalls('create'));
    }

    public function testExistingCustomerIsReused(): void
    {
        $pmId = 'pm_test_existing_' . uniqid();
        $customerId = 'cus_existing_' . uniqid();

        $this->kanvasUser->set($this->compositeCustomerKey(), $customerId);

        $client = new FakeStripeClient();
        $client->getCustomers()->queueResponse('retrieve', $this->fakeCustomer($customerId));
        $client->getPaymentMethods()->queueResponse('attach', $this->fakePaymentMethod($pmId));
        $client->getPaymentMethods()->queueResponse('retrieve', $this->fakePaymentMethod($pmId));

        $result = $this->buildService($client)->tokenize(
            ['stripe_payment_method_id' => $pmId],
            $this->kanvasUser
        );

        $this->assertTrue($result->success);
        $this->assertCount(0, $client->getCustomers()->getCalls('create'));
        $this->assertCount(1, $client->getCustomers()->getCalls('retrieve'));
    }

    public function testExistingCustomerDeletedAtStripeRecreatesCustomer(): void
    {
        $pmId = 'pm_test_recreate_' . uniqid();
        $staleCustomerId = 'cus_stale_' . uniqid();
        $newCustomerId = 'cus_new_' . uniqid();

        $this->kanvasUser->set($this->compositeCustomerKey(), $staleCustomerId);

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
        $client->getPaymentMethods()->queueResponse('attach', $this->fakePaymentMethod($pmId));
        $client->getPaymentMethods()->queueResponse('retrieve', $this->fakePaymentMethod($pmId));

        $result = $this->buildService($client)->tokenize(
            ['stripe_payment_method_id' => $pmId],
            $this->kanvasUser
        );

        $this->assertTrue($result->success);
        $this->assertCount(1, $client->getCustomers()->getCalls('create'));
        $this->assertSame($newCustomerId, $this->kanvasUser->get($this->compositeCustomerKey()));
    }

    public function testRetokenizeStillSucceedsWhenAlreadyAttached(): void
    {
        $pmId = 'pm_test_already_' . uniqid();
        $customerId = 'cus_already_' . uniqid();

        $this->kanvasUser->set($this->compositeCustomerKey(), $customerId);

        $alreadyAttached = StripeInvalidRequestException::factory(
            'The payment method (' . $pmId . ') has already been attached to a customer.',
            400,
            null,
            null,
            null,
            null,
        );

        $client = new FakeStripeClient();
        $client->getCustomers()->queueResponse('retrieve', $this->fakeCustomer($customerId));
        $client->getPaymentMethods()->queueResponse('attach', $alreadyAttached);
        $client->getPaymentMethods()->queueResponse('retrieve', $this->fakePaymentMethod($pmId));

        $result = $this->buildService($client)->tokenize(
            ['stripe_payment_method_id' => $pmId],
            $this->kanvasUser
        );

        $this->assertTrue($result->success);
        $this->assertSame($pmId, $result->token);
    }

    public function testStripeAttachFailureSurfacesAsValidationException(): void
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
        $client->getPaymentMethods()->queueResponse('attach', $apiError);

        $this->expectException(ValidationException::class);

        $this->buildService($client)->tokenize(
            ['stripe_payment_method_id' => $pmId],
            $this->kanvasUser
        );
    }

    public function testDeleteTokenDetachesAtStripe(): void
    {
        $pmId = 'pm_test_delete_' . uniqid();

        $client = new FakeStripeClient();
        $client->getPaymentMethods()->queueResponse('detach', $this->fakePaymentMethod($pmId));

        $result = $this->buildService($client)->deleteToken($pmId);

        $this->assertTrue($result);
        $this->assertCount(1, $client->getPaymentMethods()->getCalls('detach'));
    }

    public function testDeleteTokenSucceedsWhenAlreadyDetached(): void
    {
        $pmId = 'pm_test_already_detached_' . uniqid();

        $alreadyDetached = StripeInvalidRequestException::factory(
            'No such payment method: ' . $pmId,
            404,
            null,
            null,
            null,
            null,
        );

        $client = new FakeStripeClient();
        $client->getPaymentMethods()->queueResponse('detach', $alreadyDetached);

        $result = $this->buildService($client)->deleteToken($pmId);

        $this->assertTrue($result);
    }

    public function testUpdateTokenSyncsBillingDetailsToStripe(): void
    {
        $pmId = 'pm_test_update_' . uniqid();

        $client = new FakeStripeClient();
        $client->getPaymentMethods()->queueResponse('update', $this->fakePaymentMethod($pmId));

        $existing = new PaymentMethodData(
            app: $this->kanvasApp,
            user: $this->kanvasUser,
            company: $this->company,
            payment_ending_numbers: '4242',
            payment_methods_brand: 'visa',
            expiration_date: '2030-12-31',
            zip_code: '00000',
            stripe_card_id: $pmId,
            instrument_identifier_id: null,
            processor: 'stripe',
            metadata: ['stripe_payment_method_id' => $pmId],
        );

        $updated = $this->buildService($client)->updateToken($existing, [
            'address' => '123 Main St',
            'city' => 'Santo Domingo',
            'country' => 'DO',
            'zip_code' => '10101',
            'phone' => '+18095551234',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
        ]);

        $this->assertSame($pmId, $updated->stripe_card_id);
        $this->assertSame('10101', $updated->zip_code);
        $this->assertSame('stripe', $updated->processor);
        $this->assertSame('Santo Domingo', $updated->metadata['city']);
        $this->assertSame('123 Main St', $updated->metadata['address']);
        $this->assertSame($pmId, $updated->metadata['stripe_payment_method_id']);

        $updateCalls = $client->getPaymentMethods()->getCalls('update');
        $this->assertCount(1, $updateCalls);
        $this->assertSame($pmId, $updateCalls[0]['params']['id']);
        $this->assertSame('Jane Doe', $updateCalls[0]['params']['billing_details']['name']);
        $this->assertSame('123 Main St', $updateCalls[0]['params']['billing_details']['address']['line1']);
        $this->assertSame('10101', $updateCalls[0]['params']['billing_details']['address']['postal_code']);
    }

    public function testUpdateTokenSkipsStripeCallWhenNoBillingDetailsProvided(): void
    {
        $pmId = 'pm_test_noupdate_' . uniqid();

        $client = new FakeStripeClient();

        $existing = new PaymentMethodData(
            app: $this->kanvasApp,
            user: $this->kanvasUser,
            company: $this->company,
            payment_ending_numbers: '4242',
            payment_methods_brand: 'visa',
            expiration_date: '2030-12-31',
            zip_code: '00000',
            stripe_card_id: $pmId,
            instrument_identifier_id: null,
            processor: 'stripe',
            metadata: [],
        );

        $updated = $this->buildService($client)->updateToken($existing, [
            'expiration_date' => '2031-01-31',
        ]);

        $this->assertSame('2031-01-31', $updated->expiration_date);
        $this->assertCount(0, $client->getPaymentMethods()->getCalls('update'));
    }
}
