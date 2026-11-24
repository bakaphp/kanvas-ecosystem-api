<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Followers\Actions\AddFollowerAction;
use Kanvas\Guild\Followers\Models\Follower;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;

class FollowLeadOnMessageAction
{
    public function __construct(
        protected Lead $lead,
        protected Users $sender,
    ) {
    }

    public function execute(): ?Follower
    {
        $senderId = (int) $this->sender->getId();

        // Owner and creator are already notified as stakeholders.
        if ($senderId === (int) $this->lead->leads_owner_id || $senderId === (int) $this->lead->users_id) {
            return null;
        }

        // Lead and person share a company, so the membership check passes or fails for both.
        try {
            if ($this->lead->people instanceof People) {
                new AddFollowerAction($this->lead->people, $this->sender)->execute();
            }

            return new AddFollowerAction($this->lead, $this->sender)->execute();
        } catch (ModelNotFoundException) {
            // The sender is not a member of the lead's company, so there is nobody to subscribe.
            return null;
        }
    }
}
