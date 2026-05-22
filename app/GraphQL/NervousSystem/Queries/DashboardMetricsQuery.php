<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Queries;

use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Dashboard\DataTransferObject\DashboardMetrics;
use Kanvas\NervousSystem\Dashboard\Enums\DashboardPeriodEnum;
use Kanvas\NervousSystem\Dashboard\Services\DashboardMetricsService;
use Kanvas\Users\Models\Users;

class DashboardMetricsQuery
{
    public function __invoke(mixed $rootValue, array $args): DashboardMetrics
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $period = isset($args['period'])
            ? DashboardPeriodEnum::from((string) $args['period'])
            : DashboardPeriodEnum::TODAY;

        return new DashboardMetricsService()->compute($app, $company, $period);
    }
}
