<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\CardNet;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\CardNet\Client;
use Kanvas\Connectors\CardNet\Enums\CustomFieldEnum;
use Kanvas\Connectors\CardNet\Exceptions\CardNetException;
use Kanvas\Connectors\CardNet\Services\CardNetService;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Payments\Infrastructure\Processors\CardNet\CardNetProcessor;
use Kanvas\Users\Models\Users;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class CardNetActivationFlagTest extends TestCase
{
    use DatabaseTransactions;

    protected Apps $kanvasApp;
    protected Users $kanvasUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasUser = static::$cachedUser;
        $this->kanvasUser->set(CustomFieldEnum::CUSTOMER_ID->value, 555);
    }

    protected function tearDown(): void
    {
        $this->kanvasUser->del(CustomFieldEnum::CUSTOMER_ID->value);
        Mockery::close();
        parent::tearDown();
    }

    public function testTokenizedCardIsPendingWhenItsCardNetProfileIsDisabled(): void
    {
        $service = $this->serviceTokenizing();
        $service->shouldReceive('getCustomer')->once()->with(555)->andReturn($this->customerWithProfile(enabled: false));

        $result = $this->processor($service)->tokenize($this->cardDetails(), $this->kanvasUser);

        $this->assertTrue($result->success);
        $this->assertTrue($result->raw[CustomFieldEnum::REQUIRES_ACTIVATION->value]);
        $this->assertSame(126577, $result->raw[CustomFieldEnum::PAYMENT_PROFILE_ID->value]);
    }

    public function testTokenizedCardIsActiveWhenItsCardNetProfileIsEnabled(): void
    {
        $service = $this->serviceTokenizing();
        $service->shouldReceive('getCustomer')->once()->with(555)->andReturn($this->customerWithProfile(enabled: true));

        $result = $this->processor($service)->tokenize($this->cardDetails(), $this->kanvasUser);

        $this->assertTrue($result->success);
        $this->assertFalse($result->raw[CustomFieldEnum::REQUIRES_ACTIVATION->value]);
        $this->assertSame(126577, $result->raw[CustomFieldEnum::PAYMENT_PROFILE_ID->value]);
    }

    public function testTokenizedCardIsSavedAsActiveWhenTheProfileCannotBeRead(): void
    {
        $service = $this->serviceTokenizing();
        $service->shouldReceive('getCustomer')->once()->andThrow(new CardNetException('Connection failed'));

        $result = $this->processor($service)->tokenize($this->cardDetails(), $this->kanvasUser);

        $this->assertTrue($result->success);
        $this->assertFalse($result->raw[CustomFieldEnum::REQUIRES_ACTIVATION->value]);
        $this->assertArrayNotHasKey(CustomFieldEnum::PAYMENT_PROFILE_ID->value, $result->raw);
    }

    public function testActivationIsConfirmedAgainstTheCardNetProfile(): void
    {
        $paymentMethod = $this->pendingPaymentMethod();

        $service = $this->serviceActivating();
        $service->shouldReceive('getCustomer')->once()->with(555)->andReturn($this->customerWithProfile(enabled: true));

        $result = $this->processor($service)->activatePaymentMethod($paymentMethod, 'Z2R78V');

        $paymentMethod->refresh();
        $this->assertTrue($result->success);
        $this->assertFalse($paymentMethod->getMetadata(CustomFieldEnum::REQUIRES_ACTIVATION->value));
        $this->assertSame(126577, $paymentMethod->getMetadata(CustomFieldEnum::PAYMENT_PROFILE_ID->value));
    }

    public function testActivationReadsTheProfileFromTheCustomerCardNetReturns(): void
    {
        $paymentMethod = $this->pendingPaymentMethod();

        /** @var MockInterface&CardNetService $service */
        $service = Mockery::mock(CardNetService::class);
        $service->shouldReceive('activatePaymentProfile')
            ->once()
            ->with(555, 'CT__TOKEN', 'Z2R78V')
            ->andReturn($this->customerWithProfile(enabled: true));
        $service->shouldNotReceive('getCustomer');

        $result = $this->processor($service)->activatePaymentMethod($paymentMethod, 'Z2R78V');

        $paymentMethod->refresh();
        $this->assertTrue($result->success);
        $this->assertFalse($paymentMethod->getMetadata(CustomFieldEnum::REQUIRES_ACTIVATION->value));
        $this->assertSame(126577, $paymentMethod->getMetadata(CustomFieldEnum::PAYMENT_PROFILE_ID->value));
    }

    public function testAcceptedActivationOfAProfileStillDisabledKeepsThePendingFlag(): void
    {
        $paymentMethod = $this->pendingPaymentMethod();

        $service = $this->serviceActivating();
        $service->shouldReceive('getCustomer')->once()->andReturn($this->customerWithProfile(enabled: false));

        $result = $this->processor($service)->activatePaymentMethod($paymentMethod, 'Z2R78V');

        $paymentMethod->refresh();
        $this->assertFalse($result->success);
        $this->assertTrue($paymentMethod->getMetadata(CustomFieldEnum::REQUIRES_ACTIVATION->value));
    }

    public function testAcceptedActivationOfATokenCardNetNoLongerHasDisablesTheCard(): void
    {
        $paymentMethod = $this->pendingPaymentMethod();

        $service = $this->serviceActivating();
        $service->shouldReceive('getCustomer')->once()->andReturn(['CustomerId' => 555, 'PaymentProfiles' => []]);

        $result = $this->processor($service)->activatePaymentMethod($paymentMethod, 'Z2R78V');

        $paymentMethod->refresh();
        $this->assertFalse($result->success);
        $this->assertTrue($paymentMethod->getMetadata(CustomFieldEnum::DISABLED->value));
    }

    public function testRejectedActivationCodeKeepsThePendingFlag(): void
    {
        $paymentMethod = $this->pendingPaymentMethod();

        /** @var MockInterface&CardNetService $service */
        $service = Mockery::mock(CardNetService::class);
        $service->shouldReceive('activatePaymentProfile')
            ->once()
            ->andThrow(new CardNetException('TK009 INVALID_ACTIVATION_CODE'));
        $service->shouldReceive('getCustomer')->once()->andReturn($this->customerWithProfile(enabled: false));

        $result = $this->processor($service)->activatePaymentMethod($paymentMethod, 'WRONG1');

        $paymentMethod->refresh();
        $this->assertFalse($result->success);
        $this->assertTrue($paymentMethod->getMetadata(CustomFieldEnum::REQUIRES_ACTIVATION->value));
        $this->assertNull($paymentMethod->getMetadata(CustomFieldEnum::DISABLED->value));
    }

    public function testRejectedActivationThatRemovedTheTokenDisablesTheCard(): void
    {
        $paymentMethod = $this->pendingPaymentMethod();

        /** @var MockInterface&CardNetService $service */
        $service = Mockery::mock(CardNetService::class);
        $service->shouldReceive('activatePaymentProfile')
            ->once()
            ->andThrow(new CardNetException('TK009 INVALID_ACTIVATION_CODE'));
        $service->shouldReceive('getCustomer')->once()->andReturn(['CustomerId' => 555, 'PaymentProfiles' => []]);

        $result = $this->processor($service)->activatePaymentMethod($paymentMethod, 'WRONG3');

        $paymentMethod->refresh();
        $this->assertFalse($result->success);
        $this->assertTrue($paymentMethod->getMetadata(CustomFieldEnum::DISABLED->value));
    }

    public function testActivationErrorsReturnedWithASuccessfulStatusKeepThePendingFlag(): void
    {
        $paymentMethod = $this->pendingPaymentMethod();

        /** @var MockInterface&Client $client */
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->with('v1/api/customer/555/activate', ['Token' => 'CT__TOKEN', 'ActivationCode' => 'ZZZZZZ'])
            ->andReturn([
                'Response' => null,
                'Errors' => [['ErrorCode' => 'TK012', 'Message' => 'Error en la Activación del Token']],
            ]);
        $client->shouldReceive('get')
            ->once()
            ->with('v1/api/customer/555')
            ->andReturn(['Response' => $this->customerWithProfile(enabled: true), 'Errors' => []]);

        $result = $this->processor(new CardNetService($client))->activatePaymentMethod($paymentMethod, 'ZZZZZZ');

        $paymentMethod->refresh();
        $this->assertFalse($result->success);
        $this->assertSame('TK012 Error en la Activación del Token', $result->message);
        $this->assertTrue($paymentMethod->getMetadata(CustomFieldEnum::REQUIRES_ACTIVATION->value));
    }

    private function serviceActivating(): MockInterface&CardNetService
    {
        /** @var MockInterface&CardNetService $service */
        $service = Mockery::mock(CardNetService::class);
        $service->shouldReceive('activatePaymentProfile')
            ->once()
            ->with(555, 'CT__TOKEN', 'Z2R78V')
            ->andReturn([]);

        return $service;
    }

    private function processor(CardNetService $service): CardNetProcessor
    {
        return new CardNetProcessor($this->kanvasApp, $service);
    }

    private function serviceTokenizing(): MockInterface&CardNetService
    {
        /** @var MockInterface&CardNetService $service */
        $service = Mockery::mock(CardNetService::class);
        $service->shouldReceive('tokenizeDirect')
            ->once()
            ->andReturn(['TokenId' => 'CT__TOKEN', 'Brand' => 'VISA']);

        return $service;
    }

    private function customerWithProfile(bool $enabled): array
    {
        return [
            'CustomerId' => 555,
            'PaymentProfiles' => [
                ['PaymentProfileId' => 126570, 'Token' => 'CT__OTHER', 'Enabled' => true],
                ['PaymentProfileId' => 126577, 'Token' => 'CT__TOKEN', 'Enabled' => $enabled],
            ],
        ];
    }

    private function cardDetails(): array
    {
        return [
            'number' => '4111 1111 1111 1111',
            'cvv' => '123',
            'expiration_date' => '12/29',
            'firstname' => 'Test',
            'lastname' => 'Card',
        ];
    }

    private function pendingPaymentMethod(): PaymentMethods
    {
        $paymentMethod = new PaymentMethods();
        $paymentMethod->apps_id = $this->kanvasApp->getId();
        $paymentMethod->companies_id = $this->kanvasUser->getCurrentCompany()->getId();
        $paymentMethod->users_id = $this->kanvasUser->getId();
        $paymentMethod->stripe_card_id = 'CT__TOKEN';
        $paymentMethod->payment_ending_numbers = '1111';
        $paymentMethod->payment_methods_brand = 'visa';
        $paymentMethod->expiration_date = '12/29';
        $paymentMethod->processor = 'cardnet';
        $paymentMethod->metadata = [
            CustomFieldEnum::CUSTOMER_ID->value => 555,
            CustomFieldEnum::REQUIRES_ACTIVATION->value => true,
        ];
        $paymentMethod->save();

        return $paymentMethod;
    }
}
