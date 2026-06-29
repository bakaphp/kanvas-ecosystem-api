<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Queries\Reports;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\Reports\DataTransferObject\ArAgingData;
use Kanvas\Scribe\Reports\DataTransferObject\BalanceSheetData;
use Kanvas\Scribe\Reports\DataTransferObject\ProfitAndLossData;
use Kanvas\Scribe\Reports\DataTransferObject\RevenueReportData;
use Kanvas\Scribe\Reports\DataTransferObject\TrialBalanceData;
use Kanvas\Scribe\Reports\Enums\RevenueGroupByEnum;
use Kanvas\Scribe\Reports\Repositories\ArAgingRepository;
use Kanvas\Scribe\Reports\Repositories\BalanceSheetRepository;
use Kanvas\Scribe\Reports\Repositories\ProfitAndLossRepository;
use Kanvas\Scribe\Reports\Repositories\RevenueRepository;
use Kanvas\Scribe\Reports\Repositories\TrialBalanceRepository;

class ReportsQuery
{
    public function balanceSheet(mixed $rootValue, array $request): BalanceSheetData
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return new BalanceSheetRepository()->generate(
            app: $app,
            company: $company,
            asOf: Carbon::parse((string) $request['as_of']),
            currency: (string) ($request['currency'] ?? 'USD'),
        );
    }

    public function profitAndLoss(mixed $rootValue, array $request): ProfitAndLossData
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return new ProfitAndLossRepository()->generate(
            app: $app,
            company: $company,
            periodStart: Carbon::parse((string) $request['period_start']),
            periodEnd: Carbon::parse((string) $request['period_end']),
            currency: (string) ($request['currency'] ?? 'USD'),
        );
    }

    public function trialBalance(mixed $rootValue, array $request): TrialBalanceData
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return new TrialBalanceRepository()->generate(
            app: $app,
            company: $company,
            asOf: Carbon::parse((string) $request['as_of']),
            currency: (string) ($request['currency'] ?? 'USD'),
        );
    }

    public function arAging(mixed $rootValue, array $request): ArAgingData
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return new ArAgingRepository()->generate(
            app: $app,
            company: $company,
            asOf: Carbon::parse((string) $request['as_of']),
            currency: (string) ($request['currency'] ?? 'USD'),
        );
    }

    public function revenue(mixed $rootValue, array $request): RevenueReportData
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $groupBy = isset($request['group_by'])
            ? RevenueGroupByEnum::from((string) $request['group_by'])
            : RevenueGroupByEnum::CUSTOMER;

        return new RevenueRepository()->generate(
            app: $app,
            company: $company,
            periodStart: Carbon::parse((string) $request['period_start']),
            periodEnd: Carbon::parse((string) $request['period_end']),
            groupBy: $groupBy,
            currency: (string) ($request['currency'] ?? 'USD'),
        );
    }
}
