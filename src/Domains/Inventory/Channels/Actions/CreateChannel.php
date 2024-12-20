<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Str;
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
    public function execute(): Channels
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->dto->company,
            $this->user
        );

        return Channels::firstOrCreate([
            'companies_id' => $this->dto->company->getId(),
            'apps_id' => $this->dto->app->getId(),
            'slug' => $this->dto->slug ?? Str::slug($this->dto->name),
        ], [
            'name' => $this->dto->name,
            'description' => $this->dto->description,
            'is_default' => $this->dto->is_default,
            'is_published' => $this->dto->is_published,
            'users_id' => $this->user->getId(),
        ]);
    }
}
