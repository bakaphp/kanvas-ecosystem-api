<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;

trait SearchableTrait
{
    abstract public static function getModel() : Model;

    public static function getById(int $id, ?CompanyInterface $company = null) : Model
    {
        $company = $company ?? auth()->user()->getCurrentCompany();

        try {
            return self::getModel()::company($company)
                ->app()
                ->notDelete()
                ->where('id', $id)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    public static function getByUuid(string $uuid, ?CompanyInterface $company = null) : Model
    {
        $company = $company ?? auth()->user()->getCurrentCompany();

        try {
            return self::getModel()::company($company)
                ->app()
                ->notDelete()
                ->where('uuid', $uuid)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    public static function getByName(string $name, ?CompanyInterface $company = null) : Model
    {
        $company = $company ?? auth()->user()->getCurrentCompany();

        try {
            return self::getModel()::company($company)
                ->app()
                ->notDelete()
                ->where('name', $name)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }
}
