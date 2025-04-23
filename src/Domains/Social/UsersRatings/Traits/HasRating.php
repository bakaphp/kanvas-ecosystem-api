<?php

namespace Kanvas\Social\UsersRatings\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\UsersRatings\Actions\CreateUserRatingAction;
use Kanvas\Social\UsersRatings\DataTransferObject\UserRating as DataTransferObjectUserRating;
use Kanvas\Social\UsersRatings\Models\UserRating;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

trait HasRating
{
    public function usersRatings()
    {
        $systemModule = SystemModulesRepository::getByModelName(self::class, app(Apps::class));

        return $this->hasMany(UserRating::class, 'entity_id')->where('system_modules_id', $systemModule->getId());
    }

    public function addRating(
        Model $model,
        float $rating,
        ?string $comment = null,
        ?AppInterface $app = null,
        ?CompanyInterface $company = null
    ): bool {
        if (!$this instanceof Users) {
            throw new Exception('The method addRating can only be used by the Users model');
        }

        $company = $company ?? $this->getCurrentCompany();
        $app = $app ?? app(Apps::class);

        $systemModule = SystemModulesRepository::getByModelName(get_class($model), app(Apps::class));
        $dto = DataTransferObjectUserRating::from([
            'app'          => $app,
            'user'         => $this,
            'company'      => $company,
            'systemModule' => $systemModule,
            'entityId'     => $model->getId(),
            'rating'       => $rating,
            'comment'      => $comment,
        ]);

        return (bool) (new CreateUserRatingAction($dto))->execute();
    }
}
