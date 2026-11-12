<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Neuron\Tools;

use Baka\Support\Str;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteInvoiceAction;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Import NetSuite Invoice', category: 'accounting')]
class ImportNetSuiteInvoiceTool extends Tool implements RequiresSystemAgent
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use TrackByInputs;

    protected string $name = 'import_netsuite_invoice';

    protected ?string $description = 'Imports one NetSuite invoice into Kanvas: the sales order it was billed from becomes the '
        . 'Kanvas order, line for line, and the invoice becomes a Kanvas invoice linked to that order, with the same total '
        . 'and balance as NetSuite. Products on the order that Kanvas does not have yet are added from NetSuite. Running it '
        . 'again for the same invoice refreshes its balance instead of creating a copy. It contacts no one. Pass the '
        . 'invoice number people use (e.g. 2611599) or, from a NetSuite link (custinvc.nl?id=1986059), the internal id. '
        . 'If the import fails, customer, product or order changes may already have been saved; tell the user why it failed.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'invoice_number',
                type: PropertyType::STRING,
                description: 'The NetSuite invoice number (tranId), e.g. 2611599.',
                required: false,
            ),
            new ToolProperty(
                name: 'internal_id',
                type: PropertyType::STRING,
                description: 'The NetSuite internal id, the id= in the invoice URL. Use when the user pasted a link.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $invoice_number = null, ?string $internal_id = null): array
    {
        $invoiceNumber = Str::trimToNull($invoice_number);
        $internalId = Str::trimToNull($internal_id);

        if ($invoiceNumber === null && $internalId === null) {
            return $this->invalidArgs('Pass invoice_number or internal_id.');
        }

        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('NetSuite invoice import');
        }

        $action = $this->importAction();

        try {
            $invoice = $invoiceNumber !== null
                ? $action->executeByNumber($invoiceNumber)
                : $action->executeById($internalId);
        } catch (ModelNotFoundException $e) {
            return $this->notFound(
                ['reason' => 'netsuite_invoice_not_found', 'message' => $e->getMessage()],
                'Ask the user to check the missing NetSuite record. Related customer, product or order changes may already have been saved.'
            );
        } catch (ValidationException $e) {
            return $this->denied($e->getMessage(), guidance: 'Tell the user why the import failed; related customer, product or order changes may already have been saved.');
        } catch (Throwable $e) {
            report($e);

            return $this->failed('The NetSuite invoice import failed.', guidance: 'Related customer, product or order changes may already have been saved. Do not retry this turn.');
        }

        return $this->ok([
            'invoice' => [
                'id' => $invoice->getId(),
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->document_status->value,
                'total' => (float) $invoice->total_native,
                'balance_due' => (float) $invoice->balance_due_native,
                'currency' => $invoice->currency,
                'due_date' => $invoice->due_date?->toDateString(),
                'customer' => $invoice->billable_display_name,
            ],
            'order' => $action->order === null ? null : [
                'id' => $action->order->getId(),
                'order_number' => $action->order->getOrderNumber(),
                'lines' => $action->order->items()->count(),
            ],
            'products_added_from_netsuite' => $action->pulledProducts(),
            'products_restored_from_netsuite' => $action->restoredProducts(),
        ], 'Imported. To collect payment, create_invoice_payment_link with this invoice id.');
    }

    /**
     * Records are written as the agent's own user, so an import is attributed to the agent that ran it.
     */
    protected function importAction(): PullNetSuiteInvoiceAction
    {
        return new PullNetSuiteInvoiceAction(
            $this->app,
            $this->company,
            $this->contextAgent()?->user ?? $this->contextUser() ?? $this->company->getAiAgentUserOrFail()
        );
    }
}
