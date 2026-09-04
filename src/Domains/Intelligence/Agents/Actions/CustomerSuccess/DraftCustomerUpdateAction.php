<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\CustomerSuccess;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Actions\Chat\RunNeuronChatAction;
use Kanvas\Intelligence\Agents\DataTransferObject\CustomerUpdateDraft;
use Kanvas\Intelligence\Agents\DataTransferObject\CustomerUpdateResult;
use Kanvas\Intelligence\Agents\Enums\CustomerUpdateSkipEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Contracts\BehavesAsKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\CustomerSuccess\CustomerUpdateAgent;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\KanvasReleaseFeedService;
use Kanvas\Social\Messages\Models\Message;

/**
 * Produces this month's draft for one account. Drafts only — nothing here sends, and nothing here
 * writes to the account. The caller decides what to do with what comes back.
 */
class DraftCustomerUpdateAction
{
    public const string WATERMARK_FIELD = 'newsletter_last_release_at';

    /**
     * A monthly newsletter covers a month. The window is the period, NOT the gap since the last send —
     * otherwise a skipped month vanishes, and a second run in the same month sees almost nothing.
     * Repetition is handled where it belongs: the previous update is in the notes thread and the agent
     * is told not to repeat it.
     */
    private const int WINDOW_DAYS = 30;

    private const int NOTES_WINDOW = 20;
    private const int NOTE_CHARS = 600;
    private const int CONTACT_WINDOW = 6;
    private const int LEAD_WINDOW = 5;

    /**
     * @param mixed $handlerOverride a pre-built chat handler, so a test can drive the action without a
     *                               live provider. `RunNeuronChatAction::$handler` is itself `mixed`,
     *                               so this matches what it will be handed. Null in production.
     */
    public function __construct(
        private readonly Organization $organization,
        private readonly Agent $agent,
        private readonly mixed $handlerOverride = null,
    ) {
    }

    public function execute(): CustomerUpdateResult
    {
        $lastWrittenTo = $this->watermark();
        $windowOpensAt = now()->subDays(self::WINDOW_DAYS);

        $releases = new KanvasReleaseFeedService($this->organization->app)->publishedSince($windowOpensAt);

        // Nothing shipped in the window: skip the LLM turn entirely rather than paying for it to tell
        // us there is nothing to say.
        if ($releases === []) {
            return CustomerUpdateResult::skipped(CustomerUpdateSkipEnum::NO_RELEASES, 0);
        }

        $body = new RunNeuronChatAction(
            agent: $this->agent,
            session: null,
            message: $this->brief($lastWrittenTo, $windowOpensAt),
            app: $this->organization->app,
            user: $this->agent->user,
            handler: $this->handlerOverride ?? $this->handler(),
            media: [],
            // MUST stay false. The default returns a friendly apology string when a turn fails, and this
            // caller's output is emailed to a paying customer — a newsroom already published one of
            // those apologies as an article (KANVAS-ECOSYSTEM-691). A pipeline wants the exception.
            fallbackOnFailure: false,
        )->execute();

        $body = Str::trimToNull($body);

        if ($body === null || str_contains($body, CustomerUpdateAgent::NOTHING_TO_SEND)) {
            return CustomerUpdateResult::skipped(CustomerUpdateSkipEnum::AGENT_DECLINED, count($releases));
        }

        [$subject, $body] = $this->splitSubject($body);

        return CustomerUpdateResult::drafted(
            new CustomerUpdateDraft(
                organization: $this->organization,
                subject: $subject,
                body: $body,
                coveredFrom: $releases[array_key_first($releases)]->publishedAt,
                coveredThrough: $releases[array_key_last($releases)]->publishedAt,
                releaseTags: array_map(fn ($release): string => $release->tag, $releases),
            ),
            count($releases)
        );
    }

    /**
     * When we last wrote to them. Used to tell the agent what it must not repeat — it does NOT bound
     * the release query, which is always the fixed window above, so a long-dormant account still gets
     * one month of releases rather than a wall of everything since.
     */
    private function watermark(): ?Carbon
    {
        $stored = Str::trimToNull($this->organization->get(self::WATERMARK_FIELD));

        return $stored !== null ? Carbon::parse($stored) : null;
    }

    private function brief(?Carbon $lastWrittenTo, Carbon $windowOpensAt): string
    {
        $brief = 'Draft this month\'s Kanvas update for organization ' . $this->organization->getId()
            . ' ("' . $this->organization->name . '").'
            . ' You have been given every Kanvas release published since '
            . $windowOpensAt->toDateString() . ' — that is the month this update covers.'
            . ' Title it for ' . $this->periodLabel($windowOpensAt) . '.';

        $brief .= $lastWrittenTo !== null
            ? ' We last wrote to them on ' . $lastWrittenTo->toDateString()
                . '. The update we sent is in the thread below: do not repeat it, lead with what is new since then,'
                . ' and if everything in this window was already covered there, say ' . CustomerUpdateAgent::NOTHING_TO_SEND . '.'
            : ' We have never written to them before, so nothing here has been said to them yet.';

        $brief .= $this->relationshipContext();

        $notes = $this->accountNotes();

        if ($notes === '') {
            return $brief . "\n\nThere are no notes on this account, so you know nothing about them beyond"
                . ' their name. Say only what is plainly true of any customer, and keep it short.';
        }

        return $brief . "\n\nWhat is on this account, oldest first. Everything you know about them is here:\n\n"
            . $notes
            . "\n\nUse read_channel_window on channel " . $this->organization->notes->getId()
            . ' if you need more than this.';
    }

