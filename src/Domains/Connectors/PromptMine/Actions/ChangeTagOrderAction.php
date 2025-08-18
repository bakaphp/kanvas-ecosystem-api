<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PromptMine\Enums\FeedTabsEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Social\Tags\Actions\CreateTagAction;
use Kanvas\Social\Tags\DataTransferObjects\Tag as TagData;
use Kanvas\Social\Tags\Models\Tag;

class GenerateFeedTagsForUsersAction
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
        $feedTagNames = FeedTabsEnum::getValues();
        $firstFeedTag = Tag::fromApp($this->app)
                    ->where('users_id', $this->user->getId())
                    ->whereIn('name', [$this->tagName])
                    ->where('weight', $this->weight)
                    ->first();

        $feedTag = Tag::fromApp($this->app)
                    ->where('users_id', $this->user->getId())
                    ->where('name', $this->tagName)
                    ->where('weight', $this->weight)
                    ->first();

        if ($firstFeedTag && $feedTag) {
            $firstFeedTag->weight = $feedTag->weight;
            $firstFeedTag->save();
            $feedTag->weight = $this->weight;
            $feedTag->save();

            return true;
        }

        return false;
    }
}
