<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DeliversToBillingContacts;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPersonForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesScribeQuoteForTool;
use Kanvas\Scribe\Delivery\DataTransferObject\DocumentDelivery;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\Delivery\Services\BillingContactResolverService;
use Kanvas\Scribe\Quotes\Actions\SendQuoteAction;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteTransitionException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/** Sends a quote to the customer as a tracked link (a signing page when it has a SOW) and marks it SENT. */
#[AgentTool(name: 'Send Quote', category: 'accounting')]
class SendQuoteTool extends Tool implements RequiresSystemAgent
{
    use DeliversToBillingContacts;
    use HasKanvasContext;
    use ResolvesPersonForTool;
    use ResolvesScribeQuoteForTool;
    use TrackByInputs;

    protected string $name = 'send_quote';

    protected ?string $description = 'Sends a quote to the customer as a tracked link (email with the quote PDF and the SOW '
        . 'attached, or WhatsApp), and marks a draft SENT — which assigns its number and validity date. A quote '
        . 'with a Statement of Work opens a signing page: the customer must sign to accept, and accepting starts '
        . 'its billing schedule on its own. You see when they open, accept or decline it. Call it again on a sent '
        . 'quote to resend. With no person_id it goes to the quote\'s contact, else to every billing contact of the '
        . 'customer, each with their own link; recipients lists who got it. If it answers no_billing_contact, '
        . 'ask who should receive it and call again with their person_id.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'quote_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas quote id (returned as quote_id by create_quote or find_quote).',
                required: true,
            ),
            new ToolProperty(
                name: 'person_id',
                type: PropertyType::INTEGER,
                description: 'The one person to send it to, only when the customer named one. Saved as a billing '
                    . 'contact of the customer. Leave empty to use the quote\'s contact or the billing contacts.',
                required: false,
            ),
            new ToolProperty(
                name: 'channel',
                type: PropertyType::STRING,
                description: 'email or whatsapp. Leave empty for email when they have one, else WhatsApp.',
                required: false,
                enum: [LeadCommunicationChannelEnum::EMAIL->value, LeadCommunicationChannelEnum::WHATSAPP->value],
            ),
            new ToolProperty(
                name: 'message',
                type: PropertyType::STRING,
                description: 'Optional short note to the customer, in their language. The link is added for you.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $quote_id,
        ?int $person_id = null,
        ?string $channel = null,
        ?string $message = null
    ): array {
        $quote = $this->resolveQuote($quote_id);

        if (is_array($quote)) {
            return ['sent' => false, ...$quote];
        }

        if ($quote->customer_organization_id === null) {
            return [
                'sent' => false,
                'reason' => 'no_customer',
                'message' => "Quote {$quote_id} has no customer on it — it cannot be sent.",
            ];
        }

        $named = null;
        if ($person_id !== null) {
            $named = $this->resolvePersonOrError($person_id);
            if (is_array($named)) {
                return ['sent' => false, 'reason' => 'person_not_found', ...$named];
            }
        }

        /** @var Organization $customer */
        $customer = Organization::getByIdFromCompanyApp(
            $quote->customer_organization_id,
            $this->company,
            $this->app,
        );

        $channel = LeadCommunicationChannelEnum::tryFrom((string) Str::trimToNull($channel));
        $sent = $quote;

        try {
            $result = $this->deliverToEach(
                new BillingContactResolverService()->resolve($quote, $named),
                function (People $people) use (&$sent, $customer, $channel, $message): DocumentDelivery {
                    $action = new SendQuoteAction(
                        quote: $sent,
                        billable: $customer,
                        user: $this->contextUser(),
                        deliverTo: $people,
                        channel: $channel,
                        agent: $this->contextAgent(),
                        body: $message,
                    );
                    $sent = $action->execute();

                    return $action->delivery;
                }
            );
        } catch (InvalidQuoteTransitionException $e) {
            return [
                'sent' => false,
                'reason' => 'invalid_transition',
                'message' => $e->getMessage(),
            ];
        } catch (DocumentDeliveryException $e) {
            return ['sent' => false, 'reason' => $e->reason, 'message' => $e->getMessage()];
        }

        if ($result['recipients'] === []) {
            return [
                'sent' => false,
                'reason' => $result['first_failure']?->reason,
                'message' => $result['first_failure']?->getMessage(),
                'failed' => $result['failed'],
            ];
        }

        return [
            'sent' => true,
            'quote_id' => $sent->getId(),
            'quote_number' => $sent->quote_number,
            'status' => $sent->status->value,
            'customer' => $sent->billable_display_name,
            'currency' => $sent->currency,
            'total' => $sent->total_native,
            'valid_until' => $sent->valid_until?->toDateString(),
            'recipients' => $result['recipients'],
            'failed' => $result['failed'],
        ];
    }
}
