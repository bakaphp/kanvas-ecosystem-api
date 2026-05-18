<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Queries;

use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Dashboard\Enums\DashboardPeriodEnum;
use Kanvas\NervousSystem\Pulse\DataTransferObject\PulseMetrics;
use Kanvas\NervousSystem\Pulse\Services\PulseMetricsService;
use Kanvas\Users\Models\Users;

class PulseMetricsQuery
{
    public function __invoke(mixed $rootValue, array $args): PulseMetrics
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $period = isset($args['period'])
            ? DashboardPeriodEnum::from((string) $args['period'])
            : DashboardPeriodEnum::TODAY;

        return new PulseMetricsService()->compute($app, $company, $period);
    }
}
