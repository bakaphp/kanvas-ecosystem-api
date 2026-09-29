<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Services;

use Baka\Support\Str;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\MessageIntentEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureConfigurationEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureReasonEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Repositories\MessagesRepository;

/**
 * What the customer asked decides the obligation, not what the model wants to answer. The
 * customer's text is read from the lead's last message rather than taken as a tool parameter, so
 * the model cannot soften a payment question into a price question to get a template back.
 */
class PriceDisclosureReplyGate
{
    public function __construct(
        private readonly MessageIntentDetector $intents = new MessageIntentDetector(),
    ) {
    }

    /**
     * @return array{reason: PriceDisclosureReasonEnum, detail: string}|null the controlled topic
     *         that must suppress the reply, or null when a price disclosure may be rendered
     */
    public function evaluate(Lead $lead): ?array
    {
        if (! (bool) $lead->company->get(PriceDisclosureConfigurationEnum::ENABLED->value)) {
            return null;
        }

        $inbound = $this->lastCustomerMessage($lead);
        if ($inbound === null) {
            return null;
        }

        $intents = $this->intents->detect($inbound);

        if (in_array(MessageIntentEnum::PAYMENT, $intents, true) || in_array(MessageIntentEnum::COMPARISON, $intents, true)) {
            return [
                'reason' => PriceDisclosureReasonEnum::PAYMENT_UNSUPPORTED,
                'detail' => 'The customer asked about a monthly payment and no approved financing payload exists.',
            ];
        }

        if (in_array(MessageIntentEnum::ADD_ON, $intents, true)) {
            return [
                'reason' => PriceDisclosureReasonEnum::ADD_ON_DISCLOSURE_MISSING,
                'detail' => 'The customer asked about an add-on and no approved add-on disclosure exists.',
            ];
        }

        return null;
    }

    private function lastCustomerMessage(Lead $lead): ?string
    {
        $message = MessagesRepository::getLastMessageByEntity($lead, $lead->app);
        $payload = $message?->message;

        if (! is_array($payload) || ! empty($payload['from_ia'])) {
            return null;
        }

        return Str::trimToNull((string) ($payload['content'] ?? ''));
    }
}
