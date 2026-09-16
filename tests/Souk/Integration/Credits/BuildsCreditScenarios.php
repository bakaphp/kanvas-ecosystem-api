<?php

declare(strict_types=1);

namespace Tests\Souk\Integration\Credits;

use Database\Seeders\Souk\DiscountTypeSeeder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Discounts\Enums\DiscountTypeEnum;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Users\Models\Users;

trait BuildsCreditScenarios
{
    protected function seedDiscountTypes(): void
    {
        $this->seed(DiscountTypeSeeder::class);
    }

    protected function creditType(): DiscountType
    {
        return DiscountType::getByName(DiscountTypeEnum::AUTO_APPLIED_CREDIT->label());
    }

    protected function issueCredit(Companies $company, float $amount, string $name = 'Credit'): Discount
    {
        return Discount::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'name' => $name,
            'description' => 'Issued for testing',
            'discount_type_id' => $this->creditType()->getId(),
            'value' => $amount,
            'is_percentage' => false,
            'code' => null,
            'is_active' => true,
            'usage_limit' => 1,
            'usage_count' => 0,
            'is_one_per_customer' => false,
        ]);
    }

    protected function orderFor(Companies $company, float $gross, ?Users $user = null): Order
    {
        $app = app(Apps::class);
        $user ??= auth()->user();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'status' => 'completed',
                'total_gross_amount' => $gross,
                'total_net_amount' => $gross,
                'discount_amount' => 0,
            ]);

        $item = new OrderItem();
        $item->apps_id = $app->getId();
        $item->order_id = $order->getId();
        $item->variant_id = 1;
        $item->variant_name = 'Test item';
        $item->product_name = 'Test item';
        $item->product_sku = 'TEST-SKU';
        $item->quantity = 1;
        $item->unit_price_net_amount = $gross;
        $item->unit_price_gross_amount = $gross;
        $item->quantity_fulfilled = 0;
        $item->currency = 'USD';
        $item->is_public = 1;
        $item->save();

        return $order;
    }
}
