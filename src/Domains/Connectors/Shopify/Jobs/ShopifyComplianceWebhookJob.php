<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Jobs;

use Kanvas\Connectors\Shopify\Traits\ShopifyWebhookValidation;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

#[WorkflowAction(
    name: 'Shopify Compliance Webhook',
    description: 'Receiver for Shopify\'s mandatory GDPR compliance callbacks (customer data request, customer '
        . 'redact, shop redact). It records the request so there is a signed audit trail; it does not '
        . 'action the erasure itself. Required by Shopify for app listing — wire it and leave it alone.',
    integration: IntegrationsEnum::SHOPIFY,
)]
class ShopifyComplianceWebhookJob extends ProcessWebhookJob
{
    use ShopifyWebhookValidation;
    protected int $failedReturnHttpCode = 401;

    #[Override]
    public function execute(): array
    {
        $this->validateShopifyWebhook();

        $messageInput = [
            'message' => $this->webhookRequest->payload,
            'reactions_count' => 0,
            'comments_count' => 0,
            'total_liked' => 0,
            'total_disliked' => 0,
            'total_saved' => 0,
            'total_shared' => 0,
            'ip_address' => $this->webhookRequest->payload['x-real-ip'] ?? null,
        ];

        $action = 'shopify_compliance_webhook';
        $messageTypeDto = MessageTypeInput::from([
            'apps_id' => $this->receiver->app->getId(),
            'name' => $action,
            'verb' => $action,
        ]);
        $messageType = (new CreateMessageTypeAction($messageTypeDto))->execute();

        $createMessage = (new CreateMessageAction(
            MessageInput::fromArray(
                $messageInput,
                $this->receiver->user,
                $messageType,
                $this->receiver->company,
                $this->receiver->app,
            ),
        ))->execute();

        return [
            'message' => 'Message created successfully for Shopify compliance webhook',
            'message_id' => $createMessage->getId(),
        ];
    }
}
