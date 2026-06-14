<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Ledger;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\Ledger\Actions\CloseFiscalPeriodAction;
use Kanvas\Scribe\Ledger\Actions\OpenFiscalPeriodAction;
use Kanvas\Scribe\Ledger\Actions\ReopenFiscalPeriodAction;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use RuntimeException;

class FiscalPeriodMutation
{
    public function open(mixed $rootValue, array $request): FiscalPeriod
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        return new OpenFiscalPeriodAction(
            app: $app,
            company: $company,
            periodStart: Carbon::parse((string) $input['period_start']),
            periodEnd: Carbon::parse((string) $input['period_end']),
            user: $user,
            metadata: $input['metadata'] ?? null,
        )->execute();
    }

    public function close(mixed $rootValue, array $request): FiscalPeriod
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $period = $this->findPeriod((int) $request['id'], $app, $company);

        $hard = (bool) ($request['hard'] ?? false);

        return new CloseFiscalPeriodAction(
            period: $period,
            targetStatus: $hard ? FiscalPeriodStatusEnum::HARD_CLOSED : FiscalPeriodStatusEnum::SOFT_CLOSED,
            user: $user,
            closeNotes: $request['close_notes'] ?? null,
        )->execute();
    }

    public function reopen(mixed $rootValue, array $request): FiscalPeriod
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $period = $this->findPeriod((int) $request['id'], $app, $company);

        return new ReopenFiscalPeriodAction(
            period: $period,
            user: $user,
            reopenNotes: $request['reopen_notes'] ?? null,
        )->execute();
    }

    private function findPeriod(int $id, $app, $company): FiscalPeriod
    {
        $period = FiscalPeriod::query()
            ->where('id', $id)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->first();

        if ($period === null) {
            throw new RuntimeException(
                "Fiscal period {$id} not found in app {$app->getId()} / company {$company->getId()}."
            );
        }

        return $period;
    }
}
