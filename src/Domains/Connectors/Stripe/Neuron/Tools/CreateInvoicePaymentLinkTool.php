<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Neuron\Tools;

use Kanvas\Connectors\Stripe\Services\StripePaymentLinkService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesInvoiceForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Hands the asker a link; never sends it. Delivering it to the customer is send_invoice's job, because
 * only a send through an engagement is tracked.
 */
#[AgentTool(name: 'Create Invoice Payment Link', category: 'accounting')]
class CreateInvoicePaymentLinkTool extends Tool implements RequiresSystemAgent
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesInvoiceForTool;
    use TrackByInputs;

    protected string $name = 'create_invoice_payment_link';

    protected ?string $description = 'Creates a Stripe card payment link for what is still owed on an issued invoice and returns '
        . 'it to the user to share. It does not contact the customer. Asking again while the balance is unchanged '
        . 'returns the same link; after a partial payment it makes a new link for the rest. When the customer pays, '
        . 'the invoice is marked paid in Kanvas automatically. Refuses a draft, paid or voided invoice.';

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
                description: 'The Kanvas invoice id (from find_invoice or import_netsuite_invoice).',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $invoice_id): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('invoice payment link');
        }

        $invoice = $this->resolveInvoice($invoice_id);

        if (is_array($invoice)) {
            return $this->notFound($invoice);
        }

        try {
            $link = $this->paymentLinkService()->generatePaymentLinkFromInvoice($invoice);
        } catch (ValidationException $e) {
            return $this->denied($e->getMessage(), guidance: 'No link was created.');
        } catch (Throwable $e) {
            report($e);

            return $this->failed('Stripe could not create the link right now.', guidance: 'No link was created. Do not retry this turn.');
        }

        return $this->ok([
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->invoice_number,
            'amount' => (float) $invoice->balance_due_native,
            'currency' => $invoice->currency,
            'payment_link' => $link->url,
        ], 'Nothing was sent to the customer; give this link to the user to share.');
    }

    protected function paymentLinkService(): StripePaymentLinkService
    {
        return new StripePaymentLinkService($this->app, $this->company);
    }
}
