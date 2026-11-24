<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Services;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\FollowUp\DataTransferObject\AgentFollowUpResult;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Social\Messages\Models\Message;

/**
 * Has the AR agent write one collections reminder. The prompt carries the invoice, where the step
 * sits against the due date, whether the customer opened the link (an opened, unpaid invoice gets a
 * different tone than one nobody looked at) and the last messages exchanged with the person, so the
 * reminder is written in the language and register of that thread rather than a template's.
 *
 * Resolved from the container so a test can bind a scripted composer instead of a model.
 */
class InvoiceDunningComposer
{
    private const int THREAD_EXCERPT_MESSAGES = 8;

    public function compose(
        Invoice $invoice,
        People $people,
        Agent $agent,
        int $step,
        ?Carbon $openedAt
    ): AgentFollowUpResult {
        $session = $this->latestSession($people, $invoice);

        $raw = new AgentChatKernel(
            agent: $agent,
            session: $session,
            message: $this->prompt(
                $invoice,
                $people,
                $step,
                $openedAt
            ),
            user: $agent->user ?? $invoice->company->getAiAgentUserOrFail(),
            sourceChannel: $session?->channel,
            persistConversation: false,
            privateUserTurn: true,
            fallbackOnFailure: false,
        )->execute();

        return AgentFollowUpResult::fromKernelResponse($raw);
    }

    private function prompt(
        Invoice $invoice,
        People $people,
        int $step,
        ?Carbon $openedAt
    ): string {
        $due = $invoice->due_date;
        $timing = match (true) {
            $step < 0 => 'due in ' . abs($step) . ' days',
            $step === 0 => 'due today',
            default => $step . ' days past due',
        };
        $opened = $openedAt !== null
            ? 'The customer opened the invoice link on ' . $openedAt->toDateString() . ' and has not paid.'
            : 'The customer has not opened the invoice link yet.';

        return implode("\n", [
            'Write one payment reminder for this invoice, as the company, to ' . $people->getDisplayName() . '.',
            '',
            'Invoice: ' . $invoice->invoice_number,
            'Customer: ' . ($invoice->billable_display_name ?? ''),
            'Balance due: ' . $invoice->currency . ' ' . number_format((float) $invoice->balance_due_native, 2),
            'Due date: ' . ($due?->toDateString() ?? 'none') . ' (' . $timing . ')',
            $opened,
            '',
            'Recent messages with this person (oldest first):',
            $this->threadExcerpt($people, $invoice),
            '',
            'Rules: write in the language the person uses in these messages (the company\'s language if there are '
            . 'none). Before the due date be a friendly heads-up; after it be clear about the amount and the date '
            . 'without threats. If they opened it, acknowledge they have seen it. If the messages show they already '
            . 'paid, dispute the invoice or promised a date, do not send a reminder. Do not include the link, a '
            . 'greeting header image or a signature; the link is added for you.',
            'Answer only with JSON: {"should_respond": true|false, "advance_stage": false, "message": "...", '
            . '"reason": "..."}.',
        ]);
    }

    private function threadExcerpt(People $people, Invoice $invoice): string
    {
        $channelIds = new LeadFollowUpEligibilityService()->personChannelIds(
            $people->getId(),
            (int) $invoice->apps_id,
            (int) $invoice->companies_id
        );

        if ($channelIds->isEmpty()) {
            return '(none)';
        }

        $lines = Message::query()
            ->where('is_deleted', 0)
            ->whereHas('channels', fn ($query) => $query->whereIn('channels.id', $channelIds))
            ->latest('id')
            ->limit(self::THREAD_EXCERPT_MESSAGES)
            ->get()
            ->reverse()
            ->map(function (Message $message): string {
                $payload = $message->getMessage();
                $who = ($payload['from_me'] ?? true) ? 'Us' : 'Them';

                return $who . ': ' . mb_substr(trim((string) ($payload['content'] ?? $payload['text'] ?? '')), 0, 400);
            })
            ->filter(fn (string $line): bool => ! str_ends_with($line, ': '))
            ->values()
            ->all();

        return $lines !== [] ? implode("\n", $lines) : '(none)';
    }

    private function latestSession(People $people, Invoice $invoice): ?Session
    {
        /** @var Session|null $session */
        $session = Session::query()
            ->where('is_deleted', 0)
            ->where('apps_id', $invoice->apps_id)
            ->where('companies_id', $invoice->companies_id)
            ->where('entity_namespace', People::class)
            ->where('entity_id', $people->getId())
            ->latest('id')
            ->first();

        return $session;
    }
}
