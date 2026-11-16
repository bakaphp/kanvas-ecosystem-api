<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Supervisor\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Social\Messages\Models\AppModuleMessage;

/**
 * Findings live in the ledger: opened and resolved are events, and a finding is open while its latest
 * event says so. That is what keeps one problem from being reported again every hour.
 */
final class SupervisorFindingLedger
{
    public const string SOURCE_DOMAIN = 'salesassist_supervisor';
    public const string EVENT_OPENED = 'sa.supervisor.finding_opened';
    public const string EVENT_RESOLVED = 'sa.supervisor.finding_resolved';
    public const string EVENT_REVIEW_COMPLETED = 'sa.supervisor.review_completed';

    public const string ORIGIN_CONFIGURATION = 'configuration';
    public const string ORIGIN_CONVERSATION = 'conversation';

    private const int FIRST_REVIEW_HOURS = 24;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>> Payload of each open finding, by fingerprint.
     */
    public function open(): array
    {
        $events = $this->events()
            ->whereIn('event_type', [self::EVENT_OPENED, self::EVENT_RESOLVED])
            ->orderBy('id')
            ->get();

        $open = [];
        foreach ($events as $event) {
            $fingerprint = (string) ($event->payload['fingerprint'] ?? '');

            if ($event->event_type === self::EVENT_OPENED) {
                $open[$fingerprint] = $event->payload;

                continue;
            }

            unset($open[$fingerprint]);
        }

        return $open;
    }

    /**
     * Opens what the audit found, resolves what it stopped finding. Conversation findings are not the
     * audit's to close.
     *
     * @param list<array{code: string, severity: string, summary: string, evidence: array<string, mixed>}> $findings
     * @return array{opened: int, resolved: int}
     */
    public function reconcileConfiguration(array $findings): array
    {
        $open = $this->open();
        $current = [];
        $opened = 0;

        foreach ($findings as $finding) {
            $fingerprint = self::fingerprint(self::ORIGIN_CONFIGURATION, $finding['code']);
            $current[$fingerprint] = true;

            if (! isset($open[$fingerprint])) {
                $this->record(self::ORIGIN_CONFIGURATION, $fingerprint, $finding);
                $opened++;
            }
        }

        $resolved = 0;
        foreach ($open as $fingerprint => $payload) {
            if (($payload['origin'] ?? null) === self::ORIGIN_CONFIGURATION && ! isset($current[$fingerprint])) {
                $this->append(self::EVENT_RESOLVED, ['fingerprint' => $fingerprint, 'code' => $payload['code'] ?? null]);
                $resolved++;
            }
        }

        return ['opened' => $opened, 'resolved' => $resolved];
    }

    /**
     * @param array<string, mixed> $finding
     */
    public function record(string $origin, string $fingerprint, array $finding): void
    {
        $this->append(self::EVENT_OPENED, [...$finding, 'origin' => $origin, 'fingerprint' => $fingerprint]);

        if (($finding['severity'] ?? null) === SupervisorConfigurationAuditService::SEVERITY_CRITICAL) {
            Log::warning('SalesAssist supervisor critical finding', [
                'company_id' => $this->company->getId(),
                'code' => $finding['code'] ?? null,
            ]);

            new SupervisorFindingNotifier($this->app, $this->company)->critical($finding);
        }
    }

    /**
     * Findings opened since the given moment and still open: what a digest has news about. Critical ones
     * already went out when they opened.
     *
     * @return list<array<string, mixed>>
     */
    public function openNonCriticalSince(Carbon $since): array
    {
        $recent = $this->events()
            ->where('event_type', self::EVENT_OPENED)
            ->where('occurred_at', '>=', $since)
            ->get()
            ->map(static fn (Event $event): string => (string) ($event->payload['fingerprint'] ?? ''))
            ->all();

        return array_values(array_filter(
            $this->open(),
            static fn (array $finding, string $fingerprint): bool => in_array($fingerprint, $recent, true)
                && ($finding['severity'] ?? null) !== SupervisorConfigurationAuditService::SEVERITY_CRITICAL,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    public function lastReviewedAt(): ?Carbon
    {
        $event = $this->events()
            ->where('event_type', self::EVENT_REVIEW_COMPLETED)
            ->latest('id')
            ->first();

        $until = $event?->payload['reviewed_until'] ?? null;

        return $until === null ? null : Carbon::parse($until);
    }

    public function reviewSince(): Carbon
    {
        return $this->lastReviewedAt() ?? Carbon::now()->subHours(self::FIRST_REVIEW_HOURS);
    }

    public function leadMessagesSince(Carbon $since): Builder
    {
        return AppModuleMessage::query()
            ->where('system_modules', Lead::class)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->whereHas('message', fn ($query) => $query->where('is_deleted', 0)->where('created_at', '>', $since));
    }

    public function markReviewed(Carbon $until): void
    {
        $this->append(self::EVENT_REVIEW_COMPLETED, ['reviewed_until' => $until->toIso8601String()]);
    }

    public static function fingerprint(string $origin, string ...$parts): string
    {
        return hash('sha256', $origin . '|' . implode('|', $parts));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(string $eventType, array $payload): void
    {
        new AppendEventAction(new EventData(
            app: $this->app,
            company: $this->company,
            sourceDomain: self::SOURCE_DOMAIN,
            eventType: $eventType,
            status: EventStatusEnum::INFO,
            payload: $payload,
        ))->execute();
    }

    private function events(): Builder
    {
        return Event::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source_domain', self::SOURCE_DOMAIN);
    }
}
