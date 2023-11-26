<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
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
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    public static function getByUuid(string $uuid, ?AppInterface $app): self
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
            throw new ExceptionsModelNotFoundException($e->getMessage());
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
            throw new ExceptionsModelNotFoundException($e->getMessage());
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
            //we want to expose the not found msg
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    public static function getByIdFromBranch(mixed $id, CompaniesBranches $branch): self
    {
        try {
            return self::where('id', $id)
                ->notDeleted()
                ->where('companies_branches_id', $branch->getId())
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            //we want to expose the not found msg
            throw new ExceptionsModelNotFoundException($e->getMessage());
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
            //we want to expose the not found msg
            throw new ExceptionsModelNotFoundException($e->getMessage());
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
            //we want to expose the not found msg
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    /**
     * can't use the name company since the scope is also using the same name.
     *
     * @return BelongsTo<Companies>
     */
    public function company(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(
            Companies::class,
            'companies_id',
            'id'
        );
    }

    public function user(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(
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
        return  $this->setConnection('ecosystem')->belongsTo(
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

        return $this->saveOrFail();
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
        return (int) $this->is_deleted === StateEnums::YES->getValue();
    }
}
