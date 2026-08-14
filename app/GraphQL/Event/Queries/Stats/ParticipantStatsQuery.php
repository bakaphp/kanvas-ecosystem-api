<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Queries\Stats;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Reports\DataTransferObject\ParticipantActivity;
use Kanvas\Event\Reports\Repositories\ParticipantActivityRepository;

class ParticipantStatsQuery
{
    public function __invoke(mixed $rootValue, array $args): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $stats = ParticipantActivityRepository::activity(
            app: $app,
            company: $company,
            fromDate: isset($args['from_date']) ? Carbon::parse((string) $args['from_date']) : null,
            toDate: isset($args['to_date']) ? Carbon::parse((string) $args['to_date']) : null,
            topN: isset($args['top_n']) ? (int) $args['top_n'] : null,
        );

        return [
            'total' => $stats['total'],
            'new_count' => $stats['new_count'],
            'returning_count' => $stats['returning_count'],
            'rows' => array_map(fn (ParticipantActivity $row) => $row->toArray(), $stats['rows']),
        ];
    }
}
