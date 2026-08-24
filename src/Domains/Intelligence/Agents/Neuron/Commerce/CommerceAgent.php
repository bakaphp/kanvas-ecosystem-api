<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Commerce;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\ExportRecordsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\ExportTableTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\CheckProductDiscoverySetupTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\ConfigureProductDiscoveryTool;
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
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderFulfillmentStatsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderPaymentStatsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderProviderStatsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderTrendTool;
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
        . 'reports, over the Souk tools. Also sets up and diagnoses natural-language product discovery '
        . 'for the storefront.',
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
            new OrderTrendTool(),
            new OrderFulfillmentStatsTool(),
            new OrderProviderStatsTool(),
            new FindSalesOrderTool(),
            new ListOpenSalesOrdersTool(),
            new FindProductTool(),
            new CreateSampleOrderTool(),
            new SalesByCustomerTool(),
            new SalesByProductTool(),
            new SalesRevenueTool(),
            new ExportRecordsTool(),
            new ExportTableTool(),
            new CheckProductDiscoverySetupTool(),
            new ConfigureProductDiscoveryTool(),
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
            '- "Marketplace commission" / platform take-rate on commissioned orders → order_commission_stats. Per-provider payouts / "what do we owe provider X" / "which provider sells the most" → order_provider_stats.',
            '- "Orders over time" / "month by month" / "which week was best" / "is volume up or down" → order_trend (group_by day|week|month). It returns only periods that have orders — do not read a missing period as a data gap.',
            '- "Waiting to ship" / "paid but not fulfilled" / "how much is uncollected" / fulfillment backlog → order_fulfillment_stats.',
            '- "Revenue this quarter" / sales trend → sales_revenue (set by_month for a trend). "Top customers" / "biggest buyers" → sales_by_customer. "Best sellers" / "top products" → sales_by_product. All exclude draft/canceled orders — state the date range.',
            '- "Look up sales order #X" → find_sales_order. "What orders are open" / a customer\'s in-flight orders → list_open_sales_orders.',
            '- "Send a sample" / free unit for a reviewer → find_product to turn the product NAME into a SKU, then create_sample_order (customer email + name, SKU, qty). Ask for the email if missing — it is a real shipment. It creates a $0 DRAFT that pushes to the ERP only after a human approves it.',
            '- "Export" / "download" / "give me a CSV" of orders, affiliate commissions, etc. → export_records. Pick record_type from that tool\'s list (e.g. affiliate_commissions, orders); pass filters as an object — the orders export takes status, order_type, from_date and to_date. For an affiliate commission report ask for the affiliate code (e.g. UA20) and the date range if the user did not give them; with no affiliate it exports every affiliate in the company.',
            '- Lead with the headline number, then the top 3-5 items. Always be clear about the date range; if a tool returns nothing, say so instead of guessing.',
            '- Accounting documents (invoices, receivables, who owes us) are the Accounts Receivable agent\'s area, not yours.',
            '',
            '## Natural-language product discovery (the storefront gift/search finder)',
            '- ALWAYS run check_product_discovery_setup first — for "set up discovery", "search is bad", and especially "search returns nothing". It reports every prerequisite with its own fix, and setup only works in one order.',
            '- "Search returns nothing at all" is usually NOT an empty catalog: a filter on a field the Typesense collection does not declare matches zero products instead of being ignored. The check reports exactly that, with the PATCH command. Reindexing does NOT add a field to a collection that already exists.',
            '- configure_product_discovery writes the settings only. It never indexes, enriches or creates the collection — those cost money and hours — so relay the commands it returns and say a human must run them, in order.',
            '- Pass catalog_language whenever shoppers do not type English. Term matching ships English only, so on a Spanish storefront "de lujo", "barato" and "para mi novia" all parse as nothing and both budget and recipient filtering are silently dead.',
            '- Pass catalog_type "gift" when the shopper buys for someone else. Changing it later invalidates every blurb and needs a full re-enrichment, so ask before assuming.',
            '- excluded_categories is only for things that are NEVER the answer — gift wrap, shipping, warranties. A gift card IS a gift; if it surfaced for the wrong person that is a recipient-filtering problem, not a reason to bury the product.',
            '- Bad results are usually blurb quality, not ranking. Ask for a blurb-coverage number from the check before proposing any tuning.',
        ]);
    }
}
