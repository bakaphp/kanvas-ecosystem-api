<?php
declare(strict_types=1);

namespace Kanvas\Guild\Customers\Repositories;

use Baka\Contracts\CompanyInterface;
use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Guild\Customers\Models\Peoples;
use Kanvas\Guild\Customers\Models\PeoplesRelationships;

class PeoplesRepository
{
    use SearchableTrait;

    public static function getModel() : Model
    {
        return new Peoples();
    }

    public static function getRelationshipTypeById(int $id, CompanyInterface $company) : PeoplesRelationships
    {
        try {
            return PeoplesRelationships::fromCompany($company)
                ->fromApp()
                ->notDeleted()
                ->where('id', $id)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }
}
