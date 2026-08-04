<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Webhooks;

use Illuminate\Http\Request;
use Kanvas\Connectors\Twilio\Actions\RecordDeliveryStatusEventAction;
use Kanvas\Connectors\Twilio\Enums\CustomFieldEnum;
use Kanvas\Connectors\Twilio\Services\WebhookSignatureValidator;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

#[WorkflowAction]
class ProcessTwilioMessageStatusWebhookJob extends ProcessWebhookJob
{
    private const string MESSAGE_TYPE_VERB = 'twilio-message-status';

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        return WebhookSignatureValidator::validate(
            request: $request,
            company: $receiver->company,
            expectedUrl: $receiver->getUrl(),
        );
    }

    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $sid = trim((string) ($payload['MessageSid'] ?? $payload['SmsSid'] ?? ''));
        $status = trim((string) ($payload['MessageStatus'] ?? $payload['SmsStatus'] ?? ''));
        $errorCode = trim((string) ($payload['ErrorCode'] ?? ''));
        $errorMessage = trim((string) ($payload['ErrorMessage'] ?? ''));

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
            $recordedDelivery = new RecordDeliveryStatusEventAction(
                message: null,
                payload: $payload,
                app: $this->receiver->app,
                company: $this->receiver->company,
            )->execute();
            if ($recordedDelivery['created']) {
                $this->applyDestinationRemediation($payload, $errorCode);
            }

            return [
                'message' => 'Twilio status recorded; parent message not found',
                'sid' => $sid,
                'twilio_status' => $status,
                'attempt_id' => $recordedDelivery['attempt']->getId(),
                'event_id' => $recordedDelivery['event']->getId(),
            ];
        }

        $recordedDelivery = new RecordDeliveryStatusEventAction($parent, $payload)->execute();
        $attempt = $recordedDelivery['attempt'];

        $slug = 'twilio-status-' . strtolower(
            $sid . '-' . $status . '-' . substr($recordedDelivery['event']->event_key, 0, 16),
        );
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

        $currentStatus = (string) ($parent->get(CustomFieldEnum::CURRENT_STATUS->value) ?? '');
        if (RecordDeliveryStatusEventAction::canAdvanceStatus($currentStatus, $status)) {
            $parent->set(CustomFieldEnum::CURRENT_STATUS->value, $attempt->current_status);
            $parent->set(CustomFieldEnum::LAST_STATUS_AT->value, now()->toAtomString());
            if ($errorCode !== '') {
                $parent->set(CustomFieldEnum::LAST_ERROR_CODE->value, $errorCode);
            }
            if ($errorMessage !== '') {
                $parent->set(CustomFieldEnum::LAST_ERROR_MESSAGE->value, $errorMessage);
            }
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

        if ($recordedDelivery['created']) {
            $this->applyDestinationRemediation($payload, $errorCode);
        }

        return [
            'message' => 'Twilio message status recorded',
            'sid' => $sid,
            'twilio_status' => $status,
            'message_id' => $child->getId(),
            'parent_message_id' => $parent->getId(),
        ];
    }

    protected function applyDestinationRemediation(array $payload, string $errorCode): void
    {
        if (! in_array($errorCode, ['21610', '30006'], true)) {
            return;
        }

        $destination = trim((string) ($payload['To'] ?? ''));
        if ($destination === '') {
            return;
        }

        $normalizedDestination = Contact::normalizeValue(
            $destination,
            ContactTypeEnum::CELLPHONE->value,
        );

        $people = PeoplesRepository::getByPhoneNumber(
            app: $this->receiver->app,
            company: $this->receiver->company,
            phoneNumbers: [$destination, Contact::cleanPhone($destination)],
        )->get();

        foreach ($people as $person) {
            if ($errorCode === '21610') {
                $person->setPhoneOptOut($destination);

                continue;
            }

            $person->contacts()
                ->whereIn('contacts_types_id', Contact::PHONE_TYPES)
                ->get()
                ->filter(
                    fn (Contact $contact): bool => Contact::normalizeValue(
                        $contact->value,
                        $contact->contacts_types_id,
                    ) === $normalizedDestination,
                )
                ->each(fn (Contact $contact): bool => $contact->markInvalid());
        }
    }
}
