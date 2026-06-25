<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Apollo\Queries;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Actions\CleanupReportAction;
use Kanvas\Users\Models\Users;

class CleanupReportQuery
{
    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    public function report(mixed $rootValue, array $request): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CleanupReportAction(
            app: $app,
            company: $company,
            from: ! empty($request['from']) ? Carbon::parse($request['from'])->startOfDay() : null,
            to: ! empty($request['to']) ? Carbon::parse($request['to'])->endOfDay() : null,
            topCompanies: (int) ($request['topCompanies'] ?? 5),
        )->execute();
    }
}
