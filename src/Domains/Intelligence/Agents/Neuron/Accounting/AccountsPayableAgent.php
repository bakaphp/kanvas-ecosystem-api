<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindBillTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindPurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindVendorTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOpenBillsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOpenPurchaseOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\MatchBillsForPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryApAgingTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryDataFreshnessTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\ApplyApPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\CreateApBillTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\VoidApBillTool;
use Override;

/**
 * The Accounts-Payable teammate — a system-user agent (it IS a Kanvas user, has its own identity +
 * ledger memory, is @mention-reachable) specialised for AP. It answers "what do we owe / to whom /
 * what's on order / who is this vendor" over the ERP data synced into Scribe.
 *
 * Extends SystemUserAgent (not bare BaseKanvasAgent) so it inherits the internal-teammate mechanics:
 * self-identity grounding, cross-entity ledger recall (read_my_ledger / who_is_user), entity-aware
 * chat history, and write attribution to its OWN user (actingUser). On top of that core it adds the
 * AP read tools below.
 *
 * Mostly read-only, but create_ap_bill/void_ap_bill/apply_ap_payment write for real, bypassing human approval — only on explicit request.
 */
#[AgentTypeDefinition(
    name: 'Accounts Payable Agent',
    description: 'AP teammate — answers what the company owes using synced ERP data (open bills, AP aging, '
        . 'open purchase orders, vendors), and can create+push or void a bill on explicit request.',
    provider: 'neuron',
    soul: 'You are the Accounts-Payable teammate. You answer questions about what the company owes its '
        . 'vendors using your read tools. You are accountable and precise with numbers. create_ap_bill, '
        . 'void_ap_bill, and apply_ap_payment bypass the normal human-approval path and write straight to '
        . 'whichever Acumatica tenant is configured — only call any of them when the user explicitly asks you '
        . 'to, never on your own initiative.',
    outputFormat: 'Plain text. Lead with the headline number; short paragraphs; lists only for distinct items.',
)]
class AccountsPayableAgent extends SystemUserAgent
{
    #[Override]
    protected function tools(): array
    {
        return array_merge(parent::tools(), $this->addToolContext([
            new QueryDataFreshnessTool(),
            new QueryApAgingTool(),
            new ListOpenBillsTool(),
            new ListOpenPurchaseOrdersTool(),
            new FindPurchaseOrderTool(),
            new FindBillTool(),
            new FindVendorTool(),
            new MatchBillsForPaymentTool(),
            new CreateApBillTool(),
            new VoidApBillTool(),
            new ApplyApPaymentTool(),
        ]));
    }

    #[Override]
    public function instructions(): string
    {
        return parent::instructions() . "\n\n" . $this->apGuidance();
    }

    private function apGuidance(): string
    {
        return implode("\n", [
            '## How to handle Accounts-Payable questions',
            '- Call query_data_freshness first; if the sync is more than 2 days stale, say so before quoting numbers.',
            '- "What do we owe" / "how much is overdue to vendors" → query_ap_aging.',
            '- "Which bills are outstanding" / "what\'s due soon" / a vendor\'s unpaid bills → list_open_bills '
            . '(set only_overdue for past-due focus).',
            '- "What has vendor X got on order" / matching an invoice to a PO → list_open_purchase_orders.',
            '- Resolving a vendor name off an invoice → find_vendor; if more than one candidate, confirm which.',
            '- Lead with the headline (e.g. "Total payables: $84,200 across 12 vendors; $19,500 overdue"), then '
            . 'the top 3-5 items. Be honest about freshness; never invent precision the data lacks.',
            '- "Create a bill for vendor X" → create_ap_bill, only when the user explicitly asks for it — it '
            . 'writes straight to Acumatica, bypassing human approval.',
            '- "Void/cancel/undo that bill" → void_ap_bill, given the bill_id from create_ap_bill.',
            '- "Pay a vendor bill" / "record a payment against bill Y" → apply_ap_payment, only when the user '
            . 'explicitly asks to record a real payment. Needs the bill_id, amount, and a payment reference.',
        ]);
    }
}
