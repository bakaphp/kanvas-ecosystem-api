<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Channels\Actions;

use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Channels\DataTransferObject\Channels as ChannelsDto;
use Illuminate\Support\Str;

class CreateChannel
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
       protected ChannelsDto $dto
    ) {
    }
    
    /**
     * execute
     *
     * @return Channels
     */
    public function execute(): Channels
    {
        return Channels::create([
            'companies_id' => $this->dto->companies_id,
            'apps_id' => $this->dto->apps_id,
            'users_id' => $this->dto->users_id,
            'uuid' => Str::uuid(),
            'name' => $this->dto->name,
            'description' => $this->dto->description,
            'slug' => $this->dto->slug ?? Str::slug($this->dto->name),
            'is_published' => $this->dto->is_published,
        ]);
    }
}
