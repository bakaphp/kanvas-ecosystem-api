<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Webhooks;

use Baka\Support\Str;
use Kanvas\Connectors\Stripe\Traits\CashierWebhookTrait;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Laravel\Cashier\Cashier;

class CashierStripeWebhookJob extends ProcessWebhookJob
{
    use CashierWebhookTrait;

    public function execute(): array
    {
        //$regionId = $this->receiver->configuration['region_id'];
        $payload = $this->webhookRequest->payload;
        $method = 'handle' . Str::studly(str_replace('.', '_', $payload['type']));

        if (method_exists($this, $method)) {
            $this->setMaxNetworkRetries();

            /**
             * @todo this is a copy and past from laravel cashier webhook controller
             * we need to look for a better way to keep this update without copy and paste
             */
            $response = $this->{$method}($payload);
        } else {
            $response = $this->missingMethod($payload);
        }

        return [
            'message' => 'Stripe Webhook Sync',
            'response' => $response,
        ];
    }
}
