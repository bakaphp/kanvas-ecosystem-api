<?php

declare(strict_types=1);

namespace Database\Seeders\Workflow;

use Illuminate\Database\Seeder;
use Kanvas\Connectors\CardNet\Handlers\CardNetHandler;
use Kanvas\Connectors\Internal\Handlers\InternalHandler;
use Kanvas\Connectors\Stripe\Handlers\StripeHandler;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\Integrations;

class IntegrationsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Integrations::firstOrCreate(
            [
                'name' => IntegrationsEnum::INTERNAL->value,
                'apps_id' => 0,
            ],
            [
                'handler' => InternalHandler::class,
            ]
        );

        Integrations::firstOrCreate(
            [
                'name' => IntegrationsEnum::CARDNET->value,
                'apps_id' => 0,
            ],
            [
                'handler' => CardNetHandler::class,
            ]
        );

        Integrations::firstOrCreate(
            [
                'name' => IntegrationsEnum::STRIPE->value,
                'apps_id' => 0,
            ],
            [
                'handler' => StripeHandler::class,
            ]
        );
    }
}
