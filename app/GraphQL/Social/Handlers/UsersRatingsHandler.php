<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Handlers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Social\UsersRatings\Models\UsersRatings;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class UsersRatingsHandler
{
    /**
     * @param  array<string, mixed>  $whereConditions
     */
    public function __invoke(
        object $builder,
        array $whereConditions,
        ?Model $model = null,
        string $boolean = 'and',
    ): void {
        $systemModule = $this->getSystemModules($builder->from);
        $userRating = UsersRatings::query();
        $userRating->from((new UsersRatings())->getFullTableName())
            ->whereColumn('entity_id', 'products.id')
            ->where('system_modules_id', $systemModule->getId())
        ->selectRaw('AVG(rating) as rating');
        $builder->addSelect(['rating' => $userRating])
                ->having('rating', '=', $whereConditions['value']);
        if ($whereConditions['value'] == 0) {
            $builder->orHavingRaw('rating IS NULL');
        }
    }

    protected function getSystemModules(string $entity): ?SystemModules
    {
        $systemModule = null;
        switch ($entity) {
            case 'products':
                $systemModule = SystemModulesRepository::getByModelName(Products::class, app(Apps::class));

                break;
            default:
                break;
        }

        return $systemModule;
    }
}
