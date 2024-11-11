<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Services;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Subscription\Plans\Repositories\PlanRepository;

class StripePlanService
{
    public function __construct(
        protected AppInterface $app,
        protected string $stripePlanId,
        protected ?UserInterface $user = null
    ) {
        $this->user = $user;
    }

    public function mapPlanForImport(array $data): array
    {
        $webhookPlan = $data['data']['object'];

        $name = $webhookPlan['name'];
        $description = $webhookPlan['description'];
        $status = $webhookPlan['active'];

        return [
            'apps_id' => $this->app->getId(),
            'name' => $name,
            'description' => $description,
            'stripe_id' => $this->stripePlanId,
            'is_active' => $status,
        ];
    }
}
