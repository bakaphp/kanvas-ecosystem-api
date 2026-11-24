<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Illuminate\Support\Carbon;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpOutcome;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpFailureReasonEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpOutcomeKindEnum;
use Kanvas\Intelligence\FollowUp\Services\InvoiceDunningComposer;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpEligibilityService;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Scribe\Delivery\Actions\SendDocumentEngagementAction;
use Kanvas\Scribe\Delivery\DataTransferObject\DocumentDelivery;
use Kanvas\Scribe\Delivery\DataTransferObject\DunningSchedule;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Delivery\Enums\DocumentSignalEnum;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\Delivery\Services\DocumentEngagementSignalService;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Collections on the follow-up engine (plan 3.1): the Invoice executor beside FollowUpLeadAction.
 *
 * The clock is the due date, not a stage interval: the company's DunningSchedule names the day
 * offsets, the step last sent is the touch's `template` in follow_up_state (`dunning:+7`), and the
 * agent writes each reminder from the thread. Every reminder goes through the invoice's open
 * `view-invoice` engagement, so the customer always gets the link they already have and the agent
 * reads their reply in the same thread. After the last step the account owner is brought in once,
 * and that is the only terminal state besides paid.
 */
class FollowUpInvoiceAction
{
    private ?string $lastMessage = null;

    public function __construct(
        private readonly Invoice $invoice,
        private readonly ?Agent $agent,
        private readonly bool $force = false,
        private readonly LeadFollowUpEligibilityService $replies = new LeadFollowUpEligibilityService(),
        private readonly DocumentEngagementSignalService $signals = new DocumentEngagementSignalService(),
    ) {
    }

    public function execute(): FollowUpOutcome
    {
        /** @var Companies $company */
        $company = $this->invoice->company;
        $schedule = DunningSchedule::forCompany($company);

        if (! $schedule->enabled) {
            return $this->skip('dunning_disabled');
        }

        if ($this->invoice->isPaid() || $this->invoice->document_status === InvoiceDocumentStatusEnum::VOIDED) {
            return FollowUpOutcome::completed();
        }

        if ($this->invoice->document_status !== InvoiceDocumentStatusEnum::SENT) {
            return $this->skip('invoice_not_sent');
        }

        if ((float) $this->invoice->balance_due_native <= 0.0 || $this->invoice->due_date === null) {
            return $this->skip('nothing_due');
        }

        if ($this->invoice->isFollowUpExhausted()) {
            return FollowUpOutcome::exhausted((string) $this->invoice->getFollowUpExhaustedReason());
        }

        $threads = $this->openThreads();
        if ($threads === []) {
            return $this->skip('no_engagement');
        }

        $daysPastDue = (int) $this->invoice->due_date->copy()->startOfDay()->diffInDays(Carbon::today(), false);
        $lastStep = DunningSchedule::stepFromLabel($this->invoice->getFollowUpLastTemplate());

        if ($lastStep !== null && $schedule->isLastStep($lastStep) && $daysPastDue >= $schedule->escalateAfterDays) {
            return $this->escalate($daysPastDue);
        }

        $step = $schedule->dueStep($daysPastDue, $lastStep);
        if ($step === null) {
            return $this->skip('not_due');
        }

        if (! $this->force && $this->customerRepliedRecently($threads, $schedule)) {
            return $this->skip('customer_replied');
        }

        if ($this->agent === null) {
            return $this->skip('no_ar_agent');
        }

        return $this->sendReminders($threads, $step);
    }

    /**
     * Every billing contact who holds the invoice gets the reminder in their own thread; the step is
     * spent once, when at least one of them got it, so a failure for one never resends to the others.
     *
     * @param list<Engagement> $threads
     */
    private function sendReminders(array $threads, int $step): FollowUpOutcome
    {
        $sent = [];
        $notSent = [];

        foreach ($threads as $thread) {
            $outcome = $this->sendReminder($thread, $step);
            if ($outcome instanceof DocumentDelivery) {
                $sent[] = $outcome;
            } else {
                $notSent[] = $outcome;
            }
        }

        if ($sent === []) {
            foreach ($notSent as $outcome) {
                if ($outcome->kind === FollowUpOutcomeKindEnum::ERROR) {
                    return $outcome;
                }
            }

            return $notSent[0];
        }

        $channels = array_values(array_unique(array_map(
            static fn (DocumentDelivery $delivery): string => $delivery->target->channel->value,
            $sent,
        )));
        $this->invoice->bumpFollowUp($channels, DunningSchedule::stepLabel($step));

        return FollowUpOutcome::sent((string) $this->lastMessage);
    }

