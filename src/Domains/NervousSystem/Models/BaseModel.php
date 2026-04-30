<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Models;

use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Model;

class BaseModel extends Model
{
    use KanvasModelTrait;

    protected $connection = 'intelligence';

    public $timestamps = false;
}
