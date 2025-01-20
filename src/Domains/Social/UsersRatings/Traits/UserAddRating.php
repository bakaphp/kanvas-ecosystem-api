<?php

namespace Kanvas\Social\UsersRatings\Traits;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\UsersRatings\Actions\CreateUsersRatings;
use Kanvas\Social\UsersRatings\DataTransferObject\UsersRatings as UsersRatingsDTO;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

trait UserAddRating
{
    public function addRating(Model $model, float $rating, ?string $comment = null): bool
    {
        $systemModule = SystemModulesRepository::getByModelName(get_class($model), app(Apps::class));
        $dto = UsersRatingsDTO::from([
            'app' => app(Apps::class),
            'user' => $this,
            'company' => auth()->user()->getCurrentCompany(),
            'systemModule' => $systemModule,
            'entityId' => $model->getId(),
            'rating' => $rating,
            'comment' => $comment
        ]);

        return (bool)(new CreateUsersRatings($dto))->execute();
    }
}
