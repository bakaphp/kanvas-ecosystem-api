<?php

declare(strict_types=1);

namespace Kanvas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Traits\KanvasModelTrait;
use Kanvas\Traits\SoftDeletes;

class BaseModel extends EloquentModel
{
    use HasFactory;
    use KanvasModelTrait;
    //use SoftDeletes;

    protected $attributes = [
        'is_deleted' => 0,
    ];
}
