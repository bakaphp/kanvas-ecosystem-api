<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DeliversToBillingContacts;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesInvoiceForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPersonForTool;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\Delivery\Services\BillingContactResolverService;
use Kanvas\Scribe\Invoices\Actions\SendInvoiceAction;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/** The one way an invoice reaches a customer: a tracked link (plus the legal PDF by email), then SENT. */
#[AgentTool(name: 'Send Invoice', category: 'accounting')]
class SendInvoiceTool extends Tool implements RequiresSystemAgent
{
    use DeliversToBillingContacts;
    use ResolvesInvoiceForTool;
    use ResolvesPersonForTool;
    use TrackByInputs;

    protected string $name = 'send_invoice';

    protected ?string $description = 'Sends an issued invoice to the customer\'s billing contacts as a tracked link (email with '
        . 'the legal PDF attached, or WhatsApp) and marks it SENT. With no person_id it goes to every billing '
        . 'contact, each with their own link; recipients lists who got it. You see later whether they opened it '
        . 'and when it is paid. Call it again to resend, e.g. on WhatsApp when the email was never opened. For an '
        . 'e-CF company it refuses until DGII accepted the e-CF. If it answers no_billing_contact, ask who '
        . 'receives invoices at that customer and call again with their person_id.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'invoice_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas invoice id (invoice_id from create_ar_invoice or find_invoice).',
                required: true,
            ),
            new ToolProperty(
                name: 'person_id',
                type: PropertyType::INTEGER,
                description: 'The one person to send it to, only when the customer named one. Saved as a billing '
                    . 'contact of the customer. Leave empty to send to every known billing contact.',
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
        int $invoice_id,
        ?int $person_id = null,
        ?string $channel = null,
        ?string $message = null
    ): array {
        $invoice = $this->resolveInvoice($invoice_id);
        if (is_array($invoice)) {
            return ['sent' => false, ...$invoice];
        }

        $named = null;
        if ($person_id !== null) {
            $named = $this->resolvePersonOrError($person_id);
            if (is_array($named)) {
                return ['sent' => false, 'reason' => 'person_not_found', ...$named];
            }
        }

        $user = $this->contextUser() ?? $invoice->company->getAiAgentUserOrFail();
        $channel = LeadCommunicationChannelEnum::tryFrom((string) Str::trimToNull($channel));

        try {
            $result = $this->deliverToEach(
                new BillingContactResolverService()->resolve($invoice, $named),
                fn (People $people) => new SendInvoiceAction(
                    invoice: $invoice,
                    people: $people,
                    user: $user,
                    channel: $channel,
                    agent: $this->contextAgent(),
                    body: $message,
                )->execute()
            );
        } catch (DocumentDeliveryException $e) {
            return ['sent' => false, 'reason' => $e->reason, 'message' => $e->getMessage()];
        }

        if ($result['recipients'] === []) {
            return $this->undeliveredResult($result);
        }

        return [
            'sent' => true,
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->refresh()->document_status->value,
            'recipients' => $result['recipients'],
            'failed' => $result['failed'],
        ];
    }
}
