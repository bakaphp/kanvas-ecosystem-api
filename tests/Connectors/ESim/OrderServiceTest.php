<?php

declare(strict_types=1);

namespace Tests\Connectors\ESim;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ESim\Enums\ConfigurationEnum;
use Kanvas\Connectors\ESim\Enums\ProviderEnum;
use Kanvas\Connectors\ESim\Services\OrderService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;
use Kanvas\Souk\Orders\Models\Order;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OrderServiceTest extends TestCase
{
    use DatabaseTransactions;

    public static function providersThatNeedABundle(): array
    {
        return [
            'esimgo' => [ProviderEnum::E_SIM_GO->value],
            'airalo' => [ProviderEnum::AIRALO->value],
        ];
    }

    #[DataProvider('providersThatNeedABundle')]
    public function testAVariantWithoutABundleAttributeFailsValidationInsteadOfReadingNull(string $provider): void
    {
        $variant = $this->variantFor($provider);
        $variant->shouldReceive('getAttributeByName')->with('esim_bundle_type')->andReturnNull();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Variant 99 (ESIM-99) has no esim_bundle_type attribute');

        new OrderService($this->order(), $variant)->createOrder();
    }

    public function testAnEsimGoVariantWithoutDaysFailsValidationInsteadOfReadingNull(): void
    {
        $variant = $this->variantFor(ProviderEnum::E_SIM_GO->value);
        $variant->shouldReceive('getAttributeByName')->with('esim_bundle_type')->andReturn($this->attribute('esim_US_7D'));
        $variant->shouldReceive('getAttributeByName')->with('esim_days')->andReturnNull();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('has no esim_days attribute');

        new OrderService($this->order(), $variant)->createOrder();
    }

    /**
     * The ESim client reads its credentials from the order's app; a mocked app keeps the
     * settings out of the shared test app, which every parallel process reads.
     */
    private function order(): Order
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create();

        $configuredApp = Mockery::mock(Apps::class)->makePartial();
        $configuredApp->shouldReceive('get')->with(ConfigurationEnum::BASE_URL->value)->andReturn('https://esim.test');
        $configuredApp->shouldReceive('get')->with(ConfigurationEnum::APP_TOKEN->value)->andReturn('test-token');
        $order->setRelation('app', $configuredApp);

        return $order;
    }

    private function variantFor(string $provider): Variants
    {
        $variant = Mockery::mock(Variants::class)->makePartial();
        $variant->id = 99;
        $variant->sku = 'ESIM-99';
        $variant->shouldReceive('getAttributeBySlug')
            ->with(ConfigurationEnum::VARIANT_PROVIDER_SLUG->value)
            ->andReturn($this->attribute($provider));

        return $variant;
    }

    private function attribute(string $value): VariantsAttributes
    {
        $attribute = new VariantsAttributes();
        $attribute->setTranslation('value', 'en', $value);

        return $attribute;
    }
}
