<?php

declare(strict_types=1);

namespace Kanvas\Communication\Models;

use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Model;

class BaseModel extends Model
{
    use KanvasModelTrait;

    protected $connection = 'workflow';
    protected $guarded = [];
}
