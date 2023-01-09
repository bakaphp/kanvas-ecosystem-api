<?php
declare(strict_types=1);

namespace Baka\Traits;

use Exception;

/**
 * https://laracasts.com/discuss/channels/laravel/override-method-setkeysforsavequery-error?page=1&replyId=658568.
 *
 * Trait to handle composite keys
 */
trait HasCompositePrimaryKeyTrait
{
    /**
     * Disable auto-incrementing for primary key.
     *
     * @return void
     */
    public function getIncrementing()
    {
        return false;
    }

    /**
     * Override the method to set keys for save query.
     *
     * @param object $query
     *
     * @return object
     */
    protected function setKeysForSaveQuery($query)
    {
        foreach ($this->getKeyName() as $key) {
            // UPDATE: Added isset() per overflow's comment.
            if (isset($this->$key)) {
                $query->where($key, '=', $this->$key);
            } else {
                throw new Exception(__METHOD__ . 'Missing part of the primary key: ' . $key);
            }
        }
        return $query;
    }

    /**
     * Override the method to get key for save query.
     *
     * @param string|null $keyName
     *
     * @return mixed
     */
    protected function getKeyForSaveQuery($keyName = null)
    {
        if (is_null($keyName)) {
            $keyName = $this->getKeyName();
        }

        if (isset($this->original[$keyName])) {
            return $this->original[$keyName];
        }

        return $this->getAttribute($keyName);
    }
}
