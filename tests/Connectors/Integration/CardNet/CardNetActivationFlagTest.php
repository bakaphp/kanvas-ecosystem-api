<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\CardNet;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\CardNet\Enums\ConfigurationEnum;
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
        $this->kanvasApp->del(ConfigurationEnum::REQUIRES_ACTIVATION->value);
        Mockery::close();
        parent::tearDown();
    }

    public function testTokenizedCardIsPendingWhenTheAppRequiresActivation(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::REQUIRES_ACTIVATION->value, true);

        $result = $this->processor($this->serviceTokenizing())->tokenize($this->cardDetails(), $this->kanvasUser);

        $this->assertTrue($result->success);
        $this->assertTrue($result->raw[CustomFieldEnum::REQUIRES_ACTIVATION->value]);
    }

    public function testTokenizedCardIsActiveWhenTheAppDoesNotRequireActivation(): void
    {
        $result = $this->processor($this->serviceTokenizing())->tokenize($this->cardDetails(), $this->kanvasUser);

        $this->assertTrue($result->success);
        $this->assertFalse($result->raw[CustomFieldEnum::REQUIRES_ACTIVATION->value]);
    }

    public function testSuccessfulActivationClearsThePendingFlag(): void
    {
        $paymentMethod = $this->pendingPaymentMethod();

        /** @var MockInterface&CardNetService $service */
        $service = Mockery::mock(CardNetService::class);
        $service->shouldReceive('activatePaymentProfile')
            ->once()
            ->with(555, 'CT__TOKEN', 'Z2R78V')
            ->andReturn(['PaymentProfileId' => 42]);

        $result = $this->processor($service)->activatePaymentMethod($paymentMethod, 'Z2R78V');

        $paymentMethod->refresh();
        $this->assertTrue($result->success);
        $this->assertFalse($paymentMethod->getMetadata(CustomFieldEnum::REQUIRES_ACTIVATION->value));
        $this->assertSame(42, $paymentMethod->getMetadata(CustomFieldEnum::PAYMENT_PROFILE_ID->value));
    }

    public function testRejectedActivationCodeKeepsThePendingFlag(): void
    {
        $paymentMethod = $this->pendingPaymentMethod();

        /** @var MockInterface&CardNetService $service */
        $service = Mockery::mock(CardNetService::class);
        $service->shouldReceive('activatePaymentProfile')
            ->once()
            ->andThrow(new CardNetException('TK009 INVALID_ACTIVATION_CODE'));

        $result = $this->processor($service)->activatePaymentMethod($paymentMethod, 'WRONG1');

        $paymentMethod->refresh();
        $this->assertFalse($result->success);
        $this->assertTrue($paymentMethod->getMetadata(CustomFieldEnum::REQUIRES_ACTIVATION->value));
        $this->assertNull($paymentMethod->getMetadata(CustomFieldEnum::PAYMENT_PROFILE_ID->value));
    }

    private function processor(CardNetService $service): CardNetProcessor
    {
        return new CardNetProcessor($this->kanvasApp, $service);
    }

    private function serviceTokenizing(): CardNetService
    {
        /** @var MockInterface&CardNetService $service */
        $service = Mockery::mock(CardNetService::class);
        $service->shouldReceive('tokenizeDirect')
            ->once()
            ->andReturn(['TokenId' => 'CT__TOKEN', 'Brand' => 'VISA']);

        return $service;
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