    private function sendReminder(Engagement $thread, int $step): DocumentDelivery|FollowUpOutcome
    {
        /** @var People $people */
        $people = $thread->people;

        try {
            $result = app(InvoiceDunningComposer::class)->compose(
                $this->invoice,
                $people,
                $this->agent,
                $step,
                $this->openedAt($thread)
            );
        } catch (Throwable $e) {
            report($e);

            return $this->skip(FollowUpFailureReasonEnum::AGENT_CALL_FAILED->value . ': ' . $e->getMessage());
        }

        if (! $result->shouldRespond || $result->message === null) {
            return $this->skip('agent: ' . ($result->reason ?? 'declined'));
        }

        try {
            $delivery = new SendDocumentEngagementAction(
                document: $this->invoice,
                people: $people,
                user: $this->actor($thread),
                action: DocumentActionEnum::VIEW_INVOICE,
                agent: $this->agent,
                body: $result->message,
                subject: 'Reminder: invoice ' . $this->invoice->invoice_number . ' from ' . $this->invoice->company->name,
            )->execute();
        } catch (DocumentDeliveryException $e) {
            return $this->skip(FollowUpFailureReasonEnum::NO_REACHABLE_CHANNEL->value . ': ' . $e->reason);
        } catch (Throwable $e) {
            report($e);
            $this->invoice->emitLedgerEvent('scribe.invoice.follow_up.send_failed', EventStatusEnum::ERROR, [
                'step' => $step,
                'reason' => FollowUpFailureReasonEnum::SEND_FAILED->value,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return FollowUpOutcome::error(FollowUpFailureReasonEnum::SEND_FAILED->value);
        }

        $this->lastMessage = $result->message;

        $this->invoice->emitLedgerEvent('scribe.invoice.follow_up.sent', payload: [
            'step' => $step,
            'channel' => $delivery->target->channel->value,
            'engagement_uuid' => $delivery->engagement->uuid,
            'message_id' => $delivery->message->getId(),
            'people_id' => $people->getId(),
        ]);

        return $delivery;
    }

    private function escalate(int $daysPastDue): FollowUpOutcome
    {
        $owner = $this->accountOwner();
        $ownerLabel = $owner !== null
            ? trim($owner->firstname . ' ' . $owner->lastname) . ' <' . $owner->email . '>'
            : 'the company owner';

        $this->signals->raise(
            $this->invoice,
            DocumentSignalEnum::DUNNING_ESCALATED,
            "{$ownerLabel} to step in on invoice {$this->invoice->invoice_number} ({$this->invoice->billable_display_name}, "
            . "{$this->invoice->currency} " . number_format((float) $this->invoice->balance_due_native, 2)
            . ", {$daysPastDue} days past due, every reminder sent): tell them with send_email_to_user and what the "
            . 'customer has said so far'
        );

        $this->invoice->markFollowUpExhausted('escalated');
        $this->invoice->emitLedgerEvent('scribe.invoice.follow_up.escalated', payload: [
            'days_past_due' => $daysPastDue,
            'account_owner_users_id' => $owner?->getId(),
        ]);

        return FollowUpOutcome::exhausted('escalated');
    }

    /**
     * The customer's account owner on the organization, else whoever owns the company.
     */
    private function accountOwner(): ?Users
    {
        $owner = $this->invoice->customer?->user;
        if ($owner instanceof Users) {
            return $owner;
        }

        $companyOwner = $this->invoice->company->user;

        return $companyOwner instanceof Users ? $companyOwner : null;
    }

    /**
     * A customer who wrote back is in a conversation with the agent; the next reminder waits until that
     * has been quiet for `reply_pause_days`, so "paid yesterday" is not followed by a dunning email.
     *
     * @param list<Engagement> $threads
     */
    private function customerRepliedRecently(array $threads, DunningSchedule $schedule): bool
    {
        foreach ($threads as $thread) {
            $lastInbound = $this->replies->getLastInboundAtForPerson(
                $thread->people,
                (int) $this->invoice->apps_id,
                (int) $this->invoice->companies_id
            );

            if ($lastInbound !== null && $lastInbound->greaterThan(Carbon::now()->subDays($schedule->replyPauseDays))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The latest view-invoice engagement of each person the invoice was shared with.
     *
     * @return list<Engagement>
     */
    private function openThreads(): array
    {
        return Engagement::query()
            ->sharing($this->invoice, DocumentActionEnum::VIEW_INVOICE->value)
            ->with('people')
            ->latest('id')
            ->get()
            ->unique('people_id')
            ->filter(fn (Engagement $engagement): bool => $engagement->people !== null)
            ->values()
            ->all();
    }

    private function openedAt(Engagement $thread): ?Carbon
    {
        /** @var Engagement|null $opened */
        $opened = Engagement::query()
            ->inThreadOf($thread)
            ->atStage(ActionStatusEnum::OPEN)
            ->oldest('id')
            ->first();

        return $opened?->created_at !== null ? Carbon::parse($opened->created_at) : null;
    }

    /**
     * The user who shared the invoice: the open engagement is reused only for the same sharer, and a
     * reminder must carry the link the customer already has.
     */
    private function actor(Engagement $thread): Users
    {
        $user = $thread->user ?? $this->agent?->user;

        return $user instanceof Users ? $user : $this->invoice->company->getAiAgentUserOrFail();
    }

    private function skip(string $reason): FollowUpOutcome
    {
        $this->invoice->emitLedgerEvent('scribe.invoice.follow_up.skipped', payload: ['reason' => $reason]);

        return FollowUpOutcome::skipped($reason);
    }
}
