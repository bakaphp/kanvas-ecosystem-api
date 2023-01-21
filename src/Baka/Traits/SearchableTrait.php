<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;

trait SearchableTrait
{
    abstract public static function getModel() : Model;

    public static function getById(int $id, ?CompanyInterface $company = null) : Model
    {
        $company = $company ?? auth()->user()->getCurrentCompany();

        return self::getModel()::where('companies_id', $company->getId())
            ->where('apps_id', app(Apps::class)->id)
            ->where('is_deleted', StateEnums::NO->getValue())
            ->findOrFail($id);
    }

    public static function getByUuid(string $uuid, ?CompanyInterface $company = null) : Model
    {
        $company = $company ?? auth()->user()->getCurrentCompany();

        return self::getModel()::where('companies_id', $company->getId())
            ->where('apps_id', app(Apps::class)->getId())
            ->where('uuid', $uuid)
            ->where('is_deleted', StateEnums::NO->getValue())
            ->findOrFail();
    }

    public static function getByName(string $name, ?CompanyInterface $company = null) : Model
    {
        $company = $company ?? auth()->user()->getCurrentCompany();

        return self::getModel()::where('companies_id', $company->getId())
            ->where('apps_id', app(Apps::class)->getId())
            ->where('is_deleted', StateEnums::NO->getValue())
            ->where('name', $name)
            ->findOrFail();
    }
}
