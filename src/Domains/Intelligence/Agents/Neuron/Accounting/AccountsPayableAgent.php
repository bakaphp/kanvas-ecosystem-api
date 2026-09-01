<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AddOrganizationApproverTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApprovePendingItemTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ExtractInvoiceDataTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindBillTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindPurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindVendorTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOpenBillsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOpenPurchaseOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\MatchBillsForPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryApAgingTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryDataFreshnessTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\AddBillNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\ApplyApPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\AttachBillFileTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\CreateApBillTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\VoidApBillTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Approvals\CheckApprovalStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\DownloadAttachmentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\ListEmailsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\MarkEmailAsReadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\ReadEmailDetailsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\ReplyToEmailTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\AppendGoogleSheetRowsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\ClearGoogleSheetRangeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\CreateGoogleSheetTabTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\ReadGoogleSheetTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\UpdateGoogleSheetCellTool;
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
        $tools = array_merge(parent::tools(), $this->addToolContext([
            new QueryDataFreshnessTool(),
            new QueryApAgingTool(),
            new ListOpenBillsTool(),
            new ListOpenPurchaseOrdersTool(),
            new FindPurchaseOrderTool(),
            new FindBillTool(),
            new FindVendorTool(),
            new MatchBillsForPaymentTool(),
            new AddOrganizationApproverTool(),
            new CreateApBillTool(),
            new VoidApBillTool(),
            new ApplyApPaymentTool(),
            new AddBillNoteTool(),
            new AttachBillFileTool(),
            new ReadGoogleSheetTool(),
            new AppendGoogleSheetRowsTool(),
            new UpdateGoogleSheetCellTool(),
            new ClearGoogleSheetRangeTool(),
            new CreateGoogleSheetTabTool(),
            new ListEmailsTool(),
            new ReadEmailDetailsTool(),
            new DownloadAttachmentTool(),
            new ExtractInvoiceDataTool(),
            new MarkEmailAsReadTool(),
            new ReplyToEmailTool(),
        ]));

        // Read-only, so unlike approve_pending_item it is not gated on a requesting human: reporting
        // where something stands authorizes nothing.
        $statusUser = $this->requestingHuman() ?? $this->actingUser();
        if ($statusUser !== null && $this->app !== null && $this->company !== null) {
            $tools[] = new CheckApprovalStatusTool()->withContext($this->app, $this->company, $statusUser);
        }

        // approve_pending_item must authorize against the real human, not actingUser() (the agent itself on @mention/channel surfaces).
        $requestingHuman = $this->requestingHuman();
        if ($requestingHuman !== null && $this->app !== null && $this->company !== null) {
            $tools[] = new ApprovePendingItemTool()->withContext($this->app, $this->company, $requestingHuman);
        }

        return $tools;
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
            '- "Add/assign an approver for vendor X" → find_vendor first to resolve organization_id, then '
            . 'add_organization_approver with that id and the approver\'s email. A vendor can have more than '
            . 'one approver — this never replaces an existing one, only adds.',
            '- Lead with the headline (e.g. "Total payables: $84,200 across 12 vendors; $19,500 overdue"), then '
            . 'the top 3-5 items. Be honest about freshness; never invent precision the data lacks.',
            '- "Create a bill for vendor X" → create_ap_bill, only when the user explicitly asks for it — by '
            . 'default it writes straight to Acumatica, bypassing human approval. Pass push_to_acumatica: false '
            . 'only when you specifically want it to stop at pending_approval instead (e.g. the automatic '
            . 'invoice-email flow below).',
            '- "Void/cancel/undo that bill" → void_ap_bill, given the bill_id from create_ap_bill.',
            '- "Pay a vendor bill" / "record a payment against bill Y" → apply_ap_payment, only when the user '
            . 'explicitly asks to record a real payment. Needs the bill_id, amount, and a payment reference.',
            '- "Add a note to bill Y" → add_bill_note; "attach this file to bill Y" → attach_bill_file. Both '
            . 'require the bill to already be pushed to Acumatica.',
            '- "Read/check this Google Sheet" → read_google_sheet, given the URL the user shared. "Add these '
            . 'rows to the sheet" → write_google_sheet. "Mark that row as X in the sheet" → '
            . 'update_google_sheet_cell, only after confirming the exact cell with read_google_sheet first — '
            . 'never guess a row/column. "Clear/wipe that row/cell" → clear_google_sheet_range — this empties '
            . 'the values but never removes the row itself. "Create a new tab called X" → create_google_sheet_tab.',
            '- "Check for new invoice emails" / "any unread bills in the inbox" → list_emails with a query like '
            . '"has:attachment is:unread". "What does this email say" / "does it have an invoice attached" → '
            . 'read_email_details with the message_id. "Pull that PDF out" / "save this attachment" → '
            . 'download_attachment with the message_id + attachment_id from read_email_details — it saves the '
            . 'file to Kanvas and returns a filesystem_id/url. The real vendor/total/dates are inside the PDF, '
            . 'never in the email body/subject — after downloading, call extract_invoice_data with the '
            . 'filesystem_id to read the amount and other fields before writing them anywhere (e.g. a sheet).',
            '- If your own message this turn contains a line like `[Attached file on this message — '
            . 'filesystem_id: 123, filename: "invoice.pdf"]`, an invoice arrived directly to your own inbox '
            . '(not via the Gmail search tools) — call extract_invoice_data(filesystem_id: 123) straight away, '
            . 'skipping list_emails/read_email_details/download_attachment entirely (there is no Gmail message '
            . 'to look up). Use that exact filesystem_id — never one from an earlier turn.',
            '- **Never reuse a `[Attachment: ...]` description from your own chat history as if it were '
            . 'attached to the CURRENT message.** That marker is a saved summary of a file from a *previous* '
            . 'turn — a different invoice, even if this email looks similar (same sender, same boilerplate '
            . 'wording, no subject). Only ever act on a filesystem_id explicitly given to you in the current '
            . 'turn. If the current message has no attachment marker and no Gmail email to check, say plainly '
            . 'that no attachment was provided this time — never fill the gap with a memory of an older one.',
            '- When you process an invoice email end-to-end, follow this exact order every time, without being '
            . 'asked — this is a standard step of processing an invoice email, not a separate favor: '
            . '(1) Get the real vendor/total/dates and the file\'s identifier — either list_emails → '
            . 'read_email_details → download_attachment → extract_invoice_data (Gmail), or, if this message '
            . 'already carries a `[Attached file...]` marker, extract_invoice_data(filesystem_id) directly. '
            . '(2) create_ap_bill with push_to_acumatica: false and source_attachment_url/'
            . 'source_attachment_filename set to the file\'s url/filename from step 1, using that real data. '
            . 'Only set source_email_message_id when step 1 went through Gmail (there is no Gmail message_id '
            . 'on the direct-inbox path — leave it out there). This creates the Kanvas bill and submits it for '
            . 'approval (status: pending_approval), giving you the Kanvas bill_id. Do NOT push to Acumatica in '
            . 'this flow — a human approves the bill later and the push happens as a separate, later step, not '
            . 'something you do here. Skip attach_bill_file too — it requires the bill to already be pushed to '
            . 'Acumatica, which hasn\'t happened yet; the file gets attached automatically at approval time '
            . 'instead. '
            . '(3) write_google_sheet to log the row — range "Invoices!A1", omit sheet_url_or_id to use the '
            . 'default sheet — with the ID invoice column set to the Kanvas bill_id from step 2 (NOT the '
            . 'vendor\'s own invoice number), then [vendor_name, total, "Pending", "", "", '
            . 'create_ap_bill\'s own approved_by_flag value verbatim] — the two empty strings are the Approved '
            . 'Date/By columns, left blank for a real approval; approved_by_flag already carries "NOT IN '
            . 'APPROVER LIST" when there is no approver configured, so this makes that visible directly in the '
            . 'sheet instead of only in your chat reply. Never compose that text yourself — copy the tool\'s '
            . 'own value. '
            . '(4) If step 1 went through Gmail, mark_email_as_read on the message_id now, after both steps '
            . 'above succeeded, so a failed run can still be found and retried on the next '
            . '"has:attachment is:unread" search. Not applicable on the direct-inbox path — skip it silently. '
            . '(5) In your final reply, always give the complete breakdown of everything that happened so far: '
            . 'Kanvas bill_id, vendor, invoice number, amount, GL account, subaccount, memo, and status '
            . '(pending_approval in Kanvas / "Pending" in the sheet) — never a short summary. There is no '
            . 'Acumatica reference yet at this stage — say so plainly rather than leaving it out; the push to '
            . 'Acumatica happens later, once a human approves the bill.',
            '- When someone says to approve a pending bill (e.g. "approve bill 1072") → approve_pending_item '
            . 'with target_type: "bill" and the target_id they gave you. If it reports not_authorized, tell '
            . 'them plainly only the approver configured for that bill\'s vendor can do this — never try to '
            . 'work around it. If it reports no_approver_configured, tell them the vendor needs an approver '
            . 'email set up before this bill can be approved. On success with pushed: true, do all of the '
            . 'following before your final reply, in order: '
            . '(1) add_bill_note on that bill_id with the evidence text "Approved by {approved_by} on '
            . '{approved_at}". '
            . '(2) If the result included a source_attachment_url, call attach_bill_file with that bill_id, '
            . 'file_url, and file_name — the invoice PDF can only be attached now that the bill is actually '
            . 'pushed to Acumatica. Skip this step silently when there is no source_attachment_url. '
            . '(3) If the result included a source_email_message_id, call reply_to_email with that '
            . 'message_id, target_type: "bill", target_id: the bill_id, and the same evidence text plus the '
            . 'bill reference (e.g. "Approved by {approved_by} on {approved_at} — Bill #{bill_number}, '
            . 'Acumatica ref {reference}."), so it lands as an internal note in the original invoice thread. '
            . 'Skip this step silently when there is no source_email_message_id — not every bill comes from '
            . 'an email. '
            . '(4) read_google_sheet to find the row whose column A (ID invoice) matches this bill_id — never '
            . 'guess the row. Then update_google_sheet_cell three times on that row: column D (Status) to '
            . '"Approved", column E (Approved Date) to approved_at, and column F (Approved By) to approved_by '
            . '(the approver\'s email). '
            . '(5) Reply with the complete breakdown: bill_id, vendor, approved_by, approved_at, and the new '
            . 'Acumatica reference.',
        ]);
    }
}
