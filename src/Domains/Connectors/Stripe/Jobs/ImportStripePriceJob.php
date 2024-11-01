<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Jobs;

use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Stripe\Services\StripePriceService;
use Kanvas\Subscription\Importer\Actions\PriceImporterAction;
use Kanvas\Subscription\Importer\DataTransferObjects\PriceImporter;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class ImportStripePriceJob extends ProcessWebhookJob
{
    public array $data = [];

    public function execute(): array
    {
        if (! in_array($this->webhookRequest->payload['type'], ['price.created', 'price.updated'])) {
            Log::error('Webhook type not found', ['type' => $this->webhookRequest->payload['type']]);
            return [];
        }

        $this->data = $this->webhookRequest->payload;
        $webhookPrice = $this->data['data']['object'];
        $app = $this->webhookRequest->receiverWebhook->app;

        $stripePriceService = new StripePriceService(
            app: $app,
            stripePriceId: $webhookPrice['id'],
        );

        $mappedPrice = $stripePriceService->mapPriceForImport($this->webhookRequest->payload);
        $price = (new PriceImporterAction(
            PriceImporter::from($mappedPrice),
            $app,
            $this->webhookRequest->receiverWebhook->user
        ))->execute();

        return [
            'message' => 'Price synced successfully',
            'stripe_id' => $webhookPrice['id'],
            'data' => $price,
        ];
    }
}
