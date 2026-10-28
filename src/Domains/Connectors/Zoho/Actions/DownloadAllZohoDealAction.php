<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Generator;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Guild\Deals\Models\Deal;
use Webleit\ZohoCrmApi\Models\Record;

class DownloadAllZohoDealAction
{
    protected int $totalDealsProcessed = 0;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
    ) {
    }

    /**
     * @return Generator<int, Deal>
     */
    public function execute(int $totalPages = 50, int $dealsPerPage = 200): Generator
    {
        for ($page = 1; $page <= $totalPages; $page++) {
            $records = $this->fetchPage($page, $dealsPerPage);

            if ($records === []) {
                return;
            }

            foreach ($records as $record) {
                $deal = $this->pull($record);

                if ($deal === null) {
                    continue;
                }

                $this->totalDealsProcessed++;

                yield $deal;
            }
        }
    }

    public function getTotalDealsProcessed(): int
    {
        return $this->totalDealsProcessed;
    }

    /**
     * @return list<array>
     */
    protected function fetchPage(int $page, int $perPage): array
    {
        $records = Client::getInstance($this->app, $this->company)
            ->deals
            ->getList(['page' => $page, 'per_page' => $perPage]);

        return collect($records)
            ->map(fn (Record $record) => $record->getData())
            ->values()
            ->all();
    }

    protected function pull(array $zohoDealInfo): ?Deal
    {
        $zohoDealId = (string) ($zohoDealInfo['id'] ?? '');

        if ($zohoDealId === '') {
            return null;
        }

        return new PullZohoDealAction(
            $this->app,
            $this->company,
            $this->user,
            $zohoDealId
        )->execute($zohoDealInfo);
    }
}
