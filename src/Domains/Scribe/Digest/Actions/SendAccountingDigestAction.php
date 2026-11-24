<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Digest\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Scribe\Digest\Services\AccountingDigestBuilderService;
use Kanvas\Scribe\Digest\Services\AccountingDigestSummaryService;
use Kanvas\Scribe\Notifications\Enums\ScribeEmailTemplateEnum;
use Kanvas\Scribe\Setup\Services\AccountingAgentLocatorService;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;

/**
 * One AR/AP digest per company per day, to the people the accounting agents report to: whoever hired each
 * agent, plus the company's AgentReport members (the daily learning digest's audience), else the owner.
 * A company with no AR/AP agent, or a day with nothing to report, sends nothing and records nothing.
 */
class SendAccountingDigestAction
{
    public function __construct(
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Carbon $day,
    ) {
    }

    /**
     * @return int how many people it was sent to
     */
    public function execute(): int
    {
        $agents = new AccountingAgentLocatorService()->all($this->app, $this->company);

        if ($agents === []) {
            return 0;
        }

        $digest = new AccountingDigestBuilderService($this->app, $this->company, $this->day)->build();

        if (! $digest['has_activity']) {
            return 0;
        }

        $recipients = $this->recipients($agents);

        if ($recipients->isEmpty()) {
            return 0;
        }

        $summary = new AccountingDigestSummaryService()->text($digest);

        $notification = new Blank(
            ScribeEmailTemplateEnum::ACCOUNTING_DIGEST->value,
            [...$digest, 'summary' => $summary],
            ['mail'],
            $this->company
        );
        $notification->setSubject(sprintf('%s — accounting digest for %s', $this->company->name, $digest['date']));

        Notification::send($recipients->all(), $notification);

        $this->record($recipients, $agents, $summary);

        return $recipients->count();
    }

    /**
     * @param list<Agent> $agents
     * @return Collection<int, Users>
     */
    private function recipients(array $agents): Collection
    {
        $creatorIds = array_values(array_filter(array_map(fn (Agent $agent): ?int => $agent->created_by_users_id, $agents)));

        $people = Users::query()
            ->whereIn('id', $creatorIds)
            ->where('is_deleted', 0)
            ->get()
            ->merge($this->agentReportMembers());

        if ($people->isEmpty() && $this->company->user !== null) {
            $people = collect([$this->company->user]);
        }

        return $people
            ->filter(fn (Users $user): bool => trim((string) $user->email) !== '')
            ->unique(fn (Users $user): int => $user->getId())
            ->values();
    }

    /**
     * @return Collection<int, Users>
     */
    private function agentReportMembers(): Collection
    {
        try {
            return UsersRepository::getActiveCompanyAppUsersByRole(
                $this->company,
                $this->app,
                RolesEnums::AGENT_REPORT->value
            )->get();
        } catch (ModelNotFoundException) {
            return collect();
        }
    }

    /**
     * @param Collection<int, Users> $recipients
     * @param list<Agent> $agents
     */
    private function record(Collection $recipients, array $agents, string $summary): void
    {
        try {
            new AppendEventAction(new EventData(
                app: $this->app,
                company: $this->company,
                sourceDomain: 'Scribe',
                eventType: 'scribe.accounting_digest.sent',
                status: EventStatusEnum::SUCCESS,
                sourceEntityType: Companies::class,
                sourceEntityId: $this->company->getId(),
                actorType: 'System',
                payload: [
                    'date' => $this->day->toDateString(),
                    'recipient_count' => $recipients->count(),
                    'agent_ids' => array_map(fn (Agent $agent): int => $agent->getId(), $agents),
                    'summary' => $summary,
                ],
            ))->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
