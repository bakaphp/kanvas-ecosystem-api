<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;

class CreateContentSessionAction
{
    public function __construct(
        public string $entityNamespace,
        public int|string $entityId,
        public ?Agent $agent = null,
        public ?CompaniesBranches $branch = null,
    ) {
    }

    public function execute(): array
    {
        return match ($this->entityNamespace) {
            People::class => $this->mapPeople(People::getById($this->entityId)),
            default => [],
        };
    }

    protected function mapPeople(People $people): array
    {
        return [
            'branch' => $this->branch,
            'people_id' => $people->id,
            'firstname' => $people->firstname,
            'lastname' => $people->lastname,
            'middlename' => $people->middlename,
            'leads' => $people->leads->toArray(),
            'address' => $people->address->toArray(),
            'contacts' => $people->contacts->toArray(),
            'background' => $this->agent?->role,
            'checklist' => [
                'credit-app' => 'https://kanvas.dev/credit-app',
                'trade-in' => 'https://kanvas.dev/trade-in',
            ],
        ];
    }
}
