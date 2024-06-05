<?php
declare(strict_types=1);

namespace Kanvas\Social\Tags\Actions;

use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Social\Tags\DataTransferObjects\Tag as TagData;

class CreateTagAction
{
    public function __construct(private TagData $tagData)
    {
    }
    
    public function execute(): Tag
    {
        return Tag::create([
            'apps_id' => $this->tagData->app->getId(),
            'users_id' => $this->tagData->user?->getId(),
            'companies_id' => $this->tagData->company->getId(),
            'name' => $this->tagData->name,
            'slug' => $this->tagData->slug,
            'weight' => $this->tagData->weight,
        ]);
    }
}