<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Supervisor\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Supervisor\Services\SupervisorConfigurationAuditService;
use Kanvas\Connectors\SalesAssist\Supervisor\Services\SupervisorFindingLedger;
use Kanvas\Connectors\SalesAssist\Supervisor\Services\SupervisorScheduleService;
use Kanvas\Intelligence\Agents\Actions\Chat\WakeAgentInSessionAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;

/**
 * One dealer's hourly tick. The audit always runs and costs no model. The agent only wakes inside the
 * dealer's window and only when there is a conversation it has not seen.
 */
class RunSupervisorReviewJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    private const string INSTRUCTION = 'Review the new conversations of this dealer and record what is wrong.';

    public int $tries = 1;
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly Agent $agent,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->app->getId() . ':' . $this->company->getId();
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $ledger = new SupervisorFindingLedger($this->app, $this->company);
        $ledger->reconcileConfiguration(
            new SupervisorConfigurationAuditService($this->app, $this->company)->execute()
        );

        if (! new SupervisorScheduleService($this->company)->shouldReviewConversations()) {
            return;
        }

        $until = Carbon::now();

        if ($ledger->leadMessagesSince($ledger->reviewSince())->exists()) {
            new WakeAgentInSessionAction(
                agent: $this->agent,
                session: $this->session(),
                instruction: self::INSTRUCTION,
                user: $this->agent->user,
            )->execute();
        }

        $ledger->markReviewed($until);
    }

    private function session(): Session
    {
        /** @var Session $session */
        $session = Session::firstOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'entity_namespace' => Companies::class,
                'entity_id' => $this->company->getId(),
                'agents_id' => $this->agent->getId(),
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'channel_id' => null,
                'content' => '',
                'user' => [],
            ],
        );

        return $session;
    }
}
