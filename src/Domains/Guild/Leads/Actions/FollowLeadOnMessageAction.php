<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Follows\Actions\FollowAction;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Models\Users;
use Throwable;

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

        try {
            $follow = $this->follow($this->lead);
        } catch (ModelNotFoundException) {
            // The sender is not a member of the lead's company, so there is nobody to subscribe.
            return null;
        }

        $this->followPerson();

        return $follow;
    }

    /**
     * Best effort: this runs after the message is already sent, so nothing here may fail the caller.
     */
    private function followPerson(): void
    {
        if (! $this->lead->people instanceof People) {
            return;
        }

        try {
            $this->follow($this->lead->people);
        } catch (ModelNotFoundException) {
            // Old data can hold a person filed under another company, which the sender is no member of.
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function follow(Lead|People $entity): UsersFollows
    {
        $existing = UsersFollowsRepository::getByUserAndEntity($this->sender, $entity, $this->lead->app);

        return $existing instanceof UsersFollows
            ? $existing
            : new FollowAction($this->sender, $entity, app: $this->lead->app)->execute();
    }
}
