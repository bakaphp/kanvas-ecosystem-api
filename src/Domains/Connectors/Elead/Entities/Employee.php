<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Entities;

use Baka\Contracts\AppInterface;
use Generator;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Client;

class Employee
{
    public string $id;
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

    public static function getAll(AppInterface $app, Companies $company, string $position = 'Administrator'): Generator
    {
        $client = new Client($app, $company);
        $response = $client->get(
            '/sales/v1/elead/productreferencedata/companyEmployees?positionName=' . $position,
        );

        foreach ($response['items'] as $item) {
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
