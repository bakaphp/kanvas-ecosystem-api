<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Illuminate\Support\Facades\DB;

class UserFullTableName extends Users
{
    public function __construct(array $attributes = [])
    {
        $this->setTable(DB::connection('ecosystem')->getDatabaseName() . '.' . $this->table);
        parent::__construct($attributes);
    }
}
