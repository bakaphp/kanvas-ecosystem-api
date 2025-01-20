<?php

namespace Kanvas\Social\UsersRatings\Traits;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\UsersRatings\Actions\CreateUsersRatings;
use Kanvas\Social\UsersRatings\DataTransferObject\UsersRatings as UsersRatingsDTO;
use Kanvas\Social\UsersRatings\Models\UsersRatings as UsersRatingsModel;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Exception;

trait HasRating
{
    public function usersRatings()
    {
        $systemModule = SystemModulesRepository::getByModelName(self::class, app(Apps::class));

        return $this->hasMany(UsersRatingsModel::class, 'entity_id')->where('system_modules_id', $systemModule->getId());
    }

    public function addRating(Model $model, float $rating, ?string $comment = null): bool
    {
        if (! $this instanceof Users) {
            throw new Exception('The method addRating can only be used by the Users model');
        }
        $systemModule = SystemModulesRepository::getByModelName(get_class($model), app(Apps::class));
        $dto = UsersRatingsDTO::from([
            'app' => app(Apps::class),
            'user' => $this,
            'company' => auth()->user()->getCurrentCompany(),
            'systemModule' => $systemModule,
            'entityId' => $model->getId(),
            'rating' => $rating,
            'comment' => $comment,
        ]);

        return (bool)(new CreateUsersRatings($dto))->execute();
    }
}
