<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AbstractCustomerOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AnswerCustomerOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CancelCustomerOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ConfirmCustomerOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindCustomerOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListCustomerOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\RegisterCustomerPoTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ResolvePoDifferenceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ReviewCustomerPoTool;
use Kanvas\Scribe\Quotes\Models\Quote;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\AssertsToolRunKeys;
use Tests\Traits\CreatesTestQuote;

class CustomerOrderToolsTest extends ScribeTestCase
{
    use AssertsToolRunKeys;
    use CreatesTestQuote;

    public function test_the_agent_takes_a_customer_po_through_both_gates(): void
    {
        [$customer, $quote] = $this->customerWithQuote();

        $registered = $this->register($customer, $quote, ['incoterm' => 'FOB']);

        $this->assertTrue($registered['registered']);
        $this->assertSame('received', $registered['document_status']);
        $this->assertStringContainsString('review_customer_po', $registered['next']);

        $orderNumber = $registered['order_number'];
        $reviewed = $this->tool(ReviewCustomerPoTool::class)
            ->__invoke(order_number: $orderNumber, agreed_incoterm: 'CIF');

        $this->assertTrue($reviewed['reviewed']);
        $this->assertSame(['incoterm'], $reviewed['open_differences']);
        $this->assertStringContainsString('resolve_po_difference', $reviewed['next']);

        $blocked = $this->tool(ConfirmCustomerOrderTool::class)
            ->__invoke(order_number: $orderNumber, feasibility_confirmed: true);
        $this->assertFalse($blocked['confirmed']);
        $this->assertStringContainsString('Still open: incoterm', $blocked['message']);

        $resolved = $this->tool(ResolvePoDifferenceTool::class)->__invoke(
            order_number: $orderNumber,
            key: 'incoterm',
            resolution: 'Customer agreed to FOB by email',
        );
        $this->assertSame([], $resolved['open_differences']);

        $confirmed = $this->tool(ConfirmCustomerOrderTool::class)
            ->__invoke(order_number: $orderNumber, feasibility_confirmed: true);
        $this->assertTrue($confirmed['confirmed']);
        $this->assertSame('confirmed', $confirmed['document_status']);

        $accepted = $this->tool(AnswerCustomerOrderTool::class)->__invoke(
            order_number: $orderNumber,
            decision: 'accept',
            etd: '2026-12-10',
            eta: '2026-12-28',
        );
        $this->assertTrue($accepted['answered']);
        $this->assertSame('accepted', $accepted['document_status']);
        $this->assertSame('2026-12-28', $accepted['eta']);

        $found = $this->tool(FindCustomerOrderTool::class)->__invoke(order_number: $orderNumber);
        $this->assertTrue($found['found']);
        $this->assertCount(2, $found['lines']);
    }

    public function test_operations_can_return_an_order_and_it_is_listed_by_status(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $orderNumber = $this->register($customer, $quote)['order_number'];
        $this->tool(ReviewCustomerPoTool::class)->__invoke(order_number: $orderNumber);
        $this->tool(ConfirmCustomerOrderTool::class)
            ->__invoke(order_number: $orderNumber, feasibility_confirmed: true);

        $noReason = $this->tool(AnswerCustomerOrderTool::class)
            ->__invoke(order_number: $orderNumber, decision: 'return');
        $this->assertFalse($noReason['answered']);

        $returned = $this->tool(AnswerCustomerOrderTool::class)->__invoke(
            order_number: $orderNumber,
            decision: 'return',
            reason: 'No container space',
        );
        $this->assertSame('returned', $returned['document_status']);

        $listed = $this->tool(ListCustomerOrdersTool::class)
            ->__invoke(status: 'returned', customer_organization_id: $customer->getId());
        $this->assertSame([$orderNumber], array_column($listed['sales_orders'], 'order_number'));
    }

    public function test_an_unknown_decision_is_refused(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $orderNumber = $this->register($customer, $quote)['order_number'];

        $result = $this->tool(AnswerCustomerOrderTool::class)
            ->__invoke(order_number: $orderNumber, decision: 'maybe');

        $this->assertFalse($result['answered']);
        $this->assertStringContainsString('accept or return', $result['message']);
    }

    public function test_cancelling_reports_the_new_status(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $orderNumber = $this->register($customer, $quote)['order_number'];

        $result = $this->tool(CancelCustomerOrderTool::class)
            ->__invoke(order_number: $orderNumber, reason: 'Customer withdrew');

        $this->assertTrue($result['cancelled']);
        $this->assertSame('cancelled', $result['document_status']);
    }

    public function test_an_unknown_customer_quote_or_order_is_reported_not_guessed(): void
    {
        [$customer, $quote] = $this->customerWithQuote();

        $noCustomer = $this->tool(RegisterCustomerPoTool::class)
            ->__invoke(customer_organization_id: 999999999, po_source: 'customer_po');
        $noQuote = $this->register(
            $customer,
            $quote,
            [],
            quoteNumber: 'NO-SUCH-QUOTE'
        );
        $noOrder = $this->tool(FindCustomerOrderTool::class)->__invoke(order_number: 'SO-999999999');

        $this->assertSame('customer_not_found', $noCustomer['reason']);
        $this->assertSame('quote_not_found', $noQuote['reason']);
        $this->assertSame('sales_order_not_found', $noOrder['reason']);
    }

    public function test_a_status_that_does_not_exist_is_refused(): void
    {
        $result = $this->tool(ListCustomerOrdersTool::class)->__invoke(status: 'shipped_maybe');

        $this->assertSame('unknown_status', $result['reason']);
    }

    public function test_the_sales_order_tools_key_their_run_budget_by_inputs(): void
    {
        foreach ([
            RegisterCustomerPoTool::class,
            ReviewCustomerPoTool::class,
            ResolvePoDifferenceTool::class,
            ConfirmCustomerOrderTool::class,
            AnswerCustomerOrderTool::class,
            CancelCustomerOrderTool::class,
        ] as $tool) {
            $this->assertRunKeyFollowsInputs(new $tool());
        }
    }

    /**
     * @template T of AbstractCustomerOrderTool
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function tool(string $class): AbstractCustomerOrderTool
    {
        return new $class()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function register(
        Organization $customer,
        Quote $quote,
        array $extra = [],
        ?string $quoteNumber = null,
    ): array {
        return $this->tool(RegisterCustomerPoTool::class)->__invoke(...[
            'customer_organization_id' => $customer->getId(),
            'po_source' => 'customer_po',
            'quote_number' => $quoteNumber ?? $quote->quote_number,
            'customer_po_number' => 'PO-' . uniqid(),
            'customer_po_date' => '2026-11-20',
            'currency' => 'USD',
            'lines' => [
                ['description' => 'Pallet of rice', 'quantity' => 10, 'unit_price' => 20, 'sku' => 'RICE-1'],
                ['description' => 'Pallet of beans', 'quantity' => 5, 'unit_price' => 30, 'sku' => 'BEAN-1'],
            ],
            'signature_checklist' => ['authorized_signer' => true],
        ] + $extra);
    }

    /**
     * @return array{Organization, Quote}
     */
    private function customerWithQuote(): array
    {
        $customer = $this->seedTestOrganization('Tool Customer');

        return [$customer, $this->createTestQuote($customer)];
    }
}
