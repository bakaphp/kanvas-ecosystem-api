<?php

declare(strict_types=1);

namespace Kanvas\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class BaseModel extends EloquentModel
{
    use HasFactory;
}
