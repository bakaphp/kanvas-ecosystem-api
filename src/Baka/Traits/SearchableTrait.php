<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;

trait SearchableTrait
{
    abstract public static function getModel(): Model;

    public static function getById(int $id, ?CompanyInterface $company = null, ?AppInterface $app = null): Model
    {
        $company = $company ?? auth()->user()->getCurrentCompany();

        try {
            return self::getModel()::fromCompany($company)
                ->fromApp($app)
                ->notDeleted()
                ->where('id', $id)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    public static function getByIdOrGlobal(int $id, ?CompanyInterface $company = null, ?AppInterface $app = null): Model
    {
        $company = $company ?? auth()->user()->getCurrentCompany();
        $companyId = $company->getId();

        try {
            $query = self::getModel()::when($app, fn ($q, $a) => $q->fromApp($a))
                ->notDeleted()
                ->where('id', $id);

            if (($app ?? app(Apps::class))->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value)) {
                $query->where(function ($q) use ($companyId) {
                    $q->where('companies_id', 0)
                      ->orWhere('companies_id', $companyId);
                });
            } else {
                $query->where('companies_id', $companyId);
            }

            return $query->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    public static function getByUuid(string $uuid, ?CompanyInterface $company = null, ?AppInterface $app = null): Model
    {
        $company = $company ?? auth()->user()->getCurrentCompany();

        try {
            return self::getModel()::fromCompany($company)
                ->app()
                ->notDeleted()
                ->where('uuid', $uuid)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    public static function getByName(string $name, ?CompanyInterface $company = null, ?AppInterface $app = null): Model
    {
        $company = $company ?? auth()->user()->getCurrentCompany();

        try {
            return self::getModel()::fromCompany($company)
                ->fromApp($app)
                ->notDeleted()
                ->where('name', $name)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    public static function getBySlug(string $slug, ?CompanyInterface $company = null, ?AppInterface $app = null): Model
    {
        $company = $company ?? auth()->user()->getCurrentCompany();

        try {
            return self::getModel()::fromCompany($company)
                ->fromApp($app)
                ->notDeleted()
                ->where('slug', $slug)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }
}
