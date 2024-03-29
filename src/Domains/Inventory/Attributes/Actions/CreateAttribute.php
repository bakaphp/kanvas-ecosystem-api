<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributeDto;
use Kanvas\Inventory\Attributes\Models\Attributes;

class CreateAttribute
{
    public function __construct(
        protected AttributeDto $dto,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     *
     * @return Attributes
     */
    public function execute(): Attributes
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->dto->company,
            $this->user
        );

        return Attributes::firstOrCreate([
            'name' => $this->dto->name,
            'companies_id' => $this->dto->company->getId(),
            'apps_id' => $this->dto->app->getId(),
        ], [
            'users_id' => $this->user->getId()
        ]);
    }
}
