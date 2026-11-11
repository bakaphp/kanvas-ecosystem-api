<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice;

use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\Leads\Services\LeadConversationTranscriptService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Leads\Services\ContactHistoryService;
use Throwable;

use function Sentry\captureException;

/**
 * Resolve the Lead behind a phone number and assemble a compact context an
 * OUTBOUND voice agent can use — so the agent knows who it's calling and their
 * recent history instead of just a number.
 *
 * Reuses Kanvas's own resolution (PeoplesRepository::getByPhoneNumber +
 * LeadsRepository::getPeopleActiveLead) and the shared contact history, so the agent
 * knows how and when we last talked, on any channel. Scoped to the agent's company/app.
 *
 * Best-effort: returns null (no context) rather than throwing, so a placing
 * call is never blocked by a lookup miss. The `summary` is a short, bounded,
 * natural-language block suitable to hand the LLM as call context.
 */
class BuildVoiceAgentLeadContextAction
{
    // Keep the payload small enough to ride along as a Twilio <Stream> parameter.
    private const CONTEXT_INFO_MAX = 800;
    private const int RECENT_MESSAGES = 6;
    private const int RECENT_MESSAGES_MAX = 600;
    private const int MESSAGE_MAX = 160;

    public function __construct(
        private readonly Agent $agent,
        private readonly string $phone,
    ) {
    }

    /**
     * @return array<string, mixed>|null compact lead context, or null when none resolves
     */
    public function execute(): ?array
    {
        try {
            [$person, $lead] = $this->resolve();
            if ($person === null) {
                return null; // truly unknown number — nothing to recognize
            }

            // Known lead -> full context. Known contact with no lead -> a lighter
            // "returning caller" context (recognition without pretending we have a
            // lead). Both set is_returning so callers can tailor the greeting.
            return $lead !== null
                ? $this->leadContext($lead)
                : $this->contactContext($person);
        } catch (Throwable $e) {
            captureException($e);

            return null; // never block the call over a context miss
        }
    }

    /**
     * Resolve the person behind the number and their current lead (if any).
     *
     * @return array{0: People|null, 1: Lead|null}
     */
    private function resolve(): array
    {
        $company = $this->agent->companies_id > 0
            ? Companies::find($this->agent->companies_id)
            : null;
        if ($company === null) {
            return [null, null];
        }

        $people = PeoplesRepository::getByPhoneNumber(
            app: $this->agent->app,
            company: $company,
            phoneNumbers: $this->phoneVariants(),
        )->get();
        if ($people->isEmpty()) {
            return [null, null];
        }

        // Prefer a person with an active lead; else the first person's last lead.
        foreach ($people as $person) {
            $lead = LeadsRepository::getPeopleActiveLead($person);
            if ($lead !== null) {
                return [$person, $lead];
            }
        }

        $first = $people->first();

        return [$first, LeadsRepository::getPeopleLastLead($first)];
    }

    /**
     * @return array<string, mixed>
     */
    private function leadContext(Lead $lead): array
    {
        $name = trim(($lead->firstname ?? '') . ' ' . ($lead->lastname ?? ''));
        if ($name === '') {
            $name = (string) ($lead->title ?? '');
        }

        $vehicle = trim((string) $lead->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value));
        $contextInfo = $this->historyText(
            new ContactHistoryService()->forLead($lead),
            $lead->getAiContextInfo()['voice_context'] ?? null,
        );

        $context = [
            'lead_id' => $lead->getId(),
            'name' => $name !== '' ? $name : null,
            // Guard with is_object: these relations resolve to the raw FK int
            // (e.g. 0) when unset — a voice-captured lead has status/stage/owner
            // of 0 — and ?-> only guards null, so ?->name on an int throws.
            'status' => is_object($lead->status) ? $lead->status->name : null,
            'stage' => is_object($lead->stage) ? $lead->stage->name : null,
            'owner' => is_object($lead->owner) ? $lead->owner->displayname : null,
            'vehicle_interest' => $vehicle !== '' ? $vehicle : null,
            'context_info' => $contextInfo,
            'recent_messages' => $this->recentMessages($lead),
            'is_returning' => true,
        ];
        $context['summary'] = $this->summarize($context);

