<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Actions;

use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\Follows\Notifications\NewFollowerNotification;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class FollowAction
{
    public function __construct(
        public Users $user,
        public EloquentModel $entity,
        public ?CompanyInterface $company = null,
        public ?Apps $app = null
    ) {
        if (! $this->company instanceof CompanyInterface && ! empty($this->entity->companies_id) || ! empty($this->entity->company_id)) {
            $this->company = $this->entity->company()->firstOrFail();
        }
        $this->app = $this->app ?? app(Apps::class);
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public function execute(): UsersFollows
    {
        UsersRepository::belongsToThisApp($this->user, $this->app, $this->company);

        $search = [
            'users_id' => $this->user->getId(),
            'entity_id' => $this->entity->getId(),
            'entity_namespace' => get_class($this->entity),
            'apps_id' => $this->app->getId(),
        ];
        $params = [
            'is_deleted' => StateEnums::NO->getValue(),
        ];

        if ($this->company) {
            $params['companies_id'] = $this->company->getId();
            $params['companies_branches_id'] = $this->company->defaultBranch()->firstOrFail()->getId();
        }

        if (isset($this->entity->companies_branches_id)) {
            $params['companies_branches_id'] = $this->entity->companies_branches_id;
        }

        $userFollowed = UsersFollows::updateOrCreate($search, $params);

        if ($userFollowed->wasRecentlyCreated && $this->entity instanceof UserInterface) {
            try {
                $this->entity->notify(new NewFollowerNotification($this->user, [
                    'app' => $this->app,
                    'company' => $this->company,
                    'user_followed' => [
                        'id' => $this->user->getId(),
                        'displayname' => $this->user->displayname,
                        'photo' => $this->user->photo,
                    ],
                    'user_following' => [
                        'id' => $this->entity->getId(),
                        'displayname' => $this->entity->displayname,
                        'photo' => $this->entity->photo,
                    ],
                    'title' => 'New Follower',
                    'message' => sprintf('You have a new follower %s', $this->user->displayname),
                    'destination_id' => $this->user->getId(),
                    'destination_type' => 'USER',
                    'destination_event' => 'FOLLOWING',
                ]));
            } catch (ModelNotFoundException $e) {
            }
        }

        return $userFollowed;
    }
}
