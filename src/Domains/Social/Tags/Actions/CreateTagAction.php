<?php

declare(strict_types=1);

namespace Kanvas\Social\Tags\Actions;

use Kanvas\Social\Tags\DataTransferObjects\Tag as TagData;
use Kanvas\Social\Tags\Models\Tag;

class CreateTagAction
{
    public function __construct(
        protected TagData $tagData
    ) {
    }

    public function execute(): Tag
    {
        return Tag::firstOrCreate([
            'apps_id' => $this->tagData->app->getId(),
            'companies_id' => $this->tagData->company->getId(),
            'name' => $this->tagData->name,
        ], [
            'users_id' => $this->tagData->user?->getId(),
            'weight' => $this->tagData->weight,
        ]);
    }
}
