<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PromptMine\Enums\FeedTagsEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Social\Tags\Actions\CreateTagAction;
use Kanvas\Social\Tags\DataTransferObjects\Tag as TagData;
use Kanvas\Social\Tags\Models\Tag;

class GenerateFeedTagsForUsersAction
{
    public function __construct(
        private Users $user,
        private Apps $app,
        private Companies $company,
    ) {}

    public function execute(): void
    {
        $feedTagNames = FeedTagsEnum::getValues();
        $originalFeedTags = Tag::fromApp($this->app)
            ->whereIn('name', $feedTagNames)
            ->get();

        foreach ($originalFeedTags as $feedTag) {
            Tag::firstOrCreate([
                'apps_id' => $this->app->getId(),
                'name' => $feedTag->name,
                'companies_id' => $this->company->getId(),
                'users_id' => $this->user->getId(),
            ], [
                'weight' => $feedTag->weight ?? 0,
            ]);
        }
    }
}
