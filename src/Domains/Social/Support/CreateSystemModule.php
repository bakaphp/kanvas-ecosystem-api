<?php

declare(strict_types=1);

namespace Kanvas\Social\Support;

use Baka\Contracts\AppInterface;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesComments\Models\MessageComment;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\Reactions\Models\Reaction;
use Kanvas\Social\Topics\Models\EntityTopics;
use Kanvas\Social\Topics\Models\Topic;
use Kanvas\Social\UsersLists\Models\UserList;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;

class CreateSystemModule
{
    public function __construct(
        protected AppInterface $app
    ) {
    }

    public function run(): void
    {
        $createSystemModule = new CreateInCurrentAppAction($this->app);

        $createSystemModule->execute(Interactions::class);
        $createSystemModule->execute(Message::class);
        $createSystemModule->execute(UsersFollows::class);
        $createSystemModule->execute(Channel::class);
        $createSystemModule->execute(MessageType::class);
        $createSystemModule->execute(Reaction::class);
        $createSystemModule->execute(MessageComment::class);
        $createSystemModule->execute(Topic::class);
        $createSystemModule->execute(EntityTopics::class);
        $createSystemModule->execute(UsersInteractions::class);
        $createSystemModule->execute(UserList::class);
    }
}
