<?php
declare(strict_types=1);
namespace Baka\Traits;

trait BaseModel
{
    /**
     * Get by uui.
     *
     * @param string $uuid
     *
     * @return self
     */
    public static function getByUuid(string $uuid) : self
    {
        return self::where('id', $uuid)
            ->where('is_deleted', StateEnums::NO->getValue())
            ->firstOrFail();
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
        return self::where('id', (int) $id)
            ->where('is_deleted', StateEnums::NO->getValue())
            ->firstOrFail();
    }
}
