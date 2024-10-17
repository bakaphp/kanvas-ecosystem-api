<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Actions;

use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Models\UsersFollows;
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
        $params = [];

        if ($this->company) {
            $params['companies_id'] = $this->company->getId();
            $params['companies_branches_id'] = $this->company->defaultBranch()->firstOrFail()->getId();
        }

        if (isset($this->entity->companies_branches_id)) {
            $params['companies_branches_id'] = $this->entity->companies_branches_id;
        }

        return UsersFollows::firstOrCreate($search, $params);
    }
}
