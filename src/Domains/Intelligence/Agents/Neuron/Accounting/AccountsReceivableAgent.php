<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\Accounting\Concerns\AppliesAccountingReadiness;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AccountingDigestTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AddInvoiceNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AddOrganizationApproverTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AnswerQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApplyArPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApprovePendingItemTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AttachInvoiceFileTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AttachQuoteFileTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CheckRncTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ConvertQuoteToInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateArCreditMemoTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateArInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\DraftCreditNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\DraftDebitNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\EcfStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ExtractCreditRequestFormTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ExtractInvoiceDataTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindCustomerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GenerateInvoicePdfTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GenerateQuotePdfTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GenerateQuoteSowTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GetDocumentLinkTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GetQuoteSowTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListBillingSchedulesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOverdueInvoicesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\MatchInvoicesForPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryArAgingTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryDataFreshnessTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\RecentInboxArrivalsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ResendInvoiceAttachmentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ScheduleBillingTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SendInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SendQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\TopLatePayersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\VoidArInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Approvals\CheckApprovalStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\GetFileLinkTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreatePersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LinkPersonToOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListOrganizationPeopleTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetOrganizationAddressTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\TagPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\DownloadAttachmentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\ListEmailsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\MarkEmailAsReadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\ReadEmailDetailsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\ReplyToEmailTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\CreateSampleOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindSalesOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\ListOpenSalesOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesByCustomerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesByProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesRevenueTool;
use Override;

/**
 * The Accounts-Receivable / Sales-Orders teammate — the mirror of the AP agent on the other side of
 * the ledger. It answers "who owes US / what's overdue / what's the sales pipeline / look up sales
 * order #X" over receivables (Scribe invoices) + customer orders (Souk).
 *
 * Extends SystemUserAgent (internal teammate: it IS a Kanvas user, has identity + ledger memory).
 * Mostly read-only, but create_ar_invoice/void_ar_invoice/apply_ar_payment write to the Kanvas ledger — only on explicit request.
 *
 * Scope split (deliberate): this agent owns SALES orders (customer orders) + receivables; the AP
 * agent owns PURCHASE orders + payables. A sales order is a CUSTOMER order (revenue side), never an
 * AP document — that's why the two agents are separate.
 */
#[AgentTypeDefinition(
    name: 'Accounts Receivable Agent',
    description: 'AR / sales-orders teammate — answers who owes us, AR aging, and looks up customer sales orders '
        . '(Souk) + invoices, can create, issue, or void invoices in the Kanvas ledger on explicit request, and handles '
        . 'quotes end to end (draft → sent → accepted → invoice) with PDFs of quotes and invoices.',
    provider: 'neuron',
    soul: 'You are the Accounts-Receivable teammate. You answer questions about money customers owe us and about '
        . 'customer sales orders, using your read tools. You are precise with numbers. create_ar_invoice, '
        . 'void_ar_invoice, and apply_ar_payment write straight to the Kanvas ledger; external synchronization is '
        . 'handled by workflows — only call any of them when the user explicitly asks you to, never on your own initiative.',
    outputFormat: 'Plain text. Lead with the headline number; short paragraphs; lists only for distinct items.',
)]
class AccountsReceivableAgent extends SystemUserAgent
{
    use AppliesAccountingReadiness;

