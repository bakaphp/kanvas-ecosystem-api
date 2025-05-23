<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

trait KanvasModelTrait
{
    use KanvasScopesTrait;

    public function getId(): mixed
    {
        return $this->getKey();
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public static function getByName(string $name, ?AppInterface $app = null): self
    {
        try {
            return self::where('name', $name)
                ->notDeleted()
                ->when($app, function ($query, $app) {
                    $query->fromApp($app);
                })
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            //we want to expose the not found msg
            throw new ExceptionsModelNotFoundException($e->getMessage() . "  $name");
        }
    }

    public static function getByUuid(string $uuid, ?AppInterface $app = null): self
    {
        try {
            return self::where('uuid', $uuid)
                ->notDeleted()
                ->when($app, function ($query, $app) {
                    $query->fromApp($app);
                })
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            //we want to expose the not found msg
            throw new ExceptionsModelNotFoundException($e->getMessage() . "  $uuid");
        }
    }

    public static function getById(mixed $id, ?AppInterface $app = null): self
    {
        try {
            return self::where('id', $id)
            ->when($app, function ($query, $app) {
                $query->fromApp($app);
            })
            ->notDeleted()
            ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            //we want to expose the not found msg
            throw new ExceptionsModelNotFoundException($e->getMessage() . " $id");
        }
    }

    public static function getByIdFromCompany(mixed $id, CompanyInterface $company): self
    {
        try {
            return self::where('id', $id)
                ->notDeleted()
                ->fromCompany($company)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            // Custom error message for company lookup by ID
            throw new ExceptionsModelNotFoundException(
                sprintf('No %s record found with ID %s for Company ID %s.', get_called_class(), $id, $company->getId())
            );
        }
    }

    public static function getByIdFromCompanyApp(mixed $id, CompanyInterface $company, AppInterface $app): self
    {
        try {
            return self::where('id', $id)
                ->notDeleted()
                ->fromCompany($company)
                ->fromApp($app)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            // Custom error message for company and app lookup by ID
            throw new ExceptionsModelNotFoundException(
                sprintf('No %s record found with ID %s for Company ID %s', get_called_class(), $id, $company->getId())
            );
        }
    }

    public static function getByUuidFromCompanyApp(string $uuid, ?CompanyInterface $company = null, ?AppInterface $app = null): self
    {
        try {
            return self::where('uuid', $uuid)
                ->when($company, function ($query, $company) {
                    $query->where('companies_id', $company->getId());
                })
                ->when($app, function ($query, $app) {
                    $query->where('apps_id', $app->getId());
                })
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            // Custom error message for company and app lookup by UUID
            throw new ExceptionsModelNotFoundException(
                sprintf(
                    'No %s record found with UUID %s%s%s.',
                    get_called_class(),
                    $uuid,
                    $company ? ' for Company ID ' . $company->getId() : '',
                    $app ? ' and App ID ' . $app->getId() : ''
                )
            );
        }
    }

    public static function getByIdFromBranch(mixed $id, CompaniesBranches $branch, ?AppInterface $app = null): self
    {
        try {
            return self::where('id', $id)
                ->notDeleted()
                ->where('companies_branches_id', $branch->getId())
                ->when($app, function ($query, $app) {
                    $query->fromApp($app);
                })
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            // Custom error message for branch lookup by ID
            throw new ExceptionsModelNotFoundException(
                sprintf('No %s record found with ID %s for Branch ID %s.', get_called_class(), $id, $branch->getId())
            );
        }
    }

    public static function getByUuidFromCompany(string $uuid, CompanyInterface $company): self
    {
        try {
            return self::where('uuid', $uuid)
                ->notDeleted()
                ->fromCompany($company)
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            // Custom error message for company lookup by UUID
            throw new ExceptionsModelNotFoundException(
                sprintf('No %s record found with UUID %s for Company ID %s.', get_called_class(), $uuid, $company->getId())
            );
        }
    }

    public static function getByUuidFromBranch(string $uuid, CompaniesBranches $branch): self
    {
        try {
            return self::where('uuid', $uuid)
                ->notDeleted()
                ->where('companies_branches_id', $branch->getId())
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            // Custom error message for branch lookup by UUID
            throw new ExceptionsModelNotFoundException(
                sprintf('No %s record found with UUID %s for Branch ID %s.', get_called_class(), $uuid, $branch->getId())
            );
        }
    }

    /**
     * can't use the name company since the scope is also using the same name.
     *
     * @return BelongsTo<Companies>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Companies::class,
            'companies_id',
            'id'
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            Users::class,
            'users_id',
            'id'
        );
    }

    /**
     * cant use app because of the scope name.
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(
            Apps::class,
            'apps_id',
            'id'
        );
    }

    /**
     * Current soft delete.
     *
     * @todo change to laravel default behavior
     */
    public function softDelete(): bool
    {
        $this->is_deleted = StateEnums::YES->getValue();

        $delete = $this->saveOrFail();
        if (method_exists($this, 'searchableSoftDelete')) {
            $this->searchableSoftDelete();
        }

        return $delete;
    }

    /**
     * restore
     *
     * @todo change to laravel default behavior
     */
    public function restoreRecord(): bool
    {
        $this->is_deleted = StateEnums::NO->getValue();

        return $this->saveOrFail();
    }

    /**
     * Get the table name with the connection name.
     */
    public static function getFullTableName(): string
    {
        $model = new static();

        return $model->getConnection()->getDatabaseName() . '.' . $model->getTable();
    }

    /**
     * Get the table name.
     */
    public static function getTableName(): string
    {
        return ((new static())->getTable());
    }

    /**
     * Those the given entity have the given column.
     */
    public function hasColumn(string $name): bool
    {
        return Schema::connection($this->getConnectionName())
                ->hasColumn($this->getTableName(), $name);
    }

    public function getCacheKey(): string
    {
        return Str::simpleSlug(static::class) . '-' . $this->getId();
    }

    public function isDeleted(): bool
    {
        if ($this->is_deleted instanceof Carbon) {
            return $this->is_deleted->greaterThan(Carbon::create(2010));
        }

        return (int) $this->is_deleted === StateEnums::YES->getValue();
    }

    public function hasWorkflow(): bool
    {
        return method_exists($this, 'fireWorkflow');
    }

    /**
     * When you delete records and send it to searchable, you will only get id and is_deleted info, no relationship
     * we need this method to do special delete on the searchable side.
     */
    public function searchableDeleteRecord(): bool
    {
        return ! isset($this->app);
    }

    /**
     * for scout don't index the record if is deleted.
     * cant use return type because of laravel scout class
     * @return bool
     */
    public function shouldBeSearchable()
    {
        return ! $this->isDeleted();
    }

    public function getSystemModule(): SystemModules
    {
        return SystemModulesRepository::getByModelName(get_class($this), $this->app);
    }
}
