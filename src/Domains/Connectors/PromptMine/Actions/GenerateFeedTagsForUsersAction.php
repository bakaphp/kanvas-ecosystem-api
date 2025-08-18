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
        private Companies $company,
        private int $weight
    ) {
    }

    public function execute(): void
    {
        $feedTagNames = FeedTabsEnum::getValues();
        $appPreferences = $this->user->get('user_app_' . $this->app->getId() . '_preferences');
        if ($appPreferences && in_array('user_motivation',$appPreferences) && isset($appPreferences['user_motivation'])) {

            $originalFeedTags = Tag::fromApp($this->app)
                    ->whereIn('name', $feedTagNames)
                    ->get();

            foreach ($originalFeedTags as $feedTag) {
                $tagData = new TagData($this->app, $this->user, $this->company, $feedTag->name, $feedTag->name, $feedTag->weight);
                (new CreateTagAction($tagData))->execute();
            }
        }

        

    }
}
