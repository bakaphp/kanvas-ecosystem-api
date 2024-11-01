<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Stripe;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Stripe\Services\StripePlanService;
use Kanvas\Subscription\Importer\Actions\PlanImporterAction;
use Kanvas\Subscription\Importer\DataTransferObjects\PlanImporter;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Tests\TestCase;

final class ImportPlanFromStripeActivityTest extends TestCase
{
    protected Apps $appModel;
    protected UserInterface $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appModel = app(Apps::class);
        $this->user = Users::factory()->create();
        if (empty($this->appModel->get(ConfigurationEnum::STRIPE_SECRET_KEY->value))) {
            $this->appModel->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, getenv('TEST_STRIPE_SECRET_KEY'));
        }
    }

    public function testImportPlanWorkflow(): void
    {
        $app = app(Apps::class);

        $planData = $this->getPlanTestData();
        $webhookPlan = $planData['data']['object'];
        $stripePlanService = new StripePlanService(
            app: $app,
            stripePlanId: $webhookPlan['id'],
        );

        $mappedPlan = $stripePlanService->mapPlanForImport($planData);
        $plan = (new PlanImporterAction(
            PlanImporter::from($mappedPlan),
            $this->user,
            $app,
        ))->execute();

        $this->assertEquals(
            $plan->stripe_id,
            $webhookPlan['id']
        );
    }

    public function getPlanTestData(): array
    {
        return json_decode('{
        "id": "evt_1QG8cdBwyV21ueMMOISO9HZI",
        "object": "event",
        "api_version": "2019-10-17",
        "created": 1730420511,
        "data": {
            "object": {
            "id": "prod_R8PRt8soaKKSVK",
            "object": "product",
            "active": true,
            "attributes": [
            ],
            "created": 1730420510,
            "default_price": "price_1QG8ccBwyV21ueMMw4EcqGBP",
            "description": "test description",
            "features": [
            ],
            "images": [
            ],
            "livemode": false,
            "marketing_features": [
            ],
            "metadata": {
            },
            "name": "test webhook plan",
            "package_dimensions": null,
            "shippable": null,
            "statement_descriptor": null,
            "tax_code": null,
            "type": "service",
            "unit_label": null,
            "updated": 1730420511,
            "url": null
            },
            "previous_attributes": {
            "default_price": null,
            "updated": 1730420510
            }
        },
        "livemode": false,
        "pending_webhooks": 3,
        "request": {
            "id": "req_gcBQxG0UzVnoL2",
            "idempotency_key": "4ff15309-94cc-42ad-b642-2da3f4693f9c"
        },
        "type": "product.updated"
    }', true); // true to return as associative array
    }
}
