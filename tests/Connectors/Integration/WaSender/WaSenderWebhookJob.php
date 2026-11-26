<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Kanvas\Communication\Webhooks\ProcessCommunicationWebhookJob;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * The communication webhook for a receiver these tests configure freely: they replace the whole
 * `configuration` to set group and assistant keys, which would drop the provider name the real job
 * reads from it. WaSenderProviderTest covers the receiver naming its provider.
 */
final class WaSenderWebhookJob extends ProcessCommunicationWebhookJob
{
    #[Override]
    protected static function providerOf(ReceiverWebhook $receiver): ?string
    {
        return WaSenderHandler::slug();
    }
}
