<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Services\LeadService;
use Kanvas\Guild\Leads\Models\Lead;
use Throwable;

class PullLeadsByDateRangeAction
{
    protected LeadService $leadService;
    protected PullLeadAction $pullLeadAction;

    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
    ) {
        $this->leadService = new LeadService($this->app, $this->company);
        $this->pullLeadAction = new PullLeadAction($this->app, $this->company, $this->user);
    }

    /**
     * Pull deals by date range.
     *
     * @param string $startDate Start date in YYYY-MM-DD format
     * @param string $endDate End date in YYYY-MM-DD format
     * @param int $offset Number of rows to skip
     *
     * @return Lead[]
     */
    public function execute(
        string $startDate,
        string $endDate,
        int $offset = 0
    ): array {
        return DB::transaction(function () use ($startDate, $endDate, $offset): array {
            $deals = $this->leadService->getDealsByRange(
                $startDate,
                $endDate,
                $offset
            );

            $syncedLeads = [];
            foreach ($deals as $deal) {
                try {
                    $syncedLeads[] = $this->pullLeadAction->syncDeal($deal);
                } catch (Throwable $e) {
                    report($e);
                }
            }

            return $syncedLeads;
        });
    }
}
