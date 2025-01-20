<?php

namespace Kanvas\Social\UsersRatings\Traits;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\UsersRatings\Actions\CreateUserRating;
use Kanvas\Social\UsersRatings\DataTransferObject\UserRating as UsersRatingsDTO;
use Kanvas\Social\UsersRatings\Models\UserRating as UsersRatingsModel;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

trait HasRating
{
    public function usersRatings()
    {
        $systemModule = SystemModulesRepository::getByModelName(self::class, app(Apps::class));

        return $this->hasMany(UsersRatingsModel::class, 'entity_id')->where('system_modules_id', $systemModule->getId());
    }

    public function addRating(
        Model $model,
        float $rating,
        ?string $comment = null,
        ?Apps $app = null,
        ?Companies $companies = null
    ): bool {
        if (! $this instanceof Users) {
            throw new Exception('The method addRating can only be used by the Users model');
        }
        $systemModule = SystemModulesRepository::getByModelName(get_class($model), app(Apps::class));
        $dto = UsersRatingsDTO::from([
            'app' => $app ?? app(Apps::class),
            'user' => $this,
            'company' => $companies ?? auth()->user()->getCurrentCompany(),
            'systemModule' => $systemModule,
            'entityId' => $model->getId(),
            'rating' => $rating,
            'comment' => $comment,
        ]);

        return (bool)(new CreateUserRating($dto))->execute();
    }
}
