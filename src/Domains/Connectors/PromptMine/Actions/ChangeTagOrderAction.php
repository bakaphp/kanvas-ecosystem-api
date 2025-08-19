<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Enums\FeedTagsEnum;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Connectors\PromptMine\Actions\GenerateFeedTagsForUsersAction;
use Kanvas\Users\Models\Users;

class ChangeTagOrderAction
{
    public function __construct(
        private Users $user,
        private Apps $app,
        private string $tagName,
        private int $weight
    ) {
    }

    public function execute(): bool
    {
        // Just in case the logged user does not have the feed tags generated
        $generateFeedTags = new GenerateFeedTagsForUsersAction($this->user, $this->app, $this->user->getCurrentCompany());
        $generateFeedTags->execute();

        $oldWeight = 0;
        $feedTag = Tag::fromApp($this->app)
            ->where('users_id', $this->user->getId())
            ->where('name', $this->tagName)
            ->first();

        if ($feedTag) {
            $oldWeight = $feedTag->weight;
            $feedTag->weight = $this->weight;
            $feedTag->save();
        }

        $feedTagNames = FeedTagsEnum::getValues();
        $firstFeedTag = Tag::fromApp($this->app)
            ->where('users_id', $this->user->getId())
            ->whereIn('name', $feedTagNames)
            ->whereNot('name', $this->tagName)
            ->where('weight', $this->weight)
            ->first();

        if ($firstFeedTag) {
            $firstFeedTag->weight = $oldWeight;
            $firstFeedTag->save();
        }


        return true;
    }
}
