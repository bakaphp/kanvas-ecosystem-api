<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Kanvas\Guild\Customers\Models\People;

class CreateContentSessionAction
{
    public function __construct(
        public string $entityNamespace,
        public int $entityId
    ) {
    }

    public function execute(): array
    {
        switch ($this->entityNamespace) {
            case People::class:
                $people = People::getById($this->entityId);
                return $this->mapPeople($people);
            default:
                break;
        }

        return [];
    }

    protected function mapPeople(People $people): array
    {
        return [
            'firstname' => $people->firstname,
            'lastname' => $people->lastname,
            'middlename' => $people->middlename,
            'leads' => $people->leads->toArray(),
            'address' => $people->address->toArray(),
            'contacts' => $people->contacts->toArray(),
        ];
    }
}
