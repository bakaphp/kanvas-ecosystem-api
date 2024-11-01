<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Jobs;

use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Stripe\Services\StripePlanService;
use Kanvas\Subscription\Importer\Actions\PlanImporterAction;
use Kanvas\Subscription\Importer\DataTransferObjects\PlanImporter;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class ImportStripePlanJob extends ProcessWebhookJob
{
    public array $data = [];

    public function execute(): array
    {
        if (! in_array($this->webhookRequest->payload['type'], ['product.created', 'product.updated'])) {
            Log::error('Webhook type not found', ['type' => $this->webhookRequest->payload['type']]);
            return [];
        }

        $this->data = $this->webhookRequest->payload;
        $webhookPlan = $this->data['data']['object'];
        $app = $this->webhookRequest->receiverWebhook->app;

        $stripePlanService = new StripePlanService(
            app: $app,
            stripePlanId: $webhookPlan['id'],
        );

        $mappedPlan = $stripePlanService->mapPlanForImport($this->webhookRequest->payload);
        $plan = (new PlanImporterAction(
            PlanImporter::from($mappedPlan),
            $app,
            $this->webhookRequest->receiverWebhook->user
        ))->execute();

        return [
            'message' => 'Plan synced successfully',
            'stripe_id' => $webhookPlan['id'],
            'data' => $plan,
        ];
    }
}