        return $context;
    }

    /**
     * A known contact with no lead yet — recognized, but not (yet) a lead.
     *
     * @return array<string, mixed>
     */
    private function contactContext(People $person): array
    {
        $name = $this->peopleName($person);
        $history = $this->historyText(new ContactHistoryService()->forPeople($person));
        $messages = $this->recentMessages($person);
        $summary = $name !== null
            ? "We already know this contact: {$name}. There's no open lead yet; they've reached out before."
            : "This number has reached out before, but we don't have their name yet.";

        return [
            'lead_id' => null,
            'name' => $name,
            'status' => null,
            'stage' => null,
            'owner' => null,
            'vehicle_interest' => null,
            'context_info' => $history,
            'recent_messages' => $messages,
            'is_returning' => true,
            'summary' => implode("\n", array_filter([
                $summary,
                $history !== null ? "Previous contacts:\n{$history}" : null,
                $messages,
            ])),
        ];
    }

    /**
     * The last few SMS / email / chat turns, as the lead's text agent sees them, so a call
     * that follows a text conversation picks it up. Best-effort: a failure here only drops
     * the block, never the rest of the context.
     */
    private function recentMessages(Lead|People $entity): ?string
    {
        try {
            $lines = LeadConversationTranscriptService::lines($entity);
        } catch (Throwable $e) {
            captureException($e);

            return null;
        }

        $lines = array_map(
            fn (string $line): string => Str::limit($line, self::MESSAGE_MAX),
            array_slice($lines, -self::RECENT_MESSAGES)
        );
        if ($lines === []) {
            return null;
        }

        return mb_substr("Recent messages:\n" . implode("\n", $lines), 0, self::RECENT_MESSAGES_MAX);
    }

    /**
     * Recent contacts newest first. A legacy voice summary in LEAD_CONTEXT_INFO stands in
     * only while the lead has no history yet. Bounded to fit a Twilio <Stream> parameter.
     *
     * @param array<int, array<string, mixed>> $entries
     */
    private function historyText(array $entries, ?string $legacy = null): ?string
    {
        $text = trim(implode("\n", array_filter([
            new ContactHistoryService()->toPromptLines($entries),
            $entries === [] ? $legacy : null,
        ])));

        return $text !== '' ? mb_substr($text, 0, self::CONTEXT_INFO_MAX) : null;
    }

    /**
     * A real name for the person, or null. createPeopleFromPhone stores the phone
     * number as firstname, so a phone-only "name" is treated as unknown.
     */
    private function peopleName(People $person): ?string
    {
        $name = trim(($person->firstname ?? '') . ' ' . ($person->lastname ?? ''));
        if ($name === '' || preg_match('/^[\d\s+()\-]+$/', $name) === 1) {
            return null;
        }

        return $name;
    }

    /**
     * The number variants to feed getByPhoneNumber — the local (normalized) form
     * and the country-code form. Mirrors the shared pattern used elsewhere for
     * this exact lookup (see ProcessTwilioWebhookJob::processContactFromMessage).
     *
     * @return array<int, string>
     */
    private function phoneVariants(): array
    {
        return array_values(array_unique(array_filter([
            Str::normalizePhoneNumber($this->phone),
            str_replace('+', '', $this->phone),
        ])));
    }

    /**
     * @param array<string, mixed> $c
     */
    private function summarize(array $c): string
    {
        $lines = [];
        $lines[] = $c['name'] !== null
            ? "You're speaking with {$c['name']}."
            : "You're speaking with a customer.";

        $state = trim(($c['status'] ?? '') . ' / ' . ($c['stage'] ?? ''), ' /');
        if ($state !== '') {
            $lines[] = "Status: {$state}.";
        }
        if ($c['owner'] !== null) {
            $lines[] = "Assigned salesperson: {$c['owner']}.";
        }
        if ($c['vehicle_interest'] !== null) {
            $lines[] = "Interest: {$c['vehicle_interest']}.";
        }
        if ($c['context_info'] !== null) {
            $lines[] = "Previous contacts:\n{$c['context_info']}";
        }
        if ($c['recent_messages'] !== null) {
            $lines[] = $c['recent_messages'];
        }

        return implode("\n", $lines);
    }
}
