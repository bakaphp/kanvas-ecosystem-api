<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Actions;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class DailyUsageReportAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company
    ) {
    }

    public function execute(?string $date = null): array
    {
        $report = $this->company->get(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value) ?? [];
        $usersWhoWantReport = $this->company->get(ConfigurationEnum::APOLLO_COMPANY_REPORTS_USERS->value) ?? [];
        $todayInEST = $date ?? Carbon::now('America/New_York')->format('Y-m-d');

        if (empty($report) || ! isset($report[$todayInEST]) || empty($usersWhoWantReport)) {
            return [];
        }

        return $this->sendReport($todayInEST, $report[$todayInEST], $usersWhoWantReport);
    }

    private function sendReport(string $date, array $report, array $users): array
    {
        $totalUsersSent = [];
        foreach ($users as $userId) {
            try {
                $user = Users::getById($userId);
                UsersRepository::belongsToThisApp($user, $this->app, $this->company);

                $user->notify(
                    new Blank(
                        'apollo-daily-report',
                        [
                            'report' => $report,
                            'today' => $date,
                            'subject' => 'Apollo Daily Enrichment Report - ' . $date,
                        ],
                        ['mail'],
                        $user
                    )
                );

                $totalUsersSent[] = $user->id;
            } catch (ModelNotFoundException $e) {
                continue;
            }
        }

        return $totalUsersSent;
    }
}
