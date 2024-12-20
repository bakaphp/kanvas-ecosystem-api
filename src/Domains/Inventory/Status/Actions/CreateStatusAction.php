<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Status\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Str;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Status\DataTransferObject\Status as StatusDto;
use Kanvas\Inventory\Status\Models\Status;

class CreateStatusAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected StatusDto $dto,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     *
     * @return Status
     */
    public function execute(): Status
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->dto->company,
            $this->user
        );

        return Status::firstOrCreate([
            'companies_id' => $this->dto->company->getId(),
            'apps_id' => $this->dto->app->getId(),
            'slug' => $this->dto->slug ?? Str::slug($this->dto->name),
        ], [
            'name' => $this->dto->name,
            'is_default' => $this->dto->is_default
        ]);
    }
}
