<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Models\Users;

class UnFollowAction
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public Users $user,
        public EloquentModel $entity,
        protected ?AppInterface $app = null
    ) {
        $this->app = $app ?? app(Apps::class);
    }

    /**
     * execute
     */
    public function execute(): bool
    {
        $follow = UsersFollowsRepository::getByUserAndEntity($this->user, $this->entity, $this->app);
        if ($follow) {
            return $follow->softDelete();
        }

        return false;
    }
}
