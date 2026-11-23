<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Override;

class UserFullTableName extends Users
{
    public function __construct(array $attributes = [])
    {
        $this->setTable(DB::connection('ecosystem')->getDatabaseName() . '.' . $this->table);
        parent::__construct($attributes);
    }

    #[Override]
    public function getMorphClass(): string
    {
        return Users::class;
    }

    /**
     * Settings belong to the Users row, so the Redis hash must be the one Users writes to. The trait
     * derives it from get_class(), which gave this subclass its own hash: a setting saved through
     * Users (e.g. email_signature) was invisible here, and a cached "not found" marker never expired.
     */
    #[Override]
    public function getSettingsRedisPrimaryKey(): string
    {
        return Str::simpleSlug(Users::class . '_settings_' . $this->getKey());
    }
}