    /**
     * Which month the update is "for". The window is a rolling 30 days that usually straddles two
     * months, so the label is the month holding most of it — computed here rather than left to the
     * agent, which would otherwise pick a different month depending on the day it runs.
     */
    private function periodLabel(Carbon $windowOpensAt): string
    {
        return $windowOpensAt->copy()
            ->addDays((int) (self::WINDOW_DAYS / 2))
            ->format('F Y');
    }

    /**
     * The agent is asked for `Subject: ...` on the first line. Split rather than trust: a model that
     * forgets the line should still produce a sendable body, so a missing subject falls back rather
     * than shipping the word "Subject:" into someone's inbox.
     *
     * @return array{0: string, 1: string}
     */
    private function splitSubject(string $completion): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($completion)) ?: [];
        $first = trim($lines[0] ?? '');

        if (preg_match('/^subject\s*:\s*(.+)$/i', $first, $matches) !== 1) {
            return ['Kanvas — what shipped this month', trim($completion)];
        }

        return [
            trim($matches[1]),
            trim(implode("\n", array_slice($lines, 1))),
        ];
    }

    /**
     * CRM context the notes rarely repeat: who the update reaches, what we are currently selling them,
     * and how long they have been a customer. An open opportunity is the difference between an update
     * that informs and one that moves something — if there is a live deal on a module that just
     * shipped a feature, that is the paragraph worth leading with.
     */
    private function relationshipContext(): string
    {
        $lines = [];

        $contacts = $this->organization->peoples()
            ->limit(self::CONTACT_WINDOW)
            ->get()
            ->map(fn (People $person): string => trim($person->getName()))
            ->filter(fn (string $name): bool => $name !== '')
            ->implode(', ');

        if ($contacts !== '') {
            $lines[] = 'People we deal with there: ' . $contacts . '.';
        }

        $leads = $this->organization->leads()
            ->with(['stage', 'status'])
            ->notDeleted()
            ->orderByDesc('id')
            ->limit(self::LEAD_WINDOW)
            ->get()
            ->map(function (Lead $lead): string {
                $stage = Str::trimToNull($lead->stage?->name);

                return trim((string) $lead->title) . ($stage !== null ? ' (' . $stage . ')' : '');
            })
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->unique()
            ->implode('; ');

        if ($leads !== '') {
            $lines[] = 'Open with them right now: ' . $leads
                . '. If something in these releases moves one of these forward, that is the thing to lead with.';
        }

        $since = $this->organization->created_at;
        if ($since !== null) {
            $months = (int) $since->diffInMonths(now());
            $lines[] = $months < 2
                ? 'They are a brand new account — do not write as though there is shared history.'
                : 'They have been a customer for about ' . $months . ' months.';
        }

        return $lines === [] ? '' : "\n\n" . implode(' ', $lines);
    }

    /**
     * The notes are injected rather than left to a tool call. The agent is told to read them, but
     * whether it does is the model's choice — and a draft written without them is a generic email,
     * which is the one outcome this feature exists to avoid. Same reasoning as the watermark: if the
     * orchestrator already knows something, hand it over instead of hoping for a round trip.
     *
     * Fetched newest-first so the cap keeps recent context, then reversed so it reads as a story.
     */
    private function accountNotes(): string
    {
        $channel = $this->organization->notes;

        if ($channel === null) {
            return '';
        }

        return $channel->messages()
            ->with('tags')
            ->where('messages.is_deleted', 0)
            ->orderByDesc('messages.id')
            ->limit(self::NOTES_WINDOW)
            ->get()
            ->reverse()
            ->map(function (Message $message): string {
                $kind = $message->tags->first()?->slug ?? 'note';
                $written = $message->created_at?->toDateString() ?? '';
                $body = Str::limit(trim($message->contentText()), self::NOTE_CHARS);

                return '[' . $kind . ' · ' . $written . '] ' . $body;
            })
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->implode("\n\n");
    }

    private function handler(): BehavesAsKanvasAgent
    {
        $handlerClass = $this->agent->type?->handler;

        if ($handlerClass === null || ! class_exists($handlerClass)) {
            throw new ValidationException(
                'Agent ' . $this->agent->getId() . ' has no usable handler class. '
                . 'Run kanvas:intelligence:sync-agent-types.'
            );
        }

        $handler = new $handlerClass();

        if (! $handler instanceof BehavesAsKanvasAgent) {
            throw new ValidationException($handlerClass . ' is not a Kanvas agent handler.');
        }

        $handler->setConfiguration(
            agent: $this->agent,
            entity: $this->organization,
            user: $this->agent->user,
        );

        return $handler;
    }
}
