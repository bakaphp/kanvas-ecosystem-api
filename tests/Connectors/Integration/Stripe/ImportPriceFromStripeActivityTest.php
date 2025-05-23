<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Stripe;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Services\StripePriceService;
use Kanvas\Subscription\Importer\Actions\PriceImporterAction;
use Kanvas\Subscription\Importer\DataTransferObjects\PriceImporter;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ImportPriceFromStripeActivityTest extends TestCase
{
    protected Apps $appModel;
    protected UserInterface $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appModel = app(Apps::class);
        $this->user = Users::factory()->create();
        $this->seedAppPlans();
        if (empty($this->appModel->get(ConfigurationEnum::STRIPE_SECRET_KEY->value))) {
            $this->appModel->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, getenv('TEST_STRIPE_SECRET_KEY'));
        }
    }

    public function testImportPriceWorkflow(): void
    {
        $app = app(Apps::class);

        $priceData = $this->getPriceTestData();
        $webhookPrice = $priceData['data']['object'];
        $stripePriceService = new StripePriceService(
            app: $app,
            stripePriceId: $webhookPrice['id'],
        );

        $mappedPrice = $stripePriceService->mapPriceForImport($priceData);
        $price = (new PriceImporterAction(
            PriceImporter::from($mappedPrice),
            $this->user,
            $app
        ))->execute();

        $this->assertEquals(
            $price->stripe_id,
            $webhookPrice['id']
        );
    }

    public function getPriceTestData(): array
    {
        return json_decode('{
        "id": "evt_1QFL4zBwyV21ueMMcrkvc9u4",
        "object": "event",
        "api_version": "2019-10-17",
        "created": 1730230069,
        "data": {
            "object": {
                "id": "price_1QFL4zBwyV21ueMMsdOn1EVz",
                "object": "price",
                "active": true,
                "billing_scheme": "per_unit",
                "created": 1730230069,
                "currency": "usd",
                "custom_unit_amount": null,
                "livemode": false,
                "lookup_key": null,
                "metadata": {},
                "nickname": null,
                "product": "prod_R7aFDqoZMlE1E7",
                "recurring": {
                    "aggregate_usage": null,
                    "interval": "year",
                    "interval_count": 1,
                    "meter": null,
                    "trial_period_days": null,
                    "usage_type": "licensed"
                },
                "tax_behavior": "unspecified",
                "tiers_mode": null,
                "transform_quantity": null,
                "type": "recurring",
                "unit_amount": 5000,
                "unit_amount_decimal": "3000"
            }
        },
        "livemode": false,
        "pending_webhooks": 1,
        "request": {
            "id": "req_LVCz5GHxMJ15qv",
            "idempotency_key": "c5a2ae52-9ba7-4225-ba58-19fa37c3efa1"
        },
        "type": "price.created"
    }', true); // true to return as associative array
    }

    protected function seedAppPlans()
    {
        $plan = [
            'apps_id' => $this->appModel->id,
            'name' => 'Test Plan',
            'payment_interval' => 'year',
            'description' => 'This is a test plan.',
            'stripe_id' => 'prod_R7aFDqoZMlE1E7',
            'free_trial_dates' => 15,
            'is_default' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('apps_plans')->updateOrInsert(
            ['stripe_id' => $plan['stripe_id']],
            $plan
        );
    }
}
