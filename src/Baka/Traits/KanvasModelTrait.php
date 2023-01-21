<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;

trait KanvasModelTrait
{
    /**
     * Get primary key.
     *
     * @return mixed
     */
    public function getId() : mixed
    {
        return $this->getKey();
    }

    /**
     * Get uuid.
     *
     * @return string
     */
    public function getUuid() : string
    {
        return $this->uuid;
    }

    /**
     * Get by uui.
     *
     * @param string $uuid
     *
     * @return self
     */
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

    /**
     * Get by Id.
     *
     * @param mixed $id
     *
     * @return self
     */
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

    /**
     * Not deleted scope.
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeNotDeleted(Builder $query) : Builder
    {
        return $query->where('is_deleted', '=', StateEnums::NO->getValue());
    }
}
