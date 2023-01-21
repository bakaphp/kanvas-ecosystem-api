<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Channels\DataTransferObject\Channels as ChannelsDto;
use Kanvas\Inventory\Channels\Models\Channels;

class CreateChannel
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected ChannelsDto $dto,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     *
     * @return Channels
     */
    public function execute() : Channels
    {
        CompaniesRepository::userAssociatedToCompany(
            Companies::getById($this->dto->companies_id),
            $this->user
        );

        return Channels::firstOrCreate([
            'companies_id' => $this->dto->companies_id,
            'apps_id' => $this->dto->apps_id,
            'name' => $this->dto->name,
        ], [
            'name' => $this->dto->name,
            'description' => $this->dto->description,
            'slug' => $this->dto->slug ?? Str::slug($this->dto->name),
            'is_published' => $this->dto->is_published,
            'users_id' => $this->dto->users_id,
            'companies_id' => $this->dto->companies_id,
            'apps_id' => $this->dto->apps_id,
        ]);
    }
}