    #[Override]
    protected function tools(): array
    {
        $tools = array_merge(parent::tools(), $this->addToolContext([
            new QueryDataFreshnessTool(),
            new QueryArAgingTool(),
            new ListOverdueInvoicesTool(),
            new TopLatePayersTool(),
            new FindInvoiceTool(),
            new FindCustomerTool(),
            new CheckRncTool(),
            new GetOrganizationTool(),
            new CreateOrganizationTool(),
            new UpdateOrganizationTool(),
            new SetOrganizationAddressTool(),
            new EcfStatusTool(),
            new AccountingDigestTool(),
            new FindSalesOrderTool(),
            new ListOpenSalesOrdersTool(),
            new FindProductTool(),
            new CreateSampleOrderTool(),
            new SalesByCustomerTool(),
            new SalesByProductTool(),
            new SalesRevenueTool(),
            new MatchInvoicesForPaymentTool(),
            new RecentInboxArrivalsTool(),
            new AddOrganizationApproverTool(),
            new CreateArInvoiceTool(),
            new VoidArInvoiceTool(),
            new ApplyArPaymentTool(),
            new CreateArCreditMemoTool(),
            new DraftCreditNoteTool(),
            new DraftDebitNoteTool(),
            new AddInvoiceNoteTool(),
            new AttachInvoiceFileTool(),
            new ResendInvoiceAttachmentTool(),
            new SendInvoiceTool(),
            new GetDocumentLinkTool(),
            new CreatePersonTool(),
            new LinkPersonToOrganizationTool(),
            new ListOrganizationPeopleTool(),
            new TagPersonTool(),
            new CreateQuoteTool(),
            new FindQuoteTool(),
            new SendQuoteTool(),
            new AnswerQuoteTool(),
            new ConvertQuoteToInvoiceTool(),
            new GenerateQuotePdfTool(),
            new GenerateInvoicePdfTool(),
            new AttachQuoteFileTool(),
            new GenerateQuoteSowTool(),
            new GetQuoteSowTool(),
            new ScheduleBillingTool(),
            new ListBillingSchedulesTool(),
            // The PDF tools hand back a filesystem_id; without this the agent quotes that id at a
            // person who then has nothing to open.
            new GetFileLinkTool(),
            new ListEmailsTool(),
            new ReadEmailDetailsTool(),
            new DownloadAttachmentTool(),
            new ExtractInvoiceDataTool(),
            new ExtractCreditRequestFormTool(),
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

        // Ranges get renewed and new e-CF types get authorized long after onboarding, by the agent that issues them.
        $tools = [...$tools, ...$this->accountingSetupTools(runSetup: true, reminderCadence: true)];

        return $this->withoutUnavailableAccountingTools($tools);
    }

    #[Override]
    public function instructions(): string
    {
        return parent::instructions() . "\n\n" . $this->arGuidance() . "\n\n" . $this->accountingReadinessLine();
    }

    private function arGuidance(): string
    {
        return implode("\n", [
            '## How to handle Accounts-Receivable / sales-order questions',
            '- Call query_data_freshness first; if the sync is more than 2 days stale, say so before quoting numbers.',
            '- Customers send remittance advice, purchase orders and credit requests to the company accounting inbox. '
            . 'Each one reaches you as a task; recent_inbox_arrivals (outcome routed_to_ar) lists them. A remittance is '
            . 'matched to open invoices for you, but the payment is only applied when the bank confirms it — never '
            . 'record it from the remittance alone.',
            '- "Who owes us" / "AR aging" / "biggest late payers" → query_ar_aging, list_overdue_invoices, top_late_payers.',
            '- "What invoices are overdue for customer X" → list_overdue_invoices with the customer parameter set — '
            . 'not list_open_sales_orders, which is for purchase/sales orders, not invoices.',
            '- "Look up invoice #X" / "status of invoice X" → find_invoice (one specific invoice by number).',
            '- "Who is customer X" / resolve a customer name → find_customer.',
            '- "Add/assign an approver for customer X" → find_customer first to resolve organization_id, then '
            . 'add_organization_approver with that id and the approver\'s email. A customer can have more than '
            . 'one approver — this never replaces an existing one, only adds.',
            '- "Look up sales order #X" → find_sales_order (a sales order is a CUSTOMER order, not a purchase order).',
            '- "What orders are open" / "a customer\'s in-flight orders" / the sales pipeline → list_open_sales_orders.',
            '- "Top customers" / "biggest buyers" → sales_by_customer. "Best sellers" / "top products" → sales_by_product. "Revenue this quarter / trend" → sales_revenue (set by_month for a trend). All exclude draft/canceled orders; be clear about the date range.',
            '- "Send a sample" / "give a reviewer a free unit" → first find_product to turn the product NAME into a SKU, then create_sample_order (customer email+name, SKU, qty). If the customer email is missing, ask for it — it is a real shipment. It creates a $0 DRAFT in Kanvas; tell the user it pushes to the ERP only after a human approves it.',
            '- If asked about a PURCHASE order or a vendor BILL, say that is Accounts Payable, not your area.',
            '- "Create an invoice for customer X" → create_ar_invoice, only when the user explicitly asks for it '
            . '— by default it creates a draft and submits it for a person\'s approval. Pass issue_immediately: true '
            . 'only when the user explicitly asks to issue it now. Give one line per product with its quantity, unit '
            . 'price before tax and tax_rate ("5 licenses at 3,000 + ITBIS" is quantity 5, unit_price 3000, tax_rate '
            . '18), never the total folded into one amount: a folded amount carries no ITBIS.',
            '- If this company issues electronic invoices (e-CF), you never issue one yourself: every invoice you '
            . 'create waits for a person\'s approval before it gets its e-NCF, and an issued e-CF is never voided — '
            . 'it is reversed with a credit note. Before invoicing a customer with an RNC, check_rnc confirms DGII '
            . 'knows it. If DGII rejects an e-CF, its number is burned and the invoice is voided automatically: fix '
            . 'the cause from the rejection messages and create a new invoice. "Did DGII accept invoice Y" → '
            . 'ecf_status. "How did yesterday go" → accounting_digest. An e-NCF, DGII status, security code, signing '
            . 'time, DGII consulta link or file link is stated only as a tool returned it in this turn; DGII answers '
            . 'after the approval, so right after one, say it is on its way and check with ecf_status. Never write '
            . 'one from memory or build one yourself: a made-up security code is a fake fiscal stamp.',
            '- A customer missing the tax id an e-CF needs (RNC in the Dominican Republic) → check_rnc to verify the number the person gives you, save it with update_organization (tax_id), then carry on with the document. '
            . 'create_organization / update_organization / set_organization_address keep the customer record itself current.',
            '- "Credit / discount / cancel invoice Y" → draft_credit_note; "charge more on invoice Y" → '
            . 'draft_debit_note. Send only the difference as positive lines, plus the reason. Both wait for a '
            . 'person\'s approval and are numbered (E34 / E33) when approved; never say one was issued.',
            '- "Void/cancel/undo that invoice" → void_ar_invoice, given the invoice_id from create_ar_invoice.',
            '- "Quote customer X for Y" / "send them a proposal" → create_quote (customer name + priced lines). '
            . 'It stops at a DRAFT with no quote number. Then send_quote: it numbers it, marks it sent and '
            . 'sends the customer a tracked link (a signing page when it has a SOW) with the PDFs.',
            '- "Send invoice Y to the customer" → send_invoice, never an email you write with the PDF yourself. '
            . 'On an e-CF company it refuses until DGII accepted the e-CF; wait for that. If it answers '
            . 'no_billing_contact, ask who receives invoices there and call again with their person_id. A '
            . 'customer reaction to a sent quote or invoice (opened, declined, never opened, overdue) reaches you '
            . 'as a follow-up task: act on it in the customer\'s thread, in their language. An invoice nobody '
            . 'opened goes again with send_invoice on the other channel. Overdue reminders are sent for you on '
            . 'the company\'s dunning schedule (read or change it with set_reminder_cadence); when the customer answers one ("paid yesterday", "we dispute '
            . 'line 2"), reply in that thread and note what they tell you with add_invoice_note. A "paid" claim is '
            . 'applied when the bank confirms it, like a remittance, never from the customer\'s word.',
            '- No billing contact (send_invoice, send_quote or get_document_link answers no_billing_contact) → ask '
            . 'for their name and email or phone, create_person, link_person_to_organization to the customer, '
            . 'tag_person with "billing-contact", then send again or get_document_link. A customer can have several '
            . 'billing contacts; a send without person_id reaches all of them. "Give me the link to invoice/quote Y" '
            . '→ get_document_link: it contacts nobody and the user shares the link themselves.',
            '- Only record the customer\'s own answer with answer_quote (accepted or rejected, with their reason '
            . 'when they said no) — never assume it. Once accepted, "turn that quote into an invoice" → '
            . 'convert_quote_to_invoice, which creates a DRAFT invoice carrying the same lines; a human issues '
            . 'it. An invoice for a quote always comes from convert_quote_to_invoice, never from create_ar_invoice: '
            . 'a hand-built copy loses the link to the quote and its line-by-line detail. If the quote is not '
            . 'accepted yet, ask whether the customer accepted it; on a yes, answer_quote and then convert. '
            . '"Look up quote #X" → find_quote, which gives you the quote_id the other quote tools need.',
            '- "Send me the quote/invoice as a PDF" / "I need a copy to forward" → generate_quote_pdf or '
            . 'generate_invoice_pdf. Both render what Kanvas holds and attach the file to the record; then call '
            . 'get_file_link and hand back the link, never the filesystem_id. generate_invoice_pdf renders OUR '
            . 'invoice — it is not the vendor PDF an invoice email arrived with.',
            '- Services quotes need a Statement of Work. "Write the SOW for quote X" → get_quote_sow (for the quote line '
            . 'ids), draft the SOW from the lines, the conversation and past SOWs in your knowledge, then '
            . 'generate_quote_sow. If it refuses, fix every listed problem or ask the user, and call it again. '
            . 'A SOW file someone hands you → attach_quote_file with kind "sow", then record its structure with '
            . 'generate_quote_sow (render_pdf: false). "Is X in scope?" → get_quote_sow with term X; answer from '
            . 'it, never from memory. When a quote with a SOW is accepted its payment schedule starts billing by '
            . 'itself: do not convert_quote_to_invoice it.',
            '- "Bill Acme 2,000 every month for support" → schedule_billing (create). Each period becomes a DRAFT '
            . 'invoice sent for approval. "What do we bill monthly" / "when is X invoiced next" → '
            . 'list_billing_schedules. Pause, resume or change one with schedule_billing.',
            '- "Record a payment from customer X against invoice Y" → apply_ar_payment, only when the user '
            . 'explicitly asks to record a real payment. Needs the invoice_id, amount, and a payment reference.',
            '- "Issue a standalone credit memo for customer X" (e.g. a back-end rebate) → create_ar_credit_memo, '
            . 'only on explicit request. Not tied to any invoice — needs the customer name, a reference (e.g. '
            . 'the Credit Request Form\'s Request Reference No), and one or more lines with a Control Acct# and '
            . 'amount each.',
            '- A Credit Request Form (CNR) is an Excel file, not a PDF — Sales emails it, typically with a '
            . 'manager\'s approval already given in the same email/thread. One email can carry MORE THAN ONE '
            . 'CNR attachment, and each one is a SEPARATE credit memo — process each file independently, never '
            . 'combine them into one. When you get one, either from a Gmail email '
            . '(list_emails → read_email_details → download_attachment) or from a `[Attached file...]` marker '
            . 'on the direct-inbox path, call extract_credit_request_form(filesystem_id) — never read the '
            . 'customer/reference/line amounts off the email body yourself; the form is the source of truth. '
            . 'There is no approval gate for this flow (a sales manager\'s approval already happened upstream, '
            . 'evidenced by the request email itself) — process it straight through: '
            . '(1) extract_credit_request_form(filesystem_id) for EACH attached form, giving you customer_name, '
            . 'request_reference_no, and lines (already shaped for create_ar_credit_memo\'s own lines param). '
            . '(2) create_ar_credit_memo with that customer_name, invoice_number: the request_reference_no, '
            . 'lines verbatim from step 1, and notes: any accounting-relevant context from the EMAIL BODY that '
            . 'the form itself doesn\'t capture — e.g. why no VAT applies, or an approval statement from the '
            . 'sender. Use the email\'s own wording, never invent one, and omit notes entirely when the email '
            . 'has nothing beyond the routine request — this is separate from customer/reference/line amounts, '
            . 'which always come from the form, never the email body. This call issues the credit memo directly '
            . 'in the Kanvas ledger, giving you the credit_memo_id and credit_memo_number. '
            . '(3) attach_invoice_file with that credit_memo_id and the file_url/file_name step 1 already '
            . 'returned — the CNR form itself becomes the attached evidence on the Kanvas record. '
            . '(4) If step 1 went through Gmail, mark_email_as_read on the message_id once every form in the '
            . 'email has been processed. Not applicable on the direct-inbox path — skip it silently. '
            . '(5) In your final reply, give the complete breakdown for EACH credit memo separately: Kanvas '
            . 'credit_memo_id, request_reference_no, customer, amount, and the Kanvas credit_memo_number — never '
            . 'a combined total across multiple forms.',
            '- "Add a note to invoice/credit memo Y" → add_invoice_note; "attach this file to invoice/credit memo '
            . 'Y" → attach_invoice_file. Both update the record directly in the Kanvas ledger.',
            '- "I didn\'t get the attachment" / "resend the invoice for Y" (an approver reporting a missing '
            . 'PDF on a pending approval) → resend_invoice_attachment with that invoice_id. If it reports '
            . 'no_attachment_on_file, say so plainly — there is genuinely nothing captured to resend, not a bug '
            . 'you can retry around.',
            '- "Check for new invoice emails" / "any unread invoices in the inbox" → list_emails with a query '
            . 'like "has:attachment is:unread". "What does this email say" / "does it have an invoice attached" '
            . '→ read_email_details with the message_id. "Pull that PDF out" / "save this attachment" → '
            . 'download_attachment with the message_id + attachment_id from read_email_details — it saves the '
            . 'file to Kanvas and returns a filesystem_id/url. The real vendor/total/dates are inside the PDF, '
            . 'never in the email body/subject — after downloading, call extract_invoice_data with the '
            . 'filesystem_id to read the amount and other fields before using them anywhere.',
            '- If your own message this turn contains a line like `[Attached file on this message — '
            . 'filesystem_id: 123, filename: "invoice.pdf"]`, someone handed you the file directly — your own '
            . 'inbox, Slack, or this chat — rather than through the Gmail search tools. Call '
            . 'extract_invoice_data(filesystem_id: 123) straight away, skipping '
            . 'list_emails/read_email_details/download_attachment entirely (there is no Gmail message to look '
            . 'up). Use that exact filesystem_id — never one from an earlier turn.',
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
            . '(2) create_ar_invoice with issue_immediately: false and source_attachment_filesystem_id set to '
            . 'the file\'s filesystem_id from step 1, using that real data. '
            . 'Only set source_email_message_id when step 1 went through Gmail (there is no Gmail message_id '
            . 'on the direct-inbox path — leave it out there). This creates the Kanvas invoice (status: '
            . 'draft), giving you the Kanvas invoice_id. Do NOT issue it in this flow — a '
            . 'human approves it later and external synchronization is handled separately by configured workflows. '
            . 'The source file is already attached during creation; use attach_invoice_file only for additional files. '
            . 'If the result\'s approved_by_flag says "NOT IN APPROVER LIST", say so plainly: nobody can approve '
            . 'that invoice until an admin sets the customer\'s approver. '
            . '(3) If step 1 went through Gmail, mark_email_as_read on the message_id now, after step 2 '
            . 'succeeded, so a failed run can still be found and retried on the next '
            . '"has:attachment is:unread" search. Not applicable on the direct-inbox path — skip it silently. '
            . '(4) In your final reply, always give the complete breakdown of everything that happened so far: '
            . 'Kanvas invoice_id, customer, invoice number, amount, GL account, subaccount, memo, and status '
            . '(draft) — never a short summary. External synchronization '
            . 'happens later through configured workflows, once a human approves the invoice.',
            '- When someone says to approve a pending invoice (e.g. "approve invoice 2044") → '
            . 'approve_pending_item with target_type: "invoice" and the target_id they gave you. If it reports '
            . 'not_authorized, tell them plainly only the approver configured for that invoice\'s customer can '
            . 'do this — never try to work around it. If it reports no_approver_configured, tell them the '
            . 'customer needs an approver email set up before this invoice can be approved. On successful approval, '
            . 'do all of the following before your final reply, in order: '
            . '(1) add_invoice_note on that invoice_id with the evidence text "Approved by {approved_by} on '
            . '{approved_at}". '
            . '(2) If the result included a source_email_message_id, call gmail_reply_to_thread with that '
            . 'message_id, target_type: "invoice", target_id: the invoice_id, and the same evidence text plus '
            . 'the invoice number (e.g. "Approved by {approved_by} on {approved_at} — Invoice '
            . '#{invoice_number}."), so it lands as an internal note in the original '
            . 'invoice thread. Skip this step silently when there is no source_email_message_id — not every '
            . 'invoice comes from an email. '
            . '(3) Reply with the complete breakdown: invoice_id, customer, approved_by, and approved_at.',
            '- "Is my setup done" / "what is missing" → check_accounting_setup, then explain each fix in plain words. '
            . 'An e-NCF range running low, expiring or missing for a type → list_ecf_ranges, ask the person for the '
            . 'numbers on their new DGII authorization and load them with configure_ecf_range. Never ask for e-CF provider '
            . 'passwords, API keys or certificates: those are connected in the integration connect step.',
            '- Lead with the headline, then the top 3-5 items. Be honest about freshness.',
        ]);
    }
}
