<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Scribe\Delivery\Actions\SendDocumentEngagementAction;
use Kanvas\Scribe\Delivery\DataTransferObject\DocumentDelivery;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Delivery\Support\DocumentShareRule;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Services\InvoiceStateMachineService;
use Kanvas\Users\Models\Users;

/**
 * The one path an invoice reaches a customer (decision 7): a `view-invoice` engagement for the billing
 * contact, its link by email (legal PDF attached) or WhatsApp, then SENT. Never the provider's own
 * email, which nothing could track.
 *
 * A SENT invoice can be sent again (another channel, a lost email); the open engagement is reused,
 * so the customer keeps one link.
 */
class SendInvoiceAction
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly People $people,
        private readonly Users $user,
        private readonly ?LeadCommunicationChannelEnum $channel = null,
        private readonly ?Agent $agent = null,
        private readonly ?string $body = null,
        private readonly InvoiceStateMachineService $stateMachine = new InvoiceStateMachineService(),
        private readonly EcfIssuanceService $ecf = new EcfIssuanceService(),
    ) {
    }

    public function execute(): DocumentDelivery
    {
        DocumentShareRule::assertInvoiceShareable($this->invoice, $this->ecf);

        $delivery = new SendDocumentEngagementAction(
            document: $this->invoice,
            people: $this->people,
            user: $this->user,
            action: DocumentActionEnum::VIEW_INVOICE,
            channel: $this->channel,
            agent: $this->agent,
            body: $this->body,
        )->execute();

        $this->markSent();

        $this->invoice->emitLedgerEvent('scribe.invoice.sent', payload: [
            'invoice_number' => $this->invoice->invoice_number,
            'engagement_uuid' => $delivery->engagement->uuid,
            'channel' => $delivery->target->channel->value,
            'people_id' => $this->people->getId(),
        ]);

        return $delivery;
    }

    private function markSent(): void
    {
        DB::connection('accounting')->transaction(function (): void {
            if ($this->invoice->document_status !== InvoiceDocumentStatusEnum::SENT) {
                $this->stateMachine->assertTransition($this->invoice, InvoiceDocumentStatusEnum::SENT);
                $this->invoice->document_status = InvoiceDocumentStatusEnum::SENT;
            }

            $this->invoice->sent_at ??= Carbon::now();
            $this->invoice->delivery_status = 'sent';
            $this->invoice->delivery_last_attempt_at = Carbon::now();
            $this->invoice->save();
        });
    }
}
