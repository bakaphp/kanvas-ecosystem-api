<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Follows\Actions\FollowAction;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Models\Users;

class FollowLeadOnMessageAction
{
    public function __construct(
        protected Lead $lead,
        protected Users $sender,
    ) {
    }

    public function execute(): ?UsersFollows
    {
        $senderId = (int) $this->sender->getId();

        // Owner and creator are already notified as stakeholders.
        if ($senderId === (int) $this->lead->leads_owner_id || $senderId === (int) $this->lead->users_id) {
            return null;
        }

        $existing = UsersFollowsRepository::getByUserAndEntity($this->sender, $this->lead, $this->lead->app);
        if ($existing instanceof UsersFollows) {
            return $existing;
        }

        try {
            return new FollowAction($this->sender, $this->lead, app: $this->lead->app)->execute();
        } catch (ModelNotFoundException) {
            // The sender is not a member of the lead's company, so there is nobody to subscribe.
            return null;
        }
    }
}
