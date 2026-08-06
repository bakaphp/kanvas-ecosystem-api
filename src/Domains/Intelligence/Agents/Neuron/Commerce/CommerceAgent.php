<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Commerce;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\ExportRecordsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\CreateSampleOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindSalesOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\ListOpenSalesOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesByCustomerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesByProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesRevenueTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\ListOrderTypesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderBreakdownTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderCommissionStatsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderPaymentStatsTool;
use Override;

/**
 * The Commerce back-office teammate — the store's reporting + operations hand over Souk. It answers
 * "how are sales", "order volume by status/type", "top customers/products", "marketplace commission",
 * looks up sales orders + products, sends sample units, and produces downloadable CSV exports
 * (orders, affiliate-commission reports, …) via export_records.
 *
 * Extends SystemUserAgent (internal teammate: it IS a Kanvas user, has identity + ledger memory).
 * Read/report-first; the only write paths are create_sample_order ($0 draft, human-approved) and the
 * export tool (which just writes a file to the tenant bucket). It never touches accounting documents —
 * receivables/invoices belong to the Accounts Receivable agent.
 */
#[AgentTypeDefinition(
    name: 'Commerce Agent',
    description: 'Commerce back-office teammate — sales/order reporting (revenue, breakdowns, top customers/products, '
        . 'commissions), sales-order + product lookups, sample orders, and CSV exports including affiliate-commission '
        . 'reports, over the Souk tools.',
    provider: 'neuron',
    soul: 'You are the Commerce teammate. You run reporting and back-office operations for the store using the Souk '
        . 'tools: sales and order numbers, top customers and products, marketplace commission, order and product '
        . 'lookups, sample orders, and downloadable CSV exports. You are precise with numbers and always clear about '
        . 'the date range. You never invent figures — if a tool returns nothing, you say so plainly.',
    outputFormat: 'Plain text. Lead with the headline number, then the top 3-5 items; lists only for distinct items. '
        . 'When you produce an export, give the download link and the row count.',
)]
class CommerceAgent extends SystemUserAgent
{
    #[Override]
    protected function tools(): array
    {
        return array_merge(parent::tools(), $this->addToolContext([
            new ListOrderTypesTool(),
            new OrderBreakdownTool(),
            new OrderPaymentStatsTool(),
            new OrderCommissionStatsTool(),
            new FindSalesOrderTool(),
            new ListOpenSalesOrdersTool(),
            new FindProductTool(),
            new CreateSampleOrderTool(),
            new SalesByCustomerTool(),
            new SalesByProductTool(),
            new SalesRevenueTool(),
            new ExportRecordsTool(),
        ]));
    }

    #[Override]
    public function instructions(): string
    {
        return parent::instructions() . "\n\n" . $this->commerceGuidance();
    }

    private function commerceGuidance(): string
    {
        return implode("\n", [
            '## How to handle commerce reporting + back-office questions',
            '- "Order volume" / "how many orders pending vs paid vs cancelled" / pipeline health → order_breakdown (group_by status, or type). If the user names an order type, call list_order_types first to resolve it.',
            '- "How much did we collect" / paid totals / average order value → order_payment_stats.',
            '- "Marketplace commission" / platform take-rate on commissioned orders → order_commission_stats.',
            '- "Revenue this quarter" / sales trend → sales_revenue (set by_month for a trend). "Top customers" / "biggest buyers" → sales_by_customer. "Best sellers" / "top products" → sales_by_product. All exclude draft/canceled orders — state the date range.',
            '- "Look up sales order #X" → find_sales_order. "What orders are open" / a customer\'s in-flight orders → list_open_sales_orders.',
            '- "Send a sample" / free unit for a reviewer → find_product to turn the product NAME into a SKU, then create_sample_order (customer email + name, SKU, qty). Ask for the email if missing — it is a real shipment. It creates a $0 DRAFT that pushes to the ERP only after a human approves it.',
            '- "Export" / "download" / "give me a CSV" of orders, affiliate commissions, etc. → export_records. Pick record_type from that tool\'s list (e.g. affiliate_commissions, orders); pass filters as an object. For an affiliate commission report ask for the affiliate code (e.g. UA20) and the date range if the user did not give them; with no affiliate it exports every affiliate in the company.',
            '- Lead with the headline number, then the top 3-5 items. Always be clear about the date range; if a tool returns nothing, say so instead of guessing.',
            '- Accounting documents (invoices, receivables, who owes us) are the Accounts Receivable agent\'s area, not yours.',
        ]);
    }
}
