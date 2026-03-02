<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Entities;

use Baka\Contracts\AppInterface;
use Generator;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Client;
use Kanvas\Connectors\Elead\Support\EleadCache;

class Employee
{
    public ?string $id = null;
    public ?string $firstName = null;
    public ?string $lastName = null;
    public bool $isActive = true;
    public bool $isOff = true;
    public array $links = [];
    public ?Companies $company = null;
    public ?AppInterface $app = null;

    /**
     * Assign value to the current object.
     */
    public function assign(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public static function getAll(AppInterface $app, Companies $company, string $position = 'Administrator', bool $fresh = false): Generator
    {
        $cache = new EleadCache($app, $company);
        $cacheKey = strtolower(str_replace(' ', '_', $position));

        if (! $fresh) {
            $cached = $cache->get('employees', $cacheKey);

            if ($cached !== null) {
                foreach ($cached as $item) {
                    $employee = new Employee();
                    $employee->assign($item);
                    $employee->app = $app;
                    $employee->company = $company;

                    yield $employee;
                }

                return;
            }
        }

        $client = new Client($app, $company);
        $response = $client->get(
            '/sales/v1/elead/productreferencedata/companyEmployees?positionName=' . urlencode($position),
        );

        $items = $response['items'] ?? [];
        $cache->setReference('employees', $cacheKey, $items);

        foreach ($items as $item) {
            $employee = new Employee();
            $employee->assign($item);
            $employee->app = $app;
            $employee->company = $company;

            yield $employee;
        }
    }

    public function getEmails(): array
    {
        $client = new Client($this->app, $this->company);
        $response = $client->get(
            str_replace('https://api.fortellis.io', '', strtolower($this->links[0]['href'])),
        );

        if (isset($response['items'])) {
            return $response['items'];
        }

        return [];
    }
}
