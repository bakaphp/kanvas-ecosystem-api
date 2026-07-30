<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Webhooks;

use Kanvas\Connectors\Twilio\Enums\CustomFieldEnum;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

#[WorkflowAction]
class ProcessTwilioMessageStatusWebhookJob extends ProcessWebhookJob
{
    private const string MESSAGE_TYPE_VERB = 'twilio-message-status';

    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $sid = trim((string) ($payload['MessageSid'] ?? $payload['SmsSid'] ?? ''));
        $status = trim((string) ($payload['MessageStatus'] ?? $payload['SmsStatus'] ?? ''));

        if ($sid === '' || $status === '') {
            $this->failedReturnHttpCode = 422;

            return [
                'status' => 422,
                'message' => 'MessageSid and MessageStatus are required',
            ];
        }

        $parent = Message::getByCustomFieldBuilderTransactionSafe(
            CustomFieldEnum::MESSAGE_SID->value,
            $sid,
            $this->receiver->company,
        )
            ->fromApp($this->receiver->app)
            ->fromCompany($this->receiver->company)
            ->notDeleted()
            ->first();

        if (! $parent instanceof Message) {
            return [
                'message' => 'Twilio parent message not found',
                'sid' => $sid,
                'twilio_status' => $status,
            ];
        }

        $slug = 'twilio-status-' . strtolower($sid . '-' . $status);
        $existingChild = Message::query()
            ->fromApp($this->receiver->app)
            ->fromCompany($this->receiver->company)
            ->notDeleted()
            ->where('parent_id', $parent->getId())
            ->where('slug', $slug)
            ->first();

        if ($existingChild instanceof Message) {
            return [
                'message' => 'Twilio message status already recorded',
                'sid' => $sid,
                'twilio_status' => $status,
                'message_id' => $existingChild->getId(),
            ];
        }

        $messageType = MessageTypeService::getOrCreate(
            $parent->app,
            self::MESSAGE_TYPE_VERB,
        );

        $child = new CreateMessageAction(
            new MessageInput(
                app: $parent->app,
                company: $parent->company,
                user: $parent->user,
                type: $messageType,
                message: new AiChatMessagePayload(
                    content: $status,
                    from_me: true,
                    from_ia: false,
                    raw_data: $payload,
                    message_id: $sid,
                )->toArray(),
                parent_id: $parent->getId(),
                parent_unique_id: $parent->uuid,
                tags: ['twilio-status', $status],
                slug: $slug,
            ),
        )->execute();

        return [
            'message' => 'Twilio message status recorded',
            'sid' => $sid,
            'twilio_status' => $status,
            'message_id' => $child->getId(),
            'parent_message_id' => $parent->getId(),
        ];
    }
}
