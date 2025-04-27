<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Entities;

use Baka\Contracts\AppInterface;
use Generator;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Client;

class LeadStatus
{
    public ?string $status = null;
    public array $subStatus = [];

    /**
     * Assign value to the current object.
     */
    public function assign(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public static function getAll(AppInterface $app, Companies $company): Generator
    {
        $client = new Client($app, $company);
        $response = $client->get(
            '/sales/v1/elead/productreferencedata/companyOpportunityStatuses'
        );

        foreach ($response['items'] as $item) {
            $leadSource = new self();
            $leadSource->assign($item);
            yield $leadSource;
        }
    }
}
