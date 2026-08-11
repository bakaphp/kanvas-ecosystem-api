<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

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
}
