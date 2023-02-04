<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Users\Models\Users;

trait KanvasModelTrait
{
    public function getId() : mixed
    {
        return $this->getKey();
    }

    public function getUuid() : string
    {
        return $this->uuid;
    }

    public static function getByUuid(string $uuid) : self
    {
        try {
            return self::where('uuid', $uuid)
                ->where('is_deleted', StateEnums::NO->getValue())
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            //we want to expose the not found msg
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    public static function getById(mixed $id) : self
    {
        try {
            return self::where('id', $id)
                ->where('is_deleted', StateEnums::NO->getValue())
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            //we want to expose the not found msg
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    /**
     * @return BelongsTo<Companies>
     */
    public function company() : BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(
            Companies::class,
            'companies_id',
            'id'
        );
    }

    public function user() : BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(
            Users::class,
            'users_id',
            'id'
        );
    }

    public function app() : BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(
            Apps::class,
            'apps_id',
            'id'
        );
    }

    /**
     * Current soft delete.
     *
     * @todo change to laravel default behavior
     *
     * @return bool
     */
    public function softDelete() : bool
    {
        $this->is_deleted = StateEnums::YES->getValue();
        return $this->saveOrFail();
    }
}
