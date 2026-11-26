<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\DescribesSalesOrderFields;
use Kanvas\Scribe\SalesOrders\Actions\RegisterCustomerPoAction;
use Kanvas\Scribe\SalesOrders\DataTransferObject\SalesOrder as SalesOrderData;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Registers what a customer committed to buy as a received sales order; it never confirms it. */
#[AgentTool(name: 'Register Customer PO', category: 'accounting')]
class RegisterCustomerPoTool extends AbstractCustomerOrderTool
{
    use DescribesSalesOrderFields;

    protected string $name = 'register_customer_po';

    protected ?string $description = 'Registers what a customer committed to buy as a sales order with its own SO number: '
        . 'either the customer\'s own purchase order (po_source customer_po, with its number and date) or our proforma '
        . 'returned signed and stamped (po_source signed_proforma, which takes its lines, currency and number from the '
        . 'quote). The order starts as received: nothing is promised to the customer until it is reviewed against its '
        . 'quote and confirmed. Use it when the person says a customer sent a PO or signed a proforma.';

    /**
     * @return array<int, ToolProperty|ArrayProperty|ObjectProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'customer_organization_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas organization id of the customer (find_customer returns it).',
                required: true,
            ),
            new ToolProperty(
                name: 'po_source',
                type: PropertyType::STRING,
                description: 'customer_po when the customer sent their own purchase order, signed_proforma when they '
                    . 'returned our proforma signed and stamped.',
                required: true,
            ),
            new ToolProperty(
                name: 'quote_number',
                type: PropertyType::STRING,
                description: 'The number of our quote or proforma the order is reviewed against. Required for a '
                    . 'signed proforma.',
                required: false,
            ),
            ...$this->salesOrderFieldProperties(
                'The lines exactly as the customer\'s PO lists them. A signed proforma may leave this out: the '
                . 'quote\'s lines are used.'
            ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>>|null $lines
     * @param array<string, bool>|null $signature_checklist
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        int $customer_organization_id,
        string $po_source,
        ?string $quote_number = null,
        ?string $customer_po_number = null,
        ?string $customer_po_date = null,
        ?string $currency = null,
        ?array $lines = null,
        ?string $incoterm = null,
        ?string $consignee = null,
        ?int $payment_terms_days = null,
        ?string $requested_date = null,
        ?array $signature_checklist = null,
        ?string $notes = null,
    ): array {
        $quote = $this->quoteByNumber($quote_number, 'registered');

        if (is_array($quote)) {
            return $quote;
        }

        try {
            $order = new RegisterCustomerPoAction(
                SalesOrderData::from(
                    $this->app,
                    $this->user,
                    $this->company,
                    array_filter([
                        'customer_id' => $customer_organization_id,
                        'po_source' => $po_source,
                        'quote_id' => $quote?->getId(),
                        'customer_po_number' => $customer_po_number,
                        'customer_po_date' => $customer_po_date,
                        'currency' => $currency,
                        'lines' => $lines,
                        'incoterm' => $incoterm,
                        'consignee' => $consignee,
                        'payment_terms_days' => $payment_terms_days,
                        'requested_date' => $requested_date,
                        'signature_checklist' => $signature_checklist,
                        'notes' => $notes,
                    ], static fn (mixed $value): bool => $value !== null)
                )
            )->execute();
        } catch (ModelNotFoundException) {
            return [
                'registered' => false,
                'reason' => 'customer_not_found',
                'message' => "No customer with organization id {$customer_organization_id} for this company.",
            ];
        } catch (Throwable $e) {
            return [
                'registered' => false,
                'reason' => 'not_registered',
                'message' => $e->getMessage(),
            ];
        }

        return ['registered' => true] + $this->summary($order);
    }
}
