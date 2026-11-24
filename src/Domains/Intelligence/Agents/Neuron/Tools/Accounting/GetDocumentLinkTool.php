<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesInvoiceForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPersonForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesScribeQuoteForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Delivery\Actions\OpenDocumentEngagementAction;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\Delivery\Services\BillingContactResolverService;
use Kanvas\Scribe\Delivery\Support\DocumentShareRule;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The tracked link of a document for each billing contact, for a person who shares it by hand. Same
 * engagement as a send, so a later send_invoice / send_quote to that person carries this same link.
 */
#[AgentTool(name: 'Get Document Link', category: 'accounting')]
class GetDocumentLinkTool extends Tool implements RequiresSystemAgent
{
    use ReportsToolOutcome;
    use ResolvesInvoiceForTool;
    use ResolvesPersonForTool;
    use ResolvesScribeQuoteForTool;
    use TrackByInputs;

    protected string $name = 'get_document_link';

    protected ?string $description = 'Gets the customer page link of an issued invoice or a sent quote, one per billing contact, so '
        . 'the user can share it themselves. It does not contact anyone, attaches no PDF and does not change the '
        . 'document\'s status. Each link is tracked like a sent one (you see when it is opened), and a later '
        . 'send_invoice or send_quote to the same person reuses it. A quote with a Statement of Work gives the '
        . 'signing page. An e-CF invoice has no link until DGII accepted it. If it answers no_billing_contact, ask '
        . 'who the billing contact is and call again with their person_id.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'document_type',
                type: PropertyType::STRING,
                description: 'invoice or quote.',
                required: true,
                enum: ['invoice', 'quote'],
            ),
            new ToolProperty(
                name: 'document_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas invoice_id or quote_id (from find_invoice or find_quote).',
                required: true,
            ),
            new ToolProperty(
                name: 'person_id',
                type: PropertyType::INTEGER,
                description: 'The one person the link is for, only when the user named one. Saved as a billing '
                    . 'contact of the customer. Leave empty for a link per known billing contact.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $document_type, int $document_id, ?int $person_id = null): array
    {
        $document = match (strtolower(trim($document_type))) {
            'invoice' => $this->resolveInvoice($document_id),
            'quote' => $this->resolveQuote($document_id),
            default => null,
        };

        if ($document === null) {
            return $this->invalidArgs('document_type must be invoice or quote.');
        }

        if (is_array($document)) {
            return $this->notFound($document);
        }

        $named = null;
        if ($person_id !== null) {
            $named = $this->resolvePersonOrError($person_id);
            if (is_array($named)) {
                return $this->notFound(['reason' => 'person_not_found', ...$named]);
            }
        }

        $user = $this->contextUser() ?? $document->company->getAiAgentUserOrFail();
        $action = DocumentActionEnum::forDocument($document);

        try {
            DocumentShareRule::assertLinkable($document);

            $links = array_map(
                fn (People $people): array => new OpenDocumentEngagementAction(
                    document: $document,
                    people: $people,
                    user: $user,
                    action: $action,
                )->execute()->toToolResult(),
                new BillingContactResolverService()->resolve($document, $named),
            );
        } catch (DocumentDeliveryException $e) {
            return $this->denied($e->getMessage(), ['reason' => $e->reason], 'No link was created.');
        }

        return $this->ok(
            [
                'document_type' => $document_type,
                'document_id' => $document->getId(),
                'document_number' => $document->documentNumber(),
                'links' => $links,
            ],
            'Nothing was sent to the customer; hand these links to the user to share.'
        );
    }
}
